<?php

/*
 * Concurrency for scheduled-booking capacity: expiration, acceptance and
 * customer cancellation racing on the same bookings. Same harness as the other
 * tests in this folder: one PHP process and Postgres connection per request or
 * command, interleavings forced by a holder connection that keeps a row lock
 * until the test has seen every worker queue up behind it.
 */

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Support.php';

uses(Tests\TestCase::class, DatabaseTruncation::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrency tests need PostgreSQL.');
    }
});

/** Holds one row's lock on a separate connection until commit(). */
function csHold(string $table, int $id): PDO
{
    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("SELECT id FROM {$table} WHERE id = {$id} FOR UPDATE");

    return $holder;
}

function csCapacity(string $date, int $used): void
{
    DB::table('booking_capacity')->insert(['booking_date' => $date, 'slots_used' => $used, 'slots_max' => 2, 'created_at' => now(), 'updated_at' => now()]);
}

function csExpiredBookings(int $count): array
{
    [, $customer] = ccCustomer('Expiring Customer');

    return array_map(fn () => ccScheduledBooking($customer, ['scheduled_expires_at' => now()->subHour()]), range(1, $count));
}

function csCancelledCount(array $results): int
{
    return array_sum(array_map(fn ($result) => preg_match('/Cancelled (\d+) expired/', $result['output'], $m) ? (int) $m[1] : 0, $results));
}

/* ---------- Concurrent expiration ---------- */

it('cancels each expired booking once and releases nothing when two expiration commands run together', function () {
    [$first, $second] = csExpiredBookings(2);
    csCapacity('2030-03-10', 1);

    $holder = csHold('bookings', $first->id);
    $one = ccStartArtisan('bookings:expire-scheduled');
    $two = ccStartArtisan('bookings:expire-scheduled');
    expect(ccAwaitWaiting(2))->toBeTrue('both commands should be queued behind the lock');
    $holder->commit();

    $results = [ccResult($one), ccResult($two)];

    expect(array_column($results, 'exit'))->toBe([0, 0]);
    expect(csCancelledCount($results))->toBe(2); // not 4: no booking is cancelled by both
    expect(Booking::whereIn('id', [$first->id, $second->id])->pluck('status')->unique()->all())->toBe(['cancelled']);
    expect(ccSlots('2030-03-10'))->toBe(1); // another booking's slot, untouched
    expect(AuditLog::where('action', 'like', '%cancel%')->count())->toBe(0);
});

it('never cancels a booking that was accepted while the expiration command waited', function () {
    [$user, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer, ['scheduled_expires_at' => now()->subHour()]); // past its window, quotation flag not set
    $quotation = ccSentQuotation($booking);

    $holder = csHold('bookings', $booking->id);
    $accept = ccStart($user, 'POST', url("/api/v1/quotations/{$quotation->id}/accept"));
    expect(ccAwaitWaiting(1))->toBeTrue();
    $expire = ccStartArtisan('bookings:expire-scheduled');
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $acceptResult = ccResult($accept);
    $expireResult = ccResult($expire);

    ccNoServerError($acceptResult);
    expect($acceptResult['status'])->toBe(200, json_encode($acceptResult));
    expect($expireResult['output'])->toBe('No expired scheduled bookings found.');
    expect($booking->fresh()->status)->toBe('scheduled_confirmed');
    expect(ccSlots('2030-03-10'))->toBe(1);
    ccAssertTeamIntegrity();
});

/* ---------- Concurrent acceptance ---------- */

it('reserves one slot when the same acceptance arrives twice at once', function () {
    [$user, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = ccSentQuotation($booking);

    $holder = csHold('bookings', $booking->id);
    $a = ccStart($user, 'POST', url("/api/v1/quotations/{$quotation->id}/accept"));
    $b = ccStart($user, 'POST', url("/api/v1/quotations/{$quotation->id}/accept"));
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $results = [ccResult($a), ccResult($b)];
    foreach ($results as $result) {
        ccNoServerError($result);
    }

    expect(collect($results)->pluck('status')->sort()->values()->all())->toBe([200, 422]);
    expect(collect($results)->firstWhere('status', 422)['body']['message'])->toBe('This quotation has already been processed.');
    expect(ccSlots('2030-03-10'))->toBe(1);
    expect($quotation->fresh()->status)->toBe('accepted');
});

it('reserves one slot per vehicle, not two, when a group acceptance is doubled', function () {
    [$user, $customer] = ccCustomer();
    $primary = ccScheduledBooking($customer);
    $sibling = ccScheduledBooking($customer, ['group_code' => 'GRP-CC1']);
    $quotation = ccSentQuotation($primary, [$sibling]);

    $holder = csHold('bookings', $primary->id);
    $a = ccStart($user, 'POST', url("/api/v1/quotations/{$quotation->id}/accept"));
    $b = ccStart($user, 'POST', url("/api/v1/quotations/{$quotation->id}/accept"));
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $results = [ccResult($a), ccResult($b)];
    foreach ($results as $result) {
        ccNoServerError($result);
    }

    expect(collect($results)->pluck('status')->sort()->values()->all())->toBe([200, 422]);
    expect(ccSlots('2030-03-10'))->toBe(2); // one per vehicle
    expect(Booking::where('status', 'scheduled_confirmed')->count())->toBe(2);
});

/* ---------- Acceptance versus cancellation ---------- */

// Before: acceptance locked the quotation and then the booking; cancellation locked the
// booking and then updated the quotation. Lined up as below, Postgres aborted one of them.
it('does not deadlock when a customer cancels while the quotation is being accepted', function () {
    [$user, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer);
    $quotation = ccSentQuotation($booking);

    $holder = csHold('quotations', $quotation->id);
    $accept = ccStart($user, 'POST', url("/api/v1/quotations/{$quotation->id}/accept"));
    expect(ccAwaitWaiting(1))->toBeTrue();
    $cancel = ccStart($user, 'POST', url("/api/v1/bookings/{$booking->booking_code}/cancel"), ['reason' => 'Changed my mind']);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $acceptResult = ccResult($accept);
    $cancelResult = ccResult($cancel);

    ccNoServerError($acceptResult);
    ccNoServerError($cancelResult);
    expect($acceptResult['status'])->toBe(200, json_encode($acceptResult));
    expect($cancelResult['status'])->toBe(200, json_encode($cancelResult));

    // Accepted first, then cancelled. Releasing the slot on cancellation is the separate, pending step,
    // so the counter may still show the slot; what matters here is that it is never negative or doubled.
    expect($booking->fresh()->status)->toBe('cancelled');
    expect($quotation->fresh()->status)->toBe('accepted');
    expect(ccSlots('2030-03-10'))->toBeIn([0, 1]);
    ccAssertTeamIntegrity();
});

/* ---------- Expiration versus cancellation ---------- */

it('lets either the customer cancellation or the expiration win, never both, and releases nothing', function () {
    [$user, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer, ['scheduled_expires_at' => now()->subHour()]);
    csCapacity('2030-03-10', 1);

    $holder = csHold('bookings', $booking->id);
    $cancel = ccStart($user, 'POST', url("/api/v1/bookings/{$booking->booking_code}/cancel"), ['reason' => 'No longer needed']);
    expect(ccAwaitWaiting(1))->toBeTrue();
    $expire = ccStartArtisan('bookings:expire-scheduled');
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $cancelResult = ccResult($cancel);
    $expireResult = ccResult($expire);

    ccNoServerError($cancelResult);
    expect($expireResult['exit'])->toBe(0);
    expect($booking->fresh()->status)->toBe('cancelled');
    expect(ccSlots('2030-03-10'))->toBe(1);

    $cancelledByCustomer = $cancelResult['status'] === 200;
    $cancelledByExpiry = str_contains($expireResult['output'], 'Cancelled 1');
    expect($cancelledByCustomer xor $cancelledByExpiry)->toBeTrue(json_encode([$cancelResult['status'], $expireResult['output']]));
});
