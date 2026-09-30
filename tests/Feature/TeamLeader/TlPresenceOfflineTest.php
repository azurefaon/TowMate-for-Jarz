<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function tpoRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function (Role $role) use ($id) {
        $role->id = $id;
        $role->save();
    });
}

function tpoTeamLeader(): User
{
    tpoRole(3, 'Team Leader');

    return User::factory()->create([
        'role_id' => 3,
        'must_change_password' => false,
        'last_ping_at' => now(),
    ]);
}

function tpoTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'Presence Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);
}

/** A unit currently held by the team leader, with dispatcher-side state that a release must clear. */
function tpoUnit(TruckType $truckType, User $teamLeader): Unit
{
    $unit = Unit::create([
        'name' => 'Presence Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'on_job',
    ]);

    Unit::where('id', $unit->id)->update([
        'dispatcher_status' => 'reserved',
        'dispatcher_note' => 'Held for a job',
        'zone_confirmed' => true,
    ]);

    return $unit->fresh();
}

function tpoBooking(TruckType $truckType, Unit $unit, User $teamLeader, string $status): Booking
{
    $customer = Customer::create([
        'full_name' => 'Presence Customer',
        'phone' => '09171234567',
        'email' => 'presence-' . uniqid() . '@example.com',
    ]);

    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'driver_name' => 'Driver One',
        'pickup_address' => 'Quezon City Test Pickup',
        'pickup_lat' => 14.6760,
        'pickup_lng' => 121.0437,
        'dropoff_address' => 'Makati Test Dropoff',
        'dropoff_lat' => 14.5547,
        'dropoff_lng' => 121.0244,
        'distance_km' => 12.5,
        'base_rate' => 1500,
        'per_km_rate' => 300,
        'computed_total' => 4050,
        'final_total' => 4536,
        'vat_exclusive_total' => 4050,
        'status' => $status,
        'assigned_at' => now(),
    ]);
}

it('going offline releases the unit and un-assigns a booking that has not been started', function () {
    $leader = tpoTeamLeader();
    $truckType = tpoTruckType();
    $unit = tpoUnit($truckType, $leader);
    $booking = tpoBooking($truckType, $unit, $leader, 'assigned');

    expect($leader->fresh()->last_ping_at)->not->toBeNull();

    Sanctum::actingAs($leader, ['*']);

    $this->postJson('/api/v1/team-leader/presence/offline')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('presence', 'offline');

    // Presence cleared.
    expect($leader->fresh()->last_ping_at)->toBeNull();

    // Unit released back to the pool with its dispatcher-side state cleared.
    $unit->refresh();
    expect($unit->team_leader_id)->toBeNull();
    expect($unit->status)->toBe('available');
    expect($unit->dispatcher_status)->toBeNull();
    expect($unit->dispatcher_note)->toBeNull();
    expect((bool) $unit->zone_confirmed)->toBeFalse();

    // The not-yet-started booking goes back to dispatch for reassignment.
    $booking->refresh();
    expect($booking->assigned_team_leader_id)->toBeNull();
    expect($booking->status)->toBe('assigned');
    expect($booking->driver_name)->toBeNull();
});

it('going offline keeps the unit and the booking of a job that is already underway', function () {
    $leader = tpoTeamLeader();
    $truckType = tpoTruckType();
    $unit = tpoUnit($truckType, $leader);
    $booking = tpoBooking($truckType, $unit, $leader, 'on_the_way');

    Sanctum::actingAs($leader, ['*']);

    $this->postJson('/api/v1/team-leader/presence/offline')->assertOk();

    // Presence is still cleared, but a running job is never taken away.
    expect($leader->fresh()->last_ping_at)->toBeNull();

    $booking->refresh();
    expect($booking->assigned_team_leader_id)->toBe($leader->id);
    expect($booking->status)->toBe('on_the_way');
    expect($booking->driver_name)->toBe('Driver One');

    $unit->refresh();
    expect($unit->team_leader_id)->toBe($leader->id);
    expect($unit->status)->toBe('on_job');
});

it('going offline only affects the authenticated team leader', function () {
    $leader = tpoTeamLeader();
    $other = tpoTeamLeader();
    $truckType = tpoTruckType();

    $unit = tpoUnit($truckType, $leader);
    $otherUnit = tpoUnit($truckType, $other);
    $otherBooking = tpoBooking($truckType, $otherUnit, $other, 'assigned');

    Sanctum::actingAs($leader, ['*']);

    $this->postJson('/api/v1/team-leader/presence/offline')->assertOk();

    expect($unit->fresh()->team_leader_id)->toBeNull();

    expect($other->fresh()->last_ping_at)->not->toBeNull();
    expect($otherUnit->fresh()->team_leader_id)->toBe($other->id);
    expect($otherUnit->fresh()->status)->toBe('on_job');
    expect($otherBooking->fresh()->assigned_team_leader_id)->toBe($other->id);
});

it('rejects unauthenticated and non-team-leader callers without changing anything', function () {
    $leader = tpoTeamLeader();
    $truckType = tpoTruckType();
    $unit = tpoUnit($truckType, $leader);

    $this->postJson('/api/v1/team-leader/presence/offline')->assertStatus(401);

    tpoRole(5, 'Customer');
    $customer = User::factory()->create(['role_id' => 5, 'status' => 'active']);
    Sanctum::actingAs($customer, ['*']);

    $this->postJson('/api/v1/team-leader/presence/offline')->assertStatus(403);

    expect($unit->fresh()->team_leader_id)->toBe($leader->id);
    expect($leader->fresh()->last_ping_at)->not->toBeNull();
});
