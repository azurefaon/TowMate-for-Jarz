<?php

namespace App\Services\Finance;

use App\Models\Finance\JobPersonnel;
use Carbon\CarbonImmutable;

/**
 * Read-only driver (and crew) records: completed jobs and vehicles towed per week, and each person's history.
 *
 * Source of truth: the Owner's Job Crew records (who actually worked each job, per unit and role). Bookings do not
 * store a driver, and a unit's current roster is never used, so a job counts for a person only once its crew is
 * recorded. Jobs with nobody recorded in the chosen role are counted separately so the gap stays visible.
 *
 * Jobs come from JobCollectionService (service date), so a group trip is one job with several vehicles.
 * Weeks start on Monday; a Sunday job is shown in the week of the Monday before it and flagged.
 * This service never calculates commission.
 */
class DriverRecordService
{
    public const ROLE_FILTERS = [
        'driver' => 'Drivers',
        'rider' => 'Riders / Substitutes',
        'team_leader' => 'Team Leaders',
        'crew_mate' => 'Crew Mates',
        'all' => 'All crew roles',
    ];

    public function __construct(private readonly JobCollectionService $collections)
    {
    }

    public function build(CarbonImmutable $from, CarbonImmutable $to, ?int $unitId = null, string $role = 'driver'): array
    {
        $tz = config('finance.timezone');
        $from = $from->setTimezone($tz)->startOfDay();
        $to = $to->setTimezone($tz)->endOfDay();
        $role = array_key_exists($role, self::ROLE_FILTERS) ? $role : 'driver';

        $weeks = [];
        for ($m = $from->startOfWeek(CarbonImmutable::MONDAY); $m->lessThanOrEqualTo($to) && count($weeks) < 60; $m = $m->addWeek()) {
            $weeks[$m->toDateString()] = $m;
        }

        $jobs = $this->collections->jobs($from, $to, ['basis' => 'service', 'unit_id' => $unitId])
            ->filter(fn (array $job) => $job['service_completed'])->values();

        $people = [];
        $unrecorded = [];
        foreach ($jobs as $job) {
            $memberIds = collect($job['members'])->pluck('id');
            $crew = JobPersonnel::current()->whereIn('booking_id', $memberIds)
                ->when($role !== 'all', fn ($q) => $q->where('commission_role', $role))
                ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
                ->with('unit:id,name')->orderBy('id')->get();

            if ($crew->isEmpty()) {
                $unrecorded[] = ['reference' => $job['reference'], 'booking_id' => $job['anchor']->id, 'service_date' => $job['service_date'],
                    'units' => collect($job['units'])->pluck('unit_name')->filter()->unique()->implode(', ')];
                continue;
            }

            $week = $job['service_date']->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            // One person counts a job once, even when recorded in two roles or for two units.
            foreach ($crew->groupBy(fn (JobPersonnel $p) => CommissionCalculator::recipientKey($p)) as $key => $rows) {
                $unitIds = $rows->pluck('unit_id')->filter()->unique();
                $vehicles = $job['is_group']
                    ? collect($job['units'])->filter(fn ($u) => $unitIds->isEmpty() || $unitIds->contains($u['unit_id']))->count()
                    : (int) $job['vehicles'];

                $people[$key] ??= ['key' => $key, 'name' => $rows->first()->name_snapshot, 'roles' => [], 'units' => [], 'weeks' => [],
                    'jobs' => 0, 'vehicles' => 0, 'first' => null, 'last' => null, 'history' => []];
                $p = &$people[$key];
                $p['jobs']++;
                $p['vehicles'] += $vehicles;
                $p['weeks'][$week] ??= ['jobs' => 0, 'vehicles' => 0];
                $p['weeks'][$week]['jobs']++;
                $p['weeks'][$week]['vehicles'] += $vehicles;
                foreach ($rows as $r) {
                    $p['roles'][$r->roleLabel()] = true;
                    if ($r->unit) {
                        $p['units'][$r->unit->name] = true;
                    }
                }
                $p['first'] = $p['first'] === null || $job['service_date']->lessThan($p['first']) ? $job['service_date'] : $p['first'];
                $p['last'] = $p['last'] === null || $job['service_date']->greaterThan($p['last']) ? $job['service_date'] : $p['last'];
                $p['history'][] = [
                    'reference' => $job['reference'], 'booking_id' => $job['anchor']->id, 'group_code' => $job['group_code'], 'service_date' => $job['service_date'],
                    'sunday' => $job['service_date']->isSunday(), 'customer' => $job['customer']?->full_name,
                    'unit' => $rows->pluck('unit.name')->filter()->unique()->implode(', '), 'role' => $rows->map->roleLabel()->unique()->implode(', '),
                    'substitute' => (bool) $rows->contains(fn ($r) => $r->is_substitute || $r->borrowed_from_unit_id),
                    'vehicles' => $vehicles, 'payment_status' => $job['status_label'],
                ];
                unset($p);
            }
        }

        foreach ($people as &$p) {
            $p['role_list'] = implode(', ', array_keys($p['roles']));
            $p['unit_list'] = implode(', ', array_keys($p['units']));
            $p['weeks_active'] = count($p['weeks']);
            usort($p['history'], fn ($a, $b) => $b['service_date'] <=> $a['service_date']);
        }
        unset($p);
        uasort($people, fn ($a, $b) => [$b['jobs'], $a['name']] <=> [$a['jobs'], $b['name']]);

        $weekTotals = [];
        foreach (array_keys($weeks) as $w) {
            $weekTotals[$w] = ['jobs' => 0, 'vehicles' => 0];
        }

        return [
            'from' => $from->startOfDay(),
            'to' => $to->startOfDay(),
            'role' => $role,
            'role_label' => self::ROLE_FILTERS[$role],
            'weeks' => $weeks,
            'people' => $people,
            'unrecorded' => $unrecorded,
            'totals' => [
                'people' => count($people),
                'completed_jobs' => $jobs->count(),
                'jobs_with_record' => $jobs->count() - count($unrecorded),
                'jobs_without_record' => count($unrecorded),
                'vehicles' => (int) $jobs->sum('vehicles'),
            ],
        ];
    }
}
