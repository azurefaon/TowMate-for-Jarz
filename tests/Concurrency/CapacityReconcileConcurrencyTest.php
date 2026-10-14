<?php

/*
 * The reconciliation must never run alongside booking writes. These tests use
 * separate PHP processes and Postgres connections:
 *  - a writer already in flight makes the apply refuse (it cannot prove quiescence);
 *  - while an apply is running, new booking writes (inserts included) cannot
 *    get in, and the ones queued behind it then see the corrected data;
 *  - two applies at once never do the work twice.
 * The apply is held mid-transaction with the cc_pause trigger (it pauses on its
 * audit_logs insert, after the data is verified and before it commits).
 */

use App\Models\AuditLog;
use App\Models\Booking;
use App\Services\CapacityReconciliationService;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Support.php';

uses(Tests\TestCase::class, DatabaseTruncation::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrency tests need PostgreSQL.');
    }

    ccInstallPauseTrigger();
});

afterEach(function () {
    if (DB::getDriverName() === 'pgsql') {
        ccDropPauseTrigger();
    }
});

/** Pre-migration style data: accepted bookings with no marker and drifted counters. */
function rcnLegacy(): array
{
    [$user, $customer] = ccCustomer();
    $first = ccScheduledBooking($customer, ['status' => 'scheduled_confirmed', 'price_locked_at' => now()->subDay()]);
    $second = ccScheduledBooking($customer, ['status' => 'scheduled_confirmed', 'price_locked_at' => now()->subDay(), 'scheduled_date' => '2030-03-11']);
    DB::table('booking_capacity')->insert([
        ['booking_date' => '2030-03-10', 'slots_used' => 5, 'slots_max' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['booking_date' => '2030-03-20', 'slots_used' => 2, 'slots_max' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return [$user, $customer, $first, $second];
}

function rcnFingerprint(): string
{
    Artisan::call('towmate:capacity-reconcile', ['--json' => true]);

    return json_decode(Artisan::output(), true)['fingerprint'];
}

function rcnApply(string $fingerprint, string $app = 'cc_worker', int $lockTimeoutMs = 10000): \Symfony\Component\Process\Process
{
    return ccStartArtisan('towmate:capacity-reconcile', ['--apply' => true, '--confirm-plan' => $fingerprint, '--no-interaction' => true, '--lock-timeout' => $lockTimeoutMs], $app);
}

function rcnState(): array
{
    return [
        'markers' => DB::table('bookings')->orderBy('id')->pluck('capacity_reserved_date', 'id')->all(),
        'counters' => DB::table('booking_capacity')->orderBy('booking_date')->pluck('slots_used', 'booking_date')->all(),
        'audits' => AuditLog::where('action', 'capacity_reconciled')->count(),
    ];
}

/* ---------- A writer already in flight: refuse, change nothing ---------- */

it('refuses to apply while any booking write is in flight, whatever kind it is', function (string $kind) {
    [, , $first] = rcnLegacy();
    $fingerprint = rcnFingerprint();
    $before = rcnState();

    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query(match ($kind) {
        'update' => "UPDATE bookings SET updated_at = updated_at WHERE id = {$first->id}",
        'row lock' => "SELECT id FROM bookings WHERE id = {$first->id} FOR UPDATE",
        'insert' => "INSERT INTO bookings (customer_id, truck_type_id, pickup_address, dropoff_address) SELECT customer_id, truck_type_id, 'x', 'y' FROM bookings LIMIT 1",
        'capacity write' => "UPDATE booking_capacity SET slots_used = slots_used WHERE booking_date = '2030-03-20'",
    });

    $result = ccResult(rcnApply($fingerprint, lockTimeoutMs: 300));
    $holder->rollBack();

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('cannot be proven quiesced')->toContain('Nothing was changed');
    expect(rcnState())->toEqual($before);
})->with(['update', 'row lock', 'insert', 'capacity write']);

/* ---------- While the apply runs ---------- */

it('blocks every booking write, inserts included, for as long as the apply holds its locks, then lets them through on corrected data', function () {
    [$user, , $first, $second] = rcnLegacy();
    // A third booking still waiting for acceptance, whose quotation a customer is about to accept.
    [$otherUser, $otherCustomer] = ccCustomer('Waiting Customer');
    $pending = ccScheduledBooking($otherCustomer, ['scheduled_date' => '2030-03-12']);
    $quotation = ccSentQuotation($pending);
    $fingerprint = rcnFingerprint();

    $pauser = ccHolder();
    $pauser->query('SELECT pg_advisory_lock(' . CC_PAUSE_KEY . ')');

    $apply = rcnApply($fingerprint, 'cc_pause');
    expect(ccAwaitWaiting(1))->toBeTrue('the apply should be paused inside its transaction, holding the locks');

    // 1. A brand-new booking cannot even be inserted.
    $writer = ccHolder();
    $writer->exec("SET lock_timeout = '300ms'");
    $blocked = null;
    try {
        $writer->exec("INSERT INTO bookings (customer_id, truck_type_id, pickup_address, dropoff_address) SELECT customer_id, truck_type_id, 'x', 'y' FROM bookings LIMIT 1");
    } catch (PDOException $e) {
        $blocked = $e->getCode();
    }
    expect($blocked)->toBe('55P03'); // lock_not_available

    // 2. A customer cancelling and a customer accepting both queue up behind it.
    $cancel = ccStart($user, 'POST', url("/api/v1/bookings/{$first->booking_code}/cancel"), ['reason' => 'Plans changed']);
    $accept = ccStart($otherUser, 'POST', url("/api/v1/quotations/{$quotation->id}/accept"));
    expect(ccAwaitWaiting(3))->toBeTrue('both requests should be waiting for the apply');
    expect(DB::table('bookings')->where('id', $first->id)->value('status'))->toBe('scheduled_confirmed'); // not slipped through

    $pauser->query('SELECT pg_advisory_unlock(' . CC_PAUSE_KEY . ')');

    $applyResult = ccResult($apply);
    $cancelResult = ccResult($cancel);
    $acceptResult = ccResult($accept);

    expect($applyResult['exit'])->toBe(0);
    expect($applyResult['output'])->toContain('Applied');
    ccNoServerError($cancelResult);
    ccNoServerError($acceptResult);
    expect($cancelResult['status'])->toBe(200, json_encode($cancelResult));
    expect($acceptResult['status'])->toBe(200, json_encode($acceptResult));

    // The queued requests ran on the corrected data: the cancel released the slot the apply had just marked,
    // and the accept reserved a new one. Every counter equals its markers again.
    expect(Booking::find($first->id)->capacity_reserved_date)->toBeNull();
    expect(Booking::find($second->id)->capacity_reserved_date->toDateString())->toBe('2030-03-11');
    expect(Booking::find($pending->id)->capacity_reserved_date->toDateString())->toBe('2030-03-12');
    $report = app(CapacityReconciliationService::class)->report();
    expect($report['summary'])->toMatchArray(['stored_too_high' => 0, 'stored_too_low' => 0, 'negative' => 0, 'missing_row' => 0, 'markers_to_set' => 0, 'markers_to_move' => 0, 'markers_to_clear' => 0, 'counters_to_change' => 0]);
    expect(collect($report['dates'])->firstWhere('date', '2030-03-10')['stored'])->toBe(0);
});

/* ---------- Two applies at once ---------- */

it('lets only one of two simultaneous applies do the work', function () {
    rcnLegacy();
    $fingerprint = rcnFingerprint();

    $pauser = ccHolder();
    $pauser->query('SELECT pg_advisory_lock(' . CC_PAUSE_KEY . ')');
    $first = rcnApply($fingerprint, 'cc_pause');
    expect(ccAwaitWaiting(1))->toBeTrue();
    $second = rcnApply($fingerprint);
    expect(ccAwaitWaiting(2))->toBeTrue('the second apply should be waiting for the first one\'s locks');
    $pauser->query('SELECT pg_advisory_unlock(' . CC_PAUSE_KEY . ')');

    $results = [ccResult($first), ccResult($second)];

    expect($results[0]['exit'])->toBe(0);
    expect($results[1]['exit'])->toBe(1);
    expect($results[1]['output'])->toContain('differs from the dry run you confirmed'); // re-planned under the lock: nothing left to do
    expect(rcnState()['audits'])->toBe(1);
    expect(app(CapacityReconciliationService::class)->report()['summary'])->toMatchArray(['markers_to_set' => 0, 'counters_to_change' => 0]);
});
