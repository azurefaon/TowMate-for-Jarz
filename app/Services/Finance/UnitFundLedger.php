<?php

namespace App\Services\Finance;

use App\Models\Finance\Expense;
use App\Models\Finance\FundEntry;
use App\Models\Unit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only unit fund balances, computed on the backend from recorded history:
 *
 *   Opening balance + Funds issued − Advance-funded spending − Funds returned = Remaining balance
 *
 * - Opening balance = the same formula applied to everything dated BEFORE the period, so outstanding
 *   funds carry into the next period automatically and nothing has to be "closed".
 * - Advance-funded spending = APPROVED expenses whose funding source is the unit fund (Phase 3 writes them).
 * - Pending review = unit-fund expenses still submitted or verified. They are shown separately and are
 *   NOT deducted until the Owner approves them.
 * - Customer collections and commission payouts are not part of this ledger at all.
 *
 * Every sum is a PostgreSQL NUMERIC aggregate, combined with Money (bcmath). No floats.
 */
class UnitFundLedger
{
    /**
     * @return Collection<int, array{unit_id:int, unit_name:string, plate_number:?string, archived:bool, opening:Money, issued:Money, spent:Money, returned:Money, remaining:Money, pending_amount:Money, pending_count:int}>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, ?int $unitId = null): Collection
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $funds = DB::table('finance_fund_entries')
            ->where('status', FundEntry::STATUS_RECORDED)
            ->where('entry_date', '<=', $toDate)
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->groupBy('unit_id')
            ->selectRaw("unit_id,
                COALESCE(SUM(amount) FILTER (WHERE entry_type = 'issuance' AND entry_date < ?), 0) AS issued_before,
                COALESCE(SUM(amount) FILTER (WHERE entry_type = 'return' AND entry_date < ?), 0) AS returned_before,
                COALESCE(SUM(amount) FILTER (WHERE entry_type = 'issuance' AND entry_date >= ?), 0) AS issued_in,
                COALESCE(SUM(amount) FILTER (WHERE entry_type = 'return' AND entry_date >= ?), 0) AS returned_in",
                [$fromDate, $fromDate, $fromDate, $fromDate])
            ->get()->keyBy('unit_id');

        // Only the unit_fund split of each expense counts. One split row per (expense, source) means each
        // approved expense is deducted exactly once, and company or personal-advance splits never touch the fund.
        $spending = DB::table('finance_expense_fundings as f')
            ->join('finance_expenses as e', 'e.id', '=', 'f.expense_id')
            ->where('f.source', 'unit_fund')
            ->where('e.expense_date', '<=', $toDate)
            ->when($unitId, fn ($q) => $q->where('e.unit_id', $unitId))
            ->groupBy('e.unit_id')
            ->selectRaw("e.unit_id AS unit_id,
                COALESCE(SUM(f.amount) FILTER (WHERE e.status = 'approved' AND e.expense_date < ?), 0) AS spent_before,
                COALESCE(SUM(f.amount) FILTER (WHERE e.status = 'approved' AND e.expense_date >= ?), 0) AS spent_in,
                COALESCE(SUM(f.amount) FILTER (WHERE e.status IN ('submitted', 'verified')), 0) AS pending_amount,
                COUNT(*) FILTER (WHERE e.status IN ('submitted', 'verified')) AS pending_count",
                [$fromDate, $fromDate])
            ->get()->keyBy('unit_id');

        $unitIds = $funds->keys()->merge($spending->keys())->unique();

        // Every active unit is listed (even with no activity); archived units only when they still have history.
        $units = Unit::query()
            ->when($unitId, fn ($q) => $q->whereKey($unitId), fn ($q) => $q->where(fn ($w) => $w->whereNull('archived_at')->orWhereIn('id', $unitIds)))
            ->orderBy('name')
            ->get(['id', 'name', 'plate_number', 'archived_at']);

        return $units->map(function (Unit $unit) use ($funds, $spending) {
            $f = $funds->get($unit->id);
            $s = $spending->get($unit->id);

            $opening = Money::of((string) ($f->issued_before ?? '0'))
                ->minus((string) ($s->spent_before ?? '0'))
                ->minus((string) ($f->returned_before ?? '0'));
            $issued = Money::of((string) ($f->issued_in ?? '0'));
            $spent = Money::of((string) ($s->spent_in ?? '0'));
            $returned = Money::of((string) ($f->returned_in ?? '0'));

            return [
                'unit_id' => $unit->id,
                'unit_name' => $unit->name,
                'plate_number' => $unit->plate_number,
                'archived' => $unit->archived_at !== null,
                'opening' => $opening,
                'issued' => $issued,
                'spent' => $spent,
                'returned' => $returned,
                'remaining' => $opening->plus($issued)->minus($spent)->minus($returned),
                'pending_amount' => Money::of((string) ($s->pending_amount ?? '0')),
                'pending_count' => (int) ($s->pending_count ?? 0),
            ];
        })->values();
    }

    /** @param Collection<int, array> $rows */
    public function totals(Collection $rows): array
    {
        $totals = [];
        foreach (['opening', 'issued', 'spent', 'returned', 'remaining', 'pending_amount'] as $key) {
            $totals[$key] = Money::sum($rows->pluck($key));
        }
        $totals['pending_count'] = (int) $rows->sum('pending_count');

        return $totals;
    }

    /** Remaining balance of one unit as of a date (inclusive), or as of today when no date is given. */
    public function balance(int $unitId, ?string $asOf = null): Money
    {
        $date = CarbonImmutable::parse($asOf ?? now(config('finance.timezone'))->toDateString());
        $row = $this->summary($date, $date, $unitId)->first();

        return $row ? $row['remaining'] : Money::zero();
    }

    /** Unit-fund expenses awaiting Dispatcher verification or Owner approval (not yet deducted). */
    public function pendingAmount(int $unitId): Money
    {
        return Money::of((string) DB::table('finance_expense_fundings as f')
            ->join('finance_expenses as e', 'e.id', '=', 'f.expense_id')
            ->where('f.source', 'unit_fund')
            ->where('e.unit_id', $unitId)
            ->whereIn('e.status', Expense::PENDING_STATUSES)
            ->sum('f.amount'));
    }

    /** Cash the unit still holds after setting aside unit-fund expenses awaiting review (may be negative). */
    public function available(int $unitId): Money
    {
        return $this->balance($unitId)->minus($this->pendingAmount($unitId));
    }
}
