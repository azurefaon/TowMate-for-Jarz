<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Support\Facades\DB;

function vcp1Role(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function vcp1Owner(): User
{
    return User::factory()->create(['role_id' => vcp1Role(1, 'Owner')->id, 'status' => 'active', 'must_change_password' => false]);
}

function vcp1TruckType(array $overrides = []): TruckType
{
    return TruckType::create(array_merge([
        'name' => 'VCP1 Truck ' . fake()->unique()->word(),
        'class' => 'light',
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'max_tonnage' => 4500,
        'status' => 'active',
    ], $overrides));
}

function vcp1VehicleType(array $overrides = []): VehicleType
{
    return VehicleType::create(array_merge([
        'name' => 'VCP1 Vehicle ' . fake()->unique()->word(),
        'category' => 'cars_suvs',
        'weight_kg' => 1500,
        'status' => 'active',
        'display_order' => 0,
    ], $overrides));
}

function vcp1RecategorizeMigration()
{
    return require database_path('migrations/2026_09_20_210200_recategorize_and_reorder_common_vehicle_types.php');
}

function vcp1Customer(): Customer
{
    return Customer::create([
        'full_name' => 'VCP1 Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'vcp1-' . uniqid() . '@example.com',
    ]);
}

it('accepts each of the four required categories when creating a vehicle type', function (string $category) {
    $truckType = vcp1TruckType();

    $vehicleType = vcp1VehicleType(['category' => $category, 'required_truck_type_id' => $truckType->id]);

    expect($vehicleType->fresh()->category)->toBe($category);
})->with(['cars_suvs', 'pickups_vans', 'trucks', 'other_heavy']);

it('still accepts the legacy category values for records not yet migrated', function (string $category) {
    $vehicleType = vcp1VehicleType(['category' => $category]);

    expect($vehicleType->fresh()->category)->toBe($category);
})->with(['2_wheeler', '4_wheeler', 'heavy_vehicle']);

it('relies on the vehicle_categories lookup table, not a database constraint, to police category values', function () {
    DB::table('vehicle_types')->insert([
        'name' => 'VCP1 Bad Category',
        'category' => 'not_a_real_category',
        'weight_kg' => 1000,
        'display_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(VehicleType::where('name', 'VCP1 Bad Category')->exists())->toBeTrue();
});

it('rejects an unrecognized category value through the owner store endpoint', function () {
    $owner = vcp1Owner();
    $truckType = vcp1TruckType();

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VCP1 Invalid Category ' . uniqid(),
        'category' => 'not_a_real_category',
        'weight_kg' => 1200,
        'required_truck_type_id' => $truckType->id,
    ]);

    $response->assertSessionHasErrors('category');
    expect(VehicleType::where('name', 'like', 'VCP1 Invalid Category%')->exists())->toBeFalse();
});

it('exposes the human-readable label for each of the four required categories', function (string $category, string $label) {
    $vehicleType = vcp1VehicleType(['category' => $category]);

    expect($vehicleType->category_label)->toBe($label);
})->with([
    ['cars_suvs', 'Cars & SUVs'],
    ['pickups_vans', 'Pickups & Vans'],
    ['trucks', 'Trucks'],
    ['other_heavy', 'Other / Heavy Vehicles'],
]);

it('orders vehicle types by display_order ascending within a category', function () {
    $truckType = vcp1TruckType();
    $last = vcp1VehicleType(['category' => 'trucks', 'display_order' => 70, 'required_truck_type_id' => $truckType->id]);
    $first = vcp1VehicleType(['category' => 'trucks', 'display_order' => 10, 'required_truck_type_id' => $truckType->id]);
    $middle = vcp1VehicleType(['category' => 'trucks', 'display_order' => 40, 'required_truck_type_id' => $truckType->id]);

    $ordered = VehicleType::where('category', 'trucks')->orderBy('display_order')->pluck('id')->all();

    expect($ordered)->toBe([$first->id, $middle->id, $last->id]);
});

it('recategorizes exact-match vehicle types without changing their id, weight, or required truck type', function () {
    $light = vcp1TruckType(['class' => 'light', 'max_tonnage' => 4500]);
    $medium = vcp1TruckType(['class' => 'medium', 'max_tonnage' => 7500]);

    $sedan = VehicleType::create([
        'name' => 'Sedan',
        'category' => '4_wheeler',
        'weight_kg' => 1500,
        'required_truck_type_id' => $light->id,
        'display_order' => 5,
        'status' => 'active',
    ]);
    $unrelated = VehicleType::create([
        'name' => 'VCP1 Untouched Vehicle',
        'category' => '4_wheeler',
        'weight_kg' => 2000,
        'required_truck_type_id' => $medium->id,
        'display_order' => 99,
        'status' => 'active',
    ]);

    vcp1RecategorizeMigration()->up();

    $sedan->refresh();
    $unrelated->refresh();

    expect($sedan->category)->toBe('cars_suvs');
    expect($sedan->display_order)->toBe(10);
    expect($sedan->weight_kg)->toEqualWithDelta(1500, 0.01);
    expect($sedan->required_truck_type_id)->toBe($light->id);

    expect($unrelated->category)->toBe('4_wheeler');
    expect($unrelated->display_order)->toBe(99);
});

it('reverts recategorized vehicle types to their original category and display order on rollback', function () {
    $light = vcp1TruckType(['class' => 'light', 'max_tonnage' => 4500]);

    $pickup = VehicleType::create([
        'name' => 'Pickup Truck',
        'category' => '4_wheeler',
        'weight_kg' => 2800,
        'required_truck_type_id' => $light->id,
        'display_order' => 10,
        'status' => 'active',
    ]);

    $migration = vcp1RecategorizeMigration();
    $migration->up();
    $migration->down();

    $pickup->refresh();

    expect($pickup->category)->toBe('4_wheeler');
    expect($pickup->display_order)->toBe(10);
});

it('preserves booking references to a vehicle type through the recategorization migration', function () {
    $light = vcp1TruckType(['class' => 'light', 'max_tonnage' => 4500]);
    $customer = vcp1Customer();

    $hatchback = VehicleType::create([
        'name' => 'Hatchback',
        'category' => '4_wheeler',
        'weight_kg' => 1100,
        'required_truck_type_id' => $light->id,
        'display_order' => 6,
        'status' => 'active',
    ]);

    $booking = tap(new Booking())->forceFill([
        'customer_id' => $customer->id,
        'truck_type_id' => $light->id,
        'vehicle_type_id' => $hatchback->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'completed',
    ]);
    $booking->save();

    vcp1RecategorizeMigration()->up();

    expect($booking->fresh()->vehicle_type_id)->toBe($hatchback->id);
    expect($booking->fresh()->vehicleType->name)->toBe('Hatchback');
    expect($hatchback->fresh()->category)->toBe('cars_suvs');
});

it('still enforces weight-compatibility guards when saving a vehicle type under a required category', function () {
    $owner = vcp1Owner();
    $heavyOnly = vcp1TruckType(['class' => 'heavy', 'max_tonnage' => 999]);

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VCP1 Light Car ' . uniqid(),
        'category' => 'cars_suvs',
        'weight_kg' => 1400,
        'required_truck_type_id' => $heavyOnly->id,
    ]);

    $response->assertSessionHasErrors('weight_kg');
    expect(VehicleType::where('name', 'like', 'VCP1 Light Car%')->exists())->toBeFalse();
});

it('allows a required-category vehicle type to be saved with a weight-compatible truck type', function () {
    $owner = vcp1Owner();
    $lightTruck = vcp1TruckType(['class' => 'light', 'max_tonnage' => 4500]);

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VCP1 Compact SUV Candidate ' . uniqid(),
        'category' => 'cars_suvs',
        'weight_kg' => 1300,
        'required_truck_type_id' => $lightTruck->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    $created = VehicleType::where('name', 'like', 'VCP1 Compact SUV Candidate%')->first();
    expect($created)->not->toBeNull();
    expect($created->category)->toBe('cars_suvs');
    expect($created->truckTypes->pluck('id')->all())->toBe([$lightTruck->id]);
});
