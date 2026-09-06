<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function phase2CustomerRole(): Role
{
    return Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });
}

function phase2TeamLeaderRole(): Role
{
    return Role::find(3) ?: tap(new Role(['name' => 'Team Leader']), function ($r) {
        $r->id = 3;
        $r->save();
    });
}

function phase2CustomerScenario(): array
{
    $user = User::factory()->create(['role_id' => phase2CustomerRole()->id]);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function phase2Booking(Customer $customer, string $status = 'requested'): Booking
{
    $truckType = TruckType::create([
        'name' => 'RL Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);

    $booking = Booking::create([
        'customer_id'   => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 2016,
        'status' => $status,
    ]);
    $booking->update(['booking_code' => 'TM-RL' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

function phase2Quotation(Booking $booking): Quotation
{
    return Quotation::create([
        'quotation_number'   => 'Q-RL-' . uniqid(),
        'source_booking_id'  => $booking->id,
        'customer_id'        => $booking->customer_id,
        'truck_type_id'      => $booking->truck_type_id,
        'pickup_address'     => $booking->pickup_address,
        'dropoff_address'    => $booking->dropoff_address,
        'distance_km'        => $booking->distance_km,
        'estimated_price'    => $booking->final_total,
        'status'             => 'sent',
        'sent_at'            => now(),
    ]);
}

function phase2TlScenario(): array
{
    $tl = User::factory()->create(['role_id' => phase2TeamLeaderRole()->id, 'must_change_password' => false]);
    $truckType = TruckType::create(['name' => 'RL TL Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $unit = Unit::create([
        'name' => 'RL Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $tl->id,
        'status' => 'on_job',
    ]);
    $customer = Customer::create(['full_name' => 'RL Customer', 'phone' => '09170000000', 'email' => 'rl-' . uniqid() . '@example.com']);
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

// 1 — booking creation throttled ──────────────────────────────────────────
it('1: booking creation is rate limited', function () {
    [$user] = phase2CustomerScenario();
    Sanctum::actingAs($user, ['*']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/bookings', [])->assertStatus(422);
    }

    $this->postJson('/api/v1/bookings', [])->assertStatus(429);
});

// 2 — quotation mutation throttled ────────────────────────────────────────
it('2: quotation mutation is rate limited', function () {
    [$user, $customer] = phase2CustomerScenario();
    $booking = phase2Booking($customer);
    $quotation = phase2Quotation($booking);
    Sanctum::actingAs($user, ['*']);

    for ($i = 0; $i < 15; $i++) {
        $this->postJson("/api/v1/quotations/{$quotation->id}/inquire", ['message' => 'hi']);
    }

    $this->postJson("/api/v1/quotations/{$quotation->id}/inquire", ['message' => 'hi'])
        ->assertStatus(429);
});

// 3 — cancellation throttled ──────────────────────────────────────────────
it('3: booking cancellation is rate limited', function () {
    [$user, $customer] = phase2CustomerScenario();
    $booking = phase2Booking($customer);
    Sanctum::actingAs($user, ['*']);

    for ($i = 0; $i < 8; $i++) {
        $this->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", []);
    }

    $this->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", [])
        ->assertStatus(429);
});

// 4 — geo proxy throttled ─────────────────────────────────────────────────
it('4: the authenticated mobile geo proxy is rate limited', function () {
    [$user] = phase2CustomerScenario();
    Sanctum::actingAs($user, ['*']);

    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/api/v1/geo/search?q=test');
    }

    $this->getJson('/api/v1/geo/search?q=test')->assertStatus(429);
});

it('4b: the public web geo proxy is rate limited', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->getJson('/geo/search?q=test');
    }

    $this->getJson('/geo/search?q=test')->assertStatus(429);
});

// 5 — upload throttled ────────────────────────────────────────────────────
it('5: Team Leader photo upload is rate limited', function () {
    Storage::fake('public');
    [$tl, $booking] = phase2TlScenario();
    Sanctum::actingAs($tl, ['*']);

    for ($i = 0; $i < 15; $i++) {
        $this->post("/api/v1/team-leader/task/{$booking->booking_code}/photo", [
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('p.jpg'),
            'type' => 'arrival',
        ]);
    }

    $this->post("/api/v1/team-leader/task/{$booking->booking_code}/photo", [
        'photo' => \Illuminate\Http\UploadedFile::fake()->image('p.jpg'),
        'type' => 'arrival',
    ])->assertStatus(429);
});

// 6 — presence/location limiter permits legitimate traffic but blocks abuse ─
it('6: Team Leader presence pings within legitimate polling volume are never throttled', function () {
    [$tl] = phase2TlScenario();
    Sanctum::actingAs($tl, ['*']);

    // A real client pings roughly once every few seconds — well under the limit.
    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/api/v1/team-leader/presence/ping')->assertOk();
    }
});

it('6b: Team Leader presence ping abuse beyond the legitimate volume is throttled', function () {
    [$tl] = phase2TlScenario();
    Sanctum::actingAs($tl, ['*']);

    for ($i = 0; $i < 40; $i++) {
        $this->postJson('/api/v1/team-leader/presence/ping');
    }

    $this->postJson('/api/v1/team-leader/presence/ping')->assertStatus(429);
});

it('6c: Team Leader location updates within legitimate polling volume are never throttled', function () {
    [$tl] = phase2TlScenario();
    Sanctum::actingAs($tl, ['*']);

    // The controller itself de-dupes writes <10s apart; simulate a compliant
    // client by advancing the clock 10s between each of a generous number of ticks.
    for ($i = 0; $i < 15; $i++) {
        $this->putJson('/api/v1/team-leader/location', ['lat' => 14.5, 'lng' => 121.0])->assertOk();
        $this->travel(10)->seconds();
    }
});
