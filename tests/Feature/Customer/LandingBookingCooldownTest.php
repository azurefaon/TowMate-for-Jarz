<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use Illuminate\Support\Facades\Mail;

function landingBookingPayload(): array
{
    return [
        'full_name' => 'Maria Santos',
        'age' => 31,
        'phone' => '09171234567',
        'email' => 'maria@gmail.com',
        'customer_type' => 'regular',
        'service_type' => 'book_now',
        'truck_type_id' => 'Flatbed Truck',
        'vehicle_category' => '4_wheeler',
        'pickup_address' => 'Ortigas Center',
        'pickup_lat' => '14.5872',
        'pickup_lng' => '121.0569',
        'dropoff_address' => 'Pasig City Hall',
        'drop_lat' => '14.5764',
        'drop_lng' => '121.0851',
        'notes' => 'Vehicle already completed previous booking.',
    ];
}

it('allows a customer to book again after a completed booking', function () {
    Mail::fake();

    $truckType = TruckType::create([
        'name' => 'Flatbed Truck',
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Flatbed support',
    ]);

    $customer = Customer::create([
        'full_name' => 'Maria Santos',
        'age' => 31,
        'phone' => '09171234567',
        'email' => 'maria@gmail.com',
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'created_by_admin_id' => null,
        'age' => 31,
        'pickup_address' => 'Completed Pickup',
        'dropoff_address' => 'Completed Drop-off',
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $response = $this->from(route('landing.book'))
        ->post(route('landing.book.store'), landingBookingPayload());

    $response->assertRedirect(route('landing'));
    $response->assertSessionHasNoErrors();

    expect(Booking::where('customer_id', $customer->id)->count())->toBe(2)
        ->and(Booking::latest('id')->first()->status)->toBe('requested');
});

it('still blocks duplicate booking attempts for the same active pickup and drop-off route', function () {
    Mail::fake();

    $truckType = TruckType::create([
        'name' => 'Flatbed Truck',
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Flatbed support',
    ]);

    $customer = Customer::create([
        'full_name' => 'Maria Santos',
        'age' => 31,
        'phone' => '09171234567',
        'email' => 'maria@gmail.com',
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'created_by_admin_id' => null,
        'age' => 31,
        'pickup_address' => 'Ortigas Center',
        'dropoff_address' => 'Pasig City Hall',
        'status' => 'requested',
    ]);

    $response = $this->from(route('landing.book'))
        ->post(route('landing.book.store'), landingBookingPayload());

    $response->assertRedirect(route('landing.book'));
    $response->assertSessionHasErrors([
        'phone' => 'You already have an active booking for this same pickup and drop-off route.',
    ]);

    expect(Booking::where('customer_id', $customer->id)->count())->toBe(1);
});

it('allows another active booking when the drop-off is different', function () {
    Mail::fake();

    $truckType = TruckType::create([
        'name' => 'Flatbed Truck',
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Flatbed support',
    ]);

    $customer = Customer::create([
        'full_name' => 'Maria Santos',
        'age' => 31,
        'phone' => '09171234567',
        'email' => 'maria@gmail.com',
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'created_by_admin_id' => null,
        'age' => 31,
        'pickup_address' => 'Ortigas Center',
        'dropoff_address' => 'Different Destination',
        'status' => 'requested',
    ]);

    $response = $this->from(route('landing.book'))
        ->post(route('landing.book.store'), landingBookingPayload());

    $response->assertRedirect(route('landing'));
    $response->assertSessionHasNoErrors();

    expect(Booking::where('customer_id', $customer->id)->count())->toBe(2);
});
