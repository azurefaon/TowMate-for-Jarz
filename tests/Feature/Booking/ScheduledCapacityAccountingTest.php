<?php

/*
 * Scheduled-booking capacity accounting. A capacity slot is reserved only when
 * a scheduled booking's quotation is accepted and its price locked; an expired,
 * unaccepted booking never reserved one, so cancelling it releases nothing.
 */

use App\Exceptions\QuotationAlreadyProcessedException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\DispatcherNotification;
use App\Services\QuotationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../Concurrency/Support.php';

function scCapacity(string $date, int $used): void
{
    DB::table('booking_capacity')->insert(['booking_date' => $date, 'slots_used' => $used, 'slots_max' => 2, 'created_at' => now(), 'updated_at' => now()]);
}

function scExpired(array $attrs = []): Booking
{
    [, $customer] = ccCustomer('Expiry Customer ' . random_int(1, 99999));

    return ccScheduledBooking($customer, array_merge(['scheduled_expires_at' => now()->subHour()], $attrs));
}

/* ---------- A passed 7-day window never cancels a scheduled booking ---------- */

it('no longer cancels an unaccepted scheduled booking just because its 7-day window passed', function () {
    scCapacity('2030-03-10', 1); // held by some other, accepted booking
    $booking = scExpired();

    $this->artisan('bookings:expire-scheduled')->expectsOutput('Scheduled bookings are no longer cancelled automatically. Nothing to do.')->assertExitCode(0);

    expect($booking->fresh()->status)->toBe('scheduled');
    expect(ccSlots('2030-03-10'))->toBe(1);
});

it('is not on the scheduler any more', function () {
    $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");

    expect($scheduled)->not->toContain('bookings:expire-scheduled');
});

it('leaves every kind of scheduled booking, expired window or not, exactly as it was', function () {
    [, $customer] = ccCustomer('Mixed Customer');
    $bookings = [
        'accepted' => ccScheduledBooking($customer, ['status' => 'scheduled_confirmed', 'price_locked_at' => now(), 'scheduled_expires_at' => now()->subDay()]),
        'quoted' => ccScheduledBooking($customer, ['quotation_generated' => true, 'scheduled_expires_at' => now()->subDay()]),
        'window passed, never quoted' => ccScheduledBooking($customer, ['scheduled_expires_at' => now()->subDays(30)]),
        'not expired yet' => ccScheduledBooking($customer, ['scheduled_expires_at' => now()->addHour()]),
        'no expiry set' => ccScheduledBooking($customer, ['scheduled_expires_at' => null]),
        'book now' => ccScheduledBooking($customer, ['status' => 'requested', 'service_type' => 'book_now', 'scheduled_expires_at' => now()->subDay()]),
    ];
    scCapacity('2030-03-10', 1);
    $before = collect($bookings)->map(fn ($b) => $b->fresh()->only(['status', 'updated_at']))->all();

    $this->artisan('bookings:expire-scheduled')->assertExitCode(0);

    foreach ($bookings as $label => $booking) {
        expect($booking->fresh()->only(['status', 'updated_at']))->toEqual($before[$label], $label);
    }
    expect(ccSlots('2030-03-10'))->toBe(1);
});

it('sends no notifications, events or audit rows when it runs', function () {
    scExpired();
    \App\Observers\AuditObserver::flush(); // write out the rows the setup itself queued
    $audits = AuditLog::count();
    $notifications = DispatcherNotification::count();
    Event::fake();

    $this->artisan('bookings:expire-scheduled')->assertExitCode(0);

    foreach ([\App\Events\BookingStatusUpdated::class, \App\Events\BookingCancelled::class, 'eloquent.updating: ' . Booking::class, 'eloquent.updated: ' . Booking::class, 'eloquent.saved: ' . Booking::class] as $event) {
        Event::assertNotDispatched($event);
    }
    expect(AuditLog::count())->toBe($audits)->and(DispatcherNotification::count())->toBe($notifications);
});

/* ---------- Acceptance reserves one slot per vehicle ---------- */

it('reserves exactly one slot when a single scheduled booking is accepted', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);

    app(QuotationService::class)->acceptQuotation(ccSentQuotation($booking));

    expect($booking->fresh()->status)->toBe('scheduled_confirmed');
    expect($booking->fresh()->price_locked_at)->not->toBeNull();
    expect(ccSlots('2030-03-10'))->toBe(1);
});

it('reserves one slot per vehicle for a group, on each vehicle\'s own date', function () {
    [, $customer] = ccCustomer();
    $primary = ccScheduledBooking($customer);
    $sameDay = ccScheduledBooking($customer, ['group_code' => 'GRP-T1']);
    $otherDay = ccScheduledBooking($customer, ['group_code' => 'GRP-T1', 'scheduled_date' => '2030-03-11']);

    app(QuotationService::class)->acceptQuotation(ccSentQuotation($primary, [$sameDay, $otherDay]));

    expect(ccSlots('2030-03-10'))->toBe(2);
    expect(ccSlots('2030-03-11'))->toBe(1);
    expect(Booking::whereIn('id', [$primary->id, $sameDay->id, $otherDay->id])->where('status', 'scheduled_confirmed')->count())->toBe(3);
});

it('refuses a second acceptance and adds no second slot', function () {
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = ccSentQuotation($booking);
    $service = app(QuotationService::class);

    $service->acceptQuotation($quotation);

    // A stale copy, as a second request would hold it.
    $stale = \App\Models\Quotation::find($quotation->id);
    $stale->status = 'sent';

    expect(fn () => $service->acceptQuotation($stale))->toThrow(QuotationAlreadyProcessedException::class, 'Quotation already processed.');
    expect(ccSlots('2030-03-10'))->toBe(1);
});

it('rolls acceptance back completely, slot included, when a later step fails', function () {
    [, $customer] = ccCustomer();
    $primary = ccScheduledBooking($customer);
    $sibling = ccScheduledBooking($customer, ['group_code' => 'GRP-T2']);
    $quotation = ccSentQuotation($primary, [$sibling]);

    DB::unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION sc_fail() RETURNS trigger AS \$\$
        BEGIN
            IF NEW.id = {$sibling->id} AND NEW.status = 'scheduled_confirmed' THEN RAISE EXCEPTION 'simulated failure'; END IF;
            RETURN NEW;
        END \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER sc_fail BEFORE UPDATE ON bookings FOR EACH ROW EXECUTE FUNCTION sc_fail();
    SQL);

    // The failure happens after the primary booking was confirmed and its slot reserved.
    DB::beginTransaction(); // isolate the aborted statement from the test's own transaction
    try {
        app(QuotationService::class)->acceptQuotation($quotation);
        $thrown = null;
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    DB::rollBack();

    expect($thrown)->not->toBeNull();
    expect($quotation->fresh()->status)->toBe('sent');
    expect($primary->fresh()->status)->toBe('scheduled')->and($primary->fresh()->price_locked_at)->toBeNull();
    expect(ccSlots('2030-03-10'))->toBeNull();
});

/* ---------- Read-only reconciliation ---------- */

it('reports stored versus accepted capacity by date without changing anything', function () {
    [, $customer] = ccCustomer();
    ccScheduledBooking($customer, ['status' => 'scheduled_confirmed', 'price_locked_at' => now(), 'booking_code' => 'TM-R1']);       // holds a slot on 03-10
    ccScheduledBooking($customer, ['status' => 'scheduled_confirmed', 'price_locked_at' => now(), 'scheduled_date' => '2030-03-12']); // holds a slot, no row at all
    ccScheduledBooking($customer, ['status' => 'cancelled', 'price_locked_at' => now(), 'scheduled_date' => '2030-03-11']);          // accepted then cancelled
    ccScheduledBooking($customer, ['status' => 'scheduled', 'scheduled_date' => '2030-03-13']);                                       // unaccepted: holds nothing
    scCapacity('2030-03-10', 3); // too high
    scCapacity('2030-03-11', 1); // too high: its booking ended
    scCapacity('2030-03-13', 0); // correct
    DB::table('booking_capacity')->insert(['booking_date' => '2030-03-14', 'slots_used' => -1, 'slots_max' => 2, 'created_at' => now(), 'updated_at' => now()]);
    $before = ['capacity' => DB::table('booking_capacity')->orderBy('id')->get()->all(), 'bookings' => Booking::orderBy('id')->get()->map->getAttributes()->all()];

    $report = app(\App\Services\CapacityReconciliationService::class)->report();
    $byDate = collect($report['dates'])->keyBy('date');

    expect($byDate['2030-03-10'])->toMatchArray(['stored' => 3, 'expected' => 1, 'difference' => 2, 'status' => 'stored_too_high']);
    expect($byDate['2030-03-11'])->toMatchArray(['stored' => 1, 'expected' => 0, 'difference' => 1, 'status' => 'stored_too_high', 'accepted_then_ended' => 1]);
    expect($byDate['2030-03-12'])->toMatchArray(['stored' => null, 'expected' => 1, 'status' => 'missing_row']);
    expect($byDate['2030-03-13'])->toMatchArray(['stored' => 0, 'expected' => 0, 'status' => 'ok']);
    expect($byDate['2030-03-14'])->toMatchArray(['stored' => -1, 'status' => 'negative']);
    expect($report['summary'])->toMatchArray(['dates_checked' => 5, 'ok' => 1, 'stored_too_high' => 2, 'negative' => 1, 'missing_row' => 1]);

    $this->artisan('towmate:capacity-report', ['--only-differences' => true])->assertExitCode(0);

    expect(['capacity' => DB::table('booking_capacity')->orderBy('id')->get()->all(), 'bookings' => Booking::orderBy('id')->get()->map->getAttributes()->all()])->toEqual($before);
});
