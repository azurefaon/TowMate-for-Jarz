<?php

// Phase A: Assign Unit -> View Live Map. Covers the server side of the shared live map
// (units/locations?scope=assignment), the guards assignBooking() applies when the dispatcher
// finally presses Proceed to Dispatch, and the front-end contract the drawer and Return share.

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

function lmapRole(int $id, string $name): void
{
    if (! Role::find($id)) {
        $role = new Role(['name' => $name]);
        $role->id = $id;
        $role->save();
    }
}

function lmapDispatcher(): User
{
    lmapRole(1, 'Owner');
    lmapRole(2, 'Dispatcher');
    lmapRole(3, 'Team Leader');
    lmapRole(5, 'Customer');

    return User::factory()->create(['role_id' => 2, 'must_change_password' => false]);
}

function lmapTruck(string $name, string $class): TruckType
{
    return TruckType::firstOrCreate(['name' => $name], [
        'class' => $class, 'base_rate' => 1500, 'per_km_rate' => 300, 'status' => 'active',
    ]);
}

function lmapUnit(TruckType $truck, string $name, array $unit = [], array $tl = []): Unit
{
    $leader = User::factory()->create(array_merge(['role_id' => 3, 'must_change_password' => false, 'last_ping_at' => now()], $tl));
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create(array_merge([
        'name' => $name,
        'plate_number' => strtoupper(substr(md5($name . microtime()), 0, 7)),
        'truck_type_id' => $truck->id,
        'team_leader_id' => $leader->id,
        'status' => 'available',
        'driver_name' => 'LMAP DRIVER',
        'current_lat' => 14.62,
        'current_lng' => 121.04,
        'location_updated_at' => now(),
    ], $unit));
}

function lmapBooking(TruckType $truck, string $status = 'confirmed', array $extra = []): Booking
{
    $customer = Customer::firstOrCreate(['email' => 'lmap@example.com'], ['full_name' => 'Live Map Customer', 'phone' => '09170000000']);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pickup',
        'pickup_lat' => 14.6760,
        'pickup_lng' => 121.0437,
        'dropoff_address' => 'Dropoff',
        'dropoff_lat' => 14.5547,
        'dropoff_lng' => 121.0244,
        'distance_km' => 12.5,
        'base_rate' => 1500,
        'per_km_rate' => 300,
        'computed_total' => 4050,
        'final_total' => 4536,
        'vat_exclusive_total' => 4050,
        'status' => $status,
        'service_type' => 'book_now',
    ], $extra));
}

function lmapBusyJob(Unit $unit, string $status = 'on_the_way'): Booking
{
    return lmapBooking(TruckType::find($unit->truck_type_id), $status, [
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $unit->team_leader_id,
        'assigned_at' => now(),
    ]);
}

function lmapLocations($test, User $dispatcher, Booking $booking)
{
    return $test->actingAs($dispatcher)->getJson(route('admin.units.locations', ['scope' => 'assignment', 'booking' => $booking->booking_code]));
}

function lmapUnitRow($response, Unit $unit): array
{
    return collect($response->json())->firstWhere('unit_id', $unit->id) ?? [];
}

// ── 1 + 2: the map can be opened for an eligible booking ─────────────────────
it('opens the Intermediate map data for a confirmed Book Now booking', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $unit = lmapUnit($light, 'Light 01');
    $booking = lmapBooking($light);

    $row = lmapUnitRow(lmapLocations($this, $dispatcher, $booking)->assertOk(), $unit);

    expect($row['selectable'])->toBeTrue()
        ->and($row['compatible'])->toBeTrue()
        ->and($row['phase'])->toBe('available')
        ->and($row['gps_state'])->toBe('live')
        ->and($row['reason'])->toBeNull()
        ->and($row['booking_truck_type_name'])->toBe('Light Duty')
        ->and($row['label'])->toContain('Light 01');
});

it('opens the Scheduled map data only inside the ready window', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    lmapUnit($light, 'Light 01');

    $soon = lmapBooking($light, 'scheduled_confirmed', [
        'service_type' => 'schedule',
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addMinutes(30)->format('H:i'),
    ]);
    $later = lmapBooking($light, 'scheduled_confirmed', [
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDays(3)->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    lmapLocations($this, $dispatcher, $soon)->assertOk();
    lmapLocations($this, $dispatcher, $later)
        ->assertStatus(422)
        ->assertJsonPath('message', 'This booking is not yet ready for dispatch — unit selection opens 1 hour before the scheduled service time.');
});

it('refuses map data for a booking that cannot be assigned a unit and for an unknown booking', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $requested = lmapBooking($light, 'requested');

    lmapLocations($this, $dispatcher, $requested)->assertStatus(422);

    $this->actingAs($dispatcher)
        ->getJson(route('admin.units.locations', ['scope' => 'assignment', 'booking' => 'NOPE-0000']))
        ->assertStatus(404);
});

it('does not hand the assignment map to anyone but a dispatcher', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $booking = lmapBooking($light);
    $url = route('admin.units.locations', ['scope' => 'assignment', 'booking' => $booking->booking_code]);

    $this->getJson($url)->assertStatus(401);

    foreach ([1, 3, 5] as $roleId) {
        $other = User::factory()->create(['role_id' => $roleId, 'must_change_password' => false]);
        $this->actingAs($other)->getJson($url)->assertForbidden();
    }

    $this->actingAs($dispatcher)->getJson($url)->assertOk();
});

// ── 7, 8, 9, 10: eligibility and GPS labelling are decided by the server, fresh ──
it('marks busy, finishing, other-class, off-duty and reserved units as not selectable with a reason', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $medium = lmapTruck('Medium Duty', 'medium');
    $booking = lmapBooking($light);

    $free = lmapUnit($light, 'Free');
    $busy = lmapUnit($light, 'Busy');
    lmapBusyJob($busy, 'on_the_way');
    $finishing = lmapUnit($light, 'Finishing');
    lmapBusyJob($finishing, 'arrived_dropoff');
    $other = lmapUnit($medium, 'Medium');
    $offDuty = lmapUnit($light, 'OffDuty', [], ['duty_status' => 'unavailable']);
    $reserved = lmapUnit($light, 'Reserved');
    lmapBooking($light, 'confirmed', ['selected_unit_id' => $reserved->id]);

    $response = lmapLocations($this, $dispatcher, $booking)->assertOk();
    $rows = fn (Unit $u) => lmapUnitRow($response, $u);

    expect($rows($free)['selectable'])->toBeTrue()
        ->and($rows($busy)['selectable'])->toBeFalse()
        ->and($rows($busy)['phase'])->toBe('busy')
        ->and($rows($busy)['reason'])->toBe('Busy on an active job.')
        ->and($rows($finishing)['selectable'])->toBeFalse()
        ->and($rows($finishing)['phase'])->toBe('finishing')
        ->and($rows($finishing)['reason'])->toContain('Finishing a task')
        ->and($rows($other)['compatible'])->toBeFalse()
        ->and($rows($other)['selectable'])->toBeFalse()
        ->and($rows($other)['reason'])->toContain('Exact tow class required')
        ->and($rows($offDuty)['selectable'])->toBeFalse()
        ->and($rows($offDuty)['reason'])->toContain('unavailable for duty')
        ->and($rows($reserved)['selectable'])->toBeFalse()
        ->and($rows($reserved)['reason'])->toBe('Reserved for another booking.');
});

it('keeps a unit that is reserved for this very booking selectable', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $unit = lmapUnit($light, 'Mine');
    $booking = lmapBooking($light, 'confirmed', ['selected_unit_id' => $unit->id]);

    expect(lmapUnitRow(lmapLocations($this, $dispatcher, $booking), $unit)['selectable'])->toBeTrue();
});

it('labels GPS freshness exactly and never invents coordinates', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $booking = lmapBooking($light);

    $live = lmapUnit($light, 'Live', ['location_updated_at' => now()->subSeconds(10)]);
    $recent = lmapUnit($light, 'Recent', ['location_updated_at' => now()->subMinutes(3)]);
    $stale = lmapUnit($light, 'Stale', ['location_updated_at' => now()->subMinutes(20)], ['last_ping_at' => now()->subHour()]);
    $none = lmapUnit($light, 'None', ['current_lat' => null, 'current_lng' => null, 'location_updated_at' => null]);

    $response = lmapLocations($this, $dispatcher, $booking)->assertOk();

    expect(lmapUnitRow($response, $live)['gps_state'])->toBe('live')
        ->and(lmapUnitRow($response, $recent)['gps_state'])->toBe('recent')
        ->and(lmapUnitRow($response, $recent)['updated_seconds_ago'])->toBeBetween(170, 190)
        ->and(lmapUnitRow($response, $stale)['gps_state'])->toBe('stale')
        ->and(lmapUnitRow($response, $stale)['warning'])->toContain('outdated')
        ->and(lmapUnitRow($response, $stale)['selectable'])->toBeTrue() // existing rules allow it; the map only warns
        ->and(lmapUnitRow($response, $none)['gps_state'])->toBe('none')
        ->and(lmapUnitRow($response, $none)['lat'])->toBeNull()
        ->and(lmapUnitRow($response, $none)['lng'])->toBeNull()
        ->and(lmapUnitRow($response, $none)['warning'])->toContain('No GPS location');
});

it('leaves the default (Return) locations response exactly as it was', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $fresh = lmapUnit($light, 'Fresh');
    $noGps = lmapUnit($light, 'NoGps', ['current_lat' => null, 'current_lng' => null, 'location_updated_at' => null]);
    $offlineIdle = lmapUnit($light, 'OfflineIdle', ['location_updated_at' => now()->subHour()], ['last_ping_at' => now()->subHour()]);

    $rows = collect($this->actingAs($dispatcher)->getJson(route('admin.units.locations'))->assertOk()->json());

    expect($rows->pluck('unit_id')->all())->toContain($fresh->id)
        ->and($rows->pluck('unit_id')->all())->not->toContain($noGps->id)
        ->and($rows->pluck('unit_id')->all())->not->toContain($offlineIdle->id)
        ->and($rows->first())->not->toHaveKey('selectable')
        ->and($rows->first())->toHaveKeys(['unit_id', 'lat', 'lng', 'is_online', 'updated_seconds_ago', 'job_status']);
});

// ── 11: group vehicles are judged independently, each against its own booking ──
it('judges each group vehicle against its own booking', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $medium = lmapTruck('Medium Duty', 'medium');
    $lightUnit = lmapUnit($light, 'Light');
    $mediumUnit = lmapUnit($medium, 'Medium');

    $vehicle1 = lmapBooking($light, 'confirmed', ['group_code' => 'GRP-1']);
    $vehicle2 = lmapBooking($medium, 'confirmed', ['group_code' => 'GRP-1']);

    $for1 = lmapLocations($this, $dispatcher, $vehicle1);
    $for2 = lmapLocations($this, $dispatcher, $vehicle2);

    expect(lmapUnitRow($for1, $lightUnit)['selectable'])->toBeTrue()
        ->and(lmapUnitRow($for1, $mediumUnit)['selectable'])->toBeFalse()
        ->and(lmapUnitRow($for2, $mediumUnit)['selectable'])->toBeTrue()
        ->and(lmapUnitRow($for2, $lightUnit)['selectable'])->toBeFalse();

    // Choosing a unit for one Light vehicle reserves it, so the group's other Light vehicle no
    // longer sees it as selectable, while the first vehicle keeps its own unit.
    $vehicle3 = lmapBooking($light, 'confirmed', ['group_code' => 'GRP-1']);
    $vehicle1->update(['selected_unit_id' => $lightUnit->id]);

    expect(lmapUnitRow(lmapLocations($this, $dispatcher, $vehicle3), $lightUnit)['reason'])->toBe('Reserved for another booking.')
        ->and(lmapUnitRow(lmapLocations($this, $dispatcher, $vehicle1), $lightUnit)['selectable'])->toBeTrue();
});

// ── 14 + 15: assignment is still validated on the server, whatever the UI showed ──
it('assigns a free compatible unit', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $unit = lmapUnit($light, 'Free');
    $booking = lmapBooking($light);

    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), ['action' => 'accept', 'assigned_unit_id' => $unit->id])
        ->assertOk();

    expect($booking->fresh()->status)->toBe('assigned')
        ->and($booking->fresh()->assigned_unit_id)->toBe($unit->id);
});

it('rejects a busy unit, a wrong class and a stale selection even when the request names them directly', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $medium = lmapTruck('Medium Duty', 'medium');
    $booking = lmapBooking($light);
    $assign = fn (Unit $u) => $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), ['action' => 'accept', 'assigned_unit_id' => $u->id]);

    $busy = lmapUnit($light, 'Busy');
    lmapBusyJob($busy);
    $wrong = lmapUnit($medium, 'Wrong');

    $assign($busy)->assertStatus(422);
    $assign($wrong)->assertStatus(422)->assertJsonPath('message', 'This unit is not compatible with the booking vehicle.');

    // Free when the picker was drawn, busy by the time Proceed is pressed.
    $wasFree = lmapUnit($light, 'WasFree');
    lmapBusyJob($wasFree, 'accepted');
    $assign($wasFree)->assertStatus(422);

    expect($booking->fresh()->status)->toBe('confirmed')->and($booking->fresh()->assigned_unit_id)->toBeNull();
});

it('rejects an off-duty or dispatcher-blocked Team Leader on the server, not just in the picker', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $booking = lmapBooking($light);

    $offDuty = lmapUnit($light, 'OffDuty', [], ['duty_status' => 'unavailable']);
    $blocked = lmapUnit($light, 'Blocked');
    app(\App\Services\TeamLeaderAvailabilityService::class)->setOperationalOverride(User::find($blocked->team_leader_id), 'unavailable', 'Lunch');

    foreach ([$offDuty, $blocked] as $unit) {
        $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), ['action' => 'accept', 'assigned_unit_id' => $unit->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This unit is not available for dispatch right now. Please choose another unit.');
    }

    expect($booking->fresh()->assigned_unit_id)->toBeNull();
});

it('keeps the Scheduled ready window and the accepted-booking requirement on the assign endpoint', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $unit = lmapUnit($light, 'Free');

    $later = lmapBooking($light, 'scheduled_confirmed', [
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDays(3)->toDateString(),
        'scheduled_time' => '10:00',
    ]);
    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $later), ['action' => 'accept', 'assigned_unit_id' => $unit->id])
        ->assertStatus(422);
    expect($later->fresh()->assigned_unit_id)->toBeNull();

    // A booking that is not accepted/confirmed cannot be given a unit by naming one.
    $requested = lmapBooking($light, 'requested');
    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $requested), ['action' => 'accept', 'assigned_unit_id' => $unit->id])
        ->assertStatus(422);
    expect($requested->fresh()->assigned_unit_id)->toBeNull()->and($requested->fresh()->status)->toBe('requested');
});

it('does not let a non-dispatcher use the assign endpoint', function () {
    lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $unit = lmapUnit($light, 'Free');
    $booking = lmapBooking($light);

    foreach ([1, 3, 5] as $roleId) {
        $other = User::factory()->create(['role_id' => $roleId, 'must_change_password' => false]);
        $this->actingAs($other)->postJson(route('admin.booking.assign', $booking), ['action' => 'accept', 'assigned_unit_id' => $unit->id])
            ->assertForbidden();
    }

    expect($booking->fresh()->assigned_unit_id)->toBeNull();
});

// ── 3, 5, 6, 12, 13: front-end contract (one map, one poller, select ≠ inspect) ──
it('exposes one shared live map and wires the drawer button once', function () {
    $dispatch = file_get_contents(public_path('dispatcher/js/dispatch.js'));
    $drawer = file_get_contents(public_path('dispatcher/js/booking-drawer.js'));

    // One Google Map instance and one poll timer, shared by Return and Assign Unit.
    expect(substr_count($dispatch, 'new google.maps.Map('))->toBe(1)
        ->and(substr_count($dispatch, 'window.setInterval(poll'))->toBe(1)
        ->and($dispatch)->toContain('window.dispatchLiveMap = api')
        ->and($dispatch)->toContain('scope=assignment')
        // Escape closes the map before the drawer underneath.
        ->and($dispatch)->toContain('e.stopImmediatePropagation()');

    // Inspecting a marker never calls the selection callback; only the Select Unit button does.
    expect($dispatch)->toContain('inspectUnit(u.unit_id, false)')
        ->and(substr_count($dispatch, 'callback(String(fresh.unit_id), fresh)'))->toBe(1)
        ->and(substr_count($dispatch, 'ctx.onSelect'))->toBe(1);

    // One "View Live Map" button per Assign Unit section (single or per group vehicle), never per unit card.
    expect(substr_count($drawer, 'liveMapButtonHtml('))->toBe(3) // definition + single + group
        ->and($drawer)->not->toMatch('/function unitCardHtml[\s\S]{0,1500}View Live Map/');
});

it('renders the dispatch page with a single Google Maps script and the Assign Unit map shell', function () {
    $dispatcher = lmapDispatcher();

    $html = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk()->getContent();

    expect(substr_count($html, 'maps.googleapis.com/maps/api/js'))->toBe(1)
        ->and(substr_count($html, 'id="dispatchLiveMap"'))->toBe(1)
        ->and($html)->toContain('id="trackingDetail"')
        ->and($html)->toContain('data-assign-filter="selectable"')
        ->and($html)->toContain('data-assign-filter="all"')
        // Return still opens its map through the shared lifecycle, in its default mode.
        ->and($html)->toContain("mode: 'return'");
});

// ── driver readiness is enforced on the server, not only hidden by the pickers ──
it('refuses a direct assignment to a unit with no driver, an off-duty driver or an inactive driver account', function () {
    $dispatcher = lmapDispatcher();
    lmapRole(4, 'Driver');
    $light = lmapTruck('Light Duty', 'light');
    $booking = lmapBooking($light);

    $noDriver = lmapUnit($light, 'NoDriver', ['driver_name' => null]);
    $offDutyDriver = lmapUnit($light, 'OffDutyDriver', ['driver_duty_status' => 'unavailable']);
    $inactiveAccount = User::factory()->create(['role_id' => 4, 'status' => 'inactive', 'must_change_password' => false]);
    $inactiveDriver = lmapUnit($light, 'InactiveDriver', ['driver_name' => null, 'driver_id' => $inactiveAccount->id]);

    foreach ([$noDriver, $offDutyDriver, $inactiveDriver] as $unit) {
        $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), ['action' => 'accept', 'assigned_unit_id' => $unit->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This unit is not available for dispatch right now. Please choose another unit.');
    }

    expect($booking->fresh()->status)->toBe('confirmed')->and($booking->fresh()->assigned_unit_id)->toBeNull();

    // The map shows the same verdict and says why.
    $row = lmapUnitRow(lmapLocations($this, $dispatcher, $booking), $noDriver);
    expect($row['selectable'])->toBeFalse()->and($row['reason'])->toBe('No driver is assigned to this unit.');
});

it('still assigns a unit whose driver is a linked active account', function () {
    $dispatcher = lmapDispatcher();
    lmapRole(4, 'Driver');
    $light = lmapTruck('Light Duty', 'light');
    $booking = lmapBooking($light);
    $account = User::factory()->create(['role_id' => 4, 'status' => 'active', 'must_change_password' => false]);
    $unit = lmapUnit($light, 'LinkedDriver', ['driver_name' => null, 'driver_id' => $account->id]);

    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), ['action' => 'accept', 'assigned_unit_id' => $unit->id])
        ->assertOk();
});

it('keeps group assignment working per vehicle while refusing a driverless unit for one of them', function () {
    $dispatcher = lmapDispatcher();
    $light = lmapTruck('Light Duty', 'light');
    $vehicle1 = lmapBooking($light, 'confirmed', ['group_code' => 'GRP-DRV']);
    $vehicle2 = lmapBooking($light, 'confirmed', ['group_code' => 'GRP-DRV']);
    $unit1 = lmapUnit($light, 'GroupOne');
    $unit2 = lmapUnit($light, 'GroupTwo');
    $driverless = lmapUnit($light, 'Driverless', ['driver_name' => null]);
    $assign = fn (Booking $b, Unit $u) => $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $b), ['action' => 'accept', 'assigned_unit_id' => $u->id]);

    $assign($vehicle1, $unit1)->assertOk();
    $assign($vehicle2, $driverless)->assertStatus(422);
    $assign($vehicle2, $unit2)->assertOk();
    // The same unit cannot also be given to the sibling vehicle.
    $assign(lmapBooking($light, 'confirmed', ['group_code' => 'GRP-DRV']), $unit1)->assertStatus(422);

    expect($vehicle1->fresh()->assigned_unit_id)->toBe($unit1->id)
        ->and($vehicle2->fresh()->assigned_unit_id)->toBe($unit2->id)
        ->and($vehicle1->fresh()->assigned_unit_id)->not->toBe($vehicle2->fresh()->assigned_unit_id);
});
