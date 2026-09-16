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

function gbuRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function gbuCustomer(): array
{
    $user = User::factory()->create(['role_id' => gbuRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function gbuTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'GBU Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function gbuVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function gbuReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => gbuRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'GBU Ready Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'GBU Ready Driver',
    ]);
}

function gbuImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("vehicle-$i.jpg", 300, 300), range(1, $count));
}

function gbuCreateScheduledGroupRequest(User $user): array
{
    $primaryTruck = gbuTruckType();
    $primaryVehicle = gbuVehicleType($primaryTruck->id, 'GBU Sedan');

    $extraTruck = gbuTruckType();
    $extraVehicle = gbuVehicleType($extraTruck->id, 'GBU Motorcycle');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $primaryVehicle->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '13:00',
        'vehicle_images' => gbuImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id],
        ]),
        'extra_vehicle_images' => [0 => gbuImages(1)],
    ]);

    return [$response, $primaryVehicle, $extraVehicle];
}

function gbuCreateBookNowRequest(User $user): array
{
    $truck = gbuTruckType();
    gbuReadyUnit($truck);
    $vehicle = gbuVehicleType($truck->id, 'GBU Book Now Sedan');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $vehicle->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'book_now',
        'vehicle_images' => gbuImages(1),
    ]);

    return [$response, $vehicle];
}

it('createBooking returns a booking summary for every created booking in an all-Scheduled multi-vehicle request', function () {
    [$user] = gbuCustomer();
    [$response, $primaryVehicle, $extraVehicle] = gbuCreateScheduledGroupRequest($user);

    $response->assertCreated();
    $bookings = $response->json('bookings');
    expect($bookings)->toHaveCount(2);

    expect($bookings[0]['booking_code'])->toBe($response->json('booking_code'));
    expect($bookings[0]['service_type'])->toBe('schedule');
    expect($bookings[0]['status'])->toBe('scheduled');
    expect($bookings[0]['vehicle_type_name'])->toBe($primaryVehicle->name);
    expect($bookings[0]['is_current'])->toBeTrue();

    expect($bookings[1]['service_type'])->toBe('schedule');
    expect($bookings[1]['status'])->toBe('scheduled');
    expect($bookings[1]['vehicle_type_name'])->toBe($extraVehicle->name);
    expect($bookings[1]['scheduled_date'])->not->toBeNull();
    expect($bookings[1]['is_current'])->toBeFalse();
});

it('createBooking returns exactly one booking summary for a single-vehicle request', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateBookNowRequest($user);

    $response->assertCreated();
    expect($response->json('bookings'))->toHaveCount(1);
    expect($response->json('group_code'))->toBeNull();
});

it('currentBooking exposes group_vehicle_count and siblings for an all-Scheduled group', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $current = test()->getJson('/api/v1/bookings/current');
    $current->assertOk();
    expect($current->json('data.booking_code'))->toBeIn([$primaryCode, $siblingCode]);
    expect($current->json('data.group_vehicle_count'))->toBe(2);
    expect($current->json('data.group_siblings'))->toHaveCount(1);
});

it('currentBooking prioritizes an active in-progress job over a Book Now Requested booking', function () {
    [$user, $customer] = gbuCustomer();
    $truck = gbuTruckType();
    $vehicle = gbuVehicleType($truck->id, 'GBU Active Vehicle');

    $requested = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'vehicle_type_id' => $vehicle->id,
        'pickup_address' => 'A', 'pickup_lat' => 14.5, 'pickup_lng' => 120.9,
        'dropoff_address' => 'B', 'dropoff_lat' => 14.6, 'dropoff_lng' => 120.9,
        'distance_km' => 5, 'base_rate' => 1500, 'per_km_rate' => 60,
        'computed_total' => 1500, 'final_total' => 1680,
        'status' => 'requested', 'service_type' => 'book_now',
        'confirmation_type' => 'mobile',
    ]);
    $requested->update(['booking_code' => 'TM-' . str_pad($requested->id, 5, '0', STR_PAD_LEFT)]);

    $active = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'vehicle_type_id' => $vehicle->id,
        'pickup_address' => 'A', 'pickup_lat' => 14.5, 'pickup_lng' => 120.9,
        'dropoff_address' => 'B', 'dropoff_lat' => 14.6, 'dropoff_lng' => 120.9,
        'distance_km' => 5, 'base_rate' => 1500, 'per_km_rate' => 60,
        'computed_total' => 1500, 'final_total' => 1680,
        'status' => 'in_progress', 'service_type' => 'book_now',
        'confirmation_type' => 'mobile',
    ]);
    $active->update(['booking_code' => 'TM-' . str_pad($active->id, 5, '0', STR_PAD_LEFT)]);

    Sanctum::actingAs($user, ['*']);
    $current = test()->getJson('/api/v1/bookings/current');
    $current->assertOk();
    expect($current->json('data.booking_code'))->toBe($active->booking_code);
});

it('cancelling one booking in a Scheduled group leaves its sibling untouched', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $cancel = test()->postJson("/api/v1/bookings/{$siblingCode}/cancel");
    $cancel->assertOk();

    $siblingBooking = Booking::where('booking_code', $siblingCode)->first();
    $primaryBooking = Booking::where('booking_code', $primaryCode)->first();

    expect($siblingBooking->status)->toBe('cancelled');
    expect($primaryBooking->status)->toBe('scheduled');
});

it('detail marks a Scheduled sibling pricing as provisional and omits a misleading Distance Fee', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $detail = test()->getJson("/api/v1/bookings/{$siblingCode}/detail");
    $detail->assertOk();
    expect($detail->json('data.pricing_is_provisional'))->toBeTrue();
    expect($detail->json('data.distance_fee'))->toBeNull();
});

it('detail keeps canonical Distance Fee for a Book Now booking', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateBookNowRequest($user);
    $response->assertCreated();
    $bookNowCode = $response->json('booking_code');

    $detail = test()->getJson("/api/v1/bookings/{$bookNowCode}/detail");
    $detail->assertOk();
    expect($detail->json('data.pricing_is_provisional'))->toBeFalse();
    expect($detail->json('data.distance_fee'))->not->toBeNull();
    expect((float) $detail->json('data.distance_fee'))->toBeGreaterThan(0);
});

it('detail exposes group_siblings for a grouped booking and an empty list for a standalone one', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $detail = test()->getJson("/api/v1/bookings/{$primaryCode}/detail");
    $detail->assertOk();
    expect($detail->json('data.group_siblings'))->toHaveCount(1);
    expect($detail->json('data.group_siblings.0.booking_code'))->toBe($siblingCode);

    [$soloUser] = gbuCustomer();
    [$soloResponse] = gbuCreateBookNowRequest($soloUser);
    $soloResponse->assertCreated();
    $soloDetail = test()->getJson("/api/v1/bookings/{$soloResponse->json('booking_code')}/detail");
    $soloDetail->assertOk();
    expect($soloDetail->json('data.group_siblings'))->toBe([]);
});

it('bookingHistory returns the customer-facing vehicle_type_name for each booking', function () {
    [$user] = gbuCustomer();
    [$response, $primaryVehicle] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();

    $history = test()->getJson('/api/v1/bookings/history');
    $history->assertOk();
    $names = collect($history->json('data'))->pluck('vehicle_type_name');
    expect($names)->toContain($primaryVehicle->name);
});
