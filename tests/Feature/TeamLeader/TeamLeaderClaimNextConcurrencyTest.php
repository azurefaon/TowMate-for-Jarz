<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function tlcnRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function tlcnTeamLeader(): User
{
    tlcnRole(3, 'Team Leader');

    return User::factory()->create(['role_id' => 3, 'must_change_password' => false, 'status' => 'active']);
}

function tlcnTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'TLCN Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function tlcnCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'TLCN Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'tlcn-' . uniqid() . '@example.com',
    ]);
}

function tlcnBooking(Customer $customer, TruckType $truckType, string $groupCode, array $overrides = []): Booking
{
    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'service_type' => 'book_now',
    ], $overrides));
}

it('lets a Team Leader with group history claim the unclaimed sibling task', function () {
    $customer = tlcnCustomer();
    $truckType = tlcnTruckType();
    $tlA = tlcnTeamLeader();
    $groupCode = 'TLCN-GROUP-' . uniqid();
    $unit = Unit::create([
        'name' => 'TLCN Unit A',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $tlA->id,
        'status' => 'available',
    ]);

    tlcnBooking($customer, $truckType, $groupCode, [
        'assigned_team_leader_id' => $tlA->id,
        'assigned_unit_id' => $unit->id,
        'status' => 'completed',
    ]);
    $sibling = tlcnBooking($customer, $truckType, $groupCode, ['status' => 'confirmed']);

    Sanctum::actingAs($tlA, ['*']);
    $response = test()->postJson('/api/v1/team-leader/group/' . $groupCode . '/claim-next');

    $response->assertOk()->assertJsonPath('success', true)
        ->assertJsonPath('data.booking_code', $sibling->booking_code);

    $sibling->refresh();
    expect($sibling->assigned_team_leader_id)->toBe($tlA->id)
        ->and($sibling->status)->toBe('accepted')
        ->and($sibling->assigned_unit_id)->toBe($unit->id);
});

it('does not let a second Team Leader steal an already-claimed sibling task', function () {
    $customer = tlcnCustomer();
    $truckType = tlcnTruckType();
    $tlA = tlcnTeamLeader();
    $tlB = tlcnTeamLeader();
    $groupCode = 'TLCN-GROUP-' . uniqid();

    tlcnBooking($customer, $truckType, $groupCode, [
        'assigned_team_leader_id' => $tlA->id,
        'status' => 'completed',
    ]);
    tlcnBooking($customer, $truckType, $groupCode, [
        'assigned_team_leader_id' => $tlB->id,
        'status' => 'completed',
    ]);
    $sibling = tlcnBooking($customer, $truckType, $groupCode, ['status' => 'confirmed']);

    Sanctum::actingAs($tlA, ['*']);
    test()->postJson('/api/v1/team-leader/group/' . $groupCode . '/claim-next')
        ->assertOk()
        ->assertJsonPath('data.booking_code', $sibling->booking_code);

    Sanctum::actingAs($tlB, ['*']);
    $secondAttempt = test()->postJson('/api/v1/team-leader/group/' . $groupCode . '/claim-next');

    $secondAttempt->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'No available sibling booking found in this group.');

    $sibling->refresh();
    expect($sibling->assigned_team_leader_id)->toBe($tlA->id)
        ->and($sibling->status)->toBe('accepted');

    expect(Booking::where('group_code', $groupCode)->where('assigned_team_leader_id', $tlB->id)->count())->toBe(1);
});

it('exercises the real locked re-check query path directly against a row already claimed under lock', function () {
    $customer = tlcnCustomer();
    $truckType = tlcnTruckType();
    $tlA = tlcnTeamLeader();
    $tlB = tlcnTeamLeader();
    $groupCode = 'TLCN-GROUP-' . uniqid();

    tlcnBooking($customer, $truckType, $groupCode, ['assigned_team_leader_id' => $tlA->id, 'status' => 'completed']);
    tlcnBooking($customer, $truckType, $groupCode, ['assigned_team_leader_id' => $tlB->id, 'status' => 'completed']);
    $sibling = tlcnBooking($customer, $truckType, $groupCode, ['status' => 'confirmed']);

    \Illuminate\Support\Facades\DB::transaction(function () use ($groupCode, $tlA, $sibling) {
        $locked = Booking::where('group_code', $groupCode)
            ->whereNull('assigned_team_leader_id')
            ->whereIn('status', ['requested', 'scheduled', 'scheduled_confirmed', 'confirmed'])
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        expect($locked->id)->toBe($sibling->id);

        $locked->update([
            'assigned_team_leader_id' => $tlA->id,
            'status' => 'accepted',
            'assigned_at' => now(),
        ]);
    });

    $reCheck = Booking::where('group_code', $groupCode)
        ->whereNull('assigned_team_leader_id')
        ->whereIn('status', ['requested', 'scheduled', 'scheduled_confirmed', 'confirmed'])
        ->orderBy('id')
        ->lockForUpdate()
        ->first();

    expect($reCheck)->toBeNull();
});

it('behaves idempotently when the same Team Leader submits claim-next twice in a row', function () {
    $customer = tlcnCustomer();
    $truckType = tlcnTruckType();
    $tlA = tlcnTeamLeader();
    $groupCode = 'TLCN-GROUP-' . uniqid();

    tlcnBooking($customer, $truckType, $groupCode, ['assigned_team_leader_id' => $tlA->id, 'status' => 'completed']);
    $sibling = tlcnBooking($customer, $truckType, $groupCode, ['status' => 'confirmed']);

    Sanctum::actingAs($tlA, ['*']);
    $first = test()->postJson('/api/v1/team-leader/group/' . $groupCode . '/claim-next')->assertOk();
    $second = test()->postJson('/api/v1/team-leader/group/' . $groupCode . '/claim-next')->assertOk();

    expect($first->json('data.booking_code'))->toBe($sibling->booking_code)
        ->and($second->json('data.booking_code'))->toBe($sibling->booking_code);

    expect(Booking::where('id', $sibling->id)->where('assigned_team_leader_id', $tlA->id)->count())->toBe(1);
});

it('returns the existing clean not-found response when no sibling is available to claim', function () {
    $customer = tlcnCustomer();
    $truckType = tlcnTruckType();
    $tlA = tlcnTeamLeader();
    $groupCode = 'TLCN-GROUP-' . uniqid();

    tlcnBooking($customer, $truckType, $groupCode, ['assigned_team_leader_id' => $tlA->id, 'status' => 'completed']);

    Sanctum::actingAs($tlA, ['*']);
    test()->postJson('/api/v1/team-leader/group/' . $groupCode . '/claim-next')
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'No available sibling booking found in this group.');
});

it('does not let a non-Team-Leader user hit the claim-next endpoint', function () {
    $customerRole = tlcnRole(5, 'Customer');
    $user = User::factory()->create(['role_id' => $customerRole->id]);
    $groupCode = 'TLCN-GROUP-' . uniqid();

    Sanctum::actingAs($user, ['*']);
    $response = test()->postJson('/api/v1/team-leader/group/' . $groupCode . '/claim-next');

    expect($response->status())->not->toBe(200);
    expect(Booking::where('group_code', $groupCode)->count())->toBe(0);
});

it('lets the claimed task continue through the normal Team Leader lifecycle afterward', function () {
    $customer = tlcnCustomer();
    $truckType = tlcnTruckType();
    $tlA = tlcnTeamLeader();
    $groupCode = 'TLCN-GROUP-' . uniqid();

    tlcnBooking($customer, $truckType, $groupCode, ['assigned_team_leader_id' => $tlA->id, 'status' => 'completed']);
    $sibling = tlcnBooking($customer, $truckType, $groupCode, ['status' => 'confirmed']);

    Sanctum::actingAs($tlA, ['*']);
    test()->postJson('/api/v1/team-leader/group/' . $groupCode . '/claim-next')->assertOk();

    expect($sibling->fresh()->status)->toBe('accepted');

    test()->patchJson('/api/v1/team-leader/task/' . $sibling->booking_code . '/status', ['status' => 'on_the_way'])
        ->assertOk()
        ->assertJsonPath('data.status', 'on_the_way')
        ->assertJsonPath('success', true);

    expect($sibling->fresh()->status)->toBe('on_the_way');
});

it('preserves scheduled-status siblings as claimable exactly as before', function () {
    $customer = tlcnCustomer();
    $truckType = tlcnTruckType();
    $tlA = tlcnTeamLeader();
    $groupCode = 'TLCN-GROUP-' . uniqid();

    tlcnBooking($customer, $truckType, $groupCode, ['assigned_team_leader_id' => $tlA->id, 'status' => 'completed']);
    $sibling = tlcnBooking($customer, $truckType, $groupCode, [
        'status' => 'scheduled_confirmed',
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
    ]);

    Sanctum::actingAs($tlA, ['*']);
    test()->postJson('/api/v1/team-leader/group/' . $groupCode . '/claim-next')
        ->assertOk()
        ->assertJsonPath('data.booking_code', $sibling->booking_code);

    expect($sibling->fresh()->status)->toBe('accepted');
});
