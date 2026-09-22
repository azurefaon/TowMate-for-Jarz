<?php

namespace App\Services;

use App\Models\Personnel;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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

    public function listPersonnel(string $search = '', string $roleFilter = '', int $perPage = 7, int $page = 1): LengthAwarePaginator
    {
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
            'status' => $person->archived_at ? 'Archived' : ($person->personnel_enabled ? 'Active' : 'Inactive'),
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

    protected function findCurrentUnitForRecord(Personnel $record): ?Unit
    {
        $columns = $record->role === 'driver'
            ? ['driver_name', 'driver_2_name']
            : ['crew_member_1_name', 'crew_member_2_name'];

        foreach ($columns as $column) {
            $unit = Unit::whereNull('archived_at')
                ->whereRaw("lower(trim({$column})) = ?", [strtolower(trim($record->full_name))])
                ->first();

            if ($unit) {
                return $unit;
            }
        }

        return null;
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

    public function homeAssignmentsRoster(): Collection
    {
        $homeTeamLeaders = User::where('role_id', 3)
            ->whereNotNull('home_unit_id')
            ->whereNull('archived_at')
            ->whereNull('anonymized_at')
            ->get()
            ->keyBy('home_unit_id');

        $homeDriverAccounts = User::where('role_id', 4)
            ->whereNotNull('home_unit_id')
            ->whereNull('archived_at')
            ->whereNull('anonymized_at')
            ->get()
            ->keyBy('home_unit_id');

        $homeDriverRecords = Personnel::where('role', 'driver')
            ->whereNotNull('home_unit_id')
            ->get()
            ->keyBy('home_unit_id');

        $homeCrewRecords = Personnel::where('role', 'crew')
            ->whereNotNull('home_unit_id')
            ->get()
            ->groupBy('home_unit_id');

        return Unit::whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Unit $unit) => [
                'unit' => $unit,
                'team_leader' => $homeTeamLeaders->get($unit->id),
                'driver' => $homeDriverAccounts->get($unit->id) ?? $homeDriverRecords->get($unit->id),
                'crew' => $homeCrewRecords->get($unit->id, collect())->pluck('full_name')->values()->all(),
            ]);
    }
}
