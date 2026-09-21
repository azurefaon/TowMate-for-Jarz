<?php

use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleType;

function vwoRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function vwoOwner(): User
{
    return User::factory()->create(['role_id' => vwoRole(1, 'Owner')->id, 'status' => 'active', 'must_change_password' => false]);
}

function vwoTruckType(array $overrides = []): TruckType
{
    return TruckType::create(array_merge([
        'name' => 'VWO Truck ' . fake()->unique()->word(),
        'class' => 'light',
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'max_tonnage' => 4500,
        'status' => 'active',
    ], $overrides));
}

function vwoVehicleType(array $overrides = []): VehicleType
{
    return VehicleType::create(array_merge([
        'name' => 'VWO Vehicle ' . fake()->unique()->word(),
        'category' => 'cars_suvs',
        'weight_kg' => null,
        'status' => 'active',
        'display_order' => 0,
    ], $overrides));
}

it('creates a vehicle type with a null weight when the field is omitted entirely', function () {
    $owner = vwoOwner();
    $truckType = vwoTruckType();

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VWO Omitted Weight ' . uniqid(),
        'category' => 'cars_suvs',
        'required_truck_type_id' => $truckType->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    $created = VehicleType::where('name', 'like', 'VWO Omitted Weight%')->first();
    expect($created)->not->toBeNull();
    expect($created->weight_kg)->toBeNull();
});

it('creates a vehicle type with a null weight when the field is submitted blank', function () {
    $owner = vwoOwner();
    $truckType = vwoTruckType();

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VWO Blank Weight ' . uniqid(),
        'category' => 'cars_suvs',
        'weight_kg' => '',
        'required_truck_type_id' => $truckType->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    $created = VehicleType::where('name', 'like', 'VWO Blank Weight%')->first();
    expect($created)->not->toBeNull();
    expect($created->weight_kg)->toBeNull();
});

it('does not validate a null weight against the required truck type, since null is not proof of capacity', function () {
    $owner = vwoOwner();
    $heavyOnly = vwoTruckType(['class' => 'heavy', 'max_tonnage' => 999999]);

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VWO Null Weight Heavy ' . uniqid(),
        'category' => 'trucks',
        'required_truck_type_id' => $heavyOnly->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    $created = VehicleType::where('name', 'like', 'VWO Null Weight Heavy%')->first();
    expect($created)->not->toBeNull();
    expect($created->weight_kg)->toBeNull();
    expect($created->required_truck_type_id)->toBe($heavyOnly->id);
});

it('rejects a provided weight that is incompatible with the selected required truck type', function () {
    $owner = vwoOwner();
    $heavyOnly = vwoTruckType(['class' => 'heavy', 'max_tonnage' => 999999]);

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VWO Incompatible Weight ' . uniqid(),
        'category' => 'cars_suvs',
        'weight_kg' => 1400,
        'required_truck_type_id' => $heavyOnly->id,
    ]);

    $response->assertSessionHasErrors('weight_kg');
    expect(VehicleType::where('name', 'like', 'VWO Incompatible Weight%')->exists())->toBeFalse();
});

it('accepts a provided weight that is compatible with the selected required truck type', function () {
    $owner = vwoOwner();
    $lightTruck = vwoTruckType(['class' => 'light', 'max_tonnage' => 4500]);

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VWO Compatible Weight ' . uniqid(),
        'category' => 'cars_suvs',
        'weight_kg' => 1300,
        'required_truck_type_id' => $lightTruck->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    $created = VehicleType::where('name', 'like', 'VWO Compatible Weight%')->first();
    expect((float) $created->weight_kg)->toEqualWithDelta(1300, 0.01);
});

it('edits a vehicle type to clear a previously-set weight back to null', function () {
    $owner = vwoOwner();
    $truckType = vwoTruckType();
    $target = vwoVehicleType(['weight_kg' => 1500, 'required_truck_type_id' => $truckType->id]);

    $response = test()->actingAs($owner)->put(route('superadmin.vehicle-types.update', $target), [
        'name' => $target->name,
        'category' => $target->category,
        'weight_kg' => '',
        'required_truck_type_id' => $truckType->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    expect($target->fresh()->weight_kg)->toBeNull();
});

it('edits a vehicle type that already has a null weight without being forced to enter one', function () {
    $owner = vwoOwner();
    $truckType = vwoTruckType();
    $target = vwoVehicleType(['weight_kg' => null, 'required_truck_type_id' => $truckType->id]);

    $response = test()->actingAs($owner)->put(route('superadmin.vehicle-types.update', $target), [
        'name' => 'VWO Renamed Null Weight',
        'category' => $target->category,
        'required_truck_type_id' => $truckType->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    $target->refresh();
    expect($target->weight_kg)->toBeNull();
    expect($target->name)->toBe('VWO Renamed Null Weight');
});

it('rejects creating a vehicle type without a required truck type', function () {
    $owner = vwoOwner();

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VWO No Required Truck ' . uniqid(),
        'category' => 'cars_suvs',
    ]);

    $response->assertSessionHasErrors('required_truck_type_id');
    expect(VehicleType::where('name', 'like', 'VWO No Required Truck%')->exists())->toBeFalse();
});

it('rejects editing a vehicle type to remove its required truck type', function () {
    $owner = vwoOwner();
    $truckType = vwoTruckType();
    $target = vwoVehicleType(['required_truck_type_id' => $truckType->id]);

    $response = test()->actingAs($owner)->put(route('superadmin.vehicle-types.update', $target), [
        'name' => $target->name,
        'category' => $target->category,
    ]);

    $response->assertSessionHasErrors('required_truck_type_id');
    expect($target->fresh()->required_truck_type_id)->toBe($truckType->id);
});

it('syncs the pivot to exactly the required truck type when creating a vehicle type', function () {
    $owner = vwoOwner();
    $truckType = vwoTruckType();

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VWO Pivot Create ' . uniqid(),
        'category' => 'cars_suvs',
        'required_truck_type_id' => $truckType->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    $created = VehicleType::where('name', 'like', 'VWO Pivot Create%')->first();
    expect($created->truckTypes->pluck('id')->all())->toBe([$truckType->id]);
});

it('resyncs the pivot to only the new required truck type when it changes, dropping the old one', function () {
    $owner = vwoOwner();
    $light = vwoTruckType(['class' => 'light', 'max_tonnage' => 4500]);
    $medium = vwoTruckType(['class' => 'medium', 'max_tonnage' => 7500]);
    $target = vwoVehicleType(['required_truck_type_id' => $light->id]);
    $target->truckTypes()->sync([$light->id]);

    $response = test()->actingAs($owner)->put(route('superadmin.vehicle-types.update', $target), [
        'name' => $target->name,
        'category' => $target->category,
        'required_truck_type_id' => $medium->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    $target->refresh();
    expect($target->required_truck_type_id)->toBe($medium->id);
    expect($target->truckTypes->pluck('id')->all())->toBe([$medium->id]);
});

it('never leaves the pivot with more than the one required truck type after a save', function () {
    $owner = vwoOwner();
    $light = vwoTruckType(['class' => 'light', 'max_tonnage' => 4500]);
    $heavy = vwoTruckType(['class' => 'heavy', 'max_tonnage' => 999999]);
    $target = vwoVehicleType(['required_truck_type_id' => $light->id]);
    $target->truckTypes()->sync([$light->id, $heavy->id]);

    expect($target->truckTypes->pluck('id')->sort()->values()->all())->toBe([$light->id, $heavy->id]);

    test()->actingAs($owner)->put(route('superadmin.vehicle-types.update', $target), [
        'name' => $target->name,
        'category' => $target->category,
        'required_truck_type_id' => $light->id,
    ])->assertSessionDoesntHaveErrors();

    $target->refresh();
    expect($target->truckTypes->pluck('id')->all())->toBe([$light->id]);
});
