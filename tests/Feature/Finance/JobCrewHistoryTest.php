<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Finance\JobPersonnel;
use App\Models\Quotation;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\CrewHistoryService;
use App\Services\Finance\DriverRecordService;
use App\Services\Finance\JobCollectionService;
use App\Services\Finance\JobCrewCaptureService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// Job Crew shows each completed job's crew automatically. For jobs from before live capture, the crew is rebuilt from
// the booking's Dispatch record and the unit's audited roster history at the start of the job (never today's
// roster), shown as "Assigned · not confirmed", and recorded only when the Owner confirms it in one action.

beforeEach(function () {
    // Simulate jobs that ran before live capture existed: Dispatch events record nothing in finance_job_personnel.
    $this->mock(JobCrewCaptureService::class)->shouldIgnoreMissing();
    $GLOBALS['jchBase'] = finLastWeek();   // fixed before any time travel
});

/** Runs $step at $at (Manila) as $actor and writes the audit rows it produced at that moment. */
function jchAt(CarbonImmutable $at, ?User $actor, Closure $step): mixed
{
    test()->travelTo($at);
    $actor ? test()->actingAs($actor) : auth()->logout();
    $result = $step();
    AuditObserver::flush();

    return $result;
}

function jchDay(int $day = 2, int $h = 8, int $m = 0): CarbonImmutable
{
    return $GLOBALS['jchBase']->addDays($day)->setTime($h, $m);
}

/** A job dispatched to $unit, accepted at $start by its Team Leader, completed by the TL and confirmed by Dispatch. */
function jchJob(Unit $unit, User $tl, User $dispatcher, CarbonImmutable $start, string $total = '1000.00', array $extra = []): Booking
{
    $booking = jchAt($start->subMinutes(5), $dispatcher, fn () => finServicedBooking($unit, $total, array_merge(
        ['assigned_unit_id' => null, 'status' => 'confirmed', 'completed_at' => null, 'completion_requested_at' => null, 'payment_submitted_at' => null], $extra)));
    jchAt($start->subMinutes(4), $dispatcher, fn () => $booking->update(['assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $tl->id, 'status' => 'assigned', 'assigned_at' => now()]));
    jchAt($start, $tl, fn () => $booking->update(['status' => 'accepted']));
    jchAt($start->addMinutes(3), $tl, fn () => $booking->update(['status' => 'waiting_verification', 'completion_requested_at' => now(), 'payment_submitted_at' => now(), 'payment_method' => 'cash', 'cash_received' => $total]));
    jchAt($start->addMinutes(13), $dispatcher, fn () => $booking->update(['status' => 'completed', 'completed_at' => now()]));

    return $booking->fresh();
}

/** TM-00131-like history: TL from the start, Driver A before the job, crew added and the driver replaced afterwards. */
function jchHistory(): array
{
    $owner = finOwner();
    $dispatcher = finUser(2);
    $tl = finUser(3);
    $driverA = finPersonnel('driver');   // on the roster during the job
    $mate = finPersonnel('crew');        // added after the job
    $driverB = finPersonnel('driver');   // today's driver
    $unit = jchAt(jchDay(2, 7, 46), $dispatcher, fn () => finUnit($tl));
    jchAt(jchDay(2, 11, 8), $dispatcher, fn () => $unit->update(['driver_name' => $driverA->full_name, 'driver_personnel_id' => $driverA->id]));
    $booking = jchJob($unit, $tl, $dispatcher, jchDay(2, 12, 7));
    jchAt(jchDay(3, 12, 39), $dispatcher, fn () => $unit->update(['crew_member_1_name' => $mate->full_name, 'crew_member_1_personnel_id' => $mate->id]));
    jchAt(jchDay(4, 16, 6), $dispatcher, fn () => $unit->update(['driver_name' => null, 'driver_personnel_id' => null, 'crew_member_1_name' => null, 'crew_member_1_personnel_id' => null]));
    jchAt(jchDay(4, 16, 49), $dispatcher, fn () => $unit->update(['driver_name' => $driverB->full_name, 'driver_personnel_id' => $driverB->id]));
    test()->travelBack();
    test()->actingAs($owner);

    return compact('owner', 'dispatcher', 'tl', 'driverA', 'mate', 'driverB', 'unit', 'booking');
}

it('rebuilds the crew at the start of the job from Dispatch and fleet history, never from today\'s roster', function () {
    $h = jchHistory();
    $job = app(JobCollectionService::class)->jobFor($h['booking']);
    $ev = app(CrewHistoryService::class)->forJob($job)[$h['unit']->id];

    expect(array_keys($ev['members']))->toBe(['team_leader', 'driver_1'])
        ->and($ev['members']['team_leader']['user_id'])->toBe($h['tl']->id)
        ->and($ev['members']['team_leader']['completed_by_self'])->toBeTrue()
        ->and($ev['members']['driver_1']['personnel_id'])->toBe($h['driverA']->id)   // not today's driver B
        ->and($ev['members']['driver_1']['uncertain'])->toBeFalse()
        ->and($ev['as_of']->equalTo(jchDay(2, 12, 7)))->toBeTrue()
        ->and(JobPersonnel::count())->toBe(0);                                         // viewing records nothing

    $this->get(route('superadmin.finance.crew.show', $h['booking']->id))->assertOk()
        ->assertSee('Crew found in the Dispatch history')
        ->assertSee($h['driverA']->full_name)->assertSee($h['tl']->full_name ?: $h['tl']->name)
        ->assertSee('data-crew-history="driver_1"', false)->assertSee('Assigned · not confirmed')
        ->assertSee('completed the job from their own account');
    // Inside the history crew block (the manual form's dropdown lists every person): only the crew of that day.
    $block = \Illuminate\Support\Str::betweenFirst($this->get(route('superadmin.finance.crew.show', $h['booking']->id))->getContent(), 'data-crew-history-form=', '</form>');
    expect($block)->toContain($h['driverA']->full_name)->not->toContain($h['driverB']->full_name)->not->toContain($h['mate']->full_name);
    expect(JobPersonnel::count())->toBe(0);
});

it('records the history crew in one Owner action, confirmed and audited, and feeds Driver Records and commission', function () {
    $h = jchHistory();
    $week = finLastWeek();

    // Before confirmation: not counted, but named on Driver Records and flagged on the Job Crew list.
    $before = app(DriverRecordService::class)->build($week, $week->addDays(5), null, 'all');
    expect($before['people'])->toBe([])->and($before['unrecorded'][0]['in_history'])->toContain($h['driverA']->full_name . ' (Driver)');
    $this->get(route('superadmin.finance.crew.index', ['week' => $week->toDateString()]))->assertOk()->assertSee('data-crew-status="history"', false);

    $this->post(route('superadmin.finance.crew.confirm-history', $h['booking']->id), ['unit_id' => $h['unit']->id, 'slots' => ['team_leader', 'driver_1']])
        ->assertRedirect()->assertSessionHas('success');

    $rows = JobPersonnel::current()->where('booking_id', $h['booking']->id)->get()->keyBy('slot');
    expect($rows)->toHaveCount(2)
        ->and($rows['team_leader']->user_id)->toBe($h['tl']->id)->and($rows['team_leader']->commission_role)->toBe('team_leader')
        ->and($rows['driver_1']->personnel_id)->toBe($h['driverA']->id)->and($rows['driver_1']->commission_role)->toBe('driver')
        ->and($rows->every(fn ($r) => $r->source === 'backfill' && ! $r->needs_review && $r->confirmed_by === $h['owner']->id && str_contains($r->notes, 'Dispatch and fleet history')))->toBeTrue()
        ->and(AuditLog::where('action', 'finance_crew_recorded')->count())->toBe(2);

    // Confirming again records no duplicates.
    $this->post(route('superadmin.finance.crew.confirm-history', $h['booking']->id), ['unit_id' => $h['unit']->id, 'slots' => ['team_leader', 'driver_1']]);
    expect(JobPersonnel::current()->where('booking_id', $h['booking']->id)->count())->toBe(2);

    $after = app(DriverRecordService::class)->build($week, $week->addDays(5), null, 'driver');
    $person = $after['people']["personnel:{$h['driverA']->id}"];
    $calc = app(CommissionCalculator::class)->draft($week, $h['unit']->id);
    $share = collect(collect($calc['lines'])->firstWhere('booking_id', $h['booking']->id)['roles']['driver']['recipients'])->first();
    expect($person['confirmed_jobs'])->toBe(1)->and($person['pending_jobs'])->toBe(0)
        ->and($person['history'][0]['source'])->toBe('Rebuilt from records')
        ->and($share['personnel_id'])->toBe($h['driverA']->id)->and($share['pending'])->toBeFalse()
        ->and($person['draft']->toString())->toBe($share['amount']->toString())
        ->and(collect($calc['blockers'])->pluck('code'))->not->toContain('personnel_needs_review')->toContain('policy_for_client_review')
        ->and($calc['can_approve'])->toBeFalse();
    $this->get(route('superadmin.finance.crew.show', $h['booking']->id))->assertOk()->assertDontSee('Crew found in the Dispatch history')->assertSee('data-crew-state="confirmed"', false);
});

it('marks people uncertain (unticked) when the roster changed during the job or its history has a gap', function () {
    $owner = finOwner();
    $dispatcher = finUser(2);
    $tl = finUser(3);
    [$a, $b, $c] = [finPersonnel('driver'), finPersonnel('driver'), finPersonnel('crew')];
    $unit = jchAt(jchDay(1, 8), $dispatcher, fn () => finUnit($tl));
    jchAt(jchDay(1, 9), $dispatcher, fn () => $unit->update(['driver_name' => $a->full_name, 'driver_personnel_id' => $a->id, 'crew_member_1_name' => $c->full_name, 'crew_member_1_personnel_id' => $c->id]));
    $booking = jchAt(jchDay(2, 9, 55), $dispatcher, fn () => finServicedBooking($unit, '1000.00', ['assigned_unit_id' => null, 'status' => 'confirmed', 'completed_at' => null, 'completion_requested_at' => null]));
    jchAt(jchDay(2, 9, 56), $dispatcher, fn () => $booking->update(['assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $tl->id, 'status' => 'assigned']));
    jchAt(jchDay(2, 10), $tl, fn () => $booking->update(['status' => 'accepted']));
    jchAt(jchDay(2, 10, 30), $dispatcher, fn () => $unit->update(['driver_name' => $b->full_name, 'driver_personnel_id' => $b->id]));   // during the job
    jchAt(jchDay(2, 11), $tl, fn () => $booking->update(['status' => 'waiting_verification', 'completion_requested_at' => now(), 'payment_submitted_at' => now(), 'payment_method' => 'cash', 'cash_received' => '1000.00']));
    jchAt(jchDay(2, 11, 10), $dispatcher, fn () => $booking->update(['status' => 'completed', 'completed_at' => now()]));
    // An unlogged change (bypassing the model), then a logged one that no longer matches the chain: a gap.
    Unit::whereKey($unit->id)->update(['crew_member_1_name' => 'Unlogged Person', 'crew_member_1_personnel_id' => null]);
    jchAt(jchDay(3, 9), $dispatcher, fn () => Unit::find($unit->id)->update(['crew_member_1_name' => null, 'crew_member_1_personnel_id' => null]));
    test()->travelBack();

    $ev = app(CrewHistoryService::class)->forJob(app(JobCollectionService::class)->jobFor($booking->fresh()))[$unit->id];
    expect($ev['members']['driver_1']['personnel_id'])->toBe($a->id)
        ->and($ev['members']['driver_1']['uncertain'])->toBeTrue()->and($ev['members']['driver_1']['reason'])->toContain('changed while the job was running')
        ->and($ev['members']['crew_member_1']['uncertain'])->toBeTrue()->and($ev['members']['crew_member_1']['reason'])->toContain('gap')
        ->and($ev['members']['team_leader']['uncertain'])->toBeFalse();

    $html = $this->actingAs($owner)->get(route('superadmin.finance.crew.show', $booking->id))->assertOk()->assertSee('Uncertain')->getContent();
    expect($html)->toMatch('/name="slots\[\]" value="team_leader"\s+checked/')
        ->not->toMatch('/name="slots\[\]" value="driver_1"\s+checked/');
});

it('marks a job older than the fleet history as uncertain', function () {
    $tl = finUser(3);
    $driver = finPersonnel('driver');
    // The roster predates the audit history: no log exists for it.
    $unit = Unit::withoutEvents(fn () => tap(finUnit($tl))->update(['driver_name' => $driver->full_name, 'driver_personnel_id' => $driver->id]));
    AuditObserver::flush();
    finUnit();                // an unrelated logged change: the fleet history starts now
    AuditObserver::flush();
    $old = CarbonImmutable::parse('2026-01-07 10:00', config('finance.timezone'));
    $booking = Booking::withoutEvents(fn () => finServicedBooking($unit, '1000.00', ['assigned_team_leader_id' => $tl->id, 'assigned_at' => $old,
        'completion_requested_at' => $old->addHour(), 'payment_submitted_at' => $old->addHour(), 'completed_at' => $old->addHours(2)]));

    $ev = app(CrewHistoryService::class)->forJob(app(JobCollectionService::class)->jobFor($booking))[$unit->id];
    expect($ev['members']['driver_1']['uncertain'])->toBeTrue()->and($ev['members']['driver_1']['reason'])->toContain('lder than the fleet history')
        ->and(collect($ev['notes'])->implode(' '))->toContain('older than the fleet history');
});

it('marks crew on loan at the start of the job as borrowed, and needs the second Driver\'s role before recording', function () {
    $owner = finOwner();
    $dispatcher = finUser(2);
    $tl = finUser(3);
    $second = finPersonnel('driver');
    $lent = finPersonnel('crew');
    $unit = jchAt(jchDay(1, 8), $dispatcher, fn () => finUnit($tl));
    $lender = jchAt(jchDay(1, 8, 5), $dispatcher, fn () => finUnit());
    jchAt(jchDay(1, 9), $dispatcher, function () use ($unit, $second, $lent, $lender) {
        $unit->update(['driver_2_name' => $second->full_name, 'driver_2_personnel_id' => $second->id, 'crew_member_2_name' => $lent->full_name, 'crew_member_2_personnel_id' => $lent->id]);
        UnitCrewLoan::create(['from_unit_id' => $lender->id, 'to_unit_id' => $unit->id, 'from_slot' => 'crew_member_1', 'to_slot' => 'crew_member_2',
            'person_name' => $lent->full_name, 'personnel_id' => $lent->id, 'borrowed_at' => now()]);
    });
    $booking = jchJob($unit, $tl, $dispatcher, jchDay(2, 10));
    test()->travelBack();
    $this->actingAs($owner);

    $ev = app(CrewHistoryService::class)->forJob(app(JobCollectionService::class)->jobFor($booking))[$unit->id];
    expect($ev['members']['crew_member_2']['borrowed_from'])->toBe($lender->id)->and($ev['members']['driver_2']['role'])->toBeNull();

    $this->post(route('superadmin.finance.crew.confirm-history', $booking->id), ['unit_id' => $unit->id, 'slots' => ['driver_2']])->assertSessionHasErrors('finance');
    expect(JobPersonnel::count())->toBe(0);
    $this->post(route('superadmin.finance.crew.confirm-history', $booking->id), ['unit_id' => $unit->id, 'slots' => ['driver_2', 'crew_member_2'], 'second_driver_role' => 'rider'])->assertSessionHas('success');
    $rows = JobPersonnel::current()->where('booking_id', $booking->id)->get()->keyBy('slot');
    expect($rows['driver_2']->commission_role)->toBe('rider')
        ->and($rows->firstWhere('personnel_id', $lent->id)->borrowed_from_unit_id)->toBe($lender->id)
        ->and($rows->firstWhere('personnel_id', $lent->id)->is_substitute)->toBeTrue();
});

it('shows one crew per unit for a group trip towed by two units', function () {
    $owner = finOwner();
    $dispatcher = finUser(2);
    [$tlA, $tlB] = [finUser(3), finUser(3)];
    [$dA, $dB] = [finPersonnel('driver'), finPersonnel('driver')];
    [$a, $b] = jchAt(jchDay(1, 8), $dispatcher, fn () => [finUnit($tlA), finUnit($tlB)]);
    jchAt(jchDay(1, 9), $dispatcher, function () use ($a, $b, $dA, $dB) {
        $a->update(['driver_name' => $dA->full_name, 'driver_personnel_id' => $dA->id]);
        $b->update(['driver_name' => $dB->full_name, 'driver_personnel_id' => $dB->id]);
    });
    $customer = Customer::create(['full_name' => 'Group Client', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'jch-' . Str::random(6) . '@example.com']);
    $code = 'GRP-' . Str::upper(Str::random(5));
    $extra = ['customer_id' => $customer->id, 'group_code' => $code, 'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff'];
    $m1 = jchJob($a, $tlA, $dispatcher, jchDay(2, 10), '6000.00', $extra);
    $m2 = jchJob($b, $tlB, $dispatcher, jchDay(2, 10, 1), '4000.00', $extra);
    test()->travelBack();
    $q = Quotation::create(['quotation_number' => 'QT-' . Str::random(6), 'source_booking_id' => $m1->id, 'customer_id' => $customer->id, 'truck_type_id' => $m1->truck_type_id,
        'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff', 'distance_km' => 30, 'estimated_price' => '10000', 'additional_fee' => '0', 'discount' => '0',
        'service_type' => 'book_now', 'status' => 'accepted', 'extra_vehicles' => [['booking_id' => $m2->id, 'truck_type_id' => $m2->truck_type_id, 'final_total' => 4000.0]]]);
    Booking::withoutEvents(fn () => Booking::whereIn('id', [$m1->id, $m2->id])->update(['quotation_id' => $q->id]));

    $ev = app(CrewHistoryService::class)->forJob(app(JobCollectionService::class)->jobFor($m1->fresh()));
    expect(array_keys($ev))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($ev[$a->id]['members']['driver_1']['personnel_id'])->toBe($dA->id)
        ->and($ev[$b->id]['members']['driver_1']['personnel_id'])->toBe($dB->id)
        ->and($ev[$b->id]['members']['team_leader']['user_id'])->toBe($tlB->id);
    $this->actingAs($owner)->get(route('superadmin.finance.crew.show', $m1->id))->assertOk()
        ->assertSee('data-crew-history-form="' . $a->id . '"', false)->assertSee('data-crew-history-form="' . $b->id . '"', false);
});

it('lets the Owner confirm a unit\'s pending captured crew in one action, and only the Owner', function () {
    // Live capture is on for this test.
    app()->forgetInstance(JobCrewCaptureService::class);
    $this->app->offsetUnset(JobCrewCaptureService::class);
    $owner = finOwner();
    $dispatcher = finUser(2);
    $tl = finUser(3);
    [$d1, $d2, $m] = [finPersonnel('driver'), finPersonnel('driver'), finPersonnel('crew')];
    $unit = finUnit($tl);
    $unit->update(['driver_name' => $d1->full_name, 'driver_personnel_id' => $d1->id, 'driver_2_name' => $d2->full_name, 'driver_2_personnel_id' => $d2->id,
        'crew_member_1_name' => $m->full_name, 'crew_member_1_personnel_id' => $m->id]);
    $booking = finServicedBooking($unit, '1000.00', ['assigned_unit_id' => null, 'status' => 'confirmed', 'completed_at' => null]);
    $this->actingAs($dispatcher);
    $booking->update(['assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $tl->id, 'status' => 'assigned']);
    $booking->update(['status' => 'completed', 'completed_at' => finLastWeek()->addDays(2)->setTime(11, 0)]);
    expect(JobPersonnel::current()->where('booking_id', $booking->id)->where('needs_review', true)->count())->toBe(4);

    foreach ([$dispatcher, $tl] as $user) {
        $this->actingAs($user)->post(route('superadmin.finance.crew.confirm-pending', $booking->id), ['unit_id' => $unit->id])->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.crew.confirm-history', $booking->id), ['unit_id' => $unit->id, 'slots' => ['team_leader']])->assertForbidden();
    }
    $this->actingAs($owner)->get(route('superadmin.finance.crew.show', $booking->id))->assertOk()->assertSee('data-crew-confirm-all="' . $unit->id . '"', false)
        ->assertDontSee('Crew found in the Dispatch history');
    $this->actingAs($owner)->post(route('superadmin.finance.crew.confirm-pending', $booking->id), ['unit_id' => $unit->id])->assertSessionHas('success');

    $rows = JobPersonnel::current()->where('booking_id', $booking->id)->get()->keyBy('slot');
    expect($rows->except([])->filter(fn ($r) => $r->slot !== 'driver_2')->every(fn ($r) => ! $r->needs_review && $r->confirmed_by === $owner->id))->toBeTrue()
        ->and($rows['driver_2']->needs_review)->toBeTrue()                                // role still to choose
        ->and(JobPersonnel::where('booking_id', $booking->id)->count())->toBe(4);        // confirmed in place, no duplicates
});
