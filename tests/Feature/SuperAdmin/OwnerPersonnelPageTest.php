<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;

function ppRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ppOwner(): User
{
    ppRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function ppDispatcher(): User
{
    ppRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function ppTeamLeader(string $name = 'PP Team Leader'): User
{
    ppRole(3, 'Team Leader');

    return User::factory()->create(['role_id' => 3, 'name' => $name, 'status' => 'active']);
}

function ppDriverUser(string $name = 'PP Driver'): User
{
    ppRole(4, 'Driver');

    return User::factory()->create(['role_id' => 4, 'name' => $name, 'status' => 'active']);
}

function ppUnit(string $name): Unit
{
    $truckType = TruckType::create(['name' => 'PP Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);

    return Unit::create([
        'name' => $name,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);
}

it('owner can view the personnel page', function () {
    ppTeamLeader('PP Visible Leader');

    $response = $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'));

    $response->assertOk();
    $response->assertSee('PP Visible Leader');
    $response->assertSee('Team Leader');
});

it('dispatcher cannot access the owner personnel page', function () {
    $this->actingAs(ppDispatcher())->get(route('superadmin.personnel.index'))->assertForbidden();
});

it('lists personnel without exposing any login credential fields', function () {
    ppDriverUser('PP No Login Driver');

    $response = $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'));

    $response->assertOk();
    $response->assertDontSee('password', false);
    $response->assertDontSee('type="password"', false);
});

it('owner can set a personnel home unit', function () {
    $owner = ppOwner();
    $driver = ppDriverUser();
    $unit = ppUnit('PP Home Unit 01');

    $response = $this->actingAs($owner)->patch(route('superadmin.personnel.home-unit', $driver), [
        'home_unit_id' => $unit->id,
    ]);

    $response->assertRedirect();
    expect($driver->fresh()->home_unit_id)->toBe($unit->id);
});

it('records a personnel_home_unit_changed business audit event when home unit changes', function () {
    $owner = ppOwner();
    $driver = ppDriverUser();
    $unit = ppUnit('PP Audit Unit');

    $this->actingAs($owner)->patch(route('superadmin.personnel.home-unit', $driver), [
        'home_unit_id' => $unit->id,
    ]);

    $log = AuditLog::where('action', 'personnel_home_unit_changed')->latest()->first();

    expect($log)->not->toBeNull();
    expect($log->entity_type)->toBe('User');
    expect($log->entity_id)->toBe($driver->id);
});

it('owner can activate and deactivate personnel without touching account status', function () {
    $owner = ppOwner();
    $teamLeader = ppTeamLeader();

    expect($teamLeader->fresh()->personnel_enabled)->toBeTrue();

    $this->actingAs($owner)->patch(route('superadmin.personnel.toggle', $teamLeader))->assertRedirect();

    $teamLeader->refresh();
    expect($teamLeader->personnel_enabled)->toBeFalse();
    expect($teamLeader->status)->toBe('active');

    $log = AuditLog::where('action', 'personnel_deactivated')->latest()->first();
    expect($log)->not->toBeNull();
});

it('classifies personnel lifecycle events as business, excluded from system admin audit logs', function () {
    ppRole(6, 'System Admin');
    $admin = User::factory()->create(['role_id' => 6, 'status' => 'active', 'must_change_password' => false]);

    $owner = ppOwner();
    $teamLeader = ppTeamLeader('PP Hidden From System Admin');

    $this->actingAs($owner)->patch(route('superadmin.personnel.toggle', $teamLeader));

    $response = $this->actingAs($admin)->get(route('system-admin.audit-logs.index'));

    $response->assertOk();
    $response->assertDontSee('set to Inactive.');

    expect(AuditLog::where('action', 'personnel_deactivated')->exists())->toBeTrue();
});

it('shows personnel lifecycle events in owner business activity', function () {
    $owner = ppOwner();
    $teamLeader = ppTeamLeader('PP Shown In Business Activity');

    $this->actingAs($owner)->patch(route('superadmin.personnel.toggle', $teamLeader));

    $response = $this->actingAs($owner)->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('Personnel Deactivated');
    $response->assertSee('PP Shown In Business Activity');
});

it('home unit stays stable when dispatcher borrows the team leader elsewhere', function () {
    $owner = ppOwner();
    $dispatcher = ppDispatcher();

    $home = ppUnit('PP Home Base');
    $borrowTarget = ppUnit('PP Borrow Target');

    $teamLeader = ppTeamLeader('PP Stable Leader');
    $home->update(['team_leader_id' => $teamLeader->id]);

    $this->actingAs($owner)->patch(route('superadmin.personnel.home-unit', $teamLeader), [
        'home_unit_id' => $home->id,
    ]);

    expect($teamLeader->fresh()->home_unit_id)->toBe($home->id);

    app(\App\Services\UnitTeamAssignmentService::class)->assignTeamLeader($borrowTarget, $teamLeader->id, $dispatcher);

    expect($teamLeader->fresh()->home_unit_id)->toBe($home->id);
});

it('shows a borrowed team leader as borrowed while home unit remains unchanged', function () {
    $owner = ppOwner();
    $dispatcher = ppDispatcher();

    $home = ppUnit('PP Borrow Home');
    $borrowTarget = ppUnit('PP Borrow Elsewhere');

    $teamLeader = ppTeamLeader('PP Borrowed Leader');
    $home->update(['team_leader_id' => $teamLeader->id]);
    $teamLeader->update(['home_unit_id' => $home->id]);

    app(\App\Services\UnitTeamAssignmentService::class)->assignTeamLeader($borrowTarget, $teamLeader->id, $dispatcher);

    $response = $this->actingAs($owner)->get(route('superadmin.personnel.index'));

    $response->assertOk();
    $response->assertSee('Borrowed');
    $response->assertSee($home->name);
    $response->assertSee($borrowTarget->name);
});

it('dispatcher borrow action does not itself write a personnel_home_unit_changed event', function () {
    $dispatcher = ppDispatcher();

    $home = ppUnit('PP No Mutation Home');
    $borrowTarget = ppUnit('PP No Mutation Target');

    $teamLeader = ppTeamLeader('PP No Mutation Leader');
    $home->update(['team_leader_id' => $teamLeader->id]);
    $teamLeader->update(['home_unit_id' => $home->id]);

    app(\App\Services\UnitTeamAssignmentService::class)->assignTeamLeader($borrowTarget, $teamLeader->id, $dispatcher);

    $log = AuditLog::where('action', 'personnel_home_unit_changed')
        ->where('entity_id', $teamLeader->id)
        ->first();

    expect($log)->toBeNull();
});

it('owner cannot reach dispatcher operational borrow or return routes', function () {
    ppRole(3, 'Team Leader');
    $owner = ppOwner();
    $unit = ppUnit('PP Owner Blocked Unit');
    $teamLeader = ppTeamLeader();

    $this->actingAs($owner)
        ->post(route('admin.drivers.units.assign-team-leader', $unit), ['team_leader_id' => $teamLeader->id])
        ->assertForbidden();
});

it('shows home assignments grouped by unit as master configuration', function () {
    $owner = ppOwner();
    $unit = ppUnit('PP Roster Unit');
    $teamLeader = ppTeamLeader('PP Roster Leader');
    $teamLeader->update(['home_unit_id' => $unit->id]);

    $response = $this->actingAs($owner)->get(route('superadmin.home-assignments.index'));

    $response->assertOk();
    $response->assertSee('PP Roster Unit');
    $response->assertSee('PP Roster Leader');
    $response->assertDontSee('Borrow');
    $response->assertDontSee('Return');
});

it('does not change historical booking personnel snapshot when home unit changes later', function () {
    $owner = ppOwner();
    $teamLeader = ppTeamLeader('PP Snapshot Leader');
    $originalUnit = ppUnit('PP Snapshot Original Unit');
    $newHomeUnit = ppUnit('PP Snapshot New Home');

    $customer = Customer::create([
        'full_name' => 'PP Snapshot Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'pp-snapshot-' . uniqid() . '@example.com',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $originalUnit->truck_type_id,
        'assigned_unit_id' => $originalUnit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->actingAs($owner)->patch(route('superadmin.personnel.home-unit', $teamLeader), [
        'home_unit_id' => $newHomeUnit->id,
    ]);

    expect($booking->fresh()->assigned_unit_id)->toBe($originalUnit->id);
    expect($booking->fresh()->assigned_team_leader_id)->toBe($teamLeader->id);
});

/* ---------- Revision 2 ---------- */

function ppRecord(string $first, string $last, array $attrs = []): \App\Models\Personnel
{
    return \App\Models\Personnel::create(array_merge(['first_name' => $first, 'last_name' => $last, 'role' => 'crew', 'personnel_status' => 'active'], $attrs));
}

function ppPerson(int $roleId, string $first, string $last, array $attrs = []): User
{
    ppRole($roleId, $roleId === 3 ? 'Team Leader' : 'Driver');

    return User::factory()->create(array_merge([
        'role_id' => $roleId, 'status' => 'active', 'first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'name' => "$first $last",
    ], $attrs));
}

it('shows the revision 2 columns without the duplicated fleet tabs', function () {
    $response = $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'));

    $response->assertOk();
    $response->assertSee('Home unit is where each person normally works. Current unit is where they are today.');
    $response->assertSee('Add driver or crew member');
    $response->assertSee('placeholder="Search name or ID"', false);
    $response->assertDontSee('class="fleet-tabs"', false);
    ppRecord('Rico', 'Santos');
    $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'))
        ->assertSeeInOrder(['>Name<', '>Role<', '>Home unit<', '>Current unit<', '>Status<', '>Actions<'], false);
});

it('tells app accounts apart from personnel records', function () {
    ppPerson(3, 'Ramil', 'Navarro', ['user_code' => 'TL-0003']);
    ppRecord('Noel', 'Bautista');

    $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'))
        ->assertSeeInOrder(['Noel Bautista', 'No app account', 'Crew Member'])
        ->assertSeeInOrder(['Ramil Navarro', 'App account · ID TL-0003', 'Team Leader']);
});

it('shows home and current unit as same, borrowed, not set, or not on a unit', function () {
    $jarz1 = ppUnit('PP JARZ 1');
    $jarz2 = ppUnit('PP JARZ 2');
    $jarz3 = ppUnit('PP JARZ 3');

    $same = ppPerson(3, 'Same', 'Leader', ['home_unit_id' => $jarz1->id]);
    $jarz1->update(['team_leader_id' => $same->id]);
    $borrowed = ppPerson(3, 'Borrowed', 'Leader', ['home_unit_id' => $jarz2->id]);
    $jarz3->update(['team_leader_id' => $borrowed->id]);
    ppRecord('Nohome', 'Crew');
    $jarz2->update(['crew_member_1_name' => 'Nohome Crew']);
    ppRecord('Idle', 'Crew', ['home_unit_id' => $jarz1->id]);

    $response = $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'));

    $response->assertSeeInOrder(['Borrowed Leader', 'PP JARZ 2', 'PP JARZ 3', 'Borrowed from PP JARZ 2']);
    $response->assertSeeInOrder(['Idle Crew', 'PP JARZ 1', 'Not on a unit']);
    $response->assertSeeInOrder(['Nohome Crew', 'Not set', 'PP JARZ 2']);
    $response->assertSeeInOrder(['Same Leader', 'PP JARZ 1', 'Same as home unit']);
});

it('shows account status from the sign-in status, not the unused personnel flag', function () {
    ppPerson(4, 'Signed', 'Out', ['status' => 'inactive', 'personnel_enabled' => true]);
    ppPerson(4, 'Flag', 'Off', ['status' => 'active', 'personnel_enabled' => false]);

    $content = $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'))->getContent();

    expect($content)->toMatch('/Flag Off.*?tt-badge is-active">Active</s')
        ->toMatch('/Signed Out.*?tt-badge is-inactive">Inactive</s');
});

it('shows a record status from the personnel record', function () {
    ppRecord('Rico', 'Santos', ['personnel_status' => 'inactive']);

    expect($this->actingAs(ppOwner())->get(route('superadmin.personnel.index'))->getContent())
        ->toMatch('/Rico Santos.*?tt-badge is-inactive">Inactive</s');
});

it('never offers the account activation toggle', function () {
    $leader = ppPerson(3, 'Ramil', 'Navarro');

    $response = $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'));

    $response->assertDontSee(route('superadmin.personnel.toggle', $leader->id), false);
    $response->assertSee("It isn't changed from Personnel.", false);
    $response->assertSee('data-action-url="' . route('superadmin.personnel.home-unit', $leader->id) . '"', false);
});

it('offers record activation wired to the existing toggle route', function () {
    $record = ppRecord('Rico', 'Santos');

    $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'))
        ->assertSee('data-toggle-url="' . route('superadmin.personnel-records.toggle', $record->id) . '"', false)
        ->assertSee('data-action-url="' . route('superadmin.personnel-records.update', $record->id) . '"', false);
});

it('explains what record activation changes', function () {
    $record = ppRecord('Rico', 'Santos');
    $owner = ppOwner();

    $this->actingAs($owner)->patch(route('superadmin.personnel-records.toggle', $record->id))
        ->assertSessionHas('success', "Rico Santos is inactive and can't be placed on a unit.");
    $this->actingAs($owner)->patch(route('superadmin.personnel-records.toggle', $record->id))
        ->assertSessionHas('success', 'Rico Santos is active and can be placed on a unit.');
});

/* ---------- Search, filters, pagination ---------- */

it('searches name and account ID case-insensitively across every page', function () {
    foreach (range(1, 9) as $i) {
        ppRecord('Alpha', "Person{$i}");
    }
    ppPerson(3, 'Zed', 'Leader', ['user_code' => 'TL-0099']);

    $owner = ppOwner();
    $this->actingAs($owner)->get(route('superadmin.personnel.index', ['search' => 'zed lead']))
        ->assertSee('Zed Leader')->assertSee('Showing 1–1 of 1 personnel');
    $this->actingAs($owner)->get(route('superadmin.personnel.index', ['search' => 'tl-0099']))
        ->assertSee('Zed Leader');
    $this->actingAs($owner)->get(route('superadmin.personnel.index', ['search' => 'nobody here']))
        ->assertSee('No personnel match these filters');
});

it('filters by role and status and ignores unknown values', function () {
    ppPerson(3, 'Lead', 'One');
    ppRecord('Crew', 'Active');
    ppRecord('Crew', 'Resting', ['personnel_status' => 'inactive']);

    $owner = ppOwner();
    $this->actingAs($owner)->get(route('superadmin.personnel.index', ['role' => 'team_leader']))
        ->assertSee('Lead One')->assertDontSee('Crew Active');
    $this->actingAs($owner)->get(route('superadmin.personnel.index', ['status' => 'inactive']))
        ->assertSee('Crew Resting')->assertDontSee('Crew Active')->assertDontSee('Lead One');
    $this->actingAs($owner)->get(route('superadmin.personnel.index', ['role' => 'crew', 'status' => 'active']))
        ->assertSee('Crew Active')->assertDontSee('Crew Resting');
    $this->actingAs($owner)->get(route('superadmin.personnel.index', ['role' => 'pilot', 'status' => 'gone']))
        ->assertSee('Showing 1–3 of 3 personnel');
});

it('paginates seven per page and keeps filters in page links', function () {
    foreach (range(1, 9) as $i) {
        ppRecord('Page', "Crew{$i}");
    }

    $owner = ppOwner();
    $first = $this->actingAs($owner)->get(route('superadmin.personnel.index', ['role' => 'crew']));
    $first->assertSee('Showing 1–7 of 9 personnel');
    $first->assertSee('role=crew', false);
    $first->assertSee('page=2', false);

    $this->actingAs($owner)->get(route('superadmin.personnel.index', ['role' => 'crew', 'page' => 2]))
        ->assertSee('Showing 8–9 of 9 personnel');
    $this->actingAs($owner)->get(route('superadmin.personnel.index', ['role' => 'crew', 'page' => 5]))
        ->assertRedirect(route('superadmin.personnel.index', ['role' => 'crew', 'page' => 2]));
});

it('shows a first-run empty state', function () {
    $this->actingAs(ppOwner())->get(route('superadmin.personnel.index'))
        ->assertSee('No personnel yet')->assertDontSee('Clear filters');
});

/* ---------- Forms ---------- */

it('keeps typed values and shows field messages after a failed add', function () {
    $owner = ppOwner();
    $from = route('superadmin.personnel.index', ['role' => 'crew']);

    $response = $this->actingAs($owner)->from($from)->post(route('superadmin.personnel-records.store'), [
        '_modal' => 'add', 'first_name' => '', 'middle_name' => 'Kept', 'last_name' => 'Cruz', 'role' => '',
    ]);

    $response->assertRedirect($from);
    $response->assertSessionHasErrors(['first_name' => 'Enter a first name.', 'role' => 'Choose a role.']);

    $page = $this->actingAs($owner)->get($from);
    $page->assertSee('data-reopen="add"', false);
    $page->assertSee('Check the 2 highlighted fields. Everything else you entered is kept.');
    $page->assertSee('value="Kept"', false);
    $page->assertSee('value="Cruz"', false);
    $page->assertSee('id="ppFirstError"', false);
});

it('adds a record with a home unit', function () {
    $unit = ppUnit('PP JARZ 9');

    $this->actingAs(ppOwner())->post(route('superadmin.personnel-records.store'), [
        'first_name' => 'Ely', 'last_name' => 'Cruz', 'role' => 'driver', 'home_unit_id' => $unit->id,
    ])->assertSessionHas('success', 'Ely Cruz added.');

    $this->assertDatabaseHas('personnel', ['first_name' => 'Ely', 'home_unit_id' => $unit->id, 'personnel_status' => 'active']);
});

it('saves details and home unit from one edit submit, with the home unit audited', function () {
    $unit = ppUnit('PP JARZ 8');
    $record = ppRecord('Old', 'Name');

    $this->actingAs(ppOwner())->put(route('superadmin.personnel-records.update', $record->id), [
        'first_name' => 'New', 'last_name' => 'Name', 'role' => 'crew', 'home_unit_id' => $unit->id,
    ])->assertSessionHas('success', 'New Name updated.');

    expect($record->fresh()->home_unit_id)->toBe($unit->id);
    expect(AuditLog::where('action', 'personnel_home_unit_changed')->where('entity_id', $record->id)->exists())->toBeTrue();
});

it('leaves the home unit alone when an edit does not send it', function () {
    $unit = ppUnit('PP JARZ 7');
    $record = ppRecord('Keep', 'Home', ['home_unit_id' => $unit->id]);

    $this->actingAs(ppOwner())->put(route('superadmin.personnel-records.update', $record->id), [
        'first_name' => 'Keep', 'last_name' => 'Home', 'role' => 'crew',
    ])->assertSessionHasNoErrors();

    expect($record->fresh()->home_unit_id)->toBe($unit->id);
});

it('reopens the record dialog after a failed edit', function () {
    $record = ppRecord('Edit', 'Me');
    $owner = ppOwner();

    $this->actingAs($owner)->from(route('superadmin.personnel.index'))->put(route('superadmin.personnel-records.update', $record->id), [
        '_modal' => 'record', '_edit_id' => $record->id, 'first_name' => 'Edit', 'last_name' => '', 'role' => 'crew',
    ])->assertSessionHasErrors(['last_name' => 'Enter a last name.']);

    $page = $this->actingAs($owner)->get(route('superadmin.personnel.index'));
    $page->assertSee('data-reopen="record"', false);
    $page->assertSee('action="' . route('superadmin.personnel-records.update', $record->id) . '"', false);
    $page->assertSee('Check the highlighted field. Everything else you entered is kept.');
});

it('reopens the account dialog with the team leader move error', function () {
    $unit = ppUnit('PP JARZ 6');
    $other = ppPerson(3, 'Already', 'There');
    $unit->update(['team_leader_id' => $other->id]);
    $leader = ppPerson(3, 'Moving', 'Leader');
    $owner = ppOwner();

    $this->actingAs($owner)->from(route('superadmin.personnel.index'))->patch(route('superadmin.personnel.home-unit', $leader->id), [
        '_modal' => 'account', '_edit_id' => $leader->id, 'home_unit_id' => $unit->id,
    ])->assertSessionHasErrors('home_unit_id');

    expect($leader->fresh()->home_unit_id)->toBeNull();
    expect($unit->fresh()->team_leader_id)->toBe($other->id);

    $page = $this->actingAs($owner)->get(route('superadmin.personnel.index'));
    $page->assertSee('data-reopen="account"', false);
    $page->assertSee('PP JARZ 6 already has a Team Leader assigned.');
    $page->assertSee('action="' . route('superadmin.personnel.home-unit', $leader->id) . '"', false);
});

it('still blocks a role change while the record is placed on a unit', function () {
    $record = ppRecord('Placed', 'Crew');
    $unit = ppUnit('PP JARZ 5');
    $unit->update(['crew_member_1_name' => 'Placed Crew', 'crew_member_1_personnel_id' => $record->id]);

    $this->actingAs(ppOwner())->put(route('superadmin.personnel-records.update', $record->id), [
        'first_name' => 'Placed', 'last_name' => 'Crew', 'role' => 'driver',
    ])->assertSessionHasErrors('role');

    expect($record->fresh()->role)->toBe('crew')
        ->and($unit->fresh()->crew_member_1_personnel_id)->toBe($record->id);
});

/* ---------- Home Assignments revision 2 ---------- */

it('shows the home assignments header and filters without fleet tabs', function () {
    $response = $this->actingAs(ppOwner())->get(route('superadmin.home-assignments.index'));

    $response->assertOk();
    $response->assertSee("The usual team for each unit. Dispatchers can change today's team in Units &amp; Leaders without changing anyone's home unit.", false);
    $response->assertSee('placeholder="Search unit or person"', false);
    $response->assertDontSee('class="fleet-tabs"', false);
});

it('lists everyone who shares a home unit, without hiding duplicates', function () {
    $unit = ppUnit('HA JARZ 4');
    ppPerson(3, 'Paolo', 'Lim', ['home_unit_id' => $unit->id]);
    ppPerson(3, 'Jose', 'Lim', ['home_unit_id' => $unit->id]);
    ppPerson(4, 'Leo', 'Santos', ['home_unit_id' => $unit->id]);
    ppRecord('Ely', 'Cruz', ['role' => 'driver', 'home_unit_id' => $unit->id]);
    foreach (['Ben Cruz', 'Jay Ramos', 'Rey Torres'] as $name) {
        [$first, $last] = explode(' ', $name);
        ppRecord($first, $last, ['home_unit_id' => $unit->id]);
    }

    $response = $this->actingAs(ppOwner())->get(route('superadmin.home-assignments.index'));

    $response->assertSeeInOrder(['HA JARZ 4', 'Jose Lim', 'Paolo Lim', 'Ely Cruz', 'Leo Santos', 'Ben Cruz', 'Jay Ramos', 'Rey Torres']);
    $response->assertDontSee('Not set');
});

it('shows the plate and truck type under each unit', function () {
    $unit = ppUnit('HA JARZ 1');
    $unit->update(['plate_number' => 'NAB4521']);

    $this->actingAs(ppOwner())->get(route('superadmin.home-assignments.index'))
        ->assertSeeInOrder(['HA JARZ 1', 'NAB 4521 · ' . $unit->truckType->name]);
});

it('marks a missing team leader or driver as Not set and an empty crew as None', function () {
    $unit = ppUnit('HA JARZ 3');

    $content = $this->actingAs(ppOwner())->get(route('superadmin.home-assignments.index'))->getContent();

    expect(substr_count($content, '</i>Not set</span>'))->toBe(2);
    expect($content)->toContain('<span class="tt-muted">None</span>');
});

it('tags inactive people and does not count them toward a complete team', function () {
    $unit = ppUnit('HA JARZ 5');
    ppPerson(3, 'Ana', 'Ramos', ['home_unit_id' => $unit->id]);
    ppPerson(4, 'Gone', 'Driver', ['home_unit_id' => $unit->id, 'status' => 'inactive']);
    ppRecord('Idle', 'Crew', ['home_unit_id' => $unit->id, 'personnel_status' => 'inactive']);

    $response = $this->actingAs(ppOwner())->get(route('superadmin.home-assignments.index', ['show' => 'missing']));

    $response->assertSee('HA JARZ 5');
    $response->assertSee('Gone Driver<span class="ha-tag">Inactive</span>', false);
    $response->assertSee('Idle Crew<span class="ha-tag">Inactive</span>', false);
    $response->assertSee('No active driver');
});

it('filters to units missing a team leader or driver, with counts', function () {
    $complete = ppUnit('HA Complete');
    ppPerson(3, 'Full', 'Leader', ['home_unit_id' => $complete->id]);
    ppRecord('Full', 'Driver', ['role' => 'driver', 'home_unit_id' => $complete->id]);
    $noDriver = ppUnit('HA No Driver');
    ppPerson(3, 'Lonely', 'Leader', ['home_unit_id' => $noDriver->id]);
    ppUnit('HA Empty');

    $owner = ppOwner();
    $this->actingAs($owner)->get(route('superadmin.home-assignments.index'))
        ->assertSee('>All units (3)<', false)
        ->assertSee('>Missing team leader or driver (2)<', false)
        ->assertSee('Showing 3 of 3 units');

    $this->actingAs($owner)->get(route('superadmin.home-assignments.index', ['show' => 'missing']))
        ->assertSee('HA No Driver')->assertSee('HA Empty')->assertDontSee('<span class="tt-main">HA Complete</span>', false)
        ->assertSee('Showing 2 of 3 units');
});

it('says when no unit is missing anyone', function () {
    $unit = ppUnit('HA Done');
    ppPerson(3, 'Done', 'Leader', ['home_unit_id' => $unit->id]);
    ppPerson(4, 'Done', 'Driver', ['home_unit_id' => $unit->id]);

    $this->actingAs(ppOwner())->get(route('superadmin.home-assignments.index', ['show' => 'missing']))
        ->assertSee('Every unit has a team leader and a driver');
});

it('searches unit, plate and people case-insensitively', function () {
    $a = ppUnit('HA Alpha');
    $a->update(['plate_number' => 'ABC1234']);
    $b = ppUnit('HA Bravo');
    ppRecord('Dan', 'Aquino', ['home_unit_id' => $b->id]);

    $owner = ppOwner();
    $this->actingAs($owner)->get(route('superadmin.home-assignments.index', ['search' => 'ha alpha']))
        ->assertSee('HA Alpha')->assertDontSee('<span class="tt-main">HA Bravo</span>', false)->assertSee('Showing 1 of 2 units');
    $this->actingAs($owner)->get(route('superadmin.home-assignments.index', ['search' => 'abc 1234']))
        ->assertSee('HA Alpha')->assertDontSee('<span class="tt-main">HA Bravo</span>', false);
    $this->actingAs($owner)->get(route('superadmin.home-assignments.index', ['search' => 'AQUINO']))
        ->assertSee('HA Bravo')->assertDontSee('<span class="tt-main">HA Alpha</span>', false);
    $this->actingAs($owner)->get(route('superadmin.home-assignments.index', ['search' => 'zzz']))
        ->assertSee('No units match these filters');
});

it('shows a first-run state with no units, and skips archived units', function () {
    $owner = ppOwner();
    $this->actingAs($owner)->get(route('superadmin.home-assignments.index'))->assertSee('No units yet');

    $unit = ppUnit('HA Archived');
    $unit->forceFill(['archived_at' => now()])->save();
    $this->actingAs($owner)->get(route('superadmin.home-assignments.index'))->assertSee('No units yet');
});

it('gives the dialog each person with the existing endpoint for their kind', function () {
    $unit = ppUnit('HA Data');
    $leader = ppPerson(3, 'Data', 'Leader', ['home_unit_id' => $unit->id]);
    $record = ppRecord('Data', 'Crew');

    $content = $this->actingAs(ppOwner())->get(route('superadmin.home-assignments.index'))->getContent();
    preg_match('/<script type="application\/json" id="homeTeamData">(.*?)<\/script>/s', $content, $m);
    $data = json_decode($m[1], true);
    $people = collect($data['people'])->keyBy('key');

    expect($people['account-' . $leader->id]['url'])->toBe(route('superadmin.personnel.home-unit', $leader->id))
        ->and($people['account-' . $leader->id]['home'])->toBe($unit->id)
        ->and($people['account-' . $leader->id]['role'])->toBe('team_leader')
        ->and($people['record-' . $record->id]['url'])->toBe(route('superadmin.personnel-records.home-unit', $record->id))
        ->and($people['record-' . $record->id]['home'])->toBeNull()
        ->and($data['units'][$unit->id])->toBe('HA Data');
    // Saves are atomic, so the old one-team-leader-per-save limit is gone.
    expect($content)->not->toContain('Change one team leader per save.');
});

/* ---------- The calls the dialog makes (existing endpoints, JSON) ---------- */

it('moves a team leader onto a free unit through the existing endpoint', function () {
    $unit = ppUnit('HA Free');
    $leader = ppPerson(3, 'New', 'Leader');

    $this->actingAs(ppOwner())->patchJson(route('superadmin.personnel.home-unit', $leader->id), ['home_unit_id' => $unit->id])
        ->assertRedirect();

    expect($leader->fresh()->home_unit_id)->toBe($unit->id)
        ->and($unit->fresh()->team_leader_id)->toBe($leader->id);
});

it('refuses a second team leader on a led unit and changes nothing', function () {
    $unit = ppUnit('HA Led');
    $current = ppPerson(3, 'Current', 'Leader', ['home_unit_id' => $unit->id]);
    $unit->update(['team_leader_id' => $current->id]);
    $other = ppPerson(3, 'Other', 'Leader');

    $this->actingAs(ppOwner())->patchJson(route('superadmin.personnel.home-unit', $other->id), ['home_unit_id' => $unit->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'HA Led already has a Team Leader assigned. Remove or move that Team Leader first.');

    expect($other->fresh()->home_unit_id)->toBeNull()
        ->and($unit->fresh()->team_leader_id)->toBe($current->id);
});

it('refuses to move a team leader off a unit that is on an active job', function () {
    $unit = ppUnit('HA Busy');
    $leader = ppPerson(3, 'Busy', 'Leader', ['home_unit_id' => $unit->id]);
    $unit->update(['team_leader_id' => $leader->id, 'driver_name' => 'Some Driver']);
    $customer = Customer::create(['full_name' => 'HA Customer', 'phone' => '0917' . fake()->numerify('#######'), 'email' => 'ha-' . uniqid() . '@example.com']);
    Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $unit->truck_type_id, 'assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $leader->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 1800, 'status' => 'on_the_way',
    ]);

    $this->actingAs(ppOwner())->patchJson(route('superadmin.personnel.home-unit', $leader->id), ['home_unit_id' => null])
        ->assertStatus(422);

    expect($unit->fresh()->team_leader_id)->toBe($leader->id)
        ->and($leader->fresh()->home_unit_id)->toBe($unit->id);
});

it('removing a team leader from the home team takes them off the unit, as before', function () {
    $unit = ppUnit('HA Remove');
    $leader = ppPerson(3, 'Removed', 'Leader', ['home_unit_id' => $unit->id]);
    $unit->update(['team_leader_id' => $leader->id]);

    $this->actingAs(ppOwner())->patchJson(route('superadmin.personnel.home-unit', $leader->id), ['home_unit_id' => null])
        ->assertRedirect();

    expect($leader->fresh()->home_unit_id)->toBeNull()
        ->and($unit->fresh()->team_leader_id)->toBeNull();
});

it('changes a record home unit without touching the unit the dispatcher placed them on', function () {
    $home = ppUnit('HA Home');
    $placed = ppUnit('HA Placed');
    $record = ppRecord('Placed', 'Crew', ['home_unit_id' => $placed->id]);
    $placed->update(['crew_member_1_name' => 'Placed Crew', 'crew_member_1_personnel_id' => $record->id]);

    $this->actingAs(ppOwner())->patchJson(route('superadmin.personnel-records.home-unit', $record->id), ['home_unit_id' => $home->id])
        ->assertRedirect();

    expect($record->fresh()->home_unit_id)->toBe($home->id)
        ->and($placed->fresh()->crew_member_1_personnel_id)->toBe($record->id);
});

it('rejects an archived unit as a home unit with a field message', function () {
    $unit = ppUnit('HA Gone');
    $unit->forceFill(['archived_at' => now()])->save();
    $record = ppRecord('Some', 'Crew');

    $this->actingAs(ppOwner())->patchJson(route('superadmin.personnel-records.home-unit', $record->id), ['home_unit_id' => $unit->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.home_unit_id.0', 'Choose a unit from the list.');

    expect($record->fresh()->home_unit_id)->toBeNull();
});

it('keeps home assignments and their endpoints owner-only', function () {
    $dispatcher = ppDispatcher();
    $record = ppRecord('Guarded', 'Crew');
    $unit = ppUnit('HA Guard');

    $this->actingAs($dispatcher)->get(route('superadmin.home-assignments.index'))->assertForbidden();
    $this->actingAs($dispatcher)->patchJson(route('superadmin.personnel-records.home-unit', $record->id), ['home_unit_id' => $unit->id])->assertForbidden();
    expect($record->fresh()->home_unit_id)->toBeNull();
});

it('accepts the dialog request shape: form POST with _method PATCH and JSON errors', function () {
    $unit = ppUnit('HA Shape');
    $current = ppPerson(3, 'Shape', 'Leader', ['home_unit_id' => $unit->id]);
    $unit->update(['team_leader_id' => $current->id]);
    $other = ppPerson(3, 'Shape', 'Other');
    $record = ppRecord('Shape', 'Crew', ['home_unit_id' => $unit->id]);
    $owner = ppOwner();
    $json = ['Accept' => 'application/json'];

    $this->actingAs($owner)->post(route('superadmin.personnel.home-unit', $other->id), ['_method' => 'PATCH', 'home_unit_id' => $unit->id], $json)
        ->assertStatus(422)->assertJsonPath('message', 'HA Shape already has a Team Leader assigned. Remove or move that Team Leader first.');
    expect($other->fresh()->home_unit_id)->toBeNull();

    $this->actingAs($owner)->post(route('superadmin.personnel-records.home-unit', $record->id), ['_method' => 'PATCH', 'home_unit_id' => ''], $json)
        ->assertRedirect();
    expect($record->fresh()->home_unit_id)->toBeNull();
});

/* ---------- Borrowed team leader protection ---------- */

/** Team leader led by home unit $home, then borrowed to $away by a dispatcher (real service, real loan). */
function btlBorrowed(Unit $home, Unit $away): User
{
    $leader = ppPerson(3, 'Borrowed', 'Leader', ['home_unit_id' => $home->id]);
    $home->update(['team_leader_id' => $leader->id]);
    app(\App\Services\UnitTeamAssignmentService::class)->assignTeamLeader($away, $leader->id, ppDispatcher());

    return $leader->fresh();
}

function btlSnapshot(): array
{
    return [
        'units' => Unit::orderBy('id')->get(['id', 'team_leader_id', 'driver_name', 'driver_personnel_id', 'crew_member_1_name', 'crew_member_1_personnel_id'])->toArray(),
        'loans' => \App\Models\UnitCrewLoan::orderBy('id')->get(['id', 'from_unit_id', 'to_unit_id', 'returned_at'])->toArray(),
        'homes' => User::whereIn('role_id', [3, 4])->orderBy('id')->pluck('home_unit_id', 'id')->all(),
    ];
}

function btlPatch($test, User $leader, ?int $unitId)
{
    return $test->actingAs(ppOwner())->patchJson(route('superadmin.personnel.home-unit', $leader->id), ['home_unit_id' => $unitId]);
}

it('still moves a team leader who is on their own home unit to a new home unit', function () {
    $home = ppUnit('BTL Home');
    $next = ppUnit('BTL Next');
    $leader = ppPerson(3, 'Home', 'Leader', ['home_unit_id' => $home->id]);
    $home->update(['team_leader_id' => $leader->id]);

    btlPatch($this, $leader, $next->id)->assertRedirect();

    expect($leader->fresh()->home_unit_id)->toBe($next->id)
        ->and($home->fresh()->team_leader_id)->toBeNull()
        ->and($next->fresh()->team_leader_id)->toBe($leader->id);
});

it('still clears the home unit of a team leader on their own home unit, taking them off it', function () {
    $home = ppUnit('BTL Clear');
    $leader = ppPerson(3, 'Clear', 'Leader', ['home_unit_id' => $home->id]);
    $home->update(['team_leader_id' => $leader->id]);

    btlPatch($this, $leader, null)->assertRedirect();

    expect($leader->fresh()->home_unit_id)->toBeNull()
        ->and($home->fresh()->team_leader_id)->toBeNull();
});

it('refuses to change the home unit of a borrowed team leader and changes nothing', function () {
    $home = ppUnit('BTL JARZ 2');
    $away = ppUnit('BTL JARZ 3');
    $other = ppUnit('BTL JARZ 9');
    $leader = btlBorrowed($home, $away);
    $before = btlSnapshot();

    btlPatch($this, $leader, $other->id)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Borrowed Leader is on BTL JARZ 3, borrowed from BTL JARZ 2. Changing their home unit here would take them off BTL JARZ 3. Ask a dispatcher to return them in Units & Leaders first, or set their home unit to BTL JARZ 3.');

    expect(btlSnapshot())->toBe($before);
});

it('refuses to clear the home unit of a borrowed team leader and changes nothing', function () {
    $leader = btlBorrowed(ppUnit('BTL H'), ppUnit('BTL A'));
    $before = btlSnapshot();

    btlPatch($this, $leader, null)->assertStatus(422);

    expect(btlSnapshot())->toBe($before);
});

it('lets the truck a team leader is borrowed to become their home unit, without moving anyone', function () {
    $home = ppUnit('BTL Old Home');
    $away = ppUnit('BTL New Home');
    $leader = btlBorrowed($home, $away);

    btlPatch($this, $leader, $away->id)->assertRedirect();

    expect($leader->fresh()->home_unit_id)->toBe($away->id)
        ->and($away->fresh()->team_leader_id)->toBe($leader->id)
        ->and($home->fresh()->team_leader_id)->toBeNull()
        ->and(\App\Models\UnitCrewLoan::whereNull('returned_at')->where('person_user_id', $leader->id)->exists())->toBeFalse();
});

it('protects a team leader a dispatcher placed on a truck while they had no home unit', function () {
    $placed = ppUnit('BTL Placed');
    $elsewhere = ppUnit('BTL Elsewhere');
    $leader = ppPerson(3, 'Placed', 'Leader');
    $placed->update(['team_leader_id' => $leader->id]);

    btlPatch($this, $leader, $elsewhere->id)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Placed Leader is currently leading BTL Placed. Changing their home unit here would take them off BTL Placed. Ask a dispatcher to return them in Units & Leaders first, or set their home unit to BTL Placed.');
    expect($placed->fresh()->team_leader_id)->toBe($leader->id);

    btlPatch($this, $leader, $placed->id)->assertRedirect();
    expect($leader->fresh()->home_unit_id)->toBe($placed->id)->and($placed->fresh()->team_leader_id)->toBe($leader->id);
});

it('keeps the active job restriction for a team leader on their home unit', function () {
    $home = ppUnit('BTL Job');
    $next = ppUnit('BTL Job Next');
    $leader = ppPerson(3, 'Job', 'Leader', ['home_unit_id' => $home->id]);
    $home->update(['team_leader_id' => $leader->id, 'driver_name' => 'Some Driver']);
    $customer = Customer::create(['full_name' => 'BTL Customer', 'phone' => '0917' . fake()->numerify('#######'), 'email' => 'btl-' . uniqid() . '@example.com']);
    Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $home->truck_type_id, 'assigned_unit_id' => $home->id, 'assigned_team_leader_id' => $leader->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 1800, 'status' => 'on_the_way',
    ]);

    btlPatch($this, $leader, $next->id)->assertStatus(422)
        ->assertJsonPath('message', 'This Team Leader currently has an active job and cannot be moved.');

    expect($home->fresh()->team_leader_id)->toBe($leader->id)->and($leader->fresh()->home_unit_id)->toBe($home->id);
});

it('keeps the reservation restriction for a team leader on their home unit', function () {
    $home = ppUnit('BTL Reserved');
    $next = ppUnit('BTL Reserved Next');
    $leader = ppPerson(3, 'Reserved', 'Leader', ['home_unit_id' => $home->id]);
    $home->update(['team_leader_id' => $leader->id]);
    $customer = Customer::create(['full_name' => 'BTL Customer', 'phone' => '0917' . fake()->numerify('#######'), 'email' => 'btl-' . uniqid() . '@example.com']);
    $booking = Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $home->truck_type_id, 'selected_unit_id' => $home->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 1800, 'status' => 'confirmed',
    ]);

    btlPatch($this, $leader, $next->id)->assertStatus(422)
        ->assertJsonPath('message', "Cannot move the Team Leader from BTL Reserved — it is reserved for booking {$booking->booking_code}. Team changes are unavailable while a unit is reserved.");

    expect($home->fresh()->team_leader_id)->toBe($leader->id);
});

it('refuses a borrowed team leader through every request shape and reopens the dialog with the reason', function () {
    $home = ppUnit('BTL Form Home');
    $leader = btlBorrowed($home, ppUnit('BTL Form Away'));
    $other = ppUnit('BTL Form Other');
    $owner = ppOwner();

    $this->actingAs($owner)->post(route('superadmin.personnel.home-unit', $leader->id), ['_method' => 'PATCH', 'home_unit_id' => $other->id], ['Accept' => 'application/json'])
        ->assertStatus(422);

    $this->actingAs($owner)->from(route('superadmin.personnel.index'))
        ->patch(route('superadmin.personnel.home-unit', $leader->id), ['_modal' => 'account', '_edit_id' => $leader->id, 'home_unit_id' => $other->id])
        ->assertRedirect(route('superadmin.personnel.index'))
        ->assertSessionHasErrors('home_unit_id');

    $page = $this->actingAs($owner)->get(route('superadmin.personnel.index'));
    $page->assertSee('data-reopen="account"', false);
    $page->assertSee('Changing their home unit here would take them off BTL Form Away.');
    expect($leader->fresh()->home_unit_id)->toBe($home->id);
});

it('gives the Personnel and Home Assignments dialogs each leader’s current unit', function () {
    $home = ppUnit('BTL Data Home');
    $away = ppUnit('BTL Data Away');
    $leader = btlBorrowed($home, $away);
    $owner = ppOwner();

    $this->actingAs($owner)->get(route('superadmin.personnel.index'))
        ->assertSee('data-current-unit-id="' . $away->id . '"', false)
        ->assertSee('data-current-unit-name="BTL Data Away"', false);

    $content = $this->actingAs($owner)->get(route('superadmin.home-assignments.index'))->getContent();
    preg_match('/<script type="application\/json" id="homeTeamData">(.*?)<\/script>/s', $content, $m);
    $person = collect(json_decode($m[1], true)['people'])->firstWhere('key', 'account-' . $leader->id);

    expect($person['home'])->toBe($home->id)->and($person['current'])->toBe($away->id);
    expect($content)->toContain("A team leader on another truck today can’t be changed here until a dispatcher returns them.");
});

it('keeps the existing active job message first for a team leader placed without a home unit', function () {
    $placed = ppUnit('BTL Busy Placed');
    $leader = ppPerson(3, 'Busy', 'Placed');
    $placed->update(['team_leader_id' => $leader->id, 'driver_name' => 'Some Driver']);
    $customer = Customer::create(['full_name' => 'BTL Customer', 'phone' => '0917' . fake()->numerify('#######'), 'email' => 'btl-' . uniqid() . '@example.com']);
    Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $placed->truck_type_id, 'assigned_unit_id' => $placed->id, 'assigned_team_leader_id' => $leader->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 1800, 'status' => 'on_the_way',
    ]);

    btlPatch($this, $leader, ppUnit('BTL Busy Target')->id)->assertStatus(422)
        ->assertJsonPath('message', 'This Team Leader currently has an active job and cannot be moved.');
});

/* ---------- Atomic Home Team save (PUT superadmin.home-assignments.update) ---------- */

function habSave($test, User $owner, Unit $unit, array $desired, array $expected = [], ?string $token = null)
{
    return $test->actingAs($owner)->putJson(route('superadmin.home-assignments.update', $unit->id), [
        'token' => $token ?? app(\App\Services\PersonnelService::class)->homeTeamToken($unit->id),
        'team_leader' => $desired['team_leader'] ?? [],
        'driver' => $desired['driver'] ?? [],
        'crew' => $desired['crew'] ?? [],
        'expected' => (object) $expected,
    ]);
}

function habKey($person): string
{
    return ($person instanceof User ? 'account-' : 'record-') . $person->id;
}

function habSnapshot(): array
{
    // Write anything already queued (test setup) so only the batch is compared.
    \App\Observers\AuditObserver::flush();

    return [
        'units' => Unit::orderBy('id')->get(['id', 'team_leader_id', 'driver_id', 'driver_name', 'driver_personnel_id', 'crew_member_1_name', 'crew_member_1_personnel_id', 'archived_at'])->toArray(),
        'users' => User::orderBy('id')->get(['id', 'home_unit_id'])->toArray(),
        'records' => \App\Models\Personnel::orderBy('id')->get(['id', 'home_unit_id', 'personnel_status'])->toArray(),
        'loans' => \App\Models\UnitCrewLoan::orderBy('id')->get(['id', 'to_unit_id', 'returned_at'])->toArray(),
        'audits' => AuditLog::count(),
    ];
}

it('saves several home team changes together and audits each one', function () {
    $unit = ppUnit('HAB Multi');
    $leader = ppPerson(3, 'New', 'Leader');
    $driverAccount = ppPerson(4, 'Account', 'Driver');
    $driverRecord = ppRecord('Record', 'Driver', ['role' => 'driver']);
    $oldCrew = ppRecord('Old', 'Crew', ['home_unit_id' => $unit->id]);
    $newCrew = ppRecord('New', 'Crew');
    $owner = ppOwner();
    $auditsBefore = AuditLog::count();

    habSave($this, $owner, $unit, [
        'team_leader' => [habKey($leader)],
        'driver' => [habKey($driverAccount), habKey($driverRecord)],
        'crew' => [habKey($newCrew)],
    ], [habKey($leader) => null, habKey($driverAccount) => null, habKey($driverRecord) => null, habKey($newCrew) => null, habKey($oldCrew) => $unit->id])
        ->assertOk()
        ->assertJsonCount(5, 'saved')
        ->assertJsonPath('token', app(\App\Services\PersonnelService::class)->homeTeamToken($unit->id));

    expect($leader->fresh()->home_unit_id)->toBe($unit->id)
        ->and($unit->fresh()->team_leader_id)->toBe($leader->id)
        ->and($driverAccount->fresh()->home_unit_id)->toBe($unit->id)
        ->and($driverRecord->fresh()->home_unit_id)->toBe($unit->id)
        ->and($newCrew->fresh()->home_unit_id)->toBe($unit->id)
        ->and($oldCrew->fresh()->home_unit_id)->toBeNull()
        ->and(AuditLog::where('action', 'personnel_home_unit_changed')->count())->toBe(5)
        ->and(AuditLog::count())->toBeGreaterThan($auditsBefore);
});

it('replaces a team leader in one save, removal before addition', function () {
    $unit = ppUnit('HAB Swap');
    $old = ppPerson(3, 'Old', 'Leader', ['home_unit_id' => $unit->id]);
    $unit->update(['team_leader_id' => $old->id]);
    $new = ppPerson(3, 'New', 'Leader');

    habSave($this, $owner ?? ppOwner(), $unit, ['team_leader' => [habKey($new)]], [habKey($old) => $unit->id, habKey($new) => null])
        ->assertOk()
        ->assertJsonPath('saved.0.name', 'Old Leader')->assertJsonPath('saved.0.change', 'removed')
        ->assertJsonPath('saved.1.name', 'New Leader')->assertJsonPath('saved.1.change', 'added');

    expect($unit->fresh()->team_leader_id)->toBe($new->id)
        ->and($old->fresh()->home_unit_id)->toBeNull()
        ->and($new->fresh()->home_unit_id)->toBe($unit->id);
});

it('rolls back every change, including audit entries, when the final change fails', function () {
    $unit = ppUnit('HAB Rollback');
    $old = ppPerson(3, 'Old', 'Leader', ['home_unit_id' => $unit->id]);
    $unit->update(['team_leader_id' => $old->id]);
    $new = ppPerson(3, 'New', 'Leader');
    $driver = ppPerson(4, 'Account', 'Driver');
    $lastCrew = ppRecord('Last', 'Crew');
    $owner = ppOwner();
    $owner ??= ppOwner();
    $before = habSnapshot();

    // Simulate the last change failing inside the transaction.
    \App\Models\Personnel::updating(function ($record) use ($lastCrew) {
        if ($record->id === $lastCrew->id) {
            throw new \RuntimeException('Simulated failure on the last change.');
        }
    });

    habSave($this, $owner, $unit, [
        'team_leader' => [habKey($new)],
        'driver' => [habKey($driver)],
        'crew' => [habKey($lastCrew)],
    ], [habKey($old) => $unit->id, habKey($new) => null, habKey($driver) => null, habKey($lastCrew) => null])
        ->assertStatus(422)
        ->assertJsonPath('message', "Nothing was saved. Last Crew can't be added to this home team: Simulated failure on the last change.");

    \App\Models\Personnel::flushEventListeners();

    // End of request: nothing queued by the rolled-back changes may be written.
    \App\Observers\AuditObserver::flush();

    expect(habSnapshot())->toBe($before);
});

it('writes the queued audit entries for a successful save at the end of the request', function () {
    $unit = ppUnit('HAB Audit OK');
    $leader = ppPerson(3, 'Audited', 'Leader');
    $before = AuditLog::count();

    habSave($this, $owner ?? ppOwner(), $unit, ['team_leader' => [habKey($leader)]], [habKey($leader) => null])->assertOk();
    \App\Observers\AuditObserver::flush();

    // Manual personnel_home_unit_changed row plus observer diffs for the user and unit.
    expect(AuditLog::count())->toBeGreaterThan($before + 1);
});

it('rejects a stale dialog with 409 and changes nothing', function () {
    $unit = ppUnit('HAB Stale');
    $crew = ppRecord('Some', 'Crew');
    $staleToken = app(\App\Services\PersonnelService::class)->homeTeamToken($unit->id);
    ppRecord('Sneaky', 'Crew', ['home_unit_id' => $unit->id]); // someone else changed the team
    $owner ??= ppOwner();
    $before = habSnapshot();

    habSave($this, $owner ?? ppOwner(), $unit, ['crew' => [habKey($crew)]], [habKey($crew) => null], $staleToken)
        ->assertStatus(409)
        ->assertJsonPath('message', 'This home team was changed by someone else since you opened it. Nothing was saved. Close the dialog to see the latest team, then make your changes again.');

    expect(habSnapshot())->toBe($before);
});

it('rejects a change whose person moved since the dialog opened with 409', function () {
    $unit = ppUnit('HAB Moved');
    $elsewhere = ppUnit('HAB Elsewhere');
    $crew = ppRecord('Moved', 'Crew', ['home_unit_id' => $elsewhere->id]);

    habSave($this, $owner ?? ppOwner(), $unit, ['crew' => [habKey($crew)]], [habKey($crew) => null])
        ->assertStatus(409)
        ->assertJsonPath('message', "Moved Crew's home unit changed since you opened this dialog. Nothing was saved. Close the dialog to see the latest team, then make your changes again.");

    expect($crew->fresh()->home_unit_id)->toBe($elsewhere->id);
});

it('keeps the active job restriction and rolls back the rest', function () {
    $unit = ppUnit('HAB Job');
    $leader = ppPerson(3, 'Busy', 'Leader', ['home_unit_id' => $unit->id]);
    $unit->update(['team_leader_id' => $leader->id, 'driver_name' => 'Some Driver']);
    $customer = Customer::create(['full_name' => 'HAB Customer', 'phone' => '0917' . fake()->numerify('#######'), 'email' => 'hab-' . uniqid() . '@example.com']);
    Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $unit->truck_type_id, 'assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $leader->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 1800, 'status' => 'on_the_way',
    ]);
    $crew = ppRecord('Extra', 'Crew');
    $owner ??= ppOwner();
    $before = habSnapshot();

    habSave($this, $owner ?? ppOwner(), $unit, ['crew' => [habKey($crew)]], [habKey($leader) => $unit->id, habKey($crew) => null])
        ->assertStatus(422)
        ->assertJsonPath('message', "Nothing was saved. Busy Leader can't be removed from this home team: This Team Leader currently has an active job and cannot be moved.");

    \App\Observers\AuditObserver::flush();
    expect(habSnapshot())->toBe($before);
});

it('keeps the reservation restriction', function () {
    $unit = ppUnit('HAB Reserved');
    $leader = ppPerson(3, 'Reserved', 'Leader', ['home_unit_id' => $unit->id]);
    $unit->update(['team_leader_id' => $leader->id]);
    $customer = Customer::create(['full_name' => 'HAB Customer', 'phone' => '0917' . fake()->numerify('#######'), 'email' => 'hab-' . uniqid() . '@example.com']);
    Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $unit->truck_type_id, 'selected_unit_id' => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 1800, 'status' => 'confirmed',
    ]);

    habSave($this, $owner ?? ppOwner(), $unit, [], [habKey($leader) => $unit->id])->assertStatus(422);

    expect($unit->fresh()->team_leader_id)->toBe($leader->id)->and($leader->fresh()->home_unit_id)->toBe($unit->id);
});

it('keeps the borrowed team leader protection and rolls back the rest', function () {
    $home = ppUnit('HAB Home');
    $away = ppUnit('HAB Away');
    $leader = ppPerson(3, 'Borrowed', 'Leader', ['home_unit_id' => $home->id]);
    $home->update(['team_leader_id' => $leader->id]);
    app(\App\Services\UnitTeamAssignmentService::class)->assignTeamLeader($away, $leader->id, ppDispatcher());
    $crew = ppRecord('Extra', 'Crew');
    $owner ??= ppOwner();
    $before = habSnapshot();

    habSave($this, $owner ?? ppOwner(), $home, ['crew' => [habKey($crew)]], [habKey($leader) => $home->id, habKey($crew) => null])
        ->assertStatus(422)
        ->assertJsonPath('message', "Nothing was saved. Borrowed Leader can't be removed from this home team: Borrowed Leader is on HAB Away, borrowed from HAB Home. Changing their home unit here would take them off HAB Away. Ask a dispatcher to return them in Units & Leaders first, or set their home unit to HAB Away.");

    \App\Observers\AuditObserver::flush();
    expect(habSnapshot())->toBe($before);
});

it('refuses an archived unit', function () {
    $unit = ppUnit('HAB Archived');
    $unit->forceFill(['archived_at' => now()])->save();
    $crew = ppRecord('Some', 'Crew');

    habSave($this, $owner ?? ppOwner(), $unit, ['crew' => [habKey($crew)]], [habKey($crew) => null])
        ->assertStatus(422)
        ->assertJsonPath('message', "This unit is archived, so its home team can't be changed.");

    expect($crew->fresh()->home_unit_id)->toBeNull();
});

it('refuses people chosen for the wrong role', function () {
    $unit = ppUnit('HAB Roles');
    $driverAccount = ppPerson(4, 'Wrong', 'Role');
    $crew = ppRecord('Crew', 'Only');

    habSave($this, $owner ?? ppOwner(), $unit, ['team_leader' => [habKey($driverAccount)]], [habKey($driverAccount) => null])
        ->assertStatus(422)->assertJsonPath('message', "Wrong Role can't be chosen as a team leader. Nothing was saved.");
    habSave($this, $owner ?? ppOwner(), $unit, ['team_leader' => [habKey($crew)]], [habKey($crew) => null])
        ->assertStatus(422)->assertJsonValidationErrors('team_leader.0');

    expect($driverAccount->fresh()->home_unit_id)->toBeNull()->and($crew->fresh()->home_unit_id)->toBeNull();
});

it('is owner-only', function () {
    $unit = ppUnit('HAB Guard');
    $crew = ppRecord('Guarded', 'Crew');
    $payload = ['token' => str_repeat('a', 40), 'team_leader' => [], 'driver' => [], 'crew' => [habKey($crew)], 'expected' => [habKey($crew) => null]];

    $this->actingAs(ppDispatcher())->putJson(route('superadmin.home-assignments.update', $unit->id), $payload)->assertForbidden();
    auth()->logout();
    $this->putJson(route('superadmin.home-assignments.update', $unit->id), $payload)->assertUnauthorized();

    expect($crew->fresh()->home_unit_id)->toBeNull();
});

it('leaves dispatcher placements alone when only home units change', function () {
    $unit = ppUnit('HAB Placement');
    $placedOn = ppUnit('HAB Placed On');
    $crew = ppRecord('Placed', 'Crew', ['home_unit_id' => $placedOn->id]);
    $placedOn->update(['crew_member_1_name' => 'Placed Crew', 'crew_member_1_personnel_id' => $crew->id]);

    habSave($this, $owner ?? ppOwner(), $unit, ['crew' => [habKey($crew)]], [habKey($crew) => $placedOn->id])->assertOk();

    expect($crew->fresh()->home_unit_id)->toBe($unit->id)
        ->and($placedOn->fresh()->crew_member_1_personnel_id)->toBe($crew->id)
        ->and($unit->fresh()->crew_member_1_personnel_id)->toBeNull();
});

it('gives each Edit button the team token the batch checks', function () {
    $unit = ppUnit('HAB Token');
    ppRecord('Token', 'Crew', ['home_unit_id' => $unit->id]);

    $this->actingAs(ppOwner())->get(route('superadmin.home-assignments.index'))
        ->assertSee('data-team-token="' . app(\App\Services\PersonnelService::class)->homeTeamToken($unit->id) . '"', false)
        ->assertSee('data-save-url="' . route('superadmin.home-assignments.update', '__ID__') . '"', false);
});
