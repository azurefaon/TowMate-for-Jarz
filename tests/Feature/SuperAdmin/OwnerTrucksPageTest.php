<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\UnitAvailabilityService;
use Illuminate\Support\Facades\Route;

function trucksRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function trucksOwner(): User
{
    trucksRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function trucksDispatcher(): User
{
    trucksRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

function trucksTeamLeader(string $name): User
{
    trucksRole(3, 'Team Leader');

    return User::factory()->create([
        'role_id' => 3,
        'status' => 'active',
        'must_change_password' => false,
        'name' => $name,
    ]);
}

function trucksTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'TRK Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function trucksUnit(array $overrides = []): Unit
{
    return Unit::create(array_merge([
        'name' => 'TRK Unit ' . fake()->unique()->numerify('####'),
        'plate_number' => fake()->unique()->bothify('???####'),
        'truck_type_id' => trucksTruckType()->id,
        'status' => 'available',
    ], $overrides));
}

it('owner can access trucks', function () {
    $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'))->assertOk();
});

it('dispatcher cannot access owner trucks', function () {
    $this->actingAs(trucksDispatcher())->get(route('superadmin.unit-truck.index'))->assertForbidden();
});

it('owner can create a valid truck', function () {
    $owner = trucksOwner();
    $truckType = trucksTruckType();

    $response = $this->actingAs($owner)->post(route('superadmin.units.store'), [
        'plate_number' => 'ABC1234',
        'truck_type_id' => $truckType->id,
    ]);

    $response->assertRedirect(route('superadmin.unit-truck.index'));
    $this->assertDatabaseHas('units', ['plate_number' => 'ABC1234', 'truck_type_id' => $truckType->id]);
});

it('owner can edit plate number and truck type', function () {
    $owner = trucksOwner();
    $unit = trucksUnit();
    $newTruckType = trucksTruckType();

    $response = $this->actingAs($owner)->put(route('superadmin.units.update', $unit->id), [
        'plate_number' => 'XYZ9876',
        'truck_type_id' => $newTruckType->id,
    ]);

    $response->assertRedirect(route('superadmin.unit-truck.index'));
    $this->assertDatabaseHas('units', [
        'id' => $unit->id,
        'plate_number' => 'XYZ9876',
        'truck_type_id' => $newTruckType->id,
    ]);
});

it('places an available unit into maintenance', function () {
    $owner = trucksOwner();
    $unit = trucksUnit(['status' => 'available']);

    $response = $this->actingAs($owner)->patch(route('superadmin.units.toggle', $unit->id));

    $response->assertRedirect();
    $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'maintenance']);
});

it('returns a maintenance unit to available', function () {
    $owner = trucksOwner();
    $unit = trucksUnit(['status' => 'maintenance', 'issue_note' => 'Brake inspection']);

    $response = $this->actingAs($owner)->patch(route('superadmin.units.toggle', $unit->id));

    $response->assertRedirect();
    $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'available', 'issue_note' => null]);
});

it('rejects the maintenance toggle for an on_job unit and preserves its status', function () {
    $owner = trucksOwner();
    $unit = trucksUnit(['status' => 'on_job']);

    $response = $this->actingAs($owner)->patch(route('superadmin.units.toggle', $unit->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'on_job']);
});

it('does not expose personnel mutation controls on the owner trucks page', function () {
    $owner = trucksOwner();
    trucksUnit();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertDontSee('assign-team-leader', false);
    $response->assertDontSee('js-assign-leader', false);
    $response->assertDontSee('remove-team-leader', false);
    $response->assertDontSee('transfer-team', false);
    $response->assertDontSee('borrow-crew', false);
});

it('shows current personnel information read-only on the owner trucks page', function () {
    $owner = trucksOwner();
    $leader = trucksTeamLeader('Read Only Leader');
    trucksUnit(['team_leader_id' => $leader->id]);

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('Read Only Leader');
});

it('keeps archive blocked while a unit is on_job', function () {
    $owner = trucksOwner();
    $unit = trucksUnit(['status' => 'on_job']);

    $response = $this->actingAs($owner)->patch(route('superadmin.units.archive', $unit->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('units', ['id' => $unit->id, 'archived_at' => null]);
});

it('no longer resolves the removed units disable route', function () {
    expect(Route::has('superadmin.units.disable'))->toBeFalse();

    $owner = trucksOwner();
    $unit = trucksUnit();

    $this->actingAs($owner)->patch("/superadmin/units/{$unit->id}/disable")->assertNotFound();
});
it('opens Fleet Management with Trucks active on the Trucks page', function () {
    $owner = trucksOwner();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('>Fleet Management<', false);
    expect(preg_match('/id="sidebarGroupFleetToggle"\s*aria-expanded="true"/', $response->getContent()))->toBe(1);
    $response->assertSee('aria-current="page">Trucks</a>', false);
});

it('renders the results summary and loads page two of trucks when enough units exist', function () {
    $owner = trucksOwner();

    for ($i = 0; $i < 15; $i++) {
        trucksUnit();
    }

    $page1 = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));
    $page2 = $this->actingAs($owner)->get(route('superadmin.unit-truck.index', ['page' => 2]));

    $page1->assertOk();
    $page2->assertOk();
    $page1->assertSee('Showing 1', false);
    $page2->assertSee('Showing 11', false);
});

it('keeps trucks ordered newest first across paginated pages', function () {
    $owner = trucksOwner();

    $oldest = trucksUnit();
    $oldest->forceFill(['created_at' => now()->subDays(2)])->save();

    for ($i = 0; $i < 9; $i++) {
        trucksUnit();
    }

    $newest = trucksUnit();
    $newest->forceFill(['created_at' => now()->addMinute()])->save();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee($newest->name);
    $response->assertDontSee($oldest->name);
});

/* ---------- Revision 2 layout ---------- */

function trucksBooking(Unit $unit, string $status, string $link = 'assigned_unit_id'): Booking
{
    $customer = Customer::create([
        'full_name' => 'TRK Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'trk-' . uniqid() . '@example.com',
    ]);

    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        $link => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 1800,
        'status' => $status,
    ]);
}

/** A unit that UnitAvailabilityService considers ready: active team leader + driver. */
function trucksReadyUnit(array $overrides = []): Unit
{
    $leader = trucksTeamLeader('Leader ' . fake()->unique()->firstName());

    return trucksUnit(array_merge(['team_leader_id' => $leader->id, 'driver_name' => 'Jun Dela Cruz'], $overrides));
}

it('shows only Truck, Plate, Truck type, Team, Status and Actions with no pricing or fleet tabs', function () {
    trucksUnit();

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSeeInOrder(['>Truck<', '>Plate<', '>Truck type<', '>Team<', '>Status<', '>Actions<'], false);
    $response->assertDontSee('Base Rate');
    $response->assertDontSee('Per km');
    $response->assertDontSee('Max Load');
    $response->assertDontSee('class="fleet-tabs"', false);
    $response->assertSee('Every truck in the fleet, its team and whether it can take a job.');
    $response->assertSee('placeholder="Search unit name, plate or team leader"', false);
});

it('formats the plate and shows the truck type class', function () {
    $type = TruckType::create(['name' => 'Light Duty', 'class' => 'light', 'base_rate' => 1500, 'per_km_rate' => 200, 'status' => 'active']);
    trucksUnit(['plate_number' => 'NAB4521', 'truck_type_id' => $type->id]);

    $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'))
        ->assertSee('NAB 4521')
        ->assertSeeInOrder(['Light Duty', 'Light class']);
});

it('shows team details read-only', function () {
    $unit = trucksReadyUnit(['crew_member_1_name' => 'Crew One', 'crew_member_2_name' => 'Crew Two']);

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertSee($unit->teamLeader->full_name ?: $unit->teamLeader->name);
    $response->assertSee('Driver: Jun Dela Cruz · Crew: 2');
    $response->assertSee('Dispatchers assign and change the team.');
    $response->assertDontSee('name="team_leader_id"', false);
});

/* ---------- Readiness from UnitAvailabilityService ---------- */

it('labels a ready truck Available', function () {
    trucksReadyUnit();

    $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'))
        ->assertSee('tk-badge is-available">Available<', false);
});

it('labels a truck without team leader or driver Not ready with both reasons', function () {
    trucksUnit();

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertSee('tk-badge is-not_ready">Not ready<', false);
    $response->assertSee('No team leader, no driver');
    $response->assertSee('No driver · No crew');
});

it('shows off-duty reasons from the availability service', function () {
    $leader = trucksTeamLeader('Paolo Lim');
    $leader->forceFill(['duty_status' => 'unavailable'])->save();
    trucksUnit(['team_leader_id' => $leader->id, 'driver_name' => 'Leo Santos', 'driver_duty_status' => 'unavailable']);

    $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'))
        ->assertSee('Team leader off duty, driver off duty');
});

it('labels maintenance with the issue note and the other blockers', function () {
    trucksUnit(['status' => 'maintenance', 'issue_note' => 'Brake repair']);

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertSee('tk-badge is-maintenance">Maintenance<', false);
    $response->assertSee('Brake repair');
    $response->assertSee('Also: no team leader, no driver');
});

it('labels a truck on an active booking On job with the booking number', function () {
    $unit = trucksReadyUnit();
    $booking = trucksBooking($unit, 'assigned');

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertSee('tk-badge is-on_job">On job<', false);
    $response->assertSee('Booking ' . $booking->job_code);
});

it('labels a truck held by a booking under review Reserved', function () {
    $unit = trucksReadyUnit();
    $booking = trucksBooking($unit, 'quoted', 'selected_unit_id');

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertSee('tk-badge is-reserved">Reserved<', false);
    $response->assertSee('Held for booking ' . $booking->job_code);
});

it('matches the availability service for every truck', function () {
    trucksReadyUnit();
    trucksReadyUnit();
    trucksUnit();
    trucksUnit(['status' => 'maintenance']);
    trucksBooking(trucksReadyUnit(), 'on_the_way');
    trucksBooking(trucksReadyUnit(), 'reviewed', 'selected_unit_id');

    $rows = app(UnitAvailabilityService::class)->evaluateAll();
    $available = $rows->where('available', true)->count();

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    expect(substr_count($response->getContent(), 'tk-badge is-available"'))->toBe($available * 2); // row + view dialog
    $response->assertSee('>Available (' . $available . ')<', false);
    $response->assertSee('>On job (1)<', false);
    $response->assertSee('>Reserved (1)<', false);
    $response->assertSee('>Maintenance (1)<', false);
    $response->assertSee('>Not ready (1)<', false);
    $response->assertSee('>All statuses (6)<', false);
});

/* ---------- Search, filter, pagination ---------- */

it('searches unit name, plate and team leader case-insensitively across every page', function () {
    $leader = trucksTeamLeader('Ramil Navarro');
    $target = trucksUnit(['name' => 'JARZ 99', 'plate_number' => 'NAB4521', 'team_leader_id' => $leader->id]);
    $target->forceFill(['created_at' => now()->subDays(5)])->save();
    foreach (range(1, 12) as $i) {
        trucksUnit();
    }

    $owner = trucksOwner();
    foreach (['jarz 99', 'nab 4521', 'NAB45', 'ramil', 'NAVARRO'] as $search) {
        $this->actingAs($owner)->get(route('superadmin.unit-truck.index', ['search' => $search]))
            ->assertSee('JARZ 99')
            ->assertSee('Showing 1–1 of 1 truck');
    }

    $this->actingAs($owner)->get(route('superadmin.unit-truck.index', ['search' => 'zzzz']))
        ->assertSee('No trucks match these filters');
});

it('filters by readiness status on the server and keeps filters in page links', function () {
    foreach (range(1, 12) as $i) {
        trucksUnit(['status' => 'maintenance']);
    }
    trucksReadyUnit(['name' => 'Ready Truck']);

    $owner = trucksOwner();

    $first = $this->actingAs($owner)->get(route('superadmin.unit-truck.index', ['status' => 'maintenance']));
    $first->assertSee('Showing 1–10 of 12 trucks');
    $first->assertDontSee('Ready Truck');
    $first->assertSee('status=maintenance', false);
    $first->assertSee('page=2', false);

    $this->actingAs($owner)->get(route('superadmin.unit-truck.index', ['status' => 'maintenance', 'page' => 2]))
        ->assertSee('Showing 11–12 of 12 trucks');

    $this->actingAs($owner)->get(route('superadmin.unit-truck.index', ['status' => 'available']))
        ->assertSee('Ready Truck')->assertSee('Showing 1–1 of 1 truck');

    $this->actingAs($owner)->get(route('superadmin.unit-truck.index', ['status' => 'bogus']))
        ->assertSee('Showing 1–10 of 13 trucks');
});

it('sends a page past the end back to the last page', function () {
    trucksUnit(['name' => 'Only Truck']);

    $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index', ['search' => 'only', 'page' => 4]))
        ->assertRedirect(route('superadmin.unit-truck.index', ['search' => 'only', 'page' => 1]));
});

it('shows a first-run empty state when there are no trucks', function () {
    $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'))
        ->assertSee('No trucks yet')
        ->assertDontSee('Clear filters');
});

/* ---------- Header ---------- */

it('links to archived trucks with the archived count', function () {
    $unit = trucksUnit();
    $unit->forceFill(['archived_at' => now()])->save();

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertSee('href="' . route('superadmin.units.archived') . '"', false);
    $response->assertSee('Archived trucks (1)');
    $response->assertDontSee($unit->name);
});

/* ---------- Actions ---------- */

it('offers maintenance and archive for a truck in service', function () {
    $unit = trucksUnit();

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertSee('aria-label="Edit ' . $unit->name . '"', false);
    $response->assertSee('aria-label="More actions for ' . $unit->name . '"', false);
    $response->assertSee('Move to maintenance');
    $response->assertSee('data-action="archive"', false);
    $response->assertDontSee('Return to service');
    $response->assertDontSee('aria-disabled="true"', false);
});

it('offers return to service for a truck in maintenance', function () {
    trucksUnit(['status' => 'maintenance']);

    $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'))
        ->assertSee('Return to service')
        ->assertDontSee('Move to maintenance');
});

it('explains why maintenance and archive are unavailable for an on_job unit', function () {
    trucksUnit(['status' => 'on_job']);

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    expect(substr_count($response->getContent(), 'aria-disabled="true"'))->toBe(2);
    $response->assertSee('It is assigned to an active job.');
});

it('keeps the forms wired to the existing routes', function () {
    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertSee('data-toggle-url="' . route('superadmin.units.toggle', '__ID__') . '"', false);
    $response->assertSee('data-archive-url="' . route('superadmin.units.archive', '__ID__') . '"', false);
    $response->assertSee('data-update-url="' . route('superadmin.units.update', '__ID__') . '"', false);
    $response->assertSee('action="' . route('superadmin.units.store') . '"', false);
    $response->assertSee('name="_token"', false);
});

it('returns to the same filtered list after archiving', function () {
    $unit = trucksUnit();
    $from = route('superadmin.unit-truck.index', ['search' => 'trk', 'status' => 'not_ready']);

    $this->actingAs(trucksOwner())->from($from)->patch(route('superadmin.units.archive', $unit->id))
        ->assertRedirect($from)
        ->assertSessionHas('success', "{$unit->name} moved to archived trucks.");

    expect($unit->fresh()->archived_at)->not->toBeNull();
});

it('still restores an archived truck from the archived page', function () {
    $unit = trucksUnit();
    $unit->forceFill(['archived_at' => now()])->save();

    $this->actingAs(trucksOwner())->patch(route('superadmin.units.restore', $unit->id))
        ->assertRedirect(route('superadmin.units.archived'));

    expect($unit->fresh()->archived_at)->toBeNull();
});

/* ---------- Add / edit form ---------- */

it('shows the generated unit name read-only in the add form', function () {
    trucksUnit(['name' => 'JARZ 4']);

    $content = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'))->getContent();

    expect($content)->toContain('aria-readonly="true" aria-labelledby="tkUnitNameLabel">JARZ 5<')
        ->not->toContain('name="name"')
        ->not->toContain('name="team_leader_id"')
        ->not->toContain('name="driver_id"');
});

it('lists active truck types with their class in the form', function () {
    TruckType::create(['name' => 'Medium Duty', 'class' => 'medium', 'base_rate' => 1, 'per_km_rate' => 1, 'status' => 'active']);
    TruckType::create(['name' => 'Retired Type', 'base_rate' => 1, 'per_km_rate' => 1, 'status' => 'inactive']);

    $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'))
        ->assertSee('Medium Duty · Medium class')
        ->assertDontSee('Retired Type');
});

it('keeps an inactive current truck type available when editing', function () {
    $type = TruckType::create(['name' => 'Retired Type', 'class' => 'heavy', 'base_rate' => 1, 'per_km_rate' => 1, 'status' => 'inactive']);
    trucksUnit(['truck_type_id' => $type->id]);

    $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'))
        ->assertSee('data-type-label="Retired Type · Heavy class (inactive)"', false);
});

it('accepts a plate typed with a space and lowercase', function () {
    $type = trucksTruckType();

    $this->actingAs(trucksOwner())->post(route('superadmin.units.store'), ['plate_number' => 'abc 1234', 'truck_type_id' => $type->id])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('units', ['plate_number' => 'ABC1234']);
});

it('shows field errors and keeps exactly what was typed', function () {
    trucksUnit(['plate_number' => 'ABC1234']);
    $owner = trucksOwner();
    $from = route('superadmin.unit-truck.index', ['status' => 'not_ready']);

    $response = $this->actingAs($owner)->from($from)->post(route('superadmin.units.store'), [
        '_modal' => 'add', 'plate_number' => 'abc 1234', 'truck_type_id' => '',
    ]);

    $response->assertRedirect($from);
    $response->assertSessionHasErrors([
        'plate_number' => 'Another truck already uses this plate number.',
        'truck_type_id' => 'Choose a truck type.',
    ]);
    $response->assertSessionHasInput('plate_number', 'abc 1234');

    $page = $this->actingAs($owner)->get($from);
    $page->assertSee('data-reopen="add"', false);
    $page->assertSee('Check the 2 highlighted fields. Everything else you entered is kept.');
    $page->assertSee('value="abc 1234"', false);
    $page->assertSee('id="tkPlateError"', false);
});

it('explains the plate format when it is invalid', function () {
    $this->actingAs(trucksOwner())->post(route('superadmin.units.store'), ['plate_number' => 'AB-12', 'truck_type_id' => trucksTruckType()->id])
        ->assertSessionHasErrors(['plate_number' => 'Plate number must be 3 letters + 4 digits, 3 letters + 3 digits, or 2 letters + 5 digits, without the letters I or O.']);
});

it('reopens the edit form for the same truck after a failed save', function () {
    $unit = trucksUnit(['name' => 'JARZ 7']);
    $owner = trucksOwner();

    $this->actingAs($owner)->from(route('superadmin.unit-truck.index'))
        ->put(route('superadmin.units.update', $unit->id), [
            '_modal' => 'edit', '_edit_id' => $unit->id, 'plate_number' => 'AB-12', 'truck_type_id' => $unit->truck_type_id,
        ])
        ->assertSessionHasErrors('plate_number');

    $page = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));
    $page->assertSee('data-reopen="edit"', false);
    $page->assertSee('action="' . route('superadmin.units.update', $unit->id) . '"', false);
    $page->assertSee('Edit truck');
    $page->assertSee('Check the highlighted field. Everything else you entered is kept.');
});

it('returns to the same filtered page after a successful edit', function () {
    $unit = trucksUnit();
    $from = route('superadmin.unit-truck.index', ['search' => 'trk', 'page' => 1]);

    $this->actingAs(trucksOwner())->from($from)
        ->put(route('superadmin.units.update', $unit->id), ['plate_number' => 'XYZ9876', 'truck_type_id' => $unit->truck_type_id])
        ->assertRedirect($from)
        ->assertSessionHas('success', "{$unit->name} updated.");
});

/* ---------- Maintenance / archive safety (live bookings, not unit.status) ---------- */

dataset('unit actions', [
    'maintenance' => ['superadmin.units.toggle', 'go to maintenance'],
    'archive' => ['superadmin.units.archive', 'be archived'],
]);

function trucksActionApplied(Unit $unit, string $route): bool
{
    $fresh = $unit->fresh();

    return $route === 'superadmin.units.toggle' ? $fresh->status === 'maintenance' : $fresh->archived_at !== null;
}

it('blocks the action while the truck is on an active job, even with status available', function (string $route, string $verb) {
    $unit = trucksReadyUnit(['status' => 'available']);
    $booking = trucksBooking($unit, 'on_the_way');

    $this->actingAs(trucksOwner())->patch(route($route, $unit->id))
        ->assertRedirect()
        ->assertSessionHas('error', "{$unit->name} can't {$verb} while it is on booking {$booking->job_code}.");

    expect(trucksActionApplied($unit, $route))->toBeFalse();
})->with('unit actions');

it('blocks the action while a booking has the truck reserved', function (string $route, string $verb) {
    $unit = trucksReadyUnit();
    $booking = trucksBooking($unit, 'confirmed', 'selected_unit_id');

    $this->actingAs(trucksOwner())->patch(route($route, $unit->id))
        ->assertSessionHas('error', "{$unit->name} can't {$verb} while booking {$booking->job_code} has it reserved.");

    expect(trucksActionApplied($unit, $route))->toBeFalse();
})->with('unit actions');

it('blocks the action for a job assigned to the team leader with no unit recorded', function (string $route) {
    $unit = trucksReadyUnit();
    $booking = trucksBooking($unit, 'assigned');
    $booking->forceFill(['assigned_unit_id' => null, 'assigned_team_leader_id' => $unit->team_leader_id])->save();

    $this->actingAs(trucksOwner())->patch(route($route, $unit->id))->assertSessionHas('error');

    expect(trucksActionApplied($unit, $route))->toBeFalse();
})->with('unit actions');

it('allows the action for an available truck', function (string $route) {
    $unit = trucksReadyUnit();

    $this->actingAs(trucksOwner())->patch(route($route, $unit->id))->assertSessionHas('success');

    expect(trucksActionApplied($unit, $route))->toBeTrue();
})->with('unit actions');

it('allows the action for a truck with no team leader, driver, or crew', function (string $route) {
    $unit = trucksUnit();

    $this->actingAs(trucksOwner())->patch(route($route, $unit->id))->assertSessionHas('success');

    expect(trucksActionApplied($unit, $route))->toBeTrue();
})->with('unit actions');

it('allows the action when the team and driver are off duty', function (string $route) {
    $unit = trucksReadyUnit(['driver_duty_status' => 'unavailable']);
    $unit->teamLeader->forceFill(['duty_status' => 'unavailable'])->save();

    $this->actingAs(trucksOwner())->patch(route($route, $unit->id))->assertSessionHas('success');

    expect(trucksActionApplied($unit, $route))->toBeTrue();
})->with('unit actions');

it('allows the action when the team leader is busy on a different truck', function (string $route) {
    $unit = trucksReadyUnit();
    $other = trucksUnit();
    $booking = trucksBooking($other, 'on_the_way');
    $booking->forceFill(['assigned_team_leader_id' => $unit->team_leader_id])->save();

    $this->actingAs(trucksOwner())->patch(route($route, $unit->id))->assertSessionHas('success');

    expect(trucksActionApplied($unit, $route))->toBeTrue();
})->with('unit actions');

it('allows the action once the booking is finished', function (string $route) {
    $unit = trucksReadyUnit();
    trucksBooking($unit, 'completed');
    trucksBooking($unit, 'cancelled', 'selected_unit_id');

    $this->actingAs(trucksOwner())->patch(route($route, $unit->id))->assertSessionHas('success');

    expect(trucksActionApplied($unit, $route))->toBeTrue();
})->with('unit actions');

it('still returns a reserved truck in maintenance to service', function () {
    $unit = trucksReadyUnit(['status' => 'maintenance']);
    trucksBooking($unit, 'confirmed', 'selected_unit_id');

    $this->actingAs(trucksOwner())->patch(route('superadmin.units.toggle', $unit->id))->assertSessionHas('success');

    expect($unit->fresh()->status)->toBe('available');
});

it('keeps the dispatcher assignment untouched when an action is blocked', function () {
    $unit = trucksReadyUnit();
    $booking = trucksBooking($unit, 'on_the_way');

    $this->actingAs(trucksOwner())->patch(route('superadmin.units.archive', $unit->id));

    expect($booking->fresh()->assigned_unit_id)->toBe($unit->id)
        ->and($unit->fresh()->team_leader_id)->toBe($unit->team_leader_id);
});

it('shows the blocking booking in the menu', function () {
    $onJob = trucksReadyUnit(['name' => 'JARZ 21']);
    $job = trucksBooking($onJob, 'on_the_way');
    $reserved = trucksReadyUnit(['name' => 'JARZ 22']);
    $hold = trucksBooking($reserved, 'confirmed', 'selected_unit_id');
    trucksUnit(['name' => 'JARZ 23']);

    $response = $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertSee("It is on booking {$job->job_code}.");
    $response->assertSee("Booking {$hold->job_code} has it reserved.");
    expect(substr_count($response->getContent(), 'aria-disabled="true"'))->toBe(4);
});

it('does not lock the menu when the team leader is busy on a different truck', function () {
    $unit = trucksReadyUnit();
    $booking = trucksBooking(trucksUnit(), 'on_the_way');
    $booking->forceFill(['assigned_team_leader_id' => $unit->team_leader_id])->save();

    $this->actingAs(trucksOwner())->get(route('superadmin.unit-truck.index'))
        ->assertSee('aria-describedby="tkMaintWhy' . $booking->assigned_unit_id . '"', false)
        ->assertDontSee('aria-describedby="tkMaintWhy' . $unit->id . '"', false)
        ->assertDontSee('aria-describedby="tkArchiveWhy' . $unit->id . '"', false);
});
