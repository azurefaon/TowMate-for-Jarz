<?php

namespace App\Services\Finance;

use App\Models\Finance\CommissionDeduction;
use App\Models\Finance\CommissionPolicy;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseFunding;
use App\Models\Finance\JobPersonnel;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * THE commission calculation (one authoritative implementation). Read-only: it never writes a statement, line or payout.
 * Statement approval and payouts are Phases 6 and 7.
 *
 * For every completed job in a Monday–Saturday week, and for every towing unit the job's verified collection is
 * allocated to (Jobs & Collections decides that), one line:
 *
 *   Sharing base = allocated verified collection
 *                − that unit's APPROVED expenses for the job (any funding source, each counted once)
 *                − that unit's APPROVED deductions for the job (fees, refunds, damage; each counted once)
 *
 * Unit fund issuances and returns never enter the base: they are cash movements, not costs. A unit/monthly expense is
 * not applied to any job; it blocks approval until the client's allocation rule exists.
 *
 * Every role's percentage applies to the same sharing base (Company 60, Driver 12, Team Leader 10, Rider/Substitute 10,
 * Crew Mate 8 initially). Shares are rounded half-up to the centavo; the rounding remainder goes to Company, so the
 * shares always add up to the base exactly. A role with nobody recorded keeps its share UNALLOCATED (never
 * redistributed). People come only from finance_job_personnel (who actually worked the job), never a unit's current crew.
 *
 * The result is a DRAFT FOR CLIENT REVIEW until the commission interpretation (C1–C6) is confirmed. Anything that
 * would make an approval wrong is listed as a blocker; approval is possible only with zero blockers.
 */
class CommissionCalculator
{
    public const ROLES = ['driver', 'team_leader', 'rider', 'crew_mate'];

    public const ROLE_LABELS = [
        'company' => 'Company',
        'driver' => 'Driver',
        'team_leader' => 'Team Leader',
        'rider' => 'Rider / Substitute',
        'crew_mate' => 'Crew Mate',
    ];

    /** Provisional slot → role mapping, used only to SHOW amounts while the role mapping (C4) is unconfirmed. */
    public const PROVISIONAL_SLOT_ROLES = [
        'team_leader' => 'team_leader',
        'driver_1' => 'driver',
        'driver_2' => 'rider',
        'crew_member_1' => 'crew_mate',
        'crew_member_2' => 'crew_mate',
    ];

    /** The policy of the draft being calculated (rule confirmations are read from it). */
    private ?CommissionPolicy $policy = null;

    public function __construct(private readonly JobCollectionService $collections)
    {
    }

    /**
     * @return array{week_start:CarbonImmutable, week_end:CarbonImmutable, policy:array, status:string, can_approve:bool,
     *               blockers:array<int,array{scope:string,code:string,message:string}>, notices:string[], lines:array, blocked_jobs:array, totals:array}
     */
    /**
     * @param  string[]|null  $jobKeys  limit the draft to these jobs (a statement's selection); null = every job in the week
     */
    public function draft(CarbonImmutable $weekStart, ?int $unitId = null, ?CommissionPolicy $policy = null, ?array $jobKeys = null): array
    {
        $tz = config('finance.timezone');
        $weekStart = $weekStart->setTimezone($tz)->startOfDay();
        $weekEnd = $weekStart->addDays((int) config('finance.week_length_days') - 1)->endOfDay();

        $blockers = [];
        $notices = [];
        if ($weekStart->dayOfWeekIso !== (int) config('finance.week_start_iso_day')) {
            $blockers[] = $this->blocker('period', 'week_not_monday', 'A commission week must start on a Monday.');
        }

        // ---- policy
        $policy ??= CommissionPolicy::where('status', CommissionPolicy::STATUS_ACTIVE)->first()
            ?? CommissionPolicy::orderByDesc('version')->first();
        $this->policy = $policy;
        $rates = $policy ? $policy->rates() : [];
        $ratesValid = $policy !== null && $this->ratesAreValid($rates);
        if (! $policy) {
            $blockers[] = $this->blocker('policy', 'no_policy', 'No commission policy exists.');
        } elseif (! $ratesValid) {
            $blockers[] = $this->blocker('policy', 'invalid_percentages', 'Commission percentages are invalid: each must be between 0 and 100 and together they must total exactly 100%.');
        }
        if ($policy && ($policy->status !== CommissionPolicy::STATUS_ACTIVE || ! $policy->isConfirmed())) {
            $blockers[] = $this->blocker('policy', 'policy_for_client_review',
                'Draft / For Client Review: the commission interpretation (sharing base, pool or per person, empty roles, role mapping) is not yet confirmed by the client.');
        }

        // ---- jobs in the week (by service date)
        $jobs = $this->collections->jobs($weekStart, $weekEnd, ['basis' => 'service', 'unit_id' => $unitId]);
        if ($jobKeys !== null) {
            $jobs = $jobs->filter(fn (array $job) => in_array($job['key'], $jobKeys, true))->values();
        }
        $sunday = $weekEnd->addDay();
        $sundayJobs = $this->collections->jobs($sunday->startOfDay(), $sunday->endOfDay(), ['basis' => 'service', 'unit_id' => $unitId])->count();
        if ($sundayJobs > 0) {
            $notices[] = "{$sundayJobs} job(s) on Sunday " . $sunday->format('M j') . ' are not in this Monday–Saturday week. Which week Sunday belongs to is pending client confirmation.';
        }

        $lines = [];
        $blockedJobs = [];
        $unitIdsInWeek = [];
        foreach ($jobs as $job) {
            $jobBlockers = $this->jobBlockers($job);
            $allocation = $job['allocation'];

            if (! in_array($allocation['status'], ['automatic', 'manual'], true)) {
                // Nothing allocatable to a unit yet: no line can be computed for this job.
                $blockedJobs[] = ['job' => $job, 'blockers' => $jobBlockers ?: [$this->blocker('job', 'not_allocated', $allocation['status_label'])]];
                foreach ($job['units'] as $u) {
                    if ($u['unit_id']) {
                        $unitIdsInWeek[$u['unit_id']] = true;
                    }
                }
                continue;
            }

            foreach ($allocation['by_unit'] as $lineUnitId => $alloc) {
                if ($unitId && (int) $lineUnitId !== (int) $unitId) {
                    continue;
                }
                $unitIdsInWeek[$lineUnitId] = true;
                $lines[] = $this->line($job, (int) $lineUnitId, $alloc, $rates, $ratesValid, $jobBlockers, count($allocation['by_unit']));
            }
        }

        // ---- unit / monthly expenses touching these units in this week: not applied, but they block approval.
        $monthly = $this->unitMonthlyExpenses(array_keys($unitIdsInWeek), $weekStart, $weekEnd);
        foreach ($monthly as $expense) {
            $blockers[] = $this->blocker('unit', 'monthly_allocation_pending',
                "Unit / monthly expense {$expense->reference_code} ({$expense->unit?->name}, " . Money::of($expense->amount)->format() . ') has no allocation rule yet (pending client confirmation).');
            if ($expense->fundings->contains('source', ExpenseFunding::SOURCE_PERSONAL)) {
                $blockers[] = $this->blocker('unit', 'personal_advance_pending', "Unit / monthly expense {$expense->reference_code} includes a personal advance that needs clarification.");
            }
        }

        foreach ($lines as $line) {
            foreach ($line['blockers'] as $b) {
                $blockers[] = $b;
            }
        }
        foreach ($blockedJobs as $bj) {
            foreach ($bj['blockers'] as $b) {
                $blockers[] = $b;
            }
        }
        if ($jobs->isEmpty()) {
            $notices[] = 'No completed jobs in this week.';
        }

        $blockers = collect($blockers)->unique(fn ($b) => $b['scope'] . '|' . $b['code'] . '|' . $b['message'])->values()->all();

        return [
            'week_start' => $weekStart,
            'week_end' => $weekEnd->startOfDay(),
            'unit_id' => $unitId,
            'policy' => $policy ? [
                'id' => $policy->id,
                'version' => $policy->version,
                'status' => $policy->status,
                'interpretation_status' => $policy->interpretation_status,
                'rates' => $rates,
                'confirmed_rules' => $policy->confirmed_rules ?? [],
            ] : null,
            'status' => 'draft_for_client_review',
            'can_approve' => $blockers === [] && $lines !== [],
            'blockers' => $blockers,
            'notices' => $notices,
            'lines' => $lines,
            'blocked_jobs' => $blockedJobs,
            'monthly_expenses' => $monthly->all(),
            'totals' => $this->totals($lines),
        ];
    }

    // ------------------------------------------------------------------ one job × unit

    private function line(array $job, int $unitId, array $alloc, array $rates, bool $ratesValid, array $jobBlockers, int $unitsOnJob): array
    {
        $memberIds = collect($job['members'])->pluck('id')->all();
        $blockers = $jobBlockers;
        $ref = $job['reference'];

        // Expenses: this unit's approved job expenses, each counted once.
        $expenses = Expense::whereIn('booking_id', $memberIds)->where('unit_id', $unitId)
            ->where('status', Expense::STATUS_APPROVED)->with('fundings')->orderBy('id')->get();
        foreach ($expenses as $e) {
            if ($e->fundings->contains('source', ExpenseFunding::SOURCE_PERSONAL)) {
                $blockers[] = $this->blocker('job', 'personal_advance_pending', "Job {$ref}: expense {$e->reference_code} includes a personal advance that needs clarification (reimbursement rule pending).");
            }
        }
        $expenseTotal = Money::sum($expenses->pluck('amount'));

        // Deductions: approved ones reduce the base; pending ones block approval.
        $deductions = CommissionDeduction::whereIn('booking_id', $memberIds)->where('unit_id', $unitId)
            ->where('status', '!=', CommissionDeduction::STATUS_VOIDED)->orderBy('id')->get();
        $approvedDeductions = $deductions->where('status', CommissionDeduction::STATUS_APPROVED);
        foreach ($deductions->where('status', CommissionDeduction::STATUS_PENDING) as $d) {
            $blockers[] = $this->blocker('job', 'deduction_pending', "Job {$ref}: deduction {$d->reference_code} (" . Money::of($d->amount)->format() . ') is awaiting review.');
        }
        $deductionTotal = Money::sum($approvedDeductions->pluck('amount'));

        // A live online payment carries a provider fee TowMate does not record: it must be entered as a deduction first.
        $hasLivePayment = collect($job['transactions'])->contains(fn ($t) => $t->status === 'paid' && $t->livemode);
        if ($hasLivePayment && ! $approvedDeductions->contains('deduction_type', 'payment_fee')) {
            $blockers[] = $this->blocker('job', 'payment_fee_unresolved', "Job {$ref}: the online payment fee has not been recorded as an approved deduction.");
        }

        $collection = Money::of($alloc['amount']);
        $base = $collection->minus($expenseTotal)->minus($deductionTotal);
        if (! $base->isPositive()) {
            $blockers[] = $this->blocker('job', 'non_positive_base', "Job {$ref} ({$alloc['unit_name']}): the sharing base is {$base->format()}. Commission needs a base above zero.");
        }

        // Personnel actually recorded for this job and unit.
        $people = JobPersonnel::current()->whereIn('booking_id', $memberIds)
            ->where(fn ($q) => $unitsOnJob === 1 ? $q->where('unit_id', $unitId)->orWhereNull('unit_id') : $q->where('unit_id', $unitId))
            ->orderBy('id')->get();
        if ($people->isEmpty()) {
            $blockers[] = $this->blocker('job', 'no_personnel', "Job {$ref} ({$alloc['unit_name']}): no personnel were recorded for this job. Nobody is assigned automatically.");
        }

        $byRole = [];
        foreach ($people as $p) {
            $role = $p->commission_role ?: (self::PROVISIONAL_SLOT_ROLES[$p->slot] ?? null);
            if (! $p->commission_role) {
                $blockers[] = $this->blocker('job', 'role_mapping_unresolved', "Job {$ref}: {$p->name_snapshot} ({$p->slot}) has no confirmed commission role. Shown provisionally as " . (self::ROLE_LABELS[$role] ?? 'none') . '.');
            }
            if ($p->needs_review) {
                $blockers[] = $this->blocker('job', 'personnel_needs_review', "Job {$ref}: the record for {$p->name_snapshot} still needs Owner review.");
            }
            if ($role && in_array($role, self::ROLES, true)) {
                $byRole[$role][] = $p;
            }
            if (($p->is_substitute || $p->borrowed_from_unit_id) && ! $this->confirms('substitutes')) {
                $blockers[] = $this->blocker('job', 'substitute_rule_pending', "Job {$ref}: {$p->name_snapshot} is recorded as a substitute or borrowed from another unit. How substitutes are paid is pending client confirmation.");
            }
        }
        if (! empty($byRole['rider']) && ! $this->confirms('rider_role')) {
            $blockers[] = $this->blocker('job', 'rider_role_pending', "Job {$ref}: a Rider / Substitute is recorded. Who receives the Rider share is pending client confirmation.");
        }
        $rolesByPerson = [];
        foreach ($byRole as $role => $holders) {
            foreach ($holders as $p) {
                $rolesByPerson[self::recipientKey($p)][$role] = $p->name_snapshot;
            }
        }
        foreach ($rolesByPerson as $roles) {
            if (count($roles) > 1 && ! $this->confirms('combined_roles')) {
                $blockers[] = $this->blocker('job', 'combined_role_rule_pending', "Job {$ref}: " . reset($roles) . ' is recorded in more than one role (' . implode(', ', array_map(fn ($r) => self::ROLE_LABELS[$r], array_keys($roles))) . '). Whether one person receives both shares is pending client confirmation.');
            }
        }

        // Shares of the base.
        $roleRows = [];
        $company = Money::zero();
        $roundingToCompany = Money::zero();
        if ($ratesValid && $base->isPositive()) {
            $split = $base->allocateByPercent($rates, 'company');
            $company = $split['parts']['company'];
            $roundingToCompany = $split['remainder'];
            foreach (self::ROLES as $role) {
                $roleRows[$role] = $this->roleRow($role, $rates[$role], $split['parts'][$role], $byRole[$role] ?? [], $ref, $blockers);
            }
        } else {
            foreach (self::ROLES as $role) {
                $roleRows[$role] = $this->roleRow($role, $rates[$role] ?? '0', Money::zero(), $byRole[$role] ?? [], $ref, $blockers);
            }
        }

        $allocated = Money::sum(collect($roleRows)->pluck('allocated'));
        $unallocated = Money::sum(collect($roleRows)->pluck('unallocated'));

        return [
            'job_key' => $job['key'],
            'reference' => $ref,
            'group_code' => $job['group_code'],
            'booking_id' => $job['anchor']->id,
            'member_booking_ids' => $memberIds,
            'allocation_status' => $job['allocation']['status'],
            'service_date' => $job['service_date'],
            'unit_id' => $unitId,
            'unit_name' => $alloc['unit_name'],
            'vehicles' => $alloc['vehicles'],
            'collection' => $collection,
            'expenses' => $expenses->map(fn (Expense $e) => ['id' => $e->id, 'reference' => $e->reference_code, 'category' => $e->categoryLabel(), 'amount' => Money::of($e->amount)])->all(),
            'expense_total' => $expenseTotal,
            'deductions' => $approvedDeductions->map(fn (CommissionDeduction $d) => ['id' => $d->id, 'reference' => $d->reference_code, 'type' => $d->typeLabel(), 'amount' => Money::of($d->amount)])->values()->all(),
            'deduction_total' => $deductionTotal,
            'base' => $base,
            'company' => $company,
            'company_rounding' => $roundingToCompany,
            'roles' => $roleRows,
            'crew_allocated' => $allocated,
            'unallocated' => $unallocated,
            'check' => $company->plus($allocated)->plus($unallocated),
            'blockers' => collect($blockers)->unique(fn ($b) => $b['code'] . '|' . $b['message'])->values()->all(),
        ];
    }

    /** One role's share and who receives it. Nobody recorded: the whole share stays unallocated. */
    private function roleRow(string $role, string $rate, Money $share, array $people, string $ref, array &$blockers): array
    {
        $recipients = [];
        if ($people === []) {
            if ($share->isPositive()) {
                $blockers[] = $this->blocker('job', 'missing_role', "Job {$ref}: no " . self::ROLE_LABELS[$role] . " was recorded; {$share->format()} stays unallocated.");
            }

            return ['role' => $role, 'label' => self::ROLE_LABELS[$role], 'rate' => $rate, 'share' => $share, 'recipients' => [], 'allocated' => Money::zero(), 'unallocated' => $share];
        }

        if (count($people) > 1 && ! $this->confirms('shared_role')) {
            $blockers[] = $this->blocker('job', 'shared_role_rule_pending', "Job {$ref}: " . count($people) . ' people share the ' . self::ROLE_LABELS[$role] . ' role. Whether they split one share or each receive it is pending client confirmation; shown split equally.');
        }

        // Provisional equal split of the role's share, exact to the centavo (remainder to the first person).
        $n = count($people);
        $parts = array_fill(0, $n, $share->isPositive() ? $share->shareOf(1, $n) : Money::zero());
        $parts[0] = $parts[0]->plus($share->minus(Money::sum($parts)));
        foreach ($people as $i => $p) {
            $recipients[] = [
                'name' => $p->name_snapshot,
                'slot' => $p->slot,
                'personnel_id' => $p->personnel_id,
                'user_id' => $p->user_id,
                'job_personnel_id' => $p->id,
                'recipient_key' => self::recipientKey($p),
                'is_substitute' => (bool) ($p->is_substitute || $p->borrowed_from_unit_id),
                'role_confirmed' => (bool) $p->commission_role,
                'amount' => $parts[$i],
            ];
        }

        return ['role' => $role, 'label' => self::ROLE_LABELS[$role], 'rate' => $rate, 'share' => $share, 'recipients' => $recipients, 'allocated' => $share, 'unallocated' => Money::zero()];
    }

    private function jobBlockers(array $job): array
    {
        $ref = $job['reference'];
        $b = [];
        if (! $job['service_completed']) {
            $b[] = $this->blocker('job', 'job_unfinished', "Job {$ref}: the job is not finished.");
        }
        if ($job['status'] !== 'paid') {
            $b[] = $this->blocker('job', 'payment_not_verified', "Job {$ref}: customer payment is {$job['status_label']} (collected {$job['collected']->format()} of {$job['agreed']->format()}).");
        }
        if ($job['allocation']['status'] === 'pending_review') {
            $b[] = $this->blocker('job', 'allocation_pending', "Job {$ref}: unit revenue allocation is pending review ({$job['allocation']['reason']}).");
        }
        if ($job['expenses']['pending']->isPositive()) {
            $b[] = $this->blocker('job', 'expenses_pending', "Job {$ref}: expenses of {$job['expenses']['pending']->format()} are awaiting review.");
        }
        if (isset($job['flags']['refund_review'])) {
            $b[] = $this->blocker('job', 'refund_unresolved', "Job {$ref}: an online payment is flagged for refund review.");
        }

        return $b;
    }

    private function unitMonthlyExpenses(array $unitIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return Expense::where('expense_scope', 'unit')
            ->whereIn('unit_id', $unitIds)
            ->whereNotIn('status', [Expense::STATUS_REJECTED, Expense::STATUS_VOIDED])
            ->where(fn ($q) => $q->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
                ->orWhereBetween('coverage_month', [$from->startOfMonth()->toDateString(), $to->startOfMonth()->toDateString()]))
            ->with(['unit:id,name', 'fundings'])
            ->orderBy('id')
            ->get();
    }

    private function totals(array $lines): array
    {
        $c = collect($lines);
        $byRole = [];
        $byRecipient = [];
        foreach ($lines as $line) {
            foreach ($line['roles'] as $role => $row) {
                $byRole[$role] ??= ['label' => $row['label'], 'share' => Money::zero(), 'allocated' => Money::zero(), 'unallocated' => Money::zero()];
                $byRole[$role]['share'] = $byRole[$role]['share']->plus($row['share']);
                $byRole[$role]['allocated'] = $byRole[$role]['allocated']->plus($row['allocated']);
                $byRole[$role]['unallocated'] = $byRole[$role]['unallocated']->plus($row['unallocated']);
                foreach ($row['recipients'] as $r) {
                    $byRecipient[$r['recipient_key']] ??= ['name' => $r['name'], 'roles' => [], 'amount' => Money::zero(), 'jobs' => 0];
                    $byRecipient[$r['recipient_key']]['amount'] = $byRecipient[$r['recipient_key']]['amount']->plus($r['amount']);
                    $byRecipient[$r['recipient_key']]['roles'][$row['label']] = true;
                    $byRecipient[$r['recipient_key']]['jobs']++;
                }
            }
        }

        $base = Money::sum($c->pluck('base'));
        $company = Money::sum($c->pluck('company'));
        $crew = Money::sum($c->pluck('crew_allocated'));
        $unallocated = Money::sum($c->pluck('unallocated'));

        return [
            'lines' => $c->count(),
            'collection' => Money::sum($c->pluck('collection')),
            'expenses' => Money::sum($c->pluck('expense_total')),
            'deductions' => Money::sum($c->pluck('deduction_total')),
            'base' => $base,
            'company' => $company,
            'crew_allocated' => $crew,
            'unallocated' => $unallocated,
            'reconciles' => $company->plus($crew)->plus($unallocated)->equals($c->filter(fn ($l) => $l['base']->isPositive())->pluck('base')->reduce(fn ($s, $b) => $s->plus($b), Money::zero())),
            'by_role' => $byRole,
            'by_recipient' => collect($byRecipient)->map(fn ($r) => $r + ['role_list' => implode(', ', array_keys($r['roles']))])->sortBy('name')->values()->all(),
        ];
    }

    /** Stable payee key: the personnel record, else the user account, else the recorded name. */
    public static function recipientKey(JobPersonnel $p): string
    {
        return $p->personnel_id ? "personnel:{$p->personnel_id}" : ($p->user_id ? "user:{$p->user_id}" : 'name:' . md5(mb_strtolower(trim($p->name_snapshot))));
    }

    private function confirms(string $rule): bool
    {
        return $this->policy !== null && $this->policy->confirmsRule($rule);
    }

    private function ratesAreValid(array $rates): bool
    {
        $total = '0';
        foreach (array_merge(['company'], self::ROLES) as $role) {
            $rate = (string) ($rates[$role] ?? '');
            if (! preg_match('/^\d{1,3}(\.\d{1,4})?$/', $rate) || bccomp($rate, '100', 4) > 0) {
                return false;
            }
            $total = bcadd($total, $rate, 4);
        }

        return bccomp($total, '100', 4) === 0;
    }

    private function blocker(string $scope, string $code, string $message): array
    {
        return ['scope' => $scope, 'code' => $code, 'message' => $message];
    }
}
