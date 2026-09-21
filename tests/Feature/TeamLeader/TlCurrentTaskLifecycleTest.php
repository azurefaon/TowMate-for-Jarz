<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function tlclRole(): Role
{
    $role = Role::find(3);
    if (! $role) {
        $role = new Role(['name' => 'Team Leader', 'description' => 'Tow unit team leader']);
        $role->id = 3;
        $role->save();
    }

    return $role;
}

function tlclTeamLeader(string $name): User
{
    return User::factory()->create([
        'role_id' => tlclRole()->id,
        'must_change_password' => false,
        'name' => $name,
    ]);
}

function tlclTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'TLCL Truck ' . uniqid(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);
}

function tlclCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'TLCL Customer',
        'phone' => '09171234567',
        'email' => 'tlcl-' . uniqid() . '@example.com',
    ]);
}

function tlclSoloCompletedBooking(): array
{
    $teamLeader = tlclTeamLeader('TLCL Solo Leader');
    $truckType = tlclTruckType();
    $unit = Unit::create([
        'name' => 'TLCL Solo Unit ' . uniqid(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);
    $customer = tlclCustomer();

    $booking = Booking::create([
        'customer_id' => $customer->id,
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

    return compact('teamLeader', 'truckType', 'unit', 'customer', 'booking');
}

function tlclGroupCompletedBookings(): array
{
    $customer = tlclCustomer();
    $truckType = tlclTruckType();
    $groupCode = 'TLCL-GROUP-' . uniqid();

    $leaderA = tlclTeamLeader('TLCL Group Leader A');
    $leaderB = tlclTeamLeader('TLCL Group Leader B');

    $unitA = Unit::create([
        'name' => 'TLCL Group Unit A ' . uniqid(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $leaderA->id,
        'status' => 'available',
    ]);
    $unitB = Unit::create([
        'name' => 'TLCL Group Unit B ' . uniqid(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $leaderB->id,
        'status' => 'available',
    ]);

    $bookingA = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'assigned_unit_id' => $unitA->id,
        'assigned_team_leader_id' => $leaderA->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'completed',
        'completed_at' => now(),
        'payment_method' => 'cash',
        'cash_received' => 9816.16,
        'payment_submitted_at' => now(),
    ]);

    $bookingB = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'assigned_unit_id' => $unitB->id,
        'assigned_team_leader_id' => $leaderB->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'completed',
        'completed_at' => now(),
        'payment_method' => 'cash',
        'cash_received' => 9816.16,
        'payment_submitted_at' => now(),
    ]);

    $quotation = Quotation::create([
        'quotation_number' => 'QT-TLCL-' . uniqid(),
        'source_booking_id' => $bookingA->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => 9816.16,
        'additional_fee' => 500,
        'status' => 'accepted',
        'extra_vehicles' => [
            ['booking_id' => $bookingA->id, 'truck_type_id' => $truckType->id, 'final_total' => 4658.08],
            ['booking_id' => $bookingB->id, 'truck_type_id' => $truckType->id, 'final_total' => 4658.08],
        ],
    ]);
    $bookingA->update(['quotation_id' => $quotation->id]);
    $bookingB->update(['quotation_id' => $quotation->id]);

    return compact('leaderA', 'leaderB', 'bookingA', 'bookingB', 'quotation', 'customer', 'truckType');
}

it('still returns a just-completed solo booking from current-task so an already-open shell can render the confirmation', function () {
    $ctx = tlclSoloCompletedBooking();
    Sanctum::actingAs($ctx['teamLeader'], ['*']);

    $current = test()->getJson('/api/v1/team-leader/task');
    $current->assertOk()
        ->assertJsonPath('data.booking_code', $ctx['booking']->booking_code)
        ->assertJsonPath('data.status', 'completed');
});

it('lists a completed solo booking in the team leader history', function () {
    $ctx = tlclSoloCompletedBooking();
    Sanctum::actingAs($ctx['teamLeader'], ['*']);

    $history = test()->getJson('/api/v1/team-leader/history');
    $history->assertOk();
    $codes = collect($history->json('data'))->pluck('booking_code');
    expect($codes)->toContain($ctx['booking']->booking_code);
});

it('returns each normalized group siblings own completed booking from current-task', function () {
    $ctx = tlclGroupCompletedBookings();

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $currentA = test()->getJson('/api/v1/team-leader/task');
    $currentA->assertOk()
        ->assertJsonPath('data.booking_code', $ctx['bookingA']->booking_code)
        ->assertJsonPath('data.status', 'completed');

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    $currentB = test()->getJson('/api/v1/team-leader/task');
    $currentB->assertOk()
        ->assertJsonPath('data.booking_code', $ctx['bookingB']->booking_code)
        ->assertJsonPath('data.status', 'completed');
});

it('never leaks a completed grouped siblings booking into the other team leaders current task', function () {
    $ctx = tlclGroupCompletedBookings();

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $currentA = test()->getJson('/api/v1/team-leader/task');
    expect($currentA->json('data.booking_code'))->not->toBe($ctx['bookingB']->booking_code);

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    $currentB = test()->getJson('/api/v1/team-leader/task');
    expect($currentB->json('data.booking_code'))->not->toBe($ctx['bookingA']->booking_code);
});

it('lists both completed grouped siblings in their own team leaders history', function () {
    $ctx = tlclGroupCompletedBookings();

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $historyA = test()->getJson('/api/v1/team-leader/history');
    expect(collect($historyA->json('data'))->pluck('booking_code'))->toContain($ctx['bookingA']->booking_code);

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    $historyB = test()->getJson('/api/v1/team-leader/history');
    expect(collect($historyB->json('data'))->pluck('booking_code'))->toContain($ctx['bookingB']->booking_code);
});

it('returns a newly assigned active booking as current task ahead of an older completed one', function () {
    $ctx = tlclSoloCompletedBooking();

    $newBooking = Booking::create([
        'customer_id' => $ctx['customer']->id,
        'truck_type_id' => $ctx['truckType']->id,
        'assigned_unit_id' => $ctx['unit']->id,
        'assigned_team_leader_id' => $ctx['teamLeader']->id,
        'pickup_address' => 'Taguig',
        'dropoff_address' => 'Pasig',
        'distance_km' => 6.2,
        'base_rate' => $ctx['truckType']->base_rate,
        'per_km_rate' => $ctx['truckType']->per_km_rate,
        'final_total' => 3200,
        'status' => 'assigned',
        'assigned_at' => now(),
    ]);

    Sanctum::actingAs($ctx['teamLeader'], ['*']);
    $current = test()->getJson('/api/v1/team-leader/task');
    $current->assertOk()
        ->assertJsonPath('data.booking_code', $newBooking->booking_code)
        ->assertJsonPath('data.status', 'assigned');
});
