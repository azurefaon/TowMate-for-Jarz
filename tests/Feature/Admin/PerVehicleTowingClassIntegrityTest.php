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

function ptcRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ptcDispatcher(): User
{
    return User::factory()->create(['role_id' => ptcRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function ptcCustomer(): array
{
    $user = User::factory()->create(['role_id' => ptcRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function ptcTruckType(float $baseRate, float $perKmRate, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function ptcVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function ptcReadyUnit(TruckType $truckType, string $label): Unit
{
    $leader = User::factory()->create(['role_id' => ptcRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'PTC Driver',
    ]);
}

function ptcImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("ptc-vehicle-$i.jpg", 300, 300), range(1, $count));
}

it('keeps each vehicle on its own towing class and rates from mobile submission through the quotation, and rejects a mismatched unit at dispatch without disturbing the other vehicles', function () {
    [$user] = ptcCustomer();

    $truckLight = ptcTruckType(1500, 60, 'PTC Light Duty');
    $unitLight = ptcReadyUnit($truckLight, 'PTC Light Unit');
    $vehicleSedan = ptcVehicleType($truckLight->id, 'PTC Sedan');

    $truckMedium = ptcTruckType(2500, 100, 'PTC Medium Duty');
    $unitMedium = ptcReadyUnit($truckMedium, 'PTC Medium Unit');
    $vehicleVan = ptcVehicleType($truckMedium->id, 'PTC Van');

    $truckHeavy = ptcTruckType(4000, 150, 'PTC Heavy Duty');
    $unitHeavy = ptcReadyUnit($truckHeavy, 'PTC Heavy Unit');
    $vehicleTruck = ptcVehicleType($truckHeavy->id, 'PTC Box Truck');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $vehicleSedan->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'book_now',
        'vehicle_images' => ptcImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleVan->id],
            ['vehicle_type_id' => $vehicleTruck->id],
        ]),
        'extra_vehicle_images' => [
            0 => ptcImages(1),
            1 => ptcImages(1),
        ],
    ]);

    $response->assertCreated();
    $groupCode = $response->json('group_code');
    expect($groupCode)->not->toBeNull();

    $groupBookings = Booking::where('group_code', $groupCode)->orderBy('id')->get();
    expect($groupBookings)->toHaveCount(3);

    $primary = $groupBookings->firstWhere('vehicle_type_id', $vehicleSedan->id);
    $siblingVan = $groupBookings->firstWhere('vehicle_type_id', $vehicleVan->id);
    $siblingTruck = $groupBookings->firstWhere('vehicle_type_id', $vehicleTruck->id);

    expect($primary->truck_type_id)->toBe($truckLight->id);
    expect((float) $primary->base_rate)->toBe(1500.0);
    expect($siblingVan->truck_type_id)->toBe($truckMedium->id);
    expect((float) $siblingVan->base_rate)->toBe(2500.0);
    expect($siblingTruck->truck_type_id)->toBe($truckHeavy->id);
    expect((float) $siblingTruck->base_rate)->toBe(4000.0);

    $quotation = Quotation::find($primary->quotation_id);
    expect($quotation)->not->toBeNull();

    $lineItemFor = fn (int $bookingId) => collect($quotation->extra_vehicles)->firstWhere('booking_id', $bookingId);
    expect((int) $lineItemFor($siblingVan->id)['truck_type_id'])->toBe($truckMedium->id);
    expect((float) $lineItemFor($siblingVan->id)['base_rate'])->toBe(2500.0);
    expect((int) $lineItemFor($siblingTruck->id)['truck_type_id'])->toBe($truckHeavy->id);
    expect((float) $lineItemFor($siblingTruck->id)['base_rate'])->toBe(4000.0);

    $groupBookings->each(fn ($b) => $b->update([
        'status' => 'confirmed',
        'customer_approved_at' => now(),
        'price_locked_at' => now(),
    ]));
    $quotation->update(['status' => 'accepted']);

    $dispatcher = ptcDispatcher();

    $mismatchedAssign = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $siblingVan), [
        'action' => 'accept',
        'assigned_unit_id' => $unitLight->id,
        'distance_km' => '12',
        'distance_fee' => '480',
    ]);
    $mismatchedAssign->assertStatus(422);
    expect($siblingVan->fresh()->status)->not->toBe('assigned');

    $primaryAssign = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $primary), [
        'action' => 'accept',
        'assigned_unit_id' => $unitLight->id,
        'distance_km' => '12',
        'distance_fee' => '480',
    ]);
    $primaryAssign->assertOk()->assertJson(['success' => true]);

    $vanAssign = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $siblingVan), [
        'action' => 'accept',
        'assigned_unit_id' => $unitMedium->id,
        'distance_km' => '12',
        'distance_fee' => '800',
    ]);
    $vanAssign->assertOk()->assertJson(['success' => true]);

    $truckAssign = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $siblingTruck), [
        'action' => 'accept',
        'assigned_unit_id' => $unitHeavy->id,
        'distance_km' => '12',
        'distance_fee' => '1200',
    ]);
    $truckAssign->assertOk()->assertJson(['success' => true]);

    expect($primary->fresh()->assigned_unit_id)->toBe($unitLight->id);
    expect($primary->fresh()->truck_type_id)->toBe($truckLight->id);
    expect($siblingVan->fresh()->assigned_unit_id)->toBe($unitMedium->id);
    expect($siblingVan->fresh()->truck_type_id)->toBe($truckMedium->id);
    expect($siblingTruck->fresh()->assigned_unit_id)->toBe($unitHeavy->id);
    expect($siblingTruck->fresh()->truck_type_id)->toBe($truckHeavy->id);
});
