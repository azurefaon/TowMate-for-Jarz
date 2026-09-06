<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\DispatcherNotification;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function makeDispatcherNotificationFixture(): array
{
    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);

    $dispatcher = User::factory()->create(['role_id' => 2]);

    $truckType = TruckType::create([
        'name' => 'Notif Truck',
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'Notification test truck',
    ]);

    $customer = Customer::create([
        'full_name' => 'Notif Customer',
        'age' => 30,
        'phone' => '09170001111',
        'email' => 'notif@example.com',
    ]);

    return compact('dispatcher', 'truckType', 'customer');
}

function baseNotifBookingAttributes(TruckType $truckType, Customer $customer): array
{
    return [
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'age' => 30,
        'pickup_address' => 'Quezon City',
        'dropoff_address' => 'Manila',
        'distance_km' => 6,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1950,
        'final_total' => 1950,
    ];
}

it('creates a new_book_now notification when a Book Now booking is created', function () {
    ['truckType' => $truckType, 'customer' => $customer] = makeDispatcherNotificationFixture();

    $booking = Booking::create(array_merge(baseNotifBookingAttributes($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $notification = DispatcherNotification::where('booking_id', $booking->id)->first();

    expect($notification)->not->toBeNull();
    expect($notification->type)->toBe(DispatcherNotification::TYPE_NEW_BOOK_NOW);
    expect($notification->isRead())->toBeFalse();
});

it('creates a new_scheduled notification when a Scheduled booking is created', function () {
    ['truckType' => $truckType, 'customer' => $customer] = makeDispatcherNotificationFixture();

    $booking = Booking::create(array_merge(baseNotifBookingAttributes($truckType, $customer), [
        'service_type' => 'schedule',
        'status' => 'requested',
        'scheduled_for' => now()->addDay(),
    ]));

    $notification = DispatcherNotification::where('booking_id', $booking->id)->first();

    expect($notification)->not->toBeNull();
    expect($notification->type)->toBe(DispatcherNotification::TYPE_NEW_SCHEDULED);
});

it('creates a verification_required notification when a booking transitions to waiting_verification', function () {
    ['truckType' => $truckType, 'customer' => $customer] = makeDispatcherNotificationFixture();

    $booking = Booking::create(array_merge(baseNotifBookingAttributes($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    // Creation already produced a new_book_now notification — this checks the transition adds a second one.
    $booking->update(['status' => 'waiting_verification']);

    $notifications = DispatcherNotification::where('booking_id', $booking->id)->get();

    expect($notifications)->toHaveCount(2);
    expect($notifications->pluck('type')->sort()->values()->all())->toBe([
        DispatcherNotification::TYPE_NEW_BOOK_NOW,
        DispatcherNotification::TYPE_VERIFICATION_REQUIRED,
    ]);
});

it('does not create a notification for unrelated status transitions', function () {
    ['truckType' => $truckType, 'customer' => $customer] = makeDispatcherNotificationFixture();

    $booking = Booking::create(array_merge(baseNotifBookingAttributes($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    DispatcherNotification::query()->delete();

    $booking->update(['status' => 'assigned']);
    $booking->update(['status' => 'on_the_way']);

    expect(DispatcherNotification::count())->toBe(0);
});

it('computes the unread count and marks all notifications as read', function () {
    ['dispatcher' => $dispatcher, 'truckType' => $truckType, 'customer' => $customer] = makeDispatcherNotificationFixture();

    Booking::create(array_merge(baseNotifBookingAttributes($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    Booking::create(array_merge(baseNotifBookingAttributes($truckType, $customer), [
        'service_type' => 'schedule',
        'status' => 'requested',
        'scheduled_for' => now()->addHours(3),
    ]));

    expect(DispatcherNotification::unread()->count())->toBe(2);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.notifications.mark-all-read'))
        ->assertOk()
        ->assertJson(['success' => true, 'unreadCount' => 0]);

    expect(DispatcherNotification::unread()->count())->toBe(0);
});

it('marks a single notification read and redirects to its target page when opened', function () {
    ['dispatcher' => $dispatcher, 'truckType' => $truckType, 'customer' => $customer] = makeDispatcherNotificationFixture();

    $booking = Booking::create(array_merge(baseNotifBookingAttributes($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $notification = DispatcherNotification::where('booking_id', $booking->id)->firstOrFail();

    $this->actingAs($dispatcher)
        ->get(route('admin.notifications.open', $notification))
        ->assertRedirect(route('admin.dispatch', ['type' => 'book-now', 'booking' => $booking->booking_code]));

    expect($notification->fresh()->isRead())->toBeTrue();
});

it('redirects a verification_required notification to the jobs page', function () {
    ['dispatcher' => $dispatcher, 'truckType' => $truckType, 'customer' => $customer] = makeDispatcherNotificationFixture();

    $booking = Booking::create(array_merge(baseNotifBookingAttributes($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $booking->update(['status' => 'waiting_verification']);

    $notification = DispatcherNotification::where('type', DispatcherNotification::TYPE_VERIFICATION_REQUIRED)
        ->where('booking_id', $booking->id)
        ->firstOrFail();

    $this->actingAs($dispatcher)
        ->get(route('admin.notifications.open', $notification))
        ->assertRedirect(route('admin.jobs'));
});

it('shows the notification bell badge only when there are unread notifications', function () {
    ['dispatcher' => $dispatcher, 'truckType' => $truckType, 'customer' => $customer] = makeDispatcherNotificationFixture();

    $this->actingAs($dispatcher)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('id="dispatcherNotifCount"', false);

    Booking::create(array_merge(baseNotifBookingAttributes($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $this->actingAs($dispatcher)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('id="dispatcherNotifCount"', false)
        ->assertSee('1');
});

it('lists notifications on the full notifications page', function () {
    ['dispatcher' => $dispatcher, 'truckType' => $truckType, 'customer' => $customer] = makeDispatcherNotificationFixture();

    Booking::create(array_merge(baseNotifBookingAttributes($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $this->actingAs($dispatcher)
        ->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertSee('New booking request');
});
