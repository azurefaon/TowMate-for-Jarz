<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use App\Services\UnitAvailabilityService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function tlmRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 4, 'name' => 'Driver', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function tlmUser(int $roleId, string $name): User
{
    tlmRoles();

    return User::factory()->create(['role_id' => $roleId, 'name' => $name, 'status' => 'active', 'must_change_password' => false]);
}

function tlmTruckType(): TruckType
{
    return TruckType::first() ?? TruckType::create(['name' => 'TLM Truck ' . uniqid(), 'base_rate' => 1500, 'per_km_rate' => 75]);
}

function tlmUnit(?User $leader = null, array $attrs = []): Unit
{
    return Unit::create(array_merge([
        'name' => 'TLM ' . uniqid(),
        'plate_number' => 'TLM-' . random_int(1000, 9999),
        'truck_type_id' => tlmTruckType()->id,
        'team_leader_id' => $leader?->id,
        'driver_name' => 'TLM Driver ' . uniqid(),
        'status' => 'available',
    ], $attrs));
}

function tlmBooking(Unit $unit, ?User $leader, string $status = 'assigned'): Booking
{
    return Booking::create([
        'customer_id' => Customer::create([
            'full_name' => 'TLM Customer', 'age' => 30, 'phone' => '09171234567', 'email' => 'tlm-' . uniqid() . '@example.test',
        ])->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $leader?->id,
        'age' => 30,
        'pickup_address' => 'Quezon City Circle', 'pickup_lat' => 14.676, 'pickup_lng' => 121.0437,
        'dropoff_address' => 'SM Megamall', 'dropoff_lat' => 14.5842, 'dropoff_lng' => 121.0568,
        'distance_km' => 6, 'base_rate' => 1500, 'per_km_rate' => 75,
        'computed_total' => 1950, 'final_total' => 1950, 'vat_exclusive_total' => 1950,
        'status' => $status, 'assigned_at' => now(),
    ]);
}

function tlmMove(User $owner, User $leader, ?Unit $unit)
{
    return test()->actingAs($owner)->patchJson(route('superadmin.personnel.home-unit', $leader), [
        'home_unit_id' => $unit?->id,
    ]);
}

it('moves a team leader between units atomically and every screen agrees', function () {
    $owner = tlmUser(1, 'TLM Owner');
    $dispatcher = tlmUser(2, 'TLM Dispatcher');
    $tl1 = tlmUser(3, 'TLM Leader One');
    $unitA = tlmUnit($tl1);
    $unitB = tlmUnit();
    $tl1->update(['home_unit_id' => $unitA->id]);

    tlmMove($owner, $tl1, $unitB)->assertRedirect();

    // Canonical link: old unit released, new unit received, Regular Unit synced.
    expect($unitA->fresh()->team_leader_id)->toBeNull()
        ->and($unitB->fresh()->team_leader_id)->toBe($tl1->id)
        ->and($tl1->fresh()->home_unit_id)->toBe($unitB->id);

    // Units & Leaders payload.
    $rows = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->viewData('rows');
    expect($rows->get($unitB->id)['unit']->team_leader_id)->toBe($tl1->id)
        ->and($rows->get($unitA->id)['unit']->team_leader_id)->toBeNull();

    // Dispatcher availability uses the same link.
    $availability = app(UnitAvailabilityService::class)->evaluateMany(Unit::whereIn('id', [$unitA->id, $unitB->id])->get());
    expect($availability->get($unitB->id)['available'])->toBeTrue()
        ->and($availability->get($unitA->id)['available'])->toBeFalse()
        ->and($availability->get($unitA->id)['reasons'])->toContain('no_team_leader');

    // Dispatching onto Unit B resolves TL1, and the mobile API returns the task.
    $staleUnit = tlmUnit(tlmUser(3, 'TLM Other Leader'));
    $booking = tlmBooking($staleUnit, null);
    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $booking), [
        'assigned_unit_id' => $unitB->id,
        'reason' => 'Wrong TL / Unit selected',
        'notes' => 'Moving to the unit TL1 now leads.',
    ])->assertOk();

    expect($booking->fresh()->assigned_team_leader_id)->toBe($tl1->id);

    Sanctum::actingAs($tl1, ['*']);
    $this->getJson('/api/v1/team-leader/task')
        ->assertOk()
        ->assertJsonPath('data.booking_code', $booking->fresh()->booking_code);
});

it('does not leak the previous unit or team leader into a new assignment', function () {
    $owner = tlmUser(1, 'TLM Owner');
    $dispatcher = tlmUser(2, 'TLM Dispatcher');
    $tl1 = tlmUser(3, 'TLM Leader One');
    $unitA = tlmUnit($tl1);
    $unitB = tlmUnit();
    tlmMove($owner, $tl1, $unitB)->assertRedirect();

    // Unit A has no TL any more, so it can't take the job and nothing is stale.
    $booking = tlmBooking(tlmUnit(tlmUser(3, 'TLM Filler')), null);
    $this->actingAs($dispatcher)->postJson(route('admin.jobs.reassign', $booking), [
        'assigned_unit_id' => $unitA->id,
        'reason' => 'Wrong TL / Unit selected',
        'notes' => 'Should be rejected: unit has no leader.',
    ])->assertStatus(422);

    expect($booking->fresh()->assigned_team_leader_id)->not->toBe($tl1->id);

    Sanctum::actingAs($tl1, ['*']);
    $this->getJson('/api/v1/team-leader/task')->assertOk()->assertJsonPath('data', null);
});

it('refuses to move a team leader onto a unit that already has another team leader', function () {
    $owner = tlmUser(1, 'TLM Owner');
    $tl1 = tlmUser(3, 'TLM Leader One');
    $tl2 = tlmUser(3, 'TLM Leader Two');
    $unitA = tlmUnit($tl1);
    $unitB = tlmUnit($tl2);

    tlmMove($owner, $tl1, $unitB)->assertStatus(422);

    expect($unitA->fresh()->team_leader_id)->toBe($tl1->id)
        ->and($unitB->fresh()->team_leader_id)->toBe($tl2->id)
        ->and($tl1->fresh()->home_unit_id)->toBeNull();
});

it('refuses to move a team leader who is on an active job', function () {
    $owner = tlmUser(1, 'TLM Owner');
    $tl1 = tlmUser(3, 'TLM Leader One');
    $unitA = tlmUnit($tl1, ['status' => 'on_job']);
    $unitB = tlmUnit();
    tlmBooking($unitA, $tl1, 'on_the_way');

    tlmMove($owner, $tl1, $unitB)->assertStatus(422);

    expect($unitA->fresh()->team_leader_id)->toBe($tl1->id)
        ->and($unitB->fresh()->team_leader_id)->toBeNull();
});

it('closes an open borrow loan when the regular unit is moved so a later return cannot undo it', function () {
    $owner = tlmUser(1, 'TLM Owner');
    $tl1 = tlmUser(3, 'TLM Leader One');
    $home = tlmUnit();
    $away = tlmUnit($tl1);
    $newHome = tlmUnit();
    $loan = UnitCrewLoan::create([
        'from_unit_id' => $home->id, 'to_unit_id' => $away->id,
        'from_slot' => 'team_leader', 'to_slot' => 'team_leader',
        'person_name' => 'TLM Leader One', 'person_user_id' => $tl1->id, 'borrowed_at' => now(),
    ]);

    tlmMove($owner, $tl1, $newHome)->assertRedirect();

    expect($loan->fresh()->returned_at)->not->toBeNull()
        ->and($away->fresh()->team_leader_id)->toBeNull()
        ->and($newHome->fresh()->team_leader_id)->toBe($tl1->id);
});

it('does not treat a unit with an archived or dangling team leader as dispatchable', function () {
    $archived = tlmUser(3, 'TLM Archived Leader');
    $unit = tlmUnit($archived);
    $archived->update(['archived_at' => now()]);

    $row = app(UnitAvailabilityService::class)->evaluate($unit->fresh());

    expect($row['available'])->toBeFalse()
        ->and($row['reasons'])->toContain('no_team_leader');
});

it('offers an active unassigned driver and excludes invalid or busy ones entirely', function () {
    $dispatcher = tlmUser(2, 'TLM Dispatcher');
    $target = tlmUnit(tlmUser(3, 'TLM Target Leader'), ['driver_name' => null]);

    $free = Personnel::create(['first_name' => 'Free', 'last_name' => 'Driver', 'role' => 'driver', 'personnel_status' => 'active']);
    Personnel::create(['first_name' => 'Off', 'last_name' => 'Driver', 'role' => 'driver', 'personnel_status' => 'inactive']);
    Personnel::create(['first_name' => 'Some', 'last_name' => 'Crew', 'role' => 'crew', 'personnel_status' => 'active']);

    $busyDriver = Personnel::create(['first_name' => 'Busy', 'last_name' => 'Driver', 'role' => 'driver', 'personnel_status' => 'active']);
    $busyLeader = tlmUser(3, 'TLM Busy Leader');
    $busyUnit = tlmUnit($busyLeader, ['driver_name' => $busyDriver->full_name, 'driver_personnel_id' => $busyDriver->id, 'status' => 'on_job']);
    tlmBooking($busyUnit, $busyLeader, 'on_the_way');

    $people = collect(
        $this->actingAs($dispatcher)
            ->getJson(route('admin.drivers.eligible-people', ['role' => 'driver', 'exclude_unit_id' => $target->id]))
            ->assertOk()->json('people')
    )->keyBy('personnel_id');

    expect($people->keys()->all())->toEqual([$free->id])
        ->and($people[$free->id]['eligible'])->toBeTrue()
        ->and($people[$free->id]['source_unit_id'])->toBeNull();

    // The unassigned driver can then actually be assigned to the empty slot.
    $this->actingAs($dispatcher)->postJson(route('admin.drivers.units.assign-slot', $target), [
        'to_slot' => 'driver_1',
        'personnel_id' => $free->id,
    ])->assertOk()->assertJsonPath('success', true);

    expect($target->fresh()->driver_personnel_id)->toBe($free->id)
        ->and($target->fresh()->driver_name)->toBe('Free Driver');

    // Now placed, so no longer offered as unassigned.
    $after = collect(
        $this->actingAs($dispatcher)
            ->getJson(route('admin.drivers.eligible-people', ['role' => 'driver']))->json('people')
    );
    expect($after->where('personnel_id', $free->id)->where('source_unit_id', null)->isEmpty())->toBeTrue();
});

it('refuses to assign an inactive or already-placed personnel record as unassigned', function () {
    $dispatcher = tlmUser(2, 'TLM Dispatcher');
    $target = tlmUnit(tlmUser(3, 'TLM Target Leader'), ['driver_name' => null]);
    $inactive = Personnel::create(['first_name' => 'Off', 'last_name' => 'Driver', 'role' => 'driver', 'personnel_status' => 'inactive']);
    $placed = Personnel::create(['first_name' => 'Placed', 'last_name' => 'Driver', 'role' => 'driver', 'personnel_status' => 'active']);
    tlmUnit(null, ['driver_name' => $placed->full_name, 'driver_personnel_id' => $placed->id]);

    foreach ([$inactive, $placed] as $person) {
        $this->actingAs($dispatcher)->postJson(route('admin.drivers.units.assign-slot', $target), [
            'to_slot' => 'driver_1',
            'personnel_id' => $person->id,
        ])->assertStatus(422);
    }

    expect($target->fresh()->driver_personnel_id)->toBeNull();
});

function ipPeople(User $dispatcher, int $excludeUnitId): \Illuminate\Support\Collection
{
    return collect(
        test()->actingAs($dispatcher)
            ->getJson(route('admin.drivers.eligible-people', ['role' => 'driver', 'exclude_unit_id' => $excludeUnitId]))
            ->assertOk()->json('people')
    );
}

function ipDriver(string $first = 'Kevin Michael', string $last = 'Camaya'): Personnel
{
    return Personnel::create(['first_name' => $first, 'last_name' => $last, 'role' => 'driver', 'personnel_status' => 'active']);
}

it('excludes a driver borrowed to a busy unit and never labels them Unassigned', function () {
    $dispatcher = tlmUser(2, 'TLM Dispatcher');
    $kevin = ipDriver();
    $home = tlmUnit(tlmUser(3, 'TLM Home Leader'), ['driver_name' => $kevin->full_name, 'driver_personnel_id' => $kevin->id]);
    $busyLeader = tlmUser(3, 'TLM Busy Leader');
    $busyUnit = tlmUnit($busyLeader, ['driver_name' => null, 'status' => 'on_job']);
    $target = tlmUnit(tlmUser(3, 'TLM Target Leader'), ['driver_name' => null]);

    app(\App\Services\UnitTeamAssignmentService::class)->assignSlotPerson($busyUnit, 'driver_1', $home, 'driver_1', $dispatcher);
    tlmBooking($busyUnit, $busyLeader, 'on_the_way');

    $people = ipPeople($dispatcher, $target->id);

    expect($people->where('personnel_id', $kevin->id)->isEmpty())->toBeTrue()
        ->and($people->where('source_unit_name', 'Unassigned')->where('name', $kevin->full_name)->isEmpty())->toBeTrue();

    // Assigning him directly is refused server-side too.
    $this->actingAs($dispatcher)->postJson(route('admin.drivers.units.assign-slot', $target), [
        'to_slot' => 'driver_1', 'personnel_id' => $kevin->id,
    ])->assertStatus(422);
});

it('excludes a driver on a name-only loan or duplicate personnel record, and frees them when it ends', function () {
    $dispatcher = tlmUser(2, 'TLM Dispatcher');
    $kevin = ipDriver();
    $home = tlmUnit(tlmUser(3, 'TLM Home Leader'));
    $away = tlmUnit(tlmUser(3, 'TLM Away Leader'), ['driver_name' => 'Kevin Michael Camaya']); // legacy: no personnel id
    $target = tlmUnit(tlmUser(3, 'TLM Target Leader'), ['driver_name' => null]);
    $loan = UnitCrewLoan::create([
        'from_unit_id' => $home->id, 'to_unit_id' => $away->id,
        'from_slot' => 'driver_1', 'to_slot' => 'driver_1',
        'person_name' => 'Kevin Michael Camaya', 'borrowed_at' => now(),
    ]);

    expect(ipPeople($dispatcher, $target->id)->where('personnel_id', $kevin->id)->isEmpty())->toBeTrue();

    // Loan ends and the legacy slot is cleared -> genuinely free again.
    $loan->update(['returned_at' => now()]);
    $away->update(['driver_name' => null]);

    $row = ipPeople($dispatcher, $target->id)->firstWhere('personnel_id', $kevin->id);
    expect($row)->not->toBeNull()->and($row['source_unit_name'])->toBe('Unassigned')->and($row['eligible'])->toBeTrue();
});

it('excludes a driver named on an active job and frees them when the job ends', function () {
    $dispatcher = tlmUser(2, 'TLM Dispatcher');
    $kevin = ipDriver();
    $leader = tlmUser(3, 'TLM Job Leader');
    $jobUnit = tlmUnit($leader, ['status' => 'on_job']);
    $booking = tlmBooking($jobUnit, $leader, 'on_the_way');
    $booking->update(['driver_name' => $kevin->full_name]);
    $target = tlmUnit(tlmUser(3, 'TLM Target Leader'), ['driver_name' => null]);

    expect(ipPeople($dispatcher, $target->id)->where('personnel_id', $kevin->id)->isEmpty())->toBeTrue();

    $booking->update(['status' => 'completed']);

    expect(ipPeople($dispatcher, $target->id)->where('personnel_id', $kevin->id)->isNotEmpty())->toBeTrue();
});

it('shows a driver placed on a free unit under that unit, not Unassigned, and a returned borrow goes home', function () {
    $dispatcher = tlmUser(2, 'TLM Dispatcher');
    $kevin = ipDriver();
    $home = tlmUnit(tlmUser(3, 'TLM Home Leader'), ['driver_name' => $kevin->full_name, 'driver_personnel_id' => $kevin->id]);
    $away = tlmUnit(tlmUser(3, 'TLM Away Leader'), ['driver_name' => null]);
    $target = tlmUnit(tlmUser(3, 'TLM Target Leader'), ['driver_name' => null]);
    $service = app(\App\Services\UnitTeamAssignmentService::class);

    $row = ipPeople($dispatcher, $target->id)->firstWhere('personnel_id', $kevin->id);
    expect($row['source_unit_id'])->toBe($home->id)->and($row['source_unit_name'])->toBe($home->name);

    $service->assignSlotPerson($away, 'driver_1', $home, 'driver_1', $dispatcher);
    expect(ipPeople($dispatcher, $target->id)->where('personnel_id', $kevin->id)->isEmpty())->toBeTrue();

    $service->returnSlotPerson(UnitCrewLoan::firstOrFail(), $dispatcher);
    $row = ipPeople($dispatcher, $target->id)->firstWhere('personnel_id', $kevin->id);
    expect($row['source_unit_id'])->toBe($home->id);
});
