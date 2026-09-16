<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

function vfbRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function vfbCustomer(): array
{
    $user = User::factory()->create(['role_id' => vfbRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function vfbTruckType(float $baseRate = 1500, float $perKmRate = 60, string $status = 'active'): TruckType
{
    return TruckType::create([
        'name' => 'VFB Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => $status,
    ]);
}

function vfbVehicleType(?int $requiredTruckTypeId): VehicleType
{
    return VehicleType::create([
        'name' => 'VFB Vehicle ' . fake()->unique()->word(),
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function vfbReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => vfbRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'VFB Ready Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'VFB Ready Driver',
    ]);
}

function vfbBookingPayload(array $overrides = []): array
{
    return array_merge([
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ], $overrides);
}

it('pricingPreview derives the truck type from vehicle_type_id and ignores a spoofed truck_type_id', function () {
    [$user] = vfbCustomer();
    $cheapTruck = vfbTruckType(500, 10);
    $expensiveTruck = vfbTruckType(9000, 500);
    $vehicleType = vfbVehicleType($expensiveTruck->id);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'vehicle_type_id' => $vehicleType->id,
        'truck_type_id' => $cheapTruck->id,
        'pickup_lat' => 14.5995, 'pickup_lng' => 120.9842,
        'drop_lat' => 14.6905, 'drop_lng' => 120.9842,
    ]);

    $response->assertOk();
    expect((float) $response->json('pricing.base_rate'))->toBe(9000.0);
    expect((float) $response->json('pricing.base_rate'))->not->toBe(500.0);
});

it('pricingPreview and createBooking derive the identical truck type for the same vehicle_type_id', function () {
    [$user, $customer] = vfbCustomer();
    $truckType = vfbTruckType(2200, 70);
    $vehicleType = vfbVehicleType($truckType->id);
    vfbReadyUnit($truckType);
    Sanctum::actingAs($user, ['*']);

    $previewResponse = test()->postJson('/api/v1/geo/pricing-preview', [
        'vehicle_type_id' => $vehicleType->id,
        'pickup_lat' => 14.5995, 'pickup_lng' => 120.9842,
        'drop_lat' => 14.6905, 'drop_lng' => 120.9842,
    ]);
    $previewResponse->assertOk();

    $bookingResponse = test()->postJson('/api/v1/bookings', vfbBookingPayload([
        'vehicle_type_id' => $vehicleType->id,
    ]));
    $bookingResponse->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();

    expect((float) $previewResponse->json('pricing.base_rate'))->toBe((float) $booking->base_rate);
    expect($booking->truck_type_id)->toBe($truckType->id);
});

it('exposes exact-type ready_truck_type_ids on the customer availability endpoint', function () {
    [$user] = vfbCustomer();
    $readyTruck = vfbTruckType();
    $idleTruck = vfbTruckType();
    vfbReadyUnit($readyTruck);
    Sanctum::actingAs($user, ['*']);

    $response = test()->getJson('/api/v1/availability');

    $response->assertOk();
    $readyIds = $response->json('ready_truck_type_ids');
    expect($readyIds)->toContain($readyTruck->id);
    expect($readyIds)->not->toContain($idleTruck->id);
});

it('pricingPreview rejects a vehicle type with no configured required truck type', function () {
    [$user] = vfbCustomer();
    $vehicleType = vfbVehicleType(null);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'vehicle_type_id' => $vehicleType->id,
        'pickup_lat' => 14.5995, 'pickup_lng' => 120.9842,
        'drop_lat' => 14.6905, 'drop_lng' => 120.9842,
    ]);

    $response->assertStatus(422);
});

it('pricingPreview rejects a vehicle type whose derived truck type is inactive', function () {
    [$user] = vfbCustomer();
    $inactiveTruck = vfbTruckType(1500, 60, 'inactive');
    $vehicleType = vfbVehicleType($inactiveTruck->id);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'vehicle_type_id' => $vehicleType->id,
        'pickup_lat' => 14.5995, 'pickup_lng' => 120.9842,
        'drop_lat' => 14.6905, 'drop_lng' => 120.9842,
    ]);

    $response->assertStatus(422);
});

it('createBooking rejects a vehicle type whose derived truck type is inactive', function () {
    [$user] = vfbCustomer();
    $inactiveTruck = vfbTruckType(1500, 60, 'inactive');
    $vehicleType = vfbVehicleType($inactiveTruck->id);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vfbBookingPayload([
        'vehicle_type_id' => $vehicleType->id,
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('blocks a Book Now request instead of silently downgrading an extra vehicle to Scheduled', function () {
    [$user, $customer] = vfbCustomer();
    $primaryTruck = vfbTruckType();
    $primaryVehicle = vfbVehicleType($primaryTruck->id);
    vfbReadyUnit($primaryTruck);

    $extraTruck = vfbTruckType();
    $extraVehicle = vfbVehicleType($extraTruck->id);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vfbBookingPayload([
        'vehicle_type_id' => $primaryVehicle->id,
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id],
        ]),
        'extra_vehicle_images' => [0 => [UploadedFile::fake()->image('extra.jpg')]],
    ]));

    $response->assertStatus(422);
    expect($response->json('unavailable_vehicles'))->toBe([2]);
    expect(Booking::count())->toBe(0);
});

it('keeps a Book Now extra vehicle as Book Now when its derived truck type has a ready unit', function () {
    [$user, $customer] = vfbCustomer();
    $primaryTruck = vfbTruckType();
    $primaryVehicle = vfbVehicleType($primaryTruck->id);
    vfbReadyUnit($primaryTruck);

    $extraTruck = vfbTruckType();
    $extraVehicle = vfbVehicleType($extraTruck->id);
    vfbReadyUnit($extraTruck);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vfbBookingPayload([
        'vehicle_type_id' => $primaryVehicle->id,
        'extra_vehicles' => json_encode([
            [
                'vehicle_type_id' => $extraVehicle->id,
                'service_type' => 'book_now',
            ],
        ]),
        'extra_vehicle_images' => [0 => [UploadedFile::fake()->image('extra.jpg')]],
    ]));

    $response->assertCreated();

    $primaryBooking = Booking::where('customer_id', $customer->id)->where('truck_type_id', $primaryTruck->id)->latest()->first();
    $spoofedSibling = Booking::where('customer_id', $customer->id)->where('truck_type_id', $extraTruck->id)->exists();

    expect($primaryBooking->extra_vehicles)->not->toBeEmpty();
    expect((int) $primaryBooking->extra_vehicles[0]['truck_type_id'])->toBe($extraTruck->id);
    expect($spoofedSibling)->toBeFalse();
});

it('still enforces the maximum of 6 vehicles server-side even with valid vehicle types', function () {
    [$user] = vfbCustomer();
    $primaryTruck = vfbTruckType();
    $primaryVehicle = vfbVehicleType($primaryTruck->id);
    Sanctum::actingAs($user, ['*']);

    $extras = [];
    for ($i = 0; $i < 6; $i++) {
        $truck = vfbTruckType();
        $vehicle = vfbVehicleType($truck->id);
        $extras[] = ['vehicle_type_id' => $vehicle->id, 'service_type' => 'book_now'];
    }

    $response = test()->postJson('/api/v1/bookings', vfbBookingPayload([
        'vehicle_type_id' => $primaryVehicle->id,
        'extra_vehicles' => json_encode($extras),
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});
