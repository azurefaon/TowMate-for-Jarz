<?php

/*
 * Genuinely concurrent tests for owner home-unit writes. Each request runs in
 * its own PHP process (tests/Concurrency/worker.php) with its own Postgres
 * connection, against committed data. Interleavings are forced, not timed:
 *
 *  - a holder connection keeps a row lock (SELECT ... FOR UPDATE) so workers
 *    queue up behind it in a known order, or
 *  - a test-only trigger pauses the worker named "cc_pause" on its first
 *    audit_logs insert (inside its transaction, holding its row locks) until
 *    the holder releases an advisory lock.
 *
 * pg_stat_activity tells the test when a worker is actually blocked.
 */

use App\Exceptions\HomeUnitConflictException;
use App\Models\AuditLog;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\PersonnelService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

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

/**
 * One single-person writer that puts someone into $unit's home team. Returns
 * [method, uri, data, json, key of the person, expected home before].
 */
function ccSingleWriter(string $kind, Unit $unit): array
{
    return match ($kind) {
        'record' => (function () use ($unit) {
            $record = ccRecord('Late', 'Record');

            return ['PATCH', route('superadmin.personnel-records.home-unit', $record), ['home_unit_id' => $unit->id], false, "record-{$record->id}"];
        })(),
        'driver account' => (function () use ($unit) {
            $driver = ccAccount(4, 'Late', 'Driver');

            return ['PATCH', route('superadmin.personnel.home-unit', $driver), ['home_unit_id' => $unit->id], false, "account-{$driver->id}"];
        })(),
        'team leader' => (function () use ($unit) {
            $leader = ccAccount(3, 'Late', 'Leader');

            return ['PATCH', route('superadmin.personnel.home-unit', $leader), ['home_unit_id' => $unit->id], false, "account-{$leader->id}"];
        })(),
        'new record' => ['POST', route('superadmin.personnel-records.store'), ['first_name' => 'Brand', 'last_name' => 'New', 'role' => 'crew', 'home_unit_id' => $unit->id], false, null],
    };
}

dataset('single writers', ['record', 'driver account', 'team leader', 'new record']);

/* ---------- Batch in flight, single-person write second ---------- */

it('makes a single-person write wait for an in-flight Home Team save of that unit', function (string $kind) {
    $owner = ccOwner();
    $unit = ccUnit('JARZ 1');
    $member = ccRecord('Rico', 'Santos', ['home_unit_id' => $unit->id]);
    $added = ccRecord('Spare', 'Crew');
    [$method, $uri, $data, $json] = ccSingleWriter($kind, $unit);

    $holder = ccHolder();
    $holder->query('SELECT pg_advisory_lock(' . CC_PAUSE_KEY . ')');

    // The batch adds Spare Crew, then pauses on its audit insert, inside its transaction.
    $batch = ccStart($owner, 'PUT', route('superadmin.home-assignments.update', $unit->id), ccBatch($unit, ["record-{$member->id}", "record-{$added->id}"]), true, 'cc_pause');
    expect(ccAwaitWaiting(1))->toBeTrue('batch never reached its pause');

    $single = ccStart($owner, $method, $uri, $data, $json);

    // The single write must queue behind the batch's lock on the unit, not commit underneath it.
    expect(ccAwaitWaiting(2))->toBeTrue('single write did not wait for the in-flight batch');
    usleep(300_000);
    expect($single->isRunning())->toBeTrue();
    expect(ccHome($unit))->toBe(collect(["record-{$member->id}"])->sort()->values()->all());

    $holder->query('SELECT pg_advisory_unlock(' . CC_PAUSE_KEY . ')');
    $batchResult = ccResult($batch);
    $singleResult = ccResult($single);

    ccNoServerError($batchResult);
    ccNoServerError($singleResult);
    expect($batchResult['status'])->toBe(200, json_encode($batchResult));
    expect($singleResult['errors'])->toBe([], json_encode($singleResult));

    // Everyone ends up on the team; the single write committed after the batch.
    expect(ccHome($unit))->toHaveCount(3);
    $batchAudit = AuditLog::where('action', 'personnel_home_unit_changed')->where('entity_id', $added->id)->value('id');
    $singleAudit = AuditLog::whereIn('action', ['personnel_home_unit_changed', 'personnel_created'])->where('id', '<>', $batchAudit)->value('id');
    expect($singleAudit)->toBeGreaterThan($batchAudit);
})->with('single writers');

/* ---------- Single-person write in flight, batch second ---------- */

it('refuses a Home Team save with 409 after a single-person write lands on that unit first', function (string $kind) {
    $owner = ccOwner();
    $unit = ccUnit('JARZ 1');
    $member = ccRecord('Rico', 'Santos', ['home_unit_id' => $unit->id]);
    $added = ccRecord('Spare', 'Crew');
    $request = ccBatch($unit, ["record-{$member->id}", "record-{$added->id}"]); // as the dialog saw it
    [$method, $uri, $data, $json] = ccSingleWriter($kind, $unit);

    $holder = ccHolder();
    $holder->query('SELECT pg_advisory_lock(' . CC_PAUSE_KEY . ')');

    // The single write pauses on its audit insert, inside its transaction.
    $single = ccStart($owner, $method, $uri, $data, $json, 'cc_pause');
    expect(ccAwaitWaiting(1))->toBeTrue('single write never reached its pause');

    $batch = ccStart($owner, 'PUT', route('superadmin.home-assignments.update', $unit->id), $request);
    expect(ccAwaitWaiting(2))->toBeTrue('batch did not wait for the in-flight single write');

    $holder->query('SELECT pg_advisory_unlock(' . CC_PAUSE_KEY . ')');
    $singleResult = ccResult($single);
    $batchResult = ccResult($batch);

    ccNoServerError($singleResult);
    ccNoServerError($batchResult);
    expect($singleResult['errors'])->toBe([], json_encode($singleResult));
    expect($batchResult['status'])->toBe(409, json_encode($batchResult));

    // Only the single write persisted; the refused batch left nothing behind.
    expect(ccHome($unit))->toHaveCount(2);
    expect($added->fresh()->home_unit_id)->toBeNull();
    expect(AuditLog::where('entity_type', 'Personnel')->where('entity_id', $added->id)->exists())->toBeFalse();
    expect(AuditLog::whereIn('action', ['personnel_home_unit_changed', 'personnel_created'])->count())->toBe(1);
})->with('single writers');

/* ---------- Same-unit lock inversion (the audited deadlock) ---------- */

// Before the fix: the batch locked the unit then the person; the team leader
// edit locked the person then the unit, and record / driver edits updated the
// person row and then took the foreign-key lock on the unit. Postgres aborted
// one side as a deadlock.
it('does not deadlock when a Home Team save and a home-unit edit put the same person on the same unit', function (string $kind) {
    $owner = ccOwner();
    $unit = ccUnit('JARZ 1');
    [$person, $uri, $request] = match ($kind) {
        'team leader' => (function () use ($unit) {
            $leader = ccAccount(3, 'Free', 'Leader');

            return [$leader, route('superadmin.personnel.home-unit', $leader), ccBatch($unit, [], ["account-{$leader->id}"])];
        })(),
        'driver account' => (function () use ($unit) {
            $driver = ccAccount(4, 'Free', 'Driver');

            return [$driver, route('superadmin.personnel.home-unit', $driver), ccBatch($unit, [], [], ["account-{$driver->id}"])];
        })(),
        'record' => (function () use ($unit) {
            $record = ccRecord('Free', 'Crew');

            return [$record, route('superadmin.personnel-records.home-unit', $record), ccBatch($unit, ["record-{$record->id}"])];
        })(),
    };

    // Hold the unit so both writers queue on it: the batch first, then the single edit.
    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("SELECT id FROM units WHERE id = {$unit->id} FOR UPDATE");

    $batch = ccStart($owner, 'PUT', route('superadmin.home-assignments.update', $unit->id), $request);
    expect(ccAwaitWaiting(1))->toBeTrue();
    $single = ccStart($owner, 'PATCH', $uri, ['home_unit_id' => $unit->id]);
    expect(ccAwaitWaiting(2))->toBeTrue();

    $holder->commit();
    $batchResult = ccResult($batch);
    $singleResult = ccResult($single);

    ccNoServerError($batchResult);
    ccNoServerError($singleResult);
    expect($batchResult['status'])->toBe(200, json_encode($batchResult));
    expect($singleResult['status'])->not->toBe(409, json_encode($singleResult));

    expect($person->fresh()->home_unit_id)->toBe($unit->id);
    if ($kind === 'team leader') {
        expect($unit->fresh()->team_leader_id)->toBe($person->id);
    }
    expect(AuditLog::where('action', 'personnel_home_unit_changed')->where('entity_id', $person->id)->count())->toBe(1);
})->with(['team leader', 'driver account', 'record']);

/* ---------- Stale single-person edits ---------- */

it('returns 409 when a team leader is moved by a Home Team save while their home-unit edit waits', function () {
    $owner = ccOwner();
    $unit = ccUnit('JARZ 1');
    $other = ccUnit('JARZ 2');
    $leader = ccAccount(3, 'Free', 'Leader');

    $holder = ccHolder();
    $holder->query('SELECT pg_advisory_lock(' . CC_PAUSE_KEY . ')');

    $batch = ccStart($owner, 'PUT', route('superadmin.home-assignments.update', $unit->id), ccBatch($unit, [], ["account-{$leader->id}"]), true, 'cc_pause');
    expect(ccAwaitWaiting(1))->toBeTrue();
    $single = ccStart($owner, 'PATCH', route('superadmin.personnel.home-unit', $leader), ['home_unit_id' => $other->id]);
    expect(ccAwaitWaiting(2))->toBeTrue();

    $holder->query('SELECT pg_advisory_unlock(' . CC_PAUSE_KEY . ')');
    $batchResult = ccResult($batch);
    $singleResult = ccResult($single);

    expect($batchResult['status'])->toBe(200, json_encode($batchResult));
    expect($singleResult['status'])->toBe(409, json_encode($singleResult));
    expect($singleResult['body']['message'])->toContain('changed somewhere else at the same moment');

    $leader->refresh();
    expect($leader->home_unit_id)->toBe($unit->id);
    expect($unit->fresh()->team_leader_id)->toBe($leader->id);
    expect($other->fresh()->team_leader_id)->toBeNull();
    expect(AuditLog::where('action', 'personnel_home_unit_changed')->where('entity_id', $leader->id)->count())->toBe(1);
});

it('returns 409 when a record is moved by a Home Team save while its home-unit edit waits', function () {
    $owner = ccOwner();
    $unit = ccUnit('JARZ 1');
    $old = ccUnit('JARZ 2');
    $third = ccUnit('JARZ 3');
    $record = ccRecord('Mark', 'Villena', ['home_unit_id' => $old->id]);

    $holder = ccHolder();
    $holder->query('SELECT pg_advisory_lock(' . CC_PAUSE_KEY . ')');

    $batch = ccStart($owner, 'PUT', route('superadmin.home-assignments.update', $unit->id), ccBatch($unit, ["record-{$record->id}"]), true, 'cc_pause');
    expect(ccAwaitWaiting(1))->toBeTrue();
    $single = ccStart($owner, 'PATCH', route('superadmin.personnel-records.home-unit', $record), ['home_unit_id' => $third->id]);
    expect(ccAwaitWaiting(2))->toBeTrue();

    $holder->query('SELECT pg_advisory_unlock(' . CC_PAUSE_KEY . ')');
    $batchResult = ccResult($batch);
    $singleResult = ccResult($single);

    expect($batchResult['status'])->toBe(200, json_encode($batchResult));
    expect($singleResult['status'])->toBe(409, json_encode($singleResult));
    expect($record->fresh()->home_unit_id)->toBe($unit->id);
    expect(AuditLog::where('action', 'personnel_home_unit_changed')->where('entity_id', $record->id)->count())->toBe(1);
});

it('sends form submissions back with the conflict message instead of a 409 page', function () {
    $owner = ccOwner();
    $unit = ccUnit('JARZ 1');
    $old = ccUnit('JARZ 2');
    $third = ccUnit('JARZ 3');
    $record = ccRecord('Mark', 'Villena', ['home_unit_id' => $old->id]);

    $holder = ccHolder();
    $holder->query('SELECT pg_advisory_lock(' . CC_PAUSE_KEY . ')');

    $batch = ccStart($owner, 'PUT', route('superadmin.home-assignments.update', $unit->id), ccBatch($unit, ["record-{$record->id}"]), true, 'cc_pause');
    expect(ccAwaitWaiting(1))->toBeTrue();
    $single = ccStart($owner, 'PATCH', route('superadmin.personnel-records.home-unit', $record), ['home_unit_id' => $third->id], false);
    expect(ccAwaitWaiting(2))->toBeTrue();

    $holder->query('SELECT pg_advisory_unlock(' . CC_PAUSE_KEY . ')');
    ccResult($batch);
    $singleResult = ccResult($single);

    expect($singleResult['status'])->toBe(302);
    expect($singleResult['errors'][0] ?? '')->toContain('changed somewhere else at the same moment');
    expect($record->fresh()->home_unit_id)->toBe($unit->id);
});

/* ---------- Lock failures ---------- */

it('answers a lock timeout with 409 and saves nothing, for single edits and the Home Team save', function (string $kind) {
    $owner = ccOwner();
    $unit = ccUnit('JARZ 1');
    $record = ccRecord('Rico', 'Santos');
    $leader = ccAccount(3, 'Free', 'Leader');
    [$method, $uri, $data] = match ($kind) {
        'record' => ['PATCH', route('superadmin.personnel-records.home-unit', $record), ['home_unit_id' => $unit->id]],
        'team leader' => ['PATCH', route('superadmin.personnel.home-unit', $leader), ['home_unit_id' => $unit->id]],
        'new record' => ['POST', route('superadmin.personnel-records.store'), ['first_name' => 'Brand', 'last_name' => 'New', 'role' => 'crew', 'home_unit_id' => $unit->id]],
        'home team' => ['PUT', route('superadmin.home-assignments.update', $unit->id), ccBatch($unit, ["record-{$record->id}"], ["account-{$leader->id}"])],
    };

    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("SELECT id FROM units WHERE id = {$unit->id} FOR UPDATE");

    $result = ccResult(ccStart($owner, $method, $uri, $data, true, 'cc_worker', '-c lock_timeout=700'));
    $holder->commit();

    ccNoServerError($result);
    expect($result['status'])->toBe(409, json_encode($result));
    expect(ccHome($unit))->toBe([]);
    expect(Personnel::where('first_name', 'Brand')->exists())->toBeFalse();
    expect(AuditLog::whereIn('action', ['personnel_home_unit_changed', 'personnel_created'])->exists())->toBeFalse();
})->with(['record', 'team leader', 'new record', 'home team']);

/* ---------- Unscripted mix ---------- */

it('never returns a server error when batch saves, single edits and new records race on one unit', function () {
    $owner = ccOwner();
    $unit = ccUnit('JARZ 1');
    $other = ccUnit('JARZ 2');
    $leaders = collect(range(1, 3))->map(fn ($n) => ccAccount(3, 'Leader', "No$n"));
    $records = collect(range(1, 4))->map(fn ($n) => ccRecord('Crew', "No$n"));

    for ($round = 0; $round < 4; $round++) {
        $leader = $leaders[$round % 3];
        $processes = [
            ccStart($owner, 'PUT', route('superadmin.home-assignments.update', $unit->id), ccBatch($unit, ["record-{$records[$round]->id}"], ["account-{$leader->id}"])),
            ccStart($owner, 'PATCH', route('superadmin.personnel.home-unit', $leader), ['home_unit_id' => $round % 2 ? $unit->id : $other->id]),
            ccStart($owner, 'PATCH', route('superadmin.personnel-records.home-unit', $records[($round + 1) % 4]), ['home_unit_id' => $unit->id]),
            ccStart($owner, 'POST', route('superadmin.personnel-records.store'), ['first_name' => 'Round', 'last_name' => "No$round", 'role' => 'crew', 'home_unit_id' => $unit->id], false),
        ];

        foreach ($processes as $process) {
            $result = ccResult($process);
            ccNoServerError($result);
            expect(in_array($result['status'], [200, 302, 409, 422], true))->toBeTrue(json_encode($result));
        }

        // Invariants: a unit has at most one leader, and a leader leads at most one unit.
        expect(Unit::whereNotNull('team_leader_id')->select('team_leader_id')->groupBy('team_leader_id')->havingRaw('count(*) > 1')->exists())->toBeFalse();
    }
});
