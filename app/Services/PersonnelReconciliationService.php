<?php

namespace App\Services;

use App\Models\Personnel;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PersonnelReconciliationService
{
    public const LINKED = 'linked';
    public const INACTIVE_MATCH = 'inactive_match';
    public const AMBIGUOUS = 'ambiguous';
    public const UNMATCHED = 'unmatched';

    public function plan(): Collection
    {
        $entries = collect()
            ->concat($this->unitEntries())
            ->concat($this->stagedEntries());

        $entries = $this->classify($entries);

        return $entries->concat($this->loanEntries($entries));
    }

    public function apply(): int
    {
        $linked = 0;

        DB::transaction(function () use (&$linked) {
            foreach ($this->plan()->where('status', self::LINKED) as $entry) {
                DB::table($entry['table'])
                    ->where('id', $entry['row_id'])
                    ->whereNull($entry['column'])
                    ->update([$entry['column'] => $entry['personnel_id']]);

                $linked++;
            }
        });

        return $linked;
    }

    public function summarize(Collection $plan): array
    {
        return $plan->groupBy('status')->map->count()->all();
    }

    protected function unitEntries(): Collection
    {
        $entries = collect();

        Unit::whereNull('archived_at')->get()->each(function (Unit $unit) use ($entries) {
            foreach (Unit::SLOT_COLUMNS as $slot => $nameColumn) {
                $idColumn = Unit::SLOT_PERSONNEL_COLUMNS[$slot];

                if (blank($unit->{$nameColumn}) || $unit->{$idColumn}) {
                    continue;
                }

                if ($slot === 'driver_1' && $unit->driver_id) {
                    continue;
                }

                $entries->push([
                    'scope' => 'unit',
                    'table' => 'units',
                    'row_id' => $unit->id,
                    'column' => $idColumn,
                    'label' => "{$unit->name} / {$slot}",
                    'name' => $unit->{$nameColumn},
                    'role' => str_starts_with($slot, 'driver') ? 'driver' : 'crew',
                ]);
            }
        });

        return $entries;
    }

    protected function stagedEntries(): Collection
    {
        $entries = collect();

        User::where('role_id', 3)->get()->each(function (User $leader) use ($entries) {
            $staged = [
                'driver_personnel_id' => ['driver', build_full_name($leader->driver_first_name, $leader->driver_middle_name, $leader->driver_last_name)],
                'crew_member_1_personnel_id' => ['crew', $leader->crew_member_1_name],
                'crew_member_2_personnel_id' => ['crew', $leader->crew_member_2_name],
            ];

            foreach ($staged as $column => [$role, $name]) {
                if (blank($name) || $leader->{$column}) {
                    continue;
                }

                $entries->push([
                    'scope' => 'staged',
                    'table' => 'users',
                    'row_id' => $leader->id,
                    'column' => $column,
                    'label' => "Team Leader #{$leader->id} / {$column}",
                    'name' => $name,
                    'role' => $role,
                ]);
            }
        });

        return $entries;
    }

    protected function classify(Collection $entries): Collection
    {
        $personnel = Personnel::all();

        $claims = $entries
            ->groupBy(fn (array $entry) => $entry['scope'] . '|' . $entry['role'] . '|' . $this->normalize($entry['name']))
            ->map->count();

        return $entries->map(function (array $entry) use ($personnel, $claims) {
            $matches = $personnel->filter(fn (Personnel $record) => $record->role === $entry['role']
                && $this->normalize($record->full_name) === $this->normalize($entry['name']));

            $active = $matches->where('personnel_status', 'active');
            $claimCount = $claims[$entry['scope'] . '|' . $entry['role'] . '|' . $this->normalize($entry['name'])] ?? 1;

            if ($active->count() === 1 && $claimCount === 1) {
                return $entry + ['status' => self::LINKED, 'personnel_id' => $active->first()->id];
            }

            if ($active->count() > 1 || ($active->count() === 1 && $claimCount > 1)) {
                return $entry + ['status' => self::AMBIGUOUS, 'personnel_id' => null];
            }

            if ($matches->isNotEmpty()) {
                return $entry + ['status' => self::INACTIVE_MATCH, 'personnel_id' => null];
            }

            return $entry + ['status' => self::UNMATCHED, 'personnel_id' => null];
        });
    }

    protected function loanEntries(Collection $classified): Collection
    {
        $entries = collect();
        $linkedUnits = $classified->where('scope', 'unit')->where('status', self::LINKED);

        UnitCrewLoan::whereNull('returned_at')->whereNull('personnel_id')->whereNull('person_user_id')->get()
            ->each(function (UnitCrewLoan $loan) use ($entries, $linkedUnits) {
                $toUnit = Unit::find($loan->to_unit_id);
                $toColumn = Unit::SLOT_PERSONNEL_COLUMNS[$loan->to_slot] ?? null;
                $personnelId = $toUnit && $toColumn ? $toUnit->{$toColumn} : null;

                if (! $personnelId) {
                    $match = $linkedUnits->first(fn (array $entry) => $entry['row_id'] === $loan->to_unit_id && $entry['column'] === $toColumn);
                    $personnelId = $match['personnel_id'] ?? null;
                }

                $record = $personnelId ? Personnel::find($personnelId) : null;
                $matches = $record && $this->normalize($record->full_name) === $this->normalize($loan->person_name);

                $entries->push([
                    'scope' => 'loan',
                    'table' => 'unit_crew_loans',
                    'row_id' => $loan->id,
                    'column' => 'personnel_id',
                    'label' => "Active loan #{$loan->id}",
                    'name' => $loan->person_name,
                    'role' => str_starts_with($loan->from_slot, 'driver') ? 'driver' : 'crew',
                    'status' => $matches ? self::LINKED : self::UNMATCHED,
                    'personnel_id' => $matches ? $personnelId : null,
                ]);
            });

        return $entries;
    }

    protected function normalize(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $value)));
    }
}
