<?php

use App\Models\Finance\Expense;
use App\Models\Finance\FundEntry;
use App\Models\Unit;
use App\Services\Finance\FinanceException;
use App\Services\Finance\UnitFundLedger;
use App\Services\Finance\UnitFundService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

/**
 * Low-level spending fixture: a unit-fund expense row (with its funding split and a receipt row) in the given
 * status, written directly so the Unit Funds ledger can be tested on its own. ExpensesTest covers the real flow.
 */
function finSpend(Unit $unit, string $amount, string $date, string $status = 'approved'): Expense
{
    $expense = Expense::create([
        'idempotency_key' => (string) Str::uuid(),
        'unit_id' => $unit->id,
        'category' => 'fuel_diesel',
        'description' => 'Diesel',
        'expense_scope' => 'unit',
        'coverage_month' => CarbonImmutable::parse($date)->startOfMonth()->toDateString(),
        'amount' => $amount,
        'expense_date' => $date,
        'funding_source' => 'unit_fund',
        'submitted_at' => now(),
    ]);
    \App\Models\Finance\ExpenseFunding::create(['expense_id' => $expense->id, 'source' => 'unit_fund', 'amount' => $amount]);
    \App\Models\Finance\ExpenseEvidence::create([
        'expense_id' => $expense->id, 'disk' => 'local', 'path' => 'finance/expenses/' . $expense->id . '/' . Str::uuid() . '.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'sha256' => hash('sha256', (string) Str::uuid()),
    ]);

    if ($status === 'approved') {
        $expense->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
    } elseif ($status === 'verified') {
        $expense->forceFill(['status' => 'verified', 'verified_at' => now()])->save();
    }

    return $expense;
}

function finIssue(Unit $unit, string $amount, string $date, string $kind = 'initial', ?string $key = null): FundEntry
{
    return app(UnitFundService::class)->issue(finOwnerCached(), [
        'unit_id' => $unit->id, 'issue_kind' => $kind, 'amount' => $amount, 'entry_date' => $date, 'counterparty_name' => 'Juan Dela Cruz',
    ], $key ?? (string) Str::uuid());
}

function finReturn(Unit $unit, string $amount, string $date): FundEntry
{
    return app(UnitFundService::class)->recordReturn(finOwnerCached(), [
        'unit_id' => $unit->id, 'amount' => $amount, 'entry_date' => $date, 'counterparty_name' => 'Juan Dela Cruz',
    ], (string) Str::uuid());
}

function finOwnerCached(): \App\Models\User
{
    return \App\Models\User::where('role_id', 1)->first() ?? finOwner();
}

function finToday(): string
{
    return now(config('finance.timezone'))->toDateString();
}

function finRow(Unit $unit, string $from, string $to): array
{
    return app(UnitFundLedger::class)->summary(CarbonImmutable::parse($from), CarbonImmutable::parse($to), $unit->id)->sole();
}

function finIssuePayload(Unit $unit, array $overrides = []): array
{
    return array_merge([
        'form' => 'issue',
        'idempotency_key' => (string) Str::uuid(),
        'unit_id' => $unit->id,
        'issue_kind' => 'initial',
        'entry_date' => finToday(),
        'amount' => '3000',
        'counterparty_name' => 'Juan Dela Cruz',
        'external_reference' => 'V-0001',
        'notes' => 'Start of week',
    ], $overrides);
}

// ---------- The client's example ----------

it('issues 3,000, deducts 2,950 advance-funded spending, accepts a 50 return and ends at exactly zero', function () {
    $unit = finUnit();
    $today = finToday();

    finIssue($unit, '3000', $today);
    finSpend($unit, '2950', $today);         // fixture until Phase 3
    finReturn($unit, '50', $today);

    $row = finRow($unit, $today, $today);

    expect($row['opening']->toString())->toBe('0.00')
        ->and($row['issued']->toString())->toBe('3000.00')
        ->and($row['spent']->toString())->toBe('2950.00')
        ->and($row['returned']->toString())->toBe('50.00')
        ->and($row['remaining']->toString())->toBe('0.00')
        ->and(app(UnitFundService::class)->balance($unit->id)->toString())->toBe('0.00');

    // Nothing is left to return.
    expect(fn () => finReturn($unit, '0.01', $today))->toThrow(FinanceException::class);
});

it('shows the same exact figures on the Owner page', function () {
    $owner = finOwner();
    $unit = finUnit();
    $today = finToday();
    finIssue($unit, '3000', $today);
    finSpend($unit, '2950', $today);
    finReturn($unit, '50', $today);

    $response = $this->actingAs($owner)->get(route('superadmin.finance.unit-funds.index', ['unit_id' => $unit->id, 'from' => $today, 'to' => $today]));

    $response->assertOk()
        ->assertSee('Unit Funds')
        ->assertSee('data-fin-total="issued">₱3,000.00<', false)
        ->assertSee('data-fin-total="spent">₱2,950.00<', false)
        ->assertSee('data-fin-total="returned">₱50.00<', false)
        ->assertSee('data-fin-total="remaining">₱0.00<', false)
        ->assertSee('Remaining balance = Opening balance + Funds issued − Advance-funded spending − Funds returned.');
});

// ---------- Carry-forward ----------

it('carries the outstanding balance into the next period as its opening balance', function () {
    $unit = finUnit();
    $lastMonday = finMonday(1);
    $lastSaturday = CarbonImmutable::parse($lastMonday)->addDays(5)->toDateString();
    $thisMonday = finMonday(0);
    $today = finToday();

    finIssue($unit, '3000', $lastMonday);
    finSpend($unit, '1000.25', CarbonImmutable::parse($lastMonday)->addDays(2)->toDateString());

    $lastWeek = finRow($unit, $lastMonday, $lastSaturday);
    expect($lastWeek['opening']->toString())->toBe('0.00')
        ->and($lastWeek['remaining']->toString())->toBe('1999.75');

    finIssue($unit, '500', $today, 'additional');

    $thisWeek = finRow($unit, $thisMonday, $today);
    expect($thisWeek['opening']->toString())->toBe('1999.75')   // carried from last week
        ->and($thisWeek['issued']->toString())->toBe('500.00')
        ->and($thisWeek['spent']->toString())->toBe('0.00')
        ->and($thisWeek['remaining']->toString())->toBe('2499.75');
});

// ---------- Duplicates ----------

it('records a double-submitted form only once', function () {
    $owner = finOwner();
    $unit = finUnit();
    $payload = finIssuePayload($unit);

    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.issue'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.issue'), $payload)->assertSessionHasNoErrors();

    expect(FundEntry::count())->toBe(1)
        ->and(FundEntry::sole()->amount)->toBe('3000.00')
        ->and(FundEntry::sole()->external_reference)->toBe('V-0001')
        ->and(\App\Models\AuditLog::where('action', 'finance_fund_issued')->count())->toBe(1);

    // The same submission key with a different amount is refused, not silently merged.
    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.issue'), ['amount' => '3500'] + $payload)
        ->assertSessionHasErrors('finance');
    expect(FundEntry::count())->toBe(1);
});

it('accepts daily operating funds for the same unit on consecutive days and carries what is left', function () {
    $unit = finUnit();
    $day1 = CarbonImmutable::parse(finToday())->subDays(2)->toDateString();
    $day2 = CarbonImmutable::parse(finToday())->subDay()->toDateString();
    $day3 = finToday();

    finIssue($unit, '3000', $day1, 'initial');
    finSpend($unit, '2800', $day1);
    finIssue($unit, '3000', $day2, 'initial');          // the next day's operating funds: not blocked
    finSpend($unit, '3100', $day2);
    finIssue($unit, '500', $day2, 'additional');        // a top-up on the same day
    finIssue($unit, '3000', $day3, 'initial');

    expect(FundEntry::where('unit_id', $unit->id)->recorded()->count())->toBe(4)
        ->and(finRow($unit, $day2, $day2)['opening']->toString())->toBe('200.00')     // day 1 leftover carried forward
        ->and(finRow($unit, $day2, $day2)['remaining']->toString())->toBe('600.00')   // 200 + 3000 + 500 - 3100
        ->and(finRow($unit, $day3, $day3)['remaining']->toString())->toBe('3600.00')
        ->and(FundEntry::where('unit_id', $unit->id)->orderBy('entry_date')->orderBy('id')->pluck('entry_date')->map->toDateString()->all())
        ->toBe([$day1, $day2, $day2, $day3]);

    // "Additional" funds on a day with no earlier issuance are allowed too; no forced daily settlement exists.
    expect(finIssue(finUnit(), '1000', finToday(), 'additional')->amount)->toBe('1000.00');
});

it('flags, but does not block, a second identical issuance on the same day', function () {
    $owner = finOwner();
    $unit = finUnit();
    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.issue'), finIssuePayload($unit))->assertSessionHasNoErrors()->assertSessionMissing('warning');
    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.issue'), finIssuePayload($unit))->assertSessionHasNoErrors()->assertSessionHas('warning');

    expect(FundEntry::where('unit_id', $unit->id)->count())->toBe(2);
});

// ---------- Authorization ----------

it('blocks every non-Owner role from viewing or recording unit funds', function (int $roleId) {
    $user = finUser($roleId);
    $unit = finUnit();

    $this->actingAs($user)->get(route('superadmin.finance.unit-funds.index'))->assertForbidden();
    $this->actingAs($user)->post(route('superadmin.finance.unit-funds.issue'), finIssuePayload($unit))->assertForbidden();
    $this->actingAs($user)->post(route('superadmin.finance.unit-funds.return'), finIssuePayload($unit, ['form' => 'return']))->assertForbidden();

    expect(FundEntry::count())->toBe(0);
})->with(['Dispatcher' => 2, 'Team Leader' => 3, 'Driver' => 4, 'Customer' => 5, 'System Admin' => 6]);

it('redirects guests to the login page', function () {
    $this->get(route('superadmin.finance.unit-funds.index'))->assertRedirect(route('login'));
    $this->post(route('superadmin.finance.unit-funds.issue'), finIssuePayload(finUnit()))->assertRedirect(route('login'));
    expect(FundEntry::count())->toBe(0);
});

it('refuses voids and corrections from non-Owners', function () {
    $unit = finUnit();
    $entry = finIssue($unit, '3000', finToday());
    $dispatcher = finUser(2);

    $this->actingAs($dispatcher)->post(route('superadmin.finance.unit-funds.void', $entry->id), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($dispatcher)->post(route('superadmin.finance.unit-funds.correct', $entry->id), finIssuePayload($unit, ['reason' => 'x']))->assertForbidden();

    expect($entry->fresh()->status)->toBe('recorded');
});

// ---------- Invalid input ----------

it('rejects invalid amounts without recording anything', function (string $amount) {
    $owner = finOwner();
    $unit = finUnit();

    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.issue'), finIssuePayload($unit, ['amount' => $amount]))
        ->assertSessionHasErrors();

    expect(FundEntry::count())->toBe(0);
})->with(['zero' => '0', 'zero decimals' => '0.00', 'negative' => '-50', 'three decimals' => '12.345', 'text' => 'abc', 'exponent' => '1e3', 'empty' => '']);

it('accepts thousands separators and stores the exact amount', function () {
    $this->actingAs(finOwner())->post(route('superadmin.finance.unit-funds.issue'), finIssuePayload(finUnit(), ['amount' => '3,000.50']))
        ->assertSessionHasNoErrors();

    expect(FundEntry::sole()->amount)->toBe('3000.50');
});

it('rejects future dates and a missing receiving person', function () {
    $owner = finOwner();
    $unit = finUnit();
    $tomorrow = now(config('finance.timezone'))->addDay()->toDateString();

    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.issue'), finIssuePayload($unit, ['entry_date' => $tomorrow]))->assertSessionHasErrors('entry_date');
    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.issue'), finIssuePayload($unit, ['counterparty_name' => '']))->assertSessionHasErrors('counterparty_name');

    expect(FundEntry::count())->toBe(0);
});

// ---------- Unsupported returns ----------

it('refuses a return larger than the remaining balance', function () {
    $owner = finOwner();
    $unit = finUnit();
    finIssue($unit, '3000', finToday());
    finSpend($unit, '2950', finToday());

    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.return'), finIssuePayload($unit, ['form' => 'return', 'amount' => '50.01']))
        ->assertSessionHasErrors('finance');

    expect(FundEntry::where('entry_type', 'return')->count())->toBe(0);
});

it('refuses a return for a unit that was never issued funds', function () {
    expect(fn () => finReturn(finUnit(), '1', finToday()))->toThrow(FinanceException::class);
});

it('refuses a return dated before the funds were issued', function () {
    $unit = finUnit();
    finIssue($unit, '3000', finToday());

    expect(fn () => finReturn($unit, '100', CarbonImmutable::parse(finToday())->subDays(3)->toDateString()))
        ->toThrow(FinanceException::class, 'on that date');
});

it('keeps pending expense reviews identifiable and sets them aside from returnable cash', function () {
    $owner = finOwner();
    $unit = finUnit();
    $today = finToday();
    finIssue($unit, '3000', $today);
    finSpend($unit, '700', $today, 'submitted');
    finSpend($unit, '300', $today, 'verified');

    $row = finRow($unit, $today, $today);
    expect($row['spent']->toString())->toBe('0.00')               // not deducted until approved
        ->and($row['remaining']->toString())->toBe('3000.00')
        ->and($row['pending_amount']->toString())->toBe('1000.00')
        ->and($row['pending_count'])->toBe(2);

    expect(fn () => finReturn($unit, '2000.01', $today))->toThrow(FinanceException::class, 'awaiting review');
    expect(finReturn($unit, '2000', $today)->amount)->toBe('2000.00');

    $this->actingAs($owner)->get(route('superadmin.finance.unit-funds.index', ['unit_id' => $unit->id]))
        ->assertSee('₱1,000.00 · 2 expenses');
});

// ---------- Corrections and history ----------

it('corrects an entry by voiding it and recording a linked replacement', function () {
    $owner = finOwner();
    $unit = finUnit();
    $original = finIssue($unit, '3000', finToday());

    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.correct', $original->id), [
        'form' => 'entry-' . $original->id,
        'idempotency_key' => (string) Str::uuid(),
        'entry_date' => finToday(),
        'amount' => '2500',
        'counterparty_name' => 'Juan Dela Cruz',
        'reason' => 'Wrong amount typed',
    ])->assertSessionHasNoErrors();

    $original->refresh();
    $replacement = FundEntry::where('corrects_entry_id', $original->id)->sole();

    expect($original->status)->toBe('voided')
        ->and($original->void_reason)->toBe('Corrected: Wrong amount typed')
        ->and($original->amount)->toBe('3000.00')            // history is never overwritten
        ->and($replacement->amount)->toBe('2500.00')
        ->and($replacement->issue_kind)->toBe('initial')
        ->and(FundEntry::count())->toBe(2)
        ->and(app(UnitFundService::class)->balance($unit->id)->toString())->toBe('2500.00');

    $this->actingAs($owner)->get(route('superadmin.finance.unit-funds.index', ['unit_id' => $unit->id]))
        ->assertSee($original->reference_code)
        ->assertSee('Corrects ' . $original->reference_code)
        ->assertSee('replaced by ' . $replacement->reference_code);
});

it('refuses a correction that would leave the unit with negative cash', function () {
    $unit = finUnit();
    $original = finIssue($unit, '3000', finToday());
    finSpend($unit, '2950', finToday());

    expect(fn () => app(UnitFundService::class)->correct(finOwnerCached(), $original, ['amount' => '1000', 'entry_date' => finToday()], 'Typo', (string) Str::uuid()))
        ->toThrow(FinanceException::class, 'negative');

    expect($original->fresh()->status)->toBe('recorded')
        ->and(FundEntry::count())->toBe(1);
});

it('voids an entry with a reason and keeps it in the history', function () {
    $owner = finOwner();
    $unit = finUnit();
    finIssue($unit, '3000', finToday());
    $return = finReturn($unit, '100', finToday());

    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.void', $return->id), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($owner)->post(route('superadmin.finance.unit-funds.void', $return->id), ['reason' => 'Return never happened'])->assertSessionHasNoErrors();

    expect($return->fresh()->status)->toBe('voided')
        ->and(app(UnitFundService::class)->balance($unit->id)->toString())->toBe('3000.00');

    $this->actingAs($owner)->get(route('superadmin.finance.unit-funds.index', ['unit_id' => $unit->id]))
        ->assertSee('Return never happened')
        ->assertSee('fin-tag--void', false);
});

it('still blocks voiding an issuance when that would leave negative cash', function () {
    $unit = finUnit();
    $first = finIssue($unit, '3000', finToday());
    finIssue($unit, '500', finToday(), 'additional');
    finSpend($unit, '3200', finToday());

    expect(fn () => app(UnitFundService::class)->void(finOwnerCached(), $first, 'Mistake'))->toThrow(FinanceException::class, 'negative');
});

// ---------- Separation and filters ----------

it('keeps customer collections out of unit fund balances', function () {
    $unit = finUnit();
    finIssue($unit, '1000', finToday());
    finBooking($unit, '5000.00'); // a completed, paid customer job for the same unit

    expect(finRow($unit, finToday(), finToday())['remaining']->toString())->toBe('1000.00');
});

it('filters the page by unit and date and lists the movement history', function () {
    $owner = finOwner();
    $a = finUnit();
    $b = finUnit();
    $entryA = finIssue($a, '1000', finToday());
    $entryB = finIssue($b, '2000', finToday());
    $old = finIssue(finUnit(), '400', finMonday(2));

    $response = $this->actingAs($owner)->get(route('superadmin.finance.unit-funds.index', ['unit_id' => $a->id, 'from' => finMonday(0), 'to' => finToday()]));
    $response->assertOk()
        ->assertSee($entryA->reference_code)
        ->assertDontSee($entryB->reference_code)
        ->assertDontSee($old->reference_code)
        ->assertSee('data-fin-total="issued">₱1,000.00<', false);

    // The default view is the current Monday–Saturday week, and the sidebar links here.
    $this->actingAs($owner)->get(route('superadmin.finance.unit-funds.index'))
        ->assertOk()
        ->assertSee('href="' . route('superadmin.finance.unit-funds.index') . '"', false)
        ->assertSee($entryB->reference_code)
        ->assertDontSee($old->reference_code);
});

it('sums sub-peso amounts exactly', function () {
    $unit = finUnit();
    finIssue($unit, '0.10', finToday());
    finIssue($unit, '0.20', finToday(), 'additional');

    expect(finRow($unit, finToday(), finToday())['remaining']->toString())->toBe('0.30');
});
