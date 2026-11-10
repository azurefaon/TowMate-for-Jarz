<?php

namespace App\Services\Finance;

use App\Models\Finance\CommissionStatement;
use App\Models\Finance\Expense;
use App\Models\Finance\JobPersonnel;
use App\Models\Finance\Payout;
use App\Models\Personnel;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Owner Finance Reports. One data set feeds the page, the printable report / PDF and the Excel workbook, so all three
 * always show the same totals. Nothing is calculated here that another service owns:
 *
 *   Jobs, collections, outstanding, allocations   JobCollectionService
 *   Expenses (summary, by category / unit / status)   ExpenseService
 *   Unit funds (opening carried forward)          UnitFundLedger
 *   Draft commission                              CommissionCalculator (through FinanceOverviewService::draftCommission)
 *   Approved commission                           approved / Paid statements and their frozen approval snapshots
 *   Payouts                                       recorded payouts only
 *
 * Draft, approved and paid amounts are separate sections and are never added together. Rules still pending client
 * confirmation (Sunday jobs, late collections, unsettled allocations, monthly allocation, role rules) are listed for
 * review, never resolved here.
 */
class FinanceReportService
{
    public const STATEMENT_FILTERS = [
        'final' => 'Approved and Paid',
        'approved' => 'Approved (not yet Paid)',
        'paid' => 'Paid',
        'voided' => 'Voided',
        'open' => 'Draft and For Review',
    ];

    public const EXPENSE_FILTERS = [
        'pending' => 'Awaiting review (Dispatcher or Owner)',
        'submitted' => 'Awaiting Dispatcher verification',
        'verified' => 'Awaiting Owner approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'voided' => 'Voided',
    ];

    public function __construct(
        private readonly JobCollectionService $collections,
        private readonly ExpenseService $expenses,
        private readonly UnitFundLedger $ledger,
        private readonly FinanceOverviewService $overview,
    ) {
    }

    /**
     * @param array{unit_id?:?int, personnel?:?string, payment_status?:?string, expense_status?:?string, statement_status?:?string} $filters
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $tz = config('finance.timezone');
        $from = $from->setTimezone($tz)->startOfDay();
        $to = $to->setTimezone($tz)->endOfDay();
        $unitId = $filters['unit_id'] ?? null;
        $person = $this->parsePerson($filters['personnel'] ?? null);
        $statementFilter = array_key_exists($filters['statement_status'] ?? '', self::STATEMENT_FILTERS) ? $filters['statement_status'] : 'final';
        $expenseFilter = array_key_exists($filters['expense_status'] ?? '', self::EXPENSE_FILTERS) ? $filters['expense_status'] : null;
        $paymentFilter = $filters['payment_status'] ?? null;
        if ($paymentFilter !== null && $paymentFilter !== 'outstanding' && ! array_key_exists($paymentFilter, JobCollectionService::COLLECTION_STATUSES)) {
            $paymentFilter = null;
        }

        // ---- jobs and collections (service date)
        $jobs = $this->collections->jobs($from, $to, ['basis' => 'service', 'unit_id' => $unitId, 'status' => $paymentFilter]);
        if ($person) {
            $jobs = $jobs->filter(fn (array $job) => $this->personWorked($job, $person))->values();
        }
        $collectionTotals = $this->collections->totals($jobs);

        // ---- review items (shown, never resolved)
        $weeks = $this->overview->weeks($from, $to);
        $sundayJobs = collect();
        foreach ($weeks as $monday) {
            $sunday = $monday->addDays(6);
            if ($sunday->betweenIncluded($from, $to)) {
                continue;   // already in the job list, and flagged
            }
            $sundayJobs = $sundayJobs->merge($this->collections->jobs($sunday->startOfDay(), $sunday->endOfDay(), ['basis' => 'service', 'unit_id' => $unitId]));
        }
        $lateCollections = $this->collections->jobs($from, $to, ['basis' => 'payment', 'unit_id' => $unitId])
            ->filter(fn (array $job) => $job['service_date'] && $job['service_date']->lessThan($from))->values();
        $unsettled = $jobs->filter(fn (array $job) => $job['status'] !== 'paid' || ! in_array($job['allocation']['status'], ['automatic', 'manual'], true))->values();

        // ---- expenses (expense date)
        $expenseQuery = Expense::query()->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->when($expenseFilter, fn ($q) => $expenseFilter === 'pending' ? $q->whereIn('status', Expense::PENDING_STATUSES) : $q->where('status', $expenseFilter));
        $expenseSummary = $this->expenses->summary($expenseQuery);
        $expenseBreakdown = $this->expenses->breakdown($expenseQuery);

        // ---- unit funds (opening carries forward from before the period)
        $fundRows = $this->ledger->summary($from, $to, $unitId);
        $fundTotals = $this->ledger->totals($fundRows);

        // ---- draft commission (estimate)
        $draft = $this->overview->draftCommission($weeks, $unitId);
        $personDraft = $person ? ($draft['by_recipient'][$person['key']] ?? null) : null;

        // ---- approved commission (frozen snapshots) and recorded payouts
        $mondays = array_map(fn (CarbonImmutable $m) => $m->toDateString(), $weeks);
        $statuses = match ($statementFilter) {
            'approved' => [CommissionStatement::STATUS_APPROVED],
            'paid' => [CommissionStatement::STATUS_PAID],
            'voided' => [CommissionStatement::STATUS_VOIDED],
            'open' => CommissionStatement::OPEN_STATUSES,
            default => [CommissionStatement::STATUS_APPROVED, CommissionStatement::STATUS_PAID],
        };
        $statements = CommissionStatement::with('unit:id,name')->whereIn('period_start', $mondays)->whereIn('status', $statuses)
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))->orderBy('period_start')->orderBy('unit_id')->get();
        if ($person) {
            $statements = $statements->filter(fn (CommissionStatement $s) => $this->personLines($s, $person['key'])->isNotEmpty())->values();
        }
        $frozen = $statements->whereIn('status', [CommissionStatement::STATUS_APPROVED, CommissionStatement::STATUS_PAID, CommissionStatement::STATUS_VOIDED]);
        $approved = [
            'filter' => $statementFilter,
            'filter_label' => self::STATEMENT_FILTERS[$statementFilter],
            'is_draft_only' => $statementFilter === 'open',
            'count' => $statements->count(),
            'base' => Money::sum($statements->pluck('total_base')),
            'company' => Money::sum($statements->pluck('company_amount')),
            'personnel' => Money::sum($statements->pluck('personnel_amount')),
            'person_amount' => $person ? Money::sum($frozen->flatMap(fn ($s) => $this->personLines($s, $person['key'])->pluck('amount'))) : null,
            'by_recipient' => $this->approvedByRecipient($frozen, $person['key'] ?? null),
            'rows' => $statements->map(fn (CommissionStatement $s) => [
                'reference' => $s->reference_code, 'period_start' => $s->period_start, 'period_end' => $s->period_end, 'unit' => $s->unit?->name,
                'status' => $s->status, 'status_label' => $s->statusLabel(), 'jobs' => (int) $s->job_count, 'base' => Money::of($s->total_base),
                'company' => Money::of($s->company_amount), 'personnel' => Money::of($s->personnel_amount), 'approved_at' => $s->approved_at, 'approved_by' => $s->approved_by_name,
            ])->all(),
        ];

        $payoutStatements = CommissionStatement::whereIn('period_start', $mondays)
            ->whereIn('status', [CommissionStatement::STATUS_APPROVED, CommissionStatement::STATUS_PAID])
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))->pluck('reference_code', 'id');
        $payouts = Payout::whereIn('statement_id', $payoutStatements->keys())->where('status', Payout::STATUS_RECORDED)
            ->when($person, fn ($q) => $q->where('recipient_key', $person['key']))->orderBy('paid_at')->orderBy('id')->get();
        $payoutData = [
            'count' => $payouts->count(),
            'total' => Money::sum($payouts->pluck('amount')),
            'by_method' => $payouts->groupBy('payout_method')->map(fn ($g, $m) => ['label' => config('finance.payout_methods')[$m] ?? $m, 'count' => $g->count(), 'total' => Money::sum($g->pluck('amount'))])->values()->all(),
            'rows' => $payouts->map(fn (Payout $p) => [
                'reference' => $p->reference_code, 'statement' => $payoutStatements[$p->statement_id] ?? null, 'recipient' => $p->recipient_name, 'roles' => $p->recipient_roles,
                'paid_at' => $p->paid_at, 'method' => $p->methodLabel(), 'external_reference' => $p->external_reference, 'recorded_by' => $p->recorded_by_name, 'amount' => Money::of($p->amount),
            ])->all(),
        ];

        $policy = $draft['policy'];
        $policyModel = $policy ? \App\Models\Finance\CommissionPolicy::find($policy['id']) : null;
        $policyConfirmed = (bool) $policyModel?->isClientConfirmedActive();

        return [
            'meta' => [
                'from' => $from->startOfDay(),
                'to' => $to->startOfDay(),
                'weeks_from' => $weeks[0] ?? null,
                'weeks_to' => $weeks ? end($weeks)->addDays((int) config('finance.week_length_days') - 1) : null,
                'unit' => $unitId ? Unit::find($unitId)?->name : null,
                'person' => $person['name'] ?? null,
                'payment_status' => $paymentFilter ? ($paymentFilter === 'outstanding' ? 'With an outstanding balance' : JobCollectionService::COLLECTION_STATUSES[$paymentFilter]) : null,
                'expense_status' => $expenseFilter ? self::EXPENSE_FILTERS[$expenseFilter] : null,
                'statement_status' => self::STATEMENT_FILTERS[$statementFilter],
                'policy_version' => $policy['version'] ?? null,
                'policy_confirmed' => $policyConfirmed,
                'policy_label' => \App\Models\Finance\CommissionPolicy::labelForId($policy['id'] ?? null),
                'timezone' => $tz,
            ],
            'jobs' => [
                'totals' => $collectionTotals,
                'rows' => $jobs->map(fn (array $j) => $this->jobRow($j))->all(),
            ],
            'review' => [
                'sunday' => $sundayJobs->map(fn (array $j) => $this->jobRow($j))->values()->all(),
                'late' => $lateCollections->map(fn (array $j) => $this->jobRow($j))->all(),
                'unsettled' => $unsettled->map(fn (array $j) => $this->jobRow($j))->all(),
                'blockers' => $draft['blockers'],
                'notices' => array_values(array_unique($draft['notices'])),
            ],
            'expenses' => ['summary' => $expenseSummary] + $expenseBreakdown,
            'funds' => ['rows' => $fundRows->values()->all(), 'totals' => $fundTotals],
            'draft' => $draft + ['person' => $personDraft],
            'approved' => $approved,
            'payouts' => $payoutData,
        ];
    }

    /** Historical participants recorded on any job (Owner crew records), for the personnel filter. */
    public function personnelOptions(): array
    {
        $rows = JobPersonnel::query()->select('personnel_id', 'user_id', 'name_snapshot')->distinct()->get();
        $options = [];
        foreach ($rows as $r) {
            $key = $r->personnel_id ? "personnel:{$r->personnel_id}" : ($r->user_id ? "user:{$r->user_id}" : null);
            if ($key) {
                $options[$key] ??= $r->name_snapshot;
            }
        }
        asort($options);

        return $options;
    }

    // ------------------------------------------------------------------ internals

    /** @return array{key:string, personnel_id:?int, user_id:?int, name:string}|null */
    private function parsePerson(?string $key): ?array
    {
        if (! is_string($key) || ! preg_match('/^(personnel|user):(\d+)$/', $key, $m)) {
            return null;
        }
        $id = (int) $m[2];
        $name = $m[1] === 'personnel' ? Personnel::find($id)?->full_name : (($u = User::find($id)) ? ($u->full_name ?: $u->name) : null);
        $name ??= JobPersonnel::where($m[1] === 'personnel' ? 'personnel_id' : 'user_id', $id)->value('name_snapshot');
        if ($name === null) {
            return null;
        }

        return ['key' => $key, 'personnel_id' => $m[1] === 'personnel' ? $id : null, 'user_id' => $m[1] === 'user' ? $id : null, 'name' => $name];
    }

    private function personWorked(array $job, array $person): bool
    {
        return JobPersonnel::current()->whereIn('booking_id', collect($job['members'])->pluck('id'))
            ->when($person['personnel_id'], fn ($q) => $q->where('personnel_id', $person['personnel_id']))
            ->when($person['user_id'], fn ($q) => $q->where('user_id', $person['user_id']))
            ->exists();
    }

    /** The person's lines in a statement's frozen snapshot (or stored lines for an open statement). */
    private function personLines(CommissionStatement $s, string $key): Collection
    {
        $lines = $s->approval_snapshot['lines'] ?? $s->lines()->get()->map(fn ($l) => ['recipient_key' => $l->recipient_key, 'recipient_name' => $l->recipient_name, 'commission_role' => $l->commission_role, 'amount' => Money::of($l->amount)->toString()])->all();

        return collect($lines)->where('recipient_key', $key)->values();
    }

    private function approvedByRecipient(Collection $statements, ?string $onlyKey): array
    {
        $result = [];
        foreach ($statements as $s) {
            if ($s->status === CommissionStatement::STATUS_VOIDED) {
                continue;   // voided statements are listed, never counted as approved earnings
            }
            foreach ($s->approval_snapshot['lines'] ?? [] as $line) {
                $k = $line['recipient_key'];
                if (in_array($k, ['company', 'unallocated'], true) || ($onlyKey && $k !== $onlyKey)) {
                    continue;
                }
                $result[$k] ??= ['name' => $line['recipient_name'], 'roles' => [], 'amount' => Money::zero()];
                $result[$k]['amount'] = $result[$k]['amount']->plus($line['amount']);
                $result[$k]['roles'][CommissionCalculator::ROLE_LABELS[$line['commission_role']] ?? $line['commission_role']] = true;
            }
        }
        uasort($result, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return array_values(array_map(fn ($r) => $r + ['role_list' => implode(', ', array_keys($r['roles']))], $result));
    }

    private function jobRow(array $j): array
    {
        return [
            'reference' => $j['reference'],
            'group_code' => $j['group_code'],
            'service_date' => $j['service_date'],
            'payment_date' => $j['payment_date'],
            'customer' => $j['customer']?->full_name,
            'units' => collect($j['units'])->pluck('unit_name')->filter()->unique()->implode(', '),
            'vehicles' => (int) $j['vehicles'],
            'agreed' => $j['agreed'],
            'collected' => $j['collected'],
            'outstanding' => $j['outstanding'],
            'status_label' => $j['status_label'],
            'allocation_label' => $j['allocation']['status_label'],
            'allocation' => collect($j['allocation']['by_unit'])->map(fn ($a) => $a['unit_name'] . ' ' . $a['amount']->format())->implode('; '),
            'flags' => array_values($j['flags']),
        ];
    }
}
