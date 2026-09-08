<?php

use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
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

it('renders a three-dot action trigger per unit instead of permanent text buttons', function () {
    $owner = trucksOwner();
    $unit = trucksUnit();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('data-lucide="more-vertical"', false);
    $response->assertSee('aria-label="Actions for ' . $unit->name . '"', false);
    $response->assertDontSee('class="action-btn edit-btn js-edit-unit"', false);
});

it('no longer renders the permanent Edit, Disable, and Archive text-button row', function () {
    $owner = trucksOwner();
    trucksUnit();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertDontSee('class="row-actions"', false);
    $response->assertDontSee('class="action-btn archive-btn"', false);
});

it('exposes edit, disable, and archive as dropdown actions for an available unit', function () {
    $owner = trucksOwner();
    $unit = trucksUnit(['status' => 'available']);

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('Edit Unit');
    $response->assertSee('Disable Unit');
    $response->assertSee('Archive Unit');
    $response->assertDontSee('Enable Unit');
});

it('exposes enable instead of disable as a dropdown action for a maintenance unit', function () {
    $owner = trucksOwner();
    $unit = trucksUnit(['status' => 'maintenance']);

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('Enable Unit');
    $response->assertDontSee('Disable Unit');
});

it('disables the dropdown disable and archive actions for an on_job unit', function () {
    $owner = trucksOwner();
    $unit = trucksUnit(['status' => 'on_job']);

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('Disable Unit');
    $response->assertSee('Archive Unit');
    expect(substr_count($response->getContent(), 'disabled'))->toBeGreaterThanOrEqual(2);
});

it('keeps the dropdown actions wired to the existing toggle and archive routes and forms', function () {
    $owner = trucksOwner();
    $unit = trucksUnit(['status' => 'available']);

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('action="' . route('superadmin.units.toggle', $unit->id) . '"', false);
    $response->assertSee('action="' . route('superadmin.units.archive', $unit->id) . '"', false);
    $response->assertSee('data-id="' . $unit->id . '"', false);
});

it('keeps the fleet tabs showing Truck Types & Rates, Trucks, and Vehicle Types', function () {
    $owner = trucksOwner();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('Truck Types &amp; Rates', false);
    $response->assertSee('Trucks');
    $response->assertSee('Vehicle Types');
});

it('keeps Fleet Management as the single sidebar fleet entry on the Trucks page', function () {
    $owner = trucksOwner();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('>Fleet Management<', false);
    $response->assertDontSee('sidebarGroupFleet', false);
});

it('gives the Archived Units link its own visually distinct button treatment with an archive icon', function () {
    $owner = trucksOwner();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('class="u-archived-btn"', false);
    $response->assertSee('data-lucide="archive"', false);
});

it('keeps the Add Truck modal present with its exact existing form action, method, and CSRF protection', function () {
    $owner = trucksOwner();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('id="addUnitModal"', false);
    $response->assertSee('action="' . route('superadmin.units.store') . '"', false);
    $response->assertSee('name="_token"', false);
});

it('keeps the Plate Number and Truck Type fields in the Add Truck modal', function () {
    $owner = trucksOwner();
    $truckType = trucksTruckType();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('name="plate_number" id="addPlate"', false);
    $response->assertSee('name="truck_type_id" id="addTruckType"', false);
    $response->assertSee($truckType->name);
});

it('shows the real generated Unit Name as read-only text rather than an editable or submitted field', function () {
    $owner = trucksOwner();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->toContain('u-readonly-field');
    expect($content)->not->toContain('name="unit_name"');
    expect($content)->not->toContain('name="name"');
});

it('does not introduce Team Leader, Driver, or Crew assignment fields in the Add Truck modal', function () {
    $owner = trucksOwner();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertDontSee('name="team_leader_id"', false);
    $response->assertDontSee('name="driver_id"', false);
    $response->assertDontSee('name="crew_member', false);
    $response->assertDontSee('Assign Team Leader');
    $response->assertDontSee('Assign Driver');
});

it('keeps Cancel and Add Truck actions present in the modal footer', function () {
    $owner = trucksOwner();

    $response = $this->actingAs($owner)->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    $response->assertSee('data-close-modal="addUnitModal">Cancel</button>', false);
    $response->assertSee('data-lucide="plus"></i>', false);
});
