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

function sbmRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function sbmCustomer(): array
{
    $user = User::factory()->create(['role_id' => sbmRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function sbmTruckType(float $baseRate = 1500, float $perKmRate = 60): TruckType
{
    return TruckType::create([
        'name' => 'SBM Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function sbmVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function sbmReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => sbmRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'SBM Ready Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'SBM Ready Driver',
    ]);
}

function sbmImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("vehicle-$i.jpg", 300, 300), range(1, $count));
}

function sbmBasePayload(VehicleType $vehicle, array $overrides = []): array
{
    return array_merge([
        'vehicle_type_id' => $vehicle->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'vehicle_images' => sbmImages(1),
    ], $overrides);
}

it('allows a Book Now request when every vehicle has exact Truck Type availability', function () {
    [$user] = sbmCustomer();
    $truck = sbmTruckType();
    sbmReadyUnit($truck);
    $vehicle = sbmVehicleType($truck->id, 'SBM Sedan');
    $extraVehicle = sbmVehicleType($truck->id, 'SBM Van');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', sbmBasePayload($vehicle, [
        'service_type' => 'book_now',
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id],
        ]),
        'extra_vehicle_images' => [0 => sbmImages(1)],
    ]));

    $response->assertCreated();
    expect($response->json('bookings'))->toHaveCount(1);
    expect(Booking::count())->toBe(1);
});

it('blocks a Book Now request when the primary vehicle has no exact Truck Type availability', function () {
    [$user] = sbmCustomer();
    $truck = sbmTruckType();
    $vehicle = sbmVehicleType($truck->id, 'SBM Unavailable Sedan');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', sbmBasePayload($vehicle, [
        'service_type' => 'book_now',
    ]));

    $response->assertStatus(422);
    expect($response->json('unavailable_vehicles'))->toBe([1]);
    expect(Booking::count())->toBe(0);
});

it('blocks a Book Now request and identifies exactly which additional vehicle is unavailable', function () {
    [$user] = sbmCustomer();
    $readyTruck = sbmTruckType();
    sbmReadyUnit($readyTruck);
    $vehicle = sbmVehicleType($readyTruck->id, 'SBM Ready Sedan');

    $unreadyTruck = sbmTruckType();
    $extraVehicle = sbmVehicleType($unreadyTruck->id, 'SBM Unready Motorcycle');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', sbmBasePayload($vehicle, [
        'service_type' => 'book_now',
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id],
        ]),
        'extra_vehicle_images' => [0 => sbmImages(1)],
    ]));

    $response->assertStatus(422);
    expect($response->json('unavailable_vehicles'))->toBe([2]);
    expect(Booking::count())->toBe(0);
});

it('Schedule entire request creates every vehicle as Scheduled, using the request schedule', function () {
    [$user] = sbmCustomer();
    $truck = sbmTruckType();
    $vehicle = sbmVehicleType($truck->id, 'SBM Sedan');
    $extraVehicle = sbmVehicleType($truck->id, 'SBM Motorcycle');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', sbmBasePayload($vehicle, [
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '14:00',
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id],
        ]),
        'extra_vehicle_images' => [0 => sbmImages(1)],
    ]));

    $response->assertCreated();
    $bookings = Booking::orderBy('id')->get();
    expect($bookings)->toHaveCount(2);
    foreach ($bookings as $b) {
        expect($b->service_type)->toBe('schedule');
        expect($b->status)->toBe('scheduled');
        expect($b->scheduled_time)->toBe('14:00');
    }
});

it('prices a Scheduled sibling with the same canonical base+distance-fee formula as the primary vehicle', function () {
    [$user, $customer] = sbmCustomer();
    $primaryTruck = sbmTruckType(1500, 60);
    $vehicle = sbmVehicleType($primaryTruck->id, 'SBM Sedan');
    $extraTruck = sbmTruckType(900, 40);
    $extraVehicle = sbmVehicleType($extraTruck->id, 'SBM Tricycle');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', sbmBasePayload($vehicle, [
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '11:00',
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id],
        ]),
        'extra_vehicle_images' => [0 => sbmImages(1)],
    ]));

    $response->assertCreated();

    $primary = Booking::where('customer_id', $customer->id)->where('truck_type_id', $primaryTruck->id)->firstOrFail();
    $sibling = Booking::where('customer_id', $customer->id)->where('truck_type_id', $extraTruck->id)->firstOrFail();

    expect((float) $sibling->distance_km)->toBe((float) $primary->distance_km);
    expect((float) $primary->distance_km)->toBeGreaterThan(4.0);

    $expectedSiblingDistanceFee = round(((float) $sibling->distance_km - 4.0) * 40, 2);
    $expectedSiblingComputedTotal = round(900 + $expectedSiblingDistanceFee, 2);
    $expectedSiblingVat = round($expectedSiblingComputedTotal * 0.12, 2);
    $expectedSiblingFinalTotal = round($expectedSiblingComputedTotal + $expectedSiblingVat, 2);

    expect((float) $sibling->computed_total)->toBe($expectedSiblingComputedTotal);
    expect((float) $sibling->vat_amount)->toBe($expectedSiblingVat);
    expect((float) $sibling->final_total)->toBe($expectedSiblingFinalTotal);
    expect((float) $sibling->final_total)->not->toBe(round(900 * 1.12, 2));

    $expectedPrimaryDistanceFee = round(((float) $primary->distance_km - 4.0) * 60, 2);
    expect((float) $primary->computed_total)->toBe(round(1500 + $expectedPrimaryDistanceFee, 2));
});

it('rejects a scheduled request missing a scheduled date and time', function () {
    [$user] = sbmCustomer();
    $truck = sbmTruckType();
    $vehicle = sbmVehicleType($truck->id, 'SBM Sedan');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', sbmBasePayload($vehicle, [
        'service_type' => 'schedule',
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('rejects a Book Now request whose extra vehicle claims to be Scheduled', function () {
    [$user] = sbmCustomer();
    $truck = sbmTruckType();
    sbmReadyUnit($truck);
    $vehicle = sbmVehicleType($truck->id, 'SBM Sedan');
    $extraVehicle = sbmVehicleType($truck->id, 'SBM Van');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', sbmBasePayload($vehicle, [
        'service_type' => 'book_now',
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id, 'service_type' => 'schedule'],
        ]),
        'extra_vehicle_images' => [0 => sbmImages(1)],
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('cannot spoof a cheaper truck_type_id for an extra vehicle to bypass the real vehicle-to-truck mapping', function () {
    [$user] = sbmCustomer();
    $cheapTruck = sbmTruckType(500, 30);
    $expensiveTruck = sbmTruckType(3000, 100);
    sbmReadyUnit($cheapTruck);
    sbmReadyUnit($expensiveTruck);
    $vehicle = sbmVehicleType($cheapTruck->id, 'SBM Sedan');
    $extraVehicle = sbmVehicleType($expensiveTruck->id, 'SBM Heavy Truck');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', sbmBasePayload($vehicle, [
        'service_type' => 'book_now',
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id, 'truck_type_id' => $cheapTruck->id],
        ]),
        'extra_vehicle_images' => [0 => sbmImages(1)],
    ]));

    $response->assertCreated();
    $booking = Booking::latest()->first();
    $extraCharge = json_decode(json_encode($booking->extra_vehicles), true)[0];
    expect((float) $extraCharge['estimated_price'])->toBe(round($expensiveTruck->base_rate * 1.12, 2));
    expect((int) $extraCharge['truck_type_id'])->toBe($expensiveTruck->id);
});

it('enforces the maximum of 6 vehicles server-side even when the client sends 7', function () {
    [$user] = sbmCustomer();
    $truck = sbmTruckType();
    sbmReadyUnit($truck);
    $vehicle = sbmVehicleType($truck->id, 'SBM Sedan');
    $extras = array_map(fn ($i) => ['vehicle_type_id' => sbmVehicleType($truck->id, "SBM Extra $i")->id], range(1, 6));
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', sbmBasePayload($vehicle, [
        'service_type' => 'book_now',
        'extra_vehicles' => json_encode($extras),
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('cancelling a sibling never reprices or retroactively touches the remaining historical booking', function () {
    [$user] = sbmCustomer();
    $truck = sbmTruckType(1500, 60);
    $vehicle = sbmVehicleType($truck->id, 'SBM Sedan');
    $extraVehicle = sbmVehicleType($truck->id, 'SBM Motorcycle');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', sbmBasePayload($vehicle, [
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id],
        ]),
        'extra_vehicle_images' => [0 => sbmImages(1)],
    ]));
    $response->assertCreated();

    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];
    $primaryBefore = Booking::where('booking_code', $primaryCode)->first();
    $baseRateBefore = (float) $primaryBefore->base_rate;
    $distanceFeeBefore = (float) $primaryBefore->computed_total - $baseRateBefore;
    $finalTotalBefore = (float) $primaryBefore->final_total;

    test()->postJson("/api/v1/bookings/{$siblingCode}/cancel")->assertOk();

    $primaryAfter = Booking::where('booking_code', $primaryCode)->first();
    expect((float) $primaryAfter->base_rate)->toBe($baseRateBefore);
    expect((float) $primaryAfter->computed_total - (float) $primaryAfter->base_rate)->toBe($distanceFeeBefore);
    expect((float) $primaryAfter->final_total)->toBe($finalTotalBefore);
    expect($primaryAfter->status)->toBe('scheduled');
});
