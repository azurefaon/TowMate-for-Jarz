<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleType;

function vdpRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function vdpOwner(): User
{
    return User::factory()->create(['role_id' => vdpRole(1, 'Owner')->id, 'status' => 'active', 'must_change_password' => false]);
}

function vdpTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'VDP Truck ' . fake()->unique()->word(),
        'class' => 'light',
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'max_tonnage' => 4500,
        'status' => 'active',
    ]);
}

function vdpVehicleType(array $overrides = []): VehicleType
{
    return VehicleType::create(array_merge([
        'name' => 'VDP Vehicle ' . fake()->unique()->word(),
        'category' => 'cars_suvs',
        'weight_kg' => 1500,
        'status' => 'active',
        'display_order' => 0,
    ], $overrides));
}

function vdpOrderedActiveNames(): array
{
    return VehicleType::where('status', 'active')
        ->orderBy('display_order')
        ->orderBy('name')
        ->pluck('name')
        ->all();
}

it('assigns a strict, deterministic rank even when display_order values are tied', function () {
    $owner = vdpOwner();
    $a = vdpVehicleType(['name' => 'VDP Tie A', 'display_order' => 5]);
    $b = vdpVehicleType(['name' => 'VDP Tie B', 'display_order' => 5]);
    $c = vdpVehicleType(['name' => 'VDP Tie C', 'display_order' => 5]);

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $positions = $response->viewData('positionByVehicleId');

    expect($positions[$a->id])->toBe($positions[$b->id] - 1);
    expect($positions[$b->id])->toBe($positions[$c->id] - 1);
});

it('creates a vehicle type at the end of the active list by default', function () {
    $owner = vdpOwner();
    $truckType = vdpTruckType();
    vdpVehicleType(['name' => 'VDP Existing First', 'display_order' => 10]);

    $response = test()->actingAs($owner)->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VDP New Default',
        'category' => 'cars_suvs',
        'weight_kg' => 1400,
        'required_truck_type_id' => $truckType->id,
    ]);

    $response->assertSessionDoesntHaveErrors();
    expect(vdpOrderedActiveNames())->toBe(['VDP Existing First', 'VDP New Default']);
});

it('does not change positions merely by viewing the table through search or category filters', function () {
    $owner = vdpOwner();
    $a = vdpVehicleType(['name' => 'VDP Filter A', 'category' => 'cars_suvs', 'display_order' => 10]);
    $b = vdpVehicleType(['name' => 'VDP Filter B', 'category' => 'trucks', 'display_order' => 20]);

    $unfiltered = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'))
        ->viewData('positionByVehicleId');

    $filtered = test()->actingAs($owner)
        ->get(route('superadmin.vehicle-types.index', ['category' => 'cars_suvs']))
        ->viewData('positionByVehicleId');

    expect($filtered[$a->id])->toBe($unfiltered[$a->id]);
    expect($filtered[$b->id])->toBe($unfiltered[$b->id]);
});

it('closes the gap for remaining active vehicles when one is deactivated', function () {
    $owner = vdpOwner();
    $first = vdpVehicleType(['name' => 'VDP Gap First', 'display_order' => 10]);
    $middle = vdpVehicleType(['name' => 'VDP Gap Middle', 'display_order' => 20]);
    $last = vdpVehicleType(['name' => 'VDP Gap Last', 'display_order' => 30]);

    test()->actingAs($owner)->patch(route('superadmin.vehicle-types.toggle', $middle))->assertRedirect();

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $positions = $response->viewData('positionByVehicleId');

    expect($positions)->not->toHaveKey($middle->id);
    expect($positions[$first->id])->toBe(1);
    expect($positions[$last->id])->toBe(2);
});

it('appends a reactivated vehicle type to the end of the active list', function () {
    $owner = vdpOwner();
    vdpVehicleType(['name' => 'VDP Reactivate First', 'display_order' => 10]);
    vdpVehicleType(['name' => 'VDP Reactivate Second', 'display_order' => 20]);
    $reactivated = vdpVehicleType(['name' => 'VDP Reactivate Me', 'status' => 'inactive', 'display_order' => 5]);

    test()->actingAs($owner)->patch(route('superadmin.vehicle-types.toggle', $reactivated))->assertRedirect();

    expect(vdpOrderedActiveNames())->toBe(['VDP Reactivate First', 'VDP Reactivate Second', 'VDP Reactivate Me']);
});
