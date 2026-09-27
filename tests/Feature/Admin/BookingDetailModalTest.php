<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function bdmRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function bdmDispatcher(): User
{
    return User::factory()->create(['role_id' => bdmRole(2, 'Dispatcher')->id, 'status' => 'active', 'must_change_password' => false]);
}

function bdmCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'BDM Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'bdm-' . uniqid() . '@example.com',
    ]);
}

function bdmTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'BDM Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function bdmBooking(TruckType $truckType, Customer $customer, array $overrides = []): Booking
{
    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Origin',
        'dropoff_address' => 'Destination',
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 2016,
        'service_type' => 'book_now',
        'status' => 'requested',
    ], $overrides));
}

it('renders the centered booking detail modal container on the dispatch page', function () {
    $dispatcher = bdmDispatcher();

    $response = test()->actingAs($dispatcher)->get(route('admin.dispatch'));
    $response->assertOk();

    $response->assertSee('id="bookingDetailModalOverlay"', false);
    $response->assertSee('window.closeBookingDetailModal()', false);
    $response->assertSee('detailBundle:', false);
});

it('opens the unified drawer from the Immediate/Book Now row click alone, with no redundant "View Quotation" button', function () {
    $dispatcher = bdmDispatcher();
    $customer = bdmCustomer();
    $truckType = bdmTruckType();
    $booking = bdmBooking($truckType, $customer, ['status' => 'requested', 'service_type' => 'book_now']);

    $response = test()->actingAs($dispatcher)->get(route('admin.dispatch'));
    $response->assertOk();
    $html = $response->getContent();

    // The row itself still opens the unified drawer on click — unchanged.
    preg_match('/<tr[^>]*data-booking-code="' . preg_quote($booking->booking_code, '/') . '"[^>]*>/s', $html, $rowMatch);
    expect($rowMatch)->not->toBeEmpty();
    expect($rowMatch[0])->toContain('onclick="window.openBookingDrawer(this)"');

    // The per-row "View Quotation" trigger button was removed as redundant
    // (the row click already opens the same drawer); no button markup for it
    // should remain, and the legacy modal call must never appear here either.
    expect($html)->not->toContain('bdm-trigger-btn--table');
    expect($html)->not->toContain("event.stopPropagation(); window.openBookingDrawer(this.closest('tr'))");
    expect($html)->not->toContain("window.openBookingDetailModal('{$booking->booking_code}')");
});

it('opens the unified drawer from the Scheduled row click alone, with no redundant "View Quotation" button', function () {
    $dispatcher = bdmDispatcher();
    $customer = bdmCustomer();
    $truckType = bdmTruckType();
    $booking = bdmBooking($truckType, $customer, [
        'status' => 'scheduled',
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
    ]);

    $response = test()->actingAs($dispatcher)->get(route('admin.dispatch'));
    $response->assertOk();
    $html = $response->getContent();

    // The Scheduled row's own click still opens the unified drawer, unchanged.
    preg_match('/<tr[^>]*data-booking-code="' . preg_quote($booking->booking_code, '/') . '"[^>]*>/s', $html, $rowMatch);
    expect($rowMatch)->not->toBeEmpty();
    expect($rowMatch[0])->toContain('onclick="window.openBookingDrawer(this)"');
    expect($rowMatch[0])->not->toContain('openBookingDetailModal');

    // The per-row "View Quotation" trigger button was removed as redundant;
    // no button markup for it should remain, and the legacy modal call must
    // never appear here either.
    expect($html)->not->toContain('View quotation for ' . $booking->booking_code);
    expect($html)->not->toContain('bdm-trigger-btn--table');

    $response->assertDontSee("window.openBookingDetailModal('{$booking->booking_code}')", false);
});

it('does not read booking.quotation_id in the modal script — it only trusts the bundle response', function () {
    $js = file_get_contents(public_path('dispatcher/js/booking-detail-modal.js'));

    expect($js)->toContain('data.quotation');
    expect($js)->not->toContain('booking.quotation_id');
    expect($js)->not->toContain('.quotation_id');
});

it('renders the modal script and route without duplicating a second detail endpoint', function () {
    $response = test()->actingAs(bdmDispatcher())->get(route('admin.dispatch'));
    $response->assertOk();

    expect(substr_count($response->getContent(), '/detail-bundle'))->toBe(1);
});
