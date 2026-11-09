<?php

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseEvidence;
use App\Models\Finance\ExpenseFunding;
use App\Models\Finance\FundEntry;
use App\Models\Unit;
use App\Models\User;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceException;
use App\Services\Finance\UnitFundLedger;
use App\Services\Finance\UnitFundService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/finance_helpers.php';

beforeEach(function () {
    Storage::fake('local');
});

function fxToday(): string
{
    return now(config('finance.timezone'))->toDateString();
}

function fxReceipt(string $name = 'receipt.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, random_int(10, 60), random_int(10, 60));
}

function fxFund(Unit $unit, string $amount, ?string $date = null, string $kind = 'initial'): FundEntry
{
    return app(UnitFundService::class)->issue(User::where('role_id', 1)->first() ?? finOwner(), [
        'unit_id' => $unit->id, 'issue_kind' => $kind, 'amount' => $amount, 'entry_date' => $date ?? fxToday(), 'counterparty_name' => 'Team Leader',
    ], (string) Str::uuid());
}

function fxBalance(Unit $unit): string
{
    return app(UnitFundLedger::class)->balance($unit->id)->toString();
}

/** Owner web-form payload. */
function fxPayload(Unit $unit, array $overrides = []): array
{
    return array_merge([
        'form' => 'create',
        'idempotency_key' => (string) Str::uuid(),
        'unit_id' => $unit->id,
        'expense_scope' => 'unit',
        'coverage_month' => now(config('finance.timezone'))->format('Y-m'),
        'expense_date' => fxToday(),
        'category' => 'fuel_diesel',
        'description' => 'Diesel refill',
        'amount' => '2950',
        'fundings' => ['unit_fund' => '2950'],
    ], $overrides);
}

/** Service-level data. */
function fxData(Unit $unit, array $overrides = []): array
{
    return array_merge([
        'unit_id' => $unit->id,
        'expense_scope' => 'unit',
        'expense_date' => fxToday(),
        'category' => 'fuel_diesel',
        'description' => 'Diesel refill',
        'amount' => '1000',
        'fundings' => ['unit_fund' => '1000'],
    ], $overrides);
}

function fxSubmit(User $actor, Unit $unit, array $overrides = [], bool $withReceipt = true): Expense
{
    return app(ExpenseService::class)->submit($actor, fxData($unit, $overrides), (string) Str::uuid(), $withReceipt ? [fxReceipt()] : []);
}

// ---------- Creation and validation ----------

it('records an Owner expense with its receipt, funding split, scope and review trail', function () {
    $owner = finOwner();
    $unit = finUnit();

    $this->actingAs($owner)->post(route('superadmin.finance.expenses.store'), fxPayload($unit) + ['receipts' => [fxReceipt()]])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $expense = Expense::with(['fundings', 'evidence'])->sole();
    expect($expense->amount)->toBe('2950.00')
        ->and($expense->status)->toBe('submitted')
        ->and($expense->expense_scope)->toBe('unit')
        ->and($expense->coverage_month->day)->toBe(1)
        ->and($expense->funding_source)->toBe('unit_fund')
        ->and($expense->fundings->sole()->amount)->toBe('2950.00')
        ->and($expense->evidence)->toHaveCount(1)
        ->and((int) $expense->submitted_by)->toBe($owner->id)
        ->and($expense->submitted_at)->not->toBeNull();

    Storage::disk('local')->assertExists($expense->evidence->first()->path);
    expect($expense->evidence->first()->path)->toStartWith('finance/expenses/');
});

it('links a job expense to its booking and requires that booking', function () {
    $owner = finOwner();
    $unit = finUnit();
    $booking = finBooking($unit);

    $this->actingAs($owner)->post(route('superadmin.finance.expenses.store'), fxPayload($unit, ['expense_scope' => 'job', 'booking_ref' => $booking->booking_code, 'coverage_month' => null]))
        ->assertSessionHasNoErrors();
    expect(Expense::sole()->booking_id)->toBe($booking->id)->and(Expense::sole()->coverage_month)->toBeNull();

    $this->actingAs($owner)->post(route('superadmin.finance.expenses.store'), fxPayload($unit, ['expense_scope' => 'job', 'booking_ref' => '']))->assertSessionHasErrors('booking_ref');
    $this->actingAs($owner)->post(route('superadmin.finance.expenses.store'), fxPayload($unit, ['expense_scope' => 'job', 'booking_ref' => '9999999']))->assertSessionHasErrors('finance');
    expect(Expense::count())->toBe(1);
});

it('rejects invalid expenses without saving anything', function (array $overrides, string $errorKey) {
    $this->actingAs(finOwner())->post(route('superadmin.finance.expenses.store'), fxPayload(finUnit(), $overrides))->assertSessionHasErrors($errorKey);

    expect(Expense::count())->toBe(0)->and(ExpenseFunding::count())->toBe(0);
})->with([
    'unknown category' => [['category' => 'gambling'], 'category'],
    'no description' => [['description' => ''], 'description'],
    'zero amount' => [['amount' => '0', 'fundings' => ['unit_fund' => '0']], 'finance'],
    'three decimals' => [['amount' => '10.005'], 'amount'],
    'future date' => [['expense_date' => '2999-01-01'], 'expense_date'],
    'funding short of the amount' => [['fundings' => ['unit_fund' => '2000']], 'finance'],
    'funding over the amount' => [['fundings' => ['unit_fund' => '2950', 'company_direct' => '1']], 'finance'],
    'no funding at all' => [['fundings' => ['unit_fund' => '']], 'finance'],
    'unknown funding source' => [['fundings' => ['customer_cash' => '2950']], 'finance'],
]);

// ---------- Evidence: upload and access ----------

it('accepts only JPG, PNG, WEBP or PDF evidence within the size limit', function () {
    $owner = finOwner();
    $expense = fxSubmit($owner, finUnit(), [], false);

    $this->actingAs($owner)->post(route('superadmin.finance.expenses.upload-evidence', $expense->id), ['receipts' => [UploadedFile::fake()->create('notes.txt', 5, 'text/plain')]])->assertSessionHasErrors('receipts.0');
    $this->actingAs($owner)->post(route('superadmin.finance.expenses.upload-evidence', $expense->id), ['receipts' => [UploadedFile::fake()->create('script.php.jpg', 5, 'application/x-php')]])->assertSessionHasErrors('receipts.0');
    $this->actingAs($owner)->post(route('superadmin.finance.expenses.upload-evidence', $expense->id), ['receipts' => [UploadedFile::fake()->create('huge.pdf', 9000, 'application/pdf')]])->assertSessionHasErrors('receipts.0');
    expect(ExpenseEvidence::count())->toBe(0);

    $this->actingAs($owner)->post(route('superadmin.finance.expenses.upload-evidence', $expense->id), ['receipts' => [UploadedFile::fake()->create('official-receipt.pdf', 200, 'application/pdf')]])->assertSessionHasNoErrors();
    expect(ExpenseEvidence::sole()->mime_type)->toBe('application/pdf');
});

it('serves evidence only to the Owner, Dispatchers and the submitting Team Leader', function () {
    $owner = finOwner();
    $teamLeader = finUser(3);
    $unit = finUnit($teamLeader);
    $expense = fxSubmit($teamLeader, $unit);
    $evidence = $expense->evidence->first();

    $this->actingAs($owner)->get(route('superadmin.finance.expenses.evidence', [$expense->id, $evidence->id]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs(finUser(2))->get(route('admin.expense-review.evidence', [$expense->id, $evidence->id]))->assertOk();

    Sanctum::actingAs($teamLeader, ['*']);
    $this->getJson("/api/v1/team-leader/expenses/{$expense->id}/evidence/{$evidence->id}")->assertOk();

    Sanctum::actingAs(finUser(3), ['*']); // a different Team Leader
    $this->getJson("/api/v1/team-leader/expenses/{$expense->id}/evidence/{$evidence->id}")->assertForbidden();

    foreach ([2, 3, 4, 5, 6] as $role) { // none of these may use the Owner route
        $this->actingAs(finUser($role))->get(route('superadmin.finance.expenses.evidence', [$expense->id, $evidence->id]))->assertForbidden();
    }
    $this->actingAs(finUser(4))->get(route('admin.expense-review.evidence', [$expense->id, $evidence->id]))->assertForbidden();

    // An evidence id from another expense is not reachable through this expense.
    $other = fxSubmit($owner, finUnit());
    $this->actingAs($owner)->get(route('superadmin.finance.expenses.evidence', [$expense->id, $other->evidence->first()->id]))->assertNotFound();
});

// ---------- Evidence required ----------

it('blocks verification and approval while evidence is missing, at every layer', function () {
    $owner = finOwner();
    $teamLeader = finUser(3);
    $unit = finUnit($teamLeader);
    $service = app(ExpenseService::class);

    $ownExpense = fxSubmit($owner, $unit, [], false);
    expect(fn () => $service->approve($owner, $ownExpense))->toThrow(FinanceException::class, 'Attach the receipt');
    $this->actingAs($owner)->post(route('superadmin.finance.expenses.approve', $ownExpense->id))->assertSessionHasErrors('finance');

    $tlExpense = fxSubmit($teamLeader, $unit, [], false);
    expect(fn () => $service->verify(finUser(2), $tlExpense))->toThrow(FinanceException::class, 'Attach the receipt');

    // Even a direct database update cannot approve it.
    finExpectDbRejects(fn () => DB::table('finance_expenses')->where('id', $ownExpense->id)->update(['status' => 'approved', 'approved_at' => now()]), 'without a receipt');

    expect($ownExpense->fresh()->status)->toBe('submitted')->and($tlExpense->fresh()->status)->toBe('submitted');

    // Once a receipt is attached, approval works.
    $service->attachEvidence($owner, $ownExpense, fxReceipt());
    expect($service->approve($owner, $ownExpense)->status)->toBe('approved');
});

// ---------- The client's example through the full flow ----------

it('issues 3,000, approves 2,950 of receipts through Team Leader -> Dispatcher -> Owner, returns 50 and ends at 0', function () {
    $owner = finOwner();
    $dispatcher = finUser(2);
    $teamLeader = finUser(3);
    $unit = finUnit($teamLeader);
    fxFund($unit, '3000');

    // Team Leader submits from the app with the receipt.
    Sanctum::actingAs($teamLeader, ['*']);
    $response = $this->post('/api/v1/team-leader/expenses', [
        'idempotency_key' => (string) Str::uuid(),
        'expense_scope' => 'unit',
        'expense_date' => fxToday(),
        'category' => 'fuel_diesel',
        'description' => 'Diesel, Petron Marilao',
        'amount' => '2950.00',
        'fundings' => ['unit_fund' => '2950.00'],
        'receipts' => [fxReceipt()],
    ], ['Accept' => 'application/json']);
    $response->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('warning', null);
    $expense = Expense::sole();

    // Pending: visible, not yet deducted.
    $row = app(UnitFundLedger::class)->summary(CarbonImmutable::parse(fxToday()), CarbonImmutable::parse(fxToday()), $unit->id)->sole();
    expect($row['pending_amount']->toString())->toBe('2950.00')->and($row['spent']->toString())->toBe('0.00')->and(fxBalance($unit))->toBe('3000.00');

    $this->actingAs($dispatcher)->post(route('admin.expense-review.verify', $expense->id), ['note' => 'Receipt matches'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post(route('superadmin.finance.expenses.approve', $expense->id))->assertSessionHasNoErrors();
    expect($expense->fresh()->status)->toBe('approved')->and(fxBalance($unit))->toBe('50.00');

    // Approving again changes nothing: deducted exactly once.
    $this->actingAs($owner)->post(route('superadmin.finance.expenses.approve', $expense->id))->assertSessionHasErrors('finance');
    expect(fxBalance($unit))->toBe('50.00');

    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.return'), [
        'form' => 'return', 'idempotency_key' => (string) Str::uuid(), 'unit_id' => $unit->id, 'entry_date' => fxToday(),
        'amount' => '50', 'counterparty_name' => 'Team Leader',
    ])->assertSessionHasNoErrors();

    expect(fxBalance($unit))->toBe('0.00');
    $this->actingAs($owner)->get(route('superadmin.finance.unit-funds.index', ['unit_id' => $unit->id, 'from' => fxToday(), 'to' => fxToday()]))
        ->assertSee('data-fin-total="spent">₱2,950.00<', false)
        ->assertSee('data-fin-total="remaining">₱0.00<', false);
});

// ---------- Mixed funding ----------

it('splits one expense across sources and deducts only the unit-fund part, once', function () {
    $owner = finOwner();
    $unit = finUnit();
    fxFund($unit, '3000');

    $expense = fxSubmit($owner, $unit, ['amount' => '1000', 'fundings' => ['unit_fund' => '600', 'company_direct' => '300', 'personal_advance' => '100']]);
    app(ExpenseService::class)->approve($owner, $expense);

    expect($expense->fresh()->funding_source)->toBe('mixed')
        ->and(ExpenseFunding::where('expense_id', $expense->id)->count())->toBe(3)
        ->and(Expense::count())->toBe(1)                          // one cost, not three
        ->and(fxBalance($unit))->toBe('2400.00');                 // only the 600 from issued funds

    $page = $this->actingAs($owner)->get(route('superadmin.finance.expenses.index'));
    $page->assertSee('data-fx-total="approved">₱1,000.00<', false)
        ->assertSee('data-fx-total="unit_fund">₱600.00<', false)
        ->assertSee('data-fx-total="company">₱300.00<', false)
        ->assertSee('data-fx-total="personal">₱100.00<', false);

    // Splits are locked after approval.
    finExpectDbRejects(fn () => DB::table('finance_expense_fundings')->where('expense_id', $expense->id)->where('source', 'unit_fund')->update(['amount' => '1']), 'locked');
});

// ---------- Overspending ----------

it('records unit-fund spending above the available cash with a warning, and creates no debt', function () {
    $owner = finOwner();
    $teamLeader = finUser(3);
    $unit = finUnit($teamLeader);
    fxFund($unit, '500');

    $this->actingAs($owner)->post(route('superadmin.finance.expenses.store'), fxPayload($unit, ['amount' => '800', 'fundings' => ['unit_fund' => '800']]) + ['receipts' => [fxReceipt()]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('warning', fn ($w) => str_contains($w, '₱300.00') && str_contains($w, 'No Team Leader debt'));

    $expense = Expense::sole();
    expect($expense->unit_fund_shortfall)->toBe('300.00')
        ->and($expense->fundings->sole()->source)->toBe('unit_fund')     // recorded exactly as stated
        ->and($expense->reviewFlags())->toHaveKey('shortfall');

    app(ExpenseService::class)->approve($owner, $expense);
    expect(fxBalance($unit))->toBe('-300.00')
        ->and(FundEntry::count())->toBe(1);                               // no automatic debt or adjustment entry

    expect(fn () => app(UnitFundService::class)->recordReturn($owner, ['unit_id' => $unit->id, 'amount' => '1', 'entry_date' => fxToday()], (string) Str::uuid()))
        ->toThrow(FinanceException::class);

    // The Team Leader app gets the same warning.
    fxFund($unit, '100', null, 'additional');
    Sanctum::actingAs($teamLeader, ['*']);
    $this->postJson('/api/v1/team-leader/expenses', fxData($unit, ['idempotency_key' => (string) Str::uuid(), 'amount' => '150', 'fundings' => ['unit_fund' => '150']]))
        ->assertCreated()
        ->assertJsonPath('data.unit_fund_shortfall', '150.00');
});

it('shows the overspend hint data on the form for each unit', function () {
    $owner = finOwner();
    $unit = finUnit();
    fxFund($unit, '1234.50');

    $this->actingAs($owner)->get(route('superadmin.finance.expenses.index'))
        ->assertOk()
        ->assertSee('data-available="1234.50"', false);
});

// ---------- Personal advances ----------

it('records a personal advance without touching unit funds and flags the pending reimbursement rule', function () {
    $owner = finOwner();
    $unit = finUnit();
    fxFund($unit, '1000');

    $expense = fxSubmit($owner, $unit, ['amount' => '450', 'fundings' => ['personal_advance' => '450']]);
    app(ExpenseService::class)->approve($owner, $expense);

    expect(fxBalance($unit))->toBe('1000.00')
        ->and($expense->fresh()->funding_source)->toBe('personal_advance')
        ->and($expense->fresh()->unit_fund_shortfall)->toBe('0.00')
        ->and($expense->fresh()->reviewFlags())->toHaveKey('personal_advance');

    $this->actingAs($owner)->get(route('superadmin.finance.expenses.index'))
        ->assertSee('Personal advance: reimbursement rule pending client confirmation')
        ->assertSee('data-fx-total="personal">₱450.00<', false);

    // Company-paid costs never reduce issued funds either.
    $company = fxSubmit($owner, $unit, ['amount' => '700', 'fundings' => ['company_direct' => '700']]);
    app(ExpenseService::class)->approve($owner, $company);
    expect(fxBalance($unit))->toBe('1000.00');
});

// ---------- Monthly / unit expenses ----------

it('flags unit and monthly expenses as awaiting the client allocation rule without allocating them', function () {
    $owner = finOwner();
    $unit = finUnit();
    $expense = fxSubmit($owner, $unit, ['category' => 'maintenance', 'description' => 'Monthly preventive maintenance', 'coverage_month' => now()->subMonth()->format('Y-m'), 'fundings' => ['company_direct' => '1000']]);

    expect($expense->expense_scope)->toBe('unit')
        ->and($expense->coverage_month->format('Y-m'))->toBe(now()->subMonth()->format('Y-m'))
        ->and($expense->booking_id)->toBeNull()
        ->and($expense->reviewFlags())->toHaveKey('monthly_allocation');

    $job = fxSubmit($owner, $unit, ['expense_scope' => 'job', 'booking_id' => finBooking($unit)->booking_code]);
    expect($job->reviewFlags())->not->toHaveKey('monthly_allocation');

    $this->actingAs($owner)->get(route('superadmin.finance.expenses.index', ['scope' => 'unit']))
        ->assertSee('Monthly allocation rule pending client confirmation')
        ->assertSee($expense->reference_code)
        ->assertDontSee($job->reference_code);
});

// ---------- Duplicate submissions ----------

it('records a double-submitted expense once, with its receipt attached once', function () {
    $owner = finOwner();
    $unit = finUnit();
    $payload = fxPayload($unit);

    $this->actingAs($owner)->post(route('superadmin.finance.expenses.store'), $payload + ['receipts' => [fxReceipt()]])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post(route('superadmin.finance.expenses.store'), $payload + ['receipts' => [fxReceipt()]])->assertSessionHasNoErrors();

    expect(Expense::count())->toBe(1)
        ->and(ExpenseFunding::count())->toBe(1)
        ->and(ExpenseEvidence::count())->toBe(1)
        ->and(\App\Models\AuditLog::where('action', 'finance_expense_submitted')->count())->toBe(1);

    $this->actingAs($owner)->post(route('superadmin.finance.expenses.store'), array_merge($payload, ['amount' => '3000', 'fundings' => ['unit_fund' => '3000']]))
        ->assertSessionHasErrors('finance');
    expect(Expense::count())->toBe(1);
});

it('records a double-submitted Team Leader app expense once', function () {
    $teamLeader = finUser(3);
    $unit = finUnit($teamLeader);
    Sanctum::actingAs($teamLeader, ['*']);
    $body = fxData($unit, ['idempotency_key' => (string) Str::uuid()]);

    $this->postJson('/api/v1/team-leader/expenses', $body)->assertCreated();
    $this->postJson('/api/v1/team-leader/expenses', $body)->assertOk();

    expect(Expense::count())->toBe(1);
});

// ---------- Authorization ----------

it('refuses direct Owner expense requests from every other role', function (int $roleId) {
    $owner = finOwner();
    $user = finUser($roleId);
    $unit = finUnit();
    $expense = fxSubmit($owner, $unit);

    $this->actingAs($user)->get(route('superadmin.finance.expenses.index'))->assertForbidden();
    $this->actingAs($user)->post(route('superadmin.finance.expenses.store'), fxPayload($unit))->assertForbidden();
    $this->actingAs($user)->post(route('superadmin.finance.expenses.approve', $expense->id))->assertForbidden();
    $this->actingAs($user)->post(route('superadmin.finance.expenses.reject', $expense->id), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($user)->post(route('superadmin.finance.expenses.correct', $expense->id), fxPayload($unit, ['reason' => 'x']))->assertForbidden();
    $this->actingAs($user)->post(route('superadmin.finance.expenses.upload-evidence', $expense->id), ['receipts' => [fxReceipt()]])->assertForbidden();

    expect(Expense::count())->toBe(1)->and($expense->fresh()->status)->toBe('submitted');
})->with(['Dispatcher' => 2, 'Team Leader' => 3, 'Driver' => 4, 'Customer' => 5, 'System Admin' => 6]);

it('keeps verification for Dispatchers and submission for Team Leaders only', function () {
    $owner = finOwner();
    $teamLeader = finUser(3);
    $unit = finUnit($teamLeader);
    $expense = fxSubmit($teamLeader, $unit);

    foreach ([1, 3, 4, 5, 6] as $role) {
        $this->actingAs(finUser($role))->post(route('admin.expense-review.verify', $expense->id))->assertForbidden();
    }
    expect($expense->fresh()->status)->toBe('submitted');

    foreach ([1, 2, 4, 5, 6] as $role) {
        Sanctum::actingAs(finUser($role), ['*']);
        $this->postJson('/api/v1/team-leader/expenses', fxData($unit, ['idempotency_key' => (string) Str::uuid()]))->assertForbidden();
    }

    // A Team Leader cannot submit for another unit: the app always uses the Team Leader's own unit.
    $otherUnit = finUnit(finUser(3));
    Sanctum::actingAs($teamLeader, ['*']);
    $this->postJson('/api/v1/team-leader/expenses', fxData($otherUnit, ['idempotency_key' => (string) Str::uuid()]))->assertCreated();
    expect(Expense::latest('id')->first()->unit_id)->toBe($unit->id);

    // A Team Leader without a unit cannot submit.
    Sanctum::actingAs(finUser(3), ['*']);
    $this->postJson('/api/v1/team-leader/expenses', fxData($unit, ['idempotency_key' => (string) Str::uuid()]))->assertStatus(422);

    $this->post(route('superadmin.finance.expenses.store'), fxPayload($unit))->assertForbidden();
});

it('redirects guests and rejects unauthenticated app requests', function () {
    auth()->logout();
    $this->get(route('superadmin.finance.expenses.index'))->assertRedirect(route('login'));
    $this->get(route('admin.expense-review.index'))->assertRedirect(route('login'));
    $this->postJson('/api/v1/team-leader/expenses', [])->assertUnauthorized();
});

// ---------- Dispatcher step ----------

it('lets a Dispatcher verify or reject from the review queue, but not approve', function () {
    $owner = finOwner();
    $dispatcher = finUser(2);
    $teamLeader = finUser(3);
    $unit = finUnit($teamLeader);
    $good = fxSubmit($teamLeader, $unit);
    $bad = fxSubmit($teamLeader, $unit, ['amount' => '99', 'fundings' => ['unit_fund' => '99']]);

    $this->actingAs($dispatcher)->get(route('admin.expense-review.index'))
        ->assertOk()->assertSee($good->reference_code)->assertSee($bad->reference_code)->assertSee('Expense Review');

    $this->actingAs($dispatcher)->post(route('admin.expense-review.verify', $good->id), ['note' => 'OK'])->assertSessionHasNoErrors();
    $this->actingAs($dispatcher)->post(route('admin.expense-review.reject', $bad->id), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($dispatcher)->post(route('admin.expense-review.reject', $bad->id), ['reason' => 'Receipt unreadable'])->assertSessionHasNoErrors();

    expect($good->fresh()->status)->toBe('verified')
        ->and((int) $good->fresh()->verified_by)->toBe($dispatcher->id)
        ->and($good->fresh()->verification_note)->toBe('OK')
        ->and($bad->fresh()->status)->toBe('rejected')
        ->and($bad->fresh()->rejection_reason)->toBe('Receipt unreadable');

    // The Owner sees the verified one waiting for approval.
    $this->actingAs($owner)->get(route('superadmin.finance.expenses.index', ['status' => 'verified']))
        ->assertSee($good->reference_code)->assertSee('Awaiting Owner approval');
});

// ---------- History and corrections ----------

it('corrects an approved expense by voiding it and recording a linked replacement, deducting only the replacement', function () {
    $owner = finOwner();
    $unit = finUnit();
    fxFund($unit, '3000');
    $original = fxSubmit($owner, $unit, ['amount' => '1000', 'fundings' => ['unit_fund' => '1000']]);
    app(ExpenseService::class)->approve($owner, $original);
    expect(fxBalance($unit))->toBe('2000.00');

    $this->actingAs($owner)->post(route('superadmin.finance.expenses.correct', $original->id), [
        'form' => 'expense-' . $original->id,
        'idempotency_key' => (string) Str::uuid(),
        'expense_scope' => 'unit',
        'coverage_month' => now()->format('Y-m'),
        'expense_date' => fxToday(),
        'category' => 'fuel_diesel',
        'description' => 'Diesel refill (corrected)',
        'amount' => '900',
        'fundings' => ['unit_fund' => '600', 'company_direct' => '300'],
        'reason' => 'Part was paid by the office',
    ])->assertSessionHasNoErrors();

    $original->refresh();
    $replacement = Expense::where('corrects_expense_id', $original->id)->sole();
    expect($original->status)->toBe('voided')
        ->and($original->void_reason)->toBe('Corrected: Part was paid by the office')
        ->and($original->amount)->toBe('1000.00')                 // history is never overwritten
        ->and($replacement->status)->toBe('submitted')
        ->and($replacement->evidence)->toHaveCount(1)             // receipt copied to a new private file
        ->and($replacement->evidence->first()->path)->not->toBe($original->evidence->first()->path)
        ->and(fxBalance($unit))->toBe('3000.00');                 // voided: no longer deducted; replacement pending

    app(ExpenseService::class)->approve($owner, $replacement);
    expect(fxBalance($unit))->toBe('2400.00');

    finExpectDbRejects(fn () => DB::table('finance_expenses')->where('id', $original->id)->update(['amount' => '1.00']), 'amount');
    finExpectDbRejects(fn () => DB::table('finance_expenses')->where('id', $original->id)->delete(), 'cannot be deleted');
});

it('voids an approved expense with a reason and stops deducting it', function () {
    $owner = finOwner();
    $unit = finUnit();
    fxFund($unit, '500');
    $expense = fxSubmit($owner, $unit, ['amount' => '200', 'fundings' => ['unit_fund' => '200']]);
    app(ExpenseService::class)->approve($owner, $expense);

    $this->actingAs($owner)->post(route('superadmin.finance.expenses.void', $expense->id), ['reason' => 'Duplicate receipt'])->assertSessionHasNoErrors();

    expect($expense->fresh()->status)->toBe('voided')->and(fxBalance($unit))->toBe('500.00');
    $this->actingAs($owner)->get(route('superadmin.finance.expenses.index'))->assertSee('Duplicate receipt');
});

it('keeps unit fund returns out of commission data', function () {
    $owner = finOwner();
    $unit = finUnit();
    fxFund($unit, '1000');
    $allocations = \App\Models\Finance\RevenueAllocation::count();
    $lines = \App\Models\Finance\CommissionLine::count();

    app(UnitFundService::class)->recordReturn($owner, ['unit_id' => $unit->id, 'amount' => '400', 'entry_date' => fxToday()], (string) Str::uuid());

    expect(\App\Models\Finance\RevenueAllocation::count())->toBe($allocations)
        ->and(\App\Models\Finance\CommissionLine::count())->toBe($lines);
});

// ---------- Filters and page ----------

it('filters the expense page by unit, booking, category, status and date', function () {
    $owner = finOwner();
    $a = finUnit();
    $b = finUnit();
    $booking = finBooking($a);
    $fuel = fxSubmit($owner, $a, ['expense_scope' => 'job', 'booking_id' => $booking->booking_code]);
    $toll = fxSubmit($owner, $b, ['category' => 'toll', 'amount' => '120', 'fundings' => ['unit_fund' => '120']]);
    $old = fxSubmit($owner, $b, ['expense_date' => finMonday(3), 'category' => 'parking', 'amount' => '50', 'fundings' => ['unit_fund' => '50']]);

    $get = fn (array $q) => $this->actingAs($owner)->get(route('superadmin.finance.expenses.index', $q));

    $get(['unit_id' => $a->id])->assertSee($fuel->reference_code)->assertDontSee($toll->reference_code);
    $get(['booking' => $booking->booking_code])->assertSee($fuel->reference_code)->assertDontSee($toll->reference_code);
    $get(['category' => 'toll'])->assertSee($toll->reference_code)->assertDontSee($fuel->reference_code);
    $get(['status' => 'approved'])->assertDontSee($fuel->reference_code);
    $get([])->assertDontSee($old->reference_code);   // default: this week
    $get(['from' => finMonday(3), 'to' => fxToday()])->assertSee($old->reference_code);

    $this->actingAs($owner)->get(route('superadmin.dashboard'))
        ->assertSee('href="' . route('superadmin.finance.expenses.index') . '"', false);
    $this->actingAs(finUser(2))->get(route('admin.expense-review.index'))
        ->assertSee('href="' . route('admin.expense-review.index') . '"', false);
});
