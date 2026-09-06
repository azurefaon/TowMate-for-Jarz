<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * towmate_jarz_testing already carries the same baseline roles (id 1-4) the
 * real seed migration inserts via insertOrIgnore — using the same technique
 * here avoids colliding with that persistent row on the unique `name`
 * column. Only the numeric role_id matters for RoleMiddleware.
 */
function ajRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function ajDispatcher(): User
{
    return User::factory()->create(['role_id' => 2]);
}

function ajTruckType(string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'Active Jobs test truck',
    ]);
}

function ajCustomer(string $name): Customer
{
    return Customer::create([
        'full_name' => $name,
        'age' => 30,
        'phone' => '09171234567',
        'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
    ]);
}

function ajTeamLeader(string $name): User
{
    return User::factory()->create(['role_id' => 3, 'name' => $name]);
}

function ajBooking(string $status, array $overrides = []): Booking
{
    $truckType = ajTruckType('AJ Truck ' . uniqid());
    $customer = ajCustomer($overrides['customer_name'] ?? 'AJ Customer ' . uniqid());
    $teamLeader = $overrides['team_leader'] ?? null;

    $unit = Unit::create([
        'name' => $overrides['unit_name'] ?? 'AJ Unit ' . uniqid(),
        'plate_number' => 'AJ-' . rand(1000, 9999),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader?->id,
        'status' => 'on_job',
    ]);

    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader?->id,
        'age' => 30,
        'pickup_address' => $overrides['pickup'] ?? 'Quezon City Circle',
        'dropoff_address' => $overrides['dropoff'] ?? 'SM Megamall, Mandaluyong',
        'distance_km' => 6,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1950,
        'final_total' => 1950,
        'status' => $status,
    ]);
}

it('renders the Active Jobs page', function () {
    ajRoles();
    $dispatcher = ajDispatcher();

    $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk();
});

it('no longer shows the duplicate Active Operations counter', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    ajBooking('assigned');

    $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->assertDontSee('Active Operations');
});

it('renders the five operational status tabs', function () {
    ajRoles();
    $dispatcher = ajDispatcher();

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-tab="all"')
        ->and($html)->toContain('data-tab="assigned"')
        ->and($html)->toContain('data-tab="en-route"')
        ->and($html)->toContain('data-tab="in-service"')
        ->and($html)->toContain('data-tab="awaiting-verification"');
});

it('computes tab counts from real active jobs, not from the paginated page', function () {
    ajRoles();
    $dispatcher = ajDispatcher();

    ajBooking('assigned');
    ajBooking('accepted');
    ajBooking('on_the_way');
    ajBooking('in_progress');
    ajBooking('waiting_verification');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('/data-tab="all">All\s*<span class="rb-tab-count">5</');
    expect($html)->toMatch('/data-tab="assigned">Assigned\s*<span class="rb-tab-count">2</');
    expect($html)->toMatch('/data-tab="en-route">En Route\s*<span class="rb-tab-count">1</');
    expect($html)->toMatch('/data-tab="in-service">In Service\s*<span class="rb-tab-count">1</');
    expect($html)->toMatch('/data-tab="awaiting-verification">Awaiting Verification\s*<span class="rb-tab-count">1</');
});

it('groups assigned and accepted bookings under the Assigned bucket', function () {
    ajRoles();
    $dispatcher = ajDispatcher();

    $assigned = ajBooking('assigned');
    $accepted = ajBooking('accepted');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    foreach ([$assigned, $accepted] as $booking) {
        $pos = strpos($html, 'data-booking-code="' . $booking->booking_code . '"');
        expect($pos)->not->toBeFalse();
        $rowStart = strrpos(substr($html, 0, $pos), '<tr class="jobs-row');
        $row = substr($html, $rowStart, $pos - $rowStart + 60);
        expect($row)->toContain('data-bucket="assigned"');
    }
});

it('groups on_the_way and arrived_pickup under the En Route bucket', function () {
    ajRoles();
    $dispatcher = ajDispatcher();

    $onTheWay = ajBooking('on_the_way');
    $arrivedPickup = ajBooking('arrived_pickup');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    foreach ([$onTheWay, $arrivedPickup] as $booking) {
        $pos = strpos($html, 'data-booking-code="' . $booking->booking_code . '"');
        expect($pos)->not->toBeFalse();
        $rowStart = strrpos(substr($html, 0, $pos), '<tr class="jobs-row');
        $row = substr($html, $rowStart, $pos - $rowStart + 60);
        expect($row)->toContain('data-bucket="en-route"');
    }
});

it('groups in_progress, loading_vehicle, on_job and arrived_dropoff under In Service', function () {
    ajRoles();
    $dispatcher = ajDispatcher();

    $bookings = [
        ajBooking('in_progress'),
        ajBooking('loading_vehicle'),
        ajBooking('on_job'),
        ajBooking('arrived_dropoff'),
    ];

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    foreach ($bookings as $booking) {
        $pos = strpos($html, 'data-booking-code="' . $booking->booking_code . '"');
        expect($pos)->not->toBeFalse();
        $rowStart = strrpos(substr($html, 0, $pos), '<tr class="jobs-row');
        $row = substr($html, $rowStart, $pos - $rowStart + 60);
        expect($row)->toContain('data-bucket="in-service"');
    }
});

it('puts only waiting_verification under the Awaiting Verification bucket', function () {
    ajRoles();
    $dispatcher = ajDispatcher();

    $waiting = ajBooking('waiting_verification');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    $pos = strpos($html, 'data-booking-code="' . $waiting->booking_code . '"');
    $rowStart = strrpos(substr($html, 0, $pos), '<tr class="jobs-row');
    $row = substr($html, $rowStart, $pos - $rowStart + 60);
    expect($row)->toContain('data-bucket="awaiting-verification"');

    expect($waiting->fresh()->status)->toBe('waiting_verification');
});

it('does not mutate the stored booking status when rendering the page', function () {
    ajRoles();
    $dispatcher = ajDispatcher();

    $booking = ajBooking('on_the_way');

    $this->actingAs($dispatcher)->get(route('admin.jobs'))->assertOk();
    $this->actingAs($dispatcher)->get(route('admin.jobs', ['tab' => 'en-route']))->assertOk();

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('renders searchable data for booking code, customer, unit and team leader', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $teamLeader = ajTeamLeader('Marco Reyes');

    $booking = ajBooking('assigned', [
        'customer_name' => 'Paolo Reyes',
        'unit_name' => 'Unit 11 · Waling-Waling',
        'team_leader' => $teamLeader,
    ]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    // Search is client-side (jobs.js) against this rendered row text — assert
    // all four searchable fields are actually present in the row's markup.
    $pos = strpos($html, 'data-booking-code="' . $booking->booking_code . '"');
    $rowStart = strrpos(substr($html, 0, $pos), '<tr class="jobs-row');
    $rowEnd = strpos($html, '</tr>', $pos);
    $row = substr($html, $rowStart, $rowEnd - $rowStart);

    expect($row)->toContain($booking->booking_code)
        ->and($row)->toContain('Paolo Reyes')
        ->and($row)->toContain('Unit 11')
        ->and($row)->toContain('Marco Reyes');
});

it('has removed the redundant Details column', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    ajBooking('assigned');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('<th>Details</th>');
    expect($html)->toContain('<th>Route</th>');
});

it('renders pickup and drop-off on the Route column', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $booking = ajBooking('assigned', [
        'pickup' => 'Katipunan Avenue, Quezon City',
        'dropoff' => 'SM Megamall, Mandaluyong',
    ]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('jobs-route-cell')
        ->and($html)->toContain('Katipunan Avenue, Quezon City')
        ->and($html)->toContain('→ SM Megamall, Mandaluyong');
});

it('no longer applies the old purple/colored status classes', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    ajBooking('on_the_way');
    ajBooking('in_progress');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('jobs-status-on-the-way')
        ->and($html)->not->toContain('jobs-status-in-progress"')
        ->and($html)->toContain('jobs-status-text');
});

it('shows a plain dash for payment when a job is not awaiting verification', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    ajBooking('in_progress');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('>—<');
});

it('keeps rows keyboard-focusable and still wired to the existing drawer markup', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    ajBooking('assigned');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('class="jobs-row js-open-job-row" tabindex="0"')
        ->and($html)->toContain('id="jobsDrawer"')
        ->and($html)->toContain('id="drawerConfirmPaymentBtn"');
});

it('renames the drawer Service Summary section to Route, using real pickup/drop-off data', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $booking = ajBooking('assigned', [
        'pickup' => 'Katipunan Avenue, Quezon City',
        'dropoff' => 'SM Megamall, Mandaluyong',
    ]);
    $booking->update(['distance_km' => 8.4]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Service Summary');
    expect($html)->toContain('<div class="jobs-drawer-section-title">Route</div>');

    // The drawer's pickup/drop-off values are the row's real dataset values,
    // which come straight from the booking's own pickup_address/dropoff_address.
    expect($html)->toContain('data-pickup="Katipunan Avenue, Quezon City"');
    expect($html)->toContain('data-dropoff="SM Megamall, Mandaluyong"');
});

it('exposes the booking distance to the drawer without recalculating it', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $booking = ajBooking('assigned');
    $booking->update(['distance_km' => 8.4]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    // Same authoritative distance_km column used everywhere else — a plain
    // pass-through, not a new calculation.
    expect($html)->toContain('data-distance-km="8.4');
    expect($html)->toContain('id="drawer-distance-wrap"');
    expect($html)->toContain('id="drawer-distance"');
    expect($booking->fresh()->distance_km)->toEqual(8.4);
});

it('removes the old yellow/hollow timeline dots and connecting line from the route markup', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    ajBooking('assigned');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    // The OLD yellow-filled/hollow timeline dots and connecting line —
    // distinct from the new .rb-route-dot (green pickup / red drop-off)
    // reused from Dispatch Queue below, which intentionally does use dots.
    expect($html)->not->toContain('class="route-dot')
        ->and($html)->not->toContain('jobs-drawer-route-line')
        ->and($html)->not->toContain('route-from')
        ->and($html)->not->toContain('route-to');
});

it('uses the same .rb-route markup/classes as the approved Dispatch Queue route component', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $booking = ajBooking('assigned');
    $booking->update(['distance_km' => 8.4]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    // Same container, same green pickup dot, same red drop-off dot, same
    // dashed-divider distance row — identical to booking-drawer.js's
    // routeSectionHtml() used by both Book Now and Scheduled.
    expect($html)->toContain('<div class="rb-route">')
        ->and($html)->toContain('<span class="rb-route-dot rb-pick"></span>')
        ->and($html)->toContain('<span class="rb-route-dot rb-drop"></span>')
        ->and($html)->toContain('class="rb-route-meta"')
        ->and($html)->toContain('<span class="rb-route-addr" id="drawer-pickup">');
});

it('renames the drawer Service Type label to Truck Type, using the real truck type value', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $truckType = ajTruckType('Medium Duty');
    $customer = ajCustomer('Truck Type Customer');

    $unit = Unit::create([
        'name' => 'AJ Unit ' . uniqid(),
        'plate_number' => 'AJ-' . rand(1000, 9999),
        'truck_type_id' => $truckType->id,
        'status' => 'on_job',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'age' => 30,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'assigned',
    ]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('>Service Type<');
    expect($html)->toContain('<div class="jobs-drawer-section-title">Truck Type</div>');
    // data-service is what jobs.js renders into the drawer's Truck Type
    // value — confirmed to come from the booking's real truckType relation.
    expect($html)->toContain('data-service="' . $truckType->name . '"');
    expect($booking->truckType->name)->toBe('Medium Duty');
});

it('does not change the booking status when opening the drawer data', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $booking = ajBooking('on_the_way');
    $booking->update(['distance_km' => 3.2]);

    $this->actingAs($dispatcher)->get(route('admin.jobs'))->assertOk();

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('keeps the confirm-payment route and controller wiring intact (untouched by this UI refinement)', function () {
    // Exercises the guard branch only (booking not yet ready for
    // completion) rather than the full success path — the success path
    // schedules an app()->terminating() closure (PDF/receipt generation)
    // that legitimately calls ob_end_flush()/fastcgi_finish_request() in
    // production, which PHPUnit flags as a "risky" test purely because of
    // output-buffer bookkeeping in the CLI test runner. That behavior
    // predates this task and JobsController::confirmPayment() was not
    // touched — this test only needs to prove the route/controller/booking
    // lookup still resolves correctly after the Blade/JS refinement.
    ajRoles();
    $dispatcher = ajDispatcher();
    $booking = ajBooking('assigned');

    $this->actingAs($dispatcher)
        ->postJson(route('admin.jobs.confirm-payment', $booking))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'This booking is not ready for completion.');

    expect($booking->fresh()->status)->toBe('assigned');
});
