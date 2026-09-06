<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Support\Facades\Cache;

// Helper names are prefixed to avoid colliding with the similarly-shaped
// helpers already declared as globals in DispatchBookingActionsTest.php,
// since Pest loads every test file's top-level functions into one namespace.

function busyStatusDispatcher(): User
{
    $role = Role::find(2) ?? tap(new Role(['name' => 'Dispatcher', 'description' => 'Dispatch staff']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function busyStatusTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'Busy Status Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);
}

function busyStatusReadyUnit(TruckType $truckType): array
{
    $teamLeaderRole = Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Tow unit team leader']);
    $teamLeader = User::factory()->create(['role_id' => $teamLeaderRole->id]);

    // Same legacy presence cache key isOnline() falls back to, matching the
    // existing convention in DispatchBookingActionsTest.php.
    Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));

    $unit = Unit::create([
        'name' => 'Busy Status Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    return [$teamLeader, $unit];
}

/** A Book Now booking already accepted by the customer, sitting at the real
 *  pre-dispatch 'confirmed' status — this is the branch of assignBooking()
 *  that actually writes assigned_unit_id/assigned_team_leader_id, unlike a
 *  fresh 'requested' booking (which only sends a quotation). */
function busyStatusConfirmedBooking(Customer $customer, TruckType $truckType): Booking
{
    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Busy Status Pickup',
        'dropoff_address' => 'Busy Status Dropoff',
        'distance_km' => 12.5,
        'base_rate' => 1500,
        'per_km_rate' => 300,
        'computed_total' => 4050,
        'final_total' => 4536,
        'status' => 'confirmed',
        'service_type' => 'book_now',
    ]);
}

it('A: a booking in accepted status makes its Team Leader busy', function () {
    $truckType = busyStatusTruckType();
    [$teamLeader, $unit] = busyStatusReadyUnit($truckType);
    $customer = Customer::create(['full_name' => 'Busy Status Customer A', 'phone' => '09170000010', 'email' => 'busy-a-' . uniqid() . '@example.com']);
    $booking = busyStatusConfirmedBooking($customer, $truckType);

    $booking->update([
        'status' => 'accepted',
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'assigned_at' => now(),
    ]);

    $busyIds = app(TeamLeaderAvailabilityService::class)->busyTeamLeaderIds();

    expect($busyIds->contains($teamLeader->id))->toBeTrue();
});

it('B: an accepted booking blocks a second dispatch to the same Team Leader/unit', function () {
    $dispatcher = busyStatusDispatcher();
    $truckType = busyStatusTruckType();
    [$teamLeader, $unit] = busyStatusReadyUnit($truckType);
    $customer = Customer::create(['full_name' => 'Busy Status Customer B', 'phone' => '09170000011', 'email' => 'busy-b-' . uniqid() . '@example.com']);

    $bookingA = busyStatusConfirmedBooking($customer, $truckType);
    $bookingB = busyStatusConfirmedBooking($customer, $truckType);

    // Step 1: real dispatch of Booking A through the actual controller route.
    $this->actingAs($dispatcher)->post(route('admin.booking.assign', $bookingA), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
    ])->assertOk()->assertJsonPath('success', true);

    expect($bookingA->fresh()->status)->toBe('assigned');

    // Step 2: the Team Leader accepts the task (mirrors TLTaskController::accept()).
    $bookingA->fresh()->update(['status' => 'accepted', 'assigned_at' => now()]);

    // Step 3: dispatcher tries to send Booking B to the same unit/Team Leader.
    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $bookingB), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Team Leader not available. Please choose another unit.');

    $freshB = $bookingB->fresh();
    expect($freshB->assigned_unit_id)->toBeNull()
        ->and($freshB->assigned_team_leader_id)->toBeNull()
        ->and($freshB->status)->toBe('confirmed');

    // Booking A must remain exactly as the Team Leader left it — untouched by the rejected attempt.
    $freshA = $bookingA->fresh();
    expect($freshA->status)->toBe('accepted')
        ->and($freshA->assigned_unit_id)->toBe($unit->id)
        ->and($freshA->assigned_team_leader_id)->toBe($teamLeader->id);
});

it('C: waiting_verification with payment_submitted_at set leaves the Team Leader available', function () {
    $truckType = busyStatusTruckType();
    [$teamLeader, $unit] = busyStatusReadyUnit($truckType);
    $customer = Customer::create(['full_name' => 'Busy Status Customer C', 'phone' => '09170000012', 'email' => 'busy-c-' . uniqid() . '@example.com']);
    $booking = busyStatusConfirmedBooking($customer, $truckType);

    $booking->update([
        'status' => 'waiting_verification',
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'payment_submitted_at' => now(),
        'payment_method' => 'cash',
        'cash_received' => 4536,
    ]);

    $busyIds = app(TeamLeaderAvailabilityService::class)->busyTeamLeaderIds();

    expect($busyIds->contains($teamLeader->id))->toBeFalse();
});

it('D: a completed booking leaves the Team Leader available', function () {
    $truckType = busyStatusTruckType();
    [$teamLeader, $unit] = busyStatusReadyUnit($truckType);
    $customer = Customer::create(['full_name' => 'Busy Status Customer D', 'phone' => '09170000013', 'email' => 'busy-d-' . uniqid() . '@example.com']);
    $booking = busyStatusConfirmedBooking($customer, $truckType);

    $booking->update([
        'status' => 'completed',
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'completed_at' => now(),
    ]);

    $busyIds = app(TeamLeaderAvailabilityService::class)->busyTeamLeaderIds();

    expect($busyIds->contains($teamLeader->id))->toBeFalse();
});

it('E: a returned booking leaves the Team Leader available', function () {
    $truckType = busyStatusTruckType();
    [$teamLeader, $unit] = busyStatusReadyUnit($truckType);
    $customer = Customer::create(['full_name' => 'Busy Status Customer E', 'phone' => '09170000014', 'email' => 'busy-e-' . uniqid() . '@example.com']);
    $booking = busyStatusConfirmedBooking($customer, $truckType);

    $booking->update([
        'status' => 'returned',
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'returned_at' => now(),
        'return_reason' => 'Customer unreachable.',
    ]);

    $busyIds = app(TeamLeaderAvailabilityService::class)->busyTeamLeaderIds();

    expect($busyIds->contains($teamLeader->id))->toBeFalse();
});
