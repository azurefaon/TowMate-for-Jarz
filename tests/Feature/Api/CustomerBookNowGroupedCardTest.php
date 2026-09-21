<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

function bngRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function bngCustomer(): array
{
    $user = User::factory()->create(['role_id' => bngRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function bngTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'BNG Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function bngVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function bngReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => bngRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'BNG Ready Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'BNG Ready Driver',
    ]);
}

function bngImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("bng-vehicle-$i.jpg", 300, 300), range(1, $count));
}

it('returns exactly one booking card for a single-vehicle Book Now request', function () {
    [$user] = bngCustomer();
    $truck = bngTruckType();
    bngReadyUnit($truck);
    $vehicle = bngVehicleType($truck->id, 'BNG Solo Sedan');

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
        'vehicle_images' => bngImages(1),
    ]);

    $response->assertCreated();
    expect($response->json('bookings'))->toHaveCount(1);
    expect($response->json('group_code'))->toBeNull();
    expect($response->json('group_vehicle_count'))->toBe(1);
    expect($response->json('group_siblings'))->toBe([]);

    $booking = Booking::where('booking_code', $response->json('booking_code'))->first();
    expect(Booking::where('customer_id', $booking->customer_id)->count())->toBe(1);
});

it('returns one parent booking card with all vehicles grouped for a 3-vehicle Book Now request, while dispatch still gets an independent row per vehicle', function () {
    [$user] = bngCustomer();

    $truckPrimary = bngTruckType();
    bngReadyUnit($truckPrimary);
    $vehiclePrimary = bngVehicleType($truckPrimary->id, 'BNG Vehicle Primary');

    $truckExtra1 = bngTruckType();
    bngReadyUnit($truckExtra1);
    $vehicleExtra1 = bngVehicleType($truckExtra1->id, 'BNG Vehicle Extra 1');

    $truckExtra2 = bngTruckType();
    bngReadyUnit($truckExtra2);
    $vehicleExtra2 = bngVehicleType($truckExtra2->id, 'BNG Vehicle Extra 2');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $vehiclePrimary->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'book_now',
        'vehicle_images' => bngImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleExtra1->id],
            ['vehicle_type_id' => $vehicleExtra2->id],
        ]),
        'extra_vehicle_images' => [
            0 => bngImages(1),
            1 => bngImages(1),
        ],
    ]);

    $response->assertCreated();

    $bookings = $response->json('bookings');
    expect($bookings)->toHaveCount(1);
    expect($bookings[0]['booking_code'])->toBe($response->json('booking_code'));

    expect($response->json('group_vehicle_count'))->toBe(3);
    expect($response->json('group_siblings'))->toHaveCount(2);

    $groupCode = $response->json('group_code');
    expect($groupCode)->not->toBeNull();

    $groupBookings = Booking::where('group_code', $groupCode)->orderBy('id')->get();
    expect($groupBookings)->toHaveCount(3);
    expect($groupBookings->pluck('vehicle_type_id')->unique())->toHaveCount(3);
    expect($groupBookings->pluck('status')->unique()->all())->toBe(['requested']);

    $primaryBooking = Booking::where('booking_code', $response->json('booking_code'))->first();
    expect($primaryBooking->quotation_id)->not->toBeNull();
    expect(Quotation::where('id', $primaryBooking->quotation_id)->count())->toBe(1);

    $quotation = Quotation::find($primaryBooking->quotation_id);
    expect($quotation->extra_vehicles)->toHaveCount(2);
});
