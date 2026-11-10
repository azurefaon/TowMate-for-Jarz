<?php

namespace App\Services\Finance;

use App\Models\Finance\CommissionStatement;
use App\Models\Finance\Expense;
use App\Models\Finance\Payout;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Read-only Owner Finance Overview. It adds nothing of its own: every figure comes from the service that owns it,
 * and the four kinds of money are kept apart:
 *
 *   1. Actual collections   JobCollectionService (verified collections and outstanding balances, by service date)
 *   2. Costs and cash       ExpenseService::summary (expenses by review status), UnitFundLedger (issued/spent/returned)
 *   3. Draft estimates      CommissionCalculator, week by week (not approved, may change)
 *   4. Approved / paid      approved and Paid statements, and recorded payouts
 *
 * Commission works in Monday–Saturday weeks, so sections 3 and 4 cover every week that starts inside the period
 * (the week containing the period start included).
 */
class FinanceOverviewService
{
    public function __construct(
        private readonly JobCollectionService $collections,
        private readonly ExpenseService $expenses,
        private readonly UnitFundLedger $ledger,
        private readonly CommissionCalculator $calculator,
    ) {
    }

    public function build(CarbonImmutable $from, CarbonImmutable $to, ?int $unitId = null): array
    {
        $tz = config('finance.timezone');
        $from = $from->setTimezone($tz)->startOfDay();
        $to = $to->setTimezone($tz)->endOfDay();

        // 1. Collections
        $jobs = $this->collections->jobs($from, $to, ['basis' => 'service', 'unit_id' => $unitId]);
        $collections = $this->collections->totals($jobs);

        // 2. Expenses and unit funds
        $expenseQuery = Expense::query()->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId));
        $expenses = $this->expenses->summary($expenseQuery);
        $funds = $this->ledger->totals($this->ledger->summary($from, $to, $unitId));

        // 3. Draft commission, week by week
        $weeks = $this->weeks($from, $to);
        $draft = $this->draftCommission($weeks, $unitId);

        // 4. Approved commission and recorded payouts (frozen figures only)
        $mondays = array_map(fn (CarbonImmutable $m) => $m->toDateString(), $weeks);
        $statements = CommissionStatement::whereIn('period_start', $mondays)->when($unitId, fn ($q) => $q->where('unit_id', $unitId))->get();
        $final = $statements->whereIn('status', [CommissionStatement::STATUS_APPROVED, CommissionStatement::STATUS_PAID]);
        $recorded = Payout::whereIn('statement_id', $final->pluck('id'))->where('status', Payout::STATUS_RECORDED)->get(['amount']);
        $approved = [
            'count' => $final->count(),
            'paid_count' => $final->where('status', CommissionStatement::STATUS_PAID)->count(),
            'open_count' => $statements->whereIn('status', CommissionStatement::OPEN_STATUSES)->count(),
            'for_review_count' => $statements->where('status', CommissionStatement::STATUS_FOR_REVIEW)->count(),
            'base' => Money::sum($final->pluck('total_base')),
            'company' => Money::sum($final->pluck('company_amount')),
            'personnel' => Money::sum($final->pluck('personnel_amount')),
            'payouts_recorded' => Money::sum($recorded->pluck('amount')),
            'payouts_count' => $recorded->count(),
        ];
        $approved['payouts_remaining'] = $approved['personnel']->minus($approved['payouts_recorded']);

        return [
            'from' => $from->startOfDay(),
            'to' => $to->startOfDay(),
            'unit_id' => $unitId,
            'collections' => $collections,
            'expenses' => $expenses,
            'funds' => $funds,
            'draft' => $draft,
            'approved' => $approved,
            'weeks_from' => $weeks[0] ?? null,
            'weeks_to' => $weeks ? end($weeks)->addDays((int) config('finance.week_length_days') - 1) : null,
            'pending' => [
                'expenses_submitted' => $expenses['submitted'],
                'expenses_verified' => $expenses['verified'],
                'missing_evidence' => $expenses['missing_evidence'],
                'allocations' => $collections['pending_allocations'],
                'statements_for_review' => $approved['for_review_count'],
            ],
        ];
    }


    /**
     * The draft commission (CommissionCalculator) summed over Monday–Saturday weeks. Shared with the Finance Reports.
     *
     * @param  CarbonImmutable[]  $weeks
     */
    public function draftCommission(array $weeks, ?int $unitId = null): array
    {
        $draft = ['base' => Money::zero(), 'company' => Money::zero(), 'crew' => Money::zero(), 'unallocated' => Money::zero(), 'collection' => Money::zero(),
            'expenses' => Money::zero(), 'deductions' => Money::zero(), 'by_role' => [], 'by_recipient' => [], 'blockers' => [], 'blocker_messages' => [],
            'notices' => [], 'weeks' => [], 'lines' => [], 'policy' => null];
        foreach ($weeks as $monday) {
            $d = $this->calculator->draft($monday, $unitId);
            $t = $d['totals'];
            foreach (['collection' => 'collection', 'base' => 'base', 'company' => 'company', 'crew' => 'crew_allocated', 'unallocated' => 'unallocated', 'expenses' => 'expenses', 'deductions' => 'deductions'] as $to => $from) {
                $draft[$to] = $draft[$to]->plus($t[$from]);
            }
            foreach ($t['by_role'] as $role => $row) {
                $draft['by_role'][$role] ??= ['label' => $row['label'], 'allocated' => Money::zero(), 'unallocated' => Money::zero()];
                $draft['by_role'][$role]['allocated'] = $draft['by_role'][$role]['allocated']->plus($row['allocated']);
                $draft['by_role'][$role]['unallocated'] = $draft['by_role'][$role]['unallocated']->plus($row['unallocated']);
            }
            foreach ($d['lines'] as $line) {
                $draft['lines'][] = $line;
                foreach ($line['roles'] as $row) {
                    foreach ($row['recipients'] as $r) {
                        $k = $r['recipient_key'];
                        $draft['by_recipient'][$k] ??= ['recipient_key' => $k, 'name' => $r['name'], 'roles' => [], 'amount' => Money::zero(), 'jobs' => 0];
                        $draft['by_recipient'][$k]['amount'] = $draft['by_recipient'][$k]['amount']->plus($r['amount']);
                        $draft['by_recipient'][$k]['roles'][$row['label']] = true;
                        $draft['by_recipient'][$k]['jobs']++;
                    }
                }
            }
            foreach ($d['blockers'] as $b) {
                $draft['blockers'][$b['code']] = ($draft['blockers'][$b['code']] ?? 0) + 1;
                $draft['blocker_messages'][] = $b['message'];
            }
            $draft['notices'] = array_merge($draft['notices'], $d['notices']);
            $draft['weeks'][] = ['monday' => $monday, 'lines' => count($d['lines']), 'blockers' => count($d['blockers']), 'base' => $t['base']];
            $draft['policy'] = $d['policy'];
        }
        arsort($draft['blockers']);
        $draft['by_role'] = CommissionCalculator::inRoleOrder($draft['by_role']);
        uasort($draft['by_recipient'], fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $draft;
    }

    /** @return CarbonImmutable[] Mondays of every commission week starting inside the period (plus the one containing its start). */
    public function weeks(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $weeks = [];
        $monday = $from->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $guard = 0;
        while ($monday->lessThanOrEqualTo($to) && $guard++ < 60) {
            $weeks[] = $monday;
            $monday = $monday->addWeek();
        }

        return $weeks;
    }
}
