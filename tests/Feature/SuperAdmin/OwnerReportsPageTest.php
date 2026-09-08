<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;

function reportsOwner(): User
{
    if (! Role::find(1)) {
        $role = new Role(['name' => 'Owner']);
        $role->id = 1;
        $role->save();
    }

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function reportsDispatcher(): User
{
    if (! Role::find(2)) {
        $role = new Role(['name' => 'Dispatcher']);
        $role->id = 2;
        $role->save();
    }

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

function reportsCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'Reports Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'reports-' . uniqid() . '@example.com',
    ]);
}

function reportsTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'RPT Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

it('owner can view reports', function () {
    $this->actingAs(reportsOwner())->get(route('superadmin.reports.index'))->assertOk();
});

it('dispatcher cannot access owner reports', function () {
    $this->actingAs(reportsDispatcher())->get(route('superadmin.reports.index'))->assertForbidden();
});

it('dispatcher cannot access the detailed booking drill-down', function () {
    $this->actingAs(reportsDispatcher())->get(route('superadmin.reports.bookings'))->assertForbidden();
});

it('dispatcher cannot access report exports', function () {
    $dispatcher = reportsDispatcher();

    $this->actingAs($dispatcher)->get(route('superadmin.reports.export'))->assertForbidden();
    $this->actingAs($dispatcher)->get(route('superadmin.reports.export-pdf'))->assertForbidden();
});

it('renders the reports page safely with no bookings in the period', function () {
    $response = $this->actingAs(reportsOwner())->get(route('superadmin.reports.index'));

    $response->assertOk();
    $response->assertSee('No bookings recorded for this period.');
});

it('only counts completed jobs as revenue, excluding open and in-progress bookings', function () {
    $owner = reportsOwner();
    $truckType = reportsTruckType();
    $customer = reportsCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 9999.00,
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

    $response = $this->actingAs($owner)->get(route('superadmin.reports.index', ['period' => 'month']));

    $response->assertOk();
    $response->assertSee('₱2,016.00');
    $response->assertDontSee('₱9,999.00');
});

it('reflects the booking performance pipeline and completion rate from existing authoritative counts', function () {
    $owner = reportsOwner();
    $truckType = reportsTruckType();
    $customer = reportsCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 2016.00,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1500.00,
        'status' => 'cancelled',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get(route('superadmin.reports.index', ['period' => 'month']));

    $response->assertOk();
    $response->assertSee('50.0%');
});

it('scopes reports to the selected period using the existing reporting pipeline', function () {
    $owner = reportsOwner();
    $truckType = reportsTruckType();
    $customer = reportsCustomer();

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

    $todayResponse = $this->actingAs($owner)->get(route('superadmin.reports.index', ['period' => 'today']));
    $todayResponse->assertOk();
    $todayResponse->assertDontSee('₱5,000.00');

    $quarterResponse = $this->actingAs($owner)->get(route('superadmin.reports.index', ['period' => 'quarter']));
    $quarterResponse->assertOk();
    $quarterResponse->assertSee('₱5,000.00');
});

it('continues to support filtering reports by truck type', function () {
    $owner = reportsOwner();
    $truckTypeA = reportsTruckType();
    $truckTypeB = reportsTruckType();
    $customer = reportsCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckTypeA->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 4444.00,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckTypeB->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 7777.00,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get(route('superadmin.reports.index', [
        'period' => 'month',
        'truck_type_id' => $truckTypeA->id,
    ]));

    $response->assertOk();
    $response->assertSee('₱4,444.00');
    $response->assertDontSee('₱7,777.00');
});

it('shows fleet and truck type performance using existing authoritative data', function () {
    $owner = reportsOwner();
    $truckType = reportsTruckType();
    $unit = Unit::create([
        'name' => 'RPT Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);
    $customer = reportsCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 2016.00,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get(route('superadmin.reports.index', ['period' => 'month']));

    $response->assertOk();
    $response->assertSee($truckType->name);
    $response->assertSee($unit->name);
});
