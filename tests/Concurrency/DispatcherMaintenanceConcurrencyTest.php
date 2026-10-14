<?php

/*
 * A booking taking the truck at the same moment a dispatcher marks it for
 * maintenance. The maintenance request locks the unit, then re-checks active
 * jobs and reservations under that lock, so whatever commits first wins and
 * the truck is never put into maintenance under a booking that holds it.
 * Separate PHP processes and Postgres connections.
 */

use App\Models\Booking;
use App\Models\Unit;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Support.php';

uses(Tests\TestCase::class, DatabaseTruncation::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrency tests need PostgreSQL.');
    }
});

it('refuses maintenance when a booking takes the truck while the request waits for the unit lock', function () {
    $dispatcher = ccDispatcher();
    $unit = ccUnit('Maintenance Race Truck');
    [, $customer] = ccCustomer();
    $booking = ccScheduledBooking($customer, ['status' => 'quoted']);

    // An "accept" in flight: it holds the unit row and has not committed yet.
    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("SELECT id FROM units WHERE id = {$unit->id} FOR UPDATE");

    $request = ccStart($dispatcher, 'POST', url("/admin-dashboard/units/{$unit->id}/maintenance"), ['reason' => 'Brake noise']);
    expect(ccAwaitWaiting(1))->toBeTrue('the maintenance request should be waiting for the unit lock');
    expect(Unit::find($unit->id)->status)->toBe('available');

    // The booking now reserves the truck and the "accept" commits.
    $holder->query("UPDATE bookings SET selected_unit_id = {$unit->id}, status = 'quoted' WHERE id = {$booking->id}");
    $holder->commit();

    $result = ccResult($request);

    ccNoServerError($result);
    expect($result['status'])->toBe(422, json_encode($result));
    expect(Unit::find($unit->id)->status)->toBe('available');
    expect(Unit::find($unit->id)->issue_note)->toBeNull();
    expect(Booking::find($booking->id)->selected_unit_id)->toBe($unit->id);
    expect(DB::table('audit_logs')->where('action', 'unit_marked_maintenance')->count())->toBe(0);
});

it('puts a free truck into maintenance once the competing transaction ends without taking it', function () {
    $dispatcher = ccDispatcher();
    $unit = ccUnit('Maintenance Free Truck');

    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("SELECT id FROM units WHERE id = {$unit->id} FOR UPDATE");

    $request = ccStart($dispatcher, 'POST', url("/admin-dashboard/units/{$unit->id}/maintenance"), ['reason' => 'Brake noise']);
    expect(ccAwaitWaiting(1))->toBeTrue();
    $holder->rollBack();

    $result = ccResult($request);

    ccNoServerError($result);
    expect($result['status'])->toBe(200, json_encode($result));
    expect(Unit::find($unit->id)->status)->toBe('maintenance');
    expect(DB::table('audit_logs')->where('action', 'unit_marked_maintenance')->count())->toBe(1);
});
