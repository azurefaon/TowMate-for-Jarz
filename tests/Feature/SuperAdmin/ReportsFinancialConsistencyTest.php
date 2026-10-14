<?php

/*
 * Owner Reports, Revenue and the exports all take their revenue from
 * ReportMetricsService: completed jobs, by completion date, with a group's
 * fee and discount allocated across its vehicles. Booking and job counts stay
 * by creation date. Dates below are explicit so the checks do not depend on today.
 */

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\DocumentGenerationService;
use App\Services\ReportMetricsService;
use Illuminate\Support\Facades\DB;

function rfcUser(int $roleId): User
{
    foreach ([1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader', 4 => 'Driver'] as $id => $name) {
        if (! Role::find($id)) {
            $role = new Role(['name' => $name]);
            $role->id = $id;
            $role->save();
        }
    }

    return User::factory()->create(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false]);
}

function rfcTruck(string $name): TruckType
{
    return TruckType::create(['name' => $name . ' ' . uniqid(), 'base_rate' => 1500, 'per_km_rate' => 60, 'status' => 'active']);
}

function rfcUnit(TruckType $truck, string $name): Unit
{
    return Unit::create(['name' => $name, 'plate_number' => 'RFC-' . uniqid(), 'truck_type_id' => $truck->id, 'status' => 'available']);
}

function rfcCustomer(): Customer
{
    return Customer::create(['full_name' => 'RFC Customer', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'rfc-' . uniqid() . '@example.test']);
}

/** A completed booking created and completed on explicit (Manila) timestamps. */
function rfcCompleted(Customer $customer, TruckType $truck, ?Unit $unit, float $total, string $createdAt, string $completedAt, array $attrs = []): Booking
{
    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'assigned_unit_id' => $unit?->id,
        'pickup_address' => 'Pasay', 'dropoff_address' => 'Makati', 'distance_km' => 9.5,
        'base_rate' => 1500, 'per_km_rate' => 60,
        'final_total' => $total, 'vat_amount' => 0, 'additional_fee' => 0, 'cash_received' => $total,
        'status' => 'completed', 'service_type' => 'book_now',
    ], $attrs));

    DB::table('bookings')->where('id', $booking->id)->update(['created_at' => $createdAt, 'completed_at' => $completedAt]);

    return $booking->fresh();
}

function rfcGroupQuotation(Customer $customer, array $bookings, float $fee, float $discount): Quotation
{
    $quotation = Quotation::create([
        'quotation_number' => 'QT-RFC-' . uniqid(),
        'source_booking_id' => $bookings[0]->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $bookings[0]->truck_type_id,
        'pickup_address' => 'Pasay', 'dropoff_address' => 'Makati', 'distance_km' => 9.5,
        'estimated_price' => collect($bookings)->sum(fn ($b) => (float) $b->final_total) + $fee - $discount,
        'additional_fee' => $fee, 'discount' => $discount,
        'service_type' => 'book_now', 'status' => 'accepted',
        'extra_vehicles' => collect($bookings)->slice(1)->map(fn ($b) => ['booking_id' => $b->id, 'truck_type_id' => $b->truck_type_id, 'final_total' => (float) $b->final_total])->values()->all(),
    ]);

    foreach ($bookings as $b) {
        $b->update(['quotation_id' => $quotation->id]);
    }

    return $quotation;
}

function rfcRange(string $from, string $to): array
{
    return ['from' => $from, 'to' => $to];
}

/** March 2030: a two-vehicle group (fee 500, discount 200) completed in March, plus an early-created single job. */
function rfcScenario(): array
{
    $customer = rfcCustomer();
    $light = rfcTruck('RFC Light');
    $heavy = rfcTruck('RFC Heavy');
    $unitA = rfcUnit($light, 'RFC Unit A');
    $unitB = rfcUnit($heavy, 'RFC Unit B');

    $a = rfcCompleted($customer, $light, $unitA, 2000, '2030-03-02 09:00:00', '2030-03-10 12:00:00', ['group_code' => 'RFC-G1']);
    $b = rfcCompleted($customer, $heavy, $unitB, 3000, '2030-03-02 09:00:00', '2030-03-10 12:00:00', ['group_code' => 'RFC-G1']);
    rfcGroupQuotation($customer, [$a, $b], 500, 200);

    // Created in February, completed in March.
    $early = rfcCompleted($customer, $light, $unitA, 1000, '2030-02-20 10:00:00', '2030-03-05 15:00:00');

    return compact('customer', 'light', 'heavy', 'unitA', 'unitB', 'a', 'b', 'early');
}

const RFC_MARCH_TOTAL = 6300.0; // 2000 + 3000 + (500 - 200) group adjustment + 1000

it('counts a booking created in another month as revenue in the month it was completed', function () {
    rfcScenario();
    $owner = rfcUser(1);

    $march = $this->actingAs($owner)->get(route('superadmin.reports.index', rfcRange('2030-03-01', '2030-03-31')))->assertOk();
    $feb = $this->actingAs($owner)->get(route('superadmin.reports.index', rfcRange('2030-02-01', '2030-02-28')))->assertOk();

    expect($march->viewData('totalRevenue'))->toBe(RFC_MARCH_TOTAL);
    expect($march->viewData('totalBookings'))->toBe(2); // created in March: the group's two vehicles
    expect($march->viewData('completedJobsCount'))->toBe(3);

    expect($feb->viewData('totalRevenue'))->toBe(0.0);
    expect($feb->viewData('totalBookings'))->toBe(1); // the early booking was created in February
});

it('applies the group fee and discount once, identically on Reports, Revenue, the service and the CSV', function () {
    rfcScenario();
    $owner = rfcUser(1);
    $query = rfcRange('2030-03-01', '2030-03-31');

    $service = app(ReportMetricsService::class)->revenueSummary(Illuminate\Support\Carbon::parse('2030-03-01')->startOfDay(), Illuminate\Support\Carbon::parse('2030-03-31')->endOfDay());
    $reports = $this->actingAs($owner)->get(route('superadmin.reports.index', $query))->viewData('financial');
    $revenue = $this->actingAs($owner)->get(route('superadmin.revenue.index', $query))->viewData('financial');

    expect($service['totalRevenue'])->toBe(RFC_MARCH_TOTAL);
    expect($reports['totalRevenue'])->toBe(RFC_MARCH_TOTAL);
    expect($revenue['totalRevenue'])->toBe(RFC_MARCH_TOTAL);
    expect($reports['vatCollected'])->toBe($revenue['vatCollected'])->and($reports['additionalFees'])->toBe($revenue['additionalFees'])->and($reports['cashReceived'])->toBe($revenue['cashReceived']);

    $csv = $this->actingAs($owner)->get(route('superadmin.reports.export', $query))->assertOk()->streamedContent();
    expect($csv)->toContain('"Completed revenue (by completion date)","6,300.00"')->toContain('"Completed jobs (by completion date)",3');
});

it('makes the revenue trend add up to the revenue total, with and without filters', function (array $extra) {
    $s = rfcScenario();
    $owner = rfcUser(1);
    $query = array_merge(rfcRange('2030-03-01', '2030-03-31'), array_map(fn ($v) => $v === ':heavy' ? $s['heavy']->id : $v, $extra));

    $page = $this->actingAs($owner)->get(route('superadmin.revenue.index', $query))->assertOk();
    $trendSum = round(collect($page->viewData('revenueTrend'))->sum('revenue'), 2);

    expect($trendSum)->toBe(round($page->viewData('financial')['totalRevenue'], 2));
    expect(count($page->viewData('revenueTrend')))->toBe(31);
})->with([
    'no filters' => [[]],
    'truck type' => [['truck_type_id' => ':heavy']],
    'min amount' => [['min_amount' => 2500]],
    'max amount' => [['max_amount' => 2500]],
]);

it('keeps the amount filters against each booking\'s final_total, with the group allocation unchanged', function () {
    rfcScenario();
    $owner = rfcUser(1);

    // min 2500 keeps only the 3000 vehicle: 3000 + 3000/5000 * 300 = 3180.
    $page = $this->actingAs($owner)->get(route('superadmin.revenue.index', array_merge(rfcRange('2030-03-01', '2030-03-31'), ['min_amount' => 2500])))->assertOk();
    $reports = $this->actingAs($owner)->get(route('superadmin.reports.index', array_merge(rfcRange('2030-03-01', '2030-03-31'), ['min_amount' => 2500])))->assertOk();

    expect($page->viewData('financial')['totalRevenue'])->toBe(3180.0);
    expect($reports->viewData('totalRevenue'))->toBe(3180.0);
});

it('shows truck-type and unit revenue that add up to the same total, including jobs created in an earlier month', function () {
    $s = rfcScenario();
    $owner = rfcUser(1);
    $reports = $this->actingAs($owner)->get(route('superadmin.reports.index', rfcRange('2030-03-01', '2030-03-31')))->assertOk();
    $revenuePage = $this->actingAs($owner)->get(route('superadmin.revenue.index', rfcRange('2030-03-01', '2030-03-31')))->assertOk();

    $byTruck = $reports->viewData('truckTypePerformance');
    $byUnit = $reports->viewData('fleetPerformance');

    expect(round($byTruck->sum('revenue'), 2))->toBe(RFC_MARCH_TOTAL);
    expect(round($byUnit->sum('revenue'), 2))->toBe(RFC_MARCH_TOTAL);
    expect(round($revenuePage->viewData('revenueByTruckType')->sum('revenue'), 2))->toBe(RFC_MARCH_TOTAL);
    expect(round($reports->viewData('vehicleReport')->sum('revenue'), 2))->toBe(RFC_MARCH_TOTAL);

    // Light: created in March (1 job, the group's vehicle A) plus the February-created job's revenue.
    $light = $byTruck->firstWhere('truck_type_name', $s['light']->name);
    expect($light['total_jobs'])->toBe(1)->and($light['revenue'])->toBe(2000.0 + 0.4 * 300 + 1000.0);
});

it('uses Manila day boundaries for both the total and the trend', function () {
    $customer = rfcCustomer();
    $truck = rfcTruck('RFC Boundary');
    $owner = rfcUser(1);

    rfcCompleted($customer, $truck, null, 1000, '2030-03-30 08:00:00', '2030-03-31 23:59:30'); // last minute of March
    rfcCompleted($customer, $truck, null, 700, '2030-03-30 08:00:00', '2030-04-01 00:00:30'); // first minute of April

    $march = $this->actingAs($owner)->get(route('superadmin.revenue.index', rfcRange('2030-03-01', '2030-03-31')))->assertOk();
    $april = $this->actingAs($owner)->get(route('superadmin.revenue.index', rfcRange('2030-04-01', '2030-04-30')))->assertOk();

    expect($march->viewData('financial')['totalRevenue'])->toBe(1000.0);
    expect($april->viewData('financial')['totalRevenue'])->toBe(700.0);

    $marchTrend = collect($march->viewData('revenueTrend'));
    expect($marchTrend->firstWhere('label', 'Mar 31')['revenue'])->toBe(1000.0)->and($marchTrend->sum('revenue'))->toBe(1000.0);
    $aprilTrend = collect($april->viewData('revenueTrend'));
    expect($aprilTrend->firstWhere('label', 'Apr 1')['revenue'])->toBe(700.0)->and($aprilTrend->sum('revenue'))->toBe(700.0);
});

it('gives the PDF export the same figures as the page', function () {
    rfcScenario();
    $owner = rfcUser(1);
    $captured = null;

    $documents = Mockery::mock(DocumentGenerationService::class);
    $documents->shouldReceive('renderReportPdf')->once()->andReturnUsing(function ($template, $data) use (&$captured) {
        $captured = $data['summary'];

        return '<html></html>';
    });
    app()->instance(DocumentGenerationService::class, $documents);

    $this->actingAs($owner)->get(route('superadmin.reports.export-pdf', rfcRange('2030-03-01', '2030-03-31')))->assertOk();

    expect($captured['totalRevenue'])->toBe(RFC_MARCH_TOTAL)
        ->and($captured['financial']['totalRevenue'])->toBe(RFC_MARCH_TOTAL)
        ->and($captured['completedJobsCount'])->toBe(3);
});

it('labels the date basis on the pages', function () {
    rfcScenario();
    $owner = rfcUser(1);

    $this->actingAs($owner)->get(route('superadmin.reports.index', rfcRange('2030-03-01', '2030-03-31')))
        ->assertOk()->assertSee('Completed revenue, by completion date')->assertSee('created');
    $this->actingAs($owner)->get(route('superadmin.revenue.index', rfcRange('2030-03-01', '2030-03-31')))
        ->assertOk()->assertSee('Completed revenue, by completion date');
});

it('keeps Reports, Revenue and the exports Owner-only', function () {
    foreach (['superadmin.reports.index', 'superadmin.revenue.index', 'superadmin.reports.export', 'superadmin.reports.export-pdf'] as $name) {
        $this->actingAs(rfcUser(2))->get(route($name))->assertForbidden();
        $this->actingAs(rfcUser(4))->get(route($name))->assertForbidden();
    }
});
