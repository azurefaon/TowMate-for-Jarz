<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function odmOwner(): User
{
    if (! Role::find(1)) {
        $role = new Role(['name' => 'Owner']);
        $role->id = 1;
        $role->save();
    }

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function odmCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'ODM Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'odm-' . uniqid() . '@example.com',
    ]);
}

function odmTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'ODM Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function odmBooking(array $overrides = []): Booking
{
    $customer = odmCustomer();
    $truckType = odmTruckType();

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 1800,
        'status' => 'completed',
        'created_at' => now(),
        'completed_at' => now(),
    ], $overrides));
}

it('shows the correct completed jobs count excluding cancelled bookings', function () {
    foreach (range(1, 6) as $unused) {
        odmBooking(['status' => 'completed']);
    }
    odmBooking(['status' => 'cancelled']);
    odmBooking(['status' => 'cancelled']);

    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('₱10,800.00');
    $response->assertSeeInOrder(['Completed Jobs', '6']);
});

it('shows the correct monthly revenue total for completed jobs', function () {
    odmBooking(['status' => 'completed', 'final_total' => 2000]);
    odmBooking(['status' => 'completed', 'final_total' => 4000]);

    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('₱6,000.00');
});
