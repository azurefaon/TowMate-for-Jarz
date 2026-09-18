<?php

use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\UnitTeamAssignmentService;

function driverSyncTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'Driver Sync Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);
}

function driverSyncTeamLeaderRole(): Role
{
    return Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Tow unit team leader']);
}

function driverSyncActor(): User
{
    $role = Role::find(2) ?? tap(new Role(['name' => 'Dispatcher', 'description' => 'Dispatch staff']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

it('populates an empty unit driver_name from the team leaders driver details on assignment', function () {
    $truckType = driverSyncTruckType();
    $teamLeaderRole = driverSyncTeamLeaderRole();
    $actor = driverSyncActor();

    $teamLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'driver_first_name' => 'PAULO',
        'driver_middle_name' => null,
        'driver_last_name' => 'PAULO',
    ]);

    $unit = Unit::create([
        'name' => 'Driver Sync Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unit, $teamLeader->id, $actor);

    expect($unit->fresh()->driver_name)->toBe('PAULO PAULO')
        ->and($unit->fresh()->driver_id)->toBeNull();
});

it('includes the middle name when building the driver full name', function () {
    $truckType = driverSyncTruckType();
    $teamLeaderRole = driverSyncTeamLeaderRole();
    $actor = driverSyncActor();

    $teamLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'driver_first_name' => 'Juan',
        'driver_middle_name' => 'Santos',
        'driver_last_name' => 'Dela Cruz',
    ]);

    $unit = Unit::create([
        'name' => 'Driver Sync Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unit, $teamLeader->id, $actor);

    expect($unit->fresh()->driver_name)->toBe('Juan Santos Dela Cruz');
});

it('carries the team leaders crew member details into empty unit crew slots', function () {
    $truckType = driverSyncTruckType();
    $teamLeaderRole = driverSyncTeamLeaderRole();
    $actor = driverSyncActor();

    $teamLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'driver_first_name' => 'Mark',
        'driver_last_name' => 'Reyes',
        'crew_member_1_name' => 'Crew One',
        'crew_member_2_name' => 'Crew Two',
    ]);

    $unit = Unit::create([
        'name' => 'Driver Sync Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unit, $teamLeader->id, $actor);

    $fresh = $unit->fresh();
    expect($fresh->crew_member_1_name)->toBe('Crew One')
        ->and($fresh->crew_member_2_name)->toBe('Crew Two');
});

it('never overwrites an existing driver or crew member already on the unit', function () {
    $truckType = driverSyncTruckType();
    $teamLeaderRole = driverSyncTeamLeaderRole();
    $actor = driverSyncActor();

    $teamLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'driver_first_name' => 'PAULO',
        'driver_last_name' => 'PAULO',
        'crew_member_1_name' => 'New Crew',
    ]);

    $unit = Unit::create([
        'name' => 'Driver Sync Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'driver_name' => 'Existing Driver',
        'crew_member_1_name' => 'Existing Crew',
    ]);

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unit, $teamLeader->id, $actor);

    $fresh = $unit->fresh();
    expect($fresh->driver_name)->toBe('Existing Driver')
        ->and($fresh->crew_member_1_name)->toBe('Existing Crew');
});

it('leaves driver_name empty when the team leader has no driver details on file', function () {
    $truckType = driverSyncTruckType();
    $teamLeaderRole = driverSyncTeamLeaderRole();
    $actor = driverSyncActor();

    $teamLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'driver_first_name' => null,
        'driver_last_name' => null,
    ]);

    $unit = Unit::create([
        'name' => 'Driver Sync Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unit, $teamLeader->id, $actor);

    expect($unit->fresh()->driver_name)->toBeNull();
});
