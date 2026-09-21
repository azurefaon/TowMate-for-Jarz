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

it('renders the edit order button and inline order bar controls on the vehicle types page', function () {
    $owner = vaoOwner();

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('id="vcEditOrderBtn"', false);
    $response->assertSee('Edit order');
    $response->assertSee('id="vcOrderBar"', false);
    $response->assertSee('id="vcOrderSaveBtn"', false);
    $response->assertSee('Save order');
    $response->assertSee('id="vcOrderCancelBtn"', false);
    $response->assertSee('id="vcCategoriesBtn"', false);
    $response->assertSee('Manage categories');
});

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

it('only renders a drag handle for active vehicle rows', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    vaoVehicleType(['name' => 'VAO Handle Active', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 10, 'status' => 'active']);
    vaoVehicleType(['name' => 'VAO Handle Inactive', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 20, 'status' => 'inactive']);

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect(substr_count($content, 'class="vc-drag-handle"'))->toBe(1);
});

it('keeps the manage categories modal hidden by default', function () {
    $owner = vaoOwner();

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->toContain('<div class="vc-modal" id="categoriesModal">');
    expect($content)->not->toContain('<div class="vc-modal is-open" id="categoriesModal">');
});

it('lays the toolbar out as a balanced two-column grid with search/add on row one and filters/actions on row two', function () {
    $owner = vaoOwner();

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->toContain('vc-toolbar-search');
    expect($content)->toContain('vc-toolbar-primary');
    expect($content)->toContain('vc-toolbar-filters');
    expect($content)->toContain('vc-toolbar-secondary');

    $searchPos = strpos($content, 'id="vcSearch"');
    $addPos = strpos($content, 'id="vcAddBtn"');
    $categoryFilterPos = strpos($content, 'id="vcCategoryFilter"');
    $statusFilterPos = strpos($content, 'id="vcStatusFilter"');
    $categoriesPos = strpos($content, 'id="vcCategoriesBtn"');
    $togglePos = strpos($content, 'id="vcOrderToggle"');
    $editOrderPos = strpos($content, 'id="vcEditOrderBtn"');
    $orderActionsPos = strpos($content, 'id="vcOrderActions"');
    $cancelPos = strpos($content, 'id="vcOrderCancelBtn"');
    $savePos = strpos($content, 'id="vcOrderSaveBtn"');

    expect([$searchPos, $addPos, $categoryFilterPos, $statusFilterPos, $categoriesPos, $togglePos, $editOrderPos, $orderActionsPos, $cancelPos, $savePos])->each->not->toBeFalse();
    expect($searchPos)->toBeLessThan($addPos);
    expect($addPos)->toBeLessThan($categoryFilterPos);
    expect($categoryFilterPos)->toBeLessThan($statusFilterPos);
    expect($statusFilterPos)->toBeLessThan($categoriesPos);
    expect($categoriesPos)->toBeLessThan($togglePos);
    expect($togglePos)->toBeLessThan($editOrderPos);
    expect($editOrderPos)->toBeLessThan($orderActionsPos);
    expect($orderActionsPos)->toBeLessThan($cancelPos);
    expect($cancelPos)->toBeLessThan($savePos);
});

it('uses a two-column css grid for the toolbar instead of a single stacked column', function () {
    $css = file_get_contents(public_path('admin/css/vehicle-types.css'));

    preg_match('/\.vc-toolbar\s*\{[^}]*\}/s', $css, $toolbarRule);

    expect($toolbarRule)->not->toBeEmpty();
    expect($toolbarRule[0])->toContain('display: grid');
    expect($toolbarRule[0])->toContain('"search primary"');
    expect($toolbarRule[0])->toContain('"filters secondary"');
});

it('does not use space-between on the toolbar row groups', function () {
    $css = file_get_contents(public_path('admin/css/vehicle-types.css'));

    foreach (['.vc-toolbar-search', '.vc-toolbar-primary', '.vc-toolbar-filters', '.vc-toolbar-secondary'] as $selector) {
        preg_match('/' . preg_quote($selector, '/') . '\s*\{[^}]*\}/s', $css, $rule);

        expect($rule)->not->toBeEmpty();
        expect($rule[0])->not->toContain('space-between');
    }
});

it('right-aligns the add vehicle type button and the manage categories and edit order group', function () {
    $css = file_get_contents(public_path('admin/css/vehicle-types.css'));

    preg_match('/\.vc-toolbar-primary\s*\{[^}]*\}/s', $css, $primaryRule);
    preg_match('/\.vc-toolbar-secondary\s*\{[^}]*\}/s', $css, $secondaryRule);

    expect($primaryRule)->not->toBeEmpty();
    expect($secondaryRule)->not->toBeEmpty();
    expect($primaryRule[0])->toContain('justify-content: flex-end');
    expect($secondaryRule[0])->toContain('justify-content: flex-end');
});

it('does not use a fixed pixel width for the search box that could overlap other toolbar controls', function () {
    $css = file_get_contents(public_path('admin/css/vehicle-types.css'));

    preg_match('/\.vc-page \.search-box\s*\{[^}]*\}/s', $css, $searchBoxRule);

    expect($searchBoxRule)->not->toBeEmpty();
    expect($searchBoxRule[0])->not->toMatch('/(?<![a-z-])width:\s*\d+px/');
});

it('does not rely on viewport-height units for the toolbar or order hint layout', function () {
    $css = file_get_contents(public_path('admin/css/vehicle-types.css'));

    preg_match('/\.vc-toolbar\s*\{[^}]*\}/s', $css, $toolbarRule);
    preg_match('/\.vc-order-hint\s*\{[^}]*\}/s', $css, $orderHintRule);

    expect($toolbarRule)->not->toBeEmpty();
    expect($orderHintRule)->not->toBeEmpty();
    expect($toolbarRule[0])->not->toContain('vh');
    expect($orderHintRule[0])->not->toContain('vh');
});

it('shows the drag instruction as plain text, not a bordered card', function () {
    $owner = vaoOwner();

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();
    $css = file_get_contents(public_path('admin/css/vehicle-types.css'));

    $response->assertOk();
    expect($content)->toContain('<p class="vc-order-hint" id="vcOrderBar">');

    preg_match('/\.vc-order-hint\s*\{[^}]*\}/s', $css, $orderHintRule);

    expect($orderHintRule)->not->toBeEmpty();
    expect($orderHintRule[0])->not->toContain('border');
    expect($orderHintRule[0])->not->toContain('background');
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

it('shows a vehicle count beside each category in the arrange panel', function () {
    $owner = vaoOwner();
    $truckType = vaoTruckType();
    vaoVehicleType(['name' => 'VAO Count Vehicle A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 10]);
    vaoVehicleType(['name' => 'VAO Count Vehicle B', 'category' => 'cars_suvs', 'required_truck_type_id' => $truckType->id, 'display_order' => 20]);

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('<span class="vc-count">2</span>', false);
});

it('renders vehicle groups directly on the page instead of inside a nested scrolling tree', function () {
    $css = file_get_contents(public_path('admin/css/vehicle-types.css'));

    preg_match('/\.vc-groups\s*\{[^}]*\}/s', $css, $groupsRule);

    expect($groupsRule)->not->toBeEmpty();
    expect($groupsRule[0])->not->toContain('overflow-y');
    expect($groupsRule[0])->not->toContain('max-height');
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

it('makes a newly created category available in the add vehicle type form', function () {
    $owner = vaoOwner();
    VehicleCategory::create(['slug' => 'vao_new_form_cat', 'name' => 'VAO New Form Category']);

    $response = test()->actingAs($owner)->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('<option value="vao_new_form_cat">VAO New Form Category</option>', false);
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
