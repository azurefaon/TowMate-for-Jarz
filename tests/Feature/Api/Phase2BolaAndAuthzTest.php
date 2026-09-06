<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function bolaCustomerRole(): Role
{
    return Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });
}

function bolaTlRole(): Role
{
    return Role::find(3) ?: tap(new Role(['name' => 'Team Leader']), function ($r) {
        $r->id = 3;
        $r->save();
    });
}

function bolaCustomerWithBooking(string $bookingStatus = 'requested'): array
{
    $user = User::factory()->create(['role_id' => bolaCustomerRole()->id]);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);
    $truckType = TruckType::create(['name' => 'BOLA Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id'   => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => $bookingStatus,
    ]);
    $booking->update(['booking_code' => 'TM-B' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT)]);
    $quotation = Quotation::create([
        'quotation_number'  => 'Q-BOLA-' . uniqid(),
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

function bolaTlWithBooking(): array
{
    $tl = User::factory()->create(['role_id' => bolaTlRole()->id, 'must_change_password' => false]);
    $truckType = TruckType::create(['name' => 'BOLA TL Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $unit = Unit::create([
        'name' => 'BOLA Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $tl->id,
        'status' => 'on_job',
    ]);
    $customer = Customer::create(['full_name' => 'BOLA Customer', 'phone' => '09170000001', 'email' => 'bola-' . uniqid() . '@example.com']);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'accepted', 'assigned_at' => now(),
    ]);

    return [$tl, $booking->fresh()];
}

// ── CUSTOMER BOLA ──────────────────────────────────────────────────────────

it('7: Customer A cannot fetch Customer B booking details', function () {
    [, , $bookingB] = bolaCustomerWithBooking();
    [$userA] = bolaCustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    $this->getJson("/api/v1/bookings/{$bookingB->booking_code}/detail")->assertStatus(404);
});

it('8: Customer A cannot cancel Customer B booking', function () {
    [, , $bookingB] = bolaCustomerWithBooking();
    [$userA] = bolaCustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    $this->postJson("/api/v1/bookings/{$bookingB->booking_code}/cancel", [])->assertStatus(404);

    expect($bookingB->fresh()->status)->toBe('requested');
});

it('9: Customer A cannot accept Customer B quotation', function () {
    [, , , $quotationB] = bolaCustomerWithBooking();
    [$userA] = bolaCustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    $this->postJson("/api/v1/quotations/{$quotationB->id}/accept", [])->assertStatus(403);

    expect($quotationB->fresh()->status)->toBe('sent');
});

it('10: Customer A cannot reject Customer B quotation', function () {
    [, , , $quotationB] = bolaCustomerWithBooking();
    [$userA] = bolaCustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    $this->postJson("/api/v1/quotations/{$quotationB->id}/reject", [])->assertStatus(403);

    expect($quotationB->fresh()->status)->toBe('sent');
});

it('11: Customer A cannot request a price review for Customer B quotation', function () {
    [, , , $quotationB] = bolaCustomerWithBooking();
    [$userA] = bolaCustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    $this->postJson("/api/v1/quotations/{$quotationB->id}/request-price-review", ['reason' => 'because'])
        ->assertStatus(403);

    expect($quotationB->fresh()->status)->toBe('sent');
});

it('11b: Customer A cannot send an inquiry on Customer B quotation', function () {
    [, , , $quotationB] = bolaCustomerWithBooking();
    [$userA] = bolaCustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    $this->postJson("/api/v1/quotations/{$quotationB->id}/inquire", ['message' => 'hijack'])
        ->assertStatus(403);

    expect($quotationB->fresh()->customer_inquiry)->toBeNull();
});

// ── TEAM LEADER BOLA ────────────────────────────────────────────────────────

it('12: TL A cannot change the status of TL B booking', function () {
    [, $bookingB] = bolaTlWithBooking();
    [$tlA] = bolaTlWithBooking();
    Sanctum::actingAs($tlA, ['*']);

    $this->patchJson("/api/v1/team-leader/task/{$bookingB->booking_code}/status", ['status' => 'on_the_way'])
        ->assertStatus(403);

    expect($bookingB->fresh()->status)->toBe('accepted');
});

it('13: TL A cannot complete TL B booking', function () {
    Storage::fake('public');
    [, $bookingB] = bolaTlWithBooking();
    [$tlA] = bolaTlWithBooking();
    Sanctum::actingAs($tlA, ['*']);

    $this->post("/api/v1/team-leader/task/{$bookingB->booking_code}/complete", [
        'payment_method' => 'cash',
        'cash_received' => 5000,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertStatus(403);

    expect($bookingB->fresh()->status)->toBe('accepted');
});

it('14: TL A cannot upload a task photo to TL B booking', function () {
    Storage::fake('public');
    [, $bookingB] = bolaTlWithBooking();
    [$tlA] = bolaTlWithBooking();
    Sanctum::actingAs($tlA, ['*']);

    $this->post("/api/v1/team-leader/task/{$bookingB->booking_code}/photo", [
        'photo' => UploadedFile::fake()->image('p.jpg'),
        'type' => 'arrival',
    ])->assertStatus(403);

    expect($bookingB->fresh()->arrival_photo_path)->toBeNull();
});

it('14b: TL A cannot self-assign into TL B group they have no history with', function () {
    [$tlB, $bookingB] = bolaTlWithBooking();
    $bookingB->update(['group_code' => 'GRP-BOLA-' . uniqid(), 'assigned_team_leader_id' => null, 'status' => 'confirmed']);
    [$tlA] = bolaTlWithBooking();
    Sanctum::actingAs($tlA, ['*']);

    $this->postJson('/api/v1/team-leader/group/' . $bookingB->group_code . '/claim-next')
        ->assertStatus(403);

    expect($bookingB->fresh()->assigned_team_leader_id)->toBeNull();
});

// ── FUNCTION-LEVEL AUTHORIZATION ─────────────────────────────────────────────

it('15: a customer cannot call a Team Leader mutation endpoint', function () {
    [, $booking] = bolaTlWithBooking();
    [$customerUser] = bolaCustomerWithBooking();
    Sanctum::actingAs($customerUser, ['*']);

    $this->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", ['status' => 'on_the_way'])
        ->assertStatus(403);
});

it('16: a Team Leader cannot call a customer-restricted mutation endpoint', function () {
    [, , $booking] = bolaCustomerWithBooking();
    [$tl] = bolaTlWithBooking();
    Sanctum::actingAs($tl, ['*']);

    // The customer booking-cancel endpoint resolves the acting customer
    // purely from auth()->id() — a Team Leader has no Customer profile, so
    // this must fail closed rather than accidentally act on someone else's
    // booking.
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", [])
        ->assertStatus(404);

    expect($booking->fresh()->status)->toBe('requested');
});

it('17: an unauthenticated request cannot call an authenticated mutation', function () {
    [, , $booking] = bolaCustomerWithBooking();

    $this->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", [])
        ->assertStatus(401);

    $this->postJson('/api/v1/team-leader/presence/ping')->assertStatus(401);
});
