<?php

/*
 * drivers.assign-unit, drivers.remove-unit, drivers.update-status and
 * team-leaders.override changed unit assignments without
 * the locks, the active-job / reservation guard, the maintenance protection or
 * the borrow records of UnitTeamAssignmentService. They are retired: the
 * requests below must not reach any controller and must change nothing. The
 * supported assignment endpoints keep working.
 */

use App\Http\Controllers\Admin\DriversController;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function lrRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 4, 'name' => 'Driver', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function lrUser(int $roleId, string $name): User
{
    lrRoles();

    return User::factory()->create(['role_id' => $roleId, 'name' => $name, 'status' => 'active', 'must_change_password' => false]);
}

function lrUnit(?User $leader = null, array $attrs = []): Unit
{
    return Unit::create(array_merge([
        'name' => 'LR ' . uniqid(),
        'plate_number' => 'LR-' . uniqid(),
        'truck_type_id' => (TruckType::first() ?? TruckType::create(['name' => 'LR Truck', 'base_rate' => 1500, 'per_km_rate' => 75]))->id,
        'team_leader_id' => $leader?->id,
        'status' => 'available',
    ], $attrs));
}

function lrBooking(Unit $unit, User $leader, string $status = 'on_the_way'): Booking
{
    return Booking::create([
        'customer_id' => Customer::create(['full_name' => 'LR Customer', 'age' => 30, 'phone' => '09171234567', 'email' => 'lr-' . uniqid() . '@example.test'])->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $leader->id,
        'age' => 30,
        'pickup_address' => 'Quezon City Circle', 'pickup_lat' => 14.676, 'pickup_lng' => 121.0437,
        'dropoff_address' => 'SM Megamall', 'dropoff_lat' => 14.5842, 'dropoff_lng' => 121.0568,
        'distance_km' => 6, 'base_rate' => 1500, 'per_km_rate' => 75,
        'computed_total' => 1950, 'final_total' => 1950, 'vat_exclusive_total' => 1950,
        'status' => $status, 'assigned_at' => now(),
    ]);
}

/** The assignment-relevant state of every unit and loan, to prove a request changed nothing. */
function lrState(): array
{
    return [
        'units' => DB::table('units')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'bookings' => DB::table('bookings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'loans' => DB::table('unit_crew_loans')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        // Fixture creation writes its own create_* entries; only assignment-type entries matter here.
        'audits' => DB::table('audit_logs')->where('action', 'not like', 'create_%')->count(),
    ];
}

it('no longer registers the legacy assignment routes or controller methods', function () {
    expect(Route::has('admin.drivers.assign-unit'))->toBeFalse()
        ->and(Route::has('admin.drivers.remove-unit'))->toBeFalse()
        ->and(method_exists(DriversController::class, 'assignUnit'))->toBeFalse()
        ->and(method_exists(DriversController::class, 'removeUnit'))->toBeFalse()
        ->and(Route::has('admin.drivers.update-status'))->toBeFalse()
        ->and(Route::has('admin.team-leaders.override'))->toBeFalse()
        ->and(method_exists(DriversController::class, 'updateStatus'))->toBeFalse()
        ->and(method_exists(DriversController::class, 'override'))->toBeFalse();

    foreach (Route::getRoutes() as $route) {
        // Other modules legitimately have their own /update-status URLs; only the drivers ones are retired.
        if (str_contains($route->uri(), 'drivers/{teamLeader}')) {
            throw new RuntimeException('A drivers/{teamLeader} route is registered again: ' . $route->uri());
        }
    }
});

it('keeps every supported assignment endpoint registered', function () {
    foreach ([
        'admin.drivers.units.assign-team-leader', 'admin.drivers.units.return-team-leader', 'admin.drivers.units.remove-team-leader',
        'admin.drivers.units.assign-slot', 'admin.drivers.units.remove-slot', 'admin.drivers.loans.return',
        'admin.drivers.units.transfer-team', 'admin.drivers.team-leaders.duty', 'admin.drivers.units.slot-duty',
    ] as $name) {
        expect(Route::has($name))->toBeTrue($name);
    }
});

it('cannot change any assignment through direct requests to the retired URLs', function (string $role) {
    $actor = lrUser($role === 'owner' ? 1 : 2, 'LR Actor');
    $free = lrUser(3, 'LR Free Leader');
    $maintLeader = lrUser(3, 'LR Maintenance Leader');
    $busyLeader = lrUser(3, 'LR Busy Leader');
    $borrowed = lrUser(3, 'LR Borrowed Leader');

    $available = lrUnit();
    $maintenance = lrUnit($maintLeader, ['status' => 'maintenance', 'dispatcher_status' => 'unavailable', 'dispatcher_note' => 'Engine']);
    $busy = lrUnit($busyLeader, ['status' => 'on_job']);
    lrBooking($busy, $busyLeader);
    $reserved = lrUnit(lrUser(3, 'LR Reserved Leader'));
    lrBooking($reserved, User::find($reserved->team_leader_id), 'scheduled_confirmed');
    $home = lrUnit();
    $borrowTarget = lrUnit($borrowed);
    UnitCrewLoan::create([
        'from_unit_id' => $home->id, 'to_unit_id' => $borrowTarget->id,
        'from_slot' => 'team_leader', 'to_slot' => 'team_leader',
        'person_name' => 'LR Borrowed Leader', 'person_user_id' => $borrowed->id, 'borrowed_at' => now(),
    ]);

    $before = lrState();

    foreach ([$free, $maintLeader, $busyLeader, $borrowed] as $leader) {
        foreach (['assign-unit', 'remove-unit'] as $action) {
            $this->actingAs($actor)->post("/admin-dashboard/drivers/{$leader->id}/{$action}", ['unit_id' => $available->id])->assertNotFound();
            $this->actingAs($actor)->postJson("/admin-dashboard/drivers/{$leader->id}/{$action}", ['unit_id' => $available->id])->assertNotFound();
        }

        $status = ['operational_status' => 'busy', 'unit_status' => 'available', 'status_reason' => 'x'];
        $this->actingAs($actor)->post("/admin-dashboard/drivers/{$leader->id}/update-status", $status)->assertNotFound();
        $this->actingAs($actor)->postJson("/admin-dashboard/drivers/{$leader->id}/update-status", $status)->assertNotFound();
        $override = ['unit_status' => 'available', 'zone_confirmed' => false, 'dispatcher_note' => 'x'];
        $this->actingAs($actor)->patch("/admin-dashboard/drivers/{$leader->id}/override", $override)->assertNotFound();
        $this->actingAs($actor)->patchJson("/admin-dashboard/drivers/{$leader->id}/override", $override)->assertNotFound();
    }

    // Other verbs on the same URLs are not served either.
    $this->actingAs($actor)->patch("/admin-dashboard/drivers/{$maintLeader->id}/remove-unit")->assertNotFound();
    $this->actingAs($actor)->get("/admin-dashboard/drivers/{$maintLeader->id}/assign-unit")->assertNotFound();

    expect(Cache::get("teamleader:dispatcher-status:{$maintLeader->id}"))->toBeNull();
    expect(lrState())->toEqual($before);
    expect($maintenance->fresh()->status)->toBe('maintenance')
        ->and($maintenance->fresh()->dispatcher_status)->toBe('unavailable')
        ->and($maintenance->fresh()->dispatcher_note)->toBe('Engine')
        ->and($maintenance->fresh()->team_leader_id)->toBe($maintLeader->id)
        ->and($busy->fresh()->team_leader_id)->toBe($busyLeader->id)
        ->and($borrowTarget->fresh()->team_leader_id)->toBe($borrowed->id);
})->with(['owner', 'dispatcher']);

it('still assigns through the supported endpoint and still protects a leader on an active job', function () {
    $dispatcher = lrUser(2, 'LR Dispatcher');
    $leader = lrUser(3, 'LR Leader');
    $unit = lrUnit();

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-team-leader', $unit), ['team_leader_id' => $leader->id])
        ->assertOk()->assertJson(['success' => true]);
    expect($unit->fresh()->team_leader_id)->toBe($leader->id);

    $busyLeader = lrUser(3, 'LR Busy');
    $busyUnit = lrUnit($busyLeader, ['status' => 'on_job']);
    lrBooking($busyUnit, $busyLeader);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-team-leader', $busyUnit))
        ->assertStatus(422);
    expect($busyUnit->fresh()->team_leader_id)->toBe($busyLeader->id);
});
