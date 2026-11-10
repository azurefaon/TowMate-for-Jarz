<?php

use App\Models\Finance\CommissionLine;
use App\Models\Finance\CommissionPolicy;
use App\Models\Finance\CommissionStatement;
use App\Models\Finance\Expense;
use App\Models\Finance\FundEntry;
use App\Models\Finance\JobPersonnel;
use App\Models\Finance\Payout;
use App\Models\Finance\RevenueAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

function finFundRow(array $overrides = []): FundEntry
{
    $owner = $overrides['_owner'] ?? finOwner();
    unset($overrides['_owner']);

    return FundEntry::create(array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'entry_type' => 'issuance',
        'issue_kind' => 'additional',
        'unit_id' => finUnit()->id,
        'amount' => '500.00',
        'entry_date' => now()->toDateString(),
        'recorded_by' => $owner->id,
    ], $overrides));
}

function finExpenseRow(array $overrides = []): Expense
{
    return Expense::create(array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'unit_id' => finUnit()->id,
        'category' => 'fuel_diesel',
        'amount' => '250.00',
        'expense_date' => now()->toDateString(),
        'expense_scope' => 'unit',
        'coverage_month' => now()->startOfMonth()->toDateString(),
        'description' => 'Diesel',
        'funding_source' => 'unit_fund',
        'submitted_at' => now(),
    ], $overrides));
}

/** Adds the funding split and a receipt row so the database will accept approval. */
function finMakeApprovable(Expense $expense): Expense
{
    \App\Models\Finance\ExpenseFunding::create(['expense_id' => $expense->id, 'source' => 'unit_fund', 'amount' => $expense->fresh()->amount]);
    \App\Models\Finance\ExpenseEvidence::create([
        'expense_id' => $expense->id, 'disk' => 'local', 'path' => 'finance/expenses/' . $expense->id . '/' . Str::uuid() . '.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'sha256' => hash('sha256', (string) Str::uuid()),
    ]);

    return $expense;
}

it('creates every finance table and seeds the initial rates as an unconfirmed draft', function () {
    foreach (['finance_fund_entries', 'finance_expenses', 'finance_expense_evidence', 'finance_job_personnel',
        'finance_revenue_allocations', 'finance_commission_policies', 'finance_commission_statements',
        'finance_commission_lines', 'finance_payouts'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing {$table}");
    }

    $policy = CommissionPolicy::sole();
    expect($policy->version)->toBe(1)
        ->and($policy->status)->toBe('draft')
        ->and($policy->interpretation_status)->toBe('pending_client_confirmation')
        ->and($policy->rates())->toBe(['company' => '60.00', 'driver' => '12.00', 'team_leader' => '10.00', 'rider' => '10.00', 'crew_mate' => '8.00']);
});

it('does not duplicate customer payment records', function () {
    // Collections stay in bookings / invoices / payment_transactions; finance only references them.
    expect(Schema::hasColumns('finance_revenue_allocations', ['booking_id', 'invoice_id', 'payment_transaction_id']))->toBeTrue()
        ->and(Schema::hasTable('finance_collections'))->toBeFalse()
        ->and(Schema::hasTable('finance_payments'))->toBeFalse();
});

it('stores and sums money exactly in NUMERIC columns', function () {
    $unit = finUnit();
    $owner = finOwner();
    foreach (['0.10', '0.20', '1000000.05'] as $amount) {
        finFundRow(['unit_id' => $unit->id, 'amount' => $amount, '_owner' => $owner]);
    }

    expect((string) FundEntry::where('unit_id', $unit->id)->sum('amount'))->toBe('1000000.35')
        ->and(DB::selectOne("SELECT data_type, numeric_scale FROM information_schema.columns WHERE table_name = 'finance_fund_entries' AND column_name = 'amount'"))
        ->data_type->toBe('numeric')
        ->numeric_scale->toBe(2);
});

it('rejects zero or negative amounts at the database level', function (string $amount) {
    finExpectDbRejects(fn () => finFundRow(['amount' => $amount]), 'ffe_amount');
    finExpectDbRejects(fn () => finExpenseRow(['amount' => $amount]), 'fex_amount');
})->with(['0.00', '-1.00']);

it('rejects commission rates that do not total 100', function () {
    finExpectDbRejects(fn () => CommissionPolicy::create([
        'version' => 2, 'name' => 'Bad', 'company_rate' => '60', 'driver_rate' => '12', 'team_leader_rate' => '10',
        'rider_rate' => '10', 'crew_mate_rate' => '9',
    ]), 'fcp_rates_total');
});

it('only accepts Monday to Saturday statement periods', function () {
    $policy = CommissionPolicy::sole();
    $monday = finMonday();
    $make = fn (string $start, string $end) => fn () => CommissionStatement::create(['period_start' => $start, 'period_end' => $end, 'policy_id' => $policy->id]);

    finExpectDbRejects($make(\Carbon\Carbon::parse($monday)->addDay()->toDateString(), \Carbon\Carbon::parse($monday)->addDays(6)->toDateString()), 'fcs_week');
    finExpectDbRejects($make($monday, \Carbon\Carbon::parse($monday)->addDays(6)->toDateString()), 'fcs_week'); // ends Sunday

    $ok = $make($monday, \Carbon\Carbon::parse($monday)->addDays(5)->toDateString())();
    expect($ok->period_end->isSaturday())->toBeTrue();
});

it('never allows finance rows to be deleted', function () {
    $entry = finFundRow();
    $expense = finExpenseRow();

    finExpectDbRejects(fn () => $entry->delete(), 'cannot be deleted');
    finExpectDbRejects(fn () => $expense->delete(), 'cannot be deleted');
    finExpectDbRejects(fn () => DB::table('finance_fund_entries')->where('id', $entry->id)->delete(), 'cannot be deleted');

    expect(FundEntry::whereKey($entry->id)->exists())->toBeTrue();
});

it('locks recorded fund entries: amount and unit cannot change, and voiding is one-way', function () {
    $entry = finFundRow();

    finExpectDbRejects(fn () => DB::table('finance_fund_entries')->where('id', $entry->id)->update(['amount' => '999.00']), 'amount');
    finExpectDbRejects(fn () => DB::table('finance_fund_entries')->where('id', $entry->id)->update(['unit_id' => finUnit()->id]), 'unit_id');

    DB::table('finance_fund_entries')->where('id', $entry->id)->update(['status' => 'voided', 'voided_at' => now(), 'void_reason' => 'Typo']);
    finExpectDbRejects(fn () => DB::table('finance_fund_entries')->where('id', $entry->id)->update(['status' => 'recorded', 'voided_at' => null, 'void_reason' => null]), 'not allowed');
});

it('allows editing a submitted expense but freezes it once approved', function () {
    $expense = finExpenseRow();
    DB::table('finance_expenses')->where('id', $expense->id)->update(['amount' => '275.00']);
    expect($expense->fresh()->amount)->toBe('275.00');

    finMakeApprovable($expense);
    DB::table('finance_expenses')->where('id', $expense->id)->update(['status' => 'approved', 'approved_at' => now()]);
    finExpectDbRejects(fn () => DB::table('finance_expenses')->where('id', $expense->id)->update(['amount' => '1.00']), 'amount');
    finExpectDbRejects(fn () => DB::table('finance_expenses')->where('id', $expense->id)->update(['status' => 'submitted']), 'not allowed');
});

it('does not let an approver be swapped for another user', function () {
    $expense = finMakeApprovable(finExpenseRow());
    $owner = finOwner();
    DB::table('finance_expenses')->where('id', $expense->id)->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $owner->id]);

    finExpectDbRejects(fn () => DB::table('finance_expenses')->where('id', $expense->id)->update(['approved_by' => finOwner()->id]), 'cannot be reassigned');
});

it('rejects a duplicate idempotency key', function () {
    $key = (string) Str::uuid();
    finFundRow(['idempotency_key' => $key]);

    finExpectDbRejects(fn () => finFundRow(['idempotency_key' => $key]), 'idempotency_key');
});

it('keeps one current personnel row per job slot and preserves superseded history', function () {
    $unit = finUnit();
    $booking = finBooking($unit);
    $first = finJobPersonnel($booking, $unit, 'driver_1', finPersonnel('driver'));

    finExpectDbRejects(fn () => finJobPersonnel($booking, $unit, 'driver_1', finPersonnel('driver')), 'fjp_one_current_per_slot');
    finExpectDbRejects(fn () => DB::table('finance_job_personnel')->where('id', $first->id)->update(['name_snapshot' => 'Someone else']), 'name_snapshot');

    // Correction = supersede, then a new current row.
    finExpectDbRejects(fn () => DB::table('finance_job_personnel')->where('id', $first->id)->update(['superseded_at' => now()]), 'fjp_supersede_reason');
    $replacement = DB::transaction(function () use ($first, $booking, $unit) {
        DB::table('finance_job_personnel')->where('id', $first->id)->update(['superseded_at' => now(), 'supersede_reason' => 'Wrong driver recorded']);
        $new = finJobPersonnel($booking, $unit, 'driver_1', finPersonnel('driver'));
        DB::table('finance_job_personnel')->where('id', $first->id)->update(['superseded_by_id' => $new->id]);

        return $new;
    });

    expect(JobPersonnel::where('booking_id', $booking->id)->count())->toBe(2)
        ->and(JobPersonnel::current()->where('booking_id', $booking->id)->sole()->id)->toBe($replacement->id);

    // Once superseded, the history row cannot be revived or re-pointed.
    finExpectDbRejects(fn () => DB::table('finance_job_personnel')->where('id', $first->id)->update(['superseded_at' => null]), 'superseded_at');
    finExpectDbRejects(fn () => DB::table('finance_job_personnel')->where('id', $first->id)->update(['superseded_by_id' => $first->id]), 'cannot be reassigned');
});

it('requires net revenue to equal gross minus VAT and one current allocation per booking and unit', function () {
    $unit = finUnit();
    $booking = finBooking($unit);

    finExpectDbRejects(fn () => RevenueAllocation::create([
        'booking_id' => $booking->id, 'unit_id' => $unit->id, 'gross_amount' => '1120.00', 'vat_amount' => '120.00',
        'net_amount' => '1000.01', 'allocation_method' => 'single_booking', 'collected_at' => now(),
    ]), 'fra_amounts');

    finAllocation($booking, $unit);
    finExpectDbRejects(fn () => finAllocation($booking, $unit), 'fra_one_current_per_booking_unit');
});

it('freezes approved statements and their lines, and settles each booking × unit only once', function () {
    $owner = finOwner();
    ['statement' => $statement, 'booking' => $booking, 'unit' => $unit] = finReviewStatement($owner);

    app(\App\Services\Finance\CommissionStatementService::class)->approve($owner, $statement);

    finExpectDbRejects(fn () => DB::table('finance_commission_statements')->where('id', $statement->id)->update(['total_base' => '1.00']), 'total_base');
    finExpectDbRejects(fn () => DB::table('finance_commission_statements')->where('id', $statement->id)->update(['approval_snapshot' => '{}']), 'approval_snapshot');
    finExpectDbRejects(fn () => DB::table('finance_commission_statements')->where('id', $statement->id)->update(['status' => 'draft']), 'not allowed');
    finExpectDbRejects(fn () => DB::table('finance_commission_lines')->where('statement_id', $statement->id)->update(['amount' => '0.00']), 'locked');
    finExpectDbRejects(fn () => DB::table('finance_commission_lines')->where('statement_id', $statement->id)->delete(), 'locked');
    finExpectDbRejects(fn () => $statement->fresh()->delete(), 'Only draft');

    // The settlement is permanent and unique while active.
    finExpectDbRejects(fn () => DB::table('finance_commission_settlements')->where('statement_id', $statement->id)->delete(), 'permanent');
    finExpectDbRejects(fn () => DB::table('finance_commission_settlements')->insert([
        'statement_id' => $statement->id, 'booking_id' => $booking->id, 'unit_id' => $unit->id, 'job_key' => 'b:' . $booking->id,
        'settled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]), 'fcst_one_active_per_booking_unit');
    finExpectDbRejects(fn () => DB::table('finance_commission_settlements')->where('statement_id', $statement->id)
        ->update(['released_at' => now(), 'release_reason' => 'sneaky']), 'released only when its statement is voided');

    // Only one open (draft or For Review) statement per week and unit.
    $policy = CommissionPolicy::where('status', 'active')->first();
    CommissionStatement::create(['period_start' => $statement->period_start->toDateString(), 'period_end' => $statement->period_end->toDateString(),
        'unit_id' => $unit->id, 'policy_id' => $policy->id, 'revision' => 2]);
    finExpectDbRejects(fn () => CommissionStatement::create(['period_start' => $statement->period_start->toDateString(), 'period_end' => $statement->period_end->toDateString(),
        'unit_id' => $unit->id, 'policy_id' => $policy->id, 'revision' => 3]), 'fcs_one_open_per_week_unit');
});

it('lets draft lines be replaced while the statement is a draft', function () {
    $policy = CommissionPolicy::sole();
    ['statement' => $statement] = finDraftStatement($policy);

    CommissionLine::where('statement_id', $statement->id)->delete();
    expect(CommissionLine::where('statement_id', $statement->id)->count())->toBe(0);
});

it('refuses payouts against a statement that is not approved', function () {
    $policy = CommissionPolicy::sole();
    ['statement' => $statement, 'driver' => $driver] = finDraftStatement($policy);

    finExpectDbRejects(fn () => Payout::create([
        'idempotency_key' => (string) Str::uuid(), 'statement_id' => $statement->id, 'recipient_key' => "personnel:{$driver->id}",
        'recipient_name' => 'Driver', 'amount' => '120.00', 'payout_method' => 'cash', 'paid_at' => now(),
    ]), 'approved commission statement');
});
