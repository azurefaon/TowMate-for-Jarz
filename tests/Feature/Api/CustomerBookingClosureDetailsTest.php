<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function cbcdCustomer(): array
{
    $role = Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });

    $user = User::factory()->create(['role_id' => $role->id]);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => 'CBCD Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'cbcd-' . uniqid() . '@example.com',
    ]);

    return [$user, $customer];
}

function cbcdBooking(Customer $customer, array $overrides = []): Booking
{
    $truckType = TruckType::create([
        'name' => 'CBCD Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 2016,
        'status' => 'cancelled',
        'service_type' => 'book_now',
    ], $overrides));
}

it('exposes the dispatcher reason and actor on cancelled and rejected history rows', function () {
    [$user, $customer] = cbcdCustomer();
    $cancelled = cbcdBooking($customer, ['status' => 'cancelled', 'rejection_reason' => 'No unit available.']);
    $rejected = cbcdBooking($customer, ['status' => 'rejected', 'rejection_reason' => 'Outside coverage.']);

    Sanctum::actingAs($user, ['*']);
    $rows = collect(test()->getJson('/api/v1/bookings/history')->assertOk()->json('data'))->keyBy('booking_code');

    expect($rows[$cancelled->booking_code]['closed_by'])->toBe('dispatcher');
    expect($rows[$cancelled->booking_code]['rejection_reason'])->toBe('No unit available.');
    expect($rows[$rejected->booking_code]['closed_by'])->toBe('dispatcher');
    expect($rows[$rejected->booking_code]['rejection_reason'])->toBe('Outside coverage.');
});

it('attributes an audited customer cancellation to the customer', function () {
    [$user, $customer] = cbcdCustomer();
    $booking = cbcdBooking($customer, ['rejection_reason' => 'Changed my mind']);
    AuditLog::create([
        'user_id' => $user->id,
        'action' => 'booking_cancelled_by_customer',
        'entity_type' => 'Booking',
        'entity_id' => $booking->id,
        'description' => 'Cancelled by customer — Changed my mind',
    ]);

    Sanctum::actingAs($user, ['*']);
    $row = test()->getJson('/api/v1/bookings/history')->assertOk()->json('data.0');
    expect($row['closed_by'])->toBe('customer');

    $detail = test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail")->assertOk()->json('data');
    expect($detail['closed_by'])->toBe('customer');
    expect($detail['rejection_reason'])->toBe('Changed my mind');
});

it('reports no actor and no reason when none was recorded, and nothing for other statuses', function () {
    [$user, $customer] = cbcdCustomer();
    $silent = cbcdBooking($customer, ['status' => 'cancelled', 'rejection_reason' => null]);
    $done = cbcdBooking($customer, ['status' => 'completed', 'rejection_reason' => 'stale text']);

    Sanctum::actingAs($user, ['*']);
    $rows = collect(test()->getJson('/api/v1/bookings/history')->assertOk()->json('data'))->keyBy('booking_code');

    expect($rows[$silent->booking_code]['closed_by'])->toBeNull();
    expect($rows[$silent->booking_code]['rejection_reason'])->toBeNull();
    expect($rows[$done->booking_code]['closed_by'])->toBeNull();
    expect($rows[$done->booking_code]['rejection_reason'])->toBeNull();
});

it('does not leak another customer\'s cancellation reason through the detail endpoint', function () {
    [$owner, $ownerCustomer] = cbcdCustomer();
    [$other, ] = cbcdCustomer();
    $booking = cbcdBooking($ownerCustomer, ['rejection_reason' => 'Private reason']);

    Sanctum::actingAs($other, ['*']);
    test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail")->assertNotFound();
});
