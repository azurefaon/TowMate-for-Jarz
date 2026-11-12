<?php

use App\Http\Controllers\SuperAdmin\Finance\CommissionController;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\PaymentTransaction;
use App\Models\Quotation;
use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\DriverRecordService;
use App\Services\Finance\JobCollectionService;
use App\Services\Finance\JobCrewService;
use App\Support\Money;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// Commission (Draft) and Driver Records apply the same eligibility rule to the same calculator lines: only completed,
// fully paid jobs count; partial / unpaid / over-collected jobs stay visible with a label and a non-payable estimate.

function cdcRecord($owner, Booking $booking, $unit, $person, string $role = 'driver'): void
{
    app(JobCrewService::class)->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$person->id}", 'commission_role' => $role]);
}

/** A finished tow (Team Leader submitted) with an optional verified online payment. */
function cdcSubmitted($unit, string $total, ?string $onlinePaid = null): Booking
{
    $booking = finServicedBooking($unit, $total, ['status' => 'waiting_verification', 'completed_at' => null, 'payment_method' => 'gcash', 'cash_received' => null]);
    if ($onlinePaid !== null) {
        PaymentTransaction::create([
            'provider' => 'paymongo', 'reference' => 'TM-' . Str::upper(Str::random(10)), 'payable_type' => 'booking', 'payable_id' => $booking->id,
            'booking_id' => $booking->id, 'customer_id' => $booking->customer_id, 'method' => 'gcash',
            'amount_centavos' => (int) bcmul($onlinePaid, '100', 0), 'status' => 'paid', 'livemode' => true, 'paid_at' => finLastWeek()->addDays(2)->setTime(12, 0),
        ]);
    }

    return $booking->fresh();
}

/** Paid single jobs, a partial job with an Owner split (so the calculator gives it shares), an unpaid job, a paid group trip over two units and a paid job split between two units. */
function cdcScenario(): array
{
    $owner = finOwner();
    [$a, $b] = [finUnit(), finUnit()];
    $ana = finPersonnel('driver');   // unit A driver
    $ben = finPersonnel('driver');   // unit B driver
    $cris = finPersonnel('crew');    // works on both units

    $paid = finServicedBooking($a, '1000.00');
    $partial = cdcSubmitted($a, '2000.00', '800.00');
    $unpaid = cdcSubmitted($a, '3000.00');
    $split = finServicedBooking($a, '5000.00');

    $customer = Customer::create(['full_name' => 'Group Client', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'cdc-' . Str::random(6) . '@example.com']);
    $code = 'GRP-' . Str::upper(Str::random(5));
    $member = fn ($u, $total) => finServicedBooking($u, $total, ['customer_id' => $customer->id, 'group_code' => $code, 'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff', 'cash_received' => '15000.00']);
    [$m1, $m2, $m3] = [$member($a, '6000.00'), $member($a, '5000.00'), $member($b, '4000.00')];
    $q = Quotation::create(['quotation_number' => 'QT-' . Str::random(6), 'source_booking_id' => $m1->id, 'customer_id' => $customer->id, 'truck_type_id' => $m1->truck_type_id,
        'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff', 'distance_km' => 30, 'estimated_price' => '15000', 'additional_fee' => '0', 'discount' => '0',
        'service_type' => 'book_now', 'status' => 'accepted', 'extra_vehicles' => [['booking_id' => $m2->id, 'truck_type_id' => $m2->truck_type_id, 'final_total' => 5000.0], ['booking_id' => $m3->id, 'truck_type_id' => $m3->truck_type_id, 'final_total' => 4000.0]]]);
    foreach ([$m1, $m2, $m3] as $m) {
        $m->update(['quotation_id' => $q->id]);
    }

    $jobs = app(JobCollectionService::class);
    $jobs->recordSplit($owner, $partial, [$a->id => '800.00']);
    $jobs->recordSplit($owner, $split, [$a->id => '3000.00', $b->id => '2000.00']);

    foreach ([$paid, $partial, $unpaid] as $job) {
        cdcRecord($owner, $job, $a, $ana);
        cdcRecord($owner, $job, $a, $cris, 'crew_mate');
    }
    cdcRecord($owner, $split, $a, $ana);
    cdcRecord($owner, $split, $b, $ben);
    cdcRecord($owner, $split, $b, $cris, 'crew_mate');
    cdcRecord($owner, $m1->fresh(), $a, $ana);
    cdcRecord($owner, $m1->fresh(), $b, $ben);
    cdcRecord($owner, $m1->fresh(), $b, $cris, 'crew_mate');

    return compact('owner', 'a', 'b', 'ana', 'ben', 'cris', 'paid', 'partial', 'unpaid', 'split', 'm1');
}

it('gives the same eligible draft per person and per unit on Commission (Draft) and Driver Records', function () {
    $s = cdcScenario();
    $week = finLastWeek();

    foreach ([null, $s['a']->id, $s['b']->id] as $unitId) {
        $lines = app(CommissionCalculator::class)->draft($week, $unitId)['lines'];
        $page = CommissionController::eligibility($lines);
        $records = app(DriverRecordService::class)->build($week, $week->addDays(5), $unitId, 'all');

        $pagePeople = collect($page['people'])->map(fn ($p) => $p['eligible']->toString())->sortKeys()->all();
        $recordPeople = collect($records['people'])->map(fn ($p) => $p['draft']->toString())->sortKeys()->all();
        expect($recordPeople)->toBe($pagePeople, 'per person, unit ' . ($unitId ?? 'all'))
            ->and($records['totals']['draft']->toString())->toBe($page['eligible']->toString())
            ->and(collect($records['people'])->map(fn ($p) => $p['jobs'] - $p['pending_collection'] - $p['awaiting_payment'] - $p['under_review'])->sortKeys()->all())
            ->toBe(collect($page['people'])->map(fn ($p) => $p['eligible_jobs'])->sortKeys()->all());
        if ($unitId) {
            expect($page['units'][$unitId]['eligible']->toString())->toBe($records['totals']['draft']->toString());
        }
    }

    // The partial job really has calculator shares; both pages keep them out of the eligible totals.
    $lines = collect(app(CommissionCalculator::class)->draft($week, $s['a']->id)['lines']);
    $partialLine = $lines->firstWhere('booking_id', $s['partial']->id);
    $page = CommissionController::eligibility($lines->all());
    $eligibleA = Money::sum($lines->filter(fn ($l) => in_array($l['booking_id'], [$s['paid']->id, $s['split']->id, $s['m1']->id], true))->pluck('crew_allocated'));
    expect($partialLine['crew_allocated']->toString())->toBe('160.00')   // Driver 12% + Crew Mate 8% of 800.00 (no TL or Rider recorded: unallocated)
        ->and($page['units'][$s['a']->id]['eligible']->toString())->toBe($eligibleA->toString())
        ->and($page['units'][$s['a']->id]['estimate']->toString())->toBe(Money::sum($lines->whereIn('booking_id', [$s['partial']->id, $s['unpaid']->id])->pluck('crew_allocated'))->toString())
        ->and($page['units'][$s['a']->id]['eligible_jobs'])->toBe(3);
});

it('labels each job on Commission (Draft) and keeps non-payable estimates out of the weekly totals', function () {
    $s = cdcScenario();
    $lines = collect(app(CommissionCalculator::class)->draft(finLastWeek(), $s['a']->id)['lines']);
    $page = CommissionController::eligibility($lines->all());
    $line = fn ($booking) => 'data-cm-line="' . $booking->booking_code . '-' . $s['a']->id . '"';

    $response = $this->actingAs($s['owner'])->get(route('superadmin.finance.commission.index', ['week' => finLastWeek()->toDateString(), 'unit_id' => $s['a']->id]))->assertOk();
    $html = $response->getContent();
    expect($html)->toContain($line($s['paid']) . ' data-cm-line-state="eligible"')
        ->toContain($line($s['partial']) . ' data-cm-line-state="pending_collection"')
        // Nothing collected yet: no calculator line, so it is listed below the units, still labelled.
        ->and($lines->contains('booking_id', $s['unpaid']->id))->toBeFalse()
        ->and($html)->toContain('data-cm-blocked-job="' . $s['unpaid']->booking_code . '" data-cm-line-state="awaiting_payment"');
    $response->assertSee('Pending Collection')
        ->assertSee('data-cm-unit-crew>' . $page['units'][$s['a']->id]['eligible']->format() . '<', false)
        ->assertSee('data-cm-total="crew">' . $page['eligible']->format() . '<', false)
        ->assertSee('data-cm-unit-estimate="' . $s['a']->id . '"', false)
        ->assertSee('not payable')
        ->assertSee('data-cm-person="personnel:' . $s['ana']->id . '"', false);

    // Driver Records for the same unit and week shows the same eligible total and the same labels.
    $q = ['from' => finLastWeek()->toDateString(), 'to' => finLastWeek()->addDays(5)->toDateString(), 'unit_id' => $s['a']->id, 'role' => 'all'];
    $this->actingAs($s['owner'])->get(route('superadmin.finance.driver-records.index', $q))->assertOk()
        ->assertSee('data-dr="draft">' . $page['eligible']->format() . '<', false);
    $this->actingAs($s['owner'])->get(route('superadmin.finance.driver-records.index', $q + ['person' => "personnel:{$s['ana']->id}"]))->assertOk()
        ->assertSee('data-dr-commission-state="pending_collection"', false)
        ->assertSee('data-dr-commission-state="awaiting_payment"', false);
});

it('keeps approval blocked: statements still refuse jobs that are not fully paid', function () {
    $s = cdcScenario();
    $calc = app(CommissionCalculator::class)->draft(finLastWeek(), $s['a']->id);
    expect($calc['can_approve'])->toBeFalse()
        ->and(collect($calc['blockers'])->pluck('code'))->toContain('payment_not_verified')->toContain('policy_for_client_review');
});

it('keeps Commission (Draft) and Driver Records Owner-only', function () {
    foreach ([2, 3, 4, 5, 6] as $role) {
        $user = finUser($role);
        $this->actingAs($user)->get(route('superadmin.finance.commission.index'))->assertForbidden();
        $this->actingAs($user)->get(route('superadmin.finance.driver-records.index'))->assertForbidden();
    }
});
