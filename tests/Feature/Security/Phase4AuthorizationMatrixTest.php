<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function p4Role(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function p4SuperAdmin(): User
{
    return User::factory()->create(['role_id' => p4Role(1, 'Super Admin')->id, 'must_change_password' => false]);
}

function p4Dispatcher(): User
{
    return User::factory()->create(['role_id' => p4Role(2, 'Admin')->id, 'must_change_password' => false]);
}

function p4TeamLeaderUser(): User
{
    return User::factory()->create(['role_id' => p4Role(3, 'Team Leader')->id, 'must_change_password' => false]);
}

function p4CustomerWithBooking(): array
{
    $user = User::factory()->create(['role_id' => p4Role(5, 'Customer')->id]);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);
    $truckType = TruckType::create(['name' => 'P4 Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id'   => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'requested',
    ]);
    $booking->update(['booking_code' => 'TM-P4' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);
    $quotation = Quotation::create([
        'quotation_number'  => 'Q-P4-' . uniqid(),
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

function p4TlWithBooking(): array
{
    $tl = p4TeamLeaderUser();
    $truckType = TruckType::create(['name' => 'P4 TL Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $unit = Unit::create([
        'name' => 'P4 Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $tl->id,
        'status' => 'on_job',
    ]);
    $customer = Customer::create(['full_name' => 'P4 Customer', 'phone' => '09170000003', 'email' => 'p4-' . uniqid() . '@example.com']);
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

it('unauthenticated user is redirected/denied from every protected surface', function () {
    test()->get('/admin-dashboard')->assertRedirect('/login');
    test()->get('/superadmin/dashboard')->assertRedirect('/login');
    test()->get('/driver')->assertRedirect('/login');
    test()->getJson('/api/v1/bookings/current')->assertStatus(401);
    test()->getJson('/api/v1/team-leader/task')->assertStatus(401);
});

it('Customer: can read their own booking detail', function () {
    [$user, , $booking] = p4CustomerWithBooking();
    Sanctum::actingAs($user, ['*']);

    test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail")->assertOk();
});

it('Customer: cannot read a foreign booking detail', function () {
    [, , $bookingB] = p4CustomerWithBooking();
    [$userA] = p4CustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    test()->getJson("/api/v1/bookings/{$bookingB->booking_code}/detail")->assertStatus(404);
});

it('Customer: can cancel their own booking', function () {
    [$user, , $booking] = p4CustomerWithBooking();
    Sanctum::actingAs($user, ['*']);

    test()->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", [])->assertOk();
});

it('Customer: cannot cancel a foreign booking', function () {
    [, , $bookingB] = p4CustomerWithBooking();
    [$userA] = p4CustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    test()->postJson("/api/v1/bookings/{$bookingB->booking_code}/cancel", [])->assertStatus(404);
    expect($bookingB->fresh()->status)->toBe('requested');
});

it('Customer: can act on their own quotation', function () {
    [$user, , , $quotation] = p4CustomerWithBooking();
    Sanctum::actingAs($user, ['*']);

    test()->postJson("/api/v1/quotations/{$quotation->id}/reject", [])->assertOk();
});

it('Customer: cannot act on a foreign quotation', function () {
    [, , , $quotationB] = p4CustomerWithBooking();
    [$userA] = p4CustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    test()->postJson("/api/v1/quotations/{$quotationB->id}/reject", [])->assertStatus(403);
    expect($quotationB->fresh()->status)->toBe('sent');
});

it('Customer: cannot access a foreign receipt', function () {
    [, , $bookingB] = p4CustomerWithBooking();
    $bookingB->update(['status' => 'completed']);
    [$userA] = p4CustomerWithBooking();
    Sanctum::actingAs($userA, ['*']);

    test()->getJson("/api/v1/bookings/{$bookingB->booking_code}/receipt")->assertStatus(404);
});

it('Team Leader: can read their own assigned task', function () {
    [$tl] = p4TlWithBooking();
    Sanctum::actingAs($tl, ['*']);

    test()->getJson('/api/v1/team-leader/task')->assertOk()->assertJsonPath('data.status', 'accepted');
});

it('Team Leader: cannot mutate an unrelated task', function () {
    [, $bookingB] = p4TlWithBooking();
    [$tlA] = p4TlWithBooking();
    Sanctum::actingAs($tlA, ['*']);

    test()->patchJson("/api/v1/team-leader/task/{$bookingB->booking_code}/status", ['status' => 'on_the_way'])
        ->assertStatus(403);
});

it('Team Leader: cannot upload to an unrelated task', function () {
    [, $bookingB] = p4TlWithBooking();
    [$tlA] = p4TlWithBooking();
    Sanctum::actingAs($tlA, ['*']);

    test()->postJson("/api/v1/team-leader/task/{$bookingB->booking_code}/photo", [
        'photo' => \Illuminate\Http\UploadedFile::fake()->image('p.jpg'),
        'type' => 'arrival',
    ])->assertStatus(403);
});

it('Dispatcher: has operational access to the dispatch queue and jobs board', function () {
    $dispatcher = p4Dispatcher();
    test()->actingAs($dispatcher)->get(route('admin.dashboard'))->assertOk();
    test()->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();
    test()->actingAs($dispatcher)->get(route('admin.jobs'))->assertOk();
});

it('Dispatcher: is denied Owner-only system settings', function () {
    $dispatcher = p4Dispatcher();

    test()->actingAs($dispatcher)->get(route('superadmin.settings.index'))->assertStatus(403);
});

it('Owner: has access to Owner-only business management screens', function () {
    $owner = p4SuperAdmin();

    test()->actingAs($owner)->get(route('superadmin.dashboard'))->assertOk();
    test()->actingAs($owner)->get(route('superadmin.settings.index'))->assertOk();
});

it('Owner: is denied Dispatcher-only personnel-movement routes', function () {
    $owner = p4SuperAdmin();

    test()->actingAs($owner)->get(route('admin.dashboard'))->assertStatus(403);
    test()->actingAs($owner)->get(route('admin.drivers'))->assertStatus(403);
});

it('System configuration access (Super Admin role) does not grant automatic towing-operation access', function () {
    $superAdmin = p4SuperAdmin();

    test()->actingAs($superAdmin)->get(route('admin.dispatch'))->assertStatus(403);
    test()->actingAs($superAdmin)->get(route('admin.jobs'))->assertStatus(403);
});

it('a Team Leader cannot access Owner-only or Dispatcher-only web screens', function () {
    $tl = p4TeamLeaderUser();

    test()->actingAs($tl)->get(route('admin.dashboard'))->assertStatus(403);
    test()->actingAs($tl)->get(route('superadmin.dashboard'))->assertStatus(403);
});

it('a Customer web account cannot access any staff dashboard', function () {
    $customerUser = User::factory()->create(['role_id' => p4Role(5, 'Customer')->id]);

    test()->actingAs($customerUser)->get(route('admin.dashboard'))->assertStatus(403);
    test()->actingAs($customerUser)->get(route('superadmin.dashboard'))->assertStatus(403);
});
