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
    config(['towmate.demo_arrival_enabled' => false]);
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
    config(['towmate.demo_arrival_enabled' => false]);
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

it('C: rejects is_demo=true when the server flag is disabled', function () {
    config(['towmate.demo_arrival_enabled' => false]);
    [$teamLeader, $booking] = makeDemoArrivalScenario('on_the_way');

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'is_demo' => true,
    ])->assertStatus(403)->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Demo arrival is not enabled.');

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('D: allows is_demo=true without GPS when the server flag is enabled', function () {
    config(['towmate.demo_arrival_enabled' => true]);
    [$teamLeader, $booking] = makeDemoArrivalScenario('on_the_way');

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'is_demo' => true,
    ])->assertOk()->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('arrived_pickup');
});

it('E: demo arrival still requires correct Team Leader ownership', function () {
    config(['towmate.demo_arrival_enabled' => true]);
    [$teamLeader, $booking] = makeDemoArrivalScenario('on_the_way', ownedByThisLeader: false);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'is_demo' => true,
    ])->assertStatus(403)->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('F: demo arrival still requires a valid current lifecycle state', function () {
    config(['towmate.demo_arrival_enabled' => true]);
    // 'assigned' cannot transition directly to 'arrived_pickup' (VALID_TRANSITIONS only allows 'accepted').
    [$teamLeader, $booking] = makeDemoArrivalScenario('assigned');

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'is_demo' => true,
    ])->assertStatus(422)->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('assigned');
});

it('G: demo arrival cannot skip arbitrary statuses', function () {
    config(['towmate.demo_arrival_enabled' => true]);
    [$teamLeader, $booking] = makeDemoArrivalScenario('on_the_way');

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'completed',
        'is_demo' => true,
    ])->assertStatus(422)->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('H: demo arrival does not modify quotation, pricing, or payment', function () {
    config(['towmate.demo_arrival_enabled' => true]);
    [$teamLeader, $booking, $quotation] = makeDemoArrivalScenario('on_the_way');

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(demoStatusUrl($booking), [
        'status' => 'arrived_pickup',
        'is_demo' => true,
    ])->assertOk();

    $freshQuotation = $quotation->fresh();
    expect($freshQuotation->status)->toBe('accepted')
        ->and((float) $freshQuotation->estimated_price)->toBe(4536.0)
        ->and($freshQuotation->version)->toBe(1);

    $freshBooking = $booking->fresh();
    expect((float) $freshBooking->final_total)->toBe(4536.0)
        ->and($freshBooking->payment_method)->toBeNull()
        ->and($freshBooking->payment_submitted_at)->toBeNull();
});

it('I: both pickup and dropoff demo paths are independently protected by the server flag', function () {
    // Dropoff, flag disabled — must be rejected exactly like pickup.
    config(['towmate.demo_arrival_enabled' => false]);
    [$teamLeaderA, $bookingA] = makeDemoArrivalScenario('on_job');
    Sanctum::actingAs($teamLeaderA, ['*']);

    $this->patchJson(demoStatusUrl($bookingA), [
        'status' => 'arrived_dropoff',
        'is_demo' => true,
    ])->assertStatus(403)->assertJsonPath('success', false);

    expect($bookingA->fresh()->status)->toBe('on_job');

    // Dropoff, flag enabled — allowed, same transition a real arrival would produce.
    config(['towmate.demo_arrival_enabled' => true]);
    [$teamLeaderB, $bookingB] = makeDemoArrivalScenario('on_job');
    Sanctum::actingAs($teamLeaderB, ['*']);

    $this->patchJson(demoStatusUrl($bookingB), [
        'status' => 'arrived_dropoff',
        'is_demo' => true,
    ])->assertOk()->assertJsonPath('success', true);

    expect($bookingB->fresh()->status)->toBe('arrived_dropoff');
});
