<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * towmate_jarz_testing already carries the same baseline roles (id 1-4) the
 * real seed migration inserts via insertOrIgnore — using the same technique
 * here avoids colliding with that persistent row on the unique `name` column.
 * Only the numeric role_id matters for RoleMiddleware / EnsureTeamLeader.
 */
function jrRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function jrDispatcher(): User
{
    return User::factory()->create(['role_id' => 2, 'must_change_password' => false]);
}

function jrTeamLeader(string $name): User
{
    return User::factory()->create(['role_id' => 3, 'name' => $name, 'must_change_password' => false]);
}

function jrTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'JR Truck ' . uniqid(),
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'Jobs reassign test truck',
    ]);
}

function jrCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'JR Customer ' . uniqid(),
        'age' => 30,
        'phone' => '09171234567',
        'email' => 'jr-' . uniqid() . '@example.test',
    ]);
}

function jrUnit(TruckType $truckType, ?User $teamLeader, string $status = 'available'): Unit
{
    return Unit::create([
        'name' => 'JR Unit ' . uniqid(),
        'plate_number' => 'JR-' . rand(1000, 9999),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader?->id,
        // UnitAvailabilityService::hasDriver() requires one of these before a
        // unit counts as dispatch-ready, regardless of team-leader assignment.
        'driver_name' => 'JR Driver ' . uniqid(),
        'status' => $status,
    ]);
}

function jrBooking(TruckType $truckType, Unit $unit, ?User $teamLeader, string $status = 'assigned'): Booking
{
    return Booking::create([
        'customer_id' => jrCustomer()->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader?->id,
        'age' => 30,
        'pickup_address' => 'Quezon City Circle',
        'pickup_lat' => 14.6760,
        'pickup_lng' => 121.0437,
        'dropoff_address' => 'SM Megamall, Mandaluyong',
        'dropoff_lat' => 14.5842,
        'dropoff_lng' => 121.0568,
        'distance_km' => 6,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1950,
        'final_total' => 1950,
        'vat_exclusive_total' => 1950,
        'status' => $status,
        'assigned_at' => now(),
    ]);
}

/** Standard scenario: an assigned booking with a fresh, eligible alternate unit ready to take it. */
function jrScenario(): array
{
    jrRoles();

    $truckType = jrTruckType();

    $originalLeader = jrTeamLeader('Original Leader ' . uniqid());
    $originalUnit = jrUnit($truckType, $originalLeader, 'available');
    $booking = jrBooking($truckType, $originalUnit, $originalLeader, 'assigned');

    $newLeader = jrTeamLeader('New Leader ' . uniqid());
    $newUnit = jrUnit($truckType, $newLeader, 'available');

    return compact('truckType', 'originalLeader', 'originalUnit', 'booking', 'newLeader', 'newUnit');
}

it('lets the dispatcher reassign an assigned booking to a new unit and team leader', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    $response = $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Wrong TL / Unit selected',
        'notes' => 'Dispatcher picked the wrong crew by mistake.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $booking = $s['booking']->fresh();

    // Status stays exactly 'assigned' — only ownership changed.
    expect($booking->status)->toBe('assigned');
    expect($booking->assigned_unit_id)->toBe($s['newUnit']->id);
    expect($booking->assigned_team_leader_id)->toBe($s['newLeader']->id);

    // Unrelated booking data is untouched.
    expect($booking->customer_id)->toBe($s['booking']->customer_id);
    expect($booking->pickup_address)->toBe('Quezon City Circle');
    expect($booking->dropoff_address)->toBe('SM Megamall, Mandaluyong');
    expect((float) $booking->final_total)->toBe(1950.0);
    expect($booking->quotation_status)->toBe($s['booking']->quotation_status);
    expect($booking->returned_at)->toBeNull();
    expect($booking->return_reason)->toBeNull();

    $audit = AuditLog::where('entity_type', 'Booking')
        ->where('entity_id', $booking->id)
        ->where('action', 'dispatcher_reassigned')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->old_value['team_leader_id'])->toBe($s['originalLeader']->id);
    expect($audit->old_value['unit_id'])->toBe($s['originalUnit']->id);
    expect($audit->new_value['team_leader_id'])->toBe($s['newLeader']->id);
    expect($audit->new_value['unit_id'])->toBe($s['newUnit']->id);
    expect($audit->new_value['reason'])->toBe('Wrong TL / Unit selected');
    expect($audit->new_value['notes'])->toBe('Dispatcher picked the wrong crew by mistake.');
    expect($audit->user_id)->toBe($dispatcher->id);
});

it('rejects reassigning to the same unit/team leader as a meaningless no-op', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['originalUnit']->id,
        'reason' => 'Dispatch adjustment',
    ])->assertStatus(422);

    $booking = $s['booking']->fresh();
    expect($booking->assigned_unit_id)->toBe($s['originalUnit']->id);
    expect($booking->assigned_team_leader_id)->toBe($s['originalLeader']->id);
});

it('rejects reassignment to a team leader/unit that is already busy on another active booking', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    // The "new" unit's team leader is already tied up on a different active job.
    jrBooking($s['truckType'], $s['newUnit'], $s['newLeader'], 'on_the_way');

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Dispatch adjustment',
    ])->assertStatus(422);

    expect($s['booking']->fresh()->assigned_unit_id)->toBe($s['originalUnit']->id);
});

it('rejects reassignment to a unit with an incompatible truck type', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    $otherTruckType = jrTruckType();
    $incompatibleLeader = jrTeamLeader('Incompatible Leader ' . uniqid());
    $incompatibleUnit = jrUnit($otherTruckType, $incompatibleLeader, 'available');

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $incompatibleUnit->id,
        'reason' => 'Dispatch adjustment',
    ])->assertStatus(422);

    expect($s['booking']->fresh()->assigned_unit_id)->toBe($s['originalUnit']->id);
});

it('requires notes when the reason is Other', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Other',
    ])->assertStatus(422);

    expect($s['booking']->fresh()->assigned_unit_id)->toBe($s['originalUnit']->id);
});

it('rejects reassignment once the booking has already been accepted by the team leader', function () {
    $s = jrScenario();
    $s['booking']->update(['status' => 'accepted']);
    $dispatcher = jrDispatcher();

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Dispatch adjustment',
    ])->assertStatus(409);

    $booking = $s['booking']->fresh();
    expect($booking->status)->toBe('accepted');
    expect($booking->assigned_unit_id)->toBe($s['originalUnit']->id);
    expect($booking->assigned_team_leader_id)->toBe($s['originalLeader']->id);
});

it('rejects reassignment for every later Team Leader status', function (string $status) {
    $s = jrScenario();
    $s['booking']->update(['status' => $status]);
    $dispatcher = jrDispatcher();

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Dispatch adjustment',
    ])->assertStatus(409);

    expect($s['booking']->fresh()->status)->toBe($status);
})->with([
    'accepted', 'on_the_way', 'arrived_pickup', 'in_progress',
    'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification',
    'completed', 'cancelled', 'returned',
]);

it('prevents the original team leader from accepting the task after a dispatcher reassignment', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Assigned TL unavailable',
    ])->assertOk();

    Sanctum::actingAs($s['originalLeader'], ['*']);

    $this->postJson("/api/v1/team-leader/task/{$s['booking']->booking_code}/accept")
        ->assertStatus(403);

    expect($s['booking']->fresh()->status)->toBe('assigned');
});

it('lets the new team leader receive the task via the normal polling endpoint', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Assigned TL unavailable',
    ])->assertOk();

    Sanctum::actingAs($s['newLeader'], ['*']);

    $this->getJson('/api/v1/team-leader/task')
        ->assertOk()
        ->assertJsonPath('data.booking_code', $s['booking']->booking_code);

    // The old TL no longer sees this task on their own poll.
    Sanctum::actingAs($s['originalLeader'], ['*']);

    $this->getJson('/api/v1/team-leader/task')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('cannot be silently overwritten when a dispatcher reassignment wins the race against a TL accept', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    // Dispatcher reassigns first ("wins the lock").
    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Assigned TL unavailable',
    ])->assertOk();

    // The stale original TL's accept() must now fail cleanly, not overwrite the reassignment.
    Sanctum::actingAs($s['originalLeader'], ['*']);
    $this->postJson("/api/v1/team-leader/task/{$s['booking']->booking_code}/accept")
        ->assertStatus(403);

    $booking = $s['booking']->fresh();
    expect($booking->status)->toBe('assigned');
    expect($booking->assigned_team_leader_id)->toBe($s['newLeader']->id);
});

it('cannot be silently overwritten when a TL accept wins the race against a dispatcher reassignment', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    // Original TL accepts first ("wins the lock").
    Sanctum::actingAs($s['originalLeader'], ['*']);
    $this->postJson("/api/v1/team-leader/task/{$s['booking']->booking_code}/accept")
        ->assertOk();

    // The dispatcher's reassignment must now see the booking is no longer
    // 'assigned' and reject cleanly instead of clobbering the TL's acceptance.
    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Assigned TL unavailable',
    ])->assertStatus(409);

    $booking = $s['booking']->fresh();
    expect($booking->status)->toBe('accepted');
    expect($booking->assigned_team_leader_id)->toBe($s['originalLeader']->id);
});

it('marks an assigned job row as reassignable via data-status on the Active Jobs page', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-status="assigned"');
    expect($html)->toContain('data-reassign-url=');
    expect($html)->toContain('data-reassign-options-url=');
});

it('marks an accepted job row as not reassignable via data-status on the Active Jobs page', function () {
    $s = jrScenario();
    $s['booking']->update(['status' => 'accepted']);
    $dispatcher = jrDispatcher();

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-status="accepted"');
    expect($html)->not->toContain('data-status="assigned"');
});

it('returns fresh reassignment options scoped to the same truck type, excluding the current unit', function () {
    $s = jrScenario();
    $dispatcher = jrDispatcher();

    $response = $this->actingAs($dispatcher)
        ->getJson(route('admin.jobs.reassign-options', $s['booking']))
        ->assertOk()
        ->assertJsonPath('success', true);

    $unitIds = collect($response->json('options'))->pluck('unit_id');

    expect($unitIds)->toContain($s['newUnit']->id);
    expect($unitIds)->not->toContain($s['originalUnit']->id);
});

it('rejects fetching reassignment options once the booking is no longer assigned', function () {
    $s = jrScenario();
    $s['booking']->update(['status' => 'accepted']);
    $dispatcher = jrDispatcher();

    $this->actingAs($dispatcher)
        ->getJson(route('admin.jobs.reassign-options', $s['booking']))
        ->assertStatus(409);
});
