<?php

use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;

function omRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function omOwner(): User
{
    omRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function omDispatcher(): User
{
    omRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

function omTeamLeader(array $overrides = []): User
{
    omRole(3, 'Team Leader');

    return User::factory()->create(array_merge([
        'role_id' => 3,
        'status' => 'active',
        'must_change_password' => false,
    ], $overrides));
}

function omTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'OM Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function omUnit(array $overrides = []): Unit
{
    return Unit::create(array_merge([
        'name' => 'OM Unit ' . fake()->unique()->numerify('####'),
        'plate_number' => fake()->unique()->bothify('???####'),
        'truck_type_id' => omTruckType()->id,
        'status' => 'available',
    ], $overrides));
}

function omCustomer(array $overrides = []): Customer
{
    return Customer::create(array_merge([
        'full_name' => 'OM Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'om-' . uniqid() . '@example.com',
    ], $overrides));
}

function omBooking(array $overrides = []): \App\Models\Booking
{
    $customer = omCustomer();
    $truckType = omTruckType();

    return \App\Models\Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'OM Pickup Address',
        'dropoff_address' => 'OM Dropoff Address',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'requested',
    ], $overrides));
}

it('owner can access the operations monitor', function () {
    $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'))->assertOk();
});

it('dispatcher cannot access the owner operations monitor', function () {
    $this->actingAs(omDispatcher())->get(route('superadmin.monitoring.index'))->assertForbidden();
});

it('renders the operations monitor page title', function () {
    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertSee('Operations Monitor');
});

it('renders the operations summary metrics', function () {
    omBooking(['status' => 'requested']);
    omUnit(['status' => 'available']);

    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertSee('Operations Summary');
    $response->assertSee('Active Jobs');
    $response->assertSee('Pending Requests');
    $response->assertSee('Scheduled Today');
    $response->assertSee('Available Units');
    $response->assertSee('id="monitorActiveJobs"', false);
    $response->assertSee('id="monitorPendingRequests"', false);
    $response->assertSee('id="monitorUnitsReady"', false);
});

it('no longer renders the removed top-level KPI cards', function () {
    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertDontSee('Watchlist Customers');
    $response->assertDontSee('Blacklisted Customers');
    $response->assertDontSee('Returned Tasks');
});

it('renders needs attention with the existing alert logic when issues exist', function () {
    omBooking([
        'status' => 'assigned',
        'assigned_unit_id' => null,
    ]);

    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertSee('Needs Attention');
    $response->assertSee('Bookings still need a unit');
});

it('renders a clean no-issues message when nothing needs attention', function () {
    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertSee('No urgent blockers');
});

it('renders current operations pipeline counts and the active jobs table', function () {
    $booking = omBooking(['status' => 'in_progress']);

    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertSee('Current Operations');
    $response->assertSee('In Progress');
    $response->assertSee($booking->job_code);
});

it('renders team leader readiness with presence and workload columns', function () {
    $leader = omTeamLeader(['name' => 'OM Online Leader', 'last_ping_at' => now()]);

    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertSee('Team Leaders');
    $response->assertSee('OM Online Leader');
    $response->assertSee('Online');
});

it('renders unit readiness with availability text', function () {
    $unit = omUnit(['status' => 'available']);

    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertSee('Units');
    $response->assertSee($unit->name);
    $response->assertSee('Available');
});

it('stacks team leaders and units full-width instead of a two-column grid', function () {
    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->toContain('monitor-readiness-stack');
    expect($content)->not->toContain('class="owner-grid"');
});

it('renders a clean empty state when the risk watchlist has no flagged customers', function () {
    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertSee('Risk Watchlist');
    $response->assertSee('No flagged customers.');
});

it('renders flagged customers in the risk watchlist when they exist', function () {
    omCustomer(['full_name' => 'OM Flagged Customer', 'risk_level' => 'watchlist']);

    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertSee('OM Flagged Customer');
});

it('does not expose dispatch, assignment, or personnel mutation controls', function () {
    omBooking();
    omTeamLeader();
    omUnit();

    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertDontSee('assign-team-leader', false);
    $response->assertDontSee('js-assign-leader', false);
    $response->assertDontSee('borrow-crew', false);
    $response->assertDontSee('name="team_leader_id"', false);
    $response->assertDontSee('name="driver_id"', false);
    $response->assertDontSee('route(\'superadmin.units.toggle', false);
    $response->assertDontSee('route(\'superadmin.units.archive', false);
});

it('continues to accept the existing search query parameter without erroring', function () {
    omBooking(['pickup_address' => 'OM Findable Pickup']);

    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index', ['search' => 'Findable']));

    $response->assertOk();
});

it('continues to support the existing status filter', function () {
    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index', ['status' => 'completed']));

    $response->assertOk();
});

it('continues to support the existing period filter', function () {
    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index', ['period' => 'week']));

    $response->assertOk();
});

it('keeps the live monitoring endpoint returning the full stats payload used by the summary metrics', function () {
    omBooking(['status' => 'requested']);

    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.live'));

    $response->assertOk();
    $response->assertJsonStructure([
        'monitoringStats' => [
            'active_jobs',
            'pending_requests',
            'scheduled_today',
            'available_units',
            'online_team_leaders',
            'dispatchers',
            'watchlist_customers',
            'blacklisted_customers',
        ],
    ]);
});

it('no longer renders the recent system activity audit feed on the operations monitor', function () {
    $response = $this->actingAs(omOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertDontSee('Recent System Activity');
});
