<?php

namespace App\Services\Finance;

use App\Models\Finance\JobPersonnel;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Read-only driver (and crew) records: completed jobs and vehicles towed per week, each person's history, and the
 * DRAFT commission per eligible job and per week.
 *
 * People come from the job crew records only: crew captured from Dispatch at assignment (Pending confirmation
 * until the Owner confirms) and crew the Owner recorded or confirmed. A unit's current roster is never read, and a
 * completed job with nobody recorded is listed as "not yet recorded", never as zero work or an invented driver.
 *
 * Jobs come from JobCollectionService (service date): a group trip is one job with several vehicles. Draft
 * commission comes from CommissionCalculator (through FinanceOverviewService::draftCommission); nothing is
 * calculated here. Only a completed, FULLY PAID job (authoritative JobCollectionService status 'paid') shows and adds
 * its draft amount. A partially paid job shows "Pending Collection", an unpaid or unverified one "Awaiting Payment":
 * they still count as completed work but never add to the commission totals. Unfinished jobs are not counted at all.
 * Weeks start on Monday; a Sunday job is shown in the week of the Monday before it.
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

    public function __construct(private readonly JobCollectionService $collections, private readonly FinanceOverviewService $overview)
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

        // Draft commission per job and person, straight from the calculator.
        $draft = [];
        foreach ($this->overview->draftCommission(array_values($weeks), $unitId)['lines'] as $line) {
            foreach ($line['roles'] as $row) {
                foreach ($row['recipients'] as $r) {
                    $draft[$line['job_key']][$r['recipient_key']] = ($draft[$line['job_key']][$r['recipient_key']] ?? Money::zero())->plus($r['amount']);
                }
            }
        }

        $jobs = $this->collections->jobs($from, $to, ['basis' => 'service', 'unit_id' => $unitId])
            ->filter(fn (array $job) => $job['service_completed'])->values();

        $people = [];
        $unrecorded = [];
        foreach ($jobs as $job) {
            $crew = JobPersonnel::current()->whereIn('booking_id', collect($job['members'])->pluck('id'))
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
                $pending = $rows->contains(fn (JobPersonnel $r) => $r->isPending());
                // Draft commission only for a fully paid job; partial and unpaid jobs stay visible but earn nothing yet.
                $commissionState = CommissionEligibility::state($job['status']);
                $eligible = $commissionState === CommissionEligibility::ELIGIBLE;
                $amount = $eligible ? ($draft[$job['key']][$key] ?? null) : null;

                $people[$key] ??= ['key' => $key, 'name' => $rows->first()->name_snapshot, 'roles' => [], 'units' => [], 'weeks' => [],
                    'jobs' => 0, 'confirmed_jobs' => 0, 'pending_jobs' => 0, 'vehicles' => 0, 'draft' => Money::zero(), 'pending_collection' => 0, 'awaiting_payment' => 0, 'under_review' => 0, 'first' => null, 'last' => null, 'history' => []];
                $p = &$people[$key];
                $p['weeks'][$week] ??= ['jobs' => 0, 'vehicles' => 0, 'pending' => 0, 'draft' => Money::zero(), 'pending_collection' => 0, 'awaiting_payment' => 0, 'under_review' => 0];
                $p['jobs']++;
                $p['vehicles'] += $vehicles;
                $p['weeks'][$week]['jobs']++;
                $p['weeks'][$week]['vehicles'] += $vehicles;
                if ($pending) {
                    $p['pending_jobs']++;
                    $p['weeks'][$week]['pending']++;
                } else {
                    $p['confirmed_jobs']++;
                }
                if ($commissionState !== 'eligible') {
                    $p[$commissionState]++;
                    $p['weeks'][$week][$commissionState]++;
                }
                if ($amount) {
                    $p['draft'] = $p['draft']->plus($amount);
                    $p['weeks'][$week]['draft'] = $p['weeks'][$week]['draft']->plus($amount);
                }
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
                    'week' => $week, 'sunday' => $job['service_date']->isSunday(), 'customer' => $job['customer']?->full_name,
                    'unit' => $rows->pluck('unit.name')->filter()->unique()->implode(', '), 'role' => $rows->map->roleLabel()->unique()->implode(', '),
                    'substitute' => (bool) $rows->contains(fn ($r) => $r->is_substitute || $r->borrowed_from_unit_id),
                    'pending' => $pending, 'source' => $rows->map->sourceLabel()->unique()->implode(', '),
                    'vehicles' => $vehicles,
                    'draft' => $amount, 'commission_state' => $commissionState, 'payment_status' => $job['status'], 'payment_label' => $job['status_label'],
                    'draft_note' => $amount ? null : ($eligible ? 'No draft yet (allocation or week pending)' : CommissionEligibility::LABELS[$commissionState]),
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
                'pending_participations' => (int) collect($people)->sum('pending_jobs'),
                'draft' => Money::sum(collect($people)->pluck('draft')),   // fully paid jobs only
                // Completed jobs by payment state (each job once, whether or not its crew is recorded).
                'paid_jobs' => $jobs->where('status', 'paid')->count(),
                'pending_collection' => $jobs->where('status', 'partial')->count(),
                'awaiting_payment' => $jobs->whereIn('status', ['unpaid', 'awaiting_verification', 'completed_unverified'])->count(),
                'under_review' => $jobs->where('status', 'overpaid')->count(),
            ],
        ];
    }
}
