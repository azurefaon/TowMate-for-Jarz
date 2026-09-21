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

function vttRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function vttCustomer(): array
{
    $user = User::factory()->create(['role_id' => vttRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function vttTruckType(string $name = 'VT Truck'): TruckType
{
    return TruckType::create([
        'name' => $name . ' ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function vttVehicleType(?int $requiredTruckTypeId = null): VehicleType
{
    return VehicleType::create([
        'name' => 'VT Vehicle ' . fake()->unique()->word(),
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function vttReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => vttRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'VT Ready Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'VT Ready Driver',
    ]);
}

function vttBookingPayload(array $overrides = []): array
{
    return array_merge([
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5,
        'pickup_lng' => 121.0,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6,
        'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ], $overrides);
}

it('accepts a booking when the submitted truck type matches the vehicle type required truck type', function () {
    [$user] = vttCustomer();
    $truckType = vttTruckType('Matching');
    $vehicleType = vttVehicleType($truckType->id);
    vttReadyUnit($truckType);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $vehicleType->id,
    ]));

    $response->assertCreated();
});

it('derives and overwrites the truck type server-side instead of trusting a mismatched client value', function () {
    [$user, $customer] = vttCustomer();
    $lightTruck = vttTruckType('Light');
    $heavyTruck = vttTruckType('Heavy');
    $sedan = vttVehicleType($lightTruck->id);
    vttReadyUnit($lightTruck);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'truck_type_id' => $heavyTruck->id,
        'vehicle_type_id' => $sedan->id,
    ]));

    $response->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    expect($booking->truck_type_id)->toBe($lightTruck->id);
    expect($booking->truck_type_id)->not->toBe($heavyTruck->id);
});

it('cannot spoof a cheaper truck type by submitting a mismatched vehicle_type_id and truck_type_id pair', function () {
    [$user, $customer] = vttCustomer();
    $cheapTruck = vttTruckType('Cheap');
    $cheapTruck->update(['base_rate' => 500, 'per_km_rate' => 10]);
    $expensiveTruck = vttTruckType('Expensive');
    $expensiveTruck->update(['base_rate' => 9000, 'per_km_rate' => 500]);
    $heavyVehicle = vttVehicleType($expensiveTruck->id);
    vttReadyUnit($expensiveTruck);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'truck_type_id' => $cheapTruck->id,
        'vehicle_type_id' => $heavyVehicle->id,
    ]));

    $response->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    expect($booking->truck_type_id)->toBe($expensiveTruck->id);
    expect((float) $booking->base_rate)->toBe(9000.0);
    expect((float) $booking->base_rate)->not->toBe(500.0);
});

it('rejects booking creation without a vehicle_type_id, matching the real customer app contract', function () {
    [$user] = vttCustomer();
    $truckType = vttTruckType('NoVehicleType');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'truck_type_id' => $truckType->id,
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('vehicle_type_id');
});

it('rejects booking creation when the vehicle type is inactive', function () {
    [$user] = vttCustomer();
    $truckType = vttTruckType('Inactive');
    $vehicleType = vttVehicleType($truckType->id);
    $vehicleType->update(['status' => 'inactive']);
    vttReadyUnit($truckType);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $vehicleType->id,
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('rejects booking creation when the vehicle type has no configured required truck type', function () {
    [$user] = vttCustomer();
    $truckType = vttTruckType('Unconfigured');
    $vehicleType = vttVehicleType(null);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $vehicleType->id,
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('returns eligible units filtered by the exact required truck type only', function () {
    $light = vttTruckType('EligLight');
    $heavy = vttTruckType('EligHeavy');

    $lightLeader = User::factory()->create(['role_id' => vttRole(3, 'Team Leader')->id]);
    $heavyLeader = User::factory()->create(['role_id' => vttRole(3, 'Team Leader')->id]);

    $lightUnit = Unit::create([
        'name' => 'VT Light Unit', 'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $light->id, 'status' => 'available',
        'team_leader_id' => $lightLeader->id, 'driver_name' => 'VT Light Driver',
    ]);
    $heavyUnit = Unit::create([
        'name' => 'VT Heavy Unit', 'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $heavy->id, 'status' => 'available',
        'team_leader_id' => $heavyLeader->id, 'driver_name' => 'VT Heavy Driver',
    ]);

    $service = app(\App\Services\UnitAvailabilityService::class);
    $eligible = $service->availableUnitIdsForTruckType($light->id);

    expect($eligible)->toContain($lightUnit->id);
    expect($eligible)->not->toContain($heavyUnit->id);
});

it('does not automatically substitute a larger truck type for a smaller required one', function () {
    $light = vttTruckType('SubLight');
    $heavy = vttTruckType('SubHeavy');

    Unit::create([
        'name' => 'VT Sub Heavy Unit Only', 'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $heavy->id, 'status' => 'available',
    ]);

    $service = app(\App\Services\UnitAvailabilityService::class);
    $eligible = $service->availableUnitIdsForTruckType($light->id);

    expect($eligible)->toBe([]);
});

it('freezes the booking own base_rate and per_km_rate even if the truck type rates change later', function () {
    [$user, $customer] = vttCustomer();
    $truckType = vttTruckType('RateChange');
    $vehicleType = vttVehicleType($truckType->id);
    vttReadyUnit($truckType);
    Sanctum::actingAs($user, ['*']);

    test()->postJson('/api/v1/bookings', vttBookingPayload([
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $vehicleType->id,
    ]))->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    $originalBaseRate = (float) $booking->base_rate;

    $truckType->update(['base_rate' => 99999, 'per_km_rate' => 99999]);

    expect((float) $booking->fresh()->base_rate)->toBe($originalBaseRate);
    expect((float) $booking->fresh()->base_rate)->not->toBe(99999.0);
});

it('requires a new vehicle type to have a required truck type at the application-validation level', function () {
    $owner = User::factory()->create(['role_id' => vttRole(1, 'Owner')->id, 'status' => 'active', 'must_change_password' => false]);

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VT No Truck Type ' . uniqid(),
        'category' => '4_wheeler',
        'weight_kg' => 1200,
    ]);

    $response->assertSessionHasErrors('required_truck_type_id');
    expect(VehicleType::where('name', 'like', 'VT No Truck Type%')->exists())->toBeFalse();
});

it('requires an edited vehicle type to keep a required truck type at the application-validation level', function () {
    $owner = User::factory()->create(['role_id' => vttRole(1, 'Owner')->id, 'status' => 'active', 'must_change_password' => false]);
    $truckType = vttTruckType('EditGuard');
    $vehicleType = vttVehicleType($truckType->id);

    $response = test()->actingAs($owner)->put(route('superadmin.vehicle-types.update', $vehicleType), [
        'name' => $vehicleType->name,
        'category' => '4_wheeler',
        'weight_kg' => 1200,
    ]);

    $response->assertSessionHasErrors('required_truck_type_id');
    expect($vehicleType->fresh()->required_truck_type_id)->toBe($truckType->id);
});

it('allows creating a vehicle type with a valid required truck type', function () {
    $owner = User::factory()->create(['role_id' => vttRole(1, 'Owner')->id, 'status' => 'active', 'must_change_password' => false]);
    $truckType = vttTruckType('ValidCreate');

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VT Valid Create ' . uniqid(),
        'category' => '4_wheeler',
        'weight_kg' => 1200,
        'required_truck_type_id' => $truckType->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    $created = VehicleType::where('name', 'like', 'VT Valid Create%')->first();
    expect($created)->not->toBeNull();
    expect($created->required_truck_type_id)->toBe($truckType->id);
});

it('derives the extra vehicle truck type from its vehicle type instead of trusting the client value', function () {
    [$user, $customer] = vttCustomer();
    $primaryTruck = vttTruckType('ExtraPrimary');
    $primaryVehicle = vttVehicleType($primaryTruck->id);

    $extraLightTruck = vttTruckType('ExtraLight');
    $extraLightVehicle = vttVehicleType($extraLightTruck->id);
    $extraHeavyTruck = vttTruckType('ExtraHeavy');
    vttReadyUnit($primaryTruck);
    vttReadyUnit($extraLightTruck);

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'vehicle_type_id' => $primaryVehicle->id,
        'extra_vehicles' => json_encode([
            [
                'vehicle_type_id' => $extraLightVehicle->id,
                'truck_type_id' => $extraHeavyTruck->id,
                'service_type' => 'book_now',
            ],
        ]),
        'extra_vehicle_images' => [0 => [UploadedFile::fake()->image('extra.jpg')]],
    ]));

    $response->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    $storedExtras = $booking->extra_vehicles;

    expect($storedExtras)->not->toBeEmpty();
    expect((int) $storedExtras[0]['truck_type_id'])->toBe($extraLightTruck->id);
    expect((int) $storedExtras[0]['truck_type_id'])->not->toBe($extraHeavyTruck->id);
});

it('uses the mapped extra vehicle rate server-side, ignoring a spoofed cheaper truck type', function () {
    [$user, $customer] = vttCustomer();
    $primaryTruck = vttTruckType('ExtraRatePrimary');
    $primaryVehicle = vttVehicleType($primaryTruck->id);

    $cheapTruck = vttTruckType('ExtraCheap');
    $cheapTruck->update(['base_rate' => 100, 'per_km_rate' => 10]);
    $expensiveTruck = vttTruckType('ExtraExpensive');
    $expensiveTruck->update(['base_rate' => 8000, 'per_km_rate' => 400]);
    $expensiveVehicle = vttVehicleType($expensiveTruck->id);
    vttReadyUnit($primaryTruck);
    vttReadyUnit($expensiveTruck);

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'vehicle_type_id' => $primaryVehicle->id,
        'extra_vehicles' => json_encode([
            [
                'vehicle_type_id' => $expensiveVehicle->id,
                'truck_type_id' => $cheapTruck->id,
                'service_type' => 'book_now',
            ],
        ]),
        'extra_vehicle_images' => [0 => [UploadedFile::fake()->image('extra.jpg')]],
    ]));

    $response->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    $storedExtras = $booking->extra_vehicles;

    $expensiveDistanceFee = round(max(0, (float) $booking->distance_km - 4.0) * 400, 2);
    $expensiveTotal = round((8000 + $expensiveDistanceFee) * 1.12, 2);
    $cheapDistanceFee = round(max(0, (float) $booking->distance_km - 4.0) * 10, 2);
    $cheapTotal = round((100 + $cheapDistanceFee) * 1.12, 2);

    expect((float) $storedExtras[0]['estimated_price'])->toBe($expensiveTotal);
    expect((float) $storedExtras[0]['estimated_price'])->not->toBe($cheapTotal);
});

it('does not let a tampered cheaper extra vehicle truck type reduce the sibling scheduled booking price', function () {
    [$user, $customer] = vttCustomer();
    $primaryTruck = vttTruckType('SiblingPrimary');
    $primaryVehicle = vttVehicleType($primaryTruck->id);

    $cheapTruck = vttTruckType('SiblingCheap');
    $cheapTruck->update(['base_rate' => 200, 'per_km_rate' => 10]);
    $expensiveTruck = vttTruckType('SiblingExpensive');
    $expensiveTruck->update(['base_rate' => 7000, 'per_km_rate' => 300]);
    $expensiveVehicle = vttVehicleType($expensiveTruck->id);

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'vehicle_type_id' => $primaryVehicle->id,
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'extra_vehicles' => json_encode([
            [
                'vehicle_type_id' => $expensiveVehicle->id,
                'truck_type_id' => $cheapTruck->id,
            ],
        ]),
        'extra_vehicle_images' => [0 => [UploadedFile::fake()->image('extra.jpg')]],
    ]));

    $response->assertCreated();

    $sibling = Booking::where('customer_id', $customer->id)->where('truck_type_id', $expensiveTruck->id)->latest()->first();
    $spoofedSibling = Booking::where('customer_id', $customer->id)->where('truck_type_id', $cheapTruck->id)->exists();

    expect($sibling)->not->toBeNull();
    expect((float) $sibling->base_rate)->toBe(7000.0);
    expect($spoofedSibling)->toBeFalse();
});

it('rejects an extra vehicle missing a vehicle_type_id', function () {
    [$user] = vttCustomer();
    $primaryTruck = vttTruckType('MissingExtraPrimary');
    $primaryVehicle = vttVehicleType($primaryTruck->id);
    $someTruck = vttTruckType('MissingExtraTruck');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'vehicle_type_id' => $primaryVehicle->id,
        'extra_vehicles' => json_encode([
            ['truck_type_id' => $someTruck->id, 'service_type' => 'book_now'],
        ]),
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('rejects an extra vehicle whose vehicle type has no configured required truck type', function () {
    [$user] = vttCustomer();
    $primaryTruck = vttTruckType('UnmappedExtraPrimary');
    $primaryVehicle = vttVehicleType($primaryTruck->id);
    $unmappedVehicle = vttVehicleType(null);

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'vehicle_type_id' => $primaryVehicle->id,
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $unmappedVehicle->id, 'service_type' => 'book_now'],
        ]),
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('rejects an extra vehicle whose vehicle type is inactive', function () {
    [$user] = vttCustomer();
    $primaryTruck = vttTruckType('InactiveExtraPrimary');
    $primaryVehicle = vttVehicleType($primaryTruck->id);
    $extraTruck = vttTruckType('InactiveExtraSecondary');
    $extraVehicle = vttVehicleType($extraTruck->id);
    $extraVehicle->update(['status' => 'inactive']);

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'vehicle_type_id' => $primaryVehicle->id,
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id, 'service_type' => 'book_now'],
        ]),
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('rejects an extra vehicle with an invalid vehicle_type_id', function () {
    [$user] = vttCustomer();
    $primaryTruck = vttTruckType('InvalidExtraPrimary');
    $primaryVehicle = vttVehicleType($primaryTruck->id);

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', vttBookingPayload([
        'vehicle_type_id' => $primaryVehicle->id,
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => 999999, 'service_type' => 'book_now'],
        ]),
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});
