<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function qporDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function qporCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'QPOR Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'qpor-' . uniqid() . '@example.com',
    ]);
}

function qporTruckType(float $baseRate, float $perKmRate): TruckType
{
    return TruckType::create([
        'name' => 'QPOR Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function qporBooking(Customer $customer, TruckType $truckType, float $distanceKm = 12.0): Booking
{
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => $distanceKm,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'status' => 'scheduled',
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    return $booking->fresh(['customer', 'truckType']);
}

it('shows the dispatcher the base rate and per-km rate that were actually used to price the quotation, not the truck type live current settings', function () {
    $dispatcher = qporDispatcher();
    $customer = qporCustomer();
    $truck = qporTruckType(1500, 60);
    $booking = qporBooking($customer, $truck, 12.0);

    test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $booking), [
        'price' => '2200',
        'distance_km' => '12',
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    $truck->update(['base_rate' => 5000, 'per_km_rate' => 250]);

    $response = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation));
    $response->assertOk();
    $data = $response->json('quotation');

    expect((float) $data['base_price'])->toBe(1500.0);
    expect((float) $data['per_km_rate'])->toBe(60.0);
    expect((float) $data['distance_fee'])->toBe(round((12.0 - 4.0) * 60, 2));
});
