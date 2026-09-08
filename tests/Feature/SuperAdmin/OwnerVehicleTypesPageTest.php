<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleType;

function vtRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function vtOwner(): User
{
    vtRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function vtDispatcher(): User
{
    vtRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

function vtTruckType(array $overrides = []): TruckType
{
    return TruckType::create(array_merge([
        'name' => 'VT Truck ' . fake()->unique()->word(),
        'class' => 'light',
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'max_tonnage' => 4000,
        'status' => 'active',
    ], $overrides));
}

function vtVehicleType(array $overrides = []): VehicleType
{
    return VehicleType::create(array_merge([
        'name' => 'VT Vehicle ' . fake()->unique()->word(),
        'category' => '4_wheeler',
        'weight_kg' => 2000,
        'status' => 'active',
        'display_order' => 0,
    ], $overrides));
}

function vtCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'VT Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'vt-' . uniqid() . '@example.com',
    ]);
}

it('owner can access vehicle types', function () {
    $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'))->assertOk();
});

it('dispatcher cannot access owner vehicle types', function () {
    $this->actingAs(vtDispatcher())->get(route('superadmin.vehicle-types.index'))->assertForbidden();
});

it('shows the fleet tabs with vehicle types active', function () {
    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('Truck Types &amp; Rates', false);
    $response->assertSee('Trucks');
    $response->assertSee('Vehicle Types');
    $response->assertSee('is-active');
});

it('preserves server-side search via query string', function () {
    $matching = vtVehicleType(['name' => 'Findable Vehicle']);
    $other = vtVehicleType();

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index', ['search' => 'Findable']));

    $response->assertOk();
    $response->assertSee($matching->name);
    $response->assertDontSee($other->name);
});

it('preserves the category filter', function () {
    $wheeler = vtVehicleType(['category' => '2_wheeler']);
    $heavy = vtVehicleType(['category' => 'heavy_vehicle']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index', ['category' => '2_wheeler']));

    $response->assertOk();
    $response->assertSee($wheeler->name);
    $response->assertDontSee($heavy->name);
});

it('preserves the status filter', function () {
    $active = vtVehicleType(['status' => 'active']);
    $inactive = vtVehicleType(['status' => 'inactive']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index', ['status' => 'inactive']));

    $response->assertOk();
    $response->assertSee($inactive->name);
    $response->assertDontSee($active->name);
});

it('keeps add vehicle type available in the toolbar', function () {
    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('id="vcAddBtn"', false);
    $response->assertSee('Add Vehicle Type');
    $response->assertSee('action="' . route('superadmin.vehicle-types.store') . '"', false);
});

it('renders existing vehicle type records', function () {
    $type = vtVehicleType();

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee($type->name);
});

it('renders category neutrally without color-coded pill classes', function () {
    $type = vtVehicleType(['category' => '2_wheeler']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee($type->category_label);
    $response->assertDontSee('vc-category--2_wheeler', false);
});

it('shows all assigned truck types for a many-to-many vehicle type', function () {
    $truckA = vtTruckType(['name' => 'VT Truck Alpha']);
    $truckB = vtTruckType(['name' => 'VT Truck Beta']);
    $type = vtVehicleType();
    $type->truckTypes()->sync([$truckA->id, $truckB->id]);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('VT Truck Alpha');
    $response->assertSee('VT Truck Beta');
    $response->assertDontSee('vc-class-tag', false);
});

it('reflects active and inactive status text without pills', function () {
    $active = vtVehicleType(['status' => 'active']);
    $inactive = vtVehicleType(['status' => 'inactive']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->toContain('status-active');
    expect($content)->toContain('status-inactive');
});

it('renders a three-dot action trigger instead of permanent text buttons', function () {
    $type = vtVehicleType();

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('data-lucide="more-vertical"', false);
    $response->assertSee('aria-label="Actions for ' . $type->name . '"', false);
    $response->assertDontSee('class="vc-action js-vc-edit"', false);
});

it('exposes edit, disable, and delete as dropdown actions for an active vehicle type with no bookings', function () {
    $type = vtVehicleType(['status' => 'active']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('Edit Vehicle Type');
    $response->assertSee('Disable');
    $response->assertSee('Delete');
    $response->assertDontSee('Enable');
});

it('exposes enable instead of disable as a dropdown action for an inactive vehicle type', function () {
    vtVehicleType(['status' => 'inactive']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('Enable');
    $response->assertDontSee('Disable');
});

it('hides delete when the vehicle type has booking history', function () {
    $type = vtVehicleType();
    $customer = vtCustomer();
    $truckType = vtTruckType();

    tap(new Booking())->forceFill([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $type->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'completed',
    ])->save();

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertDontSee('js-vc-delete', false);
});

it('keeps the dropdown actions wired to the existing toggle and destroy routes', function () {
    $type = vtVehicleType();

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('action="' . route('superadmin.vehicle-types.toggle', $type->id) . '"', false);
    $response->assertSee('data-id="' . $type->id . '"', false);
});

it('toggles vehicle type status via the exact existing patch route', function () {
    $owner = vtOwner();
    $type = vtVehicleType(['status' => 'active']);

    $response = $this->actingAs($owner)->patch(route('superadmin.vehicle-types.toggle', $type->id));

    $response->assertRedirect();
    $this->assertDatabaseHas('vehicle_types', ['id' => $type->id, 'status' => 'inactive']);
});

it('blocks delete when the vehicle type has bookings via the existing guard', function () {
    $owner = vtOwner();
    $type = vtVehicleType();
    $customer = vtCustomer();
    $truckType = vtTruckType();

    tap(new Booking())->forceFill([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'vehicle_type_id' => $type->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'completed',
    ])->save();

    $response = $this->actingAs($owner)->delete(route('superadmin.vehicle-types.destroy', $type->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('vehicle_types', ['id' => $type->id]);
});

it('deletes a vehicle type with no booking history via the existing destroy route', function () {
    $owner = vtOwner();
    $type = vtVehicleType();

    $response = $this->actingAs($owner)->delete(route('superadmin.vehicle-types.destroy', $type->id));

    $response->assertRedirect();
    $this->assertDatabaseMissing('vehicle_types', ['id' => $type->id]);
});

it('does not expose dispatcher or personnel operational controls on the vehicle types page', function () {
    vtVehicleType();

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertDontSee('assign-team-leader', false);
    $response->assertDontSee('js-assign-leader', false);
    $response->assertDontSee('borrow-crew', false);
    $response->assertDontSee('name="team_leader_id"', false);
    $response->assertDontSee('name="driver_id"', false);
});

it('keeps pagination rendering when results exceed one page', function () {
    for ($i = 0; $i < 12; $i++) {
        vtVehicleType();
    }

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('pagination-wrapper', false);
});

it('renders a compact empty state distinguishing filtered from genuinely empty results', function () {
    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('No vehicle types yet');
    $response->assertSee('Add a vehicle type to make it available for customer booking.');

    $filtered = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index', ['search' => 'zzz-no-match']));

    $filtered->assertOk();
    $filtered->assertSee('No vehicle types found');
    $filtered->assertSee('Try adjusting your search or filters.');
});

it('renders the add vehicle type modal with its exact existing form action, method, and CSRF protection', function () {
    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('id="addModal"', false);
    $response->assertSee('action="' . route('superadmin.vehicle-types.store') . '"', false);
    $response->assertSee('name="_token"', false);
});

it('keeps the exact existing field names in the add vehicle type modal', function () {
    vtTruckType();

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('name="name" id="addVcName"', false);
    $response->assertSee('name="category" id="addVcCategory"', false);
    $response->assertSee('name="weight_kg" id="addVcWeight"', false);
    $response->assertSee('name="description" id="addVcDescription"', false);
    $response->assertSee('name="truck_types[]"', false);
});

it('renders the edit vehicle type modal wired to the exact existing form and PUT method', function () {
    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('id="editModal"', false);
    $response->assertSee('id="editVcForm"', false);
    $response->assertSee('name="_method" value="PUT"', false);
    $response->assertSee('name="name" id="editVcName"', false);
    $response->assertSee('name="category" id="editVcCategory"', false);
    $response->assertSee('name="weight_kg" id="editVcWeight"', false);
});

it('renders compatible truck types as multi-select checkboxes, not radio buttons, in both modals', function () {
    vtTruckType(['name' => 'VT Compat Truck']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    $response->assertSee('VT Compat Truck');
    expect(substr_count($content, 'type="checkbox" name="truck_types[]"'))->toBeGreaterThanOrEqual(2);
    $response->assertDontSee('type="radio" name="truck_types', false);
});

it('preserves the weight-compatibility data hooks the existing JS relies on', function () {
    vtTruckType(['name' => 'VT Class Truck', 'class' => 'medium', 'max_tonnage' => 6000]);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->toContain('data-class="medium"');
    expect($content)->toContain('data-capacity="6000.00"');
    expect($content)->toContain('vc-truck-check-input add-truck-check');
    expect($content)->toContain('vc-truck-check-input edit-truck-check');
});

it('does not introduce personnel assignment fields in the add or edit vehicle type modals', function () {
    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertDontSee('name="team_leader_id"', false);
    $response->assertDontSee('name="driver_id"', false);
    $response->assertDontSee('Assign Team Leader');
    $response->assertDontSee('Assign Driver');
});
