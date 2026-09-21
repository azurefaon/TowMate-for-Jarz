<?php

use App\Models\Booking;
use App\Models\BookingVehiclePhoto;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function bvpRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function bvpCustomer(): array
{
    $user = User::factory()->create(['role_id' => bvpRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function bvpTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'BVP Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function bvpVehicleType(?int $requiredTruckTypeId): VehicleType
{
    return VehicleType::create([
        'name' => 'BVP Vehicle ' . fake()->unique()->word(),
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function bvpReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => bvpRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'BVP Ready Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'BVP Ready Driver',
    ]);
}

function bvpImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("vehicle-$i.jpg", 300, 300), range(1, $count));
}

function bvpBasePayload(VehicleType $primaryVehicle, array $overrides = []): array
{
    return array_merge([
        'vehicle_type_id' => $primaryVehicle->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
    ], $overrides);
}

it('rejects booking creation when the primary vehicle has no photo', function () {
    [$user] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('rejects booking creation when an extra vehicle has no photo', function () {
    [$user] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    $extraTruck = bvpTruckType();
    $extraVehicle = bvpVehicleType($extraTruck->id);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle, [
        'vehicle_images' => bvpImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id, 'service_type' => 'book_now'],
        ]),
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('accepts a booking with exactly 1 photo for the primary vehicle', function () {
    [$user, $customer] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    bvpReadyUnit($truck);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle, [
        'vehicle_images' => bvpImages(1),
    ]));

    $response->assertCreated();
    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    expect(BookingVehiclePhoto::where('booking_id', $booking->id)->where('vehicle_slot', 0)->count())->toBe(1);
});

it('accepts a booking with the maximum of 5 photos for the primary vehicle', function () {
    [$user, $customer] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    bvpReadyUnit($truck);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle, [
        'vehicle_images' => bvpImages(5),
    ]));

    $response->assertCreated();
    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    expect(BookingVehiclePhoto::where('booking_id', $booking->id)->where('vehicle_slot', 0)->count())->toBe(5);
});

it('rejects a 6th photo for a single vehicle', function () {
    [$user] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle, [
        'vehicle_images' => bvpImages(6),
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('rejects an invalid non-image file as a vehicle photo', function () {
    [$user] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle, [
        'vehicle_images' => [UploadedFile::fake()->create('not-an-image.pdf', 100)],
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('keeps separate photo sets for the primary vehicle and each Book Now extra vehicle', function () {
    [$user, $customer] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    bvpReadyUnit($truck);
    $extraTruck = bvpTruckType();
    $extraVehicle = bvpVehicleType($extraTruck->id);
    bvpReadyUnit($extraTruck);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle, [
        'vehicle_images' => bvpImages(2),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id, 'service_type' => 'book_now'],
        ]),
        'extra_vehicle_images' => [0 => bvpImages(3)],
    ]));

    $response->assertCreated();
    $primaryBooking = Booking::where('customer_id', $customer->id)->where('truck_type_id', $truck->id)->latest()->first();
    $sibling = Booking::where('customer_id', $customer->id)->where('truck_type_id', $extraTruck->id)->latest()->first();

    $primaryPhotos = BookingVehiclePhoto::where('booking_id', $primaryBooking->id)->where('vehicle_slot', 0)->get();
    $extraPhotos = BookingVehiclePhoto::where('booking_id', $sibling->id)->where('vehicle_slot', 0)->get();

    expect($primaryPhotos)->toHaveCount(2);
    expect($extraPhotos)->toHaveCount(3);
    expect($primaryPhotos->pluck('path')->intersect($extraPhotos->pluck('path')))->toBeEmpty();
});

it('stores the Book Now extra vehicle photos on its own sibling booking row', function () {
    [$user, $customer] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    bvpReadyUnit($truck);
    $extraTruck = bvpTruckType();
    $extraVehicle = bvpVehicleType($extraTruck->id);
    bvpReadyUnit($extraTruck);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle, [
        'vehicle_images' => bvpImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id, 'service_type' => 'book_now'],
        ]),
        'extra_vehicle_images' => [0 => bvpImages(2)],
    ]));

    $response->assertCreated();
    $sibling = Booking::where('customer_id', $customer->id)->where('truck_type_id', $extraTruck->id)->latest()->first();

    expect(Booking::where('customer_id', $customer->id)->count())->toBe(2);
    expect($sibling)->not->toBeNull();
    expect(BookingVehiclePhoto::where('booking_id', $sibling->id)->where('vehicle_slot', 0)->count())->toBe(2);
});

it('stores the Scheduled sibling vehicle photos on its own sibling booking row', function () {
    [$user, $customer] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    $extraTruck = bvpTruckType();
    $extraVehicle = bvpVehicleType($extraTruck->id);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle, [
        'vehicle_images' => bvpImages(1),
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id],
        ]),
        'extra_vehicle_images' => [0 => bvpImages(4)],
    ]));

    $response->assertCreated();

    $primaryBooking = Booking::where('customer_id', $customer->id)->where('truck_type_id', $truck->id)->latest()->first();
    $sibling = Booking::where('customer_id', $customer->id)->where('truck_type_id', $extraTruck->id)->latest()->first();

    expect($sibling)->not->toBeNull();
    expect(BookingVehiclePhoto::where('booking_id', $sibling->id)->where('vehicle_slot', 0)->count())->toBe(4);
    expect(BookingVehiclePhoto::where('booking_id', $primaryBooking->id)->where('vehicle_slot', 1)->count())->toBe(0);
});

it('still enforces the maximum of 6 vehicles with photos supplied for every vehicle', function () {
    [$user] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    Sanctum::actingAs($user, ['*']);

    $extraVehicles = [];
    $extraImages = [];
    for ($i = 0; $i < 6; $i++) {
        $t = bvpTruckType();
        $v = bvpVehicleType($t->id);
        $extraVehicles[] = ['vehicle_type_id' => $v->id, 'service_type' => 'book_now'];
        $extraImages[$i] = bvpImages(1);
    }

    $response = test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle, [
        'vehicle_images' => bvpImages(1),
        'extra_vehicles' => json_encode($extraVehicles),
        'extra_vehicle_images' => $extraImages,
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

it('rejects unsigned direct access to a stored vehicle photo path', function () {
    [$user, $customer] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);
    bvpReadyUnit($truck);
    Sanctum::actingAs($user, ['*']);

    test()->postJson('/api/v1/bookings', bvpBasePayload($vehicle, [
        'vehicle_images' => bvpImages(1),
    ]))->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    $photo = BookingVehiclePhoto::where('booking_id', $booking->id)->first();

    expect(Storage::disk('local')->exists($photo->path))->toBeTrue();

    test()->get('/protected-storage/' . $photo->path)->assertStatus(403);
});

it('keeps a legacy booking vehicle_image_path readable without a booking_vehicle_photos row', function () {
    [, $customer] = bvpCustomer();
    $truck = bvpTruckType();
    $vehicle = bvpVehicleType($truck->id);

    $legacyBooking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'vehicle_type_id' => $vehicle->id,
        'pickup_address' => 'Legacy origin',
        'pickup_lat' => 14.5, 'pickup_lng' => 121.0,
        'dropoff_address' => 'Legacy destination',
        'dropoff_lat' => 14.6, 'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1500,
        'final_total' => 1680,
        'status' => 'completed',
        'service_type' => 'book_now',
        'confirmation_type' => 'mobile',
        'vehicle_image_path' => json_encode(['vehicle_images/legacy-photo.jpg']),
    ]);

    expect($legacyBooking->vehicle_image_paths)->toBe(['vehicle_images/legacy-photo.jpg']);
    expect(BookingVehiclePhoto::where('booking_id', $legacyBooking->id)->count())->toBe(0);
});
