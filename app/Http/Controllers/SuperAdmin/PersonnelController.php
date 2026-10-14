<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Exceptions\HomeTeamSaveException;
use App\Exceptions\HomeUnitConflictException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Personnel;
use App\Models\Unit;
use App\Models\User;
use App\Services\PersonnelService;
use App\Services\UnitTeamAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class PersonnelController extends Controller
{
    public function __construct(
        protected PersonnelService $personnel,
        protected UnitTeamAssignmentService $assignment,
    ) {
    }

    /** Field messages shared by the add and edit forms. */
    protected const MESSAGES = [
        'first_name.required' => 'Enter a first name.',
        'last_name.required' => 'Enter a last name.',
        'role.required' => 'Choose a role.',
        'role.in' => 'Choose Driver or Crew Member.',
        'home_unit_id.exists' => 'Choose a unit from the list.',
        'home_unit_id.integer' => 'Choose a unit from the list.',
    ];

    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'role' => array_key_exists((string) $request->query('role'), PersonnelService::ROLE_FILTERS) ? (string) $request->query('role') : '',
            'status' => array_key_exists((string) $request->query('status'), PersonnelService::STATUS_FILTERS) ? (string) $request->query('status') : '',
        ];

        $page = max(1, (int) $request->query('page', 1));
        $personnel = $this->personnel->listPersonnel($filters['search'], $filters['role'], 7, $page, $filters['status']);

        // A filter change can leave the requested page past the end.
        if ($personnel->isEmpty() && $page > 1) {
            return redirect($personnel->url(max(1, $personnel->lastPage())));
        }

        return view('superadmin.personnel.index', [
            'personnel' => $personnel,
            'units' => Unit::whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'hasFilters' => $filters['search'] !== '' || $filters['role'] !== '' || $filters['status'] !== '',
            'roleFilters' => PersonnelService::ROLE_FILTERS,
            'statusFilters' => PersonnelService::STATUS_FILTERS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in(['driver', 'crew'])],
            'home_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('archived_at')],
        ], self::MESSAGES);

        if ($validated['role'] === 'driver' && $this->matchesDriverAccount($validated)) {
            return back()->withInput()->withErrors([
                'first_name' => 'A Driver account with this name already exists. Use the existing account instead of adding a duplicate.',
            ]);
        }

        try {
            $record = $this->personnel->createRecord([
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'role' => $validated['role'],
                'home_unit_id' => $validated['home_unit_id'] ?? null,
                'personnel_status' => 'active',
            ]);
        } catch (RuntimeException $e) {
            return $this->homeUnitRefused($request, $e);
        }

        return back()->with('success', "{$record->full_name} added.");
    }

    public function update(Request $request, Personnel $record)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in(['driver', 'crew'])],
            // Optional: lets the edit form save the home unit in the same submit.
            'home_unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('units', 'id')->whereNull('archived_at')],
        ], self::MESSAGES);

        if ($validated['role'] !== $record->role && $this->isPlacedOrStaged($record)) {
            return back()->withInput()->withErrors([
                'role' => 'This person is currently placed on a unit or staged to a Team Leader. Remove them first before changing their role.',
            ]);
        }

        $record->update(collect($validated)->except('home_unit_id')->all());
        $record->refresh();

        // The home unit is its own locked write; if it's refused, the other
        // details still save (and are audited) and the form says so.
        $homeRefused = null;
        if ($request->has('home_unit_id')) {
            try {
                $this->changeRecordHomeUnit($record, $validated['home_unit_id'] ?? null);
            } catch (RuntimeException $e) {
                $homeRefused = $this->rethrowDatabaseErrors($e);
            }
        }

        foreach (Unit::SLOT_PERSONNEL_COLUMNS as $slot => $idColumn) {
            Unit::where($idColumn, $record->id)->update([Unit::SLOT_COLUMNS[$slot] => $record->full_name]);
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'personnel_updated',
            'entity_type' => 'Personnel',
            'entity_id' => $record->id,
            'reference' => $record->full_name,
            'description' => "{$record->full_name} updated.",
        ]);

        if ($homeRefused) {
            return $this->homeUnitRefused($request, $homeRefused, "{$record->full_name}'s details were saved, but the home unit wasn't changed. ");
        }

        return back()->with('success', "{$record->full_name} updated.");
    }

    /**
     * A home-unit write that was refused (422) or collided with another
     * change (HomeUnitConflictException, 409). Nothing from it was saved.
     */
    protected function homeUnitRefused(Request $request, RuntimeException $e, string $prefix = '')
    {
        $this->rethrowDatabaseErrors($e);
        $status = $e instanceof HomeUnitConflictException ? $e->status : 422;

        if ($request->expectsJson()) {
            return response()->json(['message' => $prefix . $e->getMessage()], $status);
        }

        return back()->withInput()->withErrors(['home_unit_id' => $prefix . $e->getMessage()]);
    }

    /** QueryException is a RuntimeException too; a database error is not a refusal to show the owner. */
    protected function rethrowDatabaseErrors(RuntimeException $e): RuntimeException
    {
        if ($e instanceof \PDOException) {
            throw $e;
        }

        return $e;
    }

    protected function matchesDriverAccount(array $validated): bool
    {
        $needle = mb_strtolower(trim(build_full_name($validated['first_name'], $validated['middle_name'] ?? null, $validated['last_name'])));

        return User::where('role_id', 4)
            ->whereNull('archived_at')
            ->whereNull('anonymized_at')
            ->get()
            ->contains(fn (User $driver) => mb_strtolower(trim((string) $driver->full_name)) === $needle);
    }

    protected function isPlacedOrStaged(Personnel $record): bool
    {
        return Unit::unitIdsHoldingPersonnel($record->id)->isNotEmpty()
            || User::where(function ($q) use ($record) {
                $q->where('driver_personnel_id', $record->id)
                    ->orWhere('crew_member_1_personnel_id', $record->id)
                    ->orWhere('crew_member_2_personnel_id', $record->id);
            })->exists();
    }

    public function updateRecordHomeUnit(Request $request, Personnel $record)
    {
        $validated = $request->validate([
            'home_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('archived_at')],
        ], self::MESSAGES);

        try {
            $changed = $this->changeRecordHomeUnit($record, $validated['home_unit_id'] ?? null);
        } catch (RuntimeException $e) {
            return $this->homeUnitRefused($request, $e);
        }

        return back()->with('success', $changed ? 'Home Unit updated.' : 'Home Unit is unchanged.');
    }

    /** Sets a personnel record's home unit and audits it. Returns false when unchanged. */
    protected function changeRecordHomeUnit(Personnel $record, $newHomeUnitId): bool
    {
        return $this->personnel->setRecordHomeUnit($record, $newHomeUnitId ? (int) $newHomeUnitId : null);
    }

    public function toggleRecordStatus(Personnel $record)
    {
        $newStatus = $record->personnel_status === 'active' ? 'inactive' : 'active';

        $record->update(['personnel_status' => $newStatus]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $newStatus === 'active' ? 'personnel_activated' : 'personnel_deactivated',
            'entity_type' => 'Personnel',
            'entity_id' => $record->id,
            'reference' => $record->full_name,
            'description' => "{$record->full_name} set to " . ucfirst($newStatus) . '.',
        ]);

        return back()->with('success', $newStatus === 'active'
            ? "{$record->full_name} is active and can be placed on a unit."
            : "{$record->full_name} is inactive and can't be placed on a unit.");
    }

    public function updateHomeUnit(Request $request, User $person)
    {
        if (! in_array((int) $person->role_id, PersonnelService::ACCOUNT_ROLE_IDS, true)) {
            abort(404);
        }

        $validated = $request->validate([
            'home_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('archived_at')],
        ], self::MESSAGES);

        $newHomeUnitId = $validated['home_unit_id'] ?? null;

        if ((int) $person->home_unit_id === (int) $newHomeUnitId) {
            return back()->with('success', 'Home Unit is unchanged.');
        }

        try {
            if ((int) $person->role_id === 3) {
                $this->personnel->setTeamLeaderHomeUnit($person, $newHomeUnitId ? (int) $newHomeUnitId : null, $request->user());
            } else {
                $this->personnel->setDriverAccountHomeUnit($person, $newHomeUnitId ? (int) $newHomeUnitId : null);
            }
        } catch (RuntimeException $e) {
            return $this->homeUnitRefused($request, $e);
        }

        return back()->with('success', 'Home Unit updated.');
    }

    /**
     * Edit Home Team: saves the whole team for one unit in a single
     * transaction (PersonnelService::saveHomeTeam). JSON only.
     */
    public function saveHomeTeam(Request $request, int $unit)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:40'],
            'team_leader' => ['present', 'array'],
            'driver' => ['present', 'array'],
            'crew' => ['present', 'array'],
            'team_leader.*' => ['string', 'regex:/^account-\d+$/'],
            'driver.*' => ['string', 'regex:/^(account|record)-\d+$/'],
            'crew.*' => ['string', 'regex:/^record-\d+$/'],
            'expected' => ['present', 'array'],
            'expected.*' => ['nullable', 'integer'],
        ]);

        try {
            $saved = $this->personnel->saveHomeTeam(
                $unit,
                ['team_leader' => $validated['team_leader'], 'driver' => $validated['driver'], 'crew' => $validated['crew']],
                $validated['token'],
                $validated['expected'],
                $request->user(),
            );
        } catch (HomeTeamSaveException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        // The transaction has committed by the time this runs.
        return response()->json([
            'saved' => $saved,
            'token' => $this->personnel->homeTeamToken($unit),
        ]);
    }

    public function toggleEnabled(User $person)
    {
        if (! in_array((int) $person->role_id, PersonnelService::ACCOUNT_ROLE_IDS, true)) {
            abort(404);
        }

        $newState = ! $person->personnel_enabled;

        $person->update(['personnel_enabled' => $newState]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $newState ? 'personnel_activated' : 'personnel_deactivated',
            'entity_type' => 'User',
            'entity_id' => $person->id,
            'reference' => $person->full_name,
            'description' => "{$person->full_name} set to " . ($newState ? 'Active' : 'Inactive') . '.',
        ]);

        return back()->with('success', $newState ? 'Personnel activated.' : 'Personnel deactivated.');
    }

    public function homeAssignments(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'show' => $request->query('show') === 'missing' ? 'missing' : '',
        ];

        $people = $this->personnel->homeTeamPeople();
        $roster = $this->personnel->homeAssignmentsRoster($people);

        // Search matches the unit name, its plate (spaces ignored), or anyone on its home team.
        $searched = $roster;
        if ($filters['search'] !== '') {
            $needle = mb_strtolower($filters['search']);
            $plateNeedle = str_replace(' ', '', $needle);
            $searched = $roster->filter(function (array $entry) use ($needle, $plateNeedle) {
                $names = $entry['team_leaders']->concat($entry['drivers'])->concat($entry['crew'])->pluck('name');

                return str_contains(mb_strtolower($entry['unit']->name), $needle)
                    || ($plateNeedle !== '' && str_contains(mb_strtolower((string) $entry['unit']->plate_number), $plateNeedle))
                    || $names->contains(fn ($name) => str_contains(mb_strtolower($name), $needle));
            })->values();
        }

        $shown = $filters['show'] === 'missing' ? $searched->reject(fn (array $entry) => $entry['complete'])->values() : $searched;

        return view('superadmin.personnel.home-assignments', [
            'roster' => $shown,
            'filters' => $filters,
            'hasFilters' => $filters['search'] !== '' || $filters['show'] !== '',
            'searchedCount' => $searched->count(),
            'missingCount' => $searched->reject(fn (array $entry) => $entry['complete'])->count(),
            'totalUnits' => $roster->count(),
            'people' => $people,
            'units' => $roster->pluck('unit'),
            'teamTokens' => $this->personnel->homeTeamTokens($people, $roster->pluck('unit.id')),
        ]);
    }
}
