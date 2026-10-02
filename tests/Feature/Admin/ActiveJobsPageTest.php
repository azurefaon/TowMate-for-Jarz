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

it('has no Payment table column; payment lives only in the inline detail for awaiting jobs', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $booking = ajBooking('in_progress');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('<th>Payment</th>')
        ->and($html)->not->toContain('Payment not yet submitted');

    // The row itself carries no payment text/amount for a non-awaiting job.
    $pos = strpos($html, 'data-booking-code="' . $booking->booking_code . '"');
    $rowStart = strrpos(substr($html, 0, $pos), '<tr class="jobs-row');
    $row = substr($html, $rowStart, strpos($html, '</tr>', $pos) - $rowStart);
    expect($row)->toContain('data-payment-ready="0"')
        ->and($row)->not->toContain('₱');
});

it('has the approved columns, a plain chevron column and a page heading/subtitle', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    ajBooking('assigned');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<h1 class="jobs-title">Active Jobs</h1>')
        ->and($html)->toContain('<p class="jobs-subtitle">Monitor active towing jobs, assignments, and job progress.</p>')
        ->and($html)->toContain('<th>Booking / Customer</th>')
        ->and($html)->toContain('<th>Status</th>')
        ->and($html)->toContain('<th>Unit / Team</th>')
        ->and($html)->toContain('<th>Route</th>')
        ->and($html)->toContain('<th>Updated</th>')
        ->and($html)->toContain('<th class="jobs-col-chevron" aria-label="Expand"></th>')
        ->and($html)->toContain('class="jobs-chevron"')
        ->and($html)->not->toContain('Active Operations');
});

it('renders one hidden inline detail row directly after every job row, and no drawer', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $a = ajBooking('assigned');
    $b = ajBooking('on_the_way');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    foreach ([$a, $b] as $booking) {
        $pos = strpos($html, 'data-booking-code="' . $booking->booking_code . '"');
        $rowEnd = strpos($html, '</tr>', $pos) + strlen('</tr>');
        expect(trim(substr($html, $rowEnd, 140)))
            ->toStartWith('<tr class="jobs-detail-row" data-detail-for="' . $booking->booking_code . '" hidden>');
    }

    expect(substr_count($html, 'class="jobs-detail-row"'))->toBe(2)
        ->and($html)->not->toContain('jobsDrawer')
        ->and($html)->not->toContain('jobs-drawer')
        ->and($html)->not->toContain('drawerConfirmPaymentBtn')
        ->and($html)->toContain('id="jobsDetailTemplate"');
});

it('uses Driver / No driver recorded wording and has no Member Driver label', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $booking = ajBooking('assigned');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-driver="No driver recorded"')
        ->and($html)->toContain('<dt>Driver</dt>')
        ->and($html)->not->toContain('Member Driver')
        ->and($html)->not->toContain('No member recorded');
});

it('shows the agreed amount only in the inline detail, never in the table row', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $booking = ajBooking('in_progress');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    $pos = strpos($html, 'data-booking-code="' . $booking->booking_code . '"');
    $rowStart = strrpos(substr($html, 0, $pos), '<tr class="jobs-row');
    $row = substr($html, $rowStart, strpos($html, '</tr>', $pos) - $rowStart);

    // Existing data source (booking/group total) feeds the detail; the row
    // never prints an amount.
    expect($row)->toContain('data-total="1,950.00"')
        ->and($row)->not->toContain('₱')
        ->and($html)->toContain('<dt>Agreed amount</dt>')
        ->and(substr_count($html, 'id="job-detail-agreed"'))->toBe(1);
});

it('jobs.js has no drawer code and implements one-open-at-a-time, Escape and ?booking auto-open', function () {
    $js = file_get_contents(public_path('dispatcher/js/jobs.js'));

    expect($js)->not->toContain('openDrawer')
        ->and($js)->not->toContain('closeDrawer')
        ->and($js)->not->toContain('jobsDrawer')
        ->and($js)->not->toContain('drawerConfirmPaymentBtn')
        // one open at a time
        ->and($js)->toContain('if (currentRow && currentRow !== row) closeDetail();')
        // Escape: reassign modal first, otherwise collapse the open job
        ->and($js)->toContain('closeReassignModal();' . "\n" . '            return;')
        // ?booking=CODE still highlights/scrolls/clears AND now expands the row
        ->and($js)->toContain('target.classList.add("jobs-row--highlight");')
        ->and($js)->toContain('openDetail(target);')
        ->and($js)->toContain('window.history.replaceState')
        // reassign stays limited to the exact "assigned" status
        ->and($js)->toContain('row.dataset.status === "assigned"');
});

it('keeps rows keyboard-focusable and wired to the inline detail (confirm payment + reassign hooks)', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    ajBooking('assigned');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('class="jobs-row js-open-job-row" tabindex="0" aria-expanded="false"')
        ->and($html)->toContain('id="job-detail-confirm-btn"')
        ->and($html)->toContain('id="job-detail-reassign-btn"')
        ->and($html)->toContain('data-confirm-url=')
        ->and($html)->toContain('id="jrReassignModal"');
});

it('shows a Route section (not Service Summary) in the inline detail, using real pickup/drop-off data', function () {
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
    expect($html)->toContain('<h3 class="jobs-detail-title">Route</h3>')
        ->and($html)->toContain('id="job-detail-pickup"')
        ->and($html)->toContain('id="job-detail-dropoff"');

    // The detail's pickup/drop-off values are the row's real dataset values,
    // which come straight from the booking's own pickup_address/dropoff_address.
    expect($html)->toContain('data-pickup="Katipunan Avenue, Quezon City"');
    expect($html)->toContain('data-dropoff="SM Megamall, Mandaluyong"');
});

it('exposes the booking distance to the inline detail without recalculating it', function () {
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
    expect($html)->toContain('id="job-detail-distance-wrap"');
    expect($html)->toContain('id="job-detail-distance"');
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

    // Neither the old yellow/hollow timeline dots nor the .rb-route-dot
    // (green pickup / red drop-off) are used by the inline detail.
    expect($html)->not->toContain('class="route-dot')
        ->and($html)->not->toContain('rb-route-dot')
        ->and($html)->not->toContain('jobs-drawer-route-line')
        ->and($html)->not->toContain('route-from')
        ->and($html)->not->toContain('route-to');
});

it('renders the inline detail route as plain labelled text, with no .rb-route dot component', function () {
    ajRoles();
    $dispatcher = ajDispatcher();
    $booking = ajBooking('assigned');
    $booking->update(['distance_km' => 8.4]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('rb-route')
        ->and($html)->toContain('<dt>Pickup</dt>')
        ->and($html)->toContain('<dt>Drop-off</dt>')
        ->and($html)->toContain('<dt>Distance</dt>');
});

it('labels the inline detail Truck type (not Service Type), using the real truck type value', function () {
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
    expect($html)->toContain('<dt>Truck type</dt>');
    // data-service is what jobs.js renders into the inline detail's Truck type
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
