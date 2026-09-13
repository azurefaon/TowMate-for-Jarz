<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;

function prRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function prOwner(): User
{
    prRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function prDispatcher(): User
{
    prRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function prUnit(string $name): Unit
{
    $truckType = TruckType::create(['name' => 'PR Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);

    return Unit::create([
        'name' => $name,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);
}

it('owner can create a driver personnel record with no login account', function () {
    $owner = prOwner();
    $usersBefore = User::count();

    $response = $this->actingAs($owner)->post(route('superadmin.personnel-records.store'), [
        'first_name' => 'Marco',
        'last_name' => 'Reyes',
        'role' => 'driver',
    ]);

    $response->assertRedirect();
    expect(Personnel::where('first_name', 'Marco')->where('last_name', 'Reyes')->exists())->toBeTrue();
    expect(User::count())->toBe($usersBefore);
});

it('owner can create a pahinante personnel record with no login account', function () {
    $owner = prOwner();
    $usersBefore = User::count();

    $response = $this->actingAs($owner)->post(route('superadmin.personnel-records.store'), [
        'first_name' => 'Luisito',
        'last_name' => 'Bautista',
        'role' => 'crew',
    ]);

    $response->assertRedirect();
    $record = Personnel::where('first_name', 'Luisito')->first();
    expect($record)->not->toBeNull();
    expect($record->role)->toBe('crew');
    expect(User::count())->toBe($usersBefore);
});

it('personnel records have no password, email, or role_id columns', function () {
    $record = Personnel::create([
        'first_name' => 'Test', 'last_name' => 'Person', 'role' => 'driver', 'personnel_status' => 'active',
    ]);

    expect($record->getAttributes())->not->toHaveKey('password');
    expect($record->getAttributes())->not->toHaveKey('email');
    expect($record->getAttributes())->not->toHaveKey('role_id');
    expect($record->getAttributes())->not->toHaveKey('must_change_password');
});

it('dispatcher cannot create personnel records', function () {
    $this->actingAs(prDispatcher())->post(route('superadmin.personnel-records.store'), [
        'first_name' => 'Blocked', 'last_name' => 'Person', 'role' => 'driver',
    ])->assertForbidden();
});

it('owner can edit a personnel record', function () {
    $owner = prOwner();
    $record = Personnel::create(['first_name' => 'Old', 'last_name' => 'Name', 'role' => 'driver', 'personnel_status' => 'active']);

    $this->actingAs($owner)->put(route('superadmin.personnel-records.update', $record), [
        'first_name' => 'New', 'last_name' => 'Name', 'role' => 'driver',
    ])->assertRedirect();

    expect($record->fresh()->first_name)->toBe('New');
});

it('owner can set and persist a home unit for a personnel record', function () {
    $owner = prOwner();
    $unit = prUnit('PR Home Unit');
    $record = Personnel::create(['first_name' => 'Homed', 'last_name' => 'Driver', 'role' => 'driver', 'personnel_status' => 'active']);

    $this->actingAs($owner)->patch(route('superadmin.personnel-records.home-unit', $record), [
        'home_unit_id' => $unit->id,
    ])->assertRedirect();

    expect($record->fresh()->home_unit_id)->toBe($unit->id);
});

it('personnel active/inactive status is independent of any users.status field', function () {
    $owner = prOwner();
    $record = Personnel::create(['first_name' => 'Status', 'last_name' => 'Test', 'role' => 'crew', 'personnel_status' => 'active']);

    $this->actingAs($owner)->patch(route('superadmin.personnel-records.toggle', $record))->assertRedirect();

    expect($record->fresh()->personnel_status)->toBe('inactive');

    $log = AuditLog::where('action', 'personnel_deactivated')->where('entity_type', 'Personnel')->latest()->first();
    expect($log)->not->toBeNull();
});

it('dispatcher borrow/return of free-text crew does not change the personnel home unit', function () {
    $owner = prOwner();
    $dispatcher = prDispatcher();

    $home = prUnit('PR Crew Home');
    $target = prUnit('PR Crew Target');

    $home->update(['crew_member_1_name' => 'Jerome Reyes']);
    $record = Personnel::create([
        'first_name' => 'Jerome', 'last_name' => 'Reyes', 'role' => 'crew',
        'home_unit_id' => $home->id, 'personnel_status' => 'active',
    ]);

    $this->actingAs($owner); // no-op, just establishing session context isn't required here

    app(\App\Services\UnitTeamAssignmentService::class)->assignSlotPerson(
        $target, 'crew_member_1', $home, 'crew_member_1', $dispatcher
    );

    expect($record->fresh()->home_unit_id)->toBe($home->id);
    expect($home->fresh()->crew_member_1_name)->toBeNull();
    expect($target->fresh()->crew_member_1_name)->toBe('Jerome Reyes');
});

it('shows a backfilled crew personnel record as borrowed after dispatcher moves them, home unit unchanged', function () {
    $owner = prOwner();
    $dispatcher = prDispatcher();

    $home = prUnit('PR Visible Home');
    $target = prUnit('PR Visible Target');

    $home->update(['crew_member_1_name' => 'Visible Crew Person']);
    Personnel::create([
        'first_name' => 'Visible', 'last_name' => 'Crew Person', 'role' => 'crew',
        'home_unit_id' => $home->id, 'personnel_status' => 'active',
    ]);

    app(\App\Services\UnitTeamAssignmentService::class)->assignSlotPerson(
        $target, 'crew_member_1', $home, 'crew_member_1', $dispatcher
    );

    $response = $this->actingAs($owner)->get(route('superadmin.personnel.index'));

    $response->assertOk();
    $response->assertSee('Borrowed');
    $response->assertSee($home->name);
    $response->assertSee($target->name);
});

it('does not incorrectly merge ambiguous legacy names appearing on more than one unit', function () {
    $unitA = prUnit('PR Ambiguous A');
    $unitB = prUnit('PR Ambiguous B');

    $unitA->update(['crew_member_1_name' => 'Ambiguous Person']);
    $unitB->update(['crew_member_1_name' => 'Ambiguous Person']);

    $ambiguous = Personnel::ambiguousLegacyRosterNames();

    expect($ambiguous)->toContain('crew|ambiguous person');
});

it('does not flag unique legacy names as ambiguous', function () {
    $unit = prUnit('PR Unique Unit');
    $unit->update(['crew_member_1_name' => 'Unique Person Only']);

    $ambiguous = Personnel::ambiguousLegacyRosterNames();

    expect($ambiguous)->not->toContain('crew|unique person only');
});

it('does not change historical booking personnel snapshot when a personnel home unit changes', function () {
    $owner = prOwner();
    $originalUnit = prUnit('PR Snapshot Original');
    $newHomeUnit = prUnit('PR Snapshot New Home');

    $record = Personnel::create([
        'first_name' => 'Snapshot', 'last_name' => 'Driver', 'role' => 'driver',
        'home_unit_id' => $originalUnit->id, 'personnel_status' => 'active',
    ]);

    $customer = Customer::create([
        'full_name' => 'PR Snapshot Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'pr-snapshot-' . uniqid() . '@example.com',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $originalUnit->truck_type_id,
        'assigned_unit_id' => $originalUnit->id,
        'driver_name' => $record->full_name,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->actingAs($owner)->patch(route('superadmin.personnel-records.home-unit', $record), [
        'home_unit_id' => $newHomeUnit->id,
    ]);

    expect($booking->fresh()->assigned_unit_id)->toBe($originalUnit->id);
    expect($booking->fresh()->driver_name)->toBe($record->full_name);
});
