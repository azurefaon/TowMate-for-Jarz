<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\ReportMetricsService;

function rmsCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'RMS Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'rms-' . uniqid() . '@example.com',
    ]);
}

function rmsTruckType(string $name = 'RMS Truck'): TruckType
{
    return TruckType::create([
        'name' => $name . ' ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function rmsBooking(array $overrides = []): Booking
{
    $customer = $overrides['customer_id'] ?? null ? null : rmsCustomer();
    $truckType = $overrides['truck_type_id'] ?? null ? null : rmsTruckType();

    $booking = Booking::create(array_merge([
        'customer_id' => $customer?->id,
        'truck_type_id' => $truckType?->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 1800,
        'status' => 'completed',
    ], $overrides));

    if (array_key_exists('created_at', $overrides)) {
        $booking->forceFill(['created_at' => $overrides['created_at']])->save();
    }

    if (array_key_exists('completed_at', $overrides)) {
        $booking->forceFill(['completed_at' => $overrides['completed_at']])->save();
    }

    return $booking->fresh();
}

function rmsService(): ReportMetricsService
{
    return app(ReportMetricsService::class);
}

it('computes cancellation rate as cancelled divided by valid bookings', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    foreach (range(1, 6) as $i) {
        rmsBooking(['status' => 'completed', 'created_at' => now()]);
    }
    rmsBooking(['status' => 'in_progress', 'created_at' => now()]);
    rmsBooking(['status' => 'assigned', 'created_at' => now()]);

    rmsBooking(['status' => 'cancelled', 'created_at' => now()]);
    rmsBooking(['status' => 'cancelled', 'created_at' => now()]);

    $result = rmsService()->cancellationRate($start, $end);

    expect($result['valid'])->toBe(10);
    expect($result['cancelled'])->toBe(2);
    expect($result['rate'])->toBe(20.0);
});

it('excludes requested, not_responding, and rejected bookings from valid bookings', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    rmsBooking(['status' => 'completed', 'created_at' => now()]);
    rmsBooking(['status' => 'cancelled', 'created_at' => now()]);
    rmsBooking(['status' => 'requested', 'created_at' => now()]);
    rmsBooking(['status' => 'not_responding', 'created_at' => now()]);
    rmsBooking(['status' => 'rejected', 'created_at' => now()]);

    $result = rmsService()->cancellationRate($start, $end);

    expect($result['valid'])->toBe(2);
    expect($result['cancelled'])->toBe(1);
    expect($result['rate'])->toBe(50.0);
});

it('returns zero cancellation rate when there are no valid bookings', function () {
    $result = rmsService()->cancellationRate(now()->startOfMonth(), now()->endOfMonth());

    expect($result['valid'])->toBe(0);
    expect($result['rate'])->toBe(0.0);
});

it('computes average revenue per job from completed bookings only', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    rmsBooking(['status' => 'completed', 'final_total' => 2000, 'completed_at' => now()]);
    rmsBooking(['status' => 'completed', 'final_total' => 3000, 'completed_at' => now()]);
    rmsBooking(['status' => 'in_progress', 'final_total' => 9999, 'created_at' => now()]);

    $result = rmsService()->averageRevenuePerJob($start, $end);

    expect($result['completed_jobs'])->toBe(2);
    expect($result['revenue'])->toBe(5000.0);
    expect($result['average'])->toBe(2500.0);
});

it('windows average revenue per job by completed_at, not created_at', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    rmsBooking(['status' => 'completed', 'final_total' => 1000, 'created_at' => now()->subMonths(3), 'completed_at' => now()]);
    rmsBooking(['status' => 'completed', 'final_total' => 5000, 'created_at' => now(), 'completed_at' => now()->subMonths(3)]);

    $result = rmsService()->averageRevenuePerJob($start, $end);

    expect($result['completed_jobs'])->toBe(1);
    expect($result['revenue'])->toBe(1000.0);
});

it('groups revenue by the booking own snapshotted truck type', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $light = rmsTruckType('Light');
    $heavy = rmsTruckType('Heavy');

    rmsBooking(['status' => 'completed', 'truck_type_id' => $light->id, 'final_total' => 1000, 'completed_at' => now()]);
    rmsBooking(['status' => 'completed', 'truck_type_id' => $light->id, 'final_total' => 1500, 'completed_at' => now()]);
    rmsBooking(['status' => 'completed', 'truck_type_id' => $heavy->id, 'final_total' => 4000, 'completed_at' => now()]);

    $result = rmsService()->revenueByTruckType($start, $end)->keyBy('truck_type_name');

    expect($result[$light->name]['revenue'])->toBe(2500.0);
    expect($result[$light->name]['jobs'])->toBe(2);
    expect($result[$heavy->name]['revenue'])->toBe(4000.0);
    expect($result[$heavy->name]['jobs'])->toBe(1);
});

it('counts completed jobs by truck type', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $medium = rmsTruckType('Medium');

    rmsBooking(['status' => 'completed', 'truck_type_id' => $medium->id, 'completed_at' => now()]);
    rmsBooking(['status' => 'completed', 'truck_type_id' => $medium->id, 'completed_at' => now()]);
    rmsBooking(['status' => 'cancelled', 'truck_type_id' => $medium->id, 'created_at' => now()]);

    $result = rmsService()->completedJobsByTruckType($start, $end)->keyBy('truck_type_name');

    expect($result[$medium->name]['completed_jobs'])->toBe(2);
});

it('computes current fleet utilization as units on job divided by non-archived units', function () {
    $truckType = rmsTruckType();

    Unit::create(['name' => 'RMS Unit 1', 'plate_number' => fake()->unique()->bothify('???-####'), 'truck_type_id' => $truckType->id, 'status' => 'on_job']);
    Unit::create(['name' => 'RMS Unit 2', 'plate_number' => fake()->unique()->bothify('???-####'), 'truck_type_id' => $truckType->id, 'status' => 'available']);
    Unit::create(['name' => 'RMS Unit 3', 'plate_number' => fake()->unique()->bothify('???-####'), 'truck_type_id' => $truckType->id, 'status' => 'available']);
    Unit::create(['name' => 'RMS Unit 4 Archived', 'plate_number' => fake()->unique()->bothify('???-####'), 'truck_type_id' => $truckType->id, 'status' => 'on_job', 'archived_at' => now()]);

    $result = rmsService()->currentFleetUtilization();

    expect($result['total_units'])->toBe(3);
    expect($result['units_in_use'])->toBe(1);
    expect(round($result['rate'], 2))->toBe(33.33);
});

it('computes unit performance with completed jobs, revenue, and average revenue per job', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $unit = Unit::create(['name' => 'RMS Perf Unit', 'plate_number' => fake()->unique()->bothify('???-####'), 'truck_type_id' => rmsTruckType()->id, 'status' => 'available']);

    rmsBooking(['status' => 'completed', 'assigned_unit_id' => $unit->id, 'final_total' => 1000, 'completed_at' => now()]);
    rmsBooking(['status' => 'completed', 'assigned_unit_id' => $unit->id, 'final_total' => 3000, 'completed_at' => now()]);

    $result = rmsService()->unitPerformance($start, $end)->keyBy('unit_name');

    expect($result[$unit->name]['completed_jobs'])->toBe(2);
    expect($result[$unit->name]['revenue'])->toBe(4000.0);
    expect($result[$unit->name]['average_revenue_per_job'])->toBe(2000.0);
});

it('excludes a status-overridden completed booking with no completed_at from average revenue per job', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    rmsBooking(['status' => 'completed', 'final_total' => 2000, 'completed_at' => now()]);
    rmsBooking(['status' => 'completed', 'final_total' => 99999, 'created_at' => now()]);

    $result = rmsService()->averageRevenuePerJob($start, $end);

    expect($result['completed_jobs'])->toBe(1);
    expect($result['revenue'])->toBe(2000.0);
});

it('excludes a status-overridden completed booking with no completed_at from revenue by truck type', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $truckType = rmsTruckType('Guarded');

    rmsBooking(['status' => 'completed', 'truck_type_id' => $truckType->id, 'final_total' => 1000, 'completed_at' => now()]);
    rmsBooking(['status' => 'completed', 'truck_type_id' => $truckType->id, 'final_total' => 50000, 'created_at' => now()]);

    $result = rmsService()->revenueByTruckType($start, $end)->keyBy('truck_type_name');

    expect($result[$truckType->name]['revenue'])->toBe(1000.0);
    expect($result[$truckType->name]['jobs'])->toBe(1);
});

it('excludes a status-overridden completed booking with no completed_at from unit revenue', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $unit = Unit::create(['name' => 'RMS Guarded Unit', 'plate_number' => fake()->unique()->bothify('???-####'), 'truck_type_id' => rmsTruckType()->id, 'status' => 'available']);

    rmsBooking(['status' => 'completed', 'assigned_unit_id' => $unit->id, 'final_total' => 1500, 'completed_at' => now()]);
    rmsBooking(['status' => 'completed', 'assigned_unit_id' => $unit->id, 'final_total' => 77777, 'created_at' => now()]);

    $result = rmsService()->unitPerformance($start, $end)->keyBy('unit_name');

    expect($result[$unit->name]['revenue'])->toBe(1500.0);
    expect($result[$unit->name]['completed_jobs'])->toBe(1);
});

it('rejects a direct completed override via the generic active-bookings status endpoint', function () {
    Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) { $r->id = 2; $r->save(); });
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);

    $booking = rmsBooking(['status' => 'in_progress', 'final_total' => 12345, 'created_at' => now()]);

    $response = $this->actingAs($dispatcher)->patch(route('admin.active-bookings.update-status', $booking), [
        'status' => 'completed',
    ]);

    $response->assertSessionHasErrors('status');
    expect($booking->fresh()->status)->toBe('in_progress');
    expect($booking->fresh()->completed_at)->toBeNull();
});

it('still allows legitimate intermediate status transitions via the generic active-bookings endpoint', function () {
    Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) { $r->id = 2; $r->save(); });
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);

    $booking = rmsBooking(['status' => 'on_the_way', 'final_total' => 2000, 'created_at' => now()]);

    $response = $this->actingAs($dispatcher)->patch(route('admin.active-bookings.update-status', $booking), [
        'status' => 'arrived_pickup',
    ]);

    $response->assertSessionHasNoErrors();
    expect($booking->fresh()->status)->toBe('arrived_pickup');
});

it('defends revenue reporting even if a completed row with no completed_at existed through some other path', function () {
    rmsBooking(['status' => 'completed', 'final_total' => 12345, 'created_at' => now()]);

    $result = rmsService()->averageRevenuePerJob(now()->startOfMonth(), now()->endOfMonth());
    expect($result['completed_jobs'])->toBe(0);
    expect($result['revenue'])->toBe(0.0);
});

it('returns the same average revenue per job whether called from dashboard-style or reports-style filters', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    rmsBooking(['status' => 'completed', 'final_total' => 2000, 'completed_at' => now()]);
    rmsBooking(['status' => 'completed', 'final_total' => 4000, 'completed_at' => now()]);

    $dashboardResult = rmsService()->averageRevenuePerJob($start, $end);
    $reportsResult = rmsService()->averageRevenuePerJob($start, $end, []);

    expect($dashboardResult['average'])->toBe($reportsResult['average']);
    expect($dashboardResult['revenue'])->toBe($reportsResult['revenue']);
});
