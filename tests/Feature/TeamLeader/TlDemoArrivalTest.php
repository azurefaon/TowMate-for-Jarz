<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// Pickup/dropoff coordinates are far enough apart (>150m, the real ARRIVAL_RADIUS_METERS)
// that a real-arrival test can deliberately submit dropoff coords while pickup is the
// target and expect a radius failure.
const DEMO_TEST_PICKUP_LAT = 14.6760;
const DEMO_TEST_PICKUP_LNG = 121.0437;
const DEMO_TEST_DROPOFF_LAT = 14.5547;
const DEMO_TEST_DROPOFF_LNG = 121.0244;

function makeDemoArrivalScenario(string $status = 'on_the_way', bool $ownedByThisLeader = true): array
{
    $teamLeaderRole = Role::find(3);
    if (! $teamLeaderRole) {
        $teamLeaderRole = new Role([
            'name' => 'Team Leader',
            'description' => 'Tow unit team leader',
        ]);
        $teamLeaderRole->id = 3;
        $teamLeaderRole->save();
    }

    $teamLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'must_change_password' => false,
    ]);

    $otherLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'must_change_password' => false,
    ]);

    $truckType = TruckType::create([
        'name' => 'Demo Arrival Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);

    $unit = Unit::create([
        'name' => 'Demo Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    $customer = Customer::create([
        'full_name' => 'Demo Arrival Customer',
        'phone' => '09171234567',
        'email' => 'demo-arrival-' . uniqid() . '@example.com',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $ownedByThisLeader ? $teamLeader->id : $otherLeader->id,
        'pickup_address' => 'Quezon City Test Pickup',
        'pickup_lat' => DEMO_TEST_PICKUP_LAT,
        'pickup_lng' => DEMO_TEST_PICKUP_LNG,
        'dropoff_address' => 'Makati Test Dropoff',
        'dropoff_lat' => DEMO_TEST_DROPOFF_LAT,
        'dropoff_lng' => DEMO_TEST_DROPOFF_LNG,
        'distance_km' => 12.5,
        'base_rate' => 1500,
        'per_km_rate' => 300,
        'computed_total' => 4050,
        'final_total' => 4536,
        'status' => $status,
        'assigned_at' => now(),
    ]);

    $quotation = Quotation::create([
        'quotation_number' => 'Q-DEMO-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $booking->pickup_address,
        'dropoff_address' => $booking->dropoff_address,
        'distance_km' => 12.5,
        'estimated_price' => 4536,
        'status' => 'accepted',
    ]);

    $booking->update(['quotation_id' => $quotation->id]);

    return [$teamLeader, $booking->fresh(), $quotation->fresh()];
}

function demoStatusUrl(Booking $booking): string
{
    return '/api/v1/team-leader/task/' . $booking->booking_code . '/status';
}

it('A: allows a real arrival with valid coordinates inside the radius', function () {
    [$teamLeader, $booking] = makeDemoArrivalScenario('on_the_way');

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'lat' => DEMO_TEST_PICKUP_LAT,
        'lng' => DEMO_TEST_PICKUP_LNG,
    ])->assertOk()->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('arrived_pickup');
});

it('B: rejects a real arrival outside the radius', function () {
    [$teamLeader, $booking] = makeDemoArrivalScenario('on_the_way');

    Sanctum::actingAs($teamLeader, ['*']);

    // Submits the DROPOFF coordinates while the target is pickup — far outside 150m.
    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'lat' => DEMO_TEST_DROPOFF_LAT,
        'lng' => DEMO_TEST_DROPOFF_LNG,
    ])->assertStatus(422)->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('C: is_demo=true on the normal endpoint cannot bypass GPS at pickup', function () {
    [$teamLeader, $booking] = makeDemoArrivalScenario('on_the_way');
    Sanctum::actingAs($teamLeader, ['*']);

    // No location at all.
    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'is_demo' => true,
    ])->assertStatus(422)->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Location is required to confirm arrival.');

    // Far from the pickup (the drop-off coordinates).
    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'is_demo' => true,
        'lat' => DEMO_TEST_DROPOFF_LAT,
        'lng' => DEMO_TEST_DROPOFF_LNG,
    ])->assertStatus(422)->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('D: no environment/config flag can turn the normal endpoint into a GPS bypass', function () {
    // The old key is gone entirely, and setting it (or a client flag) does nothing.
    expect(array_key_exists('demo_arrival_enabled', config('towmate')))->toBeFalse();

    foreach ([true, false] as $enabled) {
        config(['towmate.demo_arrival_enabled' => $enabled]);
        [$teamLeader, $booking] = makeDemoArrivalScenario('on_the_way');
        Sanctum::actingAs($teamLeader, ['*']);

        $this->patchJson(demoStatusUrl($booking), [
            'status' => 'arrived_pickup',
            'is_demo' => true,
        ])->assertStatus(422)->assertJsonPath('success', false);

        expect($booking->fresh()->status)->toBe('on_the_way');
    }
});

it('E: ownership is still enforced whatever is_demo says', function () {
    [$teamLeader, $booking] = makeDemoArrivalScenario('on_the_way', ownedByThisLeader: false);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'is_demo' => true,
        'lat' => DEMO_TEST_PICKUP_LAT,
        'lng' => DEMO_TEST_PICKUP_LNG,
    ])->assertStatus(403)->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('F: the current lifecycle state is still validated whatever is_demo says', function () {
    // 'assigned' cannot transition directly to 'arrived_pickup'.
    [$teamLeader, $booking] = makeDemoArrivalScenario('assigned');
    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'is_demo' => true,
        'lat' => DEMO_TEST_PICKUP_LAT,
        'lng' => DEMO_TEST_PICKUP_LNG,
    ])->assertStatus(422)->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('assigned');
});

it('G: arbitrary statuses still cannot be skipped to', function () {
    [$teamLeader, $booking] = makeDemoArrivalScenario('on_the_way');
    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'completed',
        'is_demo' => true,
    ])->assertStatus(422)->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('H: a stray is_demo flag is ignored - valid coordinates behave as a normal arrival and no demo audit is written', function () {
    [$teamLeader, $booking, $quotation] = makeDemoArrivalScenario('on_the_way');
    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'is_demo' => true,
        'lat' => DEMO_TEST_PICKUP_LAT,
        'lng' => DEMO_TEST_PICKUP_LNG,
    ])->assertOk()->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('arrived_pickup');
    expect(\App\Models\AuditLog::whereIn('action', ['demo_arrival_confirmed', 'demo_arrival_simulated'])->count())->toBe(0);

    $freshQuotation = $quotation->fresh();
    expect($freshQuotation->status)->toBe('accepted')
        ->and((float) $freshQuotation->estimated_price)->toBe(4536.0);

    $freshBooking = $booking->fresh();
    expect((float) $freshBooking->final_total)->toBe(4536.0)
        ->and($freshBooking->payment_method)->toBeNull()
        ->and($freshBooking->payment_submitted_at)->toBeNull();
});

it('I: drop-off arrival is equally un-bypassable through the normal endpoint', function () {
    [$teamLeader, $booking] = makeDemoArrivalScenario('on_job');
    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_dropoff',
        'is_demo' => true,
    ])->assertStatus(422)->assertJsonPath('success', false);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_dropoff',
        'is_demo' => true,
        'lat' => DEMO_TEST_PICKUP_LAT,
        'lng' => DEMO_TEST_PICKUP_LNG,
    ])->assertStatus(422)->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('on_job');

    // Real coordinates at the drop-off still work exactly as before.
    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_dropoff',
        'lat' => DEMO_TEST_DROPOFF_LAT,
        'lng' => DEMO_TEST_DROPOFF_LNG,
    ])->assertOk();

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});
