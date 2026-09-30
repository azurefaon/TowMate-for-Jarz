<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function atRole(): Role
{
    $role = Role::find(3);
    if (! $role) {
        $role = new Role(['name' => 'Team Leader', 'description' => 'Tow unit team leader']);
        $role->id = 3;
        $role->save();
    }

    return $role;
}

function atTeamLeader(string $name = 'Assigned Team Leader'): User
{
    return User::factory()->create([
        'role_id' => atRole()->id,
        'must_change_password' => false,
        'name' => $name,
    ]);
}

function atTruckType(?string $class, string $name = null): TruckType
{
    return TruckType::create([
        'name' => $name ?? 'AT Truck ' . uniqid(),
        'class' => $class,
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);
}

function atUnit(User $teamLeader, TruckType $truckType, array $overrides = []): Unit
{
    return Unit::create(array_merge([
        'name' => 'AT Unit ' . uniqid(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'driver_name' => 'Free Text Driver',
        'crew_member_1_name' => 'Crew One',
        'crew_member_2_name' => 'Crew Two',
        'status' => 'available',
    ], $overrides));
}

function atBooking(User $teamLeader, TruckType $requestedType, ?Unit $unit, array $overrides = []): Booking
{
    $customer = Customer::create([
        'full_name' => 'AT Customer',
        'phone' => '09171234567',
        'email' => 'at-' . uniqid() . '@example.com',
    ]);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $requestedType->id,
        'assigned_unit_id' => $unit?->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.5,
        'base_rate' => 1500,
        'per_km_rate' => 300,
        'final_total' => 4000,
        'status' => 'accepted',
    ], $overrides));
}

function atCurrent(User $teamLeader): array
{
    Sanctum::actingAs($teamLeader);

    return test()->getJson('/api/v1/team-leader/task')->assertOk()->json('data');
}

it('returns the assigned team built from the booking unit', function () {
    $tl = atTeamLeader('Juan Leader');
    $unit = atUnit($tl, atTruckType('heavy', 'Heavy Rollback'), ['name' => 'Unit 09', 'plate_number' => 'ABC 1234']);
    atBooking($tl, atTruckType('light', 'Requested Light'), $unit);

    $data = atCurrent($tl);

    expect($data['assigned_team'])->toBe([
        'team_leader_name' => 'Juan Leader',
        'driver_name' => 'Free Text Driver',
        'crew_names' => ['Crew One', 'Crew Two'],
        'unit_name' => 'Unit 09',
        'plate_number' => 'ABC 1234',
        'truck_type_name' => 'Heavy Rollback',
        'truck_class' => 'heavy',
    ]);
    // Requested (customer) truck type stays untouched.
    expect($data['truck_type_name'])->toBe('Requested Light');
});

it('prefers the linked driver account over the free-text driver name', function () {
    $tl = atTeamLeader();
    $driver = User::factory()->create(['name' => 'Account Driver']);
    $unit = atUnit($tl, atTruckType('light'), ['driver_id' => $driver->id, 'driver_name' => 'Free Text Driver']);
    atBooking($tl, atTruckType('light'), $unit);

    expect(atCurrent($tl)['assigned_team']['driver_name'])->toBe('Account Driver');
});

it('falls back to the free-text driver name when no driver account is linked', function () {
    $tl = atTeamLeader();
    $unit = atUnit($tl, atTruckType('light'), ['driver_id' => null, 'driver_name' => 'Free Text Driver']);
    atBooking($tl, atTruckType('light'), $unit);

    expect(atCurrent($tl)['assigned_team']['driver_name'])->toBe('Free Text Driver');
});

it('filters empty crew values', function () {
    $tl = atTeamLeader();
    $unit = atUnit($tl, atTruckType('light'), ['crew_member_1_name' => null, 'crew_member_2_name' => '  ']);
    atBooking($tl, atTruckType('light'), $unit);

    expect(atCurrent($tl)['assigned_team']['crew_names'])->toBe([]);

    $unit->update(['crew_member_2_name' => 'Only Crew']);
    expect(atCurrent($tl)['assigned_team']['crew_names'])->toBe(['Only Crew']);
});

it('returns the canonical class for light, medium, heavy and null', function (?string $class) {
    $tl = atTeamLeader();
    $unit = atUnit($tl, atTruckType($class));
    atBooking($tl, atTruckType('light'), $unit);

    expect(atCurrent($tl)['assigned_team']['truck_class'])->toBe($class);
})->with(['light', 'medium', 'heavy', null]);

it('uses the unit truck type class, never the requested booking truck type class', function () {
    $tl = atTeamLeader();
    $unit = atUnit($tl, atTruckType('medium'));
    atBooking($tl, atTruckType('heavy'), $unit);

    expect(atCurrent($tl)['assigned_team']['truck_class'])->toBe('medium');
});

it('returns null assigned_team when the booking has no unit', function () {
    $tl = atTeamLeader();
    atBooking($tl, atTruckType('light'), null);

    expect(atCurrent($tl))->toHaveKey('assigned_team', null);
});

it('resolves each grouped sibling from its own unit', function () {
    $tl = atTeamLeader();
    $type = atTruckType('light');
    $unitA = atUnit($tl, $type, ['name' => 'Unit A', 'crew_member_1_name' => 'A Crew', 'crew_member_2_name' => null]);
    $otherTl = atTeamLeader('Other Leader');
    $unitB = atUnit($otherTl, atTruckType('heavy'), ['name' => 'Unit B', 'crew_member_1_name' => 'B Crew', 'crew_member_2_name' => null]);
    $groupCode = 'AT-GROUP-' . uniqid();

    $a = atBooking($tl, $type, $unitA, ['group_code' => $groupCode, 'status' => 'accepted']);
    $b = atBooking($tl, $type, $unitB, ['group_code' => $groupCode, 'status' => 'completed']);

    Sanctum::actingAs($tl);
    $a->refresh();
    $current = $this->getJson('/api/v1/team-leader/task')->assertOk()->json('data');

    expect($current['id'])->toBe($a->id)
        ->and($current['assigned_team']['unit_name'])->toBe('Unit A')
        ->and($current['assigned_team']['crew_names'])->toBe(['A Crew']);

    // Second sibling (different unit) is not inherited from the first.
    $b->update(['status' => 'accepted']);
    $a->update(['status' => 'completed']);
    $second = $this->getJson('/api/v1/team-leader/task')->assertOk()->json('data');
    expect($second['id'])->toBe($b->id)
        ->and($second['assigned_team']['unit_name'])->toBe('Unit B')
        ->and($second['assigned_team']['truck_class'])->toBe('heavy');
});

it('reflects the copied unit after claimNext and null for an unclaimed sibling', function () {
    $tl = atTeamLeader();
    $type = atTruckType('light');
    $unit = atUnit($tl, $type, ['name' => 'Copied Unit', 'crew_member_1_name' => 'Copied Crew', 'crew_member_2_name' => null]);
    $groupCode = 'AT-CLAIM-' . uniqid();

    $first = atBooking($tl, $type, $unit, ['group_code' => $groupCode, 'status' => 'completed', 'completed_at' => now()]);
    $sibling = atBooking($tl, $type, null, [
        'group_code' => $groupCode,
        'status' => 'requested',
        'assigned_team_leader_id' => null,
    ]);

    expect(Booking::find($sibling->id)->unit)->toBeNull();

    Sanctum::actingAs($tl);
    $response = $this->postJson("/api/v1/team-leader/group/{$groupCode}/claim-next")->assertOk();

    expect($response->json('data.id'))->toBe($sibling->id)
        ->and($response->json('data.assigned_team.unit_name'))->toBe('Copied Unit')
        ->and($response->json('data.assigned_team.crew_names'))->toBe(['Copied Crew'])
        ->and($response->json('data.assigned_team.team_leader_name'))->toBe($tl->name);
});

it('shows live unit roster changes on the next fetch', function () {
    $tl = atTeamLeader();
    $unit = atUnit($tl, atTruckType('light'));
    atBooking($tl, atTruckType('light'), $unit);

    expect(atCurrent($tl)['assigned_team']['crew_names'])->toBe(['Crew One', 'Crew Two']);

    $unit->update(['crew_member_1_name' => 'Replacement Crew', 'driver_name' => 'New Driver']);

    $team = atCurrent($tl)['assigned_team'];
    expect($team['crew_names'])->toBe(['Replacement Crew', 'Crew Two'])
        ->and($team['driver_name'])->toBe('New Driver');
});

it('does not add assigned_team to history rows', function () {
    $tl = atTeamLeader();
    $unit = atUnit($tl, atTruckType('light'));
    atBooking($tl, atTruckType('light'), $unit, ['status' => 'completed', 'completed_at' => now()]);

    Sanctum::actingAs($tl);
    $row = $this->getJson('/api/v1/team-leader/history')->assertOk()->json('data.0');

    expect(array_keys($row))->toBe([
        'id', 'booking_code', 'status', 'pickup_address', 'dropoff_address',
        'customer_name', 'final_total', 'completed_at',
    ]);
});

it('eager loads the assignment relations without per-relation lazy queries', function () {
    $tl = atTeamLeader();
    $driver = User::factory()->create();
    $unit = atUnit($tl, atTruckType('light'), ['driver_id' => $driver->id]);
    atBooking($tl, atTruckType('light'), $unit);

    Sanctum::actingAs($tl);
    DB::enableQueryLog();
    $this->getJson('/api/v1/team-leader/task')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn ($q) => str_contains($q, 'from "units"') && str_contains($q, '"id" in'))->count())->toBe(1);
    expect($queries->filter(fn ($q) => str_contains($q, 'from "units"'))->count())->toBe(1);
});
