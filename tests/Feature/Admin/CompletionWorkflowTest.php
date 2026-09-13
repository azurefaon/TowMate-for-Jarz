<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\ReportMetricsService;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

function cwRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cwDispatcher(): User
{
    cwRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function cwTeamLeader(): User
{
    cwRole(3, 'Team Leader');

    return User::factory()->create(['role_id' => 3, 'status' => 'active', 'must_change_password' => false]);
}

function cwCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'CW Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'cw-' . uniqid() . '@example.com',
    ]);
}

function cwBooking(array $overrides = []): Booking
{
    $customer = cwCustomer();
    $truckType = TruckType::create(['name' => 'CW Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'in_progress',
    ], $overrides));
}

it('rejects a direct completed status via the generic active-bookings endpoint', function () {
    $dispatcher = cwDispatcher();
    $booking = cwBooking(['status' => 'in_progress']);

    $response = $this->actingAs($dispatcher)->patch(route('admin.active-bookings.update-status', $booking), [
        'status' => 'completed',
    ]);

    $response->assertSessionHasErrors('status');
    expect($booking->fresh()->status)->toBe('in_progress');
});

it('returns a business-rule error message pointing to the payment confirmation flow', function () {
    $dispatcher = cwDispatcher();
    $booking = cwBooking(['status' => 'waiting_verification']);

    $response = $this->actingAs($dispatcher)->patch(route('admin.active-bookings.update-status', $booking), [
        'status' => 'completed',
    ]);

    $response->assertSessionHasErrors(['status' => 'A booking can only be marked completed through the payment confirmation flow.']);
});

it('accepts a json request and still rejects the completed override with a 422', function () {
    $dispatcher = cwDispatcher();
    $booking = cwBooking(['status' => 'in_progress']);

    $response = $this->actingAs($dispatcher)->patchJson(route('admin.active-bookings.update-status', $booking), [
        'status' => 'completed',
    ]);

    $response->assertStatus(422);
    expect($booking->fresh()->status)->toBe('in_progress');
});

it('still allows every legitimate intermediate transition through the generic endpoint', function () {
    $dispatcher = cwDispatcher();

    $transitions = [
        'assigned', 'accepted', 'confirmed', 'on_the_way', 'arrived_pickup',
        'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification',
    ];

    foreach ($transitions as $status) {
        $booking = cwBooking(['status' => 'accepted']);

        $response = $this->actingAs($dispatcher)->patch(route('admin.active-bookings.update-status', $booking), [
            'status' => $status,
        ]);

        $response->assertSessionHasNoErrors();
        expect($booking->fresh()->status)->toBe($status);
    }
});

it('completes a booking only through the canonical payment confirmation endpoint', function () {
    Mail::fake();
    $dispatcher = cwDispatcher();
    $booking = cwBooking(['status' => 'waiting_verification']);

    $response = $this->actingAs($dispatcher)->post(route('admin.jobs.confirm-payment', $booking));

    $response->assertOk();
    $booking->refresh();
    expect($booking->status)->toBe('completed');
    expect($booking->completed_at)->not->toBeNull();
});

it('records a payment_confirmed audit event on canonical completion', function () {
    Mail::fake();
    $dispatcher = cwDispatcher();
    $booking = cwBooking(['status' => 'payment_submitted']);

    $this->actingAs($dispatcher)->post(route('admin.jobs.confirm-payment', $booking));

    $log = AuditLog::where('action', 'payment_confirmed')->where('entity_id', $booking->id)->latest()->first();
    expect($log)->not->toBeNull();
});

it('rejects canonical completion when the booking is not yet in a payment-verification status', function () {
    $dispatcher = cwDispatcher();
    $booking = cwBooking(['status' => 'in_progress']);

    $response = $this->actingAs($dispatcher)->post(route('admin.jobs.confirm-payment', $booking));

    $response->assertStatus(422);
    expect($booking->fresh()->status)->toBe('in_progress');
});

it('includes a canonically completed booking in revenue reporting', function () {
    Mail::fake();
    $dispatcher = cwDispatcher();
    $booking = cwBooking(['status' => 'waiting_verification', 'final_total' => 5000]);

    $this->actingAs($dispatcher)->post(route('admin.jobs.confirm-payment', $booking));

    $metrics = app(ReportMetricsService::class);
    $result = $metrics->averageRevenuePerJob(now()->startOfMonth(), now()->endOfMonth());

    expect($result['completed_jobs'])->toBe(1);
    expect($result['revenue'])->toBe(5000.0);
});

it('never lets a bypass-completion attempt appear as completed in reporting', function () {
    $dispatcher = cwDispatcher();
    $booking = cwBooking(['status' => 'in_progress', 'final_total' => 99999]);

    $this->actingAs($dispatcher)->patch(route('admin.active-bookings.update-status', $booking), [
        'status' => 'completed',
    ]);

    $metrics = app(ReportMetricsService::class);
    $result = $metrics->averageRevenuePerJob(now()->startOfMonth(), now()->endOfMonth());

    expect($result['completed_jobs'])->toBe(0);
    expect($result['revenue'])->toBe(0.0);
});

it('leaves the existing team leader returned transition unaffected', function () {
    $tl = cwTeamLeader();
    $truckType = TruckType::create(['name' => 'CW TL Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $unit = Unit::create([
        'name' => 'CW TL Unit', 'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id, 'team_leader_id' => $tl->id, 'status' => 'on_job',
    ]);
    $customer = cwCustomer();
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'accepted',
    ]);

    Sanctum::actingAs($tl, ['*']);

    $response = test()->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", [
        'status' => 'returned',
    ]);

    $response->assertOk();
    expect($booking->fresh()->status)->toBe('returned');
});
