<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingService;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

function ditRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ditCustomer(): array
{
    $user = User::factory()->create(['role_id' => ditRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function ditTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'DIT Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function ditVehicleType(TruckType $truckType): VehicleType
{
    return VehicleType::create([
        'name' => 'DIT Vehicle ' . fake()->unique()->word(),
        'category' => '4_wheeler',
        'required_truck_type_id' => $truckType->id,
        'status' => 'active',
    ]);
}

function ditReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => ditRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'DIT Ready Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'DIT Ready Driver',
    ]);
}

const DIT_PICKUP_LAT = 14.5995;
const DIT_PICKUP_LNG = 120.9842;
const DIT_DROPOFF_LAT = 14.6905;
const DIT_DROPOFF_LNG = 120.9842;

it('ignores a forged short distance_km and prices from the real pickup/dropoff coordinates', function () {
    [$user, $customer] = ditCustomer();
    $truckType = ditTruckType();
    $vehicleType = ditVehicleType($truckType);
    ditReadyUnit($truckType);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $vehicleType->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => DIT_PICKUP_LAT,
        'pickup_lng' => DIT_PICKUP_LNG,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => DIT_DROPOFF_LAT,
        'dropoff_lng' => DIT_DROPOFF_LNG,
        'distance_km' => 0.5,
        'base_rate' => 1,
        'per_km_rate' => 1,
        'computed_total' => 1,
        'final_total' => 1,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);

    $response->assertCreated();
    $booking = Booking::where('customer_id', $customer->id)->latest()->first();

    $realDistanceKm = app(BookingService::class)->estimateDirectDistanceKm(
        DIT_PICKUP_LAT, DIT_PICKUP_LNG, DIT_DROPOFF_LAT, DIT_DROPOFF_LNG,
    );

    expect($realDistanceKm)->toBeGreaterThan(9.0);
    expect((float) $booking->distance_km)->toBe($realDistanceKm);
    expect((float) $booking->distance_km)->not->toBe(0.5);

    $expectedDistanceFee = app(BookingService::class)->distanceFeeFor($realDistanceKm, (float) $truckType->per_km_rate);
    expect($expectedDistanceFee)->toBeGreaterThan(0);

    $expectedComputedTotal = round((float) $truckType->base_rate + $expectedDistanceFee, 2);
    $expectedFinalTotal = round($expectedComputedTotal * 1.12, 2);

    expect((float) $booking->computed_total)->toBe($expectedComputedTotal);
    expect((float) $booking->final_total)->toBe($expectedFinalTotal);
    expect((float) $booking->final_total)->not->toBe(1.0);
});

it('ignores a forged short distance_km for a Scheduled booking too', function () {
    [$user, $customer] = ditCustomer();
    $truckType = ditTruckType();
    $vehicleType = ditVehicleType($truckType);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $vehicleType->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => DIT_PICKUP_LAT,
        'pickup_lng' => DIT_PICKUP_LNG,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => DIT_DROPOFF_LAT,
        'dropoff_lng' => DIT_DROPOFF_LNG,
        'distance_km' => 0.2,
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);

    $response->assertCreated();
    $booking = Booking::where('customer_id', $customer->id)->latest()->first();

    expect($booking->service_type)->toBe('schedule');
    expect((float) $booking->distance_km)->toBeGreaterThan(9.0);
    expect((float) $booking->distance_km)->not->toBe(0.2);
});

it('still creates a legitimate booking correctly with the server deriving distance from coordinates', function () {
    [$user, $customer] = ditCustomer();
    $truckType = ditTruckType();
    $vehicleType = ditVehicleType($truckType);
    ditReadyUnit($truckType);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $vehicleType->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => DIT_PICKUP_LAT,
        'pickup_lng' => DIT_PICKUP_LNG,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => DIT_DROPOFF_LAT,
        'dropoff_lng' => DIT_DROPOFF_LNG,
        'distance_km' => 10.13,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);

    $response->assertCreated();
    expect($response->json('success'))->toBeTrue();
    expect($response->json('booking_code'))->not->toBeEmpty();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    expect((float) $booking->final_total)->toBeGreaterThan((float) $truckType->base_rate);
});

it('correctly charges only the base rate when the real coordinates are within the free first 4 km', function () {
    [$user, $customer] = ditCustomer();
    $truckType = ditTruckType();
    $vehicleType = ditVehicleType($truckType);
    ditReadyUnit($truckType);
    Sanctum::actingAs($user, ['*']);

    $nearbyLat = DIT_PICKUP_LAT + 0.01;

    $response = test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $vehicleType->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => DIT_PICKUP_LAT,
        'pickup_lng' => DIT_PICKUP_LNG,
        'dropoff_address' => 'Destination nearby',
        'dropoff_lat' => $nearbyLat,
        'dropoff_lng' => DIT_PICKUP_LNG,
        'distance_km' => 500,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);

    $response->assertCreated();
    $booking = Booking::where('customer_id', $customer->id)->latest()->first();

    expect((float) $booking->distance_km)->toBeLessThan(4.0);
    $expectedTotal = round((float) $truckType->base_rate * 1.12, 2);
    expect((float) $booking->final_total)->toBe($expectedTotal);
});
