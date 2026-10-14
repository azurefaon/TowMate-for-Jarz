<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\UnitAvailabilityService;
use Illuminate\Support\Facades\DB;

/*
 * Dispatch readiness must respect existing personnel eligibility:
 *   - accounts: User::canUseApi() (status active, not archived, pending
 *     deletion or anonymized);
 *   - personnel records: personnel_status = active.
 * Presence, the temporary login lockout and crew rules are unchanged.
 */

function dreRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function dreLeader(array $attrs = []): User
{
    dreRole(3, 'Team Leader');

    return User::factory()->create(array_merge(['role_id' => 3, 'status' => 'active', 'first_name' => 'Dre', 'last_name' => 'Leader ' . fake()->unique()->numerify('###')], $attrs));
}

function dreDriverAccount(array $attrs = []): User
{
    dreRole(4, 'Driver');

    return User::factory()->create(array_merge(['role_id' => 4, 'status' => 'active'], $attrs));
}

function dreRecord(string $role = 'driver', string $status = 'active'): Personnel
{
    return Personnel::create(['first_name' => 'Dre', 'last_name' => ucfirst($role) . ' ' . fake()->unique()->numerify('###'), 'role' => $role, 'personnel_status' => $status]);
}

function dreTruckType(): TruckType
{
    return TruckType::create(['name' => 'DRE Type ' . fake()->unique()->word(), 'class' => 'light', 'base_rate' => 1500, 'per_km_rate' => 60, 'status' => 'active']);
}

/** A unit with an eligible team leader and an eligible driver record. */
function dreUnit(array $overrides = [], ?User $leader = null, ?Personnel $driver = null): Unit
{
    $leader ??= dreLeader();
    $driver ??= dreRecord();

    return Unit::create(array_merge([
        'name' => 'DRE ' . fake()->unique()->numerify('###'),
        'plate_number' => fake()->unique()->bothify('???####'),
        'truck_type_id' => dreTruckType()->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => $driver->full_name,
        'driver_personnel_id' => $driver->id,
    ], $overrides));
}

function dreEvaluate(Unit $unit): array
{
    return app(UnitAvailabilityService::class)->evaluate($unit->fresh());
}

function dreOwner(): User
{
    dreRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

/* ---------- Eligible people ---------- */

it('keeps a unit with an active team leader and active driver available', function () {
    $row = dreEvaluate(dreUnit());

    expect($row['available'])->toBeTrue()->and($row['reasons'])->toBe([]);
});

it('does not treat an offline team leader as inactive', function () {
    $leader = dreLeader(['last_ping_at' => null]);

    $row = dreEvaluate(dreUnit([], $leader));

    expect($row['presence'])->toBe('offline')->and($row['available'])->toBeTrue();
});

it('does not treat the temporary login lockout as deactivation', function () {
    $leader = dreLeader(['locked_until' => now()->addMinutes(15)]);

    expect(dreEvaluate(dreUnit([], $leader))['available'])->toBeTrue();
});

/* ---------- Ineligible team leaders ---------- */

it('blocks readiness when the team leader account is inactive, locked, or pending deletion', function (array $attrs) {
    $leader = dreLeader($attrs);

    $row = dreEvaluate(dreUnit([], $leader));

    expect($row['available'])->toBeFalse()
        ->and($row['reasons'])->toContain('team_leader_inactive')
        ->and($row['reasons'])->not->toContain('no_team_leader');
})->with([
    'inactive' => [['status' => 'inactive']],
    'auto-locked' => [['status' => 'locked']],
    'pending deletion' => [['pending_delete_at' => now()]],
]);

it('keeps archived team leaders reported as no team leader, as before', function () {
    $leader = dreLeader(['archived_at' => now()]);

    expect(dreEvaluate(dreUnit([], $leader))['reasons'])->toContain('no_team_leader')->not->toContain('team_leader_inactive');
});

it('reports an inactive account before duty status', function () {
    $leader = dreLeader(['status' => 'inactive', 'duty_status' => 'unavailable']);

    expect(dreEvaluate(dreUnit([], $leader))['reasons'])->toContain('team_leader_inactive')->not->toContain('team_leader_duty_unavailable');
});

/* ---------- Drivers ---------- */

it('blocks readiness when the placed driver record is inactive', function () {
    $row = dreEvaluate(dreUnit([], null, dreRecord('driver', 'inactive')));

    expect($row['available'])->toBeFalse()->and($row['reasons'])->toBe(['driver_inactive']);
});

it('blocks readiness when the driver account cannot sign in, and allows an active one', function () {
    $inactive = dreDriverAccount(['status' => 'inactive']);
    $active = dreDriverAccount();

    $blocked = dreEvaluate(dreUnit(['driver_id' => $inactive->id, 'driver_name' => null, 'driver_personnel_id' => null]));
    $ready = dreEvaluate(dreUnit(['driver_id' => $active->id, 'driver_name' => null, 'driver_personnel_id' => null]));

    expect($blocked['reasons'])->toBe(['driver_inactive'])
        ->and($ready['available'])->toBeTrue();
});

it('keeps a legacy free-text driver name with no record eligible', function () {
    $row = dreEvaluate(dreUnit(['driver_name' => 'Legacy Driver', 'driver_personnel_id' => null]));

    expect($row['available'])->toBeTrue();
});

it('reports an inactive driver before driver duty', function () {
    $row = dreEvaluate(dreUnit(['driver_duty_status' => 'unavailable'], null, dreRecord('driver', 'inactive')));

    expect($row['reasons'])->toBe(['driver_inactive']);
});

/* ---------- Missing people and crew: unchanged ---------- */

it('still reports missing team leader and driver as before', function () {
    $unit = dreUnit(['team_leader_id' => null, 'driver_name' => null, 'driver_personnel_id' => null]);

    expect(dreEvaluate($unit)['reasons'])->toBe(['no_team_leader', 'no_driver']);
});

it('does not require crew, even inactive crew records', function () {
    $crew = dreRecord('crew', 'inactive');
    $unit = dreUnit(['crew_member_1_name' => $crew->full_name, 'crew_member_1_personnel_id' => $crew->id]);
    $noCrew = dreUnit();

    expect(dreEvaluate($unit)['available'])->toBeTrue()
        ->and(dreEvaluate($noCrew)['available'])->toBeTrue();
});

/* ---------- Shared consumers ---------- */

it('drops a truck type from Book Now readiness when its only unit has an inactive team leader', function () {
    $ready = dreUnit();
    $blocked = dreUnit([], dreLeader(['status' => 'inactive']));

    $service = app(UnitAvailabilityService::class);

    expect($service->readyTruckTypeIds())->toContain($ready->truck_type_id)->not->toContain($blocked->truck_type_id)
        ->and($service->isTruckTypeAvailableForBookNow($blocked->truck_type_id))->toBeFalse()
        ->and($service->readyByClass())->toBe(['light' => 1]);
});

it('evaluates many units in a fixed number of queries', function () {
    foreach (range(1, 6) as $i) {
        dreUnit();
    }
    $service = app(UnitAvailabilityService::class);
    $countQueries = function () use ($service) {
        $units = Unit::with(['teamLeader', 'truckType'])->get();
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $service->evaluateMany($units);
        $count = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        return $count;
    };

    $few = $countQueries();
    foreach (range(1, 6) as $i) {
        dreUnit();
    }

    expect($countQueries())->toBe($few);
});

it('does not change any assignment while evaluating', function () {
    $leader = dreLeader(['status' => 'inactive']);
    $driver = dreRecord('driver', 'inactive');
    $unit = dreUnit([], $leader, $driver);
    $before = $unit->fresh()->only(['team_leader_id', 'driver_name', 'driver_personnel_id', 'status']);

    dreEvaluate($unit);

    expect($unit->fresh()->only(['team_leader_id', 'driver_name', 'driver_personnel_id', 'status']))->toBe($before);
});

/* ---------- Trucks page ---------- */

it('shows the new Not ready reasons on the Trucks page', function () {
    dreUnit(['name' => 'DRE Leader Off'], dreLeader(['status' => 'inactive']));
    dreUnit(['name' => 'DRE Driver Off'], null, dreRecord('driver', 'inactive'));

    $response = $this->actingAs(dreOwner())->get(route('superadmin.unit-truck.index', ['status' => 'not_ready']));

    $response->assertSee("Team leader&#039;s account is inactive", false);
    $response->assertSee('Driver is inactive');
    $response->assertSee("Account inactive · can&#039;t sign in", false);
    $response->assertSee('Showing 1–2 of 2 trucks');
});
