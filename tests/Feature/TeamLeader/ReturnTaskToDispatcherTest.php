<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function rtdRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function (Role $role) use ($id) {
        $role->id = $id;
        $role->save();
    });
}

function rtdTeamLeader(): User
{
    rtdRole(3, 'Team Leader');

    return User::factory()->create([
        'role_id' => 3,
        'must_change_password' => false,
    ]);
}

function rtdDispatcher(): User
{
    rtdRole(2, 'Dispatcher');
    rtdRole(1, 'Super Admin');

    return User::factory()->create([
        'role_id' => 2,
        'must_change_password' => false,
    ]);
}

function rtdTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'Return Task Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);
}

function rtdUnit(TruckType $truckType, User $teamLeader): Unit
{
    return Unit::create([
        'name' => 'Return Task Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'on_job',
    ]);
}

function rtdBooking(TruckType $truckType, Unit $unit, User $teamLeader, string $status = 'on_the_way'): Booking
{
    $customer = Customer::create([
        'full_name' => 'Return Task Customer',
        'phone' => '09171234567',
        'email' => 'return-task-' . uniqid() . '@example.com',
    ]);

    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
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

it('lets a team leader return a task and surfaces it to the dispatcher for reassignment', function () {
    $teamLeader = rtdTeamLeader();
    $truckType = rtdTruckType();
    $unit = rtdUnit($truckType, $teamLeader);
    $booking = rtdBooking($truckType, $unit, $teamLeader);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Vehicle/Unit Issue',
        'notes' => 'Engine overheated on the way to pickup.',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $booking->refresh();

    expect($booking->status)->toBe('returned');
    expect($booking->needs_reassignment)->toBeTrue();
    expect($booking->return_reason)->toBe('Vehicle/Unit Issue');
    expect($booking->return_notes)->toBe('Engine overheated on the way to pickup.');
    expect($booking->returned_by_team_leader_id)->toBe($teamLeader->id);
    // Previous unit/team leader stay on the booking for dispatcher visibility.
    expect($booking->assigned_unit_id)->toBe($unit->id);
    expect($booking->assigned_team_leader_id)->toBe($teamLeader->id);
    // The unit is released so it can be picked up by another job.
    expect($unit->fresh()->status)->toBe('available');

    expect(AuditLog::where('entity_type', 'Booking')
        ->where('entity_id', $booking->id)
        ->where('action', 'task_returned')
        ->exists())->toBeTrue();

    $dispatcher = rtdDispatcher();

    $this->actingAs($dispatcher)
        ->get(route('admin.dispatch'))
        ->assertOk()
        ->assertSee($booking->job_code)
        ->assertSee('Vehicle/Unit Issue');
});

it('lets the dispatcher reassign a returned task to a new eligible unit, preserving assignment history', function () {
    $originalLeader = rtdTeamLeader();
    $truckType = rtdTruckType();
    $originalUnit = rtdUnit($truckType, $originalLeader);
    $booking = rtdBooking($truckType, $originalUnit, $originalLeader);

    Sanctum::actingAs($originalLeader, ['*']);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Cannot Reach Pickup Location',
    ])->assertOk();

    $newLeader = rtdTeamLeader();
    $newUnit = rtdUnit($truckType, $newLeader);
    $newUnit->update(['status' => 'available']);

    $dispatcher = rtdDispatcher();

    $this->actingAs($dispatcher)
        ->postJson(route('admin.booking.assign', $booking), [
            'action' => 'accept',
            'assigned_unit_id' => $newUnit->id,
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('status', 'assigned');

    $booking->refresh();

    expect($booking->status)->toBe('assigned');
    expect($booking->assigned_unit_id)->toBe($newUnit->id);
    expect($booking->assigned_team_leader_id)->toBe($newLeader->id);
    expect($booking->returned_at)->toBeNull();
    expect($booking->return_reason)->toBeNull();
    expect($booking->return_notes)->toBeNull();
    expect($booking->returned_by_team_leader_id)->toBeNull();
    expect($booking->needs_reassignment)->toBeFalse();

    // Both the return event and the reassignment event remain in the audit trail.
    $history = AuditLog::where('entity_type', 'Booking')
        ->where('entity_id', $booking->id)
        ->whereIn('action', ['task_returned', 'booking_reassigned'])
        ->orderBy('id')
        ->get();

    expect($history)->toHaveCount(2);
    expect($history->first()->action)->toBe('task_returned');
    expect($history->last()->action)->toBe('booking_reassigned');
    expect($history->last()->old_value['team_leader_id'])->toBe($originalLeader->id);
    expect($history->last()->new_value['team_leader_id'])->toBe($newLeader->id);
});

it('does not allow the original team leader to act on a task after it has been reassigned', function () {
    $originalLeader = rtdTeamLeader();
    $truckType = rtdTruckType();
    $originalUnit = rtdUnit($truckType, $originalLeader);
    $booking = rtdBooking($truckType, $originalUnit, $originalLeader);

    Sanctum::actingAs($originalLeader, ['*']);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Other',
        'notes' => 'Escalating to dispatch.',
    ])->assertOk();

    $newLeader = rtdTeamLeader();
    $newUnit = rtdUnit($truckType, $newLeader);
    $newUnit->update(['status' => 'available']);

    $this->actingAs(rtdDispatcher())
        ->postJson(route('admin.booking.assign', $booking), [
            'action' => 'accept',
            'assigned_unit_id' => $newUnit->id,
        ])
        ->assertOk();

    // Simulates the original TL's stale client trying to act after the dispatcher reassigned.
    Sanctum::actingAs($originalLeader, ['*']);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Other',
    ])->assertStatus(403);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/accept")
        ->assertStatus(403);
});

it('rejects a second return attempt on an already-returned task', function () {
    $teamLeader = rtdTeamLeader();
    $truckType = rtdTruckType();
    $unit = rtdUnit($truckType, $teamLeader);
    $booking = rtdBooking($truckType, $unit, $teamLeader);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Incorrect Task Details',
    ])->assertOk();

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Incorrect Task Details',
    ])->assertStatus(409);
});

it('rejects a return reason outside the allowed category list, but only after confirming ownership', function () {
    $teamLeader = rtdTeamLeader();
    $truckType = rtdTruckType();
    $unit = rtdUnit($truckType, $teamLeader);
    $booking = rtdBooking($truckType, $unit, $teamLeader);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Not a real category',
    ])->assertStatus(422);

    $otherLeader = rtdTeamLeader();
    Sanctum::actingAs($otherLeader, ['*']);

    // A non-owning team leader must get 403, not a validation error that would
    // leak the shape of a valid request to someone who shouldn't be able to act on it.
    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Not a real category',
    ])->assertStatus(403);

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('does not affect the customer-visible status label for a returned booking', function () {
    $teamLeader = rtdTeamLeader();
    $truckType = rtdTruckType();
    $unit = rtdUnit($truckType, $teamLeader);
    $booking = rtdBooking($truckType, $unit, $teamLeader);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Other',
    ])->assertOk();

    $booking->refresh();

    expect($booking->status)->toBe('returned');
    expect($booking->status)->not->toBe('completed');
    expect($booking->status)->not->toBe('cancelled');
});
