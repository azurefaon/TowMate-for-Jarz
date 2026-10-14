<?php

/*
 * A Team Leader signing out while the owner or a dispatcher is moving the same
 * truck into maintenance. The sign-out is one conditional UPDATE, so whichever
 * order the two commit in, the truck ends up in maintenance and the sign-out
 * never takes it back to "available".
 */

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

it('does not undo a maintenance toggle that commits while the leader\'s sign-out is waiting for the truck', function () {
    $leader = ccAccount(3, 'Race', 'Leader', ['must_change_password' => false, 'last_ping_at' => now()]);
    $unit = ccUnit('RACE 1');
    $unit->update(['team_leader_id' => $leader->id, 'status' => 'available', 'dispatcher_note' => 'Depot']);

    // The toggle has written "maintenance" but not committed; the sign-out queues behind its row lock.
    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("UPDATE units SET status = 'maintenance', issue_note = 'Clutch' WHERE id = {$unit->id}");
    $signOut = ccStart($leader, 'POST', url('/api/v1/team-leader/presence/offline'));
    expect(ccAwaitWaiting(1))->toBeTrue('the sign-out should be waiting for the truck row');
    $holder->commit();

    $result = ccResult($signOut);

    ccNoServerError($result);
    expect($result['status'])->toBe(200, json_encode($result));
    $after = Unit::find($unit->id);
    expect($after->status)->toBe('maintenance');
    expect($after->issue_note)->toBe('Clutch');
    expect($after->dispatcher_note)->toBe('Depot'); // maintenance keeps its notes
    expect($after->team_leader_id)->toBeNull();
});

it('releases a truck as before when the toggle that was in flight put it back in service', function () {
    $leader = ccAccount(3, 'Race', 'Leader Two', ['must_change_password' => false, 'last_ping_at' => now()]);
    $unit = ccUnit('RACE 2');
    $unit->update(['team_leader_id' => $leader->id, 'status' => 'maintenance', 'dispatcher_note' => 'Depot']);

    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("UPDATE units SET status = 'available' WHERE id = {$unit->id}");   // back in service, uncommitted
    $signOut = ccStart($leader, 'POST', url('/api/v1/team-leader/presence/offline'));
    expect(ccAwaitWaiting(1))->toBeTrue();
    $holder->commit();

    ccResult($signOut);

    $after = Unit::find($unit->id);
    expect($after->status)->toBe('available');
    expect($after->dispatcher_note)->toBeNull(); // an in-service truck is cleared exactly as before
    expect($after->team_leader_id)->toBeNull();
});

/* ---------- A protected assignment cannot be removed by a concurrent sign-out ---------- */

/** Every open Team Leader loan names the leader the truck actually has. */
function tsLoansMatchTrucks(): void
{
    foreach (\App\Models\UnitCrewLoan::whereNull('returned_at')->where('to_slot', 'team_leader')->get() as $loan) {
        expect((int) Unit::find($loan->to_unit_id)->team_leader_id)->toBe((int) $loan->person_user_id, "open loan {$loan->id} does not match its truck");
    }
}

it('keeps the leader on a truck when a job or reservation commits while the sign-out is waiting for the truck', function (string $hold) {
    [, $customer] = ccCustomer();
    $leader = ccAccount(3, 'Race', 'Holder ' . $hold, ['must_change_password' => false, 'last_ping_at' => now()]);
    $unit = ccUnit('RACE H');
    $unit->update(['team_leader_id' => $leader->id, 'status' => 'available', 'dispatcher_note' => 'Depot']);
    $booking = ccScheduledBooking($customer, ['status' => 'requested', 'service_type' => 'book_now', 'scheduled_date' => null, 'scheduled_time' => null]);

    // A dispatcher transaction holds the truck and is about to commit a hold on it; the sign-out queues behind it.
    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("SELECT id FROM units WHERE id = {$unit->id} FOR UPDATE");
    $holder->query($hold === 'active job'
        ? "UPDATE bookings SET status = 'accepted', assigned_unit_id = {$unit->id}, assigned_team_leader_id = {$leader->id} WHERE id = {$booking->id}"
        : "UPDATE bookings SET status = 'scheduled_confirmed', selected_unit_id = {$unit->id} WHERE id = {$booking->id}");

    $signOut = ccStart($leader, 'POST', url('/api/v1/team-leader/presence/offline'));
    expect(ccAwaitWaiting(1))->toBeTrue('the sign-out should be waiting for the truck');
    $holder->commit();

    $result = ccResult($signOut);

    ccNoServerError($result);
    expect($result['status'])->toBe(200, json_encode($result));
    // The hold committed first, so the re-check under the lock sees it and the leader stays.
    expect(Unit::find($unit->id)->only(['team_leader_id', 'dispatcher_note']))->toBe(['team_leader_id' => $leader->id, 'dispatcher_note' => 'Depot']);
})->with(['active job', 'reservation']);

it('never leaves an open borrow record pointing at a truck with no leader when a dispatcher borrows the leader during the sign-out', function (string $first) {
    $dispatcher = ccDispatcher();
    $leader = ccAccount(3, 'Race', 'Borrowed ' . $first, ['must_change_password' => false, 'last_ping_at' => now()]);
    $home = ccUnit('RACE HOME');            // lower id: the truck the leader leads now
    $target = ccUnit('RACE TARGET');        // the truck the dispatcher lends them to
    $home->update(['team_leader_id' => $leader->id]);
    $leader->update(['home_unit_id' => $home->id]);

    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("SELECT id FROM units WHERE id = {$home->id} FOR UPDATE");

    $startSignOut = fn () => ccStart($leader, 'POST', url('/api/v1/team-leader/presence/offline'));
    $startBorrow = fn () => ccStart($dispatcher, 'POST', route('admin.drivers.units.assign-team-leader', $target), ['team_leader_id' => $leader->id]);

    if ($first === 'sign-out first') {
        $signOut = $startSignOut();
        expect(ccAwaitWaiting(1))->toBeTrue();
        $borrow = $startBorrow();
    } else {
        $borrow = $startBorrow();
        expect(ccAwaitWaiting(1))->toBeTrue();
        $signOut = $startSignOut();
    }
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $signOutResult = ccResult($signOut);
    $borrowResult = ccResult($borrow);

    ccNoServerError($signOutResult);
    ccNoServerError($borrowResult);
    expect($signOutResult['status'])->toBe(200, json_encode($signOutResult));
    expect(in_array($borrowResult['status'], [200, 409], true))->toBeTrue(json_encode($borrowResult));

    // Whichever won, the records agree: a borrow exists only if the leader is on the borrowing truck.
    tsLoansMatchTrucks();
    if ($first === 'sign-out first') {
        expect($borrowResult['status'])->toBe(409);            // the leader had already been released
        expect(Unit::find($home->id)->team_leader_id)->toBeNull();
        expect(\App\Models\UnitCrewLoan::count())->toBe(0);
    } else {
        expect($borrowResult['status'])->toBe(200);            // lent first, so the sign-out finds nothing to release
        expect(Unit::find($target->id)->team_leader_id)->toBe($leader->id);
        expect(\App\Models\UnitCrewLoan::whereNull('returned_at')->count())->toBe(1);
    }
})->with(['sign-out first', 'dispatcher first']);
