<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use App\Services\PersonnelReconciliationService;
use App\Services\UnitTeamAssignmentService;
use Illuminate\Support\Facades\DB;

function pbaRoles(): void
{
    foreach ([1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader', 4 => 'Driver', 5 => 'Customer', 6 => 'System Admin'] as $id => $name) {
        if (! Role::find($id)) {
            $role = new Role(['name' => $name]);
            $role->id = $id;
            $role->save();
        }
    }

    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
    }
}

function pbaDispatcher(): User
{
    pbaRoles();

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function pbaPerson(string $first, string $last, string $role = 'crew', string $status = 'active'): Personnel
{
    return Personnel::create(['first_name' => $first, 'last_name' => $last, 'role' => $role, 'personnel_status' => $status]);
}

function pbaUnit(array $attrs = []): Unit
{
    $truckType = TruckType::create(['name' => 'PBA Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);

    return Unit::create(array_merge([
        'name' => 'PBA ' . fake()->unique()->numerify('####'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ], $attrs));
}

function pbaAssignSeeded(Unit $unit, int $leaderId, User $actor): void
{
    app(UnitTeamAssignmentService::class)->assignTeamLeader($unit, $leaderId, $actor);

    $leader = User::findOrFail($leaderId);
    $seeds = [
        'driver_1' => 'driver_personnel_id',
        'crew_member_1' => 'crew_member_1_personnel_id',
        'crew_member_2' => 'crew_member_2_personnel_id',
    ];

    foreach ($seeds as $slot => $stagedColumn) {
        if ($leader->{$stagedColumn}) {
            $person = Personnel::findOrFail($leader->{$stagedColumn});
            $unit->update([
                Unit::SLOT_COLUMNS[$slot] => $person->full_name,
                Unit::SLOT_PERSONNEL_COLUMNS[$slot] => $person->id,
                Unit::SLOT_SEED_COLUMNS[$slot] => $leader->id,
            ]);
        }
    }
}

function pbaPlaced(Personnel $person, string $slot, array $attrs = []): Unit
{
    return pbaUnit(array_merge([
        Unit::SLOT_COLUMNS[$slot] => $person->full_name,
        Unit::SLOT_PERSONNEL_COLUMNS[$slot] => $person->id,
    ], $attrs));
}

function pbaSystemAdmin(): User
{
    pbaRoles();

    return User::factory()->create(['role_id' => 6, 'status' => 'active', 'must_change_password' => false]);
}

function pbaTlPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Tee',
        'last_name' => 'Ell',
        'email' => 'tl' . uniqid() . '@gmail.com',
        'phone' => '091' . fake()->unique()->numerify('########'),
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role_id' => 3,
    ], $overrides);
}

it('offers an active registered crew member in a unit slot to the assignment search', function () {
    $dispatcher = pbaDispatcher();
    $person = pbaPerson('Carlo', 'Reyes');
    pbaPlaced($person, 'crew_member_1');

    $response = $this->actingAs($dispatcher)->getJson(route('admin.drivers.eligible-people', ['role' => 'crew']));

    $response->assertOk();
    expect(collect($response->json('people'))->pluck('personnel_id')->all())->toContain($person->id);
});

it('does not offer inactive personnel to the assignment search', function () {
    $dispatcher = pbaDispatcher();
    $person = pbaPerson('Idle', 'Crew', 'crew', 'inactive');
    pbaPlaced($person, 'crew_member_1');

    $response = $this->actingAs($dispatcher)->getJson(route('admin.drivers.eligible-people', ['role' => 'crew']));

    expect($response->json('people'))->toBe([]);
});

it('does not offer a free-text legacy name with no personnel record', function () {
    $dispatcher = pbaDispatcher();
    pbaUnit(['crew_member_1_name' => 'Ghost Crew', 'driver_name' => 'Ghost Driver']);

    $crew = $this->actingAs($dispatcher)->getJson(route('admin.drivers.eligible-people', ['role' => 'crew']));
    $driver = $this->actingAs($dispatcher)->getJson(route('admin.drivers.eligible-people', ['role' => 'driver']));

    expect($crew->json('people'))->toBe([])
        ->and($driver->json('people'))->toBe([]);
});

it('does not offer a crew personnel record in a driver search', function () {
    $dispatcher = pbaDispatcher();
    $person = pbaPerson('Wrong', 'Role', 'crew');
    pbaUnit([
        'driver_name' => $person->full_name,
        'driver_personnel_id' => $person->id,
    ]);

    $response = $this->actingAs($dispatcher)->getJson(route('admin.drivers.eligible-people', ['role' => 'driver']));

    expect($response->json('people'))->toBe([]);
});

it('refuses to borrow a legacy slot that has no personnel record', function () {
    $dispatcher = pbaDispatcher();
    $source = pbaUnit(['crew_member_1_name' => 'Ghost Crew']);
    $target = pbaUnit();

    expect(fn () => app(UnitTeamAssignmentService::class)->assignSlotPerson($target, 'crew_member_1', $source, 'crew_member_1', $dispatcher))
        ->toThrow(RuntimeException::class, 'not a registered Personnel record');

    expect($source->fresh()->crew_member_1_name)->toBe('Ghost Crew')
        ->and($target->fresh()->crew_member_1_name)->toBeNull()
        ->and(UnitCrewLoan::count())->toBe(0);
});

it('refuses to borrow inactive personnel', function () {
    $dispatcher = pbaDispatcher();
    $person = pbaPerson('Off', 'Duty', 'crew', 'inactive');
    $source = pbaPlaced($person, 'crew_member_1');
    $target = pbaUnit();

    expect(fn () => app(UnitTeamAssignmentService::class)->assignSlotPerson($target, 'crew_member_1', $source, 'crew_member_1', $dispatcher))
        ->toThrow(RuntimeException::class, 'inactive');

    expect($source->fresh()->crew_member_1_personnel_id)->toBe($person->id);
});

it('moves the personnel id with a borrow, stores it on the loan, and restores it on return', function () {
    $dispatcher = pbaDispatcher();
    $person = pbaPerson('Vince', 'Aquino');
    $source = pbaPlaced($person, 'crew_member_1');
    $target = pbaUnit();
    $service = app(UnitTeamAssignmentService::class);

    $service->assignSlotPerson($target, 'crew_member_2', $source, 'crew_member_1', $dispatcher);

    $loan = UnitCrewLoan::firstOrFail();
    expect($loan->personnel_id)->toBe($person->id)
        ->and($loan->person_name)->toBe('Vince Aquino')
        ->and($source->fresh()->crew_member_1_personnel_id)->toBeNull()
        ->and($target->fresh()->crew_member_2_personnel_id)->toBe($person->id)
        ->and($target->fresh()->crew_member_2_name)->toBe('Vince Aquino');

    $service->returnSlotPerson($loan, $dispatcher);

    expect($source->fresh()->crew_member_1_personnel_id)->toBe($person->id)
        ->and($target->fresh()->crew_member_2_personnel_id)->toBeNull()
        ->and($target->fresh()->crew_member_2_name)->toBeNull();
});

it('keeps a historical name-only loan readable and returnable without inventing an identity', function () {
    $dispatcher = pbaDispatcher();
    $home = pbaUnit();
    $away = pbaUnit(['crew_member_1_name' => 'Old Timer']);
    $loan = UnitCrewLoan::create([
        'from_unit_id' => $home->id, 'to_unit_id' => $away->id,
        'from_slot' => 'crew_member_1', 'to_slot' => 'crew_member_1',
        'person_name' => 'Old Timer', 'borrowed_at' => now(), 'created_by' => $dispatcher->id,
    ]);

    app(UnitTeamAssignmentService::class)->returnSlotPerson($loan, $dispatcher);

    expect($loan->fresh()->person_name)->toBe('Old Timer')
        ->and($loan->fresh()->personnel_id)->toBeNull()
        ->and($home->fresh()->crew_member_1_name)->toBe('Old Timer')
        ->and($home->fresh()->crew_member_1_personnel_id)->toBeNull()
        ->and($home->fresh()->slotIsLegacy('crew_member_1'))->toBeTrue();
});

it('blocks a whole-team transfer that would carry an unregistered name', function () {
    $dispatcher = pbaDispatcher();
    $source = pbaUnit(['crew_member_1_name' => 'Ghost Crew']);
    $target = pbaUnit();

    expect(fn () => app(UnitTeamAssignmentService::class)->transferTeam($source, $target, $dispatcher))
        ->toThrow(RuntimeException::class, 'not a registered Personnel record');

    expect($source->fresh()->crew_member_1_name)->toBe('Ghost Crew');
});

it('removing a person from a slot clears the id but keeps the loan history', function () {
    $dispatcher = pbaDispatcher();
    $person = pbaPerson('Rico', 'Santos');
    $unit = pbaPlaced($person, 'crew_member_1');

    app(UnitTeamAssignmentService::class)->removeSlotPerson($unit, 'crew_member_1', $dispatcher);

    expect($unit->fresh()->crew_member_1_personnel_id)->toBeNull()
        ->and($unit->fresh()->crew_member_1_name)->toBeNull()
        ->and(Personnel::find($person->id))->not->toBeNull();
});

it('resolves the owner truck team display through ids and flags unlinked legacy names', function () {
    pbaRoles();
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
    $person = pbaPerson('Linked', 'Crew');
    pbaPlaced($person, 'crew_member_1');
    pbaUnit(['crew_member_1_name' => 'Ghost Crewman']);

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk()->assertSee('Linked Crew')->assertSee('Ghost Crewman')->assertSee('Unlinked');
});

it('shows personnel as assigned only through the id link, not a matching name', function () {
    pbaRoles();
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
    $person = pbaPerson('Same', 'Name');
    $unit = pbaUnit(['crew_member_1_name' => 'Same Name']);

    $service = app(\App\Services\PersonnelService::class);
    $row = $service->listPersonnel('Same Name')->first();
    expect($row['current_unit'])->toBeNull();

    $unit->update(['crew_member_1_personnel_id' => $person->id]);
    $row = $service->listPersonnel('Same Name')->first();
    expect($row['current_unit']->id)->toBe($unit->id);
});

it('keeps unit snapshots in sync when a personnel record is renamed and blocks role changes while placed', function () {
    pbaRoles();
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
    $person = pbaPerson('Old', 'Name');
    $unit = pbaPlaced($person, 'crew_member_1');

    $this->actingAs($owner)
        ->put(route('superadmin.personnel-records.update', $person), ['first_name' => 'New', 'last_name' => 'Name', 'role' => 'crew']);
    expect($unit->fresh()->crew_member_1_name)->toBe('New Name');

    $this->actingAs($owner)
        ->put(route('superadmin.personnel-records.update', $person), ['first_name' => 'New', 'last_name' => 'Name', 'role' => 'driver'])
        ->assertSessionHasErrors('role');
    expect($person->fresh()->role)->toBe('crew');
});

it('blocks adding a personnel driver that duplicates an existing driver account', function () {
    pbaRoles();
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
    User::factory()->create(['role_id' => 4, 'name' => 'Paolo Cruz', 'first_name' => 'Paolo', 'last_name' => 'Cruz']);

    $this->actingAs($owner)
        ->post(route('superadmin.personnel-records.store'), ['first_name' => 'Paolo', 'last_name' => 'Cruz', 'role' => 'driver'])
        ->assertSessionHasErrors('first_name');

    expect(Personnel::where('first_name', 'Paolo')->exists())->toBeFalse();
});

it('reconciliation links only exact unique active matches and never guesses', function () {
    pbaRoles();
    $unique = pbaPerson('Unique', 'Match');
    pbaPerson('Twin', 'Person');
    pbaPerson('Twin', 'Person');
    pbaPerson('Sleeping', 'Crew', 'crew', 'inactive');
    pbaPerson('Shared', 'Name');

    $a = pbaUnit(['crew_member_1_name' => ' unique   MATCH ']);
    $b = pbaUnit(['crew_member_1_name' => 'Twin Person']);
    $c = pbaUnit(['crew_member_1_name' => 'Sleeping Crew']);
    $d = pbaUnit(['crew_member_1_name' => 'Nobody Known']);
    $e = pbaUnit(['crew_member_1_name' => 'Shared Name']);
    $f = pbaUnit(['crew_member_1_name' => 'Shared Name']);
    $g = pbaUnit(['driver_name' => 'Unique Match']);

    $linked = app(PersonnelReconciliationService::class)->apply();

    expect($linked)->toBe(1)
        ->and($a->fresh()->crew_member_1_personnel_id)->toBe($unique->id)
        ->and($b->fresh()->crew_member_1_personnel_id)->toBeNull()
        ->and($c->fresh()->crew_member_1_personnel_id)->toBeNull()
        ->and($d->fresh()->crew_member_1_personnel_id)->toBeNull()
        ->and($e->fresh()->crew_member_1_personnel_id)->toBeNull()
        ->and($f->fresh()->crew_member_1_personnel_id)->toBeNull()
        ->and($g->fresh()->driver_personnel_id)->toBeNull()
        ->and($a->fresh()->crew_member_1_name)->toBe(' unique   MATCH ');
});

it('reconciliation links an active loan only when its unit slot resolved to the same person', function () {
    pbaRoles();
    $person = pbaPerson('Loan', 'Person');
    $home = pbaUnit();
    $away = pbaUnit(['crew_member_2_name' => 'Loan Person']);
    $loan = UnitCrewLoan::create([
        'from_unit_id' => $home->id, 'to_unit_id' => $away->id,
        'from_slot' => 'crew_member_1', 'to_slot' => 'crew_member_2',
        'person_name' => 'Loan Person', 'borrowed_at' => now(),
    ]);

    app(PersonnelReconciliationService::class)->apply();

    expect($loan->fresh()->personnel_id)->toBe($person->id)
        ->and($away->fresh()->crew_member_2_personnel_id)->toBe($person->id);
});

it('clears the current ids and name snapshots of the team when its team leader is removed', function () {
    $dispatcher = pbaDispatcher();
    $driver = pbaPerson('Drive', 'Er', 'driver');
    $crew1 = pbaPerson('Crew', 'One');
    $crew2 = pbaPerson('Crew', 'Two');
    $leader = User::factory()->create([
        'role_id' => 3,
        'driver_personnel_id' => $driver->id,
        'crew_member_1_personnel_id' => $crew1->id,
        'crew_member_2_personnel_id' => $crew2->id,
    ]);
    $unit = pbaUnit();
    $service = app(UnitTeamAssignmentService::class);
    pbaAssignSeeded($unit, $leader->id, $dispatcher);
    expect($unit->fresh()->crew_member_1_name)->toBe('Crew One');

    $service->removeTeamLeader($unit, $dispatcher);

    $fresh = $unit->fresh();
    expect($fresh->team_leader_id)->toBeNull()
        ->and($fresh->driver_personnel_id)->toBeNull()
        ->and($fresh->driver_name)->toBeNull()
        ->and($fresh->crew_member_1_personnel_id)->toBeNull()
        ->and($fresh->crew_member_1_name)->toBeNull()
        ->and($fresh->crew_member_2_personnel_id)->toBeNull()
        ->and($fresh->crew_member_2_name)->toBeNull()
        ->and(Personnel::whereIn('id', [$driver->id, $crew1->id, $crew2->id])->count())->toBe(3);

    pbaRoles();
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
    $this->actingAs($owner)->get(route('superadmin.unit-truck.index'))
        ->assertOk()
        ->assertDontSee('Crew One')
        ->assertDontSee('Crew Two')
        ->assertDontSee('Drive Er');
});

it('clears legacy-only names attached to the removed team but not unrelated slots or loans', function () {
    $dispatcher = pbaDispatcher();
    $borrowed = pbaPerson('Borrowed', 'Crew');
    $leader = User::factory()->create([
        'role_id' => 3,
        'driver_first_name' => 'Legacy', 'driver_last_name' => 'Driver',
        'crew_member_1_name' => 'Legacy Crew',
    ]);
    $home = pbaPlaced($borrowed, 'crew_member_1');
    $unit = pbaUnit([
        'team_leader_id' => $leader->id,
        'driver_name' => 'Legacy Driver',
        'crew_member_1_name' => 'Legacy Crew',
        'driver_2_name' => 'Unrelated Legacy',
    ]);
    $service = app(UnitTeamAssignmentService::class);
    $service->assignSlotPerson($unit, 'crew_member_2', $home, 'crew_member_1', $dispatcher);
    $loan = UnitCrewLoan::firstOrFail();

    $service->removeTeamLeader($unit, $dispatcher);

    $fresh = $unit->fresh();
    expect($fresh->driver_name)->toBeNull()
        ->and($fresh->crew_member_1_name)->toBeNull()
        ->and($fresh->driver_2_name)->toBe('Unrelated Legacy')
        ->and($fresh->crew_member_2_name)->toBe('Borrowed Crew')
        ->and($fresh->crew_member_2_personnel_id)->toBe($borrowed->id)
        ->and($loan->fresh()->person_name)->toBe('Borrowed Crew')
        ->and($loan->fresh()->personnel_id)->toBe($borrowed->id);
});

it('keeps the loan name snapshot readable after the person leaves the unit and the team is removed', function () {
    $dispatcher = pbaDispatcher();
    $person = pbaPerson('Snap', 'Shot');
    $leader = User::factory()->create(['role_id' => 3, 'crew_member_1_personnel_id' => $person->id]);
    $home = pbaUnit();
    $unit = pbaUnit();
    $service = app(UnitTeamAssignmentService::class);
    pbaAssignSeeded($unit, $leader->id, $dispatcher);
    $service->assignSlotPerson($home, 'crew_member_2', $unit, 'crew_member_1', $dispatcher);

    $service->removeTeamLeader($unit, $dispatcher);

    $loan = UnitCrewLoan::firstOrFail();
    expect($loan->person_name)->toBe('Snap Shot')
        ->and($loan->personnel_id)->toBe($person->id)
        ->and($unit->fresh()->crew_member_1_name)->toBeNull()
        ->and($home->fresh()->crew_member_2_name)->toBe('Snap Shot');
});

function pbaSeededLeader(array $staged = []): User
{
    return User::factory()->create(array_merge(['role_id' => 3], $staged));
}

it('clears the originally seeded crew on removal even after the staged crew was edited', function () {
    $dispatcher = pbaDispatcher();
    $crewA = pbaPerson('Crew', 'Aye');
    $crewB = pbaPerson('Crew', 'Bee');
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $crewA->id]);
    $unit = pbaUnit();
    $service = app(UnitTeamAssignmentService::class);
    pbaAssignSeeded($unit, $leader->id, $dispatcher);

    $leader->update(['crew_member_1_personnel_id' => $crewB->id, 'crew_member_1_name' => 'Crew Bee']);
    expect($unit->fresh()->crew_member_1_personnel_id)->toBe($crewA->id);

    $service->removeTeamLeader($unit, $dispatcher);

    $fresh = $unit->fresh();
    expect($fresh->crew_member_1_personnel_id)->toBeNull()
        ->and($fresh->crew_member_1_name)->toBeNull()
        ->and($fresh->crew_member_1_seeded_by_team_leader_id)->toBeNull();
});

it('clears the originally seeded driver on removal even after the staged driver was edited', function () {
    $dispatcher = pbaDispatcher();
    $driverA = pbaPerson('Driver', 'Aye', 'driver');
    $driverB = pbaPerson('Driver', 'Bee', 'driver');
    $leader = pbaSeededLeader(['driver_personnel_id' => $driverA->id]);
    $unit = pbaUnit();
    $service = app(UnitTeamAssignmentService::class);
    pbaAssignSeeded($unit, $leader->id, $dispatcher);

    $leader->update(['driver_personnel_id' => $driverB->id]);
    expect($unit->fresh()->driver_personnel_id)->toBe($driverA->id);

    $service->removeTeamLeader($unit, $dispatcher);

    expect($unit->fresh()->driver_personnel_id)->toBeNull()
        ->and($unit->fresh()->driver_name)->toBeNull()
        ->and($unit->fresh()->driver_seeded_by_team_leader_id)->toBeNull();
});

it('does not clear a manually assigned slot, another leaders slot, or driver 2 on removal', function () {
    $dispatcher = pbaDispatcher();
    $mine = pbaPerson('Mine', 'Crew');
    $other = pbaPerson('Other', 'Crew');
    $manual = pbaPerson('Manual', 'Crew');
    $driver2 = pbaPerson('Second', 'Driver', 'driver');
    $otherLeader = pbaSeededLeader();
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $mine->id]);
    $unit = pbaUnit([
        'crew_member_2_name' => $other->full_name,
        'crew_member_2_personnel_id' => $other->id,
        'crew_member_2_seeded_by_team_leader_id' => $otherLeader->id,
        'driver_2_name' => $driver2->full_name,
        'driver_2_personnel_id' => $driver2->id,
    ]);
    $service = app(UnitTeamAssignmentService::class);
    pbaAssignSeeded($unit, $leader->id, $dispatcher);

    $service->removeTeamLeader($unit, $dispatcher);

    $fresh = $unit->fresh();
    expect($fresh->crew_member_1_personnel_id)->toBeNull()
        ->and($fresh->crew_member_2_personnel_id)->toBe($other->id)
        ->and($fresh->crew_member_2_seeded_by_team_leader_id)->toBe($otherLeader->id)
        ->and($fresh->driver_2_personnel_id)->toBe($driver2->id);

    $manualUnit = pbaUnit([
        'team_leader_id' => $leader->id,
        'crew_member_1_name' => $manual->full_name,
        'crew_member_1_personnel_id' => $manual->id,
        'crew_member_1_seeded_by_team_leader_id' => $otherLeader->id,
    ]);
    $service->removeTeamLeader($manualUnit, $dispatcher);
    expect($manualUnit->fresh()->crew_member_1_personnel_id)->toBe($manual->id);
});

it('protects an actively loaned slot from team leader removal and keeps seed ownership across borrow and return', function () {
    $dispatcher = pbaDispatcher();
    $crew = pbaPerson('Loaned', 'Crew');
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $crew->id]);
    $home = pbaUnit();
    $away = pbaUnit();
    $service = app(UnitTeamAssignmentService::class);
    pbaAssignSeeded($home, $leader->id, $dispatcher);

    $service->assignSlotPerson($away, 'crew_member_2', $home, 'crew_member_1', $dispatcher);

    $loan = UnitCrewLoan::firstOrFail();
    expect($loan->seeded_by_team_leader_id)->toBe($leader->id)
        ->and($home->fresh()->crew_member_1_seeded_by_team_leader_id)->toBeNull()
        ->and($away->fresh()->crew_member_2_personnel_id)->toBe($crew->id)
        ->and($away->fresh()->crew_member_2_seeded_by_team_leader_id)->toBeNull();

    $awayLeader = pbaSeededLeader(['crew_member_2_personnel_id' => $crew->id]);
    $away->update(['team_leader_id' => $awayLeader->id]);
    $service->removeTeamLeader($away, $dispatcher);
    expect($away->fresh()->crew_member_2_personnel_id)->toBe($crew->id);

    $service->returnSlotPerson($loan, $dispatcher);

    expect($home->fresh()->crew_member_1_personnel_id)->toBe($crew->id)
        ->and($home->fresh()->crew_member_1_seeded_by_team_leader_id)->toBe($leader->id)
        ->and($away->fresh()->crew_member_2_personnel_id)->toBeNull()
        ->and($loan->fresh()->person_name)->toBe('Loaned Crew');
});

it('drops seed ownership when a slot is removed and refilled so the old leader cannot clear the new person', function () {
    $dispatcher = pbaDispatcher();
    $seeded = pbaPerson('Seeded', 'Crew');
    $replacement = pbaPerson('Replacement', 'Crew');
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $seeded->id]);
    $unit = pbaUnit();
    $donor = pbaPlaced($replacement, 'crew_member_1');
    $service = app(UnitTeamAssignmentService::class);
    pbaAssignSeeded($unit, $leader->id, $dispatcher);

    $service->removeSlotPerson($unit, 'crew_member_1', $dispatcher);
    expect($unit->fresh()->crew_member_1_seeded_by_team_leader_id)->toBeNull();

    $service->assignSlotPerson($unit, 'crew_member_1', $donor, 'crew_member_1', $dispatcher);
    UnitCrewLoan::query()->update(['returned_at' => now()]);

    $service->removeTeamLeader($unit, $dispatcher);

    expect($unit->fresh()->crew_member_1_personnel_id)->toBe($replacement->id);
});

it('moves slots on whole-team transfer without leaving seed ownership on the destination', function () {
    $dispatcher = pbaDispatcher();
    $crew = pbaPerson('Moved', 'Crew');
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $crew->id]);
    $source = pbaUnit();
    $target = pbaUnit();
    $service = app(UnitTeamAssignmentService::class);
    pbaAssignSeeded($source, $leader->id, $dispatcher);

    $service->transferTeam($source, $target, $dispatcher);

    $loan = UnitCrewLoan::where('from_slot', 'crew_member_1')->firstOrFail();
    expect($loan->seeded_by_team_leader_id)->toBe($leader->id)
        ->and($target->fresh()->crew_member_1_personnel_id)->toBe($crew->id)
        ->and($target->fresh()->crew_member_1_seeded_by_team_leader_id)->toBeNull()
        ->and($source->fresh()->crew_member_1_seeded_by_team_leader_id)->toBeNull();
});

it('still clears a legacy slot with no seed owner by the current staged id', function () {
    $dispatcher = pbaDispatcher();
    $crew = pbaPerson('Legacy', 'Seeded');
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $crew->id]);
    $unit = pbaUnit([
        'team_leader_id' => $leader->id,
        'crew_member_1_name' => $crew->full_name,
        'crew_member_1_personnel_id' => $crew->id,
    ]);

    app(UnitTeamAssignmentService::class)->removeTeamLeader($unit, $dispatcher);

    expect($unit->fresh()->crew_member_1_personnel_id)->toBeNull();
});

it('leaves reconciliation results independent of seed ownership', function () {
    pbaRoles();
    $person = pbaPerson('Plain', 'Match');
    $unit = pbaUnit(['crew_member_1_name' => 'Plain Match']);

    app(PersonnelReconciliationService::class)->apply();

    expect($unit->fresh()->crew_member_1_personnel_id)->toBe($person->id)
        ->and($unit->fresh()->crew_member_1_seeded_by_team_leader_id)->toBeNull();
});

it('releases the seeded driver and crew when an assigned team leader is archived', function () {
    $admin = pbaSystemAdmin();
    $dispatcher = pbaDispatcher();
    $driver = pbaPerson('Drive', 'Er', 'driver');
    $crew = pbaPerson('Crew', 'One');
    $leader = pbaSeededLeader(['driver_personnel_id' => $driver->id, 'crew_member_1_personnel_id' => $crew->id]);
    $unit = pbaUnit(['driver_2_name' => 'Second Legacy']);
    pbaAssignSeeded($unit, $leader->id, $dispatcher);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.archive', $leader->id), ['reason' => 'left'])
        ->assertRedirect(route('system-admin.users.index'));

    $fresh = $unit->fresh();
    expect($leader->fresh()->archived_at)->not->toBeNull()
        ->and($fresh->team_leader_id)->toBeNull()
        ->and($fresh->driver_personnel_id)->toBeNull()
        ->and($fresh->driver_name)->toBeNull()
        ->and($fresh->driver_seeded_by_team_leader_id)->toBeNull()
        ->and($fresh->crew_member_1_personnel_id)->toBeNull()
        ->and($fresh->crew_member_1_name)->toBeNull()
        ->and($fresh->crew_member_1_seeded_by_team_leader_id)->toBeNull()
        ->and($fresh->driver_2_name)->toBe('Second Legacy')
        ->and(Personnel::whereIn('id', [$driver->id, $crew->id])->count())->toBe(2);
});

it('keeps unrelated and other-leader slots when a team leader is archived', function () {
    $admin = pbaSystemAdmin();
    $other = pbaPerson('Other', 'Crew');
    $otherLeader = pbaSeededLeader();
    $leader = pbaSeededLeader();
    $unit = pbaUnit([
        'team_leader_id' => $leader->id,
        'crew_member_2_name' => $other->full_name,
        'crew_member_2_personnel_id' => $other->id,
        'crew_member_2_seeded_by_team_leader_id' => $otherLeader->id,
    ]);

    $this->actingAs($admin)->patch(route('system-admin.users.archive', $leader->id), ['reason' => 'left']);

    expect($unit->fresh()->team_leader_id)->toBeNull()
        ->and($unit->fresh()->crew_member_2_personnel_id)->toBe($other->id)
        ->and($unit->fresh()->crew_member_2_seeded_by_team_leader_id)->toBe($otherLeader->id);
});

it('keeps a loan-protected borrowed person and the loan snapshot when the team leader is archived', function () {
    $admin = pbaSystemAdmin();
    $dispatcher = pbaDispatcher();
    $crew = pbaPerson('Loaned', 'Crew');
    $owner = pbaSeededLeader(['crew_member_1_personnel_id' => $crew->id]);
    $leader = pbaSeededLeader(['crew_member_2_personnel_id' => $crew->id]);
    $home = pbaUnit();
    $away = pbaUnit();
    $service = app(UnitTeamAssignmentService::class);
    pbaAssignSeeded($home, $owner->id, $dispatcher);
    $service->assignSlotPerson($away, 'crew_member_2', $home, 'crew_member_1', $dispatcher);
    $away->update(['team_leader_id' => $leader->id]);

    $this->actingAs($admin)->patch(route('system-admin.users.archive', $leader->id), ['reason' => 'left']);

    $loan = UnitCrewLoan::firstOrFail();
    expect($away->fresh()->team_leader_id)->toBeNull()
        ->and($away->fresh()->crew_member_2_personnel_id)->toBe($crew->id)
        ->and($loan->returned_at)->toBeNull()
        ->and($loan->person_name)->toBe('Loaned Crew')
        ->and($loan->personnel_id)->toBe($crew->id);
});

it('uses the same legacy fallback on archive when the slot has no seed owner', function () {
    $admin = pbaSystemAdmin();
    $crew = pbaPerson('Legacy', 'Seeded');
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $crew->id]);
    $unit = pbaUnit([
        'team_leader_id' => $leader->id,
        'crew_member_1_name' => $crew->full_name,
        'crew_member_1_personnel_id' => $crew->id,
    ]);

    $this->actingAs($admin)->patch(route('system-admin.users.archive', $leader->id), ['reason' => 'left']);

    expect($unit->fresh()->crew_member_1_personnel_id)->toBeNull()
        ->and($unit->fresh()->crew_member_1_name)->toBeNull();
});

it('does not touch unit teams when a non team leader is archived', function () {
    $admin = pbaSystemAdmin();
    $crew = pbaPerson('Steady', 'Crew');
    $leader = pbaSeededLeader();
    $unit = pbaPlaced($crew, 'crew_member_1', ['team_leader_id' => $leader->id, 'crew_member_1_seeded_by_team_leader_id' => $leader->id]);
    $dispatcher = pbaDispatcher();

    $this->actingAs($admin)->patch(route('system-admin.users.archive', $dispatcher->id), ['reason' => 'left'])
        ->assertRedirect(route('system-admin.users.index'));

    expect($dispatcher->fresh()->archived_at)->not->toBeNull()
        ->and($unit->fresh()->team_leader_id)->toBe($leader->id)
        ->and($unit->fresh()->crew_member_1_personnel_id)->toBe($crew->id);
});

it('releases the seeded team when a team leader is purged', function () {
    $dispatcher = pbaDispatcher();
    $crew = pbaPerson('Purge', 'Crew');
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $crew->id]);
    $unit = pbaUnit();
    pbaAssignSeeded($unit, $leader->id, $dispatcher);

    app(\App\Services\UserPurgeService::class)->purge($leader);

    expect($unit->fresh()->team_leader_id)->toBeNull()
        ->and($unit->fresh()->crew_member_1_personnel_id)->toBeNull()
        ->and($unit->fresh()->crew_member_1_name)->toBeNull();
});

it('purging an account-backed driver clears only driver_id and keeps the unit team leader and seeded crew', function () {
    $dispatcher = pbaDispatcher();
    $crew = pbaPerson('Kept', 'Crew');
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $crew->id]);
    $driverAccount = User::factory()->create(['role_id' => 4]);
    $unit = pbaUnit(['driver_id' => $driverAccount->id]);
    pbaAssignSeeded($unit, $leader->id, $dispatcher);

    app(\App\Services\UserPurgeService::class)->purge($driverAccount);

    $fresh = $unit->fresh();
    expect($fresh->driver_id)->toBeNull()
        ->and($fresh->team_leader_id)->toBe($leader->id)
        ->and($fresh->crew_member_1_personnel_id)->toBe($crew->id)
        ->and($fresh->crew_member_1_seeded_by_team_leader_id)->toBe($leader->id);
});

it('purging a team leader keeps the unrelated account-backed driver and releases the seeded team', function () {
    $dispatcher = pbaDispatcher();
    $crew = pbaPerson('Released', 'Crew');
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $crew->id]);
    $driverAccount = User::factory()->create(['role_id' => 4]);
    $unit = pbaUnit(['driver_id' => $driverAccount->id]);
    pbaAssignSeeded($unit, $leader->id, $dispatcher);

    app(\App\Services\UserPurgeService::class)->purge($leader);

    $fresh = $unit->fresh();
    expect($fresh->team_leader_id)->toBeNull()
        ->and($fresh->driver_id)->toBe($driverAccount->id)
        ->and($fresh->crew_member_1_personnel_id)->toBeNull();
});

it('purging an unassigned user leaves unit assignments unchanged', function () {
    $dispatcher = pbaDispatcher();
    $crew = pbaPerson('Steady', 'Crew');
    $leader = pbaSeededLeader(['crew_member_1_personnel_id' => $crew->id]);
    $driverAccount = User::factory()->create(['role_id' => 4]);
    $bystander = User::factory()->create(['role_id' => 2]);
    $unit = pbaUnit(['driver_id' => $driverAccount->id]);
    pbaAssignSeeded($unit, $leader->id, $dispatcher);

    app(\App\Services\UserPurgeService::class)->purge($bystander);

    $fresh = $unit->fresh();
    expect($fresh->team_leader_id)->toBe($leader->id)
        ->and($fresh->driver_id)->toBe($driverAccount->id)
        ->and($fresh->crew_member_1_personnel_id)->toBe($crew->id);
});

it('creates a team leader without any driver or crew template', function () {
    $admin = pbaSystemAdmin();
    SystemSetting::setValue('max_team_leaders', 10);

    $this->actingAs($admin)
        ->post(route('system-admin.users.store'), pbaTlPayload([
            'driver_personnel_id' => 999,
            'crew_member_1_personnel_id' => 999,
            'driver_first_name' => 'Typed',
            'crew_member_1_name' => 'Typed Crew',
        ]))
        ->assertRedirect(route('system-admin.users.index'));

    $leader = User::where('role_id', 3)->latest('id')->first();
    expect($leader)->not->toBeNull()
        ->and($leader->driver_personnel_id)->toBeNull()
        ->and($leader->crew_member_1_personnel_id)->toBeNull()
        ->and($leader->driver_first_name)->toBeNull()
        ->and($leader->crew_member_1_name)->toBeNull()
        ->and(Personnel::count())->toBe(0);
});

it('renders no driver or crew template inputs on team leader create or edit', function () {
    $admin = pbaSystemAdmin();
    $leader = User::factory()->create([
        'role_id' => 3, 'phone' => '09171234567',
        'driver_first_name' => 'Legacy', 'driver_last_name' => 'Driver', 'crew_member_1_name' => 'Legacy Crew',
    ]);

    $create = $this->actingAs($admin)->get(route('system-admin.users.create'))->assertOk();
    $edit = $this->actingAs($admin)->get(route('system-admin.users.edit', $leader->id))->assertOk();

    foreach ([$create, $edit] as $response) {
        $response->assertDontSee('Driver Details')
            ->assertDontSee('Crew Members')
            ->assertDontSee('driver_personnel_id')
            ->assertDontSee('crew_member_1_personnel_id')
            ->assertDontSee('is not linked to Personnel');
    }
});

it('editing a team leader leaves hidden legacy staged values untouched', function () {
    $admin = pbaSystemAdmin();
    $driver = pbaPerson('Drive', 'Er', 'driver');
    $leader = User::factory()->create([
        'role_id' => 3, 'phone' => '09171234567',
        'driver_personnel_id' => $driver->id,
        'driver_first_name' => 'Drive', 'driver_last_name' => 'Er',
        'crew_member_1_name' => 'Legacy Crew',
    ]);

    $this->actingAs($admin)
        ->putJson(route('system-admin.users.update', $leader->id), [
            'first_name' => 'Renamed', 'last_name' => 'Leader', 'phone' => '09171234567',
            'driver_personnel_id' => '', 'crew_member_1_name' => 'Typed Over', 'driver_first_name' => 'Typed',
        ])
        ->assertSuccessful();

    $fresh = $leader->fresh();
    expect($fresh->first_name)->toBe('Renamed')
        ->and($fresh->driver_personnel_id)->toBe($driver->id)
        ->and($fresh->driver_first_name)->toBe('Drive')
        ->and($fresh->crew_member_1_name)->toBe('Legacy Crew');
});

it('assigning a team leader sets only the team leader and never seeds driver or crew', function () {
    $dispatcher = pbaDispatcher();
    $driver = pbaPerson('Drive', 'Er', 'driver');
    $crew = pbaPerson('Crew', 'One');
    $leader = pbaSeededLeader(['driver_personnel_id' => $driver->id, 'crew_member_1_personnel_id' => $crew->id]);
    $unit = pbaUnit();

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unit, $leader->id, $dispatcher);

    $fresh = $unit->fresh();
    expect($fresh->team_leader_id)->toBe($leader->id)
        ->and($fresh->driver_personnel_id)->toBeNull()
        ->and($fresh->driver_name)->toBeNull()
        ->and($fresh->crew_member_1_personnel_id)->toBeNull()
        ->and($fresh->crew_member_1_name)->toBeNull()
        ->and($fresh->driver_seeded_by_team_leader_id)->toBeNull()
        ->and($fresh->crew_member_1_seeded_by_team_leader_id)->toBeNull();
});

it('assigning a team leader preserves the existing driver and crew on the unit', function () {
    $dispatcher = pbaDispatcher();
    $existingCrew = pbaPerson('Existing', 'Crew');
    $existingDriver = pbaPerson('Existing', 'Driver', 'driver');
    $leader = pbaSeededLeader();
    $unit = pbaPlaced($existingCrew, 'crew_member_1', [
        'driver_name' => $existingDriver->full_name,
        'driver_personnel_id' => $existingDriver->id,
    ]);

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unit, $leader->id, $dispatcher);

    $fresh = $unit->fresh();
    expect($fresh->team_leader_id)->toBe($leader->id)
        ->and($fresh->crew_member_1_personnel_id)->toBe($existingCrew->id)
        ->and($fresh->driver_personnel_id)->toBe($existingDriver->id);
});

it('still assigns driver and crew directly through the personnel-backed flow after a team leader is assigned', function () {
    $dispatcher = pbaDispatcher();
    $crew = pbaPerson('Direct', 'Crew');
    $leader = pbaSeededLeader();
    $source = pbaPlaced($crew, 'crew_member_1');
    $unit = pbaUnit();
    $service = app(UnitTeamAssignmentService::class);
    $service->assignTeamLeader($unit, $leader->id, $dispatcher);

    $service->assignSlotPerson($unit, 'crew_member_1', $source, 'crew_member_1', $dispatcher);

    expect($unit->fresh()->crew_member_1_personnel_id)->toBe($crew->id)
        ->and($unit->fresh()->team_leader_id)->toBe($leader->id);
});
