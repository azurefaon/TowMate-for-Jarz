<?php

namespace App\Services;

use App\Exceptions\HomeTeamSaveException;
use App\Exceptions\HomeUnitConflictException;
use App\Models\AuditLog;
use App\Models\Personnel;
use App\Models\Unit;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PersonnelService
{
    public const ACCOUNT_ROLE_IDS = [3, 4];

    public const ACCOUNT_ROLE_LABELS = [
        3 => 'Team Leader',
        4 => 'Driver',
    ];

    public const PERSONNEL_ROLE_LABELS = [
        'driver' => 'Driver',
        'crew' => 'Crew Member',
    ];

    public const ROLE_FILTERS = [
        'team_leader' => 'Team Leader',
        'driver' => 'Driver',
        'crew' => 'Crew Member',
    ];

    public function __construct(
        protected UnitAvailabilityService $unitAvailability,
        protected TeamLeaderAvailabilityService $teamLeaderAvailability,
    ) {
    }

    protected ?array $engagementIndex = null;

    public const STATUS_FILTERS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ];

    public function listPersonnel(string $search = '', string $roleFilter = '', int $perPage = 7, int $page = 1, string $statusFilter = ''): LengthAwarePaginator
    {
        $this->engagementIndex = null;

        $accounts = User::whereIn('role_id', self::ACCOUNT_ROLE_IDS)
            ->whereNull('archived_at')
            ->whereNull('anonymized_at')
            ->with(['homeUnit', 'unit', 'driverUnit'])
            ->get()
            ->map(fn (User $person) => $this->presentAccount($person));

        $records = Personnel::with('homeUnit')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Personnel $record) => $this->presentRecord($record));

        $all = $accounts->concat($records)->sortBy('full_name')->values();

        $search = trim($search);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $all = $all->filter(function (array $row) use ($needle) {
                return str_contains(mb_strtolower($row['full_name']), $needle)
                    || str_contains(mb_strtolower((string) $row['reference']), $needle);
            })->values();
        }

        if ($roleFilter !== '' && array_key_exists($roleFilter, self::ROLE_FILTERS)) {
            $all = $all->filter(fn (array $row) => $row['role_key'] === $roleFilter)->values();
        }

        if (array_key_exists($statusFilter, self::STATUS_FILTERS)) {
            $all = $all->filter(fn (array $row) => $row['status'] === self::STATUS_FILTERS[$statusFilter])->values();
        }

        $page = max(1, $page);

        return new LengthAwarePaginator(
            $all->forPage($page, $perPage)->values(),
            $all->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    protected function presentAccount(User $person): array
    {
        $currentUnit = (int) $person->role_id === 3 ? $person->unit : $person->driverUnit;
        $homeUnit = $person->homeUnit;
        $borrowed = $homeUnit && $currentUnit && (int) $homeUnit->id !== (int) $currentUnit->id;

        return [
            'type' => 'account',
            'id' => $person->id,
            'full_name' => $person->full_name,
            'reference' => $person->user_code,
            'role_label' => self::ACCOUNT_ROLE_LABELS[(int) $person->role_id] ?? 'Personnel',
            'role_key' => (int) $person->role_id === 3 ? 'team_leader' : 'driver',
            'home_unit' => $homeUnit,
            'current_unit' => $currentUnit,
            'borrowed' => $borrowed,
            'availability' => $this->resolveAccountAvailability($person, $currentUnit, $borrowed),
            'assignment_status' => $this->resolveAssignmentStatus($currentUnit, $borrowed),
            // The account's sign-in status (RoleMiddleware blocks inactive
            // accounts). personnel_enabled is not read by login or dispatch,
            // so it is not shown as the person's status.
            'status' => $person->status === 'active' ? 'Active' : 'Inactive',
            'editable' => false,
            'model' => $person,
        ];
    }

    protected function presentRecord(Personnel $record): array
    {
        $homeUnit = $record->homeUnit;
        $currentUnit = $this->findCurrentUnitForRecord($record);
        $borrowed = $homeUnit && $currentUnit && (int) $homeUnit->id !== (int) $currentUnit->id;

        return [
            'type' => 'personnel',
            'id' => $record->id,
            'full_name' => $record->full_name,
            'reference' => null,
            'role_label' => self::PERSONNEL_ROLE_LABELS[$record->role] ?? ucfirst($record->role),
            'role_key' => $record->role === 'driver' ? 'driver' : 'crew',
            'home_unit' => $homeUnit,
            'current_unit' => $currentUnit,
            'borrowed' => $borrowed,
            'availability' => $this->resolveRecordAvailability($currentUnit, $borrowed),
            'assignment_status' => $this->resolveAssignmentStatus($currentUnit, $borrowed),
            'status' => $record->personnel_status === 'active' ? 'Active' : 'Inactive',
            'editable' => true,
            'model' => $record,
        ];
    }

    protected function resolveAssignmentStatus(?Unit $currentUnit, bool $borrowed): string
    {
        if ($borrowed) {
            return 'Borrowed';
        }

        return $currentUnit ? 'Assigned' : 'Unassigned';
    }

    /**
     * Current operational unit (id link, legacy slot name, open loan, or
     * active job) from the same index dispatch eligibility uses — never just
     * the regular/home unit.
     */
    protected function findCurrentUnitForRecord(Personnel $record): ?Unit
    {
        $this->engagementIndex ??= app(UnitTeamAssignmentService::class)->engagementIndex();

        $role = $record->role === 'driver' ? 'driver' : 'crew';
        $name = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $record->full_name)));

        return $this->engagementIndex['ids'][(int) $record->id]
            ?? $this->engagementIndex['names'][$role . '|' . $name]
            ?? null;
    }

    protected function resolveAccountAvailability(User $person, ?Unit $currentUnit, bool $borrowed): string
    {
        if ((int) $person->role_id === 3) {
            if ($person->dutyStatus() === 'unavailable') {
                return 'Unavailable';
            }

            if ($this->teamLeaderAvailability->busyTeamLeaderIds()->contains((int) $person->id)) {
                return 'Busy';
            }
        } elseif ($currentUnit) {
            $state = $this->unitAvailability->evaluate($currentUnit);

            if ($state['active_booking']) {
                return 'Busy';
            }
        }

        if ($borrowed) {
            return 'Borrowed';
        }

        return $currentUnit ? 'Assigned' : 'Available';
    }

    protected function resolveRecordAvailability(?Unit $currentUnit, bool $borrowed): string
    {
        if ($currentUnit) {
            $state = $this->unitAvailability->evaluate($currentUnit);

            if ($state['active_booking']) {
                return 'Busy';
            }
        }

        if ($borrowed) {
            return 'Borrowed';
        }

        return $currentUnit ? 'Assigned' : 'Available';
    }

    /**
     * Everyone who can have a home unit, as plain rows: team leader and driver
     * accounts, plus driver and crew personnel records. Active means the
     * account can sign in (users.status) or the record can be placed on a
     * unit (personnel_status).
     */
    public function homeTeamPeople(): Collection
    {
        // The unit each team leader leads today (units.team_leader_id).
        $ledUnitByLeader = Unit::whereNotNull('team_leader_id')->whereNull('archived_at')->pluck('id', 'team_leader_id');

        $accounts = User::whereIn('role_id', self::ACCOUNT_ROLE_IDS)
            ->whereNull('archived_at')
            ->whereNull('anonymized_at')
            ->get()
            ->map(fn (User $person) => [
                'key' => 'account-' . $person->id,
                'type' => 'account',
                'id' => $person->id,
                'name' => $person->full_name,
                'role' => (int) $person->role_id === 3 ? 'team_leader' : 'driver',
                'home_unit_id' => $person->home_unit_id ? (int) $person->home_unit_id : null,
                'current_unit_id' => (int) $person->role_id === 3 && $ledUnitByLeader->has($person->id) ? (int) $ledUnitByLeader->get($person->id) : null,
                'active' => $person->status === 'active',
            ]);

        $records = Personnel::query()
            ->get()
            ->map(fn (Personnel $record) => [
                'key' => 'record-' . $record->id,
                'type' => 'record',
                'id' => $record->id,
                'name' => $record->full_name,
                'role' => $record->role === 'driver' ? 'driver' : 'crew',
                'home_unit_id' => $record->home_unit_id ? (int) $record->home_unit_id : null,
                'current_unit_id' => null,
                'active' => $record->personnel_status === 'active',
            ]);

        return $accounts->concat($records)
            ->sortBy(fn (array $person) => mb_strtolower($person['name']))
            ->values();
    }

    /**
     * Home team per active unit. Every person whose home unit is the unit is
     * listed — several people can share a role. A unit is complete when it has
     * at least one active team leader and one active driver; crew is optional.
     */
    public function homeAssignmentsRoster(?Collection $people = null): Collection
    {
        $byUnit = ($people ?? $this->homeTeamPeople())
            ->whereNotNull('home_unit_id')
            ->groupBy('home_unit_id');

        return Unit::with('truckType')
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(function (Unit $unit) use ($byUnit) {
                $team = $byUnit->get($unit->id, collect());
                $leaders = $team->where('role', 'team_leader')->values();
                $drivers = $team->where('role', 'driver')->values();

                return [
                    'unit' => $unit,
                    'team_leaders' => $leaders,
                    'drivers' => $drivers,
                    'crew' => $team->where('role', 'crew')->values(),
                    'has_active_leader' => $leaders->contains('active', true),
                    'has_active_driver' => $drivers->contains('active', true),
                    'complete' => $leaders->contains('active', true) && $drivers->contains('active', true),
                ];
            });
    }

    /* ---------- Home unit changes (single-person endpoints and the Home Team batch) ---------- */

    /*
     * Lock order for every owner home-unit write: units (ascending id), then
     * user accounts (ascending id), then personnel records (ascending id).
     * A unit's row lock guards its home team: anyone joining or leaving it
     * waits for a Home Team save of that unit, and the reverse. Each write
     * reads which units it needs, locks them, locks the person, and then
     * checks the person's home unit is still one it locked (409 if not).
     */

    /** Sets a personnel record's home unit and audits it. Returns false when unchanged. */
    public function setRecordHomeUnit(Personnel $record, ?int $unitId): bool
    {
        return $this->homeUnitTransaction(function () use ($record, $unitId) {
            $units = Unit::lockInIdOrder([Personnel::whereKey($record->id)->value('home_unit_id'), $unitId]);
            $locked = Personnel::lockForUpdate()->find($record->id) ?? throw new HomeUnitConflictException();
            $this->assertHomeUnitsLocked($units, $locked->home_unit_id, $unitId);

            if ((int) $locked->home_unit_id === (int) $unitId) {
                return false;
            }

            $oldHomeUnit = $locked->home_unit_id ? $units->get((int) $locked->home_unit_id) : null;
            $locked->update(['home_unit_id' => $unitId]);
            $record->setRawAttributes($locked->getAttributes(), true);
            $newHomeUnit = $unitId ? $units->get($unitId) : null;

            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'personnel_home_unit_changed',
                'entity_type' => 'Personnel',
                'entity_id' => $record->id,
                'reference' => $record->full_name,
                'description' => "Home Unit for {$record->full_name} changed from " . ($oldHomeUnit->name ?? 'None') . ' to ' . ($newHomeUnit->name ?? 'None') . '.',
                'old_value' => ['home_unit' => $oldHomeUnit->name ?? 'None'],
                'new_value' => ['home_unit' => $newHomeUnit->name ?? 'None'],
            ]);

            return true;
        });
    }

    /**
     * Sets a Driver account's home unit and audits it. Returns false when
     * unchanged. Team leaders go through setTeamLeaderHomeUnit().
     */
    public function setDriverAccountHomeUnit(User $person, ?int $unitId): bool
    {
        return $this->homeUnitTransaction(function () use ($person, $unitId) {
            $units = Unit::lockInIdOrder([User::whereKey($person->id)->value('home_unit_id'), $unitId]);
            $locked = User::lockForUpdate()->find($person->id);

            if (! $locked || (int) $locked->role_id !== 4) {
                throw new HomeUnitConflictException();
            }
            $this->assertHomeUnitsLocked($units, $locked->home_unit_id, $unitId);

            if ((int) $locked->home_unit_id === (int) $unitId) {
                return false;
            }

            $oldHomeUnit = $locked->home_unit_id ? $units->get((int) $locked->home_unit_id) : null;
            $locked->update(['home_unit_id' => $unitId]);
            $person->setRawAttributes($locked->getAttributes(), true);
            $newHomeUnit = $unitId ? $units->get($unitId) : null;

            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'personnel_home_unit_changed',
                'entity_type' => 'User',
                'entity_id' => $person->id,
                'reference' => $person->full_name,
                'description' => "Home Unit for {$person->full_name} changed from " . ($oldHomeUnit->name ?? 'None') . ' to ' . ($newHomeUnit->name ?? 'None') . '.',
                'old_value' => ['home_unit' => $oldHomeUnit->name ?? 'None'],
                'new_value' => ['home_unit' => $newHomeUnit->name ?? 'None'],
            ]);

            return true;
        });
    }

    /** Team leader home unit (UnitTeamAssignmentService rules), with the same conflict handling. */
    public function setTeamLeaderHomeUnit(User $leader, ?int $unitId, User $actor): User
    {
        return $this->homeUnitTransaction(
            fn () => app(UnitTeamAssignmentService::class)->setTeamLeaderRegularUnit($leader, $unitId, $actor)
        );
    }

    /** Adds a driver or crew record, locking its initial home unit like any other home-unit write. */
    public function createRecord(array $attributes): Personnel
    {
        return $this->homeUnitTransaction(function () use ($attributes) {
            $unitId = isset($attributes['home_unit_id']) ? (int) $attributes['home_unit_id'] : null;
            $this->assertHomeUnitsLocked(Unit::lockInIdOrder([$unitId]), null, $unitId);

            $record = Personnel::create($attributes);

            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'personnel_created',
                'entity_type' => 'Personnel',
                'entity_id' => $record->id,
                'reference' => $record->full_name,
                'description' => "{$record->full_name} added as " . self::PERSONNEL_ROLE_LABELS[$record->role] . '.',
            ]);

            return $record;
        });
    }

    /**
     * Under the locks: the person's current home must be a unit this write
     * locked (otherwise it moved while we waited), and the new home must
     * still exist and not be archived.
     */
    protected function assertHomeUnitsLocked(Collection $units, $currentHomeId, ?int $targetId): void
    {
        if ($currentHomeId && ! $units->has((int) $currentHomeId)) {
            throw new HomeUnitConflictException();
        }

        if ($targetId && (! $units->has($targetId) || $units->get($targetId)->archived_at)) {
            throw new RuntimeException('That unit was archived or removed a moment ago. Choose another unit.');
        }
    }

    /**
     * Runs one home-unit write in a transaction. A deadlock or lock failure
     * with another write becomes a HomeUnitConflictException (409), and audit
     * entries the rolled-back work queued in memory are dropped. Nested inside
     * the Home Team save, it is a savepoint under the save's locks.
     */
    protected function homeUnitTransaction(callable $work): mixed
    {
        $checkpoint = AuditObserver::checkpoint();

        try {
            return DB::transaction($work);
        } catch (Throwable $e) {
            AuditObserver::restore($checkpoint);

            if (HomeUnitConflictException::causedBy($e)) {
                throw new HomeUnitConflictException();
            }

            throw $e;
        }
    }

    /** A fingerprint of who has this unit as their home unit — the state the Edit Home Team dialog opened with. */
    public function homeTeamToken(int $unitId): string
    {
        return $this->tokenFor($this->homeTeamKeys($unitId));
    }

    /**
     * homeTeamToken() for every unit at once, from homeTeamPeople() rows (the
     * same people and keys homeTeamKeys() reads), keyed by unit id.
     */
    public function homeTeamTokens(Collection $people, Collection $unitIds): array
    {
        $byUnit = $people->whereNotNull('home_unit_id')->groupBy('home_unit_id');

        return $unitIds->mapWithKeys(fn ($id) => [
            $id => $this->tokenFor($byUnit->get($id, collect())->pluck('key')->sort()->values()->all()),
        ])->all();
    }

    protected function homeTeamKeys(int $unitId): array
    {
        $accounts = User::whereIn('role_id', self::ACCOUNT_ROLE_IDS)
            ->whereNull('archived_at')
            ->whereNull('anonymized_at')
            ->where('home_unit_id', $unitId)
            ->pluck('id')
            ->map(fn ($id) => 'account-' . $id);
        $records = Personnel::where('home_unit_id', $unitId)->pluck('id')->map(fn ($id) => 'record-' . $id);

        return $accounts->concat($records)->sort()->values()->all();
    }

    protected function tokenFor(array $keys): string
    {
        return sha1(implode(',', $keys));
    }

    /**
     * Saves a unit's whole home team atomically: every change succeeds, or the
     * transaction rolls back and nothing persists — including the audit
     * entries AuditObserver keeps in memory until the end of the request.
     *
     * $desired: ['team_leader' => [key...], 'driver' => [...], 'crew' => [...]]
     *           with keys like "account-12" / "record-7" (homeTeamPeople()).
     * $token:   homeTeamToken() as the dialog saw it; a mismatch is a 409.
     * $expectedHomes: key => home unit id the dialog showed for each changed
     *           person; a mismatch is a 409.
     *
     * Each change uses the existing single-person rules (team leaders through
     * UnitTeamAssignmentService, so job, reservation, already-led and
     * borrowed-leader protections all apply). Team leader removals run before
     * additions, so a replacement works in one save.
     *
     * @return array<int,array{name:string,role:string,change:string}>
     */
    public function saveHomeTeam(int $unitId, array $desired, string $token, array $expectedHomes, User $actor): array
    {
        $checkpoint = AuditObserver::checkpoint();

        try {
            return DB::transaction(fn () => $this->applyHomeTeam($unitId, $desired, $token, $expectedHomes, $actor));
        } catch (Throwable $e) {
            AuditObserver::restore($checkpoint);

            // Postgres deadlock / lock failure: another save touched the same rows.
            if (HomeUnitConflictException::causedBy($e)) {
                throw new HomeTeamSaveException(self::HOME_TEAM_RACE, 409);
            }

            throw $e;
        }
    }

    protected const HOME_TEAM_RACE = 'This team was being changed somewhere else at the same moment. Nothing was saved — please try again.';

    protected function applyHomeTeam(int $unitId, array $desired, string $token, array $expectedHomes, User $actor): array
    {
        // Who the owner wants, by role. Each person may appear once.
        $wanted = [];
        foreach (['team_leader', 'driver', 'crew'] as $role) {
            foreach (array_unique($desired[$role] ?? []) as $key) {
                if (isset($wanted[$key])) {
                    throw new HomeTeamSaveException('The same person was chosen for two roles.');
                }
                $wanted[$key] = $role;
            }
        }

        // Shared lock order: every unit involved (this unit, each affected
        // person's home unit, the unit each affected leader is on), then
        // accounts, then records, each by id. Read the units first, lock them,
        // lock the people, then confirm nobody moved to a unit we didn't lock.
        $affected = collect($this->homeTeamKeys($unitId))->concat(array_keys($wanted))->unique();
        $accountIds = $affected->filter(fn ($key) => str_starts_with($key, 'account-'))->map(fn ($key) => (int) substr($key, 8))->sort()->values();
        $recordIds = $affected->filter(fn ($key) => str_starts_with($key, 'record-'))->map(fn ($key) => (int) substr($key, 7))->sort()->values();

        $units = Unit::lockInIdOrder($this->unitsTouchedBy($unitId, $accountIds, $recordIds));
        $unit = $units->get($unitId);

        if (! $unit || $unit->archived_at) {
            throw new HomeTeamSaveException("This unit is archived, so its home team can't be changed.");
        }

        $accounts = User::whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $records = Personnel::whereIn('id', $recordIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($this->unitsTouchedBy($unitId, $accountIds, $recordIds)->diff($units->keys())->isNotEmpty()) {
            throw new HomeTeamSaveException(self::HOME_TEAM_RACE, 409);
        }

        // The team as it is now, read under the locks. Someone who joined it
        // before the locks were taken changes the token.
        $current = $this->homeTeamKeys($unitId);
        if (! hash_equals($this->tokenFor($current), $token)) {
            throw new HomeTeamSaveException('This home team was changed by someone else since you opened it. Nothing was saved. Close the dialog to see the latest team, then make your changes again.', 409);
        }

        $people = [];
        foreach ($affected as $key) {
            $person = str_starts_with($key, 'account-') ? $accounts->get((int) substr($key, 8)) : $records->get((int) substr($key, 7));

            if (! $person || ($person instanceof User && ($person->archived_at || $person->anonymized_at))) {
                throw new HomeTeamSaveException('One of the chosen people is no longer available. Nothing was saved. Close the dialog and open it again.');
            }

            $role = $person instanceof User
                ? ((int) $person->role_id === 3 ? 'team_leader' : ((int) $person->role_id === 4 ? 'driver' : null))
                : ($person->role === 'driver' ? 'driver' : 'crew');

            if (isset($wanted[$key]) && $wanted[$key] !== $role) {
                throw new HomeTeamSaveException("{$person->full_name} can't be chosen as a " . str_replace('_', ' ', $wanted[$key]) . '. Nothing was saved.');
            }

            $people[$key] = ['model' => $person, 'role' => $role];
        }

        // Work out the changes, then check each one against what the dialog showed.
        $changes = [];
        foreach ($people as $key => $person) {
            $isHome = (int) $person['model']->home_unit_id === $unitId;
            $wantHere = isset($wanted[$key]);

            if ($isHome === $wantHere) {
                continue;
            }

            // null is a real value here ("no home unit"), so test presence explicitly.
            $actualHome = $person['model']->home_unit_id ? (int) $person['model']->home_unit_id : null;
            $expected = array_key_exists($key, $expectedHomes)
                ? ($expectedHomes[$key] === null ? null : (int) $expectedHomes[$key])
                : 'missing';
            if ($expected === 'missing' || $expected !== $actualHome) {
                throw new HomeTeamSaveException("{$person['model']->full_name}'s home unit changed since you opened this dialog. Nothing was saved. Close the dialog to see the latest team, then make your changes again.", 409);
            }

            $changes[] = ['key' => $key, 'to' => $wantHere ? $unitId : null] + $person;
        }

        // Team leader removals, team leader additions, then everyone else.
        usort($changes, fn ($a, $b) => $this->changeOrder($a) <=> $this->changeOrder($b));

        $saved = [];
        foreach ($changes as $change) {
            $model = $change['model'];

            try {
                if ($change['role'] === 'team_leader') {
                    app(UnitTeamAssignmentService::class)->setTeamLeaderRegularUnit($model, $change['to'], $actor);
                } elseif ($model instanceof User) {
                    $this->setDriverAccountHomeUnit($model, $change['to']);
                } else {
                    $this->setRecordHomeUnit($model, $change['to']);
                }
            } catch (HomeUnitConflictException) {
                throw new HomeTeamSaveException(self::HOME_TEAM_RACE, 409);
            } catch (\PDOException $e) {
                // A database error, not a refused rule (QueryException is a
                // RuntimeException too). saveHomeTeam() turns lock conflicts into a 409.
                throw $e;
            } catch (RuntimeException $e) {
                throw new HomeTeamSaveException(
                    "Nothing was saved. {$model->full_name} can't be " . ($change['to'] ? 'added to' : 'removed from') . " this home team: {$e->getMessage()}"
                );
            }

            $saved[] = ['name' => $model->full_name, 'role' => $change['role'], 'change' => $change['to'] ? 'added' : 'removed'];
        }

        return $saved;
    }

    /**
     * Every unit a Home Team save of $unitId can touch: the unit itself, each
     * affected person's home unit, and the unit each affected team leader is
     * on now.
     */
    protected function unitsTouchedBy(int $unitId, Collection $accountIds, Collection $recordIds): Collection
    {
        return collect([$unitId])
            ->concat(User::whereIn('id', $accountIds)->pluck('home_unit_id'))
            ->concat(Unit::whereIn('team_leader_id', $accountIds)->pluck('id'))
            ->concat(Personnel::whereIn('id', $recordIds)->pluck('home_unit_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    protected function changeOrder(array $change): int
    {
        return ($change['role'] === 'team_leader' ? 0 : 2) + ($change['to'] ? 1 : 0);
    }
}
