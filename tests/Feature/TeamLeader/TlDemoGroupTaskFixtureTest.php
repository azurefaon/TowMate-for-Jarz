<?php

use App\Models\Booking;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function tlDemoGroupRole(): Role
{
    $role = Role::find(3);
    if (! $role) {
        $role = new Role(['name' => 'Team Leader', 'description' => 'Tow unit team leader']);
        $role->id = 3;
        $role->save();
    }
    return $role;
}

function tlDemoGroupSeedTeamLeaderAndUnit(string $email = 'tldemogroup-teamleader@example.test'): array
{
    tlDemoGroupRole();

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'must_change_password' => false,
        'name' => 'Group Demo Leader',
        'email' => $email,
    ]);

    $truckType = TruckType::create([
        'name' => 'TL Demo Group Truck ' . uniqid(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);

    $unit = Unit::create([
        'name' => "{$teamLeader->name}'s Unit",
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    return [$teamLeader, $unit, $truckType];
}

function tlDemoGroupRunSeed(string $email, array $options = []): int
{
    return Artisan::call('dev:seed-tl-demo-group-task', array_merge(['--team-leader-email' => $email], $options));
}

function tlDemoGroupRunReset(string $email): int
{
    return Artisan::call('dev:seed-tl-demo-group-task', ['--team-leader-email' => $email, '--reset' => true]);
}

function tlDemoGroupRunInEnvironment(string $environment, callable $callback): mixed
{
    $app = app();
    $original = $app['env'];
    $app['env'] = $environment;
    try {
        return $callback();
    } finally {
        $app['env'] = $original;
    }
}

it('creates a normalized 3-vehicle consolidated group already at arrived_dropoff', function () {
    [$teamLeader] = tlDemoGroupSeedTeamLeaderAndUnit();

    $exitCode = tlDemoGroupRunSeed($teamLeader->email, ['--vehicles' => 3]);

    expect($exitCode)->toBe(0);

    $bookings = Booking::where('assigned_team_leader_id', $teamLeader->id)->get();
    expect($bookings)->toHaveCount(3);
    expect($bookings->pluck('status')->unique()->all())->toBe(['arrived_dropoff']);
    expect($bookings->pluck('group_code')->unique())->toHaveCount(1);

    $primary = $bookings->first();
    expect($primary->quotation_id)->not->toBeNull();

    $quotation = Quotation::find($primary->quotation_id);
    expect($quotation)->not->toBeNull();
    expect($quotation->extra_vehicles)->toHaveCount(3);
    expect((float) $quotation->estimated_price)->toBeGreaterThan(0);
});

it('exposes real group fields through the exact existing formatTask response shape', function () {
    [$teamLeader] = tlDemoGroupSeedTeamLeaderAndUnit();
    tlDemoGroupRunSeed($teamLeader->email, ['--vehicles' => 3]);

    \Laravel\Sanctum\Sanctum::actingAs($teamLeader, ['*']);

    $response = $this->getJson('/api/v1/team-leader/task')->assertOk();

    $data = $response->json('data');
    expect($data['status'])->toBe('arrived_dropoff');
    expect($data['group_vehicle_count'])->toBe(3);
    expect($data['group_ready_for_payment'])->toBeTrue();
    expect($data['group_total'])->not->toBeNull();
    expect($data['group_vehicle_totals'])->toHaveCount(3);
});

it('switches from a 3-vehicle to a 6-vehicle group cleanly on rerun', function () {
    [$teamLeader] = tlDemoGroupSeedTeamLeaderAndUnit();
    tlDemoGroupRunSeed($teamLeader->email, ['--vehicles' => 3]);
    $firstGroupCode = Booking::where('assigned_team_leader_id', $teamLeader->id)->first()->group_code;

    $exitCode = tlDemoGroupRunSeed($teamLeader->email, ['--vehicles' => 6]);

    expect($exitCode)->toBe(0);
    $bookings = Booking::where('assigned_team_leader_id', $teamLeader->id)->get();
    expect($bookings)->toHaveCount(6);
    expect($bookings->pluck('group_code')->unique()->first())->not->toBe($firstGroupCode);
});

it('refuses vehicle counts outside the 2-6 range', function () {
    [$teamLeader] = tlDemoGroupSeedTeamLeaderAndUnit();

    expect(tlDemoGroupRunSeed($teamLeader->email, ['--vehicles' => 1]))->toBe(1);
    expect(tlDemoGroupRunSeed($teamLeader->email, ['--vehicles' => 7]))->toBe(1);
    expect(Booking::where('assigned_team_leader_id', $teamLeader->id)->count())->toBe(0);
});

it('refuses to run outside the local or testing environment', function () {
    [$teamLeader] = tlDemoGroupSeedTeamLeaderAndUnit();

    $exitCode = tlDemoGroupRunInEnvironment('production', fn () => tlDemoGroupRunSeed($teamLeader->email));

    expect($exitCode)->toBe(1);
    expect(Booking::where('assigned_team_leader_id', $teamLeader->id)->count())->toBe(0);
});

it('removes the demo group and its quotation on reset without touching unrelated bookings', function () {
    [$teamLeader] = tlDemoGroupSeedTeamLeaderAndUnit();
    tlDemoGroupRunSeed($teamLeader->email, ['--vehicles' => 3]);
    $quotationId = Booking::where('assigned_team_leader_id', $teamLeader->id)->first()->quotation_id;

    [$otherLeader] = tlDemoGroupSeedTeamLeaderAndUnit('tldemogroup-other@example.test');
    tlDemoGroupRunSeed($otherLeader->email, ['--vehicles' => 4]);

    $exitCode = tlDemoGroupRunReset($teamLeader->email);

    expect($exitCode)->toBe(0);
    expect(Booking::where('assigned_team_leader_id', $teamLeader->id)->count())->toBe(0);
    expect(Quotation::find($quotationId))->toBeNull();
    expect(Booking::where('assigned_team_leader_id', $otherLeader->id)->count())->toBe(4);
});

it('reports an error instead of resetting when no demo group exists yet', function () {
    [$teamLeader] = tlDemoGroupSeedTeamLeaderAndUnit();

    $exitCode = tlDemoGroupRunReset($teamLeader->email);

    expect($exitCode)->toBe(1);
});

it('refuses when the team leader has no assigned unit', function () {
    tlDemoGroupRole();
    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'must_change_password' => false,
        'email' => 'tldemogroup-nounit@example.test',
    ]);

    $exitCode = tlDemoGroupRunSeed($teamLeader->email);

    expect($exitCode)->toBe(1);
    expect(Booking::where('assigned_team_leader_id', $teamLeader->id)->count())->toBe(0);
});
