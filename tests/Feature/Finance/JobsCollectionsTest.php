<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseFunding;
use App\Models\Finance\JobPersonnel;
use App\Models\Finance\RevenueAllocation;
use App\Models\PaymentTransaction;
use App\Models\Quotation;
use App\Models\Unit;
use App\Services\Finance\FinanceException;
use App\Services\Finance\JobCollectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

function jcNow(): CarbonImmutable
{
    return CarbonImmutable::now(config('finance.timezone'));
}

function jcCustomer(): Customer
{
    return Customer::create(['full_name' => 'Maria Santos', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'jc-' . Str::random(8) . '@example.com']);
}

/**
 * A booking whose tow is done. $state: confirmed (Dispatcher confirmed payment), submitted (TL submitted payment,
 * awaiting verification), overridden (status set to completed without a payment confirmation).
 */
function jcBooking(Unit $unit, string $total, string $state = 'confirmed', array $overrides = []): Booking
{
    $done = jcNow()->subHours(3);
    $base = [
        'customer_id' => jcCustomer()->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'Pasay ' . Str::random(4),
        'dropoff_address' => 'Makati',
        'distance_km' => 8,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => $total,
        'final_total' => $total,
        'completion_requested_at' => $done,
        'payment_method' => 'cash',
        'cash_received' => $total,
    ];
    $byState = match ($state) {
        'confirmed' => ['status' => 'completed', 'payment_submitted_at' => $done, 'completed_at' => $done->addHour()],
        'submitted' => ['status' => 'waiting_verification', 'payment_submitted_at' => $done, 'completed_at' => null],
        'overridden' => ['status' => 'completed', 'payment_submitted_at' => null, 'completed_at' => null, 'payment_method' => null, 'cash_received' => null],
    };

    return Booking::create(array_merge($base, $byState, $overrides))->fresh();
}

/** A normalized group trip: one booking per vehicle sharing group_code + pickup + dropoff, priced by one quotation. */
function jcGroup(array $vehicles, string $state = 'confirmed', string $fee = '0', string $discount = '0'): array
{
    $customer = jcCustomer();
    $code = 'GRP-' . Str::upper(Str::random(6));
    $pickup = 'Group pickup ' . Str::random(4);
    $members = [];
    foreach ($vehicles as [$unit, $total]) {
        $members[] = jcBooking($unit, $total, $state, ['customer_id' => $customer->id, 'group_code' => $code, 'pickup_address' => $pickup, 'cash_received' => null]);
    }
    $quotation = Quotation::create([
        'quotation_number' => 'QT-JC-' . Str::random(8),
        'source_booking_id' => $members[0]->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $members[0]->truck_type_id,
        'pickup_address' => $pickup,
        'dropoff_address' => 'Makati',
        'distance_km' => 8,
        'estimated_price' => '0',
        'additional_fee' => $fee,
        'discount' => $discount,
        'service_type' => 'book_now',
        'status' => 'accepted',
        'extra_vehicles' => collect($members)->slice(1)->map(fn ($b) => ['booking_id' => $b->id, 'truck_type_id' => $b->truck_type_id, 'final_total' => (float) $b->final_total])->values()->all(),
    ]);
    foreach ($members as $m) {
        $m->update(['quotation_id' => $quotation->id]);
    }

    return array_map(fn ($m) => $m->fresh(), $members);
}

function jcPayment(Booking $booking, string $amount, string $status = 'paid', bool $live = true): PaymentTransaction
{
    return PaymentTransaction::create([
        'provider' => 'paymongo',
        'reference' => 'TM-' . Str::upper(Str::random(10)),
        'payable_type' => $booking->group_code ? 'group' : 'booking',
        'payable_id' => $booking->id,
        'booking_id' => $booking->id,
        'customer_id' => $booking->customer_id,
        'method' => 'gcash',
        'amount_centavos' => (int) bcmul($amount, '100', 0),
        'status' => $status,
        'livemode' => $live,
        'paid_at' => $status === 'paid' ? jcNow()->subHour() : null,
    ]);
}

function jcJob(Booking $booking): array
{
    return app(JobCollectionService::class)->jobFor($booking->fresh());
}

function jcJobs(array $filters = [], ?CarbonImmutable $from = null, ?CarbonImmutable $to = null)
{
    $week = jcNow()->startOfWeek(CarbonImmutable::MONDAY);

    return app(JobCollectionService::class)->jobs($from ?? $week->subWeeks(2), $to ?? jcNow(), $filters);
}

// ---------- Verified versus unverified ----------

it('counts a Dispatcher-confirmed payment as the full verified collection', function () {
    $job = jcJob(jcBooking(finUnit(), '1800.00'));

    expect($job['status'])->toBe('paid')
        ->and($job['collected']->toString())->toBe('1800.00')
        ->and($job['outstanding']->toString())->toBe('0.00')
        ->and($job['confirmed_by_dispatcher'])->toBeTrue()
        ->and($job['payment_date'])->not->toBeNull();
});

it('does not count submitted, chosen, TEST, pending or failed payments, or demo bookings', function () {
    $unit = finUnit();

    $submitted = jcJob(jcBooking($unit, '2500.00', 'submitted', ['payment_method' => 'gcash', 'preferred_payment_method' => 'gcash']));
    expect($submitted['status'])->toBe('awaiting_verification')
        ->and($submitted['collected']->toString())->toBe('0.00')
        ->and($submitted['outstanding']->toString())->toBe('2500.00')
        ->and($submitted['payment_date'])->toBeNull();

    $test = jcBooking($unit, '3000.00', 'submitted');
    jcPayment($test, '3000.00', 'paid', live: false);
    expect(jcJob($test)['collected']->toString())->toBe('0.00')
        ->and(jcJob($test)['flags'])->toHaveKey('test_payment');

    $pending = jcBooking($unit, '1000.00', 'submitted');
    jcPayment($pending, '1000.00', 'pending');
    jcPayment($pending, '1000.00', 'failed');
    jcPayment($pending, '1000.00', 'expired');
    expect(jcJob($pending)['collected']->toString())->toBe('0.00');

    $demo = jcBooking($unit, '9999.00', 'confirmed');
    $demo->forceFill(['is_demo' => true])->save();
    expect(jcJobs()->pluck('anchor.id'))->not->toContain($demo->id);
});

it('counts a live provider-verified online payment, once, even after Dispatcher confirmation', function () {
    $booking = jcBooking(finUnit(), '4000.00', 'submitted', ['payment_method' => 'gcash', 'cash_received' => null]);
    jcPayment($booking, '4000.00');
    expect(jcJob($booking)['status'])->toBe('paid')->and(jcJob($booking)['collected']->toString())->toBe('4000.00');

    $booking->update(['status' => 'completed', 'completed_at' => now()]);
    expect(jcJob($booking)['collected']->toString())->toBe('4000.00');   // not 8,000
});

// ---------- Partial, change, unpaid ----------

it('keeps a partial customer payment and its outstanding balance exact', function () {
    $booking = jcBooking(finUnit(), '15000.00', 'submitted', ['payment_method' => 'gcash', 'cash_received' => null]);
    jcPayment($booking, '5000.50');

    $job = jcJob($booking);
    expect($job['status'])->toBe('partial')
        ->and($job['collected']->toString())->toBe('5000.50')
        ->and($job['outstanding']->toString())->toBe('9999.50')
        ->and($job['allocation']['status'])->toBe('pending_review')
        ->and($job['allocation']['reason'])->toContain('partly paid');
});

it('never treats cash tendered (with change) as revenue', function () {
    $job = jcJob(jcBooking(finUnit(), '1800.00', 'confirmed', ['cash_received' => '2000.00']));

    expect($job['collected']->toString())->toBe('1800.00')
        ->and($job['cash_tendered']->toString())->toBe('2000.00')
        ->and($job['change']->toString())->toBe('200.00')
        ->and($job['flags']['change'])->toContain('change ₱200.00');
});

it('shows a completed but unconfirmed job as unpaid, not as revenue', function () {
    $job = jcJob(jcBooking(finUnit(), '2200.00', 'overridden'));

    expect($job['status'])->toBe('completed_unverified')
        ->and($job['collected']->toString())->toBe('0.00')
        ->and($job['outstanding']->toString())->toBe('2200.00')
        ->and($job['allocation']['status'])->toBe('nothing_collected');
});

// ---------- Groups and duplicates ----------

it('counts a grouped trip once, with its group fee, and does not double-count payments', function () {
    $a = finUnit();
    $members = jcGroup([[$a, '5000.00'], [$a, '5000.00'], [$a, '5000.00']], 'confirmed', fee: '300.00');
    jcPayment($members[0], '15300.00');                       // the group paid online too
    jcPayment($members[0], '15300.00', PaymentTransaction::STATUS_DUPLICATE);

    $jobs = jcJobs()->filter(fn ($j) => $j['group_code'] === $members[0]->group_code)->values();
    expect($jobs)->toHaveCount(1);

    $job = $jobs->first();
    expect($job['vehicles'])->toBe(3)
        ->and($job['agreed']->toString())->toBe('15300.00')
        ->and($job['collected']->toString())->toBe('15300.00')
        ->and($job['flags'])->toHaveKey('refund_review')
        ->and($job['allocation']['status'])->toBe('automatic')
        ->and($job['allocation']['total']->toString())->toBe('15300.00');

    $totals = app(JobCollectionService::class)->totals($jobs);
    expect($totals['bookings'])->toBe(1)->and($totals['vehicles'])->toBe(3)->and($totals['collected']->toString())->toBe('15300.00');
});

it('uses the current invoice total when an invoice exists', function () {
    $booking = jcBooking(finUnit(), '2000.00');
    \App\Models\Invoice::create(['invoice_number' => 'INV-' . Str::random(6), 'booking_id' => $booking->id, 'subtotal' => '2000', 'additional_fee' => '150', 'discount' => '0', 'total' => '2150.00', 'status' => 'issued', 'is_current' => true]);

    expect(jcJob($booking)['agreed']->toString())->toBe('2150.00')->and(jcJob($booking)['agreed_source'])->toBe('invoice');
});

// ---------- Multi-unit allocation ----------

it('allocates a two-unit group trip so the units total exactly PHP 15,000', function () {
    $unitA = finUnit();
    $unitB = finUnit();
    $members = jcGroup([[$unitA, '10000.00'], [$unitB, '5000.00']]);

    $job = jcJob($members[0]);
    expect($job['allocation']['status'])->toBe('automatic')
        ->and($job['allocation']['by_unit'][$unitA->id]['amount']->toString())->toBe('10000.00')
        ->and($job['allocation']['by_unit'][$unitB->id]['amount']->toString())->toBe('5000.00')
        ->and($job['allocation']['total']->toString())->toBe('15000.00')
        ->and($job['allocatable']->toString())->toBe('15000.00');
});

it('shares a group discount by each vehicle charge and still totals exactly', function () {
    $units = [finUnit(), finUnit(), finUnit()];
    $members = jcGroup([[$units[0], '3333.33'], [$units[1], '3333.33'], [$units[2], '3333.34']], 'confirmed', discount: '100.00');

    $job = jcJob($members[0]);
    expect($job['agreed']->toString())->toBe('9900.00')
        ->and($job['allocation']['total']->toString())->toBe('9900.00')
        ->and(\App\Support\Money::sum(collect($job['allocation']['by_unit'])->pluck('amount'))->toString())->toBe('9900.00');
});

it('holds a one-booking job that another unit helped on for review, then records the Owner split of PHP 15,000', function () {
    $owner = finOwner();
    $unitA = finUnit();
    $unitB = finUnit();
    $booking = jcBooking($unitA, '15000.00');
    $help = Expense::create(['idempotency_key' => (string) Str::uuid(), 'unit_id' => $unitB->id, 'booking_id' => $booking->id, 'expense_scope' => 'job',
        'category' => 'assistance_other_unit', 'description' => 'Winch help', 'amount' => '500', 'expense_date' => jcNow()->toDateString(), 'funding_source' => 'unit_fund', 'submitted_at' => now()]);
    ExpenseFunding::create(['expense_id' => $help->id, 'source' => 'unit_fund', 'amount' => '500']);

    $job = jcJob($booking);
    expect($job['allocation']['status'])->toBe('pending_review')
        ->and($job['allocation']['reason'])->toContain('another unit')
        ->and($job['allocation']['by_unit'])->toBe([]);                 // never 50:50 by default

    // Amounts that do not add up exactly are refused.
    expect(fn () => app(JobCollectionService::class)->recordSplit($owner, $booking, [$unitA->id => '9000.00', $unitB->id => '5999.99']))
        ->toThrow(FinanceException::class, 'add up exactly');

    $this->actingAs($owner)->post(route('superadmin.finance.collections.split', $booking->id), [
        'amounts' => [$unitA->id => '9,000.50', $unitB->id => '5999.50'], 'note' => 'Unit B winched',
    ])->assertSessionHasNoErrors();

    $job = jcJob($booking);
    expect($job['allocation']['status'])->toBe('manual')
        ->and($job['allocation']['by_unit'][$unitA->id]['amount']->toString())->toBe('9000.50')
        ->and($job['allocation']['by_unit'][$unitB->id]['amount']->toString())->toBe('5999.50')
        ->and($job['allocation']['total']->toString())->toBe('15000.00');

    // A changed split supersedes the old rows; history is kept.
    app(JobCollectionService::class)->recordSplit($owner, $booking, [$unitA->id => '10000', $unitB->id => '5000']);
    expect(RevenueAllocation::where('booking_id', $booking->id)->count())->toBe(4)
        ->and(RevenueAllocation::current()->where('booking_id', $booking->id)->count())->toBe(2)
        ->and(RevenueAllocation::whereNotNull('superseded_at')->whereNull('superseded_by_id')->count())->toBe(0);
});

it('flags a recorded split for review when the collection later changes', function () {
    $owner = finOwner();
    $a = finUnit();
    $b = finUnit();
    $booking = jcBooking($a, '15000.00', 'submitted', ['payment_method' => 'gcash', 'cash_received' => null]);
    jcPayment($booking, '15000.00');
    app(JobCollectionService::class)->recordSplit($owner, $booking, [$a->id => '7500', $b->id => '7500']);
    expect(jcJob($booking)['allocation']['status'])->toBe('manual');

    // The invoice is re-issued with a discount: only 14,000 of the collection is now allocatable revenue.
    \App\Models\Invoice::create(['invoice_number' => 'INV-' . Str::random(6), 'booking_id' => $booking->id, 'subtotal' => '15000', 'additional_fee' => '0', 'discount' => '1000', 'total' => '14000.00', 'status' => 'issued', 'is_current' => true]);

    expect(jcJob($booking)['allocation']['status'])->toBe('pending_review')
        ->and(jcJob($booking)['status'])->toBe('overpaid')
        ->and(jcJob($booking)['allocation']['reason'])->toContain('based on ₱15,000.00');
});

it('refuses a split on a job with nothing collected', function () {
    expect(fn () => app(JobCollectionService::class)->recordSplit(finOwner(), jcBooking(finUnit(), '1000.00', 'submitted'), [finUnit()->id => '1000']))
        ->toThrow(FinanceException::class, 'no verified collection');
});

// ---------- Vehicles versus bookings ----------

it('counts one booking with three vehicles as one booking and three vehicles', function () {
    $legacy = jcBooking(finUnit(), '6000.00', 'confirmed', ['extra_vehicles' => [['truck_type_id' => 1, 'plate' => 'AAA-111'], ['truck_type_id' => 1, 'plate' => 'BBB-222']]]);

    $jobs = jcJobs(['booking' => $legacy->booking_code]);
    $totals = app(JobCollectionService::class)->totals($jobs);

    expect($jobs)->toHaveCount(1)
        ->and($jobs->first()['vehicles'])->toBe(3)
        ->and($totals['bookings'])->toBe(1)
        ->and($totals['vehicles'])->toBe(3);
});

// ---------- Historical personnel ----------

it('shows the crew recorded at the time, never the unit current crew', function () {
    $oldLeader = finUser(3, ['name' => 'Old Leader']);
    $newLeader = finUser(3, ['name' => 'New Leader']);
    $unit = finUnit($oldLeader);
    $unit->update(['driver_name' => 'Old Driver']);
    $booking = jcBooking($unit, '3000.00', 'confirmed', ['assigned_team_leader_id' => $oldLeader->id, 'driver_name' => 'Pedro Penduko']);

    // The unit's crew changes after the job.
    $unit->update(['team_leader_id' => $newLeader->id, 'driver_name' => 'Today Driver']);

    $names = collect(jcJob($booking)['personnel']['people'])->pluck('name')->all();
    expect($names)->toContain('Pedro Penduko')
        ->and(collect($names)->contains(fn ($n) => str_contains($n, 'Old Leader') || $n === $oldLeader->full_name))->toBeTrue()
        ->and($names)->not->toContain('Today Driver')
        ->and(collect($names)->contains(fn ($n) => str_contains($n, 'New Leader')))->toBeFalse();

    // Captured job personnel take precedence.
    JobPersonnel::create(['booking_id' => $booking->id, 'unit_id' => $unit->id, 'slot' => 'crew_member_1', 'name_snapshot' => 'Juan Crew', 'source' => 'manual', 'captured_at' => now()]);
    expect(jcJob($booking)['personnel']['source'])->toBe('captured')
        ->and(collect(jcJob($booking)['personnel']['people'])->pluck('name')->all())->toBe(['Juan Crew']);
});

// ---------- Dates and filters ----------

it('filters by service date or payment date and flags late payments and Sundays', function () {
    $unit = finUnit();
    $lastWeekTuesday = jcNow()->startOfWeek(CarbonImmutable::MONDAY)->subWeek()->addDay()->setTime(10, 0);
    $late = jcBooking($unit, '2000.00', 'confirmed', ['completion_requested_at' => $lastWeekTuesday, 'payment_submitted_at' => $lastWeekTuesday, 'completed_at' => jcNow()->subMinutes(5)]);
    $lastSunday = jcNow()->startOfWeek(CarbonImmutable::MONDAY)->subDay()->setTime(9, 0);
    $sunday = jcBooking($unit, '1500.00', 'confirmed', ['completion_requested_at' => $lastSunday, 'payment_submitted_at' => $lastSunday, 'completed_at' => $lastSunday->addHour()]);

    $thisWeek = [jcNow()->startOfWeek(CarbonImmutable::MONDAY), jcNow()];
    $byService = jcJobs(['basis' => 'service'], ...$thisWeek)->pluck('anchor.id');
    $byPayment = jcJobs(['basis' => 'payment'], ...$thisWeek)->pluck('anchor.id');

    expect($byService)->not->toContain($late->id)
        ->and($byPayment)->toContain($late->id)
        ->and(jcJob($late)['flags'])->toHaveKey('late_payment')
        ->and(jcJob($sunday)['flags'])->toHaveKey('sunday');
});

it('filters by unit, booking, collection status and payment method', function () {
    $a = finUnit();
    $b = finUnit();
    $paidCash = jcBooking($a, '1000.00');
    $awaitingGcash = jcBooking($b, '2000.00', 'submitted', ['payment_method' => 'gcash', 'cash_received' => null]);

    expect(jcJobs(['unit_id' => $a->id])->pluck('anchor.id')->all())->toBe([$paidCash->id])
        ->and(jcJobs(['booking' => $awaitingGcash->booking_code])->pluck('anchor.id')->all())->toBe([$awaitingGcash->id])
        ->and(jcJobs(['status' => 'paid'])->pluck('anchor.id'))->toContain($paidCash->id)->not->toContain($awaitingGcash->id)
        ->and(jcJobs(['status' => 'outstanding'])->pluck('anchor.id')->all())->toBe([$awaitingGcash->id])
        ->and(jcJobs(['method' => 'gcash'])->pluck('anchor.id')->all())->toBe([$awaitingGcash->id]);
});

// ---------- Page and authorization ----------

it('renders the Owner page with exact totals and filters', function () {
    $owner = finOwner();
    $unitA = finUnit();
    $unitB = finUnit();
    jcGroup([[$unitA, '10000.00'], [$unitB, '5000.00']]);
    jcBooking($unitA, '2500.00', 'submitted');

    $response = $this->actingAs($owner)->get(route('superadmin.finance.collections.index'));
    $response->assertOk()
        ->assertSee('Jobs &amp; Collections', false)
        ->assertSee('data-jc-total="bookings">2<', false)
        ->assertSee('data-jc-total="vehicles">3<', false)
        ->assertSee('data-jc-total="agreed">₱17,500.00<', false)
        ->assertSee('data-jc-total="collected">₱15,000.00<', false)
        ->assertSee('data-jc-total="outstanding">₱2,500.00<', false)
        ->assertSee('href="' . route('superadmin.finance.collections.index') . '"', false);

    $this->actingAs($owner)->get(route('superadmin.finance.collections.index', ['status' => 'awaiting_verification']))
        ->assertSee('data-jc-total="bookings">1<', false);
});

it('blocks every non-Owner role and guests', function () {
    $booking = jcBooking(finUnit(), '1000.00');
    foreach ([2, 3, 4, 5, 6] as $role) {
        $user = finUser($role);
        $this->actingAs($user)->get(route('superadmin.finance.collections.index'))->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.collections.split', $booking->id), ['amounts' => [1 => '1000']])->assertForbidden();
    }
    auth()->logout();
    $this->get(route('superadmin.finance.collections.index'))->assertRedirect(route('login'));
    expect(RevenueAllocation::count())->toBe(0);
});

it('reads payments without writing any customer payment record', function () {
    $booking = jcBooking(finUnit(), '1000.00', 'submitted');
    jcPayment($booking, '400.00');
    $before = [PaymentTransaction::count(), \App\Models\Invoice::count(), \App\Models\Receipt::count(), Booking::count()];

    $this->actingAs(finOwner())->get(route('superadmin.finance.collections.index'))->assertOk();

    expect([PaymentTransaction::count(), \App\Models\Invoice::count(), \App\Models\Receipt::count(), Booking::count()])->toBe($before)
        ->and($booking->fresh()->status)->toBe('waiting_verification');
});

// ---------- Consistency with the existing Revenue page ----------

it('agrees with the existing Revenue page on Dispatcher-confirmed revenue', function () {
    $unit = finUnit();
    jcBooking($unit, '1800.00');
    jcGroup([[$unit, '5000.00'], [$unit, '5000.00']], 'confirmed', fee: '300.00');
    jcBooking($unit, '999.00', 'submitted');   // not confirmed: excluded by both

    // Fixtures are dated three hours ago, which is yesterday just after midnight: include yesterday.
    $start = jcNow()->subDay()->startOfDay();
    $end = jcNow()->endOfDay();
    $ours = app(JobCollectionService::class)->totals(app(JobCollectionService::class)->jobs($start, $end, ['basis' => 'payment']));
    $revenuePage = app(\App\Services\ReportMetricsService::class)->revenueSummary($start, $end);

    expect($ours['collected']->toString())->toBe('12100.00')
        ->and(number_format($revenuePage['totalRevenue'], 2, '.', ''))->toBe($ours['collected']->toString())
        // Bookings are counted per job here (the group trip is one booking); the Revenue page counts vehicle rows.
        ->and($ours['bookings'])->toBe(2)
        ->and($revenuePage['completedCount'])->toBe(3);
});
