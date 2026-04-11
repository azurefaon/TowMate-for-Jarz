<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;

test('team leader acceptance auto-assigns its own unit and exposes a public booking code', function () {
    $this->seed(SuperAdminSeeder::class);

    $admin = User::factory()->create([
        'role_id' => 2,
        'name' => 'Dispatch Admin',
    ]);

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'Jordan Lead',
    ]);

    $driver = User::factory()->create([
        'role_id' => 4,
        'name' => 'Avery Driver',
    ]);

    $truckType = TruckType::create([
        'name' => 'Flatbed',
        'base_rate' => 1500,
        'per_km_rate' => 65,
        'status' => 'active',
    ]);

    $unit = Unit::create([
        'name' => 'Unit Alpha',
        'plate_number' => 'ABC-1234',
        'truck_type_id' => $truckType->id,
        'driver_id' => $driver->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    $customer = Customer::create([
        'full_name' => 'Jamie Customer',
        'phone' => '09171234567',
        'email' => 'jamie@example.com',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'created_by_admin_id' => $admin->id,
        'pickup_address' => 'North Avenue',
        'dropoff_address' => 'South Avenue',
        'status' => 'confirmed',
    ]);

    $response = $this->actingAs($teamLeader)
        ->post(route('teamleader.task.accept', $booking));

    $response->assertOk();

    $booking->refresh();

    expect($booking->assigned_unit_id)->toBe($unit->id)
        ->and($booking->booking_code)->not->toBeNull()
        ->and($booking->booking_code)->toMatch('/^\d{7}$/')
        ->and(route('teamleader.task.show', $booking))->toContain($booking->booking_code);
});
