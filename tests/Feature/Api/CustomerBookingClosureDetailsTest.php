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

function cbcdAudit(User $actor, Booking $booking, string $action): void
{
    AuditLog::create([
        'user_id' => $actor->id,
        'action' => $action,
        'entity_type' => 'Booking',
        'entity_id' => $booking->id,
        'description' => $action,
    ]);
}

function cbcdHistoryRow(User $user, Booking $booking): array
{
    Sanctum::actingAs($user, ['*']);

    return collect(test()->getJson('/api/v1/bookings/history')->assertOk()->json('data'))
        ->keyBy('booking_code')[$booking->booking_code];
}

it('attributes an audited customer cancellation to the customer', function () {
    [$user, $customer] = cbcdCustomer();
    $booking = cbcdBooking($customer, ['rejection_reason' => 'Changed my mind']);
    cbcdAudit($user, $booking, 'booking_cancelled_by_customer');

    $row = cbcdHistoryRow($user, $booking);
    expect($row['closed_by'])->toBe('customer');
    expect($row['rejection_reason'])->toBe('Changed my mind');

    $detail = test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail")->assertOk()->json('data');
    expect($detail['closed_by'])->toBe('customer');
});

it('attributes audited staff cancellations and rejections to the dispatcher', function (string $action, string $status) {
    [$user, $customer] = cbcdCustomer();
    $staff = User::factory()->create();
    $booking = cbcdBooking($customer, ['status' => $status, 'rejection_reason' => 'No unit available.']);
    cbcdAudit($staff, $booking, $action);

    expect(cbcdHistoryRow($user, $booking)['closed_by'])->toBe('dispatcher');
    $detail = test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail")->assertOk()->json('data');
    expect($detail['closed_by'])->toBe('dispatcher');
    expect($detail['rejection_reason'])->toBe('No unit available.');
})->with([
    ['booking_cancelled_by_dispatcher', 'cancelled'],
    ['scheduled_booking_cancelled_by_dispatcher', 'rejected'],
    ['booking_rejected', 'rejected'],
]);

it('does not call a reason alone a dispatcher action', function (string $status) {
    [$user, $customer] = cbcdCustomer();
    $booking = cbcdBooking($customer, ['status' => $status, 'rejection_reason' => 'Booking cancelled by dispatcher.']);

    $row = cbcdHistoryRow($user, $booking);
    expect($row['closed_by'])->toBe('unknown');
    expect($row['rejection_reason'])->toBe('Booking cancelled by dispatcher.');
})->with(['cancelled', 'rejected']);

it('reports unknown actor and no reason when nothing was recorded', function () {
    [$user, $customer] = cbcdCustomer();
    $booking = cbcdBooking($customer, ['rejection_reason' => '   ']);

    $row = cbcdHistoryRow($user, $booking);
    expect($row['closed_by'])->toBe('unknown');
    expect($row['rejection_reason'])->toBeNull();
});

it('ignores unrelated audit events and reports nothing for other statuses', function () {
    [$user, $customer] = cbcdCustomer();
    $staff = User::factory()->create();
    $cancelled = cbcdBooking($customer);
    cbcdAudit($staff, $cancelled, 'booking_assigned');
    $done = cbcdBooking($customer, ['status' => 'completed', 'rejection_reason' => 'stale text']);
    cbcdAudit($staff, $done, 'booking_rejected');

    expect(cbcdHistoryRow($user, $cancelled)['closed_by'])->toBe('unknown');
    $row = cbcdHistoryRow($user, $done);
    expect($row['closed_by'])->toBeNull();
    expect($row['rejection_reason'])->toBeNull();
});

it('uses the latest audited actor when several exist', function () {
    [$user, $customer] = cbcdCustomer();
    $staff = User::factory()->create();
    $booking = cbcdBooking($customer);
    cbcdAudit($user, $booking, 'booking_cancelled_by_customer');
    cbcdAudit($staff, $booking, 'booking_cancelled_by_dispatcher');

    expect(cbcdHistoryRow($user, $booking)['closed_by'])->toBe('dispatcher');
});

it('does not expose another customer\'s closure details', function () {
    [$owner, $ownerCustomer] = cbcdCustomer();
    [$other, ] = cbcdCustomer();
    $booking = cbcdBooking($ownerCustomer, ['rejection_reason' => 'Private reason']);

    Sanctum::actingAs($other, ['*']);
    test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail")->assertNotFound();
    expect(test()->getJson('/api/v1/bookings/history')->assertOk()->json('data'))->toBe([]);
});
