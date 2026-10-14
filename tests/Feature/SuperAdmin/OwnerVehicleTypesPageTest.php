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

function vtDefaultTruckType(): TruckType
{
    return TruckType::firstOrCreate(
        ['name' => 'VT Default Truck'],
        ['class' => 'light', 'base_rate' => 1500, 'per_km_rate' => 60, 'max_tonnage' => 4000, 'status' => 'active']
    );
}

function vtVehicleType(array $overrides = []): VehicleType
{
    return VehicleType::create(array_merge([
        'name' => 'VT Vehicle ' . fake()->unique()->word(),
        'category' => '4_wheeler',
        'weight_kg' => 2000,
        'required_truck_type_id' => vtDefaultTruckType()->id,
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

it('preserves server-side search via query string', function () {
    $matching = vtVehicleType(['name' => 'Findable Vehicle']);
    $other = vtVehicleType();

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index', ['search' => 'Findable']));

    $response->assertOk();
    $response->assertSee($matching->name);
    $response->assertDontSee('data-name="' . $other->name . '"', false);
});

it('preserves the category filter', function () {
    $wheeler = vtVehicleType(['category' => '2_wheeler']);
    $heavy = vtVehicleType(['category' => 'heavy_vehicle']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index', ['category' => '2_wheeler']));

    $response->assertOk();
    $response->assertSee($wheeler->name);
    $response->assertDontSee('data-name="' . $heavy->name . '"', false);
});

it('preserves the status filter', function () {
    $active = vtVehicleType(['status' => 'active']);
    $inactive = vtVehicleType(['status' => 'inactive']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index', ['status' => 'inactive']));

    $response->assertOk();
    $response->assertSee($inactive->name);
    $response->assertDontSee('data-name="' . $active->name . '"', false);
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

it('does not render a compatible truck types section in either modal', function () {
    vtTruckType(['name' => 'VT Compat Truck']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    $response->assertDontSee('Compatible Truck Types');
    expect($content)->not->toContain('type="checkbox" name="truck_types[]"');
    expect($content)->not->toContain('vc-truck-check-input');
});

it('makes weight not required in either modal', function () {
    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->not->toContain('name="weight_kg" id="addVcWeight" required');
    expect($content)->not->toContain('name="weight_kg" id="editVcWeight" required');
});

it('does not introduce personnel assignment fields in the add or edit vehicle type modals', function () {
    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertDontSee('name="team_leader_id"', false);
    $response->assertDontSee('name="driver_id"', false);
    $response->assertDontSee('Assign Team Leader');
    $response->assertDontSee('Assign Driver');
});

/* ---------- Revision 2 layout ---------- */

function vtBooking(VehicleType $type): void
{
    tap(new Booking())->forceFill([
        'customer_id' => vtCustomer()->id,
        'truck_type_id' => $type->required_truck_type_id,
        'vehicle_type_id' => $type->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'completed',
    ])->save();
}

it('shows the revision 2 header without the duplicated fleet tabs', function () {
    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    $response->assertSee('Vehicles customers choose when booking, grouped by the truck type each one needs.');
    $response->assertSee('Manage categories');
    $response->assertSee('Add vehicle type');
    $response->assertSee('placeholder="Search vehicle type"', false);
    $response->assertDontSee('class="fleet-tabs"', false);
    $response->assertSeeInOrder(['name="search"', 'name="category"', 'name="status"'], false);
});

it('groups vehicle types into truck type cards and category sections', function () {
    $light = vtTruckType(['name' => 'VT Light Duty', 'class' => 'light']);
    $heavy = vtTruckType(['name' => 'VT Heavy Duty', 'class' => 'heavy', 'max_tonnage' => null]);
    vtVehicleType(['name' => 'VT Sedan', 'category' => 'cars_suvs', 'weight_kg' => 1300, 'required_truck_type_id' => $light->id, 'description' => 'Includes crossovers']);
    vtVehicleType(['name' => 'VT Wing Van', 'category' => 'trucks', 'weight_kg' => 12000, 'required_truck_type_id' => $heavy->id]);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertSeeInOrder(['VT Light Duty', 'Light class · 1 vehicle type', 'Cars &amp; SUVs', 'VT Sedan', 'Includes crossovers', '1,300 kg', 'Active'], false);
    $response->assertSeeInOrder(['VT Heavy Duty', 'Heavy class · 1 vehicle type', 'Trucks', 'VT Wing Van', '12,000 kg'], false);
    $response->assertSee('Order is set within each category of a truck type.');
});

it('orders truck type cards by class: light, medium, then heavy', function () {
    $heavy = vtTruckType(['name' => 'VT A Heavy', 'class' => 'heavy', 'max_tonnage' => null]);
    $light = vtTruckType(['name' => 'VT Z Light', 'class' => 'light']);
    vtVehicleType(['name' => 'VT Heavy Thing', 'weight_kg' => 9000, 'required_truck_type_id' => $heavy->id]);
    vtVehicleType(['name' => 'VT Light Thing', 'required_truck_type_id' => $light->id]);

    $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'))
        ->assertSeeInOrder(['VT Z Light', 'VT A Heavy']);
});

it('numbers active vehicles within each truck type and category, and shows inactive ones as Hidden', function () {
    $truck = vtTruckType();
    $other = vtTruckType(['name' => 'VT Other Truck']);
    vtVehicleType(['name' => 'VT First', 'category' => 'cars_suvs', 'required_truck_type_id' => $truck->id, 'display_order' => 10]);
    vtVehicleType(['name' => 'VT Other Group', 'category' => 'cars_suvs', 'required_truck_type_id' => $other->id, 'display_order' => 20]);
    vtVehicleType(['name' => 'VT Second', 'category' => 'cars_suvs', 'required_truck_type_id' => $truck->id, 'display_order' => 30]);
    vtVehicleType(['name' => 'VT Retired', 'category' => 'cars_suvs', 'required_truck_type_id' => $truck->id, 'status' => 'inactive', 'display_order' => 5]);

    $content = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'))->getContent();

    expect($content)->toMatch('/data-rank>1<\/span>.*?VT First.*?data-rank>2<\/span>.*?VT Second.*?Hidden.*?VT Retired/s');
    expect($content)->toMatch('/data-rank>1<\/span>.*?VT Other Group/s');
});

it('keeps order numbers when filtering', function () {
    $truck = vtTruckType();
    vtVehicleType(['name' => 'VT Keep A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truck->id, 'display_order' => 10]);
    vtVehicleType(['name' => 'VT Keep B', 'category' => 'cars_suvs', 'required_truck_type_id' => $truck->id, 'display_order' => 20]);

    $content = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index', ['search' => 'keep b']))->getContent();

    expect($content)->toMatch('/data-rank>2<\/span>.*?VT Keep B/s')->not->toContain('VT Keep A');
});

it('searches name and description case-insensitively and reports the count', function () {
    vtVehicleType(['name' => 'VT Sedan Search', 'description' => 'Includes Crossovers']);
    vtVehicleType(['name' => 'VT Unrelated']);

    $owner = vtOwner();
    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index', ['search' => 'sedan search']))
        ->assertSee('VT Sedan Search')->assertDontSee('VT Unrelated')
        ->assertSee('Showing 1 of 2 vehicle types.');

    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index', ['search' => 'CROSSOVERS']))
        ->assertSee('VT Sedan Search');
});

it('filters by category and status, ignoring unknown filter values', function () {
    vtVehicleType(['name' => 'VT Car', 'category' => 'cars_suvs']);
    vtVehicleType(['name' => 'VT Truck Vehicle', 'category' => 'trucks', 'status' => 'inactive']);

    $owner = vtOwner();
    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index', ['category' => 'cars_suvs']))
        ->assertSee('VT Car')->assertDontSee('VT Truck Vehicle');
    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index', ['status' => 'inactive']))
        ->assertSee('VT Truck Vehicle')->assertDontSee('data-vehicle-name>VT Car<', false);
    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index', ['category' => 'nope', 'status' => 'bogus']))
        ->assertSee('VT Car')->assertSee('VT Truck Vehicle')->assertSee('Showing 2 of 2 vehicle types.');
});

it('shows distinct empty states for no matches and no vehicle types', function () {
    $owner = vtOwner();

    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index'))
        ->assertSee('No vehicle types yet')->assertDontSee('Clear filters');

    vtVehicleType();
    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index', ['search' => 'zzz-no-match']))
        ->assertSee('No vehicle types match these filters')->assertSee('Clear filters');
});

it('shows a new truck type with no vehicles, but hides it while filtering', function () {
    $empty = vtTruckType(['name' => 'VT Empty Truck']);
    vtVehicleType(['name' => 'VT Filled']);

    $owner = vtOwner();
    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index'))
        ->assertSee('VT Empty Truck')->assertSee('No vehicle types use this truck type yet.');
    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index', ['search' => 'filled']))
        ->assertDontSee('data-truck-type-id="' . $empty->id . '"', false);
});

it('keeps vehicles of an inactive truck type visible and labelled', function () {
    $retired = vtTruckType(['name' => 'VT Retired Truck', 'status' => 'inactive']);
    vtVehicleType(['name' => 'VT Orphan Vehicle', 'required_truck_type_id' => $retired->id]);

    $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'))
        ->assertSeeInOrder(['VT Retired Truck', 'Truck type inactive', 'VT Orphan Vehicle']);
});

/* ---------- Ordering controls ---------- */

it('offers Edit order only for groups with two or more active vehicles', function () {
    $two = vtTruckType(['name' => 'VT Two']);
    $one = vtTruckType(['name' => 'VT One']);
    vtVehicleType(['category' => 'cars_suvs', 'required_truck_type_id' => $two->id]);
    vtVehicleType(['category' => 'cars_suvs', 'required_truck_type_id' => $two->id]);
    vtVehicleType(['category' => 'cars_suvs', 'required_truck_type_id' => $one->id]);
    vtVehicleType(['category' => 'cars_suvs', 'required_truck_type_id' => $one->id, 'status' => 'inactive']);

    $content = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'))->getContent();

    expect(substr_count($content, 'data-edit-order'))->toBe(1);
    expect(substr_count($content, 'data-move="up"'))->toBe(2);
    expect($content)->toContain('data-reorder-url="' . route('superadmin.vehicle-types.reorder') . '"');
});

it('disables Edit order while a filter hides part of a group', function () {
    $truck = vtTruckType();
    vtVehicleType(['name' => 'VT Ord A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truck->id]);
    vtVehicleType(['name' => 'VT Ord B', 'category' => 'cars_suvs', 'required_truck_type_id' => $truck->id]);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index', ['status' => 'active']));

    expect($response->getContent())->toMatch('/data-edit-order\s+aria-disabled="true"/');
    $response->assertSee('Clear search and filters to edit the order');
});

it('saves a reordered group through the existing endpoint without touching other groups or customer order', function () {
    $truck = vtTruckType();
    $a = vtVehicleType(['name' => 'VT R A', 'category' => 'cars_suvs', 'required_truck_type_id' => $truck->id, 'display_order' => 10]);
    $x = vtVehicleType(['name' => 'VT R X', 'category' => 'trucks', 'required_truck_type_id' => $truck->id, 'display_order' => 20]);
    $b = vtVehicleType(['name' => 'VT R B', 'category' => 'cars_suvs', 'required_truck_type_id' => $truck->id, 'display_order' => 30]);

    $this->actingAs(vtOwner())->patchJson(route('superadmin.vehicle-types.reorder'), ['groups' => [
        ['required_truck_type_id' => $truck->id, 'category' => 'cars_suvs', 'vehicle_ids' => [$b->id, $a->id]],
        ['required_truck_type_id' => $truck->id, 'category' => 'trucks', 'vehicle_ids' => [$x->id]],
    ]])->assertOk();

    // Customer list: B takes A's slot, X keeps its slot in between.
    $this->getJson('/api/v1/vehicle-types/by-category/cars_suvs')
        ->assertJsonPath('vehicleTypes.0.name', 'VT R B')
        ->assertJsonPath('vehicleTypes.1.name', 'VT R A');
    expect(VehicleType::where('status', 'active')->orderBy('display_order')->pluck('name')->all())
        ->toBe(['VT R B', 'VT R X', 'VT R A']);
});

/* ---------- Add / edit / status / delete ---------- */

it('renders one add/edit dialog wired to the existing routes and fields', function () {
    $truck = vtTruckType(['name' => 'VT Medium Duty', 'class' => 'medium']);
    vtTruckType(['name' => 'VT Disabled Truck', 'status' => 'inactive']);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertSee('action="' . route('superadmin.vehicle-types.store') . '"', false);
    $response->assertSee('data-update-url="' . route('superadmin.vehicle-types.update', '__ID__') . '"', false);
    $response->assertSee('data-toggle-url="' . route('superadmin.vehicle-types.toggle', '__ID__') . '"', false);
    $response->assertSee('data-destroy-url="' . route('superadmin.vehicle-types.destroy', '__ID__') . '"', false);
    foreach (['name', 'category', 'weight_kg', 'required_truck_type_id', 'description'] as $field) {
        $response->assertSee('name="' . $field . '"', false);
    }
    $response->assertSee('VT Medium Duty · Medium class');
    $response->assertDontSee('VT Disabled Truck');
    $response->assertSee('Bookings for this vehicle use this truck type for pricing and truck matching.');
    $response->assertSee('Weight (kg) <span class="tt-optional">(optional)</span>', false);
});

it('gives each row an Edit button carrying the status and booking count for the dialog', function () {
    $type = vtVehicleType(['name' => 'VT Booked']);
    vtBooking($type);

    $response = $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertSee('aria-label="Edit VT Booked"', false);
    $response->assertSee('data-bookings="1"', false);
    $response->assertSee('data-status="active"', false);
    $response->assertDontSee('more-vertical', false);
});

it('lists newly created categories in the dialog and filter', function () {
    $this->actingAs(vtOwner())->postJson(route('superadmin.vehicle-categories.store'), ['name' => 'VT Off-road'])->assertOk();

    $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'))
        ->assertSee('<option value="vt_off_road" >VT Off-road</option>', false)
        ->assertSeeInOrder(['Vehicle categories', 'VT Off-road', '0 vehicle types']);
});

it('shows each category with its vehicle count in Manage categories', function () {
    vtVehicleType(['category' => 'trucks']);
    vtVehicleType(['category' => 'trucks']);

    $this->actingAs(vtOwner())->get(route('superadmin.vehicle-types.index'))
        ->assertSeeInOrder(['Vehicle categories', 'Trucks', '2 vehicle types'])
        ->assertSee('Categories can be renamed but not removed here');
});

it('keeps typed values and shows field messages after a failed add', function () {
    vtVehicleType(['name' => 'VT Taken']);
    $truck = vtTruckType();
    $owner = vtOwner();
    $from = route('superadmin.vehicle-types.index', ['category' => 'cars_suvs']);

    $response = $this->actingAs($owner)->from($from)->post(route('superadmin.vehicle-types.store'), [
        '_modal' => 'add', 'name' => 'VT Taken', 'category' => 'cars_suvs', 'weight_kg' => '1,3OO',
        'required_truck_type_id' => $truck->id, 'description' => 'Kept text',
    ]);

    $response->assertRedirect($from);
    $response->assertSessionHasErrors([
        'name' => 'A vehicle type with this name already exists.',
        'weight_kg' => 'Enter a number, e.g. 1300',
    ]);
    $response->assertSessionHasInput('weight_kg', '1,3OO');

    $page = $this->actingAs($owner)->get($from);
    $page->assertSee('data-reopen="add"', false);
    $page->assertSee('Check the 2 highlighted fields. Everything else you entered is kept.');
    $page->assertSee('value="1,3OO"', false);
    $page->assertSee('value="Kept text"', false);
});

it('accepts a weight typed with a thousands separator', function () {
    $truck = vtTruckType(['class' => 'heavy', 'max_tonnage' => null]);

    $this->actingAs(vtOwner())->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VT Ten Wheeler', 'category' => 'trucks', 'weight_kg' => '15,000', 'required_truck_type_id' => $truck->id,
    ])->assertSessionHasNoErrors();

    expect((float) VehicleType::where('name', 'VT Ten Wheeler')->value('weight_kg'))->toBe(15000.0);
});

it('keeps the weight compatibility rule on add and edit', function () {
    $light = vtTruckType(['class' => 'light']);
    $type = vtVehicleType();

    $this->actingAs(vtOwner())->post(route('superadmin.vehicle-types.store'), [
        'name' => 'VT Too Heavy', 'category' => 'trucks', 'weight_kg' => '9000', 'required_truck_type_id' => $light->id,
    ])->assertSessionHasErrors('weight_kg');

    $this->actingAs(vtOwner())->put(route('superadmin.vehicle-types.update', $type->id), [
        'name' => $type->name, 'category' => $type->category, 'weight_kg' => '9000', 'required_truck_type_id' => $light->id,
    ])->assertSessionHasErrors('weight_kg');
});

it('reopens the edit dialog for the same vehicle type after a failed save', function () {
    $type = vtVehicleType(['name' => 'VT Edit Me']);
    $owner = vtOwner();

    $this->actingAs($owner)->from(route('superadmin.vehicle-types.index'))->put(route('superadmin.vehicle-types.update', $type->id), [
        '_modal' => 'edit', '_edit_id' => $type->id, 'name' => '', 'category' => $type->category, 'required_truck_type_id' => $type->required_truck_type_id,
    ])->assertSessionHasErrors(['name' => 'Enter a name.']);

    $page = $this->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $page->assertSee('data-reopen="edit"', false);
    $page->assertSee('action="' . route('superadmin.vehicle-types.update', $type->id) . '"', false);
    $page->assertSee('Check the highlighted field. Everything else you entered is kept.');
});

it('returns to the same filtered list after a successful edit', function () {
    $type = vtVehicleType(['name' => 'VT Return']);
    $from = route('superadmin.vehicle-types.index', ['search' => 'return']);

    $this->actingAs(vtOwner())->from($from)->put(route('superadmin.vehicle-types.update', $type->id), [
        'name' => 'VT Return', 'category' => $type->category, 'required_truck_type_id' => $type->required_truck_type_id,
    ])->assertRedirect($from)->assertSessionHas('success', 'VT Return updated.');
});

it('explains status changes in plain language', function () {
    $type = vtVehicleType(['name' => 'VT Toggle']);
    $owner = vtOwner();

    $this->actingAs($owner)->patch(route('superadmin.vehicle-types.toggle', $type->id))
        ->assertSessionHas('success', 'VT Toggle is now hidden from customers.');
    $this->actingAs($owner)->patch(route('superadmin.vehicle-types.toggle', $type->id))
        ->assertSessionHas('success', 'VT Toggle is shown to customers again, last in its category.');
});

it('keeps hidden vehicles out of the customer list', function () {
    vtVehicleType(['name' => 'VT Visible', 'category' => 'cars_suvs']);
    vtVehicleType(['name' => 'VT Hidden', 'category' => 'cars_suvs', 'status' => 'inactive']);

    $names = collect($this->getJson('/api/v1/vehicle-types/by-category/cars_suvs')->json('vehicleTypes'))->pluck('name');

    expect($names->all())->toContain('VT Visible')->not->toContain('VT Hidden');
});
