<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function cbcRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cbcCustomerWithBooking(string $status): array
{
    $user = User::factory()->create(['role_id' => cbcRole(5, 'Customer')->id]);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);
    $truckType = TruckType::create(['name' => 'CBC Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id'   => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => $status,
    ]);
    $booking->update(['booking_code' => 'TM-CBC' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return [$user, $customer, $booking->fresh()];
}

it('rejects cancellation once a booking has progressed into an active job', function () {
    [$user, , $booking] = cbcCustomerWithBooking('on_the_way');
    Sanctum::actingAs($user, ['*']);

    test()->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", [])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('rejects cancellation of an already completed booking', function () {
    [$user, , $booking] = cbcCustomerWithBooking('completed');
    Sanctum::actingAs($user, ['*']);

    test()->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", [])
        ->assertStatus(422);

    expect($booking->fresh()->status)->toBe('completed');
});

it('rejects cancellation of a booking already awaiting verification', function () {
    [$user, , $booking] = cbcCustomerWithBooking('waiting_verification');
    Sanctum::actingAs($user, ['*']);

    test()->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", [])
        ->assertStatus(422);

    expect($booking->fresh()->status)->toBe('waiting_verification');
});

it('still allows cancellation of a confirmed Scheduled booking before dispatch', function () {
    [$user, , $booking] = cbcCustomerWithBooking('scheduled_confirmed');
    Sanctum::actingAs($user, ['*']);

    test()->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", [])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('cancelled');
});

it('rejects accepting a superseded (non-current) quotation even by its rightful owner', function () {
    [$user, $customer, $booking] = cbcCustomerWithBooking('quotation_sent');

    $superseded = Quotation::create([
        'quotation_number'  => 'Q-CBC-OLD-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id'       => $customer->id,
        'truck_type_id'     => $booking->truck_type_id,
        'pickup_address'    => $booking->pickup_address,
        'dropoff_address'   => $booking->dropoff_address,
        'distance_km'       => 5,
        'estimated_price'   => 2016,
        'status'            => 'sent',
        'sent_at'           => now()->subHour(),
        'is_current'        => false,
    ]);

    Quotation::create([
        'quotation_number'  => 'Q-CBC-NEW-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id'       => $customer->id,
        'truck_type_id'     => $booking->truck_type_id,
        'pickup_address'    => $booking->pickup_address,
        'dropoff_address'   => $booking->dropoff_address,
        'distance_km'       => 5,
        'estimated_price'   => 2200,
        'status'            => 'sent',
        'sent_at'           => now(),
        'is_current'        => true,
    ]);

    Sanctum::actingAs($user, ['*']);

    test()->postJson("/api/v1/quotations/{$superseded->id}/accept", [])
        ->assertStatus(422);

    expect($superseded->fresh()->status)->toBe('sent');
    expect($booking->fresh()->status)->toBe('quotation_sent');
});

it('rejects requesting a price review on a superseded (non-current) quotation', function () {
    [$user, $customer, $booking] = cbcCustomerWithBooking('quotation_sent');

    $superseded = Quotation::create([
        'quotation_number'  => 'Q-CBC-OLD2-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id'       => $customer->id,
        'truck_type_id'     => $booking->truck_type_id,
        'pickup_address'    => $booking->pickup_address,
        'dropoff_address'   => $booking->dropoff_address,
        'distance_km'       => 5,
        'estimated_price'   => 2016,
        'status'            => 'sent',
        'sent_at'           => now()->subHour(),
        'is_current'        => false,
    ]);

    Sanctum::actingAs($user, ['*']);

    test()->postJson("/api/v1/quotations/{$superseded->id}/request-price-review", ['reason' => 'too expensive'])
        ->assertStatus(422);

    expect($superseded->fresh()->status)->toBe('sent');
});
