<?php

/*
 * Concurrency for the capacity reservation marker: simultaneous cancellations,
 * rescheduling against cancellation, and group acceptance against group
 * cancellation across the same dates. One PHP process and Postgres connection
 * per request, interleavings forced by a holder connection (see
 * ScheduledCapacityConcurrencyTest for the harness).
 */

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\User;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Support.php';

uses(Tests\TestCase::class, DatabaseTruncation::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrency tests need PostgreSQL.');
    }
});

function rcHold(string $table, string $where): PDO
{
    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("SELECT id FROM {$table} WHERE {$where} FOR UPDATE");

    return $holder;
}

function rcAccepted(\App\Models\Customer $customer, array $attrs = []): Booking
{
    $booking = ccScheduledBooking($customer, $attrs);
    app(QuotationService::class)->acceptQuotation(ccSentQuotation($booking));

    return $booking->fresh();
}

function rcCancel(User $user, Booking $booking): \Symfony\Component\Process\Process
{
    return ccStart($user, 'POST', url("/api/v1/bookings/{$booking->booking_code}/cancel"), ['reason' => 'Changed my mind']);
}

function rcMarker(Booking $booking): ?string
{
    return $booking->fresh()->capacity_reserved_date?->toDateString();
}

/* ---------- Repeated and simultaneous cancellation ---------- */

it('releases a slot exactly once when the same cancellation arrives twice at once', function () {
    [$user, $customer] = ccCustomer();
    $booking = rcAccepted($customer);

    $holder = rcHold('bookings', "id = {$booking->id}");
    $a = rcCancel($user, $booking);
    $b = rcCancel($user, $booking);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $results = [ccResult($a), ccResult($b)];
    foreach ($results as $result) {
        ccNoServerError($result);
    }

    expect(collect($results)->pluck('status')->sort()->values()->all())->toBe([200, 422]);
    expect(ccSlots('2030-03-10'))->toBe(0); // one slot given back, not two
    expect(rcMarker($booking))->toBeNull();
    expect($booking->fresh()->status)->toBe('cancelled');
});

it('gives back each booking\'s own slot when two bookings on one date are cancelled together', function () {
    [$user, $customer] = ccCustomer();
    $first = rcAccepted($customer);
    $second = rcAccepted($customer);
    expect(ccSlots('2030-03-10'))->toBe(2);

    $capacityRow = DB::table('booking_capacity')->where('booking_date', '2030-03-10')->value('id');
    $holder = rcHold('booking_capacity', "id = {$capacityRow}");
    $a = rcCancel($user, $first);
    $b = rcCancel($user, $second);
    expect(ccAwaitWaiting(2))->toBeTrue('both cancellations should queue on the capacity row');
    $holder->commit();

    foreach ([ccResult($a), ccResult($b)] as $result) {
        ccNoServerError($result);
        expect($result['status'])->toBe(200, json_encode($result));
    }

    expect(ccSlots('2030-03-10'))->toBe(0);
    expect([rcMarker($first), rcMarker($second)])->toBe([null, null]);
});

/* ---------- Reschedule against cancellation ---------- */

it('ends with no slot anywhere when a reschedule and a cancellation race, whichever goes first', function () {
    [$user, $customer] = ccCustomer();
    $dispatcher = ccDispatcher();
    $booking = rcAccepted($customer);

    $holder = rcHold('bookings', "id = {$booking->id}");
    $reschedule = ccStart($dispatcher, 'POST', route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-04-05', 'new_scheduled_time' => '10:00']);
    $cancel = rcCancel($user, $booking);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $rescheduleResult = ccResult($reschedule);
    $cancelResult = ccResult($cancel);

    ccNoServerError($rescheduleResult);
    ccNoServerError($cancelResult);
    expect($cancelResult['status'])->toBe(200, json_encode($cancelResult));
    expect(in_array($rescheduleResult['status'], [200, 422], true))->toBeTrue(json_encode($rescheduleResult));

    // Either the slot moved and was then released from the new date, or the cancellation
    // won and the reschedule was refused. Both leave nothing counted and nothing negative.
    expect(ccSlots('2030-03-10') ?? 0)->toBe(0);
    expect(ccSlots('2030-04-05') ?? 0)->toBe(0);
    expect(rcMarker($booking))->toBeNull();
    expect($booking->fresh()->status)->toBe('cancelled');
});

it('keeps exactly one slot, on the booking\'s final date, when two reschedules race', function () {
    [, $customer] = ccCustomer();
    $booking = rcAccepted($customer);

    $holder = rcHold('bookings', "id = {$booking->id}");
    $toApril = ccStart(ccDispatcher(), 'POST', route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-04-05', 'new_scheduled_time' => '10:00']);
    $toMay = ccStart(ccDispatcher(), 'POST', route('admin.booking.reschedule', $booking), ['new_scheduled_date' => '2030-05-06', 'new_scheduled_time' => '11:00']);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    foreach ([ccResult($toApril), ccResult($toMay)] as $result) {
        ccNoServerError($result);
        expect($result['status'])->toBe(200, json_encode($result));
    }

    $final = $booking->fresh()->scheduled_date->toDateString();
    $counts = collect(['2030-03-10', '2030-04-05', '2030-05-06'])->mapWithKeys(fn ($date) => [$date => ccSlots($date) ?? 0]);

    expect($counts->sum())->toBe(1);
    expect($counts[$final])->toBe(1);
    expect(rcMarker($booking))->toBe($final);
});

/* ---------- Group acceptance against group cancellation, same dates ---------- */

it('does not deadlock when one group is accepted while another is cancelled across the same two dates', function () {
    [$user, $customer] = ccCustomer();

    // Group B is already accepted: one slot on each of two dates.
    $b1 = ccScheduledBooking($customer, ['group_code' => 'GRP-CB']);
    $b2 = ccScheduledBooking($customer, ['group_code' => 'GRP-CB', 'scheduled_date' => '2030-03-11']);
    app(QuotationService::class)->acceptQuotation(ccSentQuotation($b1, [$b2]));

    // Group A is waiting to be accepted, covering the same two dates.
    $a1 = ccScheduledBooking($customer, ['group_code' => 'GRP-CA']);
    $a2 = ccScheduledBooking($customer, ['group_code' => 'GRP-CA', 'scheduled_date' => '2030-03-11']);
    $quotationA = ccSentQuotation($a1, [$a2]);

    $firstDate = DB::table('booking_capacity')->where('booking_date', '2030-03-10')->value('id');
    $holder = rcHold('booking_capacity', "id = {$firstDate}");
    $accept = ccStart($user, 'POST', url("/api/v1/quotations/{$quotationA->id}/accept"));
    $cancel = ccStart($user, 'POST', url('/api/v1/bookings/group/GRP-CB/cancel'), ['booking_codes' => [$b1->booking_code, $b2->booking_code], 'reason' => 'Swapping']);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $acceptResult = ccResult($accept);
    $cancelResult = ccResult($cancel);
    ccNoServerError($acceptResult);
    ccNoServerError($cancelResult);
    expect($acceptResult['status'])->toBe(200, json_encode($acceptResult));
    expect($cancelResult['status'])->toBe(200, json_encode($cancelResult));

    // B gave its two slots back, A took two.
    expect([ccSlots('2030-03-10'), ccSlots('2030-03-11')])->toBe([1, 1]);
    expect([rcMarker($a1), rcMarker($a2), rcMarker($b1), rcMarker($b2)])->toBe(['2030-03-10', '2030-03-11', null, null]);
});

/* ---------- Audit ---------- */

it('records one cancellation per booking, not one per request, when the cancel is doubled', function () {
    [$user, $customer] = ccCustomer();
    $booking = rcAccepted($customer);

    $holder = rcHold('bookings', "id = {$booking->id}");
    $a = rcCancel($user, $booking);
    $b = rcCancel($user, $booking);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();
    ccResult($a);
    ccResult($b);

    expect(AuditLog::where('action', 'booking_cancelled_by_customer')->where('entity_id', $booking->id)->count())->toBe(1);
});
