<?php

/*
 * Genuinely concurrent tests for dispatcher team changes (Units & Leaders)
 * against each other and against owner home-unit writes. Same harness as
 * HomeAssignmentConcurrencyTest: one PHP process and Postgres connection per
 * request, interleavings forced with a holder lock or the cc_pause trigger.
 *
 * Each "crossing" test lines the sessions up so that the lock order used
 * before this fix (target/source, or borrow record first) forms a deadlock
 * cycle as soon as the holder lets go. With every writer locking units in
 * ascending id order first, the same line-up just runs one after the other.
 */

use App\Exceptions\TeamAssignmentConflictException;
use App\Models\AuditLog;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use Illuminate\Foundation\Testing\DatabaseTruncation;
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

/** Holds one unit's row lock on a separate connection until released. */
function cdHold(Unit $unit): PDO
{
    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("SELECT id FROM units WHERE id = {$unit->id} FOR UPDATE");

    return $holder;
}

/* ---------- Dispatcher vs owner ---------- */

// Before: the owner's edit locked JARZ 1 then JARZ 2 (id order) while
// assignTeamLeader locked its target JARZ 2, then the source JARZ 1.
it('does not deadlock when a dispatcher assigns a leader while the owner moves their home unit', function () {
    $owner = ccOwner();
    $dispatcher = ccDispatcher();
    $home = ccUnit('JARZ 1');
    $target = ccUnit('JARZ 2');
    $leader = ccAccount(3, 'Ramil', 'Navarro', ['home_unit_id' => $home->id]);
    $home->update(['team_leader_id' => $leader->id]);

    $holder = cdHold($home);
    $ownerEdit = ccStart($owner, 'PATCH', route('superadmin.personnel.home-unit', $leader), ['home_unit_id' => $target->id]);
    expect(ccAwaitWaiting(1))->toBeTrue();
    $assign = ccStart($dispatcher, 'POST', route('admin.drivers.units.assign-team-leader', $target), ['team_leader_id' => $leader->id]);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $ownerResult = ccResult($ownerEdit);
    $assignResult = ccResult($assign);
    ccNoServerError($ownerResult);
    ccNoServerError($assignResult);
    expect($ownerResult['status'])->not->toBe(409, json_encode($ownerResult));
    expect($assignResult['status'])->not->toBe(409, json_encode($assignResult));

    // Whichever ran first, the leader ends on JARZ 2 with it as home and no open loan.
    $leader->refresh();
    expect($leader->home_unit_id)->toBe($target->id);
    expect($target->fresh()->team_leader_id)->toBe($leader->id);
    expect($home->fresh()->team_leader_id)->toBeNull();
    expect(UnitCrewLoan::whereNull('returned_at')->count())->toBe(0);
    ccAssertTeamIntegrity();
});

// Before: the batch locked JARZ 1 then JARZ 2 (id order) while transferTeam
// locked its source JARZ 2 first, then the target JARZ 1.
it('does not deadlock when a dispatcher transfers a team while the owner saves a home team', function () {
    $owner = ccOwner();
    $dispatcher = ccDispatcher();
    $unitA = ccUnit('JARZ 1');
    $unitB = ccUnit('JARZ 2');
    $leader = ccAccount(3, 'Ramil', 'Navarro', ['home_unit_id' => $unitB->id]);
    $crew = ccRecord('Rico', 'Santos', ['home_unit_id' => $unitB->id]);
    $unitB->update(['team_leader_id' => $leader->id]);
    ccSeat($unitB, 'crew_member_1', $crew);
    $request = ccBatch($unitA, ["record-{$crew->id}"]);

    $holder = cdHold($unitA);
    $batch = ccStart($owner, 'PUT', route('superadmin.home-assignments.update', $unitA->id), $request);
    expect(ccAwaitWaiting(1))->toBeTrue();
    $transfer = ccStart($dispatcher, 'POST', route('admin.drivers.units.transfer-team', $unitB), ['target_unit_id' => $unitA->id]);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $batchResult = ccResult($batch);
    $transferResult = ccResult($transfer);
    ccNoServerError($batchResult);
    ccNoServerError($transferResult);
    expect($batchResult['status'])->toBe(200, json_encode($batchResult));
    expect($transferResult['status'])->toBe(200, json_encode($transferResult));

    expect($crew->fresh()->home_unit_id)->toBe($unitA->id);
    $unitA->refresh();
    expect($unitA->team_leader_id)->toBe($leader->id);
    expect($unitA->crew_member_1_personnel_id)->toBe($crew->id);
    expect($unitB->fresh()->team_leader_id)->toBeNull();
    expect(UnitCrewLoan::whereNull('returned_at')->count())->toBe(2);
    ccAssertTeamIntegrity();
});

/* ---------- Dispatcher vs dispatcher ---------- */

// Before: each assignSlotPerson locked its own target first, then its source.
it('does not deadlock when two dispatchers move crew across the same two units in opposite directions', function () {
    $dispatcher = ccDispatcher();
    $unitA = ccUnit('JARZ 1');
    $unitB = ccUnit('JARZ 2');
    $onA = ccRecord('Crew', 'OnA');
    $onB = ccRecord('Crew', 'OnB');
    ccSeat($unitA, 'crew_member_1', $onA);
    ccSeat($unitB, 'crew_member_1', $onB);

    $holder = cdHold($unitA);
    $toA = ccStart($dispatcher, 'POST', route('admin.drivers.units.assign-slot', $unitA), ['to_slot' => 'crew_member_2', 'source_unit_id' => $unitB->id, 'from_slot' => 'crew_member_1']);
    expect(ccAwaitWaiting(1))->toBeTrue();
    $toB = ccStart($dispatcher, 'POST', route('admin.drivers.units.assign-slot', $unitB), ['to_slot' => 'crew_member_2', 'source_unit_id' => $unitA->id, 'from_slot' => 'crew_member_1']);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    foreach ([ccResult($toA), ccResult($toB)] as $result) {
        ccNoServerError($result);
        expect($result['status'])->toBe(200, json_encode($result));
    }

    $unitA->refresh();
    $unitB->refresh();
    expect($unitA->crew_member_2_personnel_id)->toBe($onB->id);
    expect($unitB->crew_member_2_personnel_id)->toBe($onA->id);
    expect($unitA->crew_member_1_personnel_id)->toBeNull();
    expect($unitB->crew_member_1_personnel_id)->toBeNull();
    expect(UnitCrewLoan::whereNull('returned_at')->count())->toBe(2);
    ccAssertTeamIntegrity();
});

// Before: returnSlotPerson locked the loan, its to-unit (JARZ 2) and then its
// from-unit (JARZ 1); assignSlotPerson locked its target JARZ 1, then JARZ 2.
it('does not deadlock when a crew return and a reassignment cross the same two units', function () {
    $dispatcher = ccDispatcher();
    $home = ccUnit('JARZ 1');
    $borrower = ccUnit('JARZ 2');
    $borrowed = ccRecord('Crew', 'Borrowed');
    $native = ccRecord('Crew', 'Native');
    ccSeat($borrower, 'crew_member_1', $borrowed);
    ccSeat($borrower, 'crew_member_2', $native);
    $loan = ccLoan($home, 'crew_member_1', $borrower, 'crew_member_1', $borrowed);

    $holder = cdHold($borrower);
    $return = ccStart($dispatcher, 'POST', route('admin.drivers.loans.return', $loan));
    expect(ccAwaitWaiting(1))->toBeTrue();
    $reassign = ccStart($dispatcher, 'POST', route('admin.drivers.units.assign-slot', $home), ['to_slot' => 'crew_member_2', 'source_unit_id' => $borrower->id, 'from_slot' => 'crew_member_2']);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    foreach ([ccResult($return), ccResult($reassign)] as $result) {
        ccNoServerError($result);
        expect($result['status'])->toBe(200, json_encode($result));
    }

    $home->refresh();
    expect($home->crew_member_1_personnel_id)->toBe($borrowed->id);
    expect($home->crew_member_2_personnel_id)->toBe($native->id);
    expect($loan->fresh()->returned_at)->not->toBeNull();
    ccAssertTeamIntegrity();
});

it('reassigns a returned person from fresh data, not from what the dispatcher saw', function () {
    $dispatcher = ccDispatcher();
    $home = ccUnit('JARZ 1');
    $borrower = ccUnit('JARZ 2');
    $other = ccUnit('JARZ 3');
    $borrowed = ccRecord('Crew', 'Borrowed');
    ccSeat($borrower, 'crew_member_1', $borrowed);
    $loan = ccLoan($home, 'crew_member_1', $borrower, 'crew_member_1', $borrowed);

    // The return queues first on JARZ 2; the reassignment of the same person queues behind it.
    $holder = cdHold($borrower);
    $return = ccStart($dispatcher, 'POST', route('admin.drivers.loans.return', $loan));
    expect(ccAwaitWaiting(1))->toBeTrue();
    $reassign = ccStart($dispatcher, 'POST', route('admin.drivers.units.assign-slot', $other), ['to_slot' => 'crew_member_1', 'source_unit_id' => $borrower->id, 'from_slot' => 'crew_member_1']);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $returnResult = ccResult($return);
    $reassignResult = ccResult($reassign);
    ccNoServerError($returnResult);
    ccNoServerError($reassignResult);
    expect($returnResult['status'])->toBe(200);
    expect($reassignResult['status'])->toBe(422);
    expect($reassignResult['body']['message'])->toBe('The selected source slot is empty.');

    expect($home->fresh()->crew_member_1_personnel_id)->toBe($borrowed->id);
    expect($other->fresh()->crew_member_1_personnel_id)->toBeNull();
    expect(UnitCrewLoan::whereNull('returned_at')->count())->toBe(0);
    ccAssertTeamIntegrity();
});

/* ---------- Stale state ---------- */

it('returns 409 when the leader moves to another unit while a dispatcher assignment waits', function () {
    $owner = ccOwner();
    $dispatcher = ccDispatcher();
    $home = ccUnit('JARZ 1');
    $target = ccUnit('JARZ 2');
    $newHome = ccUnit('JARZ 3');
    $leader = ccAccount(3, 'Ramil', 'Navarro', ['home_unit_id' => $home->id]);
    $home->update(['team_leader_id' => $leader->id]);

    $holder = ccHolder();
    $holder->query('SELECT pg_advisory_lock(' . CC_PAUSE_KEY . ')');

    // The owner's home-unit change moves the leader to JARZ 3 and pauses before committing.
    $ownerEdit = ccStart($owner, 'PATCH', route('superadmin.personnel.home-unit', $leader), ['home_unit_id' => $newHome->id], true, 'cc_pause');
    expect(ccAwaitWaiting(1))->toBeTrue();
    $assign = ccStart($dispatcher, 'POST', route('admin.drivers.units.assign-team-leader', $target), ['team_leader_id' => $leader->id]);
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->query('SELECT pg_advisory_unlock(' . CC_PAUSE_KEY . ')');

    $ownerResult = ccResult($ownerEdit);
    $assignResult = ccResult($assign);
    ccNoServerError($assignResult);
    expect($ownerResult['status'])->toBe(302, json_encode($ownerResult));
    expect($assignResult['status'])->toBe(409, json_encode($assignResult));
    expect($assignResult['body']['message'])->toBe(TeamAssignmentConflictException::DISPATCH_MESSAGE);

    expect($newHome->fresh()->team_leader_id)->toBe($leader->id);
    expect($target->fresh()->team_leader_id)->toBeNull();
    expect(UnitCrewLoan::count())->toBe(0);
    expect(AuditLog::where('action', 'team_leader_assigned')->exists())->toBeFalse();
    ccAssertTeamIntegrity();
});

it('refuses to return a leader whose loan the owner closed while the return waited', function () {
    $owner = ccOwner();
    $dispatcher = ccDispatcher();
    $home = ccUnit('JARZ 1');
    $borrower = ccUnit('JARZ 2');
    $leader = ccAccount(3, 'Ramil', 'Navarro', ['home_unit_id' => $home->id]);
    $borrower->update(['team_leader_id' => $leader->id]);
    $loan = ccLoan($home, 'team_leader', $borrower, 'team_leader', $leader);

    $holder = ccHolder();
    $holder->query('SELECT pg_advisory_lock(' . CC_PAUSE_KEY . ')');

    // The owner makes the borrowed truck the leader's home (closing the loan) and pauses.
    $ownerEdit = ccStart($owner, 'PATCH', route('superadmin.personnel.home-unit', $leader), ['home_unit_id' => $borrower->id], true, 'cc_pause');
    expect(ccAwaitWaiting(1))->toBeTrue();
    $return = ccStart($dispatcher, 'POST', route('admin.drivers.units.return-team-leader', $borrower));
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->query('SELECT pg_advisory_unlock(' . CC_PAUSE_KEY . ')');

    $ownerResult = ccResult($ownerEdit);
    $returnResult = ccResult($return);
    ccNoServerError($returnResult);
    expect($ownerResult['status'])->toBe(302, json_encode($ownerResult));
    expect($returnResult['status'])->toBe(422, json_encode($returnResult));
    expect($returnResult['body']['message'])->toBe('This Team Leader is not a borrowed assignment.');

    expect($leader->fresh()->home_unit_id)->toBe($borrower->id);
    expect($borrower->fresh()->team_leader_id)->toBe($leader->id);
    expect($home->fresh()->team_leader_id)->toBeNull();
    expect($loan->fresh()->returned_at)->not->toBeNull();
    ccAssertTeamIntegrity();
});

/* ---------- Lock failures: a clear 409, nothing saved ---------- */

it('answers a dispatcher lock timeout with a 409 and no raw SQL, and saves nothing', function (string $action) {
    $dispatcher = ccDispatcher();
    $unitA = ccUnit('JARZ 1');
    $unitB = ccUnit('JARZ 2');
    $leader = ccAccount(3, 'Ramil', 'Navarro', ['home_unit_id' => $unitA->id]);
    $crew = ccRecord('Rico', 'Santos');
    $unitA->update(['team_leader_id' => $leader->id]);

    [$uri, $data, $hold] = match ($action) {
        'assign team leader' => [route('admin.drivers.units.assign-team-leader', $unitB), ['team_leader_id' => $leader->id], $unitA],
        'transfer team' => [route('admin.drivers.units.transfer-team', $unitA), ['target_unit_id' => $unitB->id], $unitB],
        'assign crew' => (function () use ($unitA, $unitB, $crew) {
            ccSeat($unitA, 'crew_member_1', $crew);

            return [route('admin.drivers.units.assign-slot', $unitB), ['to_slot' => 'crew_member_1', 'source_unit_id' => $unitA->id, 'from_slot' => 'crew_member_1'], $unitA];
        })(),
        'return crew' => (function () use ($unitA, $unitB, $crew) {
            ccSeat($unitB, 'crew_member_1', $crew);

            return [route('admin.drivers.loans.return', ccLoan($unitA, 'crew_member_1', $unitB, 'crew_member_1', $crew)), [], $unitB];
        })(),
        'return team leader' => (function () use ($unitA, $unitB, $leader) {
            $unitA->update(['team_leader_id' => null]);
            $unitB->update(['team_leader_id' => $leader->id]);

            return [route('admin.drivers.units.return-team-leader', $unitB), [], $unitA];
        })(),
        'remove team leader' => [route('admin.drivers.units.remove-team-leader', $unitA), [], $unitA],
    };
    if ($action === 'return team leader') {
        ccLoan($unitA, 'team_leader', $unitB, 'team_leader', $leader);
    }

    $before = ccSnapshot();
    $holder = cdHold($hold);
    $result = ccResult(ccStart($dispatcher, 'POST', $uri, $data, true, 'cc_worker', '-c lock_timeout=700'));
    $holder->commit();

    ccNoServerError($result);
    expect($result['status'])->toBe(409, json_encode($result));
    expect($result['body'])->toBe(['success' => false, 'message' => TeamAssignmentConflictException::DISPATCH_MESSAGE]);
    expect(ccSnapshot())->toBe($before);
})->with(['assign team leader', 'transfer team', 'assign crew', 'return crew', 'return team leader', 'remove team leader']);

/* ---------- Rollback and audit ---------- */

it('writes no audit rows for a transfer refused after it had started moving the team', function () {
    $dispatcher = ccDispatcher();
    $source = ccUnit('JARZ 1');
    $target = ccUnit('JARZ 2');
    $leader = ccAccount(3, 'Ramil', 'Navarro', ['home_unit_id' => $source->id]);
    // The leader moves first; the unregistered crew name is refused afterwards.
    $source->update(['team_leader_id' => $leader->id, 'crew_member_1_name' => 'Unregistered Helper']);

    $before = ccSnapshot();
    $result = ccResult(ccStart($dispatcher, 'POST', route('admin.drivers.units.transfer-team', $source), ['target_unit_id' => $target->id]));

    ccNoServerError($result);
    expect($result['status'])->toBe(422, json_encode($result));
    expect($result['body']['message'])->toContain('Unregistered Helper is not a registered Personnel record');
    expect(ccSnapshot())->toBe($before);
});

/* ---------- Unscripted mix ---------- */

it('keeps teams and borrow records consistent when dispatcher and owner changes race', function () {
    $owner = ccOwner();
    $dispatcher = ccDispatcher();
    $units = collect(range(1, 3))->map(fn ($n) => ccUnit("JARZ $n"));
    $leaders = $units->map(fn (Unit $unit, $i) => tap(ccAccount(3, 'Leader', 'No' . $i, ['home_unit_id' => $unit->id]), fn ($leader) => $unit->update(['team_leader_id' => $leader->id])));
    $crew = $units->map(fn (Unit $unit, $i) => tap(ccRecord('Crew', 'No' . $i, ['home_unit_id' => $unit->id]), fn ($person) => ccSeat($unit, 'crew_member_1', $person)));

    for ($round = 0; $round < 4; $round++) {
        [$a, $b, $c] = [$round % 3, ($round + 1) % 3, ($round + 2) % 3];
        $openLoan = UnitCrewLoan::whereNull('returned_at')->where('to_slot', '<>', 'team_leader')->first();

        $processes = [
            ccStart($dispatcher, 'POST', route('admin.drivers.units.assign-slot', $units[$a]), ['to_slot' => 'crew_member_2', 'source_unit_id' => $units[$b]->id, 'from_slot' => 'crew_member_1']),
            ccStart($dispatcher, 'POST', route('admin.drivers.units.assign-slot', $units[$b]), ['to_slot' => 'crew_member_2', 'source_unit_id' => $units[$c]->id, 'from_slot' => 'crew_member_1']),
            ccStart($dispatcher, 'POST', route('admin.drivers.units.assign-team-leader', $units[$c]), ['team_leader_id' => $leaders[$a]->id]),
            ccStart($owner, 'PATCH', route('superadmin.personnel.home-unit', $leaders[$b]), ['home_unit_id' => $units[$a]->id]),
            ccStart($owner, 'PUT', route('superadmin.home-assignments.update', $units[$c]->id), ccBatch($units[$c], ["record-{$crew[$a]->id}"])),
        ];
        if ($openLoan) {
            $processes[] = ccStart($dispatcher, 'POST', route('admin.drivers.loans.return', $openLoan));
        }

        foreach ($processes as $process) {
            $result = ccResult($process);
            ccNoServerError($result);
            expect(in_array($result['status'], [200, 302, 409, 422], true))->toBeTrue(json_encode($result));
        }

        ccAssertTeamIntegrity();
    }
});
