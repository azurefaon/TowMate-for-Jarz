<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function revenueOwner(): User
{
    if (! Role::find(1)) {
        $role = new Role(['name' => 'Owner']);
        $role->id = 1;
        $role->save();
    }

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function revenueCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'Revenue Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'revenue-' . uniqid() . '@example.com',
    ]);
}

function revenueTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'RevTest Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

it('renders the revenue page safely with no completed jobs in the period', function () {
    $response = $this->actingAs(revenueOwner())->get(route('superadmin.revenue.index'));

    $response->assertOk();
    $response->assertSee('No completed revenue recorded for this period.');
    $response->assertSee('No completed jobs recorded for this period.');
    $response->assertSee('No completed unit activity recorded for this period.');
});

it('only counts completed jobs as revenue, excluding open quotations and in-progress bookings', function () {
    $owner = revenueOwner();
    $truckType = revenueTruckType();
    $customer = revenueCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 9999.00,
        'status' => 'requested',
        'created_at' => now(),
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 8888.00,
        'status' => 'in_progress',
        'created_at' => now(),
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 2016.00,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get(route('superadmin.revenue.index', ['period' => 'month']));

    $response->assertOk();
    $response->assertSee('₱2,016.00');
    $response->assertDontSee('₱9,999.00');
    $response->assertDontSee('₱8,888.00');
});

it('scopes revenue to the selected period', function () {
    $owner = revenueOwner();
    $truckType = revenueTruckType();
    $customer = revenueCustomer();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 5000.00,
        'status' => 'completed',
        'completed_at' => now()->subMonths(2),
    ]);
    $booking->forceFill(['created_at' => now()->subMonths(2)])->save();

    $todayResponse = $this->actingAs($owner)->get(route('superadmin.revenue.index', ['period' => 'today']));
    $todayResponse->assertOk();
    $todayResponse->assertDontSee('₱5,000.00');

    $quarterResponse = $this->actingAs($owner)->get(route('superadmin.revenue.index', ['period' => 'quarter']));
    $quarterResponse->assertOk();
    $quarterResponse->assertSee('₱5,000.00');
});

it('supports a custom date range using the existing from/to query parameters', function () {
    $owner = revenueOwner();
    $truckType = revenueTruckType();
    $customer = revenueCustomer();

    $targetDate = now()->subDays(10);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 3333.00,
        'status' => 'completed',
        'completed_at' => $targetDate,
    ]);
    $booking->forceFill(['created_at' => $targetDate])->save();

    $response = $this->actingAs($owner)->get(route('superadmin.revenue.index', [
        'from' => $targetDate->copy()->subDay()->toDateString(),
        'to' => $targetDate->copy()->addDay()->toDateString(),
    ]));

    $response->assertOk();
    $response->assertSee('₱3,333.00');
});
