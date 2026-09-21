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

function vtfRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function vtfTruckType(string $name, string $class): TruckType
{
    return TruckType::create([
        'name' => $name,
        'class' => $class,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'max_tonnage' => match ($class) {
            'light' => 4500,
            'medium' => 7500,
            default => 999999,
        },
        'status' => 'active',
    ]);
}

function vtfThreeTruckTypes(): array
{
    return [
        vtfTruckType('Light Duty', 'light'),
        vtfTruckType('Medium Duty', 'medium'),
        vtfTruckType('Heavy Duty', 'heavy'),
    ];
}

function vtfCustomerUser(): array
{
    $user = User::factory()->create(['role_id' => vtfRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return [$user, $customer];
}

function vtfReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => vtfRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'VTF Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'VTF Driver',
    ]);
}

function vtfMigration()
{
    return require database_path('migrations/2026_09_20_220000_finalize_vehicle_type_tow_class_mapping.php');
}

it('assigns sedan and compact suv to light duty, pickup truck and van to medium duty', function () {
    [$light, $medium, $heavy] = vtfThreeTruckTypes();

    $sedan = VehicleType::create([
        'name' => 'Sedan', 'category' => '4_wheeler', 'weight_kg' => 7500,
        'required_truck_type_id' => $medium->id, 'display_order' => 5, 'status' => 'active',
    ]);
    $pickup = VehicleType::create([
        'name' => 'Pickup Truck', 'category' => '4_wheeler', 'weight_kg' => 7501,
        'required_truck_type_id' => $heavy->id, 'display_order' => 10, 'status' => 'active',
    ]);

    vtfMigration()->up();

    $sedan->refresh();
    $pickup->refresh();

    expect($sedan->required_truck_type_id)->toBe($light->id);
    expect($pickup->required_truck_type_id)->toBe($medium->id);

    $compactSuv = VehicleType::where('name', 'Compact SUV')->first();
    $van = VehicleType::where('name', 'Van')->first();

    expect($compactSuv->required_truck_type_id)->toBe($light->id);
    expect($compactSuv->category)->toBe('cars_suvs');
    expect($van->required_truck_type_id)->toBe($medium->id);
    expect($van->category)->toBe('pickups_vans');
});

it('assigns bus, 10-wheeler truck, and cement mixer to heavy duty only', function () {
    [$light, $medium, $heavy] = vtfThreeTruckTypes();

    $bus = VehicleType::create([
        'name' => 'Bus', 'category' => 'heavy_vehicle', 'required_truck_type_id' => $heavy->id,
        'display_order' => 13, 'status' => 'active',
    ]);
    $tenWheeler = VehicleType::create([
        'name' => '10-Wheeler Truck', 'category' => 'trucks', 'required_truck_type_id' => $heavy->id,
        'display_order' => 60, 'status' => 'active',
    ]);

    vtfMigration()->up();

    expect($bus->fresh()->required_truck_type_id)->toBe($heavy->id);
    expect($bus->fresh()->category)->toBe('other_heavy');
    expect($tenWheeler->fresh()->required_truck_type_id)->toBe($heavy->id);
    expect($tenWheeler->fresh()->category)->toBe('trucks');

    $cementMixer = VehicleType::where('name', 'Cement Mixer')->first();
    expect($cementMixer)->not->toBeNull();
    expect($cementMixer->required_truck_type_id)->toBe($heavy->id);
    expect($cementMixer->category)->toBe('trucks');
});

it('deactivates the legacy van slash l300 record instead of deleting it once van exists', function () {
    [$light, $medium, $heavy] = vtfThreeTruckTypes();

    $legacyVan = VehicleType::create([
        'name' => 'Van / L300', 'category' => '4_wheeler', 'required_truck_type_id' => $medium->id,
        'display_order' => 11, 'status' => 'active',
    ]);

    vtfMigration()->up();

    $legacyVan->refresh();

    expect($legacyVan->status)->toBe('inactive');
    expect($legacyVan->category)->toBe('4_wheeler');
    expect($legacyVan->required_truck_type_id)->toBe($medium->id);
    expect(VehicleType::where('status', 'active')->where('name', 'Van / L300')->exists())->toBeFalse();
});

it('preserves the booking foreign key to sedan through the tow-class finalization migration', function () {
    [$light, $medium, $heavy] = vtfThreeTruckTypes();
    [, $customer] = vtfCustomerUser();

    $sedan = VehicleType::create([
        'name' => 'Sedan', 'category' => '4_wheeler', 'weight_kg' => 7500,
        'required_truck_type_id' => $medium->id, 'display_order' => 5, 'status' => 'active',
    ]);

    $booking = tap(new Booking())->forceFill([
        'customer_id' => $customer->id,
        'truck_type_id' => $medium->id,
        'vehicle_type_id' => $sedan->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00, 'status' => 'completed',
    ]);
    $booking->save();

    vtfMigration()->up();

    expect($booking->fresh()->vehicle_type_id)->toBe($sedan->id);
    expect($booking->fresh()->vehicleType->name)->toBe('Sedan');
    expect($sedan->fresh()->required_truck_type_id)->toBe($light->id);
});

it('reverts the tow-class finalization migration cleanly', function () {
    [$light, $medium, $heavy] = vtfThreeTruckTypes();

    $sedan = VehicleType::create([
        'name' => 'Sedan', 'category' => '4_wheeler', 'weight_kg' => 7500,
        'required_truck_type_id' => $medium->id, 'display_order' => 5, 'status' => 'active',
    ]);
    $pickup = VehicleType::create([
        'name' => 'Pickup Truck', 'category' => '4_wheeler', 'weight_kg' => 7501,
        'required_truck_type_id' => $heavy->id, 'display_order' => 10, 'status' => 'active',
    ]);
    $legacyVan = VehicleType::create([
        'name' => 'Van / L300', 'category' => '4_wheeler', 'required_truck_type_id' => $medium->id,
        'display_order' => 11, 'status' => 'active',
    ]);
    $bus = VehicleType::create([
        'name' => 'Bus', 'category' => 'heavy_vehicle', 'required_truck_type_id' => $heavy->id,
        'display_order' => 13, 'status' => 'active',
    ]);

    $migration = vtfMigration();
    $migration->up();
    $migration->down();

    expect($sedan->fresh()->required_truck_type_id)->toBe($medium->id);
    expect((float) $sedan->fresh()->weight_kg)->toEqualWithDelta(7500, 0.01);
    expect($pickup->fresh()->required_truck_type_id)->toBe($heavy->id);
    expect((float) $pickup->fresh()->weight_kg)->toEqualWithDelta(7501, 0.01);
    expect($legacyVan->fresh()->status)->toBe('active');
    expect($bus->fresh()->category)->toBe('heavy_vehicle');
    expect(VehicleType::where('name', 'Compact SUV')->exists())->toBeFalse();
    expect(VehicleType::where('name', 'Van')->exists())->toBeFalse();
    expect(VehicleType::where('name', 'Cement Mixer')->exists())->toBeFalse();
});

it('rejects a heavy duty book_now request instead of silently substituting a ready medium duty unit', function () {
    [$light, $medium, $heavy] = vtfThreeTruckTypes();
    [$user, $customer] = vtfCustomerUser();

    $heavyVehicle = VehicleType::create([
        'name' => 'VTF Heavy Vehicle', 'category' => 'trucks', 'required_truck_type_id' => $heavy->id,
        'display_order' => 0, 'status' => 'active',
    ]);
    vtfReadyUnit($medium);

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $heavyVehicle->id,
        'service_type' => 'book_now',
        'pickup_address' => 'Origin', 'pickup_lat' => 14.5, 'pickup_lng' => 121.0,
        'dropoff_address' => 'Destination', 'dropoff_lat' => 14.6, 'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);

    $response->assertStatus(422);
    expect(Booking::where('customer_id', $customer->id)->where('truck_type_id', $medium->id)->exists())->toBeFalse();
    expect(Booking::where('customer_id', $customer->id)->exists())->toBeFalse();
});

it('retains the heavy duty tow class when the customer explicitly schedules instead of book_now', function () {
    [$light, $medium, $heavy] = vtfThreeTruckTypes();
    [$user, $customer] = vtfCustomerUser();

    $heavyVehicle = VehicleType::create([
        'name' => 'VTF Heavy Vehicle', 'category' => 'trucks', 'required_truck_type_id' => $heavy->id,
        'display_order' => 0, 'status' => 'active',
    ]);
    vtfReadyUnit($medium);

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $heavyVehicle->id,
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'pickup_address' => 'Origin', 'pickup_lat' => 14.5, 'pickup_lng' => 121.0,
        'dropoff_address' => 'Destination', 'dropoff_lat' => 14.6, 'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);

    $response->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();

    expect($booking->truck_type_id)->toBe($heavy->id);
    expect($booking->truck_type_id)->not->toBe($medium->id);
    expect($booking->service_type)->toBe('schedule');
});

it('keeps a light duty booking on book_now when a light duty unit is ready', function () {
    [$light, $medium, $heavy] = vtfThreeTruckTypes();
    [$user, $customer] = vtfCustomerUser();

    $lightVehicle = VehicleType::create([
        'name' => 'VTF Light Vehicle', 'category' => 'cars_suvs', 'required_truck_type_id' => $light->id,
        'display_order' => 0, 'status' => 'active',
    ]);
    vtfReadyUnit($light);

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $lightVehicle->id,
        'pickup_address' => 'Origin', 'pickup_lat' => 14.5, 'pickup_lng' => 121.0,
        'dropoff_address' => 'Destination', 'dropoff_lat' => 14.6, 'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);

    $response->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();

    expect($booking->truck_type_id)->toBe($light->id);
    expect($booking->service_type)->toBe('book_now');
});

it('never lists a busier truck class as ready for a required class with zero ready units', function () {
    [$light, $medium, $heavy] = vtfThreeTruckTypes();
    vtfReadyUnit($medium);
    vtfReadyUnit($light);

    $ready = app(BookingService::class)->dispatchAvailability()['ready_truck_type_ids'];

    expect($ready)->toContain($medium->id);
    expect($ready)->toContain($light->id);
    expect($ready)->not->toContain($heavy->id);
});
