<?php

/*
 * A booking is never ended just because nobody has answered it yet:
 *  - a Book Now request stays active, and the customer can follow up (5 min, then every 15 min);
 *  - an expired quotation leaves the booking active and can be replaced by a new one;
 *  - an expired or outdated quotation cannot be accepted;
 *  - an unconfirmed scheduled booking can be moved to a new date and time.
 */

use App\Exceptions\QuotationNotAcceptableException;
use App\Models\Booking;
use App\Models\DispatcherNotification;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/../../Concurrency/Support.php';

function bwBookNow(\App\Models\Customer $customer, array $attrs = []): Booking
{
    return ccScheduledBooking($customer, array_merge([
        'service_type' => 'book_now', 'status' => 'requested', 'scheduled_date' => null, 'scheduled_time' => null, 'scheduled_expires_at' => null,
    ], $attrs));
}

function bwExpire(Quotation $quotation): Quotation
{
    DB::table('quotations')->where('id', $quotation->id)->update(['expires_at' => now()->subMinute()]);

    return $quotation->fresh();
}

function bwField($response, string $key)
{
    return $response->json("data.{$key}") ?? $response->json($key);
}

/* ---------- Follow up ---------- */

it('refuses a follow-up in the first five minutes and tells the app how long to wait', function () {
    [$user, $customer] = ccCustomer();
    $booking = bwBookNow($customer);
    Sanctum::actingAs($user, ['*']);

    $this->postJson("/api/v1/bookings/{$booking->booking_code}/follow-up")
        ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('success', false);

    expect(DispatcherNotification::where('type', 'follow_up')->count())->toBe(0);
});

it('notifies the dispatcher after five minutes and then enforces the 15-minute cooldown', function () {
    [$user, $customer] = ccCustomer();
    $booking = bwBookNow($customer);
    Sanctum::actingAs($user, ['*']);

    $this->travel(6)->minutes();
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/follow-up")
        ->assertOk()->assertJsonPath('can_follow_up', false)->assertJsonPath('follow_up_in_seconds', 900);
    expect(DispatcherNotification::where('type', 'follow_up')->where('booking_id', $booking->id)->count())->toBe(1);

    // a double tap, and another tap 10 minutes later: both blocked, nothing extra sent
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/follow-up")->assertStatus(429);
    $this->travel(10)->minutes();
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/follow-up")->assertStatus(429);
    expect(DispatcherNotification::where('type', 'follow_up')->count())->toBe(1);

    // 16 minutes after the first follow-up it is allowed again
    $this->travel(6)->minutes();
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/follow-up")->assertOk();
    expect(DispatcherNotification::where('type', 'follow_up')->count())->toBe(2);
    expect($booking->fresh()->status)->toBe('requested'); // never ended
});

it('only lets the owner follow up and only while the request is waiting', function () {
    [, $customer] = ccCustomer('Owner One');
    [$other] = ccCustomer('Someone Else');
    $booking = bwBookNow($customer);
    $this->travel(10)->minutes();

    Sanctum::actingAs($other, ['*']);
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/follow-up")->assertNotFound();

    [$ownerUser, $ownerCustomer] = ccCustomer('Owner Two');
    $quoted = bwBookNow($ownerCustomer, ['status' => 'quotation_sent']);
    Sanctum::actingAs($ownerUser, ['*']);
    $this->postJson("/api/v1/bookings/{$quoted->booking_code}/follow-up")->assertStatus(422);

    $scheduled = ccScheduledBooking($ownerCustomer);
    $this->postJson("/api/v1/bookings/{$scheduled->booking_code}/follow-up")->assertStatus(422);

    expect(DispatcherNotification::where('type', 'follow_up')->count())->toBe(0);
});

it('shows the follow-up state in the booking detail', function () {
    [$user, $customer] = ccCustomer();
    $booking = bwBookNow($customer);
    Sanctum::actingAs($user, ['*']);

    $early = $this->getJson("/api/v1/bookings/{$booking->booking_code}/detail")->assertOk();
    expect(bwField($early, 'is_waiting_for_dispatcher'))->toBeTrue();
    expect(bwField($early, 'can_follow_up'))->toBeFalse();

    $this->travel(6)->minutes();
    $later = $this->getJson("/api/v1/bookings/{$booking->booking_code}/detail")->assertOk();
    expect(bwField($later, 'can_follow_up'))->toBeTrue();
});

it('renders a follow-up as a Dispatcher notification that opens the Book Now booking', function () {
    [, $customer] = ccCustomer('Waiting Wanda');
    $booking = bwBookNow($customer);
    $note = DispatcherNotification::create(['type' => DispatcherNotification::TYPE_FOLLOW_UP, 'booking_id' => $booking->id]);

    expect($note->title)->toBe('Customer is waiting');
    expect($note->subtitle_lines[0])->toContain($booking->booking_code);
    expect($note->target_url)->toContain('book-now')->toContain($booking->booking_code);
});

/* ---------- Quotation expiry ---------- */

it('keeps the booking active, the history and the capacity when a quotation expires', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = bwExpire(ccSentQuotation($booking));
    $slots = ccSlots('2030-03-10');

    app(QuotationService::class)->expireOldQuotations();

    expect($quotation->fresh()->status)->toBe('expired');
    expect($booking->fresh()->status)->toBe('scheduled');
    expect($booking->fresh()->quotation_status)->toBe('expired');
    expect(ccSlots('2030-03-10'))->toBe($slots);
});

it('never overwrites an accepted quotation when the expiry job runs', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = ccSentQuotation($booking);
    app(QuotationService::class)->acceptQuotation($quotation);
    DB::table('quotations')->where('id', $quotation->id)->update(['expires_at' => now()->subDay()]);

    app(QuotationService::class)->expireOldQuotations();

    expect($quotation->fresh()->status)->toBe('accepted');
    expect($booking->fresh()->status)->toBe('scheduled_confirmed');
    expect(ccSlots('2030-03-10'))->toBe(1);
});

it('refuses to accept an expired quotation even if the expiry job has not run yet', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = bwExpire(ccSentQuotation($booking));

    expect(fn () => app(QuotationService::class)->acceptQuotation($quotation))->toThrow(QuotationNotAcceptableException::class, 'expired');

    expect($booking->fresh()->status)->toBe('scheduled');
    expect(ccSlots('2030-03-10'))->toBeNull();
});

it('refuses to accept a quotation whose scheduled time has already passed', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = ccSentQuotation($booking);
    DB::table('quotations')->where('id', $quotation->id)->update(['scheduled_date' => now()->subDay()->toDateString(), 'expires_at' => now()->addDay()]);

    expect(fn () => app(QuotationService::class)->acceptQuotation($quotation->fresh()))->toThrow(QuotationNotAcceptableException::class, 'passed');
    expect(ccSlots('2030-03-10'))->toBeNull();
});

it('refuses to accept a scheduled quotation that has no time', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = ccSentQuotation($booking);
    DB::table('quotations')->where('id', $quotation->id)->update(['scheduled_time' => null]);

    expect(fn () => app(QuotationService::class)->acceptQuotation($quotation->fresh()))->toThrow(QuotationNotAcceptableException::class);
});

it('answers an expired acceptance with a plain 422 message over the API', function () {
    [$user, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = bwExpire(ccSentQuotation($booking));
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson("/api/v1/quotations/{$quotation->id}/accept")->assertStatus(422);
    expect($response->json('message'))->toContain('expired');
});

it('keeps the expired quotation as history when the dispatcher builds a new one', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $old = bwExpire(ccSentQuotation($booking));
    app(QuotationService::class)->expireOldQuotations();

    $fresh = ccSentQuotation($booking->fresh());

    expect($old->fresh()->status)->toBe('expired');
    expect($fresh->id)->not->toBe($old->id);
    expect(Quotation::where('source_booking_id', $booking->id)->count())->toBe(2);
});

/* ---------- Scheduled booking: reschedule ---------- */

it('lets the dispatcher reschedule an unconfirmed scheduled booking without confirming it', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $this->actingAs(ccDispatcher());

    $this->postJson(route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-05-01', 'new_scheduled_time' => '14:00'])->assertOk();

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe('scheduled');
    expect($fresh->scheduled_date->toDateString())->toBe('2030-05-01');
    expect(ccSlots('2030-05-01'))->toBeNull(); // nothing reserved until the customer accepts
});

it('refuses to reschedule into the past', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $this->actingAs(ccDispatcher());

    $this->postJson(route('admin.booking.reschedule', $booking), ['new_scheduled_date' => now()->subDay()->toDateString(), 'new_scheduled_time' => '09:00'])
        ->assertStatus(422);

    expect($booking->fresh()->scheduled_date->toDateString())->toBe('2030-03-10');
});

it('blocks a reschedule while a live quotation is waiting for the customer', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    ccSentQuotation($booking);
    $this->actingAs(ccDispatcher());

    $this->postJson(route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-05-01', 'new_scheduled_time' => '14:00'])
        ->assertStatus(422);

    expect($booking->fresh()->scheduled_date->toDateString())->toBe('2030-03-10');
});

it('allows rescheduling once the waiting quotation has expired', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    bwExpire(ccSentQuotation($booking));
    $this->actingAs(ccDispatcher());

    $this->postJson(route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-05-01', 'new_scheduled_time' => '14:00'])->assertOk();

    expect($booking->fresh()->scheduled_date->toDateString())->toBe('2030-05-01');
});

/* ---------- Scheduled booking: creation ---------- */

it('rejects a new scheduled booking with a time in the past or no time at all', function () {
    [$user] = ccCustomer();
    $type = \App\Models\TruckType::firstOrCreate(['name' => 'CC Light'], ['base_rate' => 1500, 'per_km_rate' => 60]);
    Sanctum::actingAs($user, ['*']);

    $payload = [
        'truck_type_id' => $type->id, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'pickup_lat' => 14.5, 'pickup_lng' => 121.0, 'drop_lat' => 14.6, 'drop_lng' => 121.1,
        'service_type' => 'schedule', 'scheduled_date' => now()->subDay()->toDateString(), 'scheduled_time' => '10:00',
    ];

    $this->postJson('/api/v1/bookings', $payload)->assertStatus(422);
    $this->postJson('/api/v1/bookings', array_merge($payload, ['scheduled_date' => now()->addDay()->toDateString(), 'scheduled_time' => null]))->assertStatus(422);
});

/* ---------- Expiry without a scheduler ---------- */

it('expires a lapsed quotation when the customer opens the booking, with no scheduler', function () {
    [$user, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = bwExpire(ccSentQuotation($booking));
    Sanctum::actingAs($user, ['*']);

    $this->getJson("/api/v1/bookings/{$booking->booking_code}/detail")->assertOk();

    expect($quotation->fresh()->status)->toBe('expired');
    expect($booking->fresh()->status)->toBe('scheduled');
});

it('expires a lapsed quotation when the dispatcher opens the queue, so it can be regenerated', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = bwExpire(ccSentQuotation($booking));
    $this->actingAs(ccDispatcher());

    $this->get(route('admin.dispatch'))->assertOk();

    expect($quotation->fresh()->status)->toBe('expired');
    expect($booking->fresh()->quotation_status)->toBe('expired');
});

it('only expires the opening customer\'s quotations when called for one customer', function () {
    [, $mine] = ccCustomer('Mine');
    [, $theirs] = ccCustomer('Theirs');
    $a = bwExpire(ccSentQuotation(ccScheduledBooking($mine)));
    $b = bwExpire(ccSentQuotation(ccScheduledBooking($theirs, ['scheduled_date' => '2030-03-11'])));

    expect(app(QuotationService::class)->expireOldQuotations($mine->id))->toBe(1);

    expect($a->fresh()->status)->toBe('expired');
    expect($b->fresh()->status)->toBe('sent');
});
