<?php

/*
 * What a Team Leader signing out (mobile "offline" or web logout) does to the
 * truck they lead.
 *
 *  - An ordinary leader on a truck with no active job, reservation or borrow is
 *    released from it, as always.
 *  - A leader stays assigned to a truck with an active job (the app-wide
 *    definition), a reservation, or a borrow, and open borrow records keep
 *    matching the truck's actual leader.
 *  - A truck under maintenance keeps its status and notes whatever happens.
 *
 * One status is deliberately different: an un-started "assigned" offer (the
 * leader has not accepted it) is returned to dispatch on sign-out, and does not
 * hold the truck. That is the pre-existing, separately tested behaviour.
 */

use App\Models\Booking;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/../../Concurrency/Support.php';

function soLeader(array $attrs = []): User
{
    return ccAccount(3, 'Sign', 'Out', array_merge(['must_change_password' => false, 'last_ping_at' => now()], $attrs));
}

/** A truck led by $leader, with the operational fields a release would clear. */
function soUnit(User $leader, array $attrs = []): Unit
{
    $unit = ccUnit('SO ' . random_int(100, 999));
    $unit->update(array_merge([
        'team_leader_id' => $leader->id, 'status' => 'available',
        'dispatcher_status' => 'standby', 'dispatcher_note' => 'Waiting at depot', 'zone_confirmed' => true,
        'last_updated_by' => 'Dispatcher Dan', 'last_updated_at' => now(),
    ], $attrs));

    return $unit->fresh();
}

function soJob(Unit $unit, User $leader, string $status, array $attrs = []): Booking
{
    [, $customer] = ccCustomer('Job Customer ' . random_int(1, 99999));

    return ccScheduledBooking($customer, array_merge([
        'status' => $status, 'service_type' => 'book_now', 'scheduled_date' => null, 'scheduled_time' => null,
        'assigned_team_leader_id' => $leader->id, 'assigned_unit_id' => $unit->id,
    ], $attrs));
}

function soReservation(Unit $unit, string $status = 'scheduled_confirmed'): Booking
{
    [, $customer] = ccCustomer('Reserving Customer ' . random_int(1, 99999));

    return ccScheduledBooking($customer, ['status' => $status, 'price_locked_at' => now(), 'selected_unit_id' => $unit->id]);
}

/** Leader $leader is borrowed from $home onto $borrower, with the open loan the dispatcher flow creates. */
function soBorrow(User $leader, array $unitAttrs = []): array
{
    $home = ccUnit('SO Home ' . random_int(100, 999));
    $borrower = soUnit($leader, $unitAttrs);
    $leader->update(['home_unit_id' => $home->id]);

    return [$home, $borrower, ccLoan($home, 'team_leader', $borrower, 'team_leader', $leader)];
}

/** Every open Team Leader loan names the leader the truck actually has. */
function soLoansMatchTrucks(): void
{
    foreach (UnitCrewLoan::whereNull('returned_at')->where('to_slot', 'team_leader')->get() as $loan) {
        expect((int) Unit::find($loan->to_unit_id)->team_leader_id)->toBe((int) $loan->person_user_id, "open loan {$loan->id} no longer matches its truck");
    }
}

/** Signs out the way the app does: the mobile presence call, or the web logout. */
function soSignOut(string $how, User $leader): void
{
    if ($how === 'mobile') {
        Sanctum::actingAs($leader, ['*']);
        test()->postJson('/api/v1/team-leader/presence/offline')->assertOk();

        return;
    }

    test()->actingAs($leader)->post('/logout')->assertRedirect();
}

function soPath(string $label): string
{
    return str_starts_with($label, 'mobile') ? 'mobile' : 'web';
}

dataset('sign-out paths', ['mobile app offline', 'web logout']);
dataset('active job statuses', ['accepted', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification']);
dataset('reserving statuses', ['quoted', 'quotation_sent', 'confirmed', 'scheduled_confirmed']);

/* ---------- Active jobs: the canonical definition ---------- */

it('uses the app-wide list of active job statuses (this test fails if that list changes)', function () {
    $canonical = app(TeamLeaderAvailabilityService::class)->busyStatusesList();

    expect(collect(['assigned', 'accepted', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification'])->sort()->values()->all())
        ->toBe(collect($canonical)->sort()->values()->all());
});

it('keeps a leader on a truck with an active job, in every active job status', function (string $status) {
    $leader = soLeader();
    $unit = soUnit($leader);
    $job = soJob($unit, $leader, $status);

    soSignOut('mobile', $leader);

    expect($unit->fresh()->only(['team_leader_id', 'dispatcher_note', 'status']))->toBe(['team_leader_id' => $leader->id, 'dispatcher_note' => 'Waiting at depot', 'status' => 'available']);
    expect($job->fresh()->only(['status', 'assigned_team_leader_id']))->toBe(['status' => $status, 'assigned_team_leader_id' => $leader->id]);
})->with('active job statuses');

it('keeps a leader whose active job names a different truck than the one they lead', function () {
    $leader = soLeader();
    $unit = soUnit($leader);
    $other = ccUnit('SO Other');
    soJob($other, $leader, 'arrived_pickup');

    soSignOut('web', $leader);

    expect($unit->fresh()->team_leader_id)->toBe($leader->id);
});

it('treats a job awaiting verification with payment submitted, or already returned, as no longer active', function () {
    $leader = soLeader();
    $unit = soUnit($leader);
    soJob($unit, $leader, 'waiting_verification', ['payment_submitted_at' => now()]);
    soJob($unit, $leader, 'accepted', ['returned_at' => now()]);

    soSignOut('mobile', $leader);

    expect($unit->fresh()->team_leader_id)->toBeNull();
});

it('returns an un-started "assigned" offer to dispatch and releases the truck, as before (the one deliberate exception)', function () {
    $leader = soLeader();
    $unit = soUnit($leader);
    $offer = soJob($unit, $leader, 'assigned', ['driver_name' => 'Driver One']);

    soSignOut('mobile', $leader);

    expect($offer->fresh()->only(['status', 'assigned_team_leader_id', 'driver_name']))->toBe(['status' => 'assigned', 'assigned_team_leader_id' => null, 'driver_name' => null]);
    expect($unit->fresh()->team_leader_id)->toBeNull();
});

it('still returns an offer to dispatch when the same leader also has a job under way, and keeps the truck for that job', function () {
    $leader = soLeader();
    $unit = soUnit($leader);
    $offer = soJob($unit, $leader, 'assigned');
    soJob($unit, $leader, 'on_the_way');

    soSignOut('mobile', $leader);

    expect($offer->fresh()->assigned_team_leader_id)->toBeNull();
    expect($unit->fresh()->team_leader_id)->toBe($leader->id);
});

/* ---------- Reservations ---------- */

it('keeps a leader on a reserved truck, whichever way they sign out, and leaves the reservation alone', function (string $label) {
    $leader = soLeader();
    $unit = soUnit($leader);
    $reservation = soReservation($unit);

    soSignOut(soPath($label), $leader);

    expect($unit->fresh()->team_leader_id)->toBe($leader->id);
    expect($reservation->fresh()->only(['status', 'selected_unit_id']))->toBe(['status' => 'scheduled_confirmed', 'selected_unit_id' => $unit->id]);
})->with('sign-out paths');

it('treats every reserving booking status as a reservation', function (string $status) {
    $leader = soLeader();
    $unit = soUnit($leader);
    soReservation($unit, $status);

    soSignOut('mobile', $leader);

    expect($unit->fresh()->team_leader_id)->toBe($leader->id);
})->with('reserving statuses');

it('releases the truck once the reservation is cancelled', function () {
    $leader = soLeader();
    $unit = soUnit($leader);
    soReservation($unit, 'cancelled');

    soSignOut('mobile', $leader);

    expect($unit->fresh()->team_leader_id)->toBeNull();
});

/* ---------- Borrowed leaders ---------- */

it('keeps a borrowed leader on the truck they were lent to, with the loan open and consistent', function (string $label) {
    $leader = soLeader();
    [, $borrower, $loan] = soBorrow($leader);

    soSignOut(soPath($label), $leader);

    expect($borrower->fresh()->team_leader_id)->toBe($leader->id);
    expect($loan->fresh()->returned_at)->toBeNull();
    soLoansMatchTrucks();
})->with('sign-out paths');

it('keeps a borrowed leader through repeated sign-outs, and the loan never closes by itself', function () {
    $leader = soLeader();
    [, $borrower, $loan] = soBorrow($leader);

    foreach (['mobile', 'web', 'mobile'] as $how) {
        soSignOut($how, $leader);
    }

    expect($borrower->fresh()->team_leader_id)->toBe($leader->id);
    expect(UnitCrewLoan::whereNull('returned_at')->count())->toBe(1);
    expect($loan->fresh()->returned_at)->toBeNull();
    soLoansMatchTrucks();
});

it('keeps a borrowed leader who also has an active job or a reservation', function () {
    $withJob = soLeader();
    [, $jobTruck] = soBorrow($withJob);
    soJob($jobTruck, $withJob, 'in_progress');

    $withReservation = soLeader();
    [, $reservedTruck] = soBorrow($withReservation);
    soReservation($reservedTruck);

    soSignOut('mobile', $withJob);
    soSignOut('mobile', $withReservation);

    expect($jobTruck->fresh()->team_leader_id)->toBe($withJob->id);
    expect($reservedTruck->fresh()->team_leader_id)->toBe($withReservation->id);
    soLoansMatchTrucks();
});

it('returns an offer to dispatch for a borrowed leader but keeps them on the borrowed truck', function () {
    $leader = soLeader();
    [, $borrower, $loan] = soBorrow($leader);
    $offer = soJob($borrower, $leader, 'assigned');

    soSignOut('mobile', $leader);

    expect($offer->fresh()->assigned_team_leader_id)->toBeNull();
    expect($borrower->fresh()->team_leader_id)->toBe($leader->id);
    expect($loan->fresh()->returned_at)->toBeNull();
});

it('releases a leader whose loan has already been returned, as an ordinary leader', function () {
    $leader = soLeader();
    [, $borrower, $loan] = soBorrow($leader);
    $loan->update(['returned_at' => now()]);

    soSignOut('mobile', $leader);

    expect($borrower->fresh()->team_leader_id)->toBeNull();
});

/* ---------- Ordinary leaders and maintenance (unchanged) ---------- */

it('releases an ordinary, non-borrowed leader from a truck with no job or reservation, as before', function (string $label) {
    $leader = soLeader();
    $unit = soUnit($leader);

    soSignOut(soPath($label), $leader);

    expect($unit->fresh()->only(['team_leader_id', 'status', 'dispatcher_status', 'dispatcher_note', 'zone_confirmed', 'last_updated_by', 'last_updated_at']))->toBe([
        'team_leader_id' => null, 'status' => 'available', 'dispatcher_status' => null, 'dispatcher_note' => null,
        'zone_confirmed' => false, 'last_updated_by' => null, 'last_updated_at' => null,
    ]);
})->with('sign-out paths');

it('keeps a truck in maintenance, with its notes and restrictions, when its leader signs out', function (string $label) {
    $leader = soLeader();
    $unit = soUnit($leader, [
        'status' => 'maintenance', 'issue_note' => 'Brake pads worn', 'dispatcher_status' => 'unavailable',
        'dispatcher_note' => 'Do not dispatch', 'zone_confirmed' => true, 'last_updated_by' => 'Owner Olive',
    ]);
    $before = $unit->only(['status', 'issue_note', 'dispatcher_status', 'dispatcher_note', 'zone_confirmed', 'last_updated_by']);

    soSignOut(soPath($label), $leader);

    $after = $unit->fresh();
    expect($after->only(['status', 'issue_note', 'dispatcher_status', 'dispatcher_note', 'zone_confirmed', 'last_updated_by']))->toBe($before);
    expect($after->team_leader_id)->toBeNull();
})->with('sign-out paths');

it('keeps a borrowed truck in maintenance when its borrowed leader signs out, and leaves the loan consistent', function () {
    $leader = soLeader();
    [, $borrower, $loan] = soBorrow($leader, ['status' => 'maintenance', 'issue_note' => 'Gearbox']);

    soSignOut('web', $leader);

    expect($borrower->fresh()->only(['status', 'issue_note', 'team_leader_id']))->toBe(['status' => 'maintenance', 'issue_note' => 'Gearbox', 'team_leader_id' => $leader->id]);
    expect($loan->fresh()->returned_at)->toBeNull();
});

it('is safe to repeat for an ordinary leader and for a maintenance truck', function () {
    $leader = soLeader();
    $unit = soUnit($leader, ['status' => 'maintenance', 'issue_note' => 'Engine']);

    soSignOut('mobile', $leader);
    $afterFirst = $unit->fresh()->only(['status', 'issue_note', 'dispatcher_note', 'team_leader_id']);
    soSignOut('mobile', $leader);
    soSignOut('web', $leader);

    expect($unit->fresh()->only(['status', 'issue_note', 'dispatcher_note', 'team_leader_id']))->toBe($afterFirst);
});

it('only touches the signing-out leader\'s truck', function () {
    $leader = soLeader();
    $other = soLeader();
    $mine = soUnit($leader);
    $theirs = soUnit($other, ['status' => 'maintenance']);

    soSignOut('mobile', $leader);

    expect($mine->fresh()->team_leader_id)->toBeNull();
    expect($theirs->fresh()->only(['team_leader_id', 'status', 'dispatcher_note']))->toBe(['team_leader_id' => $other->id, 'status' => 'maintenance', 'dispatcher_note' => 'Waiting at depot']);
});

it('still clears presence and changes nothing for a non-leader signing out', function () {
    $leader = soLeader();
    soUnit($leader);
    soSignOut('mobile', $leader);
    expect($leader->fresh()->last_ping_at)->toBeNull();

    $driver = ccAccount(4, 'Drive', 'Er', ['must_change_password' => false]);
    $unit = ccUnit('SO Plain');
    $unit->update(['status' => 'maintenance']);

    test()->actingAs($driver)->post('/logout')->assertRedirect();

    expect($unit->fresh()->status)->toBe('maintenance');
});
