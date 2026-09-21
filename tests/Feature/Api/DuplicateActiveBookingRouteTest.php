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

function dabrRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function dabrCustomer(): array
{
    $user = User::factory()->create(['role_id' => dabrRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function dabrTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'DABR Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function dabrVehicleType(int $truckTypeId): VehicleType
{
    return VehicleType::create([
        'name' => 'DABR Vehicle ' . fake()->unique()->word(),
        'category' => '4_wheeler',
        'required_truck_type_id' => $truckTypeId,
        'status' => 'active',
    ]);
}

function dabrReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => dabrRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'DABR Ready Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'DABR Ready Driver',
    ]);
}

const DABR_NOVALICHES_LAT = 14.7357;
const DABR_NOVALICHES_LNG = 121.0362;
const DABR_FAIRVIEW_LAT = 14.7385;
const DABR_FAIRVIEW_LNG = 121.0662;
const DABR_OTHER_LAT = 14.5547;
const DABR_OTHER_LNG = 121.0244;

function dabrExistingBooking(Customer $customer, TruckType $truckType, string $status, string $serviceType = 'book_now'): Booking
{
    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'SM Novaliches',
        'pickup_lat' => DABR_NOVALICHES_LAT,
        'pickup_lng' => DABR_NOVALICHES_LNG,
        'dropoff_address' => 'SM Fairview',
        'dropoff_lat' => DABR_FAIRVIEW_LAT,
        'dropoff_lng' => DABR_FAIRVIEW_LNG,
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'final_total' => 2000,
        'status' => $status,
        'service_type' => $serviceType,
    ]);
}

function dabrNewRequestPayload(TruckType $truckType, VehicleType $vehicleType, array $overrides = []): array
{
    return array_merge([
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $vehicleType->id,
        'pickup_address' => 'SM Novaliches',
        'pickup_lat' => DABR_NOVALICHES_LAT,
        'pickup_lng' => DABR_NOVALICHES_LNG,
        'dropoff_address' => 'SM Fairview',
        'dropoff_lat' => DABR_FAIRVIEW_LAT,
        'dropoff_lng' => DABR_FAIRVIEW_LNG,
        'distance_km' => 5,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ], $overrides);
}

it('blocks a new Book Now request for the same route as an existing active Book Now booking', function () {
    [$user, $customer] = dabrCustomer();
    $truckType = dabrTruckType();
    $vehicleType = dabrVehicleType($truckType->id);
    dabrExistingBooking($customer, $truckType, 'requested', 'book_now');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', dabrNewRequestPayload($truckType, $vehicleType));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('active Book Now booking');
    expect(Booking::where('customer_id', $customer->id)->count())->toBe(1);
});

it('allows a Schedule Later request for the same route as an existing active Book Now booking', function () {
    [$user, $customer] = dabrCustomer();
    $truckType = dabrTruckType();
    $vehicleType = dabrVehicleType($truckType->id);
    dabrExistingBooking($customer, $truckType, 'requested', 'book_now');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', dabrNewRequestPayload($truckType, $vehicleType, [
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '13:00',
    ]));

    $response->assertCreated();
    expect(Booking::where('customer_id', $customer->id)->count())->toBe(2);
});

it('allows a new Book Now request for the same route once the previous booking is cancelled', function () {
    [$user, $customer] = dabrCustomer();
    $truckType = dabrTruckType();
    $vehicleType = dabrVehicleType($truckType->id);
    dabrReadyUnit($truckType);
    dabrExistingBooking($customer, $truckType, 'cancelled', 'book_now');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', dabrNewRequestPayload($truckType, $vehicleType));

    $response->assertCreated();
    expect(Booking::where('customer_id', $customer->id)->count())->toBe(2);
});

it('allows a new Book Now request when the existing active booking has a different route', function () {
    [$user, $customer] = dabrCustomer();
    $truckType = dabrTruckType();
    $vehicleType = dabrVehicleType($truckType->id);
    dabrReadyUnit($truckType);
    dabrExistingBooking($customer, $truckType, 'requested', 'book_now');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', dabrNewRequestPayload($truckType, $vehicleType, [
        'dropoff_address' => 'Trinoma',
        'dropoff_lat' => DABR_OTHER_LAT,
        'dropoff_lng' => DABR_OTHER_LNG,
    ]));

    $response->assertCreated();
    expect(Booking::where('customer_id', $customer->id)->count())->toBe(2);
});

it('does not treat other terminal statuses as an active duplicate', function (string $terminalStatus) {
    [$user, $customer] = dabrCustomer();
    $truckType = dabrTruckType();
    $vehicleType = dabrVehicleType($truckType->id);
    dabrReadyUnit($truckType);
    dabrExistingBooking($customer, $truckType, $terminalStatus, 'book_now');
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', dabrNewRequestPayload($truckType, $vehicleType));

    $response->assertCreated();
})->with(['completed', 'rejected', 'not_responding']);

it('rejects a request where pickup and drop-off are the same location', function () {
    [$user] = dabrCustomer();
    $truckType = dabrTruckType();
    $vehicleType = dabrVehicleType($truckType->id);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', dabrNewRequestPayload($truckType, $vehicleType, [
        'dropoff_address' => 'SM Novaliches Entrance 2',
        'dropoff_lat' => DABR_NOVALICHES_LAT,
        'dropoff_lng' => DABR_NOVALICHES_LNG,
    ]));

    $response->assertStatus(422);
    expect($response->json('message'))->toBe('Pickup and drop-off locations must be different.');
    expect(Booking::count())->toBe(0);
});
