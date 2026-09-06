<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

function p4tRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function p4tCustomer(): array
{
    $user = User::factory()->create(['role_id' => p4tRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function p4tTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'P4T Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function p4tTlWithBooking(): array
{
    $tl = User::factory()->create(['role_id' => p4tRole(3, 'Team Leader')->id, 'must_change_password' => false]);
    $truckType = p4tTruckType();
    $unit = Unit::create([
        'name' => 'P4T Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $tl->id,
        'status' => 'on_job',
    ]);
    $customer = Customer::create(['full_name' => 'P4T Customer', 'phone' => '09170000005', 'email' => 'p4t-' . uniqid() . '@example.com']);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'pickup_address' => 'Origin', 'dropoff_address' => 'Destination', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'accepted', 'assigned_at' => now(),
    ]);

    return [$tl, $booking->fresh(), $unit];
}

it('customer cannot promote their own role via profile update', function () {
    [$user] = p4tCustomer();
    Sanctum::actingAs($user, ['*']);

    test()->postJson('/api/v1/profile/update', [
        'first_name' => 'Still',
        'last_name'  => 'Customer',
        'role_id'    => 1,
    ])->assertOk();

    expect((int) $user->fresh()->role_id)->toBe(p4tRole(5, 'Customer')->id);
});

it('customer cannot mark their own booking as completed or assign a unit/team leader through booking creation', function () {
    [$user, $customer] = p4tCustomer();
    $truckType = p4tTruckType();
    $unit = Unit::create([
        'name' => 'P4T Rogue Unit',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);
    $tl = User::factory()->create(['role_id' => p4tRole(3, 'Team Leader')->id]);
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5,
        'pickup_lng' => 121.0,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6,
        'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'status' => 'completed',
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'final_total' => 1,
        'computed_total' => 1,
        'base_rate' => 1,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);

    $response->assertCreated();
    $booking = Booking::where('customer_id', $customer->id)->latest()->first();

    expect($booking->status)->not->toBe('completed');
    expect($booking->assigned_unit_id)->toBeNull();
    expect($booking->assigned_team_leader_id)->toBeNull();
    expect((float) $booking->final_total)->not->toBe(1.0);
    expect((float) $booking->base_rate)->toBe(1500.0);
});

it('customer cannot forge distance/pricing fields on booking creation — server recomputes from truck type', function () {
    [$user, $customer] = p4tCustomer();
    $truckType = p4tTruckType();
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5,
        'pickup_lng' => 121.0,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6,
        'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'base_rate' => 1,
        'per_km_rate' => 1,
        'computed_total' => 1,
        'final_total' => 1,
        'vat_amount' => 0,
        'additional_fee' => -500,
        'discount_percentage' => 100,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);

    $response->assertCreated();
    $booking = Booking::where('customer_id', $customer->id)->latest()->first();

    expect((float) $booking->base_rate)->toBe((float) $truckType->base_rate);
    expect((float) $booking->per_km_rate)->toBe((float) $truckType->per_km_rate);
    expect((float) $booking->final_total)->toBeGreaterThan(100.0);
    expect((float) $booking->additional_fee)->toBe(0.0);
});

it('Team Leader cannot forge the final_total used to validate cash payment on completion', function () {
    [$tl, $booking] = p4tTlWithBooking();
    \App\Models\Invoice::create([
        'booking_id' => $booking->id,
        'subtotal' => 1800,
        'total' => $booking->final_total,
        'status' => 'issued',
        'is_current' => true,
    ]);
    Sanctum::actingAs($tl, ['*']);

    $response = test()->postJson("/api/v1/team-leader/task/{$booking->booking_code}/complete", [
        'payment_method' => 'cash',
        'cash_received' => 1,
        'final_total' => 1,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ]);

    $response->assertStatus(422);
    expect($booking->fresh()->status)->toBe('accepted');
});

it('Team Leader cannot bypass payment proof requirement by claiming it exists without uploading it', function () {
    [$tl, $booking] = p4tTlWithBooking();
    \App\Models\Invoice::create([
        'booking_id' => $booking->id,
        'subtotal' => 1800,
        'total' => $booking->final_total,
        'status' => 'issued',
        'is_current' => true,
    ]);
    Sanctum::actingAs($tl, ['*']);

    $response = test()->postJson("/api/v1/team-leader/task/{$booking->booking_code}/complete", [
        'payment_method' => 'gcash',
        'payment_proof_path' => 'task-photos/fabricated.jpg',
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ]);

    $response->assertStatus(422);
});

it('Team Leader status update cannot smuggle unrelated booking fields such as final_total', function () {
    [$tl, $booking] = p4tTlWithBooking();
    Sanctum::actingAs($tl, ['*']);

    test()->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", [
        'status' => 'on_the_way',
        'final_total' => 1,
        'assigned_team_leader_id' => 99999,
    ])->assertOk();

    $fresh = $booking->fresh();
    expect((float) $fresh->final_total)->toBe(2016.0);
    expect((int) $fresh->assigned_team_leader_id)->toBe($tl->id);
});
