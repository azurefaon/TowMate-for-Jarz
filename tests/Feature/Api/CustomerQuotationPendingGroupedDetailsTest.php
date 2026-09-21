<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleType;
use Laravel\Sanctum\Sanctum;

function cqpRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cqpCustomer(): array
{
    $user = User::factory()->create(['role_id' => cqpRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function cqpTruckType(float $baseRate, float $perKmRate, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

it('returns a truck_type_name and vehicle_name for every extra vehicle in a grouped Book Now quotation', function () {
    [$user, $customer] = cqpCustomer();

    $primaryTruck = cqpTruckType(1500, 60, 'CQP Light Duty');
    $extraTruck = cqpTruckType(900, 40, 'CQP Motorcycle Carrier');
    $extraVehicleType = VehicleType::create([
        'name' => 'CQP Tricycle',
        'category' => '3_wheeler',
        'required_truck_type_id' => $extraTruck->id,
        'status' => 'active',
    ]);

    $primaryBooking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $primaryTruck->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1980,
        'vat_amount' => 237.6,
        'vat_exclusive_total' => 1980,
        'final_total' => 3584.0,
        'status' => 'requested',
        'service_type' => 'book_now',
    ]);

    $siblingBooking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $extraTruck->id,
        'group_code' => 'CQP-GROUP-001',
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12,
        'base_rate' => 900,
        'per_km_rate' => 40,
        'final_total' => 1366.4,
        'status' => 'requested',
        'service_type' => 'book_now',
    ]);
    $primaryBooking->update(['group_code' => 'CQP-GROUP-001']);

    $quotation = Quotation::create([
        'quotation_number' => 'QT-CQP-' . uniqid(),
        'source_booking_id' => $primaryBooking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $primaryTruck->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12,
        'estimated_price' => 3584.0,
        'service_type' => 'book_now',
        'status' => 'sent',
        'sent_at' => now(),
        'is_current' => true,
        'extra_vehicles' => [
            [
                'booking_id' => $siblingBooking->id,
                'vehicle_type_id' => $extraVehicleType->id,
                'truck_type_id' => $extraTruck->id,
                'base_rate' => 900,
                'distance_fee' => 320,
                'vat_amount' => 146.4,
                'vat_rate' => 0.12,
                'final_total' => 1366.4,
            ],
        ],
    ]);
    $primaryBooking->update(['quotation_id' => $quotation->id]);
    $siblingBooking->update(['quotation_id' => $quotation->id]);

    Sanctum::actingAs($user, ['*']);

    $response = test()->getJson('/api/v1/quotations/pending');

    $response->assertOk();
    expect($response->json('data.truck_type_name'))->toBe('CQP Light Duty');
    expect((float) $response->json('data.estimated_price'))->toBe(3584.0);

    $extraVehicles = $response->json('data.extra_vehicles');
    expect($extraVehicles)->toHaveCount(1);
    expect($extraVehicles[0]['truck_type_name'])->toBe('CQP Motorcycle Carrier');
    expect($extraVehicles[0]['vehicle_name'])->toBe('CQP Tricycle');
    expect((float) $extraVehicles[0]['final_total'])->toBe(1366.4);
});

it('keeps a single-vehicle quotation response unchanged with an empty extra_vehicles list', function () {
    [$user, $customer] = cqpCustomer();
    $truck = cqpTruckType(1500, 60, 'CQP Solo Truck');

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1980,
        'vat_amount' => 237.6,
        'vat_exclusive_total' => 1980,
        'final_total' => 2217.6,
        'status' => 'requested',
        'service_type' => 'book_now',
    ]);

    $quotation = Quotation::create([
        'quotation_number' => 'QT-CQP-SOLO-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12,
        'estimated_price' => 2217.6,
        'service_type' => 'book_now',
        'status' => 'sent',
        'sent_at' => now(),
        'is_current' => true,
    ]);
    $booking->update(['quotation_id' => $quotation->id]);

    Sanctum::actingAs($user, ['*']);

    $response = test()->getJson('/api/v1/quotations/pending');

    $response->assertOk();
    expect($response->json('data.extra_vehicles'))->toBe([]);
});
