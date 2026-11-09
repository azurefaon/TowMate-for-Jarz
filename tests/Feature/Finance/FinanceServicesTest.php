<?php

use App\Models\AuditLog;
use App\Models\Finance\CommissionPolicy;
use App\Models\Finance\ExpenseEvidence;
use App\Models\Finance\FundEntry;
use App\Models\Finance\Payout;
use App\Services\Finance\CommissionPolicyService;
use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceException;
use App\Services\Finance\PayoutService;
use App\Services\Finance\UnitFundService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

function finExpenseData(int $unitId, array $overrides = []): array
{
    return array_merge([
        'unit_id' => $unitId,
        'amount' => '250.05',
        'expense_date' => now()->toDateString(),
        'category' => 'fuel_diesel',
        'funding_source' => 'unit_fund',
    ], $overrides);
}

// ---------- Unit funds ----------

it('records an issuance once per idempotency key and audits it', function () {
    $owner = finOwner();
    $unit = finUnit();
    $key = (string) Str::uuid();
    $data = ['unit_id' => $unit->id, 'issue_kind' => 'initial', 'amount' => '1000.10', 'entry_date' => now()->toDateString()];

    $first = app(UnitFundService::class)->issue($owner, $data, $key);
    $replay = app(UnitFundService::class)->issue($owner, $data, $key);

    expect($replay->id)->toBe($first->id)
        ->and(FundEntry::count())->toBe(1)
        ->and($first->amount)->toBe('1000.10')
        ->and($first->reference_code)->toStartWith('FUND-')
        ->and(AuditLog::where('action', 'finance_fund_issued')->count())->toBe(1)
        ->and(AuditLog::where('action', 'finance_fund_issued')->first()->category)->toBe('finance');

    expect(fn () => app(UnitFundService::class)->issue($owner, ['amount' => '999.00'] + $data, $key))
        ->toThrow(FinanceException::class, 'different details');
});

it('keeps issued funds, expenses and returns separate and computes the balance exactly', function () {
    $owner = finOwner();
    $unit = finUnit();
    $funds = app(UnitFundService::class);

    $funds->issue($owner, ['unit_id' => $unit->id, 'amount' => '1000.10', 'issue_kind' => 'initial', 'entry_date' => now()->toDateString()], (string) Str::uuid());

    $expenses = app(ExpenseService::class);
    $expense = $expenses->submit($owner, finExpenseData($unit->id), (string) Str::uuid());
    $expenses->approve($owner, $expense); // the Owner's own entry can be approved directly

    $funds->recordReturn($owner, ['unit_id' => $unit->id, 'amount' => '100.00', 'entry_date' => now()->toDateString()], (string) Str::uuid());

    expect($funds->balance($unit->id)->toString())->toBe('650.05');

    expect(fn () => $funds->recordReturn($owner, ['unit_id' => $unit->id, 'amount' => '650.06', 'entry_date' => now()->toDateString()], (string) Str::uuid()))
        ->toThrow(FinanceException::class, 'larger than the unit');
});

it('voids a fund entry with a reason instead of deleting it', function () {
    $owner = finOwner();
    $unit = finUnit();
    $entry = app(UnitFundService::class)->issue($owner, ['unit_id' => $unit->id, 'amount' => '300', 'issue_kind' => 'initial', 'entry_date' => now()->toDateString()], (string) Str::uuid());

    expect(fn () => app(UnitFundService::class)->void($owner, $entry, '  '))->toThrow(FinanceException::class);

    $voided = app(UnitFundService::class)->void($owner, $entry, 'Entered twice');
    expect($voided->status)->toBe('voided')
        ->and(FundEntry::count())->toBe(1)
        ->and(app(UnitFundService::class)->balance($unit->id)->toString())->toBe('0.00')
        ->and(fn () => app(UnitFundService::class)->void($owner, $voided, 'Again'))->toThrow(FinanceException::class);
});

// ---------- Expenses: Team Leader -> Dispatcher -> Owner ----------

it('follows the Team Leader to Dispatcher to Owner expense flow', function () {
    $owner = finOwner();
    $dispatcher = finUser(2);
    $teamLeader = finUser(3);
    $unit = finUnit($teamLeader);
    $service = app(ExpenseService::class);

    $expense = $service->submit($teamLeader, finExpenseData($unit->id), (string) Str::uuid());
    expect($expense->status)->toBe('submitted');

    expect(fn () => $service->approve($owner, $expense))->toThrow(FinanceException::class, 'Dispatcher must verify');

    $verified = $service->verify($dispatcher, $expense, 'Receipt matches');
    expect($verified->status)->toBe('verified')
        ->and(fn () => $service->verify($dispatcher, $verified))->toThrow(FinanceException::class);

    $approved = $service->approve($owner, $verified);
    expect($approved->status)->toBe('approved')
        ->and((int) $approved->approved_by)->toBe($owner->id)
        ->and(AuditLog::whereIn('action', ['finance_expense_submitted', 'finance_expense_verified', 'finance_expense_approved'])->count())->toBe(3);

    expect(fn () => $service->void($owner, $approved, ''))->toThrow(FinanceException::class);
    expect($service->void($owner, $approved, 'Duplicate receipt')->status)->toBe('voided');
});

it('stops a Team Leader from submitting for another unit and a Dispatcher from approving', function () {
    $teamLeader = finUser(3);
    $otherUnit = finUnit(finUser(3));
    $service = app(ExpenseService::class);

    expect(fn () => $service->submit($teamLeader, finExpenseData($otherUnit->id), (string) Str::uuid()))
        ->toThrow(FinanceException::class, 'their own unit');

    $ownUnit = finUnit($teamLeader);
    $expense = $service->submit($teamLeader, finExpenseData($ownUnit->id), (string) Str::uuid());
    $dispatcher = finUser(2);
    $service->verify($dispatcher, $expense);

    expect(fn () => $service->approve($dispatcher, $expense->fresh()))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('validates category and funding source against the configured lists', function () {
    $owner = finOwner();
    $unit = finUnit();

    expect(fn () => app(ExpenseService::class)->submit($owner, finExpenseData($unit->id, ['category' => 'bribe']), (string) Str::uuid()))
        ->toThrow(FinanceException::class, 'category')
        ->and(fn () => app(ExpenseService::class)->submit($owner, finExpenseData($unit->id, ['funding_source' => 'customer_cash']), (string) Str::uuid()))
        ->toThrow(FinanceException::class, 'funding source')
        ->and(fn () => app(ExpenseService::class)->submit($owner, finExpenseData($unit->id, ['amount' => 12.5]), (string) Str::uuid()))
        ->toThrow(InvalidArgumentException::class);
});

it('stores evidence on the private disk, rejects duplicates and locks evidence after approval', function () {
    Storage::fake('local');
    $owner = finOwner();
    $unit = finUnit();
    $service = app(ExpenseService::class);
    $expense = $service->submit($owner, finExpenseData($unit->id), (string) Str::uuid());

    $file = UploadedFile::fake()->image('receipt.jpg');
    $evidence = $service->attachEvidence($owner, $expense, $file);

    Storage::disk('local')->assertExists($evidence->path);
    expect($evidence->path)->toStartWith('finance/expenses/' . $expense->id . '/')
        ->and($evidence->toArray())->not->toHaveKey('path')
        ->and(fn () => $service->attachEvidence($owner, $expense, $file))->toThrow(FinanceException::class, 'already attached');

    $service->approve($owner, $expense);
    expect(fn () => $service->attachEvidence($owner, $expense->fresh(), UploadedFile::fake()->image('late.jpg', 20, 20)))
        ->toThrow(FinanceException::class, 'finalised');
    expect(ExpenseEvidence::count())->toBe(1);

    // The database refuses it too, even if the service check were bypassed.
    finExpectDbRejects(fn () => ExpenseEvidence::create([
        'expense_id' => $expense->id, 'disk' => 'local', 'path' => 'finance/expenses/x.jpg', 'mime_type' => 'image/jpeg',
        'size_bytes' => 10, 'sha256' => str_repeat('b', 64),
    ]), 'finalised expense');
});

// ---------- Commission policies ----------

it('cannot activate the seeded rates until the client confirms the interpretation', function () {
    $owner = finOwner();
    $service = app(CommissionPolicyService::class);
    $policy = CommissionPolicy::sole();

    expect(fn () => $service->activate($owner, $policy, now()->toDateString()))
        ->toThrow(FinanceException::class, 'confirmed by the client');

    $service->confirmInterpretation($owner, $policy, 'confirmed_base', 'confirmed_rule');
    $active = $service->activate($owner, $policy->fresh(), now()->toDateString());
    expect($active->status)->toBe('active');

    $v2 = $service->createDraft($owner, 'Revised', ['company' => '55', 'driver' => '15', 'team_leader' => '10', 'rider' => '10', 'crew_mate' => '10']);
    $service->confirmInterpretation($owner, $v2, 'confirmed_base', 'confirmed_rule');
    $service->activate($owner, $v2->fresh(), now()->toDateString());

    expect($active->fresh()->status)->toBe('retired')
        ->and($active->fresh()->rates()['company'])->toBe('60.00')
        ->and(CommissionPolicy::where('status', 'active')->sole()->version)->toBe(2);

    expect(fn () => $service->createDraft($owner, 'Bad', ['company' => '60', 'driver' => '12', 'team_leader' => '10', 'rider' => '10', 'crew_mate' => '8.01']))
        ->toThrow(FinanceException::class, 'exactly 100');
});

// ---------- Commission statements ----------

it('prepares only Monday-starting weeks ending on Saturday', function () {
    $owner = finOwner();
    $service = app(CommissionStatementService::class);

    expect(fn () => $service->prepareDraft($owner, \Carbon\Carbon::parse(finMonday())->addDay()->toDateString(), CommissionPolicy::sole()))
        ->toThrow(FinanceException::class, 'Monday');

    $statement = $service->prepareDraft($owner, finMonday(), CommissionPolicy::sole());
    expect($statement->period_start->isMonday())->toBeTrue()
        ->and($statement->period_end->isSaturday())->toBeTrue()
        ->and($statement->status)->toBe('draft');
});

it('refuses to approve a statement while the commission interpretation is pending', function () {
    $owner = finOwner();
    ['statement' => $statement] = finDraftStatement(CommissionPolicy::sole());

    expect(fn () => app(CommissionStatementService::class)->approve($owner, $statement))
        ->toThrow(FinanceException::class, 'client-confirmed');
});

it('approves a balanced statement once and freezes a verifiable approval hash', function () {
    $owner = finOwner();
    $service = app(CommissionStatementService::class);
    ['statement' => $statement] = finDraftStatement(finActivePolicy($owner));

    $approved = $service->approve($owner, $statement);

    expect($approved->status)->toBe('approved')
        ->and($approved->approval_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($approved->approved_by_name)->not->toBeEmpty()
        ->and($approved->approval_snapshot['lines'])->toHaveCount(5)
        ->and($service->verifyIntegrity($approved->fresh()))->toBeTrue()
        ->and(fn () => $service->approve($owner, $approved))->toThrow(FinanceException::class);
});

it('refuses to approve a statement whose totals are off by one centavo', function () {
    $owner = finOwner();
    ['statement' => $statement] = finDraftStatement(finActivePolicy($owner));
    DB::table('finance_commission_lines')->where('statement_id', $statement->id)->where('commission_role', 'crew_mate')->update(['amount' => '80.01']);

    expect(fn () => app(CommissionStatementService::class)->approve($owner, $statement))
        ->toThrow(FinanceException::class, 'add up exactly');
});

// ---------- Payouts ----------

it('records a payout equal to exact approved earnings, once per recipient, with an audit reference', function () {
    $owner = finOwner();
    ['statement' => $statement, 'driver' => $driver, 'teamLeader' => $teamLeader] = finDraftStatement(finActivePolicy($owner));
    app(CommissionStatementService::class)->approve($owner, $statement);
    $payouts = app(PayoutService::class);
    $key = (string) Str::uuid();

    $payout = $payouts->record($owner, $statement, "personnel:{$driver->id}", 'gcash', now()->toDateTimeString(), $key, 'GC-123');
    $replay = $payouts->record($owner, $statement, "personnel:{$driver->id}", 'gcash', now()->toDateTimeString(), $key, 'GC-123');

    expect($payout->amount)->toBe('120.00')
        ->and($replay->id)->toBe($payout->id)
        ->and($payout->fresh()->audit_log_id)->not->toBeNull()
        ->and(AuditLog::find($payout->fresh()->audit_log_id)->action)->toBe('finance_payout_recorded');

    expect(fn () => $payouts->record($owner, $statement, "personnel:{$driver->id}", 'cash', now()->toDateTimeString(), (string) Str::uuid()))
        ->toThrow(FinanceException::class, 'already recorded')
        ->and(fn () => $payouts->record($owner, $statement, 'company', 'cash', now()->toDateTimeString(), (string) Str::uuid()))
        ->toThrow(FinanceException::class, 'no approved earnings');

    expect($payouts->record($owner, $statement, "user:{$teamLeader->id}", 'cash', now()->toDateTimeString(), (string) Str::uuid())->amount)->toBe('100.00');

    // Earned stays separate from paid: commission lines are untouched by payouts.
    expect(\App\Models\Finance\CommissionLine::where('statement_id', $statement->id)->sum('amount'))->toEqual('1000.00')
        ->and(Payout::where('status', 'recorded')->sum('amount'))->toEqual('220.00');
});

it('cannot void a statement that has recorded payouts, but can after the payout is voided', function () {
    $owner = finOwner();
    ['statement' => $statement, 'driver' => $driver] = finDraftStatement(finActivePolicy($owner));
    $statements = app(CommissionStatementService::class);
    $statements->approve($owner, $statement);

    $payout = app(PayoutService::class)->record($owner, $statement, "personnel:{$driver->id}", 'cash', now()->toDateTimeString(), (string) Str::uuid());

    expect(fn () => $statements->void($owner, $statement, 'Wrong week'))->toThrow(FinanceException::class, 'Void the recorded payouts');

    app(PayoutService::class)->void($owner, $payout, 'Paid to wrong person');
    expect($statements->void($owner, $statement, 'Wrong week')->status)->toBe('voided')
        ->and(Payout::count())->toBe(1);
});

// ---------- Existing functionality guard ----------

it('blocks permanent deletion of a unit that has finance history', function () {
    $owner = finOwner();
    $unit = finUnit();
    app(UnitFundService::class)->issue($owner, ['unit_id' => $unit->id, 'amount' => '100', 'issue_kind' => 'initial', 'entry_date' => now()->toDateString()], (string) Str::uuid());
    $unit->forceFill(['archived_at' => now()])->save();

    $this->actingAs($owner)
        ->delete(route('superadmin.units.force-delete', $unit->id))
        ->assertSessionHas('error');

    expect(\App\Models\Unit::whereKey($unit->id)->exists())->toBeTrue();
});
