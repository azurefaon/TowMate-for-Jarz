<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;

function ppRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ppOwner(): User
{
    ppRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function ppDispatcher(): User
{
    ppRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function ppTeamLeader(string $name = 'PP Team Leader'): User
{
    ppRole(3, 'Team Leader');

    return User::factory()->create(['role_id' => 3, 'name' => $name, 'status' => 'active']);
}

function ppDriverUser(string $name = 'PP Driver'): User
{
    ppRole(4, 'Driver');

    return User::factory()->create(['role_id' => 4, 'name' => $name, 'status' => 'active']);
}

function ppUnit(string $name): Unit
{
    $truckType = TruckType::create(['name' => 'PP Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);

    return Unit::create([
        'name' => $name,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);
}

it('owner can view the personnel page', function () {
    ppTeamLeader('PP Visible Leader');

    $response = $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'));

    $response->assertOk();
    $response->assertSee('PP Visible Leader');
    $response->assertSee('Team Leader');
});

it('dispatcher cannot access the owner personnel page', function () {
    $this->actingAs(ppDispatcher())->get(route('superadmin.personnel.index'))->assertForbidden();
});

it('lists personnel without exposing any login credential fields', function () {
    ppDriverUser('PP No Login Driver');

    $response = $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'));

    $response->assertOk();
    $response->assertDontSee('password', false);
    $response->assertDontSee('type="password"', false);
});

it('owner can set a personnel home unit', function () {
    $owner = ppOwner();
    $driver = ppDriverUser();
    $unit = ppUnit('PP Home Unit 01');

    $response = $this->actingAs($owner)->patch(route('superadmin.personnel.home-unit', $driver), [
        'home_unit_id' => $unit->id,
    ]);

    $response->assertRedirect();
    expect($driver->fresh()->home_unit_id)->toBe($unit->id);
});

it('records a personnel_home_unit_changed business audit event when home unit changes', function () {
    $owner = ppOwner();
    $driver = ppDriverUser();
    $unit = ppUnit('PP Audit Unit');

    $this->actingAs($owner)->patch(route('superadmin.personnel.home-unit', $driver), [
        'home_unit_id' => $unit->id,
    ]);

    $log = AuditLog::where('action', 'personnel_home_unit_changed')->latest()->first();

    expect($log)->not->toBeNull();
    expect($log->entity_type)->toBe('User');
    expect($log->entity_id)->toBe($driver->id);
});

it('owner can activate and deactivate personnel without touching account status', function () {
    $owner = ppOwner();
    $teamLeader = ppTeamLeader();

    expect($teamLeader->fresh()->personnel_enabled)->toBeTrue();

    $this->actingAs($owner)->patch(route('superadmin.personnel.toggle', $teamLeader))->assertRedirect();

    $teamLeader->refresh();
    expect($teamLeader->personnel_enabled)->toBeFalse();
    expect($teamLeader->status)->toBe('active');

    $log = AuditLog::where('action', 'personnel_deactivated')->latest()->first();
    expect($log)->not->toBeNull();
});

it('classifies personnel lifecycle events as business, excluded from system admin audit logs', function () {
    ppRole(6, 'System Admin');
    $admin = User::factory()->create(['role_id' => 6, 'status' => 'active', 'must_change_password' => false]);

    $owner = ppOwner();
    $teamLeader = ppTeamLeader('PP Hidden From System Admin');

    $this->actingAs($owner)->patch(route('superadmin.personnel.toggle', $teamLeader));

    $response = $this->actingAs($admin)->get(route('system-admin.audit-logs.index'));

    $response->assertOk();
    $response->assertDontSee('set to Inactive.');

    expect(AuditLog::where('action', 'personnel_deactivated')->exists())->toBeTrue();
});

it('shows personnel lifecycle events in owner business activity', function () {
    $owner = ppOwner();
    $teamLeader = ppTeamLeader('PP Shown In Business Activity');

    $this->actingAs($owner)->patch(route('superadmin.personnel.toggle', $teamLeader));

    $response = $this->actingAs($owner)->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('Personnel Deactivated');
    $response->assertSee('PP Shown In Business Activity');
});

it('home unit stays stable when dispatcher borrows the team leader elsewhere', function () {
    $owner = ppOwner();
    $dispatcher = ppDispatcher();

    $home = ppUnit('PP Home Base');
    $borrowTarget = ppUnit('PP Borrow Target');

    $teamLeader = ppTeamLeader('PP Stable Leader');
    $home->update(['team_leader_id' => $teamLeader->id]);

    $this->actingAs($owner)->patch(route('superadmin.personnel.home-unit', $teamLeader), [
        'home_unit_id' => $home->id,
    ]);

    expect($teamLeader->fresh()->home_unit_id)->toBe($home->id);

    app(\App\Services\UnitTeamAssignmentService::class)->assignTeamLeader($borrowTarget, $teamLeader->id, $dispatcher);

    expect($teamLeader->fresh()->home_unit_id)->toBe($home->id);
});

it('shows a borrowed team leader as borrowed while home unit remains unchanged', function () {
    $owner = ppOwner();
    $dispatcher = ppDispatcher();

    $home = ppUnit('PP Borrow Home');
    $borrowTarget = ppUnit('PP Borrow Elsewhere');

    $teamLeader = ppTeamLeader('PP Borrowed Leader');
    $home->update(['team_leader_id' => $teamLeader->id]);
    $teamLeader->update(['home_unit_id' => $home->id]);

    app(\App\Services\UnitTeamAssignmentService::class)->assignTeamLeader($borrowTarget, $teamLeader->id, $dispatcher);

    $response = $this->actingAs($owner)->get(route('superadmin.personnel.index'));

    $response->assertOk();
    $response->assertSee('Borrowed');
    $response->assertSee($home->name);
    $response->assertSee($borrowTarget->name);
});

it('dispatcher borrow action does not itself write a personnel_home_unit_changed event', function () {
    $dispatcher = ppDispatcher();

    $home = ppUnit('PP No Mutation Home');
    $borrowTarget = ppUnit('PP No Mutation Target');

    $teamLeader = ppTeamLeader('PP No Mutation Leader');
    $home->update(['team_leader_id' => $teamLeader->id]);
    $teamLeader->update(['home_unit_id' => $home->id]);

    app(\App\Services\UnitTeamAssignmentService::class)->assignTeamLeader($borrowTarget, $teamLeader->id, $dispatcher);

    $log = AuditLog::where('action', 'personnel_home_unit_changed')
        ->where('entity_id', $teamLeader->id)
        ->first();

    expect($log)->toBeNull();
});

it('owner cannot reach dispatcher operational borrow or return routes', function () {
    ppRole(3, 'Team Leader');
    $owner = ppOwner();
    $unit = ppUnit('PP Owner Blocked Unit');
    $teamLeader = ppTeamLeader();

    $this->actingAs($owner)
        ->post(route('admin.drivers.units.assign-team-leader', $unit), ['team_leader_id' => $teamLeader->id])
        ->assertForbidden();
});

it('shows home assignments grouped by unit as master configuration', function () {
    $owner = ppOwner();
    $unit = ppUnit('PP Roster Unit');
    $teamLeader = ppTeamLeader('PP Roster Leader');
    $teamLeader->update(['home_unit_id' => $unit->id]);

    $response = $this->actingAs($owner)->get(route('superadmin.home-assignments.index'));

    $response->assertOk();
    $response->assertSee('PP Roster Unit');
    $response->assertSee('PP Roster Leader');
    $response->assertDontSee('Borrow');
    $response->assertDontSee('Return');
});

it('does not change historical booking personnel snapshot when home unit changes later', function () {
    $owner = ppOwner();
    $teamLeader = ppTeamLeader('PP Snapshot Leader');
    $originalUnit = ppUnit('PP Snapshot Original Unit');
    $newHomeUnit = ppUnit('PP Snapshot New Home');

    $customer = Customer::create([
        'full_name' => 'PP Snapshot Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'pp-snapshot-' . uniqid() . '@example.com',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $originalUnit->truck_type_id,
        'assigned_unit_id' => $originalUnit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->actingAs($owner)->patch(route('superadmin.personnel.home-unit', $teamLeader), [
        'home_unit_id' => $newHomeUnit->id,
    ]);

    expect($booking->fresh()->assigned_unit_id)->toBe($originalUnit->id);
    expect($booking->fresh()->assigned_team_leader_id)->toBe($teamLeader->id);
});
