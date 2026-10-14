<?php

/*
 * Capacity reservation marker (bookings.capacity_reserved_date): acceptance
 * records the date it counted a slot on; cancellation and rejection give that
 * exact slot back once; rescheduling moves it; bookings accepted before the
 * marker existed are never released or moved on a guess.
 */

use App\Models\AuditLog;
use App\Models\Booking;
use App\Observers\AuditObserver;
use App\Services\QuotationService;
use App\Services\ScheduledCapacityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/../../Concurrency/Support.php';

/** A booking accepted through the real acceptance path, so it carries its marker. */
function crAccepted(\App\Models\Customer $customer, array $attrs = []): Booking
{
    $booking = ccScheduledBooking($customer, $attrs);
    app(QuotationService::class)->acceptQuotation(ccSentQuotation($booking));

    return $booking->fresh();
}

function crMarker(Booking $booking): ?string
{
    return $booking->fresh()->capacity_reserved_date?->toDateString();
}

function crFailOn(string $table, string $condition): void
{
    DB::unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION cr_fail() RETURNS trigger AS \$\$
        BEGIN
            IF {$condition} THEN RAISE EXCEPTION 'simulated failure'; END IF;
            RETURN NEW;
        END \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER cr_fail BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION cr_fail();
    SQL);
}

/* ---------- Acceptance ---------- */

it('records the counted date on the booking when a quotation is accepted', function () {
    [, $customer] = ccCustomer();

    $booking = crAccepted($customer);

    expect(crMarker($booking))->toBe('2030-03-10');
    expect(ccSlots('2030-03-10'))->toBe(1);
    expect((float) $booking->final_total)->toBe(2000.0); // pricing is untouched by the reservation
});

it('marks every vehicle of a group on its own date, one slot each', function () {
    [, $customer] = ccCustomer();
    $primary = ccScheduledBooking($customer);
    $sameDay = ccScheduledBooking($customer, ['group_code' => 'GRP-R1']);
    $otherDay = ccScheduledBooking($customer, ['group_code' => 'GRP-R1', 'scheduled_date' => '2030-03-11']);

    app(QuotationService::class)->acceptQuotation(ccSentQuotation($primary, [$sameDay, $otherDay]));

    expect([crMarker($primary), crMarker($sameDay), crMarker($otherDay)])->toBe(['2030-03-10', '2030-03-10', '2030-03-11']);
    expect([ccSlots('2030-03-10'), ccSlots('2030-03-11')])->toBe([2, 1]);
});

it('does not count a booking twice, even if reserve is asked again', function () {
    [, $customer] = ccCustomer();
    $booking = crAccepted($customer);

    $again = app(ScheduledCapacityService::class)->reserve($booking, '2030-03-10');

    expect($again)->toBeFalse();
    expect(ccSlots('2030-03-10'))->toBe(1);
});

it('does not enforce slots_max: acceptance still counts past the configured limit', function () {
    [, $customer] = ccCustomer();
    DB::table('booking_capacity')->insert(['booking_date' => '2030-03-10', 'slots_used' => 2, 'slots_max' => 2, 'created_at' => now(), 'updated_at' => now()]);

    $booking = crAccepted($customer);

    expect($booking->status)->toBe('scheduled_confirmed');
    expect(ccSlots('2030-03-10'))->toBe(3);
});

/* ---------- Customer cancellation ---------- */

it('releases the slot once when a customer cancels, and a repeated request releases nothing more', function () {
    [$user, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    Sanctum::actingAs($user, ['*']);

    $this->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", ['reason' => 'Plans changed'])->assertOk();

    expect(ccSlots('2030-03-10'))->toBe(0);
    expect(crMarker($booking))->toBeNull();

    $this->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", ['reason' => 'Again'])->assertStatus(422);
    expect(ccSlots('2030-03-10'))->toBe(0);
});

it('releases the marker date, not the current date, so a service-level release is idempotent', function () {
    [, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    $capacity = app(ScheduledCapacityService::class);

    expect($capacity->release($booking))->toBe(ScheduledCapacityService::RELEASED);
    expect($capacity->release($booking))->toBe(ScheduledCapacityService::NONE);
    expect($capacity->release($booking->fresh()))->toBe(ScheduledCapacityService::NONE);
    expect(ccSlots('2030-03-10'))->toBe(0);
});

it('releases one slot per cancelled vehicle of a group and keeps the rest', function () {
    [$user, $customer] = ccCustomer();
    $primary = ccScheduledBooking($customer);
    $second = ccScheduledBooking($customer, ['group_code' => 'GRP-R2']);
    $third = ccScheduledBooking($customer, ['group_code' => 'GRP-R2', 'scheduled_date' => '2030-03-11']);
    app(QuotationService::class)->acceptQuotation(ccSentQuotation($primary, [$second, $third]));
    Sanctum::actingAs($user, ['*']);

    $this->postJson("/api/v1/bookings/group/GRP-R2/cancel", ['booking_codes' => [$second->booking_code, $third->booking_code], 'reason' => 'Two fewer'])->assertOk();

    expect([ccSlots('2030-03-10'), ccSlots('2030-03-11')])->toBe([1, 0]);
    expect([crMarker($primary), crMarker($second), crMarker($third)])->toBe(['2030-03-10', null, null]);
    expect($primary->fresh()->status)->toBe('scheduled_confirmed');
});

/* ---------- Dispatcher / admin ---------- */

it('releases the slot when a dispatcher rejects an accepted scheduled booking', function () {
    [, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    $this->actingAs(ccDispatcher());

    $this->postJson(route('admin.booking.assign', $booking), ['action' => 'reject', 'rejection_reason' => 'No truck available'])->assertOk();

    expect($booking->fresh()->status)->toBe('cancelled');
    expect(ccSlots('2030-03-10'))->toBe(0);
    expect(crMarker($booking))->toBeNull();
});

it('releases the slot when a dispatcher cancels an assigned job', function () {
    [, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    $booking->update(['status' => 'assigned']);
    $this->actingAs(ccDispatcher());

    $this->postJson(route('admin.jobs.cancel', $booking), ['reason' => 'Customer unreachable'])->assertOk();

    expect($booking->fresh()->status)->toBe('cancelled');
    expect(ccSlots('2030-03-10'))->toBe(0);
    expect(crMarker($booking))->toBeNull();
});

it('keeps the slot of a completed booking: completion is consumption, not a release', function () {
    [, $customer] = ccCustomer();
    $booking = crAccepted($customer);

    $booking->update(['status' => 'completed']);

    expect(ccSlots('2030-03-10'))->toBe(1);
    expect(crMarker($booking))->toBe('2030-03-10');
});

/* ---------- Rescheduling ---------- */

it('moves the counted slot to the new date when a reserved booking is rescheduled', function () {
    [, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    $this->actingAs(ccDispatcher());

    $this->postJson(route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-04-05', 'new_scheduled_time' => '10:00'])->assertOk();

    expect([ccSlots('2030-03-10'), ccSlots('2030-04-05')])->toBe([0, 1]);
    expect(crMarker($booking))->toBe('2030-04-05');
    expect($booking->fresh()->scheduled_date->toDateString())->toBe('2030-04-05');
});

it('changes no counter when only the time changes', function () {
    [, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    $this->actingAs(ccDispatcher());

    $this->postJson(route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-03-10', 'new_scheduled_time' => '15:30'])->assertOk();

    expect(ccSlots('2030-03-10'))->toBe(1);
    expect(crMarker($booking))->toBe('2030-03-10');
});

it('then releases the new date when the rescheduled booking is cancelled', function () {
    [$user, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    $this->actingAs(ccDispatcher());
    $this->postJson(route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-04-05', 'new_scheduled_time' => '10:00'])->assertOk();
    Sanctum::actingAs($user, ['*']);

    $this->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", ['reason' => 'Changed my mind'])->assertOk();

    expect([ccSlots('2030-03-10'), ccSlots('2030-04-05')])->toBe([0, 0]);
});

it('rolls a failed reschedule back completely: date, counters and marker', function () {
    [, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    $this->actingAs(ccDispatcher());
    AuditObserver::flush(); // write out what the setup queued, then count
    $audits = AuditLog::count();
    crFailOn('bookings', "NEW.scheduled_date = DATE '2030-04-05' AND OLD.scheduled_date IS DISTINCT FROM NEW.scheduled_date");

    $this->postJson(route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-04-05', 'new_scheduled_time' => '10:00'])->assertStatus(500);
    AuditObserver::flush();

    expect($booking->fresh()->scheduled_date->toDateString())->toBe('2030-03-10');
    expect(crMarker($booking))->toBe('2030-03-10');
    expect([ccSlots('2030-03-10'), ccSlots('2030-04-05')])->toBe([1, null]);
    expect(AuditLog::count())->toBe($audits); // no phantom audit rows for the failed change
});

it('refuses to reschedule, changing nothing, when the old date\'s counter cannot give the slot back', function () {
    [, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    DB::table('booking_capacity')->where('booking_date', '2030-03-10')->update(['slots_used' => 0]); // an existing accounting error
    $this->actingAs(ccDispatcher());
    Log::spy();

    $this->postJson(route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-04-05', 'new_scheduled_time' => '10:00'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($booking->fresh()->scheduled_date->toDateString())->toBe('2030-03-10');
    expect(crMarker($booking))->toBe('2030-03-10');
    expect([ccSlots('2030-03-10'), ccSlots('2030-04-05')])->toBe([0, null]);
    Log::shouldHaveReceived('error')->once();
});

/* ---------- Legacy bookings (accepted before the marker existed) ---------- */

it('never releases or moves a legacy booking on a guess, and says so in the log', function () {
    [$user, $customer] = ccCustomer();
    $legacy = ccScheduledBooking($customer, ['status' => 'scheduled_confirmed', 'price_locked_at' => now()]);
    DB::table('booking_capacity')->insert(['booking_date' => '2030-03-10', 'slots_used' => 1, 'slots_max' => 2, 'created_at' => now(), 'updated_at' => now()]);
    Log::spy();

    // Reschedule: existing non-reserved behaviour, no counter involved.
    $this->actingAs(ccDispatcher());
    $this->postJson(route('admin.booking.reschedule', $legacy), ['new_scheduled_date' => '2030-04-05', 'new_scheduled_time' => '10:00'])->assertOk();
    expect([ccSlots('2030-03-10'), ccSlots('2030-04-05')])->toBe([1, null]);
    expect(crMarker($legacy))->toBeNull();

    // Cancel: nothing is released, but it is logged rather than skipped silently.
    Sanctum::actingAs($user, ['*']);
    $this->postJson("/api/v1/bookings/{$legacy->booking_code}/cancel", ['reason' => 'Plans changed'])->assertOk();
    expect(ccSlots('2030-03-10'))->toBe(1);
    expect($legacy->fresh()->status)->toBe('cancelled');
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'no capacity marker'))->once();
});

it('lists unmarked bookings in the read-only report, always with their current date as the marker they will get', function () {
    [, $customer] = ccCustomer();
    $plain = ccScheduledBooking($customer, ['status' => 'scheduled_confirmed', 'price_locked_at' => now()]);
    $moved = ccScheduledBooking($customer, ['status' => 'scheduled_confirmed', 'price_locked_at' => now(), 'scheduled_date' => '2030-03-12']);
    AuditLog::create(['action' => 'booking_rescheduled', 'entity_type' => 'Booking', 'entity_id' => $moved->id, 'reference' => $moved->booking_code, 'description' => 'moved']);
    $marked = crAccepted($customer, ['scheduled_date' => '2030-03-13']);
    $before = DB::table('bookings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    $report = app(\App\Services\CapacityReconciliationService::class)->report();
    $legacy = collect($report['legacy'])->keyBy('booking_code');

    expect($legacy->keys()->sort()->values()->all())->toBe(collect([$plain->booking_code, $moved->booking_code])->sort()->values()->all());
    expect($legacy[$plain->booking_code])->toMatchArray(['suggested_marker' => '2030-03-10', 'rescheduled' => false]);
    expect($legacy[$moved->booking_code])->toMatchArray(['suggested_marker' => '2030-03-12', 'rescheduled' => true]); // history is only an observation
    expect($report['summary'])->toMatchArray(['legacy_active_unmarked' => 2, 'legacy_rescheduled' => 1]);
    expect(collect($report['dates'])->firstWhere('date', '2030-03-13'))->toMatchArray(['marked_holders' => 1, 'legacy_holders' => 0, 'status' => 'ok']);
    expect(DB::table('bookings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before); // read-only
});

/* ---------- Refused decrement, rollback and audit ---------- */

it('refuses an impossible release, keeps the marker, logs it, and still cancels the booking', function () {
    [$user, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    DB::table('booking_capacity')->where('booking_date', '2030-03-10')->update(['slots_used' => 0]);
    Sanctum::actingAs($user, ['*']);
    Log::spy();

    $this->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", ['reason' => 'Plans changed'])->assertOk();

    expect($booking->fresh()->status)->toBe('cancelled');
    expect(ccSlots('2030-03-10'))->toBe(0); // never driven negative
    expect(crMarker($booking))->toBe('2030-03-10'); // left visible, not hidden
    Log::shouldHaveReceived('error')->once();
});

it('rolls the cancellation back, status and audit included, when the capacity update fails', function () {
    [$user, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    AuditObserver::flush();
    $audits = AuditLog::count();
    crFailOn('booking_capacity', 'TRUE');
    Sanctum::actingAs($user, ['*']);

    $this->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", ['reason' => 'Plans changed'])->assertStatus(500);
    AuditObserver::flush();

    expect($booking->fresh()->status)->toBe('scheduled_confirmed');
    expect(crMarker($booking))->toBe('2030-03-10');
    expect(ccSlots('2030-03-10'))->toBe(1);
    expect(AuditLog::count())->toBe($audits);
});

it('never lets a counter go negative however many times release is asked', function () {
    [, $customer] = ccCustomer();
    $a = crAccepted($customer);
    $b = crAccepted($customer);
    $capacity = app(ScheduledCapacityService::class);

    foreach ([$a, $a, $b, $b, $a] as $booking) {
        $capacity->release($booking);
    }

    expect(ccSlots('2030-03-10'))->toBe(0);
});

it('never gives back the slot of a completed booking, even if something later cancels it', function () {
    [, $customer] = ccCustomer();
    $booking = crAccepted($customer);
    $booking->update(['status' => 'completed']);

    $booking->update(['status' => 'cancelled']); // e.g. an unconditional reject
    $result = app(ScheduledCapacityService::class)->release($booking);

    expect($result)->toBe(ScheduledCapacityService::NONE);
    expect(ccSlots('2030-03-10'))->toBe(1);
    expect(crMarker($booking))->toBe('2030-03-10');
});
