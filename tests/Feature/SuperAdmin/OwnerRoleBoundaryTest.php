<?php

use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Route;

function ownerRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ownerUser(): User
{
    ownerRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function dispatcherUser(): User
{
    ownerRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

function ownerTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'ORB Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function ownerTeamLeader(): User
{
    ownerRole(3, 'Team Leader');

    return User::factory()->create(['role_id' => 3, 'status' => 'active', 'must_change_password' => false]);
}

function ownerUnit(): Unit
{
    return Unit::create([
        'name' => 'ORB Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => ownerTruckType()->id,
        'status' => 'available',
    ]);
}

it('owner can view the dashboard', function () {
    $this->actingAs(ownerUser())->get(route('superadmin.dashboard'))->assertOk();
});

it('owner can view revenue', function () {
    $this->actingAs(ownerUser())->get(route('superadmin.revenue.index'))->assertOk();
});

it('owner can view reports', function () {
    $this->actingAs(ownerUser())->get(route('superadmin.reports.index'))->assertOk();
});

it('owner can view bookings', function () {
    $this->actingAs(ownerUser())->get(route('superadmin.bookings.index'))->assertOk();
});

it('owner cannot mutate bookings operationally because no such route exists', function () {
    expect(Route::has('superadmin.bookings.store'))->toBeFalse();
    expect(Route::has('superadmin.bookings.update'))->toBeFalse();
    expect(Route::has('superadmin.bookings.destroy'))->toBeFalse();
});

it('owner can manage legitimate fleet assets', function () {
    $owner = ownerUser();
    $truckType = ownerTruckType();

    $response = $this->actingAs($owner)->post(route('superadmin.units.store'), [
        'plate_number' => 'ABC1234',
        'truck_type_id' => $truckType->id,
    ]);

    $response->assertRedirect(route('superadmin.unit-truck.index'));
    $this->assertDatabaseHas('units', ['plate_number' => 'ABC1234']);
});

it('owner can manage truck types and rates', function () {
    $owner = ownerUser();

    $response = $this->actingAs($owner)->post(route('superadmin.truck-types.store'), [
        'name' => 'Owner Managed Truck',
        'base_rate' => 2000,
        'per_km_rate' => 80,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('truck_types', ['name' => 'Owner Managed Truck']);
});

it('owner can manage vehicle types', function () {
    $this->actingAs(ownerUser())->get(route('superadmin.vehicle-types.index'))->assertOk();
});

it('owner can view the operations monitor', function () {
    $this->actingAs(ownerUser())->get(route('superadmin.monitoring.index'))->assertOk();
});

it('owner cannot dispatch from the operations monitor because no mutation route is exposed there', function () {
    expect(Route::has('superadmin.monitoring.dispatch'))->toBeFalse();
    expect(Route::has('superadmin.monitoring.assign'))->toBeFalse();
});

it('owner cannot assign a team leader to a unit', function () {
    $owner = ownerUser();
    $unit = ownerUnit();
    $teamLeader = ownerTeamLeader();

    expect(Route::has('superadmin.units.assign-team-leader'))->toBeFalse();

    $response = $this->actingAs($owner)->patch("/superadmin/units/{$unit->id}/assign-team-leader", [
        'team_leader_id' => $teamLeader->id,
    ]);

    $response->assertNotFound();
    $this->assertDatabaseHas('units', ['id' => $unit->id, 'team_leader_id' => null]);
});

it('owner cannot remove a team leader from a unit operationally', function () {
    $owner = ownerUser();
    $teamLeader = ownerTeamLeader();
    $unit = Unit::create([
        'name' => 'ORB Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => ownerTruckType()->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    expect(Route::has('superadmin.units.remove-team-leader'))->toBeFalse();

    $response = $this->actingAs($owner)->patch("/superadmin/units/{$unit->id}/remove-team-leader");

    $response->assertNotFound();
    $this->assertDatabaseHas('units', ['id' => $unit->id, 'team_leader_id' => $teamLeader->id]);
});

it('owner cannot borrow crew between units', function () {
    $owner = ownerUser();
    $unit = ownerUnit();

    expect(Route::has('superadmin.units.borrow-crew'))->toBeFalse();

    $response = $this->actingAs($owner)->post("/superadmin/units/{$unit->id}/borrow-crew", [
        'from_unit_id' => $unit->id,
        'from_slot' => 'driver_1',
        'to_slot' => 'driver_1',
    ]);

    $response->assertNotFound();
});

it('owner cannot return borrowed crew', function () {
    expect(Route::has('unit-crew-loans.return'))->toBeFalse();

    $owner = ownerUser();
    $response = $this->actingAs($owner)->patch('/superadmin/unit-crew-loans/1/return');

    $response->assertNotFound();
});

it('dispatcher retains legitimate team leader assignment capability', function () {
    ownerRole(2, 'Dispatcher');
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
    $teamLeader = ownerTeamLeader();
    $unit = ownerUnit();

    $response = $this->actingAs($dispatcher)->post(route('admin.drivers.units.assign-team-leader', $unit->id), [
        'team_leader_id' => $teamLeader->id,
    ]);

    $response->assertStatus(302);
    $this->assertDatabaseHas('units', ['id' => $unit->id, 'team_leader_id' => $teamLeader->id]);
});

it('dispatcher retains dispatch functionality', function () {
    $dispatcher = dispatcherUser();

    $this->actingAs($dispatcher)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();
});

it('dispatcher cannot access owner financial or business-management actions', function () {
    $dispatcher = dispatcherUser();

    $this->actingAs($dispatcher)->get(route('superadmin.dashboard'))->assertForbidden();
    $this->actingAs($dispatcher)->get(route('superadmin.revenue.index'))->assertForbidden();
    $this->actingAs($dispatcher)->get(route('superadmin.settings.index'))->assertForbidden();
});

it('owner cannot access dispatcher-only operational routes', function () {
    $owner = ownerUser();

    $this->actingAs($owner)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($owner)->get(route('admin.dispatch'))->assertForbidden();
    $this->actingAs($owner)->get(route('admin.jobs'))->assertForbidden();
});

it('hidden owner ui controls cannot be bypassed by manually calling the removed endpoints', function () {
    $owner = ownerUser();
    $unit = ownerUnit();

    $this->actingAs($owner)->patch("/superadmin/units/{$unit->id}/assign-team-leader", [])->assertNotFound();
    $this->actingAs($owner)->patch("/superadmin/units/{$unit->id}/remove-team-leader")->assertNotFound();
    $this->actingAs($owner)->post("/superadmin/units/{$unit->id}/borrow-crew", [])->assertNotFound();
});

it('owner dashboard does not render team leader assignment controls', function () {
    $owner = ownerUser();
    ownerUnit();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertDontSee('assign-team-leader', false);
    $response->assertDontSee('js-assign-leader', false);
});

it('owner settings page no longer presents technical system administration controls', function () {
    $response = $this->actingAs(ownerUser())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertDontSee('Upload New APK');
    $response->assertDontSee('Team Leader Capacity');
    $response->assertDontSee('Customer Inactivity Lock');
});

it('owner sidebar no longer presents manage users as a business module', function () {
    $response = $this->actingAs(ownerUser())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertDontSee('Manage Users');
});
