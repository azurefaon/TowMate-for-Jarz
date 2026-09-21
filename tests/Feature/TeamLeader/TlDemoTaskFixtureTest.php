<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

function tlDemoRole(): Role
{
    $role = Role::find(3);
    if (! $role) {
        $role = new Role(['name' => 'Team Leader', 'description' => 'Tow unit team leader']);
        $role->id = 3;
        $role->save();
    }
    return $role;
}

function tlDemoSeedTeamLeaderAndUnit(string $email = 'tldemo-teamleader@example.test'): array
{
    tlDemoRole();

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'must_change_password' => false,
        'name' => 'Ariel Santos',
        'email' => $email,
    ]);

    $truckType = TruckType::create([
        'name' => 'TL Demo Truck ' . uniqid(),
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

function tlDemoRunSeed(string $email, array $options = []): int
{
    return Artisan::call('dev:seed-tl-demo-task', array_merge(['--team-leader-email' => $email], $options));
}

function tlDemoRunReset(string $email): int
{
    return Artisan::call('dev:seed-tl-demo-task', ['--team-leader-email' => $email, '--reset' => true]);
}

function tlDemoRunInEnvironment(string $environment, callable $callback): mixed
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

it('creates one realistic single-vehicle assigned booking for the real team leader', function () {
    [$teamLeader, $unit] = tlDemoSeedTeamLeaderAndUnit();

    $exitCode = tlDemoRunSeed($teamLeader->email);

    expect($exitCode)->toBe(0);

    $booking = Booking::where('assigned_team_leader_id', $teamLeader->id)->first();
    expect($booking)->not->toBeNull();
    expect($booking->status)->toBe('assigned');
    expect($booking->assigned_unit_id)->toBe($unit->id);
    expect($booking->group_code)->toBeNull();
    expect($booking->service_type)->toBe('book_now');
    expect((float) $booking->final_total)->toBeGreaterThan(0);
    expect($booking->pickup_address)->not->toBeEmpty();
    expect($booking->dropoff_address)->not->toBeEmpty();
    expect($booking->customer)->not->toBeNull();
});

it('does not duplicate the demo task on a second run and reports its current state', function () {
    [$teamLeader] = tlDemoSeedTeamLeaderAndUnit();
    tlDemoRunSeed($teamLeader->email);

    $countBefore = Booking::where('assigned_team_leader_id', $teamLeader->id)->count();
    $exitCode = tlDemoRunSeed($teamLeader->email);
    $countAfter = Booking::where('assigned_team_leader_id', $teamLeader->id)->count();

    expect($exitCode)->toBe(0);
    expect($countAfter)->toBe($countBefore);
});

it('refuses to run outside the local or testing environment', function () {
    [$teamLeader] = tlDemoSeedTeamLeaderAndUnit();

    $exitCode = tlDemoRunInEnvironment('production', fn () => tlDemoRunSeed($teamLeader->email));

    expect($exitCode)->toBe(1);
    expect(Booking::where('assigned_team_leader_id', $teamLeader->id)->count())->toBe(0);
});

it('refuses to reset outside the local or testing environment', function () {
    [$teamLeader] = tlDemoSeedTeamLeaderAndUnit();
    tlDemoRunSeed($teamLeader->email);
    $booking = Booking::where('assigned_team_leader_id', $teamLeader->id)->first();

    $exitCode = tlDemoRunInEnvironment('production', fn () => tlDemoRunReset($teamLeader->email));

    expect($exitCode)->toBe(1);
    expect($booking->fresh()->status)->toBe('assigned');
});

it('follows the exact existing status machine through accept and on_the_way', function () {
    [$teamLeader] = tlDemoSeedTeamLeaderAndUnit();
    tlDemoRunSeed($teamLeader->email);
    $booking = Booking::where('assigned_team_leader_id', $teamLeader->id)->first();

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson('/api/v1/team-leader/task/' . $booking->booking_code . '/status', [
        'status' => 'on_the_way',
    ])->assertStatus(422);

    $this->postJson('/api/v1/team-leader/task/' . $booking->booking_code . '/accept')
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    expect($booking->fresh()->status)->toBe('accepted');

    $this->patchJson('/api/v1/team-leader/task/' . $booking->booking_code . '/status', [
        'status' => 'on_the_way',
    ])->assertOk()->assertJsonPath('data.status', 'on_the_way');

    expect($booking->fresh()->status)->toBe('on_the_way');

    $this->patchJson('/api/v1/team-leader/task/' . $booking->booking_code . '/status', [
        'status' => 'completed',
    ])->assertStatus(422);

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('resets the same demo task back to assigned and clears lifecycle progress', function () {
    [$teamLeader] = tlDemoSeedTeamLeaderAndUnit();
    tlDemoRunSeed($teamLeader->email);
    $booking = Booking::where('assigned_team_leader_id', $teamLeader->id)->first();
    $originalCode = $booking->booking_code;

    Sanctum::actingAs($teamLeader, ['*']);
    $this->postJson('/api/v1/team-leader/task/' . $booking->booking_code . '/accept')->assertOk();
    $this->patchJson('/api/v1/team-leader/task/' . $booking->booking_code . '/status', [
        'status' => 'on_the_way',
    ])->assertOk();

    Invoice::create([
        'booking_id' => $booking->id,
        'subtotal' => 100,
        'total' => 112,
        'status' => 'issued',
        'is_current' => true,
    ]);

    expect($booking->fresh()->status)->toBe('on_the_way');

    $exitCode = tlDemoRunReset($teamLeader->email);
    $reset = $booking->fresh();

    expect($exitCode)->toBe(0);
    expect($reset->booking_code)->toBe($originalCode);
    expect($reset->status)->toBe('assigned');
    expect($reset->payment_method)->toBeNull();
    expect($reset->completed_at)->toBeNull();
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(0);
});

it('reports an error instead of resetting when no demo task exists yet', function () {
    [$teamLeader] = tlDemoSeedTeamLeaderAndUnit();

    $exitCode = tlDemoRunReset($teamLeader->email);

    expect($exitCode)->toBe(1);
});

it('never touches an unrelated completed booking for the same team leader', function () {
    [$teamLeader, $unit, $truckType] = tlDemoSeedTeamLeaderAndUnit();

    $unrelatedCustomer = Customer::create([
        'full_name' => 'Unrelated Completed Customer',
        'phone' => '09170009999',
        'email' => 'unrelated-completed-' . uniqid() . '@example.test',
    ]);

    $completed = Booking::create([
        'customer_id' => $unrelatedCustomer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'completed',
        'completed_at' => now(),
        'payment_method' => 'cash',
        'cash_received' => 4658.08,
        'payment_submitted_at' => now(),
    ]);

    tlDemoRunSeed($teamLeader->email);
    tlDemoRunReset($teamLeader->email);

    $fresh = $completed->fresh();
    expect($fresh->status)->toBe('completed');
    expect($fresh->payment_method)->toBe('cash');
    expect((float) $fresh->cash_received)->toBe(4658.08);
});

it('refuses when the given email does not belong to a real team leader', function () {
    $customerUser = User::factory()->create(['role_id' => 5, 'must_change_password' => false]);

    $exitCode = tlDemoRunSeed($customerUser->email);

    expect($exitCode)->toBe(1);
    expect(Booking::where('assigned_team_leader_id', $customerUser->id)->count())->toBe(0);
});

it('refuses when the team leader has no assigned unit', function () {
    tlDemoRole();
    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'must_change_password' => false,
        'email' => 'tldemo-nounit@example.test',
    ]);

    $exitCode = tlDemoRunSeed($teamLeader->email);

    expect($exitCode)->toBe(1);
    expect(Booking::where('assigned_team_leader_id', $teamLeader->id)->count())->toBe(0);
});
