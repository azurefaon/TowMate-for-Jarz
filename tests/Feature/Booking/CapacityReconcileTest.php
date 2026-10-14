<?php

/*
 * towmate:capacity-reconcile and the normalized report. The desired state:
 * every accepted, not-ended scheduled booking (completed included) carries its
 * CURRENT scheduled_date as capacity_reserved_date, and each date's counter is
 * the number of such bookings. Dry run by default; --apply is guarded.
 */

use App\Models\AuditLog;
use App\Models\Booking;
use App\Observers\AuditObserver;
use App\Services\CapacityReconciliationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/../../Concurrency/Support.php';

/** An accepted (price locked) scheduled booking that carries no marker, as every pre-migration booking does. */
function recAccepted(\App\Models\Customer $customer, string $date, array $attrs = []): Booking
{
    return ccScheduledBooking($customer, array_merge(['status' => 'scheduled_confirmed', 'price_locked_at' => now()->subDays(3), 'scheduled_date' => $date], $attrs));
}

function recMark(Booking $booking, ?string $date): void
{
    DB::table('bookings')->where('id', $booking->id)->update(['capacity_reserved_date' => $date]);
}

function recCounter(string $date, int $used, int $max = 2): void
{
    DB::table('booking_capacity')->insert(['booking_date' => $date, 'slots_used' => $used, 'slots_max' => $max, 'created_at' => now(), 'updated_at' => now()]);
}

function recMarker(Booking $booking): ?string
{
    return $booking->fresh()->capacity_reserved_date?->toDateString();
}

/** The dry run's JSON, as an operator's tooling would read it. */
function recPlan(): array
{
    Artisan::call('towmate:capacity-reconcile', ['--json' => true]);

    return json_decode(Artisan::output(), true);
}

/** Everything the reconciliation must not touch on a dry run, byte for byte. */
function recSnapshot(): array
{
    AuditObserver::flush();

    return [
        'bookings' => DB::table('bookings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'capacity' => DB::table('booking_capacity')->orderBy('booking_date')->get()->map(fn ($row) => (array) $row)->all(),
        'audits' => AuditLog::count(),
    ];
}

function recApply(string $fingerprint, array $extra = []): \Illuminate\Testing\PendingCommand
{
    return test()->artisan('towmate:capacity-reconcile', array_merge(['--apply' => true, '--confirm-plan' => $fingerprint, '--no-interaction' => true], $extra));
}

function recFailOn(string $table, string $condition, string $action = 'RAISE EXCEPTION \'simulated failure\''): void
{
    DB::unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION rec_fail() RETURNS trigger AS \$\$
        BEGIN
            IF {$condition} THEN {$action}; END IF;
            RETURN NEW;
        END \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER rec_fail BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION rec_fail();
    SQL);
}

/** A representative pre-correction database: every kind of drift in one place. */
function recDriftedDatabase(): array
{
    [, $customer] = ccCustomer('Private Customer');
    $b = [];
    $b['a'] = recAccepted($customer, '2030-03-10', ['group_code' => 'GRP-REC1']);                  // active, unmarked
    $b['b'] = recAccepted($customer, '2030-03-10', ['group_code' => 'GRP-REC1']);                  // its sibling, same day
    $b['completed'] = recAccepted($customer, '2030-03-11', ['status' => 'completed']);              // consumed: stays counted
    $b['moved'] = recAccepted($customer, '2030-03-12');                                             // rescheduled before (history)
    AuditLog::create(['action' => 'booking_rescheduled', 'entity_type' => 'Booking', 'entity_id' => $b['moved']->id, 'reference' => $b['moved']->booking_code, 'description' => 'moved earlier']);
    $b['cancelled'] = recAccepted($customer, '2030-03-10', ['status' => 'cancelled']);              // ended, but still marked
    recMark($b['cancelled'], '2030-03-10');
    $b['unaccepted'] = ccScheduledBooking($customer, ['status' => 'scheduled', 'scheduled_date' => '2030-03-13']); // never accepted, stale marker
    recMark($b['unaccepted'], '2030-03-13');
    $b['stale'] = recAccepted($customer, '2030-03-16');                                             // marked on a different date than it has now
    recMark($b['stale'], '2030-03-15');
    $b['good'] = recAccepted($customer, '2030-03-17');                                              // already correct
    recMark($b['good'], '2030-03-17');

    recCounter('2030-03-10', -1);   // negative
    recCounter('2030-03-17', 5, 7); // mismatched, with its own slots_max
    recCounter('2030-03-20', 2);    // orphan: no booking at all
    // 03-11, 03-12 and 03-16 have no counter row (missing)

    return [$customer, $b];
}

/* ---------- Dry run ---------- */

it('is a dry run by default and changes nothing, byte for byte', function () {
    recDriftedDatabase();
    $before = recSnapshot();

    $this->artisan('towmate:capacity-reconcile')
        ->expectsOutputToContain('DRY RUN: nothing was changed.')
        ->expectsOutputToContain('Plan fingerprint:')
        ->assertExitCode(0);

    expect(recSnapshot())->toEqual($before);
});

it('plans the normalized state and keeps history separate from the target', function () {
    [, $b] = recDriftedDatabase();

    $plan = recPlan();

    expect($plan['summary'])->toMatchArray([
        'markers_to_set' => 4,      // a, b, completed, moved
        'markers_to_move' => 1,     // stale: 03-15 -> 03-16
        'markers_to_clear' => 2,    // cancelled, unaccepted
        'counters_to_change' => 6,  // 03-10 (negative), 03-11, 03-12, 03-16 (missing rows), 03-17 (mismatch), 03-20 (orphan)
        'completed_consumed' => 1,
        'legacy_rescheduled' => 1,
        'ended_with_marker' => 1,
        'marker_without_acceptance' => 1,
        'marker_differs_from_date' => 1,
        'orphan_counters' => 1,
        'negative' => 1,
        'blockers' => 0,
    ]);
    expect($plan['observations']['rescheduled_legacy'])->toBe([$b['moved']->booking_code]);
    expect($plan['changes']['set'][$b['moved']->id]['to'])->toBe('2030-03-12'); // the current date, never a reconstructed one
});

/* ---------- Apply safeguards ---------- */

it('refuses --apply without a confirmed plan fingerprint', function () {
    recDriftedDatabase();
    $before = recSnapshot();

    $this->artisan('towmate:capacity-reconcile', ['--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('--apply needs --confirm-plan')
        ->assertExitCode(1);

    expect(recSnapshot())->toEqual($before);
});

it('refuses a fingerprint that does not match the current data', function () {
    recDriftedDatabase();
    $before = recSnapshot();

    recApply(str_repeat('0', 40))->expectsOutputToContain('differs from the dry run you confirmed')->assertExitCode(1);

    expect(recSnapshot())->toEqual($before);
});

it('refuses a plan that has gone stale because the data changed after the dry run', function () {
    [$customer] = recDriftedDatabase();
    $fingerprint = recPlan()['fingerprint'];
    recAccepted($customer, '2030-03-30'); // a booking appears after the dry run
    $before = recSnapshot();

    recApply($fingerprint)->expectsOutputToContain('Run the dry run again')->assertExitCode(1);

    expect(recSnapshot())->toEqual($before);
});

it('needs --allow-production as well when APP_ENV is production', function () {
    recDriftedDatabase();
    $fingerprint = recPlan()['fingerprint'];
    $before = recSnapshot();
    $this->app['env'] = 'production';

    recApply($fingerprint)->expectsOutputToContain('--allow-production')->assertExitCode(1);
    expect(recSnapshot())->toEqual($before);

    // A production dry run needs no flag, and tells the operator what the apply will need.
    $this->artisan('towmate:capacity-reconcile')->expectsOutputToContain('--allow-production')->assertExitCode(0);

    recApply($fingerprint, ['--allow-production' => true])->assertExitCode(0);
    expect(DB::table('booking_capacity')->where('slots_used', '<', 0)->count())->toBe(0);
});

it('asks the operator to type the database name when run interactively', function () {
    recDriftedDatabase();
    $fingerprint = recPlan()['fingerprint'];
    $before = recSnapshot();

    $this->artisan('towmate:capacity-reconcile', ['--apply' => true, '--confirm-plan' => $fingerprint])
        ->expectsQuestion('Type the database name to continue', 'not-the-database')
        ->expectsOutputToContain('The name did not match')
        ->assertExitCode(1);
    expect(recSnapshot())->toEqual($before);

    $this->artisan('towmate:capacity-reconcile', ['--apply' => true, '--confirm-plan' => $fingerprint])
        ->expectsQuestion('Type the database name to continue', DB::connection()->getDatabaseName())
        ->assertExitCode(0);
});

/* ---------- Apply ---------- */

it('brings every marker and counter to the normalized state and touches nothing else', function () {
    [, $b] = recDriftedDatabase();
    $plan = recPlan();
    AuditObserver::flush();
    $bookingsBefore = DB::table('bookings')->orderBy('id')->get()->map(fn ($row) => collect((array) $row)->except('capacity_reserved_date')->all())->all();
    $auditsBefore = AuditLog::count();
    $maxBefore = DB::table('booking_capacity')->pluck('slots_max', 'booking_date')->all();

    recApply($plan['fingerprint'])->expectsOutputToContain('Applied: 4 marker(s) set, 1 moved, 2 cleared')->assertExitCode(0);

    // Markers: every eligible booking on its current date (completed included); ended or never-accepted ones cleared.
    expect(array_map(fn ($key) => recMarker($b[$key]), ['a', 'b', 'completed', 'moved', 'cancelled', 'unaccepted', 'stale', 'good']))
        ->toBe(['2030-03-10', '2030-03-10', '2030-03-11', '2030-03-12', null, null, '2030-03-16', '2030-03-17']);

    // Counters: the number of eligible bookings per date. Orphan and negative ones are corrected; missing rows are created.
    $counters = DB::table('booking_capacity')->pluck('slots_used', 'booking_date')->map(fn ($v) => (int) $v)->all();
    expect($counters)->toBe(['2030-03-10' => 2, '2030-03-11' => 1, '2030-03-12' => 1, '2030-03-16' => 1, '2030-03-17' => 1, '2030-03-20' => 0]);

    // slots_max untouched for existing rows (including a non-default one), and nothing enforces it.
    $maxAfter = DB::table('booking_capacity')->pluck('slots_max', 'booking_date')->all();
    foreach ($maxBefore as $date => $max) {
        expect((int) $maxAfter[$date])->toBe((int) $max);
    }
    expect((int) $maxAfter['2030-03-17'])->toBe(7);

    // No other booking data changed (not even updated_at), historical dates and audit rows are intact, plus exactly one summary row.
    expect(DB::table('bookings')->orderBy('id')->get()->map(fn ($row) => collect((array) $row)->except('capacity_reserved_date')->all())->all())->toEqual($bookingsBefore);
    expect($b['moved']->fresh()->scheduled_date->toDateString())->toBe('2030-03-12');
    expect(AuditLog::where('action', 'booking_rescheduled')->count())->toBe(1);
    expect(AuditLog::count())->toBe($auditsBefore + 1);
    expect(AuditLog::where('action', 'capacity_reconciled')->value('description'))->toContain('4 marker(s) set, 1 moved to the current date, 2 cleared');
});

it('reports accurately before and after the apply', function () {
    recDriftedDatabase();
    $service = app(CapacityReconciliationService::class);

    $before = $service->report()['summary'];
    expect($before['ok'])->toBeLessThan($before['dates_checked']);

    recApply(recPlan()['fingerprint'])->assertExitCode(0);

    $after = $service->report();
    expect($after['summary'])->toMatchArray([
        'ok' => $after['summary']['dates_checked'], 'stored_too_high' => 0, 'stored_too_low' => 0, 'negative' => 0, 'missing_row' => 0, 'orphan_counters' => 0,
        'legacy_active_unmarked' => 0, 'legacy_completed_unmarked' => 0, 'ended_with_marker' => 0, 'marker_without_acceptance' => 0, 'marker_differs_from_date' => 0,
        'markers_to_set' => 0, 'markers_to_move' => 0, 'markers_to_clear' => 0, 'counters_to_change' => 0,
    ]);
    expect($after['summary']['completed_consumed'])->toBe(1);
});

it('makes a second successful run change nothing at all', function () {
    recDriftedDatabase();
    recApply(recPlan()['fingerprint'])->assertExitCode(0);
    $afterFirst = recSnapshot();

    $second = recPlan();
    expect($second['summary'])->toMatchArray(['markers_to_set' => 0, 'markers_to_move' => 0, 'markers_to_clear' => 0, 'counters_to_change' => 0]);

    recApply($second['fingerprint'])->expectsOutputToContain('Nothing to change')->assertExitCode(0);

    expect(recSnapshot())->toEqual($afterFirst); // not even updated_at or an audit row
});

it('does not enforce two bookings per day: a date may hold more than slots_max', function () {
    [, $customer] = ccCustomer();
    foreach (range(1, 4) as $i) {
        recAccepted($customer, '2030-04-01');
    }
    recCounter('2030-04-01', 1, 2);

    recApply(recPlan()['fingerprint'])->assertExitCode(0);

    expect(ccSlots('2030-04-01'))->toBe(4);
    expect((int) DB::table('booking_capacity')->where('booking_date', '2030-04-01')->value('slots_max'))->toBe(2);
});

/* ---------- Bookings with no valid date ---------- */

it('refuses to apply, and guesses nothing, while an accepted booking has no scheduled date', function () {
    [$customer] = recDriftedDatabase();
    $dateless = recAccepted($customer, '2030-03-10');
    DB::table('bookings')->where('id', $dateless->id)->update(['scheduled_date' => null, 'scheduled_for' => null]);
    $plan = recPlan();
    $before = recSnapshot();

    expect($plan['summary']['eligible_without_date'])->toBe(1);
    expect(implode(' ', $plan['blockers']))->toContain($dateless->booking_code)->toContain('will not be guessed');
    $this->artisan('towmate:capacity-reconcile')->expectsOutputToContain($dateless->booking_code)->assertExitCode(1);

    recApply($plan['fingerprint'])->expectsOutputToContain('no scheduled date')->assertExitCode(1);

    expect(recSnapshot())->toEqual($before); // the fixable drift was not applied either
    expect(recMarker($dateless))->toBeNull();
});

/* ---------- Groups and sibling bookings ---------- */

it('counts every vehicle of a group and flags groups that look inconsistent', function () {
    [, $customer] = ccCustomer();
    recAccepted($customer, '2030-05-01', ['group_code' => 'GRP-OK']);
    recAccepted($customer, '2030-05-01', ['group_code' => 'GRP-OK']);
    recAccepted($customer, '2030-05-01', ['group_code' => 'GRP-OK']);
    recAccepted($customer, '2030-05-02', ['group_code' => 'GRP-MIXED']);
    ccScheduledBooking($customer, ['status' => 'scheduled', 'group_code' => 'GRP-MIXED', 'scheduled_date' => '2030-05-02']);  // still waiting although its sibling was accepted
    recAccepted($customer, '2030-05-03', ['group_code' => 'GRP-SPLIT']);
    recAccepted($customer, '2030-05-04', ['group_code' => 'GRP-SPLIT']);
    $cancelledSibling = recAccepted($customer, '2030-05-01', ['group_code' => 'GRP-OK', 'status' => 'cancelled']);   // a cancelled sibling holds nothing

    $plan = recPlan();

    expect(collect($plan['anomalies']['group_mixed_acceptance'])->pluck('group_code')->all())->toBe(['GRP-MIXED']);
    expect(collect($plan['anomalies']['group_split_dates'])->pluck('group_code')->all())->toBe(['GRP-SPLIT']);

    recApply($plan['fingerprint'])->assertExitCode(0);

    expect([ccSlots('2030-05-01'), ccSlots('2030-05-02'), ccSlots('2030-05-03'), ccSlots('2030-05-04')])->toBe([3, 1, 1, 1]);
    expect(recMarker($cancelledSibling))->toBeNull();
});

/* ---------- Rollback ---------- */

it('rolls everything back when a write fails part-way', function () {
    recDriftedDatabase();
    $plan = recPlan();
    recFailOn('booking_capacity', "NEW.booking_date = DATE '2030-03-20'");
    $before = recSnapshot();
    Log::spy();

    recApply($plan['fingerprint'])->expectsOutputToContain('rolled back')->assertExitCode(1);

    expect(recSnapshot())->toEqual($before); // markers, counters and audit rows: all or nothing
    // Failures are logged by class only, never with a message that could echo row data.
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => ! str_contains(json_encode($context), 'simulated'))->once();
});

it('rolls back and refuses if anything but the marker changed during the apply', function () {
    recDriftedDatabase();
    $plan = recPlan();
    recFailOn('bookings', 'NEW.capacity_reserved_date IS DISTINCT FROM OLD.capacity_reserved_date', 'NEW.final_total := COALESCE(NEW.final_total, 0) + 1');
    $before = recSnapshot();

    recApply($plan['fingerprint'])->expectsOutputToContain('Verification failed')->assertExitCode(1);

    expect(recSnapshot())->toEqual($before);
});

it('rolls back and refuses if slots_max would change', function () {
    recDriftedDatabase();
    $plan = recPlan();
    recFailOn('booking_capacity', 'TRUE', 'NEW.slots_max := 99');
    $before = recSnapshot();

    recApply($plan['fingerprint'])->expectsOutputToContain('Verification failed')->assertExitCode(1);

    expect(recSnapshot())->toEqual($before);
});

/* ---------- Privacy ---------- */

it('never prints customer details', function () {
    [$customer] = recDriftedDatabase();
    $plan = recPlan();
    $output = '';

    foreach ([[], ['--json' => true]] as $options) {
        Artisan::call('towmate:capacity-reconcile', $options);
        $output .= Artisan::output();
    }
    Artisan::call('towmate:capacity-report');
    $output .= Artisan::output();

    expect($output)->not->toContain($customer->email)->not->toContain($customer->full_name)->not->toContain($customer->phone);
    expect($output)->toContain($plan['changes']['set'][array_key_first($plan['changes']['set'])]['code']); // booking codes are fine
});

/* ---------- Existing behaviour is preserved after the correction ---------- */

it('lets cancellation and rescheduling release and move the slots that were just marked', function () {
    [, $b] = recDriftedDatabase();
    recApply(recPlan()['fingerprint'])->assertExitCode(0);
    $capacity = app(\App\Services\ScheduledCapacityService::class);

    expect($capacity->release($b['a']))->toBe(\App\Services\ScheduledCapacityService::RELEASED);
    expect($capacity->move($b['moved'], '2030-04-20'))->toBeTrue();

    expect([ccSlots('2030-03-10'), ccSlots('2030-03-12'), ccSlots('2030-04-20')])->toBe([1, 0, 1]);
    expect(app(CapacityReconciliationService::class)->report()['summary']['marker_differs_from_date'])->toBe(1); // the move helper alone leaves the booking's own date to its controller
});
