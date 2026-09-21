<?php

use App\Models\Booking;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

function gvbRole(): Role
{
    $role = Role::find(3);
    if (! $role) {
        $role = new Role(['name' => 'Team Leader', 'description' => 'Tow unit team leader']);
        $role->id = 3;
        $role->save();
    }
    return $role;
}

function gvbSeedTeamLeaderAndUnit(string $email = 'gvb-teamleader@example.test'): array
{
    gvbRole();

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'must_change_password' => false,
        'name' => 'Breakdown Test Leader',
        'email' => $email,
    ]);

    $truckType = TruckType::create([
        'name' => 'GVB Truck ' . uniqid(),
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

it('exposes group_vehicle_breakdown sourced from the real sibling Booking rows', function () {
    [$teamLeader] = gvbSeedTeamLeaderAndUnit();

    Artisan::call('dev:seed-tl-demo-group-task', [
        '--team-leader-email' => $teamLeader->email,
        '--vehicles' => 3,
    ]);

    Sanctum::actingAs($teamLeader, ['*']);
    $response = $this->getJson('/api/v1/team-leader/task')->assertOk();

    $data = $response->json('data');
    $breakdown = $data['group_vehicle_breakdown'];

    expect($breakdown)->toHaveCount(3);

    $members = Booking::where('group_code', $data['group_code'])->orderBy('id')->get();
    expect($members)->toHaveCount(3);

    foreach ($members->values() as $i => $member) {
        expect((float) $breakdown[$i]['base_rate'])->toBe((float) $member->base_rate);
        expect((float) $breakdown[$i]['vat_amount'])->toBe((float) $member->vat_amount);
        expect((float) $breakdown[$i]['final_total'])->toBe((float) $member->final_total);
    }
});

it('computes distance_fee using the frozen booking per_km_rate, not the truck type live rate', function () {
    [$teamLeader, , $truckType] = gvbSeedTeamLeaderAndUnit();

    Artisan::call('dev:seed-tl-demo-group-task', [
        '--team-leader-email' => $teamLeader->email,
        '--vehicles' => 2,
    ]);

    $member = Booking::where('assigned_team_leader_id', $teamLeader->id)
        ->whereNotNull('group_code')
        ->orderBy('id')
        ->first();

    $frozenPerKmRate = (float) $member->per_km_rate;
    $frozenDistanceKm = (float) $member->distance_km;
    expect($frozenPerKmRate)->toBe(300.0);

    $truckType->update(['per_km_rate' => 999]);
    expect($truckType->fresh()->per_km_rate)->not->toEqual($frozenPerKmRate);

    Sanctum::actingAs($teamLeader, ['*']);
    $response = $this->getJson('/api/v1/team-leader/task')->assertOk();

    $data = $response->json('data');
    $breakdown = collect($data['group_vehicle_breakdown']);

    $expectedDistanceFee = app(App\Services\BookingService::class)->distanceFeeFor($frozenDistanceKm, $frozenPerKmRate);
    $wrongDistanceFeeIfLiveRateUsed = app(App\Services\BookingService::class)->distanceFeeFor($frozenDistanceKm, 999.0);

    expect((float) $breakdown->pluck('distance_fee')->unique()->first())->toBe($expectedDistanceFee);
    expect((float) $breakdown->pluck('distance_fee')->unique()->first())->not->toBe($wrongDistanceFeeIfLiveRateUsed);
});

it('leaves group_vehicle_breakdown empty for a single-vehicle booking', function () {
    [$teamLeader] = gvbSeedTeamLeaderAndUnit();

    Artisan::call('dev:seed-tl-demo-task', ['--team-leader-email' => $teamLeader->email]);

    Sanctum::actingAs($teamLeader, ['*']);
    $response = $this->getJson('/api/v1/team-leader/task')->assertOk();

    $data = $response->json('data');
    expect($data['group_vehicle_breakdown'])->toBe([]);
    expect($data['group_total'])->toBeNull();
});

it('does not change group_total, group_vehicle_totals, or group_adjustment', function () {
    [$teamLeader] = gvbSeedTeamLeaderAndUnit();

    Artisan::call('dev:seed-tl-demo-group-task', [
        '--team-leader-email' => $teamLeader->email,
        '--vehicles' => 3,
    ]);

    Sanctum::actingAs($teamLeader, ['*']);
    $response = $this->getJson('/api/v1/team-leader/task')->assertOk();
    $data = $response->json('data');

    $expectedTotal = round(array_sum(array_column($data['group_vehicle_breakdown'], 'final_total')) + $data['group_adjustment'], 2);

    expect((float) $data['group_total'])->toBe($expectedTotal);
    expect($data['group_vehicle_totals'])->toHaveCount(3);
    expect((float) $data['group_adjustment'])->toBe(200.0);
});
