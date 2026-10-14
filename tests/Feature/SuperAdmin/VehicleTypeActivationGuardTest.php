<?php

/*
 * An active vehicle type is offered to customers, so its required truck type
 * must be active (dispatchable). The reverse direction (disabling a truck type
 * that active vehicle types need) is already refused by TruckTypeController.
 */

use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleType;

function vtgOwner(): User
{
    $role = Role::find(1) ?: tap(new Role(['name' => 'Owner']), function ($r) {
        $r->id = 1;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'status' => 'active', 'must_change_password' => false]);
}

function vtgTruckType(string $status = 'active'): TruckType
{
    return TruckType::create([
        'name' => 'VTG Truck ' . uniqid(), 'class' => 'light', 'base_rate' => 1500, 'per_km_rate' => 60,
        'max_tonnage' => 4500, 'status' => $status,
    ]);
}

function vtgVehicle(TruckType $truckType, string $status): VehicleType
{
    $vehicle = VehicleType::create([
        'name' => 'VTG Vehicle ' . uniqid(), 'category' => 'cars_suvs', 'weight_kg' => null,
        'status' => $status, 'display_order' => 0, 'required_truck_type_id' => $truckType->id,
    ]);
    $vehicle->truckTypes()->sync([$truckType->id]);

    return $vehicle;
}

it('refuses to activate a vehicle type whose required truck type is disabled', function () {
    $owner = vtgOwner();
    $vehicle = vtgVehicle(vtgTruckType('inactive'), 'inactive');

    $this->actingAs($owner)->patch(route('superadmin.vehicle-types.toggle', $vehicle))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'is disabled'));

    expect($vehicle->fresh()->status)->toBe('inactive');
});

it('activates a vehicle type whose required truck type is active, last in its category', function () {
    $owner = vtgOwner();
    $vehicle = vtgVehicle(vtgTruckType(), 'inactive');

    $this->actingAs($owner)->patch(route('superadmin.vehicle-types.toggle', $vehicle))->assertSessionHas('success');

    expect($vehicle->fresh()->status)->toBe('active');
});

it('still lets an owner deactivate a vehicle type whose truck type is disabled', function () {
    $owner = vtgOwner();
    $vehicle = vtgVehicle(vtgTruckType('inactive'), 'active');

    $this->actingAs($owner)->patch(route('superadmin.vehicle-types.toggle', $vehicle))->assertSessionHas('success');

    expect($vehicle->fresh()->status)->toBe('inactive');
});

it('refuses to save an active vehicle type that points at a disabled truck type, but not an inactive one', function () {
    $owner = vtgOwner();
    $disabled = vtgTruckType('inactive');
    $active = vtgVehicle(vtgTruckType(), 'active');
    $inactive = vtgVehicle(vtgTruckType(), 'inactive');
    $payload = fn ($vehicle, $name) => ['name' => $name, 'category' => 'cars_suvs', 'required_truck_type_id' => $disabled->id];

    $this->actingAs($owner)->put(route('superadmin.vehicle-types.update', $active), $payload($active, 'VTG Renamed Active'))
        ->assertSessionHasErrors('required_truck_type_id');
    expect($active->fresh()->required_truck_type_id)->not->toBe($disabled->id)->and($active->fresh()->name)->not->toBe('VTG Renamed Active');

    $this->actingAs($owner)->put(route('superadmin.vehicle-types.update', $inactive), $payload($inactive, 'VTG Renamed Inactive'))
        ->assertSessionHasNoErrors();
    expect($inactive->fresh()->required_truck_type_id)->toBe($disabled->id);
});

it('refuses to create a new (active) vehicle type for a disabled truck type', function () {
    $owner = vtgOwner();
    $disabled = vtgTruckType('inactive');

    $this->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VTG New ' . uniqid(), 'category' => 'cars_suvs', 'required_truck_type_id' => $disabled->id,
    ])->assertSessionHasErrors('required_truck_type_id');

    expect(VehicleType::where('required_truck_type_id', $disabled->id)->count())->toBe(0);
});

it('keeps saving an active vehicle type on an active truck type, with weight compatibility unchanged', function () {
    $owner = vtgOwner();
    $truckType = vtgTruckType();
    $vehicle = vtgVehicle($truckType, 'active');

    $this->actingAs($owner)->put(route('superadmin.vehicle-types.update', $vehicle), [
        'name' => 'VTG Saved Fine', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'weight_kg' => '1300',
    ])->assertSessionHasNoErrors();

    expect($vehicle->fresh()->name)->toBe('VTG Saved Fine');
});
