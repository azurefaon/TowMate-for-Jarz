<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Unit;
use App\Services\Finance\JobCollectionService;
use App\Services\ReportMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

require_once __DIR__ . '/../Finance/finance_helpers.php';

// Phase 8 regression: a grouped trip stores the group's cash on every vehicle row. The Revenue page's
// "Cash Received" must count that cash once per collection, not once per vehicle.

function rgGroup(Unit $a, Unit $b, string $cash = '15000.00'): array
{
    $customer = Customer::create(['full_name' => 'Group Client', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'rg-' . Str::random(6) . '@example.com']);
    $code = 'GRP-' . Str::upper(Str::random(5));
    $at = CarbonImmutable::now(config('finance.timezone'))->subDays(2)->setTime(10, 0);
    $member = fn (Unit $u, string $total) => Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $u->truck_type_id, 'assigned_unit_id' => $u->id, 'group_code' => $code,
        'pickup_address' => 'Same pickup', 'dropoff_address' => 'Quezon City', 'distance_km' => 30, 'base_rate' => 1500, 'per_km_rate' => 60,
        'computed_total' => $total, 'final_total' => $total, 'payment_method' => 'cash', 'cash_received' => $cash,
        'completion_requested_at' => $at, 'payment_submitted_at' => $at, 'status' => 'completed', 'completed_at' => $at->addHour(),
    ]);
    $m1 = $member($a, '10000.00');
    $m2 = $member($b, '5000.00');
    $q = Quotation::create(['quotation_number' => 'QT-' . Str::random(6), 'source_booking_id' => $m1->id, 'customer_id' => $customer->id, 'truck_type_id' => $m1->truck_type_id,
        'pickup_address' => 'Same pickup', 'dropoff_address' => 'Quezon City', 'distance_km' => 30, 'estimated_price' => '15000', 'additional_fee' => '0', 'discount' => '0',
        'service_type' => 'book_now', 'status' => 'accepted', 'extra_vehicles' => [['booking_id' => $m2->id, 'truck_type_id' => $m2->truck_type_id, 'final_total' => 5000.0]]]);
    $m1->update(['quotation_id' => $q->id]);
    $m2->update(['quotation_id' => $q->id]);

    return [$m1->fresh(), $m2->fresh()];
}

function rgSummary(array $filters = []): array
{
    return app(ReportMetricsService::class)->revenueSummary(now()->subDays(7)->startOfDay(), now()->endOfDay(), $filters);
}

it('counts grouped-trip cash once per collection, not once per vehicle', function () {
    [$a, $b] = [finUnit(), finUnit()];
    rgGroup($a, $b, '15000.00');

    $summary = rgSummary();
    expect($summary['completedCount'])->toBe(2)               // vehicle rows still counted as completed jobs (unchanged)
        ->and($summary['totalRevenue'])->toBe(15000.0)          // allocated revenue unchanged
        ->and($summary['cashReceived'])->toBe(15000.0);          // was 30,000.00 before the fix
});

it('keeps single bookings and separate groups additive', function () {
    [$a, $b] = [finUnit(), finUnit()];
    rgGroup($a, $b, '15000.00');
    rgGroup($a, $b, '15000.00');
    finServicedBooking($a, '2500.00', ['completed_at' => now()->subDays(2)]);

    expect(rgSummary()['cashReceived'])->toBe(32500.0);
});

it('counts the group cash once when a unit filter shows only one vehicle of the group', function () {
    [$a, $b] = [finUnit(), finUnit()];
    rgGroup($a, $b, '15000.00');

    expect(rgSummary(['unit_id' => $a->id])['cashReceived'])->toBe(15000.0);
});

it('reconciles the Revenue page cash with Jobs & Collections for a grouped trip', function () {
    [$a, $b] = [finUnit(), finUnit()];
    rgGroup($a, $b, '15000.00');

    $jobs = app(JobCollectionService::class)->jobs(CarbonImmutable::now(config('finance.timezone'))->subDays(7), CarbonImmutable::now(config('finance.timezone')), ['basis' => 'payment']);
    $finance = app(JobCollectionService::class)->totals($jobs);

    expect($jobs)->toHaveCount(1)
        ->and($finance['collected']->toString())->toBe('15000.00')
        ->and(number_format(rgSummary()['cashReceived'], 2, '.', ''))->toBe($finance['collected']->toString());

    $this->actingAs(finOwner())->get(route('superadmin.revenue.index'))->assertOk()->assertSee('₱15,000.00')->assertDontSee('₱30,000.00');
});
