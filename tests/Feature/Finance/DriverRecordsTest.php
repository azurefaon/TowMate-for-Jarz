<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Finance\JobPersonnel;
use App\Models\Quotation;
use App\Services\Finance\DriverRecordService;
use App\Services\Finance\JobCrewService;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// Driver Records: completed jobs and vehicles towed per week, and each person's history (from Owner crew records).

function drvBuild(?int $unitId = null, string $role = 'driver'): array
{
    return app(DriverRecordService::class)->build(finLastWeek()->subWeeks(3), finLastWeek()->addDays(5), $unitId, $role);
}

function drvRecord($owner, Booking $booking, $unit, $person, string $role = 'driver'): JobPersonnel
{
    return app(JobCrewService::class)->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$person->id}", 'commission_role' => $role]);
}

it('counts each driver\'s completed jobs and vehicles towed per week', function () {
    $owner = finOwner();
    $unit = finUnit();
    $ana = finPersonnel('driver');
    $ben = finPersonnel('driver');
    $lastWeek = finLastWeek()->toDateString();
    $earlier = finLastWeek()->subWeeks(2);
    $at = $earlier->addDays(1)->setTime(9, 0);

    drvRecord($owner, finServicedBooking($unit, '1000.00'), $unit, $ana);
    drvRecord($owner, finServicedBooking($unit, '1200.00'), $unit, $ana);
    drvRecord($owner, finServicedBooking($unit, '900.00', ['completion_requested_at' => $at, 'payment_submitted_at' => $at, 'completed_at' => $at->addHour()]), $unit, $ana);
    drvRecord($owner, finServicedBooking($unit, '800.00'), $unit, $ben);
    finServicedBooking($unit, '700.00');                                                    // completed, nobody recorded yet
    finServicedBooking($unit, '600.00', ['status' => 'in_progress', 'completed_at' => null]); // not completed: never counted

    $d = drvBuild();
    $people = collect($d['people'])->keyBy('name');
    expect($people[$ana->full_name]['jobs'])->toBe(3)
        ->and($people[$ana->full_name]['weeks'][$lastWeek])->toBe(['jobs' => 2, 'vehicles' => 2])
        ->and($people[$ana->full_name]['weeks'][$earlier->toDateString()])->toBe(['jobs' => 1, 'vehicles' => 1])
        ->and($people[$ana->full_name]['weeks_active'])->toBe(2)
        ->and($people[$ben->full_name]['jobs'])->toBe(1)
        ->and(array_keys($d['people'])[0])->toBe("personnel:{$ana->id}")              // most jobs first
        ->and(count($d['weeks']))->toBe(4)
        ->and($d['totals'])->toMatchArray(['people' => 2, 'completed_jobs' => 5, 'jobs_with_record' => 4, 'jobs_without_record' => 1])
        ->and($d['unrecorded'])->toHaveCount(1);
});

it('counts a group trip as one job and only the vehicles towed by the driver\'s unit', function () {
    $owner = finOwner();
    [$a, $b] = [finUnit(), finUnit()];
    $customer = Customer::create(['full_name' => 'Group Client', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'dr-' . Str::random(6) . '@example.com']);
    $code = 'GRP-' . Str::upper(Str::random(5));
    $member = fn ($u, $total) => finServicedBooking($u, $total, ['customer_id' => $customer->id, 'group_code' => $code, 'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff', 'cash_received' => '15000.00']);
    [$m1, $m2, $m3] = [$member($a, '6000.00'), $member($a, '5000.00'), $member($b, '4000.00')];
    $q = Quotation::create(['quotation_number' => 'QT-' . Str::random(6), 'source_booking_id' => $m1->id, 'customer_id' => $customer->id, 'truck_type_id' => $m1->truck_type_id,
        'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff', 'distance_km' => 30, 'estimated_price' => '15000', 'additional_fee' => '0', 'discount' => '0',
        'service_type' => 'book_now', 'status' => 'accepted', 'extra_vehicles' => [['booking_id' => $m2->id, 'truck_type_id' => $m2->truck_type_id, 'final_total' => 5000.0], ['booking_id' => $m3->id, 'truck_type_id' => $m3->truck_type_id, 'final_total' => 4000.0]]]);
    foreach ([$m1, $m2, $m3] as $m) {
        $m->update(['quotation_id' => $q->id]);
    }
    $driverA = finPersonnel('driver');
    $driverB = finPersonnel('driver');
    drvRecord($owner, $m1->fresh(), $a, $driverA);
    drvRecord($owner, $m1->fresh(), $b, $driverB);

    $people = collect(drvBuild()['people'])->keyBy('name');
    expect($people[$driverA->full_name]['jobs'])->toBe(1)->and($people[$driverA->full_name]['vehicles'])->toBe(2)
        ->and($people[$driverB->full_name]['jobs'])->toBe(1)->and($people[$driverB->full_name]['vehicles'])->toBe(1)
        ->and(drvBuild()['totals']['completed_jobs'])->toBe(1)
        ->and(collect(drvBuild($b->id)['people'])->pluck('name')->all())->toBe([$driverB->full_name]);
});

it('follows the crew record: a corrected job moves to the right driver, and other roles are filtered separately', function () {
    $owner = finOwner();
    $unit = finUnit();
    $booking = finServicedBooking($unit, '1000.00');
    $wrong = finPersonnel('driver');
    $right = finPersonnel('driver');
    $mate = finPersonnel('crew');
    $row = drvRecord($owner, $booking, $unit, $wrong);
    drvRecord($owner, $booking, $unit, $mate, 'crew_mate');
    $unit->forceFill(['driver_name' => 'Someone Else', 'driver_personnel_id' => finPersonnel('driver')->id])->save();   // current roster is never used

    expect(collect(drvBuild()['people'])->pluck('name')->all())->toBe([$wrong->full_name]);

    app(JobCrewService::class)->correct($owner, $row, ['unit_id' => $unit->id, 'participant' => "personnel:{$right->id}", 'commission_role' => 'driver'], 'Wrong driver recorded');
    expect(collect(drvBuild()['people'])->pluck('name')->all())->toBe([$right->full_name])
        ->and(collect(drvBuild(null, 'crew_mate')['people'])->pluck('name')->all())->toBe([$mate->full_name])
        ->and(collect(drvBuild(null, 'all')['people'])->pluck('name')->sort()->values()->all())->toBe(collect([$right->full_name, $mate->full_name])->sort()->values()->all())
        ->and(drvBuild(null, 'not-a-role')['role'])->toBe('driver');
});

it('shows the weekly record and a person\'s performance history on the Owner page', function () {
    $owner = finOwner();
    $unit = finUnit();
    $ana = finPersonnel('driver');
    $job = finServicedBooking($unit, '1000.00');
    drvRecord($owner, $job, $unit, $ana);
    $missing = finServicedBooking($unit, '700.00');
    $q = ['from' => finLastWeek()->toDateString(), 'to' => finLastWeek()->addDays(5)->toDateString()];

    $page = $this->actingAs($owner)->get(route('superadmin.finance.driver-records.index', $q))->assertOk();
    $page->assertSee('Driver Records')->assertSee('data-dr-person="' . $ana->full_name . '"', false)
        ->assertSee('data-dr="jobs">2<', false)->assertSee('data-dr="unrecorded">1<', false)
        ->assertSee('data-dr-unrecorded="' . $missing->booking_code . '"', false)
        ->assertSee('data-dr-total="jobs">1<', false)
        ->assertSee('href="' . route('superadmin.finance.driver-records.index') . '"', false)   // sidebar link
        ->assertDontSee('data-dr-history', false);

    $this->actingAs($owner)->get(route('superadmin.finance.driver-records.index', $q + ['person' => "personnel:{$ana->id}"]))->assertOk()
        ->assertSee('data-dr-history="' . $ana->full_name . '"', false)->assertSee('data-dr-job="' . $job->booking_code . '"', false)
        ->assertSee('1 completed job(s), 1 vehicle(s) towed');

    $this->actingAs($owner)->get(route('superadmin.finance.driver-records.index', ['from' => '2026-01-05', 'to' => '2026-01-10']))->assertOk()->assertSee('data-dr-empty', false);
    $this->actingAs($owner)->get(route('superadmin.finance.driver-records.index'))->assertOk();   // default: last eight weeks
});

it('lets only the Owner open Driver Records', function () {
    foreach ([2, 3, 4, 5, 6] as $role) {
        $this->actingAs(finUser($role))->get(route('superadmin.finance.driver-records.index'))->assertForbidden();
    }
    auth()->logout();
    $this->get(route('superadmin.finance.driver-records.index'))->assertRedirect();
});
