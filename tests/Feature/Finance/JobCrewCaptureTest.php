<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Finance\JobPersonnel;
use App\Models\Quotation;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\DriverRecordService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\JobCrewCaptureService;
use App\Services\Finance\JobCrewService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// Automatic job crew capture from Dispatch: pending at assignment, confirmed only by the Owner (or by the Team
// Leader's own completion, for the Team Leader's row), never rewriting Owner records or history.

beforeEach(function () {
    Storage::fake('local');
});

/** A unit with a Team Leader account, Driver, second Driver and Crew Member. */
function jccUnit(): array
{
    $tl = finUser(3);
    $unit = finUnit($tl);
    $driver = finPersonnel('driver');
    $rider = finPersonnel('driver');
    $mate = finPersonnel('crew');
    $unit->forceFill([
        'driver_name' => $driver->full_name, 'driver_personnel_id' => $driver->id,
        'driver_2_name' => $rider->full_name, 'driver_2_personnel_id' => $rider->id,
        'crew_member_1_name' => $mate->full_name, 'crew_member_1_personnel_id' => $mate->id,
    ])->save();

    return compact('unit', 'tl', 'driver', 'rider', 'mate');
}

/** A booking waiting for Dispatch (no unit yet), serviced last Wednesday once it is completed. */
function jccOpenBooking(Unit $truckTypeFrom, string $total = '1000.00', array $overrides = []): Booking
{
    return finServicedBooking($truckTypeFrom, $total, array_merge(['assigned_unit_id' => null, 'status' => 'confirmed', 'completed_at' => null], $overrides));
}

function jccAssign(Booking $booking, ?Unit $unit, string $status = 'assigned'): Booking
{
    $booking->update(['assigned_unit_id' => $unit?->id, 'assigned_team_leader_id' => $unit?->team_leader_id, 'status' => $status]);

    return $booking->fresh();
}

function jccComplete(Booking $booking): Booking
{
    $booking->update(['status' => 'completed', 'completed_at' => finLastWeek()->addDays(2)->setTime(11, 0)]);

    return $booking->fresh();
}

/** @return \Illuminate\Support\Collection<int, JobPersonnel> current rows for the booking's trip, keyed by slot */
function jccCurrent(Booking $booking, ?int $unitId = null)
{
    return JobPersonnel::current()->where('booking_id', $booking->id)->when($unitId, fn ($q) => $q->where('unit_id', $unitId))->orderBy('id')->get()->keyBy('slot');
}

function jccExpense(Booking $booking, Unit $unit, string $amount): void
{
    $owner = User::where('role_id', 1)->first() ?? finOwner();
    $service = app(ExpenseService::class);
    $expense = $service->submit($owner, [
        'unit_id' => $unit->id, 'expense_scope' => 'job', 'booking_id' => $booking->booking_code,
        'expense_date' => finLastWeek()->addDays(2)->toDateString(), 'category' => 'fuel_diesel',
        'description' => 'Fuel for the tow', 'amount' => $amount, 'fundings' => ['unit_fund' => $amount],
    ], (string) Str::uuid(), [UploadedFile::fake()->image('r.jpg', random_int(10, 90), random_int(10, 90))]);
    $service->approve($owner, $expense);
}

it('captures the assigned unit\'s crew as Pending confirmation when Dispatch assigns a unit', function () {
    ['unit' => $unit, 'tl' => $tl, 'driver' => $driver, 'rider' => $rider, 'mate' => $mate] = jccUnit();
    $dispatcher = finUser(2);
    $booking = jccOpenBooking($unit);
    expect(JobPersonnel::where('booking_id', $booking->id)->count())->toBe(0);   // creating a booking captures nothing

    $this->actingAs($dispatcher);
    jccAssign($booking, $unit);

    $rows = jccCurrent($booking);
    expect($rows->keys()->sort()->values()->all())->toBe(['crew_member_1', 'driver_1', 'driver_2', 'team_leader'])
        ->and($rows['team_leader']->user_id)->toBe($tl->id)->and($rows['team_leader']->commission_role)->toBe('team_leader')
        ->and($rows['driver_1']->personnel_id)->toBe($driver->id)->and($rows['driver_1']->commission_role)->toBe('driver')
        ->and($rows['driver_2']->personnel_id)->toBe($rider->id)->and($rows['driver_2']->commission_role)->toBeNull()   // Rider mapping: client decision
        ->and($rows['crew_member_1']->personnel_id)->toBe($mate->id)->and($rows['crew_member_1']->commission_role)->toBe('crew_mate')
        ->and($rows->every(fn ($r) => $r->needs_review && $r->confirmed_at === null && $r->source === 'assignment_capture'
            && $r->captured_by === $dispatcher->id && $r->unit_id === $unit->id && $r->captured_at !== null))->toBeTrue()
        ->and($rows['driver_1']->sourceLabel())->toBe('Assigned by Dispatch');

    // Saving the booking again (no unit or status change) captures nothing new.
    $booking->fresh()->update(['notes' => 'Call before arrival']);
    jccAssign($booking->fresh(), $unit);
    expect(JobPersonnel::where('booking_id', $booking->id)->count())->toBe(4);
});

it('withdraws the old unit\'s pending crew on reassignment, return to Dispatch and cancellation', function () {
    ['unit' => $a] = jccUnit();
    ['unit' => $b, 'driver' => $driverB] = jccUnit();
    $this->actingAs(finUser(2));
    $booking = jccAssign(jccOpenBooking($a), $a);

    jccAssign($booking, $b);
    expect(jccCurrent($booking, $a->id))->toHaveCount(0)
        ->and(jccCurrent($booking, $b->id))->toHaveCount(4)
        ->and(jccCurrent($booking, $b->id)['driver_1']->personnel_id)->toBe($driverB->id)
        ->and(JobPersonnel::where('booking_id', $booking->id)->where('unit_id', $a->id)->whereNotNull('superseded_at')->pluck('supersede_reason')->unique()->all())
        ->toBe(['The booking was reassigned to another unit before the job.']);

    jccAssign($booking->fresh(), null, 'confirmed');   // returned to Dispatch
    expect(JobPersonnel::current()->where('booking_id', $booking->id)->count())->toBe(0)
        ->and(JobPersonnel::where('booking_id', $booking->id)->count())->toBe(8);           // history kept, nothing deleted

    $other = jccAssign(jccOpenBooking($a), $a);
    $other->update(['status' => 'cancelled', 'assigned_unit_id' => null]);
    expect(JobPersonnel::current()->where('booking_id', $other->id)->count())->toBe(0);
});

it('re-checks the crew when the Team Leader accepts: a roster change replaces only pending captures', function () {
    ['unit' => $unit, 'driver' => $old] = jccUnit();
    $this->actingAs(finUser(2));
    $booking = jccAssign(jccOpenBooking($unit), $unit);

    $new = finPersonnel('driver');
    $unit->forceFill(['driver_name' => $new->full_name, 'driver_personnel_id' => $new->id, 'crew_member_1_name' => null, 'crew_member_1_personnel_id' => null])->save();
    $booking->update(['status' => 'accepted']);

    $rows = jccCurrent($booking);
    $replaced = JobPersonnel::where('booking_id', $booking->id)->where('personnel_id', $old->id)->first();
    expect($rows['driver_1']->personnel_id)->toBe($new->id)->and($rows['driver_1']->needs_review)->toBeTrue()
        ->and($rows->has('crew_member_1'))->toBeFalse()                                   // slot emptied before the job
        ->and($replaced->superseded_at)->not->toBeNull()
        ->and($replaced->supersede_reason)->toContain('task accepted')->toContain($new->full_name)
        ->and($rows)->toHaveCount(3);
});

it('never touches rows the Owner recorded or confirmed, and never duplicates a person the Owner already recorded', function () {
    ['unit' => $unit, 'driver' => $driver, 'mate' => $mate, 'tl' => $tl] = jccUnit();
    $owner = finOwner();
    $capture = app(JobCrewCaptureService::class);
    $crew = app(JobCrewService::class);

    // A finished job the Owner recorded by hand (created already assigned, so nothing was captured).
    $manual = finServicedBooking($unit);
    $crew->record($owner, $manual, ['unit_id' => $unit->id, 'participant' => "personnel:{$driver->id}", 'commission_role' => 'driver']);
    $crew->record($owner, $manual, ['unit_id' => $unit->id, 'participant' => "personnel:{$mate->id}", 'commission_role' => 'crew_mate']);
    $this->actingAs(finUser(2));
    $capture->sync($manual, 'test');
    $current = JobPersonnel::current()->where('booking_id', $manual->id)->get();
    expect($current->where('personnel_id', $driver->id)->count())->toBe(1)->and($current->where('personnel_id', $driver->id)->first()->source)->toBe('manual')
        ->and($current->where('personnel_id', $mate->id)->count())->toBe(1)->and($current->where('personnel_id', $mate->id)->first()->source)->toBe('manual')
        ->and($current->where('source', 'assignment_capture')->pluck('slot')->sort()->values()->all())->toBe(['driver_2', 'team_leader']);   // only the missing people, pending

    // Recording a person who is already pending points the Owner to Confirm instead of adding a duplicate.
    $booking = jccComplete(jccAssign(jccOpenBooking($unit), $unit));
    expect(fn () => $crew->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$driver->id}", 'commission_role' => 'driver']))
        ->toThrow(\App\Services\Finance\FinanceException::class, 'Confirm the pending record instead');

    // A row the Owner confirmed stays put when the roster later changes and the crew is re-checked.
    $tlRow = jccCurrent($booking)['team_leader'];
    $crew->confirm($owner, $tlRow);
    $unit->forceFill(['team_leader_id' => finUser(3)->id, 'driver_name' => 'Someone Else', 'driver_personnel_id' => finPersonnel('driver')->id])->save();
    $capture->sync($booking->fresh(), 'test');

    $rows = jccCurrent($booking);
    expect($rows['team_leader']->id)->toBe($tlRow->id)->and($rows['team_leader']->user_id)->toBe($tl->id)
        ->and($rows['team_leader']->needs_review)->toBeFalse()->and($rows['team_leader']->confirmed_by)->toBe($owner->id)
        ->and($rows['driver_1']->name_snapshot)->toBe('Someone Else')->and($rows['driver_1']->needs_review)->toBeTrue();   // still pending: replaced
});

it('marks crew on loan from another unit as a substitute borrowed from that unit', function () {
    ['unit' => $unit] = jccUnit();
    ['unit' => $lender] = jccUnit();
    $borrowed = finPersonnel('crew');
    $unit->forceFill(['crew_member_2_name' => $borrowed->full_name, 'crew_member_2_personnel_id' => $borrowed->id])->save();
    UnitCrewLoan::create(['from_unit_id' => $lender->id, 'to_unit_id' => $unit->id, 'from_slot' => 'crew_member_1', 'to_slot' => 'crew_member_2',
        'person_name' => $borrowed->full_name, 'personnel_id' => $borrowed->id, 'borrowed_at' => now()]);
    $this->actingAs(finUser(2));
    $booking = jccAssign(jccOpenBooking($unit), $unit);

    $row = jccCurrent($booking)['crew_member_2'];
    expect($row->personnel_id)->toBe($borrowed->id)->and($row->is_substitute)->toBeTrue()
        ->and($row->borrowed_from_unit_id)->toBe($lender->id)->and($row->needs_review)->toBeTrue()
        ->and(jccCurrent($booking)['crew_member_1']->is_substitute)->toBeFalse();
});

it('records one crew for a unit that tows several vehicles of one group trip, and a separate crew per unit', function () {
    ['unit' => $a] = jccUnit();
    ['unit' => $b] = jccUnit();
    $customer = Customer::create(['full_name' => 'Group Client', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'jcc-' . Str::random(6) . '@example.com']);
    $code = 'GRP-' . Str::upper(Str::random(5));
    $member = fn ($total) => jccOpenBooking($a, $total, ['customer_id' => $customer->id, 'group_code' => $code, 'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff']);
    [$m1, $m2, $m3] = [$member('6000.00'), $member('5000.00'), $member('4000.00')];
    $q = Quotation::create(['quotation_number' => 'QT-' . Str::random(6), 'source_booking_id' => $m1->id, 'customer_id' => $customer->id, 'truck_type_id' => $m1->truck_type_id,
        'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff', 'distance_km' => 30, 'estimated_price' => '15000', 'additional_fee' => '0', 'discount' => '0',
        'service_type' => 'book_now', 'status' => 'accepted', 'extra_vehicles' => [['booking_id' => $m2->id, 'truck_type_id' => $m2->truck_type_id, 'final_total' => 5000.0], ['booking_id' => $m3->id, 'truck_type_id' => $m3->truck_type_id, 'final_total' => 4000.0]]]);
    foreach ([$m1, $m2, $m3] as $m) {
        $m->update(['quotation_id' => $q->id]);
    }
    $this->actingAs(finUser(2));
    jccAssign($m1->fresh(), $a);
    jccAssign($m2->fresh(), $a);
    jccAssign($m3->fresh(), $b);

    $trip = [$m1->id, $m2->id, $m3->id];
    expect(JobPersonnel::current()->whereIn('booking_id', $trip)->where('unit_id', $a->id)->count())->toBe(4)     // not 8
        ->and(JobPersonnel::current()->whereIn('booking_id', $trip)->where('unit_id', $a->id)->pluck('booking_id')->unique()->all())->toBe([$m1->id])
        ->and(JobPersonnel::current()->whereIn('booking_id', $trip)->where('unit_id', $b->id)->count())->toBe(4);

    // Moving one of unit A's two vehicles away keeps unit A's crew: A still tows the other vehicle.
    jccAssign($m2->fresh(), $b);
    expect(JobPersonnel::current()->whereIn('booking_id', $trip)->where('unit_id', $a->id)->count())->toBe(4)
        ->and(JobPersonnel::current()->whereIn('booking_id', $trip)->where('unit_id', $b->id)->count())->toBe(4);
});

it('confirms only the Team Leader\'s own row when that Team Leader completes the job', function () {
    ['unit' => $unit, 'tl' => $tl] = jccUnit();
    $this->actingAs(finUser(2));
    $booking = jccAssign(jccOpenBooking($unit), $unit);
    $other = jccAssign(jccOpenBooking($unit), $unit);

    // A Dispatcher completing a job is not evidence of who worked it.
    $other->update(['status' => 'waiting_verification']);
    expect(jccCurrent($other)->every(fn ($r) => $r->needs_review))->toBeTrue();

    $this->actingAs($tl);
    $booking->update(['status' => 'accepted']);
    $booking->update(['status' => 'waiting_verification']);

    $rows = jccCurrent($booking);
    expect($rows['team_leader']->needs_review)->toBeFalse()->and($rows['team_leader']->confirmed_by)->toBe($tl->id)->and($rows['team_leader']->confirmed_at)->not->toBeNull()
        ->and($rows->reject(fn ($r) => $r->slot === 'team_leader')->every(fn ($r) => $r->needs_review && $r->confirmed_at === null))->toBeTrue()
        ->and(AuditLog::where('action', 'finance_crew_confirmed')->where('entity_id', $rows['team_leader']->id)->exists())->toBeTrue();
});

it('keeps commission blocked while captured crew is pending, and shows it as pending in Driver Records', function () {
    ['unit' => $unit, 'tl' => $tl, 'driver' => $driver] = jccUnit();
    $owner = finOwner();
    $this->actingAs(finUser(2));
    $booking = jccAssign(jccOpenBooking($unit), $unit);
    $this->actingAs($tl);
    $booking = jccComplete($booking);

    $calc = app(CommissionCalculator::class)->draft(finLastWeek(), $unit->id);
    $line = collect($calc['lines'])->firstWhere('booking_id', $booking->id);
    $driverAmount = collect($line['roles']['driver']['recipients'])->firstWhere('personnel_id', $driver->id)['amount'];
    expect(collect($calc['blockers'])->pluck('code'))->toContain('personnel_needs_review')->toContain('role_mapping_unresolved')
        ->and($calc['can_approve'])->toBeFalse()
        ->and(collect($line['roles']['driver']['recipients'])->first()['pending'])->toBeTrue();

    $records = app(DriverRecordService::class)->build(finLastWeek(), finLastWeek()->addDays(5), null, 'driver');
    $person = $records['people']["personnel:{$driver->id}"];
    expect($person['pending_jobs'])->toBe(1)->and($person['confirmed_jobs'])->toBe(0)
        ->and($person['history'][0]['pending'])->toBeTrue()->and($person['history'][0]['source'])->toBe('Assigned by Dispatch')
        ->and($person['draft']->toString())->toBe($driverAmount->toString())                         // same figure as the calculator
        ->and($records['totals']['pending_participations'])->toBe(1)
        ->and($records['totals']['jobs_without_record'])->toBe(0);

    // The Owner confirms every pending row: the review blocker clears (policy and Rider mapping blockers remain).
    // The second Driver has no role yet: the Owner chooses it with Correct (which records it as confirmed).
    foreach (jccCurrent($booking)->filter->needs_review as $row) {
        $row->commission_role
            ? app(JobCrewService::class)->confirm($owner, $row)
            : app(JobCrewService::class)->correct($owner, $row, ['unit_id' => $unit->id, 'participant' => "personnel:{$row->personnel_id}", 'commission_role' => 'rider'], 'Worked as the Rider on this job');
    }
    $calc = app(CommissionCalculator::class)->draft(finLastWeek(), $unit->id);
    expect(collect($calc['blockers'])->pluck('code'))->not->toContain('personnel_needs_review')
        ->and($calc['can_approve'])->toBeFalse();
    $person = app(DriverRecordService::class)->build(finLastWeek(), finLastWeek()->addDays(5), null, 'driver')['people']["personnel:{$driver->id}"];
    expect($person['confirmed_jobs'])->toBe(1)->and($person['pending_jobs'])->toBe(0);
});

it('takes approved expenses off the sharing base before the per-job draft and the weekly totals', function () {
    ['unit' => $unit, 'driver' => $driver] = jccUnit();
    $this->actingAs(finUser(2));
    $first = jccComplete(jccAssign(jccOpenBooking($unit, '1000.00'), $unit));
    $second = jccComplete(jccAssign(jccOpenBooking($unit, '2000.00'), $unit));
    jccExpense($second, $unit, '500.00');

    $calc = app(CommissionCalculator::class)->draft(finLastWeek(), $unit->id);
    $lines = collect($calc['lines'])->keyBy('booking_id');
    $amountFor = fn ($line) => collect($line['roles']['driver']['recipients'])->firstWhere('personnel_id', $driver->id)['amount'];
    expect($lines[$first->id]['base']->toString())->toBe('1000.00')
        ->and($lines[$second->id]['expense_total']->toString())->toBe('500.00')
        ->and($lines[$second->id]['base']->toString())->toBe('1500.00');

    $records = app(DriverRecordService::class)->build(finLastWeek(), finLastWeek()->addDays(5), null, 'driver');
    $person = $records['people']["personnel:{$driver->id}"];
    $history = collect($person['history'])->keyBy('booking_id');
    $week = finLastWeek()->toDateString();
    expect($person['jobs'])->toBe(2)->and($person['weeks'][$week]['jobs'])->toBe(2)->and($person['weeks'][$week]['vehicles'])->toBe(2)
        ->and($history[$first->id]['draft']->toString())->toBe($amountFor($lines[$first->id])->toString())
        ->and($history[$second->id]['draft']->toString())->toBe($amountFor($lines[$second->id])->toString())
        ->and($person['weeks'][$week]['draft']->toString())->toBe($amountFor($lines[$first->id])->plus($amountFor($lines[$second->id]))->toString())
        ->and($person['draft']->toString())->toBe($person['weeks'][$week]['draft']->toString());
});

it('shows the unit breakdown on Commission (Draft) and pending crew on Driver Records', function () {
    ['unit' => $unit, 'driver' => $driver] = jccUnit();
    $owner = finOwner();
    $this->actingAs(finUser(2));
    $booking = jccComplete(jccAssign(jccOpenBooking($unit), $unit));
    jccExpense($booking, $unit, '200.00');

    $this->actingAs($owner)->get(route('superadmin.finance.commission.index', ['week' => finLastWeek()->toDateString(), 'unit_id' => $unit->id]))->assertOk()
        ->assertSee('data-cm-unit="' . $unit->id . '"', false)
        ->assertSee('data-cm-line="' . $booking->booking_code . '-' . $unit->id . '"', false)
        ->assertSee('data-cm-crew="personnel:' . $driver->id . '"', false)
        ->assertSee('data-cm-crew-state="pending"', false)
        ->assertSee('data-cm-unit-total="' . $unit->id . '"', false)
        ->assertSee(config('finance.expense_categories')['fuel_diesel'])->assertSee('− ₱200.00', false);

    $q = ['from' => finLastWeek()->toDateString(), 'to' => finLastWeek()->addDays(5)->toDateString()];
    $this->actingAs($owner)->get(route('superadmin.finance.driver-records.index', $q))->assertOk()
        ->assertSee('data-dr="pending">1<', false)->assertSee('1 pending');
    $this->actingAs($owner)->get(route('superadmin.finance.driver-records.index', $q + ['person' => "personnel:{$driver->id}"]))->assertOk()
        ->assertSee('data-dr-participation="pending"', false)->assertSee('Assigned by Dispatch')->assertSee('data-dr-week-total', false);
    $this->actingAs($owner)->get(route('superadmin.finance.crew.show', $booking->id))->assertOk()
        ->assertSee('Assigned · not confirmed')->assertSee('Assigned by Dispatch');
});

it('lets only the Owner confirm captured crew', function () {
    ['unit' => $unit, 'tl' => $tl] = jccUnit();
    $this->actingAs(finUser(2));
    $booking = jccAssign(jccOpenBooking($unit), $unit);
    $row = jccCurrent($booking)['driver_1'];

    foreach ([finUser(2), $tl, finUser(4), finUser(6)] as $user) {
        $this->actingAs($user)->post(route('superadmin.finance.crew.confirm', $row->id))->assertForbidden();
    }
    expect($row->fresh()->needs_review)->toBeTrue();

    $this->actingAs(finOwner())->post(route('superadmin.finance.crew.confirm', $row->id))->assertRedirect();
    expect($row->fresh()->needs_review)->toBeFalse()->and($row->fresh()->confirmed_at)->not->toBeNull();
});

it('never blocks Dispatch when crew capture fails', function () {
    ['unit' => $unit] = jccUnit();
    $this->mock(JobCrewCaptureService::class, fn ($m) => $m->shouldReceive('sync')->andThrow(new RuntimeException('capture down')));
    $this->actingAs(finUser(2));
    $booking = jccAssign(jccOpenBooking($unit), $unit);

    expect($booking->assigned_unit_id)->toBe($unit->id)->and($booking->status)->toBe('assigned')
        ->and(JobPersonnel::where('booking_id', $booking->id)->count())->toBe(0);
});
