<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Finance\CommissionDeduction;
use App\Models\Finance\CommissionPolicy;
use App\Models\Finance\Expense;
use App\Models\Finance\JobPersonnel;
use App\Models\PaymentTransaction;
use App\Models\Quotation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\CommissionDeductionService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceException;
use App\Services\Finance\UnitFundLedger;
use App\Services\Finance\UnitFundService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

beforeEach(function () {
    Storage::fake('local');
});

/** All fixtures use LAST week's Wednesday, so nothing is ever dated in the future. */
function cmWeek(): CarbonImmutable
{
    return CarbonImmutable::now(config('finance.timezone'))->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
}

function cmDay(): CarbonImmutable
{
    return cmWeek()->addDays(2)->setTime(10, 0);
}

function cmOwner(): User
{
    return User::where('role_id', 1)->first() ?? finOwner();
}

function cmBooking(Unit $unit, string $total, string $state = 'confirmed', array $overrides = []): Booking
{
    $customer = Customer::create(['full_name' => 'Ana Reyes', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'cm-' . Str::random(8) . '@example.com']);
    $at = cmDay();

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'Bulacan ' . Str::random(4),
        'dropoff_address' => 'Quezon City',
        'distance_km' => 30,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => $total,
        'final_total' => $total,
        'completion_requested_at' => $at,
        'payment_submitted_at' => $at,
        'payment_method' => 'cash',
        'cash_received' => $total,
    ], $state === 'confirmed'
        ? ['status' => 'completed', 'completed_at' => $at->addHour()]
        : ['status' => 'waiting_verification', 'completed_at' => null], $overrides))->fresh();
}

function cmExpense(Booking $booking, Unit $unit, string $category, string $amount, array $fundings = null, bool $approve = true, string $scope = 'job'): Expense
{
    $service = app(ExpenseService::class);
    $expense = $service->submit(cmOwner(), [
        'unit_id' => $unit->id,
        'expense_scope' => $scope,
        'booking_id' => $scope === 'job' ? $booking->booking_code : null,
        'expense_date' => cmDay()->toDateString(),
        'category' => $category,
        'description' => config('finance.expense_categories')[$category],
        'amount' => $amount,
        'fundings' => $fundings ?? ['unit_fund' => $amount],
    ], (string) Str::uuid(), [UploadedFile::fake()->image('r.jpg', random_int(10, 90), random_int(10, 90))]);

    return $approve ? $service->approve(cmOwner(), $expense) : $expense;
}

function cmCrew(Booking $booking, ?Unit $unit, string $slot, ?string $role, string $name): JobPersonnel
{
    return JobPersonnel::create([
        'booking_id' => $booking->id, 'unit_id' => $unit?->id, 'slot' => $slot, 'commission_role' => $role,
        'personnel_id' => finPersonnel($role === 'crew_mate' ? 'crew' : 'driver')->id,
        'name_snapshot' => $name, 'source' => 'completion_capture', 'captured_at' => now(),
    ]);
}

function cmFullCrew(Booking $booking, Unit $unit, bool $withRider = true): void
{
    cmCrew($booking, $unit, 'team_leader', 'team_leader', 'Tomas Leader');
    cmCrew($booking, $unit, 'driver_1', 'driver', 'Dario Driver');
    if ($withRider) {
        cmCrew($booking, $unit, 'driver_2', 'rider', 'Rico Rider');
    }
    cmCrew($booking, $unit, 'crew_member_1', 'crew_mate', 'Carlo Crew');
}

function cmDraft(?int $unitId = null, ?CommissionPolicy $policy = null): array
{
    return app(CommissionCalculator::class)->draft(cmWeek(), $unitId, $policy);
}

function cmCodes(array $draft): array
{
    return collect($draft['blockers'])->pluck('code')->unique()->values()->all();
}

function cmRole(array $line, string $role): string
{
    return $line['roles'][$role]['share']->toString();
}

// ---------- Baseline acceptance ----------

it('matches the client baseline: 15,000 collected, 2,950 expenses, 12,050 base and exact shares', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '15000.00');
    cmFullCrew($booking, $unit);

    $funds = app(UnitFundService::class);
    $funds->issue(cmOwner(), ['unit_id' => $unit->id, 'issue_kind' => 'initial', 'amount' => '3000', 'entry_date' => cmDay()->toDateString(), 'counterparty_name' => 'Tomas Leader'], (string) Str::uuid());
    cmExpense($booking, $unit, 'fuel_diesel', '1700.00');
    cmExpense($booking, $unit, 'food_travel_allowance', '1000.00');
    cmExpense($booking, $unit, 'toll', '250.00');

    $beforeReturn = cmDraft();
    $funds->recordReturn(cmOwner(), ['unit_id' => $unit->id, 'amount' => '50', 'entry_date' => cmDay()->toDateString(), 'counterparty_name' => 'Tomas Leader'], (string) Str::uuid());
    $draft = cmDraft();
    $line = $draft['lines'][0];

    expect(app(UnitFundLedger::class)->balance($unit->id)->toString())->toBe('0.00')   // 3,000 − 2,950 − 50
        ->and($draft['totals']['collection']->toString())->toBe('15000.00')
        ->and($draft['totals']['expenses']->toString())->toBe('2950.00')
        ->and($draft['totals']['base']->toString())->toBe('12050.00')
        ->and($line['company']->toString())->toBe('7230.00')
        ->and(cmRole($line, 'driver'))->toBe('1446.00')
        ->and(cmRole($line, 'team_leader'))->toBe('1205.00')
        ->and(cmRole($line, 'rider'))->toBe('1205.00')
        ->and(cmRole($line, 'crew_mate'))->toBe('964.00')
        ->and($draft['totals']['crew_allocated']->toString())->toBe('4820.00')
        ->and($draft['totals']['unallocated']->toString())->toBe('0.00')
        ->and($line['check']->toString())->toBe('12050.00')
        ->and($draft['totals']['reconciles'])->toBeTrue()
        ->and($draft['blockers'])->toBe([])
        ->and($draft['can_approve'])->toBeTrue();

    // The PHP 50 return changed nothing in the commission.
    expect($beforeReturn['totals']['base']->toString())->toBe('12050.00')
        ->and($beforeReturn['totals']['company']->toString())->toBe($draft['totals']['company']->toString());

    // Each person's draft share.
    expect(collect($draft['totals']['by_recipient'])->mapWithKeys(fn ($r) => [$r['name'] => $r['amount']->toString()])->all())->toBe([
        'Carlo Crew' => '964.00', 'Dario Driver' => '1446.00', 'Rico Rider' => '1205.00', 'Tomas Leader' => '1205.00',
    ]);
});

it('stays a Draft / For Client Review while the commission interpretation is unconfirmed', function () {
    $unit = finUnit();
    $booking = cmBooking($unit, '15000.00');
    cmFullCrew($booking, $unit);

    $draft = cmDraft();   // the seeded v1 policy is still a draft pending client confirmation

    expect($draft['status'])->toBe('draft_for_client_review')
        ->and(cmCodes($draft))->toBe(['policy_for_client_review', 'rider_role_pending'])   // rider rule is a client decision too
        ->and($draft['can_approve'])->toBeFalse()
        ->and($draft['lines'][0]['company']->toString())->toBe('9000.00');   // still calculated for review
});

// ---------- Missing roles and personnel ----------

it('keeps an absent rider share unallocated instead of redistributing it', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '12050.00');
    cmFullCrew($booking, $unit, withRider: false);

    $draft = cmDraft();
    $line = $draft['lines'][0];
    expect($line['roles']['rider']['unallocated']->toString())->toBe('1205.00')
        ->and($line['roles']['rider']['recipients'])->toBe([])
        ->and(cmRole($line, 'driver'))->toBe('1446.00')                       // nobody else gets more
        ->and($line['company']->toString())->toBe('7230.00')
        ->and($draft['totals']['crew_allocated']->toString())->toBe('3615.00')
        ->and($draft['totals']['unallocated']->toString())->toBe('1205.00')
        ->and($line['check']->toString())->toBe('12050.00')
        ->and(cmCodes($draft))->toContain('missing_role')
        ->and($draft['can_approve'])->toBeFalse();
});

it('never invents personnel when none were recorded for the job', function () {
    finActivePolicy(cmOwner());
    $leader = finUser(3);
    $unit = finUnit($leader);
    cmBooking($unit, '10000.00', 'confirmed', ['assigned_team_leader_id' => $leader->id, 'driver_name' => 'Booking Driver']);

    $line = cmDraft()['lines'][0];
    expect(collect($line['roles'])->flatMap(fn ($r) => $r['recipients'])->all())->toBe([])
        ->and($line['crew_allocated']->toString())->toBe('0.00')
        ->and($line['unallocated']->toString())->toBe('4000.00')
        ->and(collect($line['blockers'])->pluck('code'))->toContain('no_personnel');
});

it('flags unmapped roles for review and shows them provisionally', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '10000.00');
    cmCrew($booking, $unit, 'team_leader', 'team_leader', 'Tomas Leader');
    cmCrew($booking, $unit, 'driver_1', 'driver', 'Dario Driver');
    cmCrew($booking, $unit, 'driver_2', null, 'Sam Substitute');          // role mapping not confirmed
    cmCrew($booking, $unit, 'crew_member_1', 'crew_mate', 'Carlo Crew');

    $draft = cmDraft();
    $rider = $draft['lines'][0]['roles']['rider'];
    expect($rider['recipients'][0]['name'])->toBe('Sam Substitute')
        ->and($rider['recipients'][0]['role_confirmed'])->toBeFalse()
        ->and(cmCodes($draft))->toContain('role_mapping_unresolved')
        ->and($draft['can_approve'])->toBeFalse();
});

// ---------- Blockers ----------

it('blocks approval for a partly paid job and leaves it out of the calculation', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '15000.00', 'submitted', ['payment_method' => 'gcash', 'cash_received' => null]);
    cmFullCrew($booking, $unit);
    PaymentTransaction::create(['provider' => 'paymongo', 'reference' => 'TM-' . Str::random(8), 'payable_type' => 'booking', 'payable_id' => $booking->id,
        'booking_id' => $booking->id, 'customer_id' => $booking->customer_id, 'method' => 'gcash', 'amount_centavos' => 500000, 'status' => 'paid', 'livemode' => true, 'paid_at' => cmDay()]);

    $draft = cmDraft();
    expect($draft['lines'])->toBe([])
        ->and($draft['blocked_jobs'])->toHaveCount(1)
        ->and(cmCodes($draft))->toContain('payment_not_verified')->toContain('allocation_pending')
        ->and($draft['can_approve'])->toBeFalse();
});

it('blocks approval while job expenses await review, and does not deduct them yet', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '15000.00');
    cmFullCrew($booking, $unit);
    cmExpense($booking, $unit, 'fuel_diesel', '1700.00');
    cmExpense($booking, $unit, 'repairs', '800.00', approve: false);

    $draft = cmDraft();
    expect($draft['totals']['expenses']->toString())->toBe('1700.00')
        ->and($draft['totals']['base']->toString())->toBe('13300.00')
        ->and(cmCodes($draft))->toContain('expenses_pending');
});

it('keeps monthly maintenance pending allocation and out of the base', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '15000.00');
    cmFullCrew($booking, $unit);
    cmExpense($booking, $unit, 'maintenance', '3000.00', ['company_direct' => '3000.00'], approve: true, scope: 'unit');

    $draft = cmDraft();
    expect($draft['totals']['base']->toString())->toBe('15000.00')
        ->and($draft['monthly_expenses'])->toHaveCount(1)
        ->and(cmCodes($draft))->toBe(['monthly_allocation_pending']);
});

it('keeps personal advances pending clarification while still counting the cost once', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '15000.00');
    cmFullCrew($booking, $unit);
    cmExpense($booking, $unit, 'parts_equipment', '600.00', ['unit_fund' => '400.00', 'personal_advance' => '200.00']);

    $draft = cmDraft();
    expect($draft['totals']['expenses']->toString())->toBe('600.00')
        ->and($draft['totals']['base']->toString())->toBe('14400.00')
        ->and(cmCodes($draft))->toBe(['personal_advance_pending']);
});

it('blocks a zero or negative sharing base and calculates no shares for it', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '1500.00');
    cmFullCrew($booking, $unit);
    cmExpense($booking, $unit, 'repairs', '1500.00', ['company_direct' => '1500.00']);

    $line = cmDraft()['lines'][0];
    expect($line['base']->toString())->toBe('0.00')
        ->and($line['company']->toString())->toBe('0.00')
        ->and($line['crew_allocated']->toString())->toBe('0.00')
        ->and(collect($line['blockers'])->pluck('code'))->toContain('non_positive_base');

    cmExpense($booking, $unit, 'toll', '100.00');
    expect(cmDraft()['lines'][0]['base']->toString())->toBe('-100.00');
});

it('blocks invalid commission percentages', function () {
    $unit = finUnit();
    $booking = cmBooking($unit, '10000.00');
    cmFullCrew($booking, $unit);

    $bad = new CommissionPolicy(['version' => 99, 'name' => 'Invalid', 'company_rate' => '60', 'driver_rate' => '12', 'team_leader_rate' => '10', 'rider_rate' => '10', 'crew_mate_rate' => '7']);
    $draft = cmDraft(policy: $bad);

    expect(cmCodes($draft))->toContain('invalid_percentages')
        ->and($draft['lines'][0]['company']->toString())->toBe('0.00')
        ->and($draft['can_approve'])->toBeFalse();

    $negative = new CommissionPolicy(['version' => 98, 'name' => 'Negative', 'company_rate' => '70', 'driver_rate' => '-2', 'team_leader_rate' => '12', 'rider_rate' => '10', 'crew_mate_rate' => '10']);
    expect(cmCodes(cmDraft(policy: $negative)))->toContain('invalid_percentages');
});

// ---------- Multiple units ----------

it('calculates two units independently and reconciles the PHP 15,000 collection exactly', function () {
    finActivePolicy(cmOwner());
    $a = finUnit();
    $b = finUnit();
    $customer = Customer::create(['full_name' => 'Group Client', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'g-' . Str::random(6) . '@example.com']);
    $code = 'GRP-' . Str::upper(Str::random(5));
    $m1 = cmBooking($a, '10000.00', 'confirmed', ['customer_id' => $customer->id, 'group_code' => $code, 'pickup_address' => 'Same pickup', 'cash_received' => null]);
    $m2 = cmBooking($b, '5000.00', 'confirmed', ['customer_id' => $customer->id, 'group_code' => $code, 'pickup_address' => 'Same pickup', 'cash_received' => null]);
    $q = Quotation::create(['quotation_number' => 'QT-' . Str::random(6), 'source_booking_id' => $m1->id, 'customer_id' => $customer->id, 'truck_type_id' => $m1->truck_type_id,
        'pickup_address' => 'Same pickup', 'dropoff_address' => 'Quezon City', 'distance_km' => 30, 'estimated_price' => '15000', 'additional_fee' => '0', 'discount' => '0',
        'service_type' => 'book_now', 'status' => 'accepted', 'extra_vehicles' => [['booking_id' => $m2->id, 'truck_type_id' => $m2->truck_type_id, 'final_total' => 5000.0]]]);
    $m1->update(['quotation_id' => $q->id]);
    $m2->update(['quotation_id' => $q->id]);
    cmFullCrew($m1, $a);
    cmFullCrew($m2, $b);
    cmExpense($m1, $a, 'fuel_diesel', '1000.00');
    cmExpense($m2, $b, 'fuel_diesel', '500.00');

    $draft = cmDraft();
    $lines = collect($draft['lines'])->keyBy('unit_id');

    expect($lines)->toHaveCount(2)
        ->and($draft['totals']['collection']->toString())->toBe('15000.00')        // collected once
        ->and($draft['totals']['expenses']->toString())->toBe('1500.00')           // each expense once
        ->and($lines[$a->id]['base']->toString())->toBe('9000.00')
        ->and($lines[$b->id]['base']->toString())->toBe('4500.00')
        ->and($lines[$a->id]['company']->toString())->toBe('5400.00')
        ->and($lines[$b->id]['company']->toString())->toBe('2700.00')
        ->and($draft['totals']['base']->toString())->toBe('13500.00')
        ->and($draft['totals']['reconciles'])->toBeTrue()
        ->and($draft['can_approve'])->toBeTrue();

    expect(cmDraft($a->id)['totals']['collection']->toString())->toBe('10000.00');
});

// ---------- Rounding ----------

it('reconciles every line to the exact centavo, putting rounding on Company', function (string $collection, string $expense) {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, $collection);
    cmFullCrew($booking, $unit);
    if ($expense !== '0') {
        cmExpense($booking, $unit, 'toll', $expense);
    }

    $line = cmDraft()['lines'][0];
    $sum = $line['company']->plus($line['crew_allocated'])->plus($line['unallocated']);
    expect($sum->toString())->toBe($line['base']->toString())
        ->and(Money::sum(collect($line['roles'])->flatMap(fn ($r) => collect($r['recipients'])->pluck('amount')))->toString())->toBe($line['crew_allocated']->toString());
})->with([
    'one centavo over' => ['1000.01', '0'],
    'awkward base' => ['1234.57', '0'],
    'base after expense' => ['9999.99', '0.02'],
    'tiny base' => ['0.03', '0'],
]);

it('shares the rounding of a role held by two people exactly', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '1000.01');
    cmFullCrew($booking, $unit);
    cmCrew($booking, $unit, 'crew_member_2', 'crew_mate', 'Second Crew');

    $draft = cmDraft();
    $crew = $draft['lines'][0]['roles']['crew_mate'];
    expect(Money::sum(collect($crew['recipients'])->pluck('amount'))->toString())->toBe($crew['share']->toString())
        ->and(count($crew['recipients']))->toBe(2)
        ->and(cmCodes($draft))->toContain('shared_role_rule_pending');
});

// ---------- Deductions ----------

it('subtracts an approved deduction once, blocks while pending, and refuses duplicates', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '15000.00');
    cmFullCrew($booking, $unit);
    $service = app(CommissionDeductionService::class);
    $key = (string) Str::uuid();
    $data = ['booking_id' => $booking->id, 'unit_id' => $unit->id, 'deduction_type' => 'refund', 'amount' => '500', 'description' => 'Partial refund for scratched bumper', 'external_reference' => 'RF-001'];

    $deduction = $service->record(cmOwner(), $data, $key);
    expect($service->record(cmOwner(), $data, $key)->id)->toBe($deduction->id);                // replay of the same submission
    expect(fn () => $service->record(cmOwner(), $data, (string) Str::uuid()))->toThrow(FinanceException::class, 'already recorded');
    expect(fn () => $service->record(cmOwner(), array_merge($data, ['external_reference' => null]), (string) Str::uuid()))->not->toThrow(FinanceException::class);
    expect(fn () => $service->record(cmOwner(), array_merge($data, ['external_reference' => null]), (string) Str::uuid()))->toThrow(FinanceException::class, 'already recorded');
    finExpectDbRejects(fn () => CommissionDeduction::create($data + ['idempotency_key' => (string) Str::uuid()]), 'fcd_one_active_per_reference');

    $draft = cmDraft();
    expect($draft['totals']['base']->toString())->toBe('15000.00')                          // pending: not deducted
        ->and(cmCodes($draft))->toContain('deduction_pending');

    $service->approve(cmOwner(), $deduction);
    CommissionDeduction::where('status', 'pending_review')->get()->each(fn ($d) => $service->void(cmOwner(), $d, 'Entered by mistake'));

    $draft = cmDraft();
    expect($draft['totals']['deductions']->toString())->toBe('500.00')
        ->and($draft['totals']['base']->toString())->toBe('14500.00')
        ->and($draft['blockers'])->toBe([]);
});

it('requires the online payment fee to be recorded before approval', function () {
    finActivePolicy(cmOwner());
    $unit = finUnit();
    $booking = cmBooking($unit, '10000.00', 'confirmed', ['payment_method' => 'gcash', 'cash_received' => null]);
    cmFullCrew($booking, $unit);
    PaymentTransaction::create(['provider' => 'paymongo', 'reference' => 'TM-' . Str::random(8), 'payable_type' => 'booking', 'payable_id' => $booking->id,
        'booking_id' => $booking->id, 'customer_id' => $booking->customer_id, 'method' => 'gcash', 'amount_centavos' => 1000000, 'status' => 'paid', 'livemode' => true, 'paid_at' => cmDay()]);

    expect(cmCodes(cmDraft()))->toBe(['payment_fee_unresolved']);

    $fee = app(CommissionDeductionService::class)->record(cmOwner(), ['booking_id' => $booking->id, 'unit_id' => $unit->id, 'deduction_type' => 'payment_fee', 'amount' => '250', 'description' => 'GCash fee 2.5%', 'external_reference' => 'pay_123'], (string) Str::uuid());
    app(CommissionDeductionService::class)->approve(cmOwner(), $fee);

    $draft = cmDraft();
    expect($draft['blockers'])->toBe([])->and($draft['totals']['base']->toString())->toBe('9750.00');
});

// ---------- Page, access and side effects ----------

it('shows the draft on the Owner page and writes no statement, line or payout', function () {
    $unit = finUnit();
    $booking = cmBooking($unit, '15000.00');
    cmFullCrew($booking, $unit);
    cmExpense($booking, $unit, 'fuel_diesel', '2950.00');

    $this->actingAs(cmOwner())->get(route('superadmin.finance.commission.index', ['week' => cmWeek()->toDateString()]))
        ->assertOk()
        ->assertSee('Draft / For Client Review')
        ->assertSee('data-cm-total="base">₱12,050.00<', false)
        ->assertSee('data-cm-total="company">₱7,230.00<', false)
        ->assertSee('data-cm-total="crew">₱4,820.00<', false)
        ->assertSee('data-cm-blocker="policy_for_client_review"', false)
        ->assertSee('href="' . route('superadmin.finance.commission.index') . '"', false);

    expect(\App\Models\Finance\CommissionStatement::count())->toBe(0)
        ->and(\App\Models\Finance\CommissionLine::count())->toBe(0)
        ->and(\App\Models\Finance\Payout::count())->toBe(0);
});

it('lets only the Owner view the draft or manage deductions', function () {
    $booking = cmBooking(finUnit(), '1000.00');
    foreach ([2, 3, 4, 5, 6] as $role) {
        $user = finUser($role);
        $this->actingAs($user)->get(route('superadmin.finance.commission.index'))->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.commission.deductions.store'), [
            'booking_code' => $booking->booking_code, 'unit_id' => $booking->assigned_unit_id, 'deduction_type' => 'other', 'amount' => '1', 'description' => 'x', 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        expect(fn () => app(CommissionDeductionService::class)->record($user, ['booking_id' => $booking->id, 'unit_id' => $booking->assigned_unit_id, 'deduction_type' => 'other', 'amount' => '1', 'description' => 'x'], (string) Str::uuid()))
            ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    }
    expect(CommissionDeduction::count())->toBe(0);
});
