<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Support\Facades\DB;

/**
 * Covers the two eligibility-consistency fixes to JobsController::reassignOptions()/
 * reassign(): matching /admin-dashboard/dispatch's literal Unit.status === 'available'
 * requirement, and respecting dispatcher Team Leader busy/unavailable overrides.
 * Self-contained helpers (jec* prefix), matching this test suite's existing convention
 * of not sharing global functions across test files.
 */
function jecRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function jecDispatcher(): User
{
    return User::factory()->create(['role_id' => 2, 'must_change_password' => false]);
}

function jecTeamLeader(string $name): User
{
    return User::factory()->create(['role_id' => 3, 'name' => $name, 'must_change_password' => false]);
}

function jecTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'JEC Truck ' . uniqid(),
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'Jobs eligibility consistency test truck',
    ]);
}

function jecCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'JEC Customer ' . uniqid(),
        'age' => 30,
        'phone' => '09171234567',
        'email' => 'jec-' . uniqid() . '@example.test',
    ]);
}

function jecUnit(TruckType $truckType, ?User $teamLeader, string $status = 'available'): Unit
{
    return Unit::create([
        'name' => 'JEC Unit ' . uniqid(),
        'plate_number' => 'JEC-' . rand(1000, 9999),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader?->id,
        'driver_name' => 'JEC Driver ' . uniqid(),
        'status' => $status,
    ]);
}

function jecBooking(TruckType $truckType, Unit $unit, ?User $teamLeader, string $status = 'assigned'): Booking
{
    return Booking::create([
        'customer_id' => jecCustomer()->id,
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

function jecScenario(): array
{
    jecRoles();

    $truckType = jecTruckType();

    $originalLeader = jecTeamLeader('JEC Original Leader ' . uniqid());
    $originalUnit = jecUnit($truckType, $originalLeader, 'available');
    $booking = jecBooking($truckType, $originalUnit, $originalLeader, 'assigned');

    $newLeader = jecTeamLeader('JEC New Leader ' . uniqid());
    $newUnit = jecUnit($truckType, $newLeader, 'available');

    return compact('truckType', 'originalLeader', 'originalUnit', 'booking', 'newLeader', 'newUnit');
}

// ---- Literal Unit.status eligibility ----

it('excludes a unit whose literal status is not available from reassignment options', function () {
    $s = jecScenario();
    $dispatcher = jecDispatcher();

    // Nothing about this unit is "busy" in the booking-derived sense (no active
    // booking, no busy team leader) — only its literal status column disqualifies
    // it, which is exactly the case UnitAvailabilityService alone would miss.
    $s['newUnit']->update(['status' => 'on_job']);

    $unitIds = $this->actingAs($dispatcher)
        ->getJson(route('admin.jobs.reassign-options', $s['booking']))
        ->assertOk()
        ->json('options');

    expect(collect($unitIds)->pluck('unit_id'))->not->toContain($s['newUnit']->id);
});

it('rejects a direct POST reassignment to a unit whose literal status is not available', function () {
    $s = jecScenario();
    $dispatcher = jecDispatcher();

    $s['newUnit']->update(['status' => 'on_job']);

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Dispatch adjustment',
    ])->assertStatus(422);

    expect($s['booking']->fresh()->assigned_unit_id)->toBe($s['originalUnit']->id);
});

// ---- Dispatcher Team Leader operational overrides ----

it('excludes a unit whose Team Leader has a busy operational override from reassignment options', function () {
    $s = jecScenario();
    $dispatcher = jecDispatcher();

    app(TeamLeaderAvailabilityService::class)->setOperationalOverride($s['newLeader'], 'busy', 'Manually marked busy by dispatch');

    $unitIds = $this->actingAs($dispatcher)
        ->getJson(route('admin.jobs.reassign-options', $s['booking']))
        ->assertOk()
        ->json('options');

    expect(collect($unitIds)->pluck('unit_id'))->not->toContain($s['newUnit']->id);
});

it('rejects a direct POST reassignment to a Team Leader with a busy operational override', function () {
    $s = jecScenario();
    $dispatcher = jecDispatcher();

    app(TeamLeaderAvailabilityService::class)->setOperationalOverride($s['newLeader'], 'busy');

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Dispatch adjustment',
    ])->assertStatus(422);

    expect($s['booking']->fresh()->assigned_unit_id)->toBe($s['originalUnit']->id);
});

it('excludes a unit whose Team Leader has an unavailable operational override from reassignment options', function () {
    $s = jecScenario();
    $dispatcher = jecDispatcher();

    app(TeamLeaderAvailabilityService::class)->setOperationalOverride($s['newLeader'], 'unavailable', 'Out sick');

    $unitIds = $this->actingAs($dispatcher)
        ->getJson(route('admin.jobs.reassign-options', $s['booking']))
        ->assertOk()
        ->json('options');

    expect(collect($unitIds)->pluck('unit_id'))->not->toContain($s['newUnit']->id);
});

it('rejects a direct POST reassignment to a Team Leader with an unavailable operational override', function () {
    $s = jecScenario();
    $dispatcher = jecDispatcher();

    app(TeamLeaderAvailabilityService::class)->setOperationalOverride($s['newLeader'], 'unavailable');

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Dispatch adjustment',
    ])->assertStatus(422);

    expect($s['booking']->fresh()->assigned_unit_id)->toBe($s['originalUnit']->id);
});

it('makes an otherwise eligible unit/Team Leader available again once the override is cleared', function () {
    $s = jecScenario();
    $dispatcher = jecDispatcher();

    $service = app(TeamLeaderAvailabilityService::class);
    $service->setOperationalOverride($s['newLeader'], 'busy');

    $this->actingAs($dispatcher)
        ->getJson(route('admin.jobs.reassign-options', $s['booking']))
        ->assertOk()
        ->assertJsonMissing(['unit_id' => $s['newUnit']->id]);

    // Clearing the override is itself done via the "available" status, per
    // TeamLeaderAvailabilityService::setOperationalOverride()'s own contract.
    $service->setOperationalOverride($s['newLeader'], 'available');

    $unitIds = $this->actingAs($dispatcher)
        ->getJson(route('admin.jobs.reassign-options', $s['booking']))
        ->assertOk()
        ->json('options');

    expect(collect($unitIds)->pluck('unit_id'))->toContain($s['newUnit']->id);

    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $s['booking']), [
        'assigned_unit_id' => $s['newUnit']->id,
        'reason' => 'Dispatch adjustment',
    ])->assertOk()->assertJsonPath('success', true);

    $booking = $s['booking']->fresh();
    expect($booking->assigned_unit_id)->toBe($s['newUnit']->id);
    expect($booking->assigned_team_leader_id)->toBe($s['newLeader']->id);
});
