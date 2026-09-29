<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function tlfbRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function (Role $role) use ($id) {
        $role->id = $id;
        $role->save();
    });
}

function tlfbTeamLeader(): User
{
    tlfbRole(3, 'Team Leader');

    return User::factory()->create([
        'role_id' => 3,
        'must_change_password' => false,
    ]);
}

function tlfbTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'Boundary Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);
}

function tlfbUnit(TruckType $truckType, User $teamLeader): Unit
{
    return Unit::create([
        'name' => 'Boundary Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'on_job',
    ]);
}

function tlfbBooking(TruckType $truckType, Unit $unit, User $teamLeader, array $overrides = []): Booking
{
    $customer = Customer::create([
        'full_name' => 'Boundary Customer',
        'phone' => '09171234567',
        'email' => 'tl-boundary-' . uniqid() . '@example.com',
    ]);

    return Booking::create(array_merge([
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
        'status' => 'on_the_way',
        'assigned_at' => now(),
    ], $overrides));
}

it('still allows arrived_dropoff to Back to on_job', function () {
    $teamLeader = tlfbTeamLeader();
    $truckType = tlfbTruckType();
    $unit = tlfbUnit($truckType, $teamLeader);

    $booking = tlfbBooking($truckType, $unit, $teamLeader, ['status' => 'arrived_dropoff']);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", [
        'status' => 'on_job',
    ])->assertOk();

    expect($booking->fresh()->status)->toBe('on_job');
});

it('still allows arrived_dropoff to Return to dispatcher', function () {
    $teamLeader = tlfbTeamLeader();
    $truckType = tlfbTruckType();
    $unit = tlfbUnit($truckType, $teamLeader);

    $booking = tlfbBooking($truckType, $unit, $teamLeader, ['status' => 'arrived_dropoff']);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Vehicle/Unit Issue',
    ])->assertOk();

    expect($booking->fresh()->status)->toBe('returned');
});

it('blocks Back from waiting_verification to arrived_dropoff — server-side, not UI-only', function () {
    $teamLeader = tlfbTeamLeader();
    $truckType = tlfbTruckType();
    $unit = tlfbUnit($truckType, $teamLeader);

    $booking = tlfbBooking($truckType, $unit, $teamLeader, [
        'status' => 'waiting_verification',
        'payment_submitted_at' => now(),
        'cash_received' => 4536,
        'payment_method' => 'cash',
    ]);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", [
        'status' => 'arrived_dropoff',
    ])->assertStatus(422);

    $booking->refresh();
    expect($booking->status)->toBe('waiting_verification');
    // Untouched — this change must not clear payment fields.
    expect($booking->payment_submitted_at)->not->toBeNull();
    expect((float) $booking->cash_received)->toBe(4536.0);
});

it('blocks Return Task from waiting_verification — server-side, not UI-only', function () {
    $teamLeader = tlfbTeamLeader();
    $truckType = tlfbTruckType();
    $unit = tlfbUnit($truckType, $teamLeader);

    $booking = tlfbBooking($truckType, $unit, $teamLeader, [
        'status' => 'waiting_verification',
        'payment_submitted_at' => now(),
        'cash_received' => 4536,
        'payment_method' => 'cash',
    ]);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Other',
    ])->assertStatus(409);

    $booking->refresh();
    expect($booking->status)->toBe('waiting_verification');
    expect($booking->returned_at)->toBeNull();
    expect($booking->return_reason)->toBeNull();
    // Untouched — this change must not clear payment fields.
    expect($booking->payment_submitted_at)->not->toBeNull();
    expect((float) $booking->cash_received)->toBe(4536.0);
});
