<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function p4bRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function p4bCustomerWithBooking(): array
{
    $user = User::factory()->create(['role_id' => p4bRole(5, 'Customer')->id]);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);
    $truckType = TruckType::create(['name' => 'P4B Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id'   => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'requested',
    ]);
    $booking->update(['booking_code' => 'TM-P4B' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);
    $quotation = Quotation::create([
        'quotation_number'  => 'Q-P4B-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id'       => $customer->id,
        'truck_type_id'     => $truckType->id,
        'pickup_address'    => $booking->pickup_address,
        'dropoff_address'   => $booking->dropoff_address,
        'distance_km'       => 5,
        'estimated_price'   => $booking->final_total,
        'status'            => 'sent',
        'sent_at'           => now(),
    ]);

    return [$user, $customer, $booking->fresh(), $quotation->fresh()];
}

function p4bTlWithBooking(): array
{
    $tl = User::factory()->create(['role_id' => p4bRole(3, 'Team Leader')->id, 'must_change_password' => false]);
    $truckType = TruckType::create(['name' => 'P4B TL Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $unit = Unit::create([
        'name' => 'P4B Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $tl->id,
        'status' => 'on_job',
    ]);
    $customer = Customer::create(['full_name' => 'P4B Customer', 'phone' => '09170000004', 'email' => 'p4b-' . uniqid() . '@example.com']);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'accepted', 'assigned_at' => now(),
    ]);

    return [$tl, $booking->fresh(), $unit];
}

it('substituting a foreign booking code into the URL leaks no data', function () {
    [, , $bookingB] = p4bCustomerWithBooking();
    $bookingB->update(['pickup_address' => 'Unit 42 Confidential Compound', 'final_total' => 9876.54]);
    [$userA] = p4bCustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    $response = test()->getJson("/api/v1/bookings/{$bookingB->booking_code}/detail");

    $response->assertStatus(404);
    expect($response->getContent())->not->toContain('Unit 42 Confidential Compound');
    expect($response->getContent())->not->toContain('9876.54');
});

it('substituting a numeric quotation ID for a foreign quotation is rejected without leaking price data', function () {
    [, , , $quotationB] = p4bCustomerWithBooking();
    [$userA] = p4bCustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    $response = test()->postJson("/api/v1/quotations/{$quotationB->id}/accept", []);

    $response->assertStatus(403);
    expect($response->getContent())->not->toContain((string) $quotationB->estimated_price);
});

it('a sequential/adjacent quotation ID one off from the caller\'s own is still rejected', function () {
    [$userA, , , $quotationA] = p4bCustomerWithBooking();
    [, , , $quotationB] = p4bCustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    $guessedId = $quotationA->id + 1;
    if ($guessedId === $quotationB->id) {
        test()->postJson("/api/v1/quotations/{$guessedId}/accept", [])->assertStatus(403);
    } else {
        test()->postJson("/api/v1/quotations/{$guessedId}/accept", [])->assertStatus(404);
    }
});

it('substituting a foreign booking code into the receipt endpoint is rejected', function () {
    [, , $bookingB] = p4bCustomerWithBooking();
    $bookingB->update(['status' => 'completed']);
    [$userA] = p4bCustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    test()->getJson("/api/v1/bookings/{$bookingB->booking_code}/receipt")->assertStatus(404);
});

it('a Team Leader substituting a foreign booking code into every task mutation is rejected', function () {
    [, $bookingB] = p4bTlWithBooking();
    [$tlA] = p4bTlWithBooking();
    Sanctum::actingAs($tlA, ['*']);

    test()->postJson("/api/v1/team-leader/task/{$bookingB->booking_code}/accept")->assertStatus(403);
    test()->patchJson("/api/v1/team-leader/task/{$bookingB->booking_code}/status", ['status' => 'on_the_way'])->assertStatus(403);
    test()->postJson("/api/v1/team-leader/task/{$bookingB->booking_code}/return", ['reason' => 'x'])->assertStatus(403);
});

it('a Team Leader cannot manipulate group_code to self-assign into an unrelated group', function () {
    [$tlB, $bookingB] = p4bTlWithBooking();
    $bookingB->update(['group_code' => 'GRP-P4B-' . uniqid(), 'assigned_team_leader_id' => null, 'status' => 'confirmed']);
    [$tlA] = p4bTlWithBooking();
    Sanctum::actingAs($tlA, ['*']);

    test()->postJson('/api/v1/team-leader/group/' . $bookingB->group_code . '/claim-next')
        ->assertStatus(403);
    expect($bookingB->fresh()->assigned_team_leader_id)->toBeNull();
});

it('a Team Leader cannot claim a fabricated/nonexistent group_code', function () {
    [$tlA] = p4bTlWithBooking();
    Sanctum::actingAs($tlA, ['*']);

    test()->postJson('/api/v1/team-leader/group/GRP-DOES-NOT-EXIST/claim-next')
        ->assertStatus(403);
});

it('a non-existent booking code returns 404 without leaking whether it ever existed', function () {
    [$user] = p4bCustomerWithBooking();
    Sanctum::actingAs($user, ['*']);

    $response = test()->getJson('/api/v1/bookings/TM-DOES-NOT-EXIST/detail');

    $response->assertStatus(404);
});
