<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;

function ttOwner(): User
{
    if (! Role::find(1)) {
        $role = new Role(['name' => 'Owner']);
        $role->id = 1;
        $role->save();
    }

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function ttDispatcher(): User
{
    if (! Role::find(2)) {
        $role = new Role(['name' => 'Dispatcher']);
        $role->id = 2;
        $role->save();
    }

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

function ttTruckType(array $overrides = []): TruckType
{
    return TruckType::create(array_merge([
        'name' => 'TT Class ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ], $overrides));
}

function ttCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'TT Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'tt-' . uniqid() . '@example.com',
    ]);
}

it('owner can access the truck types page', function () {
    $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'))->assertOk();
});

it('dispatcher cannot access the owner truck types page', function () {
    $this->actingAs(ttDispatcher())->get(route('superadmin.truck-types.index'))->assertForbidden();
});

it('owner can create a truck type', function () {
    $response = $this->actingAs(ttOwner())->post(route('superadmin.truck-types.store'), [
        'name' => 'Heavy Duty Test',
        'class' => 'heavy',
        'base_rate' => 2000,
        'per_km_rate' => 80,
        'max_tonnage' => 8000,
        'description' => '10-wheelers, trailer trucks',
    ]);

    $response->assertRedirect(route('superadmin.truck-types.index'));
    $this->assertDatabaseHas('truck_types', [
        'name' => 'Heavy Duty Test',
        'class' => 'heavy',
        'status' => 'active',
    ]);
});

it('owner can edit a truck type', function () {
    $type = ttTruckType(['name' => 'Editable Class']);

    $response = $this->actingAs(ttOwner())->put(route('superadmin.truck-types.update', $type->id), [
        'name' => 'Renamed Class',
        'base_rate' => 1800,
        'per_km_rate' => 70,
        'max_tonnage' => 6000,
        'description' => 'Updated common uses',
    ]);

    $response->assertRedirect(route('superadmin.truck-types.index'));
    $this->assertDatabaseHas('truck_types', [
        'id' => $type->id,
        'name' => 'Renamed Class',
        'base_rate' => 1800,
    ]);
});

it('disables a truck type that has no linked units or active bookings', function () {
    $type = ttTruckType(['status' => 'active']);

    $response = $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $type->id));

    $response->assertRedirect();
    $this->assertDatabaseHas('truck_types', ['id' => $type->id, 'status' => 'inactive']);
});

it('blocks disabling a truck type that has linked units', function () {
    $type = ttTruckType(['status' => 'active']);

    Unit::create([
        'name' => 'TT Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???####'),
        'truck_type_id' => $type->id,
        'status' => 'available',
    ]);

    $response = $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $type->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('truck_types', ['id' => $type->id, 'status' => 'active']);
});

it('blocks disabling a truck type with active bookings', function () {
    $type = ttTruckType(['status' => 'active']);
    $customer = ttCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $type->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'on_job',
    ]);

    $response = $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $type->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('truck_types', ['id' => $type->id, 'status' => 'active']);
});

it('enables an inactive truck type', function () {
    $type = ttTruckType(['status' => 'inactive']);

    $response = $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $type->id));

    $response->assertRedirect();
    $this->assertDatabaseHas('truck_types', ['id' => $type->id, 'status' => 'active']);
});

it('blocks deleting a truck type with linked units', function () {
    $type = ttTruckType();

    Unit::create([
        'name' => 'TT Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???####'),
        'truck_type_id' => $type->id,
        'status' => 'available',
    ]);

    $response = $this->actingAs(ttOwner())->delete(route('superadmin.truck-types.destroy', $type->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('truck_types', ['id' => $type->id]);
});

it('blocks deleting a truck type with existing bookings', function () {
    $type = ttTruckType();
    $customer = ttCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $type->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'completed',
    ]);

    $response = $this->actingAs(ttOwner())->delete(route('superadmin.truck-types.destroy', $type->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('truck_types', ['id' => $type->id]);
});

it('blocks deleting a truck type linked to a vehicle type', function () {
    $type = ttTruckType();
    $vehicleType = VehicleType::create([
        'name' => 'TT Vehicle ' . fake()->unique()->word(),
        'category' => '4_wheeler',
        'weight_kg' => 2000,
        'status' => 'active',
    ]);

    $type->vehicleTypes()->attach($vehicleType->id);

    $response = $this->actingAs(ttOwner())->delete(route('superadmin.truck-types.destroy', $type->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('truck_types', ['id' => $type->id]);
});

it('deletes a truck type with no linked units, bookings, or vehicle types', function () {
    $type = ttTruckType();

    $response = $this->actingAs(ttOwner())->delete(route('superadmin.truck-types.destroy', $type->id));

    $response->assertRedirect();
    $this->assertDatabaseMissing('truck_types', ['id' => $type->id]);
});

it('no longer duplicates the fleet tabs inside the truck types page', function () {
    $response = $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'));

    $response->assertOk();
    $response->assertSee('Truck Types &amp; Rates', false);
    $response->assertSee('Rates and weight limits used to price bookings.');
    $response->assertDontSee('class="fleet-tabs"', false);
});

it('keeps the trucks and vehicle types pages reachable', function () {
    $owner = ttOwner();

    $this->actingAs($owner)->get(route('superadmin.unit-truck.index'))->assertOk();
    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index'))->assertOk();
});

it('lists the fleet pages as Fleet Management submenu links', function () {
    $response = $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'));

    $response->assertOk();
    $response->assertSee('>Fleet Management<', false);
    $response->assertSee('id="sidebarGroupFleet"', false);
    $response->assertSee('href="' . route('superadmin.truck-types.index') . '"', false);
    $response->assertSee('href="' . route('superadmin.unit-truck.index') . '"', false);
    $response->assertSee('href="' . route('superadmin.vehicle-types.index') . '"', false);
});

it('highlights the matching Fleet Management submenu item on all three fleet pages', function () {
    $owner = ttOwner();

    foreach ([
        'superadmin.truck-types.index' => 'Truck Types &amp; Rates',
        'superadmin.unit-truck.index' => 'Trucks',
        'superadmin.vehicle-types.index' => 'Vehicle Types',
    ] as $route => $label) {
        $response = $this->actingAs($owner)->get(route($route));
        $response->assertOk();

        $pattern = '/href="' . preg_quote(route($route), '/') . '"\s*class="sidebar-sublink active"\s*aria-current="page">' . preg_quote($label, '/') . '<\/a>/';
        expect(preg_match($pattern, $response->getContent()))->toBe(1);
        expect(preg_match('/id="sidebarGroupFleetToggle"\s*aria-expanded="true"/', $response->getContent()))->toBe(1);
    }
});


function ttUnit(TruckType $type, array $overrides = []): Unit
{
    return Unit::create(array_merge([
        'name' => 'TT Unit ' . fake()->unique()->numerify('###'),
        'plate_number' => fake()->unique()->bothify('???####'),
        'truck_type_id' => $type->id,
        'status' => 'available',
    ], $overrides));
}

function ttVehicle(array $overrides = []): VehicleType
{
    return VehicleType::create(array_merge([
        'name' => 'TT Vehicle ' . fake()->unique()->word(),
        'category' => '4_wheeler',
        'weight_kg' => 2000,
        'status' => 'active',
    ], $overrides));
}

function ttValidPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Light Duty',
        'class' => 'light',
        'base_rate' => '1500',
        'per_km_rate' => '200',
        'max_tonnage' => '4500',
        'description' => 'Sedans, SUVs, vans',
    ], $overrides);
}

function ttArchive(Unit $unit): void
{
    $unit->forceFill(['archived_at' => now()])->save();
}

/* ---------- Table ---------- */

it('renders the revision 2 table columns and row details', function () {
    $light = ttTruckType([
        'name' => 'Light Duty', 'class' => 'light', 'base_rate' => 1500, 'per_km_rate' => 200,
        'max_tonnage' => 4500, 'description' => 'Sedans, SUVs, vans',
    ]);
    ttTruckType(['name' => 'Older Type', 'base_rate' => 1200, 'per_km_rate' => 180, 'status' => 'inactive']);

    ttUnit($light);
    ttUnit($light);
    ttArchive(ttUnit($light));

    $response = $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'));

    $response->assertOk();
    $response->assertSeeInOrder(['Truck type', 'Class', 'Base rate', 'Rate per km', 'Max weight', 'Trucks', 'Status']);
    $response->assertSeeInOrder(['Light Duty', 'Sedans, SUVs, vans', 'Light', '₱1,500.00', '₱200.00', '4,500 kg', '2', 'Active']);
    $response->assertSeeInOrder(['Older Type', 'No common uses listed', 'Not set', '₱1,200.00', '₱180.00', '—', '0', 'Inactive']);
    $response->assertSee('Showing 1–2 of 2 truck types');
    $response->assertSee('placeholder="Search truck type or common use"', false);
});

it('gives each row action an accessible name', function () {
    ttTruckType(['name' => 'Active Type', 'status' => 'active']);
    ttTruckType(['name' => 'Resting Type', 'status' => 'inactive']);

    $response = $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'));

    $response->assertSee('aria-label="Edit Active Type"', false);
    $response->assertSee('aria-label="More actions for Active Type"', false);
    $response->assertSee('aria-label="More actions for Resting Type"', false);
    $response->assertSee('data-action="disable"', false);
    $response->assertSee('data-action="enable"', false);
    $response->assertSee('role="menu"', false);
});

it('explains why disabling and deleting are unavailable', function () {
    $type = ttTruckType(['name' => 'Busy Type']);
    ttUnit($type);
    ttArchive(ttUnit($type));
    $type->vehicleTypes()->attach(ttVehicle()->id);

    Booking::create([
        'customer_id' => ttCustomer()->id,
        'truck_type_id' => $type->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'assigned',
    ]);

    $response = $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'));

    $response->assertSee('In use by 1 truck, 1 archived truck and 1 active booking.');
    $response->assertSee('Linked to 1 truck, 1 archived truck, 1 booking and 1 vehicle type.');
    $response->assertSee('aria-disabled="true"', false);
});

it('offers delete without a reason when nothing is linked', function () {
    ttTruckType(['name' => 'Free Type']);

    $response = $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'));

    $response->assertDontSee('aria-disabled="true"', false);
    $response->assertDontSee('Linked to');
});

/* ---------- Search, filter, pagination ---------- */

it('searches names and common uses case-insensitively across every page', function () {
    foreach (range(1, 11) as $i) {
        ttTruckType(['name' => sprintf('Alpha %02d', $i)]);
    }
    ttTruckType(['name' => 'Zulu Flatbed', 'description' => 'Wing Vans and trailers']);

    $owner = ttOwner();

    $this->actingAs($owner)->get(route('superadmin.truck-types.index', ['search' => 'zulu']))
        ->assertSee('Zulu Flatbed')->assertDontSee('Alpha 01')
        ->assertSee('Showing 1–1 of 1 truck type');

    $this->actingAs($owner)->get(route('superadmin.truck-types.index', ['search' => 'WING vans']))
        ->assertSee('Zulu Flatbed');

    $this->actingAs($owner)->get(route('superadmin.truck-types.index', ['search' => '100%']))
        ->assertSee('No truck types match these filters');
});

it('filters by status on the server', function () {
    ttTruckType(['name' => 'On Type', 'status' => 'active']);
    ttTruckType(['name' => 'Off Type', 'status' => 'inactive']);

    $owner = ttOwner();

    $this->actingAs($owner)->get(route('superadmin.truck-types.index', ['status' => 'inactive']))
        ->assertSee('Off Type')->assertDontSee('On Type');

    $this->actingAs($owner)->get(route('superadmin.truck-types.index', ['status' => 'active']))
        ->assertSee('On Type')->assertDontSee('Off Type');

    $this->actingAs($owner)->get(route('superadmin.truck-types.index', ['status' => 'bogus']))
        ->assertSee('On Type')->assertSee('Off Type');
});

it('paginates ten per page and keeps search and status in page links', function () {
    foreach (range(1, 12) as $i) {
        ttTruckType(['name' => sprintf('Beta %02d', $i), 'status' => 'active']);
    }
    ttTruckType(['name' => 'Beta Off', 'status' => 'inactive']);

    $owner = ttOwner();

    $first = $this->actingAs($owner)->get(route('superadmin.truck-types.index', ['search' => 'beta', 'status' => 'active']));
    $first->assertSee('Showing 1–10 of 12 truck types');
    $first->assertSee('search=beta', false);
    $first->assertSee('status=active', false);
    $first->assertSee('page=2', false);

    $second = $this->actingAs($owner)->get(route('superadmin.truck-types.index', ['search' => 'beta', 'status' => 'active', 'page' => 2]));
    $second->assertSee('Showing 11–12 of 12 truck types');
    $second->assertSee('Beta 12')->assertDontSee('Beta 01')->assertDontSee('Beta Off');
});

it('sends a page past the end back to the last page', function () {
    ttTruckType(['name' => 'Only Type']);

    $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index', ['search' => 'only', 'page' => 3]))
        ->assertRedirect(route('superadmin.truck-types.index', ['search' => 'only', 'page' => 1]));
});

it('shows a first-run empty state when there are no truck types', function () {
    TruckType::query()->delete();

    $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'))
        ->assertSee('No truck types yet')
        ->assertDontSee('Clear filters');
});

/* ---------- Add / edit ---------- */

it('requires a truck class for new truck types', function () {
    $this->actingAs(ttOwner())
        ->from(route('superadmin.truck-types.index'))
        ->post(route('superadmin.truck-types.store'), ttValidPayload(['class' => null]))
        ->assertRedirect(route('superadmin.truck-types.index'))
        ->assertSessionHasErrors(['class' => 'Choose a truck class.']);

    $this->assertDatabaseMissing('truck_types', ['name' => 'Light Duty']);
});

it('rejects unknown truck classes', function () {
    $this->actingAs(ttOwner())
        ->post(route('superadmin.truck-types.store'), ttValidPayload(['class' => 'giant']))
        ->assertSessionHasErrors(['class' => 'Choose Light, Medium, or Heavy.']);
});

it('accepts amounts typed with commas or a peso sign', function () {
    $this->actingAs(ttOwner())
        ->post(route('superadmin.truck-types.store'), ttValidPayload([
            'base_rate' => '₱1,500.50', 'per_km_rate' => '200', 'max_tonnage' => '4,500',
        ]))
        ->assertSessionHasNoErrors();

    $type = TruckType::where('name', 'Light Duty')->firstOrFail();
    expect((float) $type->base_rate)->toBe(1500.5)
        ->and((float) $type->max_tonnage)->toBe(4500.0)
        ->and($type->class)->toBe('light');
});

it('shows field-level messages and keeps everything that was typed', function () {
    ttTruckType(['name' => 'Light Duty']);
    $owner = ttOwner();

    $response = $this->actingAs($owner)
        ->from(route('superadmin.truck-types.index', ['status' => 'active']))
        ->post(route('superadmin.truck-types.store'), ttValidPayload([
            'base_rate' => '1,5OO', '_modal' => 'add',
        ]));

    $response->assertRedirect(route('superadmin.truck-types.index', ['status' => 'active']));
    $response->assertSessionHasErrors([
        'name' => 'A truck type with this name already exists.',
        'base_rate' => 'Enter a number, e.g. 1500.00',
    ]);
    $response->assertSessionHasInput('base_rate', '1,5OO');
    $response->assertSessionHasInput('description', 'Sedans, SUVs, vans');

    $page = $this->actingAs($owner)->get(route('superadmin.truck-types.index', ['status' => 'active']));
    $page->assertSee('data-reopen="add"', false);
    $page->assertSee('Check the 2 highlighted fields. Everything else you entered is kept.');
    $page->assertSee('value="1,5OO"', false);
    $page->assertSee('value="Sedans, SUVs, vans"', false);
    $page->assertSee('aria-invalid="true" aria-describedby="ttNameError"', false);
});

it('reopens the edit form for the same truck type after a failed save', function () {
    $type = ttTruckType(['name' => 'Edit Me', 'class' => 'medium']);
    $owner = ttOwner();

    $this->actingAs($owner)
        ->from(route('superadmin.truck-types.index'))
        ->put(route('superadmin.truck-types.update', $type->id), ttValidPayload([
            'name' => 'Edit Me', 'class' => 'medium', 'per_km_rate' => '', '_modal' => 'edit', '_edit_id' => $type->id,
        ]))
        ->assertSessionHasErrors(['per_km_rate' => 'Enter a rate per km.']);

    $page = $this->actingAs($owner)->get(route('superadmin.truck-types.index'));
    $page->assertSee('data-reopen="edit"', false);
    $page->assertSee('action="' . route('superadmin.truck-types.update', $type->id) . '"', false);
    $page->assertSee('Edit truck type');
    $page->assertSee('Check the highlighted field. Everything else you entered is kept.');
});

it('returns to the same filtered page after a successful save', function () {
    $type = ttTruckType(['name' => 'Keep Place', 'class' => 'light']);
    $from = route('superadmin.truck-types.index', ['search' => 'keep', 'page' => 1]);

    $this->actingAs(ttOwner())
        ->from($from)
        ->put(route('superadmin.truck-types.update', $type->id), ttValidPayload(['name' => 'Keep Place']))
        ->assertRedirect($from)
        ->assertSessionHas('success', 'Keep Place updated.');
});

it('lets an older truck type without a class stay unset', function () {
    $type = ttTruckType(['name' => 'Legacy Type']);

    $this->actingAs(ttOwner())
        ->put(route('superadmin.truck-types.update', $type->id), ttValidPayload(['name' => 'Legacy Type', 'class' => null]))
        ->assertSessionHasNoErrors();

    expect($type->fresh()->class)->toBeNull();
});

it('does not let a set class be cleared', function () {
    $type = ttTruckType(['name' => 'Classed Type', 'class' => 'heavy']);

    $this->actingAs(ttOwner())
        ->put(route('superadmin.truck-types.update', $type->id), ttValidPayload(['name' => 'Classed Type', 'class' => null]))
        ->assertSessionHasErrors('class');

    expect($type->fresh()->class)->toBe('heavy');
});

it('blocks a class that cannot tow the vehicle types already linked to it', function () {
    $type = ttTruckType(['name' => 'Linked Type']);
    $type->vehicleTypes()->attach(ttVehicle(['name' => 'Ten Wheeler', 'weight_kg' => 9000])->id);
    ttVehicle(['name' => 'Wing Van', 'weight_kg' => 6000, 'required_truck_type_id' => $type->id]);

    $this->actingAs(ttOwner())
        ->put(route('superadmin.truck-types.update', $type->id), ttValidPayload(['name' => 'Linked Type', 'class' => 'light']))
        ->assertSessionHasErrors([
            'class' => "The Light class can't tow Ten Wheeler (9,000 kg) and Wing Van (6,000 kg). Change those vehicle types first.",
        ]);

    expect($type->fresh()->class)->toBeNull();

    $this->actingAs(ttOwner())
        ->put(route('superadmin.truck-types.update', $type->id), ttValidPayload(['name' => 'Linked Type', 'class' => 'medium']))
        ->assertSessionHasErrors('class');
});

it('saves a class that fits every linked vehicle type', function () {
    $type = ttTruckType(['name' => 'Fitting Type']);
    $type->vehicleTypes()->attach(ttVehicle(['weight_kg' => 9000])->id);
    ttVehicle(['weight_kg' => null, 'required_truck_type_id' => $type->id]);

    $this->actingAs(ttOwner())
        ->put(route('superadmin.truck-types.update', $type->id), ttValidPayload(['name' => 'Fitting Type', 'class' => 'heavy']))
        ->assertSessionHasNoErrors();

    $fresh = $type->fresh();
    expect($fresh->class)->toBe('heavy')
        ->and($fresh->isCompatibleWithWeight(9000))->toBeTrue()
        ->and($fresh->isCompatibleWithWeight(2000))->toBeFalse();
});

it('keeps the json api for truck type create', function () {
    $owner = ttOwner();

    $this->actingAs($owner)->postJson(route('superadmin.truck-types.store'), ttValidPayload())
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('truckType.class', 'light');

    $this->actingAs($owner)->postJson(route('superadmin.truck-types.store'), ttValidPayload(['name' => 'Other', 'base_rate' => 'abc']))
        ->assertStatus(422)->assertJsonValidationErrors(['base_rate']);
});

it('still blocks disabling a truck type whose only unit is archived', function () {
    $type = ttTruckType(['status' => 'active']);
    ttArchive(ttUnit($type));

    $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $type->id))
        ->assertSessionHas('error');

    expect($type->fresh()->status)->toBe('active');
});

/* ---------- Truck type deactivation protection ---------- */

function ttdNeeds(TruckType $type, string $name, string $status = 'active'): VehicleType
{
    return ttVehicle(['name' => $name, 'required_truck_type_id' => $type->id, 'status' => $status]);
}

function ttdSnapshot(): array
{
    return [
        'vehicles' => VehicleType::orderBy('id')->get(['id', 'name', 'status', 'required_truck_type_id', 'display_order', 'category'])->toArray(),
        'pivot' => \DB::table('vehicle_type_truck_type')->orderBy('vehicle_type_id')->get()->map(fn ($r) => (array) $r)->all(),
        'bookings' => Booking::orderBy('id')->get(['id', 'status', 'truck_type_id'])->toArray(),
    ];
}

it('refuses to disable a truck type that an active vehicle type requires, naming it', function () {
    $type = ttTruckType(['name' => 'Light Duty']);
    ttdNeeds($type, 'Sedan');
    $before = ttdSnapshot();

    $this->actingAs(ttOwner())->from(route('superadmin.truck-types.index'))->patch(route('superadmin.truck-types.toggle', $type->id))
        ->assertRedirect(route('superadmin.truck-types.index'))
        ->assertSessionHas('error', "Light Duty can't be disabled while active vehicle types need it: Sedan. Customers can still choose it, but the booking would fail. In Vehicle Types, give it a different required truck type or disable it first.");

    expect($type->fresh()->status)->toBe('active')->and(ttdSnapshot())->toBe($before);
});

it('names every dependent active vehicle type in the refusal', function () {
    $type = ttTruckType(['name' => 'Heavy Duty']);
    ttdNeeds($type, 'Wing van');
    ttdNeeds($type, '10-wheeler');

    $this->actingAs(ttOwner())->patchJson(route('superadmin.truck-types.toggle', $type->id))
        ->assertStatus(422)
        ->assertJsonPath('message', "Heavy Duty can't be disabled while active vehicle types need it: 10-wheeler and Wing van. Customers can still choose them, but the booking would fail. In Vehicle Types, give them a different required truck type or disable them first.");

    expect($type->fresh()->status)->toBe('active');
});

it('refuses the same request through the other disable route', function () {
    $type = ttTruckType();
    ttdNeeds($type, 'Sedan');

    $this->actingAs(ttOwner())->patchJson(route('superadmin.truck-types.data.toggle', $type->id))->assertStatus(422);

    expect($type->fresh()->status)->toBe('active');
});

it('allows disabling when the only vehicle types requiring it are inactive', function () {
    $type = ttTruckType();
    ttdNeeds($type, 'Retired Vehicle', 'inactive');

    $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $type->id))->assertSessionHas('success');

    expect($type->fresh()->status)->toBe('inactive');
});

it('is not blocked by vehicle types that require a different truck type', function () {
    $type = ttTruckType();
    ttdNeeds(ttTruckType(), 'Other Truck Vehicle');

    $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $type->id))->assertSessionHas('success');

    expect($type->fresh()->status)->toBe('inactive');
});

it('is not blocked by a many-to-many link alone, since bookings use the required truck type', function () {
    $type = ttTruckType();
    $vehicle = ttdNeeds(ttTruckType(), 'Linked Only');
    $type->vehicleTypes()->attach($vehicle->id);

    $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $type->id))->assertSessionHas('success');

    expect($type->fresh()->status)->toBe('inactive')
        ->and(app(\App\Services\BookingService::class)->resolveRequiredTruckType($vehicle->id)[0]?->id)->toBe($vehicle->required_truck_type_id);
});

it('keeps the existing truck and archived-truck restrictions first', function () {
    $type = ttTruckType();
    ttdNeeds($type, 'Sedan');
    ttUnit($type);
    $archivedOnly = ttTruckType();
    ttdNeeds($archivedOnly, 'Van');
    ttArchive(ttUnit($archivedOnly));

    foreach ([$type, $archivedOnly] as $t) {
        $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $t->id))
            ->assertSessionHas('error', 'This tow truck type is busy and cannot be set inactive while it is assigned or booked.');
        expect($t->fresh()->status)->toBe('active');
    }
});

it('still enables an inactive truck type even if active vehicle types require it', function () {
    $type = ttTruckType(['status' => 'inactive']);
    ttdNeeds($type, 'Sedan');

    $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $type->id))->assertSessionHas('success');

    expect($type->fresh()->status)->toBe('active');
});

it('disables normally once nothing depends on it, without touching vehicle types or bookings', function () {
    $type = ttTruckType();
    $vehicle = ttdNeeds($type, 'Sedan');
    Booking::create([
        'customer_id' => ttCustomer()->id, 'truck_type_id' => $type->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 1800, 'status' => 'completed',
    ]);
    $owner = ttOwner();

    $this->actingAs($owner)->patch(route('superadmin.truck-types.toggle', $type->id))->assertSessionHas('error');
    $this->actingAs($owner)->patch(route('superadmin.vehicle-types.toggle', $vehicle->id))->assertSessionHas('success');
    $before = ttdSnapshot();

    $this->actingAs($owner)->patch(route('superadmin.truck-types.toggle', $type->id))->assertSessionHas('success');

    expect($type->fresh()->status)->toBe('inactive')->and(ttdSnapshot())->toBe($before);
});

it('explains in the menu why Disable is unavailable and what to do', function () {
    $type = ttTruckType(['name' => 'Menu Type']);
    ttdNeeds($type, 'Hatchback');
    ttdNeeds($type, 'Sedan');

    $response = $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'));

    $response->assertSee('In use by 2 active vehicle types (Hatchback and Sedan).');
    $response->assertSee('data-guidance="First, in Vehicle Types, give those vehicle types a different required truck type or disable them."', false);
    expect($response->getContent())->toMatch('/data-action="disable"[^>]*aria-disabled="true"/');
});

it('lists the steps that apply when several things block disabling', function () {
    $type = ttTruckType();
    ttUnit($type);
    ttArchive(ttUnit($type));
    foreach (['A Car', 'B Car', 'C Car', 'D Car', 'E Car'] as $name) {
        ttdNeeds($type, $name);
    }

    $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'))
        ->assertSee('In use by 1 truck, 1 archived truck and 5 active vehicle types (A Car, B Car, C Car and 2 more).')
        ->assertSee('data-guidance="First, move its trucks, including archived ones, to another truck type and in Vehicle Types, give those vehicle types a different required truck type or disable them."', false);
});

it('leaves customer vehicle visibility and booking compatibility unchanged after a refused disable', function () {
    $type = ttTruckType();
    $vehicle = ttdNeeds($type, 'Visible Sedan');
    $vehicle->update(['category' => 'cars_suvs']);

    $this->actingAs(ttOwner())->patch(route('superadmin.truck-types.toggle', $type->id))->assertSessionHas('error');

    $names = collect($this->getJson('/api/v1/vehicle-types/by-category/cars_suvs')->json('vehicleTypes'))->pluck('name');
    [$resolved, $error] = app(\App\Services\BookingService::class)->resolveRequiredTruckType($vehicle->id);

    expect($names->all())->toContain('Visible Sedan')
        ->and($resolved?->id)->toBe($type->id)
        ->and($error)->toBeNull();
});
