<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Models\VehicleType;

function vaoRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function vaoOwner(): User
{
    return User::factory()->create(['role_id' => vaoRole(1, 'Owner')->id, 'status' => 'active', 'must_change_password' => false]);
}

function vaoTruckType(array $overrides = []): TruckType
{
    return TruckType::create(array_merge([
        'name' => 'VAO Truck ' . fake()->unique()->word(),
        'class' => 'light',
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'max_tonnage' => 4500,
        'status' => 'active',
    ], $overrides));
}

function vaoVehicleType(array $overrides = []): VehicleType
{
    return VehicleType::create(array_merge([
        'name' => 'VAO Vehicle ' . fake()->unique()->word(),
        'category' => 'cars_suvs',
        'weight_kg' => 1500,
        'status' => 'active',
        'display_order' => 0,
    ], $overrides));
}

function vaoOrderedActiveNames(): array
{
    return VehicleType::where('status', 'active')
        ->orderBy('display_order')
        ->orderBy('name')
        ->pluck('name')
        ->all();
}

it('does not render a separate arrange order modal anymore', function () {
    $owner = vaoOwner();

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->not->toContain('id="arrangeModal"');
    expect($content)->not->toContain('vc-arrange-tree');
    expect($content)->not->toContain('vc-arrange-item');
});

it('scopes each vehicle list to a single truck type and category for drag reordering', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    vaoVehicleType(['name' => 'VAO Scoped Vehicle', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 10]);

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->toContain('data-truck-type-id="' . $truckType->id . '"');
    expect($content)->toContain('data-category="cars_suvs"');
});

it('still hides truck types with no matching results while a filter is active', function () {
    $owner = vaoOwner();
    $withVehicles = vaoTruckType(['name' => 'VAO Filter Match']);
    vaoVehicleType(['required_truck_type_id' => $withVehicles->id, 'name' => 'VAO Findable']);
    $empty = vaoTruckType(['name' => 'VAO Filter Empty']);

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index', ['search' => 'Findable']));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->toContain('data-truck-type-id="' . $withVehicles->id . '"');
    expect($content)->not->toContain('data-truck-type-id="' . $empty->id . '"');
});

it('points the owner layout favicon at the working JARZ logo instead of the deleted default', function () {
    $owner = vaoOwner();

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->toContain('rel="icon"');
    expect($content)->toContain('dispatcher/images/jarz-logo.png');
    expect($content)->not->toContain('admin/images/logo.png');
    expect(substr_count($content, 'rel="icon"'))->toBe(1);
});

it('creates a new category with a generated slug', function () {
    $owner = vaoOwner();

    $response = test()->actingAs($owner)->postJson(route('superadmin.vehicle-categories.store'), [
        'name' => 'Construction Equipment',
    ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);

    $category = VehicleCategory::where('name', 'Construction Equipment')->first();
    expect($category)->not->toBeNull();
    expect($category->slug)->toBe('construction_equipment');
});

it('rejects creating a category with a duplicate name', function () {
    $owner = vaoOwner();
    VehicleCategory::create(['slug' => 'vao_existing', 'name' => 'VAO Existing Category']);

    $response = test()->actingAs($owner)->postJson(route('superadmin.vehicle-categories.store'), [
        'name' => 'VAO Existing Category',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('name');
});

it('renames an existing category without changing its slug or existing assignments', function () {
    $owner = vaoOwner();
    $category = VehicleCategory::create(['slug' => 'vao_rename_me', 'name' => 'VAO Old Name']);
    $vehicle = vaoVehicleType(['category' => 'vao_rename_me']);

    $response = test()->actingAs($owner)->putJson(route('superadmin.vehicle-categories.update', $category), [
        'name' => 'VAO New Name',
    ]);

    $response->assertOk();
    $category->refresh();
    expect($category->slug)->toBe('vao_rename_me');
    expect($category->name)->toBe('VAO New Name');

    expect($vehicle->fresh()->category)->toBe('vao_rename_me');
    expect($vehicle->fresh()->category_label)->toBe('VAO New Name');
});

it('rejects renaming a category to a name already used by another category', function () {
    $owner = vaoOwner();
    VehicleCategory::create(['slug' => 'vao_taken', 'name' => 'VAO Taken Name']);
    $category = VehicleCategory::create(['slug' => 'vao_other', 'name' => 'VAO Other Name']);

    $response = test()->actingAs($owner)->putJson(route('superadmin.vehicle-categories.update', $category), [
        'name' => 'VAO Taken Name',
    ]);

    $response->assertStatus(422);
    expect($category->fresh()->name)->toBe('VAO Other Name');
});

it('reorders vehicles within a single group via the reorder endpoint', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    $a = vaoVehicleType(['name' => 'VAO Order A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 10]);
    $b = vaoVehicleType(['name' => 'VAO Order B', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 20]);
    $c = vaoVehicleType(['name' => 'VAO Order C', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 30]);

    $response = test()->actingAs($owner)->patchJson(route('superadmin.vehicle-types.reorder'), [
        'groups' => [
            [
                'required_truck_type_id' => $truckType->id,
                'category' => 'cars_suvs',
                'vehicle_ids' => [$c->id, $a->id, $b->id],
            ],
        ],
    ]);

    $response->assertOk();
    expect(vaoOrderedActiveNames())->toBe(['VAO Order C', 'VAO Order A', 'VAO Order B']);
});

it('does not disturb vehicles outside the reordered group', function () {
    $owner = vaoOwner();
    $light = vaoTruckType(['class' => 'light']);
    $heavy = vaoTruckType(['class' => 'heavy']);
    $a = vaoVehicleType(['name' => 'VAO Mixed A', 'category' => 'cars_suvs', 'required_truck_type_id' => $light->id, 'display_order' => 10]);
    $b = vaoVehicleType(['name' => 'VAO Mixed B', 'category' => 'trucks', 'required_truck_type_id' => $heavy->id, 'display_order' => 20]);
    $c = vaoVehicleType(['name' => 'VAO Mixed C', 'category' => 'cars_suvs', 'required_truck_type_id' => $light->id, 'display_order' => 30]);

    test()->actingAs($owner)->patchJson(route('superadmin.vehicle-types.reorder'), [
        'groups' => [
            [
                'required_truck_type_id' => $light->id,
                'category' => 'cars_suvs',
                'vehicle_ids' => [$c->id, $a->id],
            ],
        ],
    ])->assertOk();

    expect(vaoOrderedActiveNames())->toBe(['VAO Mixed C', 'VAO Mixed B', 'VAO Mixed A']);
});

it('rejects a reorder request with a duplicate vehicle id', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    $a = vaoVehicleType(['name' => 'VAO Dup A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 10]);

    $response = test()->actingAs($owner)->patchJson(route('superadmin.vehicle-types.reorder'), [
        'groups' => [
            [
                'required_truck_type_id' => $truckType->id,
                'category' => 'cars_suvs',
                'vehicle_ids' => [$a->id, $a->id],
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('groups');
});

it('rejects a reorder request missing a vehicle that belongs to the group', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    $a = vaoVehicleType(['name' => 'VAO Missing A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 10]);
    vaoVehicleType(['name' => 'VAO Missing B', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 20]);

    $response = test()->actingAs($owner)->patchJson(route('superadmin.vehicle-types.reorder'), [
        'groups' => [
            [
                'required_truck_type_id' => $truckType->id,
                'category' => 'cars_suvs',
                'vehicle_ids' => [$a->id],
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('groups');
});

it('rejects a reorder request containing an inactive vehicle', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    $a = vaoVehicleType(['name' => 'VAO Inactive A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 10, 'status' => 'inactive']);

    $response = test()->actingAs($owner)->patchJson(route('superadmin.vehicle-types.reorder'), [
        'groups' => [
            [
                'required_truck_type_id' => $truckType->id,
                'category' => 'cars_suvs',
                'vehicle_ids' => [$a->id],
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('groups');
});

it('rejects a reorder request when a vehicle no longer belongs to the submitted group', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    $a = vaoVehicleType(['name' => 'VAO Outdated A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 10]);

    $response = test()->actingAs($owner)->patchJson(route('superadmin.vehicle-types.reorder'), [
        'groups' => [
            [
                'required_truck_type_id' => $truckType->id,
                'category' => 'trucks',
                'vehicle_ids' => [$a->id],
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('groups');
    expect($a->fresh()->display_order)->toBe(10);
});

it('rejects an outdated request naming a vehicle id that no longer exists', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    $a = vaoVehicleType(['name' => 'VAO Ghost A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 10]);
    $ghostId = $a->id + 999999;

    $response = test()->actingAs($owner)->patchJson(route('superadmin.vehicle-types.reorder'), [
        'groups' => [
            [
                'required_truck_type_id' => $truckType->id,
                'category' => 'cars_suvs',
                'vehicle_ids' => [$a->id, $ghostId],
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('groups');
});

it('preserves category, weight, required truck type, and status when reordering', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    $a = vaoVehicleType([
        'name' => 'VAO Preserve A', 'category' => 'trucks', 'weight_kg' => 3200,
        'required_truck_type_id' => $truckType->id, 'display_order' => 10,
    ]);
    $b = vaoVehicleType(['name' => 'VAO Preserve B', 'category' => 'trucks', 'required_truck_type_id' => $truckType->id, 'display_order' => 20]);

    test()->actingAs($owner)->patchJson(route('superadmin.vehicle-types.reorder'), [
        'groups' => [
            [
                'required_truck_type_id' => $truckType->id,
                'category' => 'trucks',
                'vehicle_ids' => [$b->id, $a->id],
            ],
        ],
    ])->assertOk();

    $a->refresh();
    expect($a->category)->toBe('trucks');
    expect((float) $a->weight_kg)->toEqualWithDelta(3200, 0.01);
    expect($a->required_truck_type_id)->toBe($truckType->id);
    expect($a->status)->toBe('active');
});

it('preserves booking references when reordering via the reorder endpoint', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    $customer = Customer::create([
        'full_name' => 'VAO Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'vao-' . uniqid() . '@example.com',
    ]);

    $a = vaoVehicleType(['name' => 'VAO Booked A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 10]);
    $b = vaoVehicleType(['name' => 'VAO Booked B', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 20]);

    $booking = tap(new Booking())->forceFill([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $a->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00, 'status' => 'completed',
    ]);
    $booking->save();

    test()->actingAs($owner)->patchJson(route('superadmin.vehicle-types.reorder'), [
        'groups' => [
            [
                'required_truck_type_id' => $truckType->id,
                'category' => 'cars_suvs',
                'vehicle_ids' => [$b->id, $a->id],
            ],
        ],
    ])->assertOk();

    expect($booking->fresh()->vehicle_type_id)->toBe($a->id);
    expect($booking->fresh()->vehicleType->name)->toBe('VAO Booked A');
});
