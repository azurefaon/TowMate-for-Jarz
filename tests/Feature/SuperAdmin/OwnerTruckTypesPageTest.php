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
        'base_rate' => 2000,
        'per_km_rate' => 80,
        'max_tonnage' => 8000,
        'description' => '10-wheelers, trailer trucks',
    ]);

    $response->assertRedirect(route('superadmin.truck-types.index'));
    $this->assertDatabaseHas('truck_types', [
        'name' => 'Heavy Duty Test',
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

it('renders the fleet management tabs with truck types active', function () {
    $response = $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'));

    $response->assertOk();
    $response->assertSee('Truck Types &amp; Rates', false);
    $response->assertSee('Trucks');
    $response->assertSee('Vehicle Types');
    $response->assertSee('is-active', false);
});

it('keeps the trucks and vehicle types pages reachable through the fleet tabs', function () {
    $owner = ttOwner();

    $this->actingAs($owner)->get(route('superadmin.unit-truck.index'))->assertOk();
    $this->actingAs($owner)->get(route('superadmin.vehicle-types.index'))->assertOk();
});

it('shows a single Fleet Management sidebar destination instead of three child links', function () {
    $response = $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'));

    $response->assertOk();
    $response->assertSee('>Fleet Management<', false);
    $response->assertDontSee('sidebarGroupFleet', false);
    $response->assertDontSee('sidebar-group-toggle" title="Fleet Management"', false);
});

it('highlights Fleet Management as the active sidebar destination on all three fleet pages', function () {
    $owner = ttOwner();
    $pattern = '/title="Fleet Management"\s*class="active"/';

    $truckTypesResponse = $this->actingAs($owner)->get(route('superadmin.truck-types.index'));
    $truckTypesResponse->assertOk();
    expect(preg_match($pattern, $truckTypesResponse->getContent()))->toBe(1);

    $trucksResponse = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));
    $trucksResponse->assertOk();
    expect(preg_match($pattern, $trucksResponse->getContent()))->toBe(1);

    $vehicleTypesResponse = $this->actingAs($owner)->get(route('superadmin.vehicle-types.index'));
    $vehicleTypesResponse->assertOk();
    expect(preg_match($pattern, $vehicleTypesResponse->getContent()))->toBe(1);
});

it('exposes accessible labels for the icon-only row actions', function () {
    ttTruckType(['status' => 'active']);
    ttTruckType(['status' => 'inactive']);

    $response = $this->actingAs(ttOwner())->get(route('superadmin.truck-types.index'));

    $response->assertOk();
    $response->assertSee('aria-label="Edit truck type"', false);
    $response->assertSee('aria-label="Disable truck type"', false);
    $response->assertSee('aria-label="Enable truck type"', false);
    $response->assertSee('aria-label="Delete truck type"', false);
});
