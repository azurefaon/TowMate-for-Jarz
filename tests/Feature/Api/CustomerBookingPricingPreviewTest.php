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

function cppRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cppCustomerUser(): User
{
    return User::factory()->create(['role_id' => cppRole(5, 'Customer')->id, 'status' => 'active']);
}

function cppCustomer(): array
{
    $user = User::factory()->create(['role_id' => cppRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function cppTruckType(float $baseRate = 1500, float $perKmRate = 60): TruckType
{
    return TruckType::create([
        'name' => 'CPP Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function cppVehicleType(TruckType $truckType): VehicleType
{
    return VehicleType::create([
        'name' => 'CPP Vehicle ' . fake()->unique()->word(),
        'category' => '4_wheeler',
        'required_truck_type_id' => $truckType->id,
        'status' => 'active',
    ]);
}

function cppReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => cppRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'CPP Ready Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'CPP Ready Driver',
    ]);
}

const CPP_PICKUP_LAT = 14.5995;
const CPP_PICKUP_LNG = 120.9842;
const CPP_DROPOFF_LAT = 14.6905;
const CPP_DROPOFF_LNG = 120.9842;

it('rejects an unauthenticated pricing preview request', function () {
    $truckType = cppTruckType();

    test()->postJson('/api/v1/geo/pricing-preview', [
        'truck_type_id' => $truckType->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_DROPOFF_LAT,
        'drop_lng' => CPP_DROPOFF_LNG,
    ])->assertStatus(401);
});

it('returns the authoritative Base Rate, Distance Fee, VAT and Final Total for a real trip', function () {
    Sanctum::actingAs(cppCustomerUser(), ['*']);
    $truckType = cppTruckType(1500, 60);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'truck_type_id' => $truckType->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_DROPOFF_LAT,
        'drop_lng' => CPP_DROPOFF_LNG,
        'service_type' => 'book_now',
    ]);

    $response->assertOk();

    $baseRate = (float) $response->json('pricing.base_rate');
    $distanceFee = (float) $response->json('pricing.distance_fee');
    $computedTotal = (float) $response->json('pricing.computed_total');
    $vatAmount = (float) $response->json('pricing.vat_amount');
    $finalTotal = (float) $response->json('pricing.final_total');

    expect($baseRate)->toBe(1500.0);
    expect((float) $response->json('pricing.per_km_rate'))->toBe(60.0);
    expect($distanceFee)->toBeGreaterThan(0);
    expect($computedTotal)->toBe(round($baseRate + $distanceFee, 2));
    expect($vatAmount)->toBe(round($computedTotal * 0.12, 2));
    expect($finalTotal)->toBe(round($computedTotal + $vatAmount, 2));
});

it('correctly applies the first-4-km-free rule for a nearby trip', function () {
    Sanctum::actingAs(cppCustomerUser(), ['*']);
    $truckType = cppTruckType(1500, 60);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'truck_type_id' => $truckType->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_PICKUP_LAT + 0.01,
        'drop_lng' => CPP_PICKUP_LNG,
    ]);

    $response->assertOk();
    expect((float) $response->json('pricing.distance_fee'))->toBe(0.0);
    expect((float) $response->json('pricing.final_total'))->toBe(round(1500 * 1.12, 2));
});

it('rejects identical pickup and dropoff coordinates', function () {
    Sanctum::actingAs(cppCustomerUser(), ['*']);
    $truckType = cppTruckType();

    test()->postJson('/api/v1/geo/pricing-preview', [
        'truck_type_id' => $truckType->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_PICKUP_LAT,
        'drop_lng' => CPP_PICKUP_LNG,
    ])->assertStatus(422);
});

it('ignores a client-forged truck_type_id that does not exist', function () {
    Sanctum::actingAs(cppCustomerUser(), ['*']);

    test()->postJson('/api/v1/geo/pricing-preview', [
        'truck_type_id' => 999999,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_DROPOFF_LAT,
        'drop_lng' => CPP_DROPOFF_LNG,
    ])->assertStatus(422);
});

it('rejects a pricing preview for an inactive primary vehicle type', function () {
    Sanctum::actingAs(cppCustomerUser(), ['*']);
    $truckType = cppTruckType();
    $vehicleType = cppVehicleType($truckType);
    $vehicleType->update(['status' => 'inactive']);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'vehicle_type_id' => $vehicleType->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_DROPOFF_LAT,
        'drop_lng' => CPP_DROPOFF_LNG,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('vehicle_type_id');
});

it('rejects a pricing preview whose extra vehicle uses an inactive vehicle type', function () {
    Sanctum::actingAs(cppCustomerUser(), ['*']);
    $primaryTruckType = cppTruckType();
    $primaryVehicle = cppVehicleType($primaryTruckType);
    $extraTruckType = cppTruckType();
    $extraVehicle = cppVehicleType($extraTruckType);
    $extraVehicle->update(['status' => 'inactive']);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'vehicle_type_id' => $primaryVehicle->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_DROPOFF_LAT,
        'drop_lng' => CPP_DROPOFF_LNG,
        'extra_vehicles' => [
            ['vehicle_type_id' => $extraVehicle->id, 'service_type' => 'book_now'],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('extra_vehicles.0.vehicle_type_id');
});

it('combines Book Now extra vehicles into one total, matching what createBooking would actually charge', function () {
    Sanctum::actingAs(cppCustomerUser(), ['*']);
    $primary = cppTruckType(1500, 60);
    $extra = cppTruckType(900, 60);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'truck_type_id' => $primary->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_PICKUP_LAT + 0.01,
        'drop_lng' => CPP_PICKUP_LNG,
        'extra_vehicles' => [
            ['truck_type_id' => $extra->id, 'service_type' => 'book_now'],
        ],
    ]);

    $response->assertOk();
    $expectedComputedTotal = round(1500 + 900, 2);
    $expectedFinalTotal = round($expectedComputedTotal * 1.12, 2);

    expect((float) $response->json('pricing.computed_total'))->toBe($expectedComputedTotal);
    expect((float) $response->json('pricing.final_total'))->toBe($expectedFinalTotal);
    expect($response->json('scheduled_extra_previews'))->toBe([]);
});

it('prices each Book Now extra vehicle using its own per_km_rate, not the primary vehicle truck type\'s rate', function () {
    Sanctum::actingAs(cppCustomerUser(), ['*']);
    $primary = cppTruckType(1500, 60);
    $extra = cppTruckType(900, 40);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'truck_type_id' => $primary->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_DROPOFF_LAT,
        'drop_lng' => CPP_DROPOFF_LNG,
        'extra_vehicles' => [
            ['truck_type_id' => $extra->id, 'service_type' => 'book_now'],
        ],
    ]);

    $response->assertOk();
    $distanceKm = (float) $response->json('pricing.distance_km');
    expect($distanceKm)->toBeGreaterThan(4.0);

    $primaryDistanceFee = round(($distanceKm - 4.0) * 60, 2);
    $extraDistanceFee = round(($distanceKm - 4.0) * 40, 2);
    $expectedDistanceFee = round($primaryDistanceFee + $extraDistanceFee, 2);
    $expectedComputedTotal = round(1500 + 900 + $expectedDistanceFee, 2);

    expect((float) $response->json('pricing.distance_fee'))->toBe($expectedDistanceFee);
    expect((float) $response->json('pricing.computed_total'))->toBe($expectedComputedTotal);
    expect((float) $response->json('pricing.distance_fee'))->not->toBe(round(($distanceKm - 4.0) * 60 * 2, 2));
});

it('prices Scheduled extra vehicles separately from the primary total, not combined', function () {
    Sanctum::actingAs(cppCustomerUser(), ['*']);
    $primary = cppTruckType(1500, 60);
    $extra = cppTruckType(900, 60);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'truck_type_id' => $primary->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_PICKUP_LAT + 0.01,
        'drop_lng' => CPP_PICKUP_LNG,
        'service_type' => 'schedule',
        'extra_vehicles' => [
            ['truck_type_id' => $extra->id, 'service_type' => 'schedule'],
        ],
    ]);

    $response->assertOk();
    $expectedFinalTotal = round(1500 * 1.12, 2);
    expect((float) $response->json('pricing.computed_total'))->toBe(1500.0);
    expect((float) $response->json('pricing.final_total'))->toBe($expectedFinalTotal);

    $extraPreviews = $response->json('scheduled_extra_previews');
    expect($extraPreviews)->toHaveCount(1);
    expect($extraPreviews[0]['truck_type_id'])->toBe($extra->id);
    expect((float) $extraPreviews[0]['base_rate'])->toBe(900.0);
    expect((float) $extraPreviews[0]['distance_fee'])->toBe(0.0);
    expect((float) $extraPreviews[0]['vat_amount'])->toBe(round(900 * 0.12, 2));
    expect((float) $extraPreviews[0]['final_total'])->toBe(round(900 * 1.12, 2));
});

it('includes the distance fee in a Scheduled extra vehicle preview for trips over 4 km, using its own truck type per_km_rate', function () {
    Sanctum::actingAs(cppCustomerUser(), ['*']);
    $primary = cppTruckType(1500, 60);
    $extra = cppTruckType(900, 40);

    $response = test()->postJson('/api/v1/geo/pricing-preview', [
        'truck_type_id' => $primary->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_DROPOFF_LAT,
        'drop_lng' => CPP_DROPOFF_LNG,
        'service_type' => 'schedule',
        'extra_vehicles' => [
            ['truck_type_id' => $extra->id, 'service_type' => 'schedule'],
        ],
    ]);

    $response->assertOk();
    $distanceKm = (float) $response->json('pricing.distance_km');
    expect($distanceKm)->toBeGreaterThan(4.0);

    $extraPreviews = $response->json('scheduled_extra_previews');
    $expectedDistanceFee = round(($distanceKm - 4.0) * 40, 2);
    $expectedSubtotal = round(900 + $expectedDistanceFee, 2);
    $expectedVat = round($expectedSubtotal * 0.12, 2);
    $expectedFinalTotal = round($expectedSubtotal + $expectedVat, 2);

    expect((float) $extraPreviews[0]['distance_fee'])->toBe($expectedDistanceFee);
    expect((float) $extraPreviews[0]['distance_fee'])->toBeGreaterThan(0);
    expect((float) $extraPreviews[0]['final_total'])->toBe($expectedFinalTotal);
    expect((float) $extraPreviews[0]['final_total'])->not->toBe(round(900 * 1.12, 2));
});

it('matches the created booking price to the pricing preview for the same coordinates on a trip over 4 km', function () {
    [$user, $customer] = cppCustomer();
    $truckType = cppTruckType(1500, 60);
    $vehicleType = cppVehicleType($truckType);
    cppReadyUnit($truckType);
    Sanctum::actingAs($user, ['*']);

    $previewResponse = test()->postJson('/api/v1/geo/pricing-preview', [
        'truck_type_id' => $truckType->id,
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'drop_lat' => CPP_DROPOFF_LAT,
        'drop_lng' => CPP_DROPOFF_LNG,
        'service_type' => 'book_now',
    ]);
    $previewResponse->assertOk();

    $previewDistanceKm = (float) $previewResponse->json('pricing.distance_km');
    $previewDistanceFee = (float) $previewResponse->json('pricing.distance_fee');
    $previewComputedTotal = (float) $previewResponse->json('pricing.computed_total');
    $previewFinalTotal = (float) $previewResponse->json('pricing.final_total');

    expect($previewDistanceKm)->toBeGreaterThan(4.0);
    expect($previewDistanceFee)->toBeGreaterThan(0);

    $bookingResponse = test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $vehicleType->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => CPP_DROPOFF_LAT,
        'dropoff_lng' => CPP_DROPOFF_LNG,
        'distance_km' => 0.5,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);
    $bookingResponse->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();

    expect((float) $booking->distance_km)->toBe($previewDistanceKm);
    expect((float) $booking->distance_km)->not->toBe(0.5);
    expect((float) $booking->computed_total)->toBe($previewComputedTotal);
    expect((float) $booking->final_total)->toBe($previewFinalTotal);
});

it('rejects a Scheduled request whose extra vehicle claims to be Book Now', function () {
    [$user] = cppCustomer();
    $primaryTruck = cppTruckType(1500, 60);
    $primaryVehicle = cppVehicleType($primaryTruck);
    $extraTruck = cppTruckType(900, 60);
    $extraVehicle = cppVehicleType($extraTruck);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $primaryTruck->id,
        'vehicle_type_id' => $primaryVehicle->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => CPP_PICKUP_LAT,
        'pickup_lng' => CPP_PICKUP_LNG,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => CPP_DROPOFF_LAT,
        'dropoff_lng' => CPP_DROPOFF_LNG,
        'distance_km' => 0.5,
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
        'extra_vehicles' => json_encode([
            [
                'vehicle_type_id' => $extraVehicle->id,
                'service_type' => 'book_now',
            ],
        ]),
        'extra_vehicle_images' => [0 => [UploadedFile::fake()->image('extra.jpg')]],
    ]);

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});
