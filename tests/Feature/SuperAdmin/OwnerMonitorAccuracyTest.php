<?php

/*
 * The Owner Operations Monitor takes its job and truck figures from the same
 * services as Dispatch and Units & Leaders: canonical active jobs, canonical
 * busy leaders, and one availability category per truck. Search matches
 * Owner → Bookings, the live endpoint is cheap when nothing changed, and the
 * Settings Mobile App tab no longer carries an iOS upload placeholder.
 */

use App\Exceptions\Booking\BlacklistedCustomerException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Support\Facades\DB;

function omaUser(int $roleId, array $attrs = []): User
{
    foreach ([1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader', 4 => 'Driver'] as $id => $name) {
        if (! Role::find($id)) {
            $role = new Role(['name' => $name]);
            $role->id = $id;
            $role->save();
        }
    }

    return User::factory()->create(array_merge(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false], $attrs));
}

function omaTruck(): TruckType
{
    return TruckType::first() ?? TruckType::create(['name' => 'OMA Truck', 'base_rate' => 1500, 'per_km_rate' => 60]);
}

function omaUnit(array $attrs = []): Unit
{
    return Unit::create(array_merge([
        'name' => 'OMA ' . uniqid(),
        'plate_number' => 'OMA-' . uniqid(),
        'truck_type_id' => omaTruck()->id,
        'status' => 'available',
    ], $attrs));
}

/** A truck that is fully dispatchable: valid leader and a driver. */
function omaReadyUnit(?User $leader = null): Unit
{
    $leader ??= omaUser(3);

    return omaUnit(['team_leader_id' => $leader->id, 'driver_name' => 'OMA Driver']);
}

function omaBooking(array $attrs = []): Booking
{
    $customer = $attrs['customer'] ?? Customer::create(['full_name' => 'OMA Customer', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'oma-' . uniqid() . '@example.test']);
    unset($attrs['customer']);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => omaTruck()->id,
        'pickup_address' => 'OMA Pickup', 'dropoff_address' => 'OMA Dropoff',
        'distance_km' => 5, 'base_rate' => 1500, 'per_km_rate' => 60,
        'computed_total' => 1800, 'final_total' => 1800,
        'status' => 'requested',
    ], $attrs));
}

function omaStats(): array
{
    return test()->actingAs(omaUser(1))->getJson(route('superadmin.monitoring.live'))->assertOk()->json('monitoringStats');
}

const OMA_ACTIVE = ['assigned', 'accepted', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'];

it('counts every active lifecycle status as an active job, and none of the excluded ones', function () {
    foreach (OMA_ACTIVE as $status) {
        omaBooking(['status' => $status]);
    }
    omaBooking(['status' => 'waiting_verification']);                                            // payment not submitted: active
    omaBooking(['status' => 'waiting_verification', 'payment_submitted_at' => now()]);          // payment submitted: not active
    omaBooking(['status' => 'assigned', 'returned_at' => now()]);                               // returned: not active
    omaBooking(['status' => 'completed', 'completed_at' => now()]);
    omaBooking(['status' => 'cancelled']);
    omaBooking(['status' => 'requested']);

    expect(omaStats()['active_jobs'])->toBe(count(OMA_ACTIVE) + 1);
});

it('detects returned tasks from the real return status, and from older rows that kept returned_at', function () {
    omaBooking(['status' => 'returned', 'returned_at' => now()]);
    omaBooking(['status' => 'assigned', 'returned_at' => now()]);  // older style
    omaBooking(['status' => 'assigned']);                          // ordinary
    omaBooking(['status' => 'completed', 'completed_at' => now()]);

    expect(omaStats()['returned_tasks'])->toBe(2);

    $this->actingAs(omaUser(1))->get(route('superadmin.monitoring.index'))
        ->assertOk()->assertSee('Returned tasks pending reassignment')->assertSee('2 returned booking(s)');
});

it('counts every busy Team Leader, not just the leaders of the eight bookings shown', function () {
    foreach (range(1, 11) as $i) {
        $leader = omaUser(3);
        $unit = omaReadyUnit($leader);
        omaBooking(['status' => 'in_progress', 'assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $leader->id]);
    }

    $stats = omaStats();
    expect($stats['active_jobs'])->toBe(11)->and($stats['busy_team_leaders'])->toBe(11);

    $html = $this->actingAs(omaUser(1))->get(route('superadmin.monitoring.index'))->getContent();
    // The jobs table lists at most eight bookings.
    expect(substr_count($html, 'monitor-route-cell'))->toBeGreaterThan(0)->toBeLessThanOrEqual(8);
});

it('puts every truck in exactly one category: maintenance, busy, reserved, available or unavailable', function () {
    omaUnit(['status' => 'maintenance', 'team_leader_id' => omaUser(3)->id, 'driver_name' => 'D']);          // maintenance
    $busyLeader = omaUser(3);
    $busy = omaReadyUnit($busyLeader);                                                                          // busy
    omaBooking(['status' => 'on_the_way', 'assigned_unit_id' => $busy->id, 'assigned_team_leader_id' => $busyLeader->id]);
    $reserved = omaReadyUnit();                                                                                 // reserved
    omaBooking(['status' => 'quoted', 'selected_unit_id' => $reserved->id]);
    omaReadyUnit();                                                                                             // available
    omaUnit(['team_leader_id' => omaUser(3)->id]);                                                              // unavailable: no driver
    omaUnit(['archived_at' => now()]);                                                                          // archived: not in the fleet

    $stats = omaStats();

    expect($stats)->toMatchArray(['total_units' => 5, 'maintenance_units' => 1, 'units_on_job' => 1, 'reserved_units' => 1, 'available_units' => 1, 'not_available_units' => 1]);
    expect($stats['available_units'] + $stats['units_on_job'] + $stats['reserved_units'] + $stats['maintenance_units'] + $stats['not_available_units'])->toBe($stats['total_units']);

    $html = $this->actingAs(omaUser(1))->get(route('superadmin.monitoring.index'))->getContent();
    expect($html)->toContain('monitor-availability--maintenance')->toContain('monitor-availability--on_job')
        ->toContain('monitor-availability--reserved')->toContain('monitor-availability--available')->toContain('monitor-availability--unavailable');
});

it('a truck on an active job is Busy even when its stored status still says available', function () {
    $leader = omaUser(3);
    $unit = omaReadyUnit($leader);
    expect($unit->status)->toBe('available');
    omaBooking(['status' => 'assigned', 'assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $leader->id]);

    expect(omaStats())->toMatchArray(['units_on_job' => 1, 'available_units' => 0]);
});

it('counts Completed Today by completion date, not by last update', function () {
    $doneToday = omaBooking(['status' => 'completed']);
    $doneYesterday = omaBooking(['status' => 'completed']);
    DB::table('bookings')->where('id', $doneToday->id)->update(['created_at' => now()->subDays(9), 'completed_at' => now()]);
    DB::table('bookings')->where('id', $doneYesterday->id)->update(['completed_at' => now()->subDay(), 'updated_at' => now()]);

    expect(omaStats()['completed_today'])->toBe(1);

    $this->actingAs(omaUser(1))->get(route('superadmin.monitoring.index'))->assertOk()->assertSee('Completed Today');
});

it('searches like Owner Bookings: case-insensitive, and phone numbers typed with the 0 prefix', function () {
    $owner = omaUser(1);
    $customer = Customer::create(['full_name' => 'Mariana Dela Cruz', 'phone' => '+639171234567', 'email' => 'mariana@example.test']);
    $match = omaBooking(['customer' => $customer, 'status' => 'requested', 'pickup_address' => 'Findable Pickup Street']);
    $other = omaBooking(['status' => 'requested']);

    foreach (['findable pickup', 'FINDABLE', 'mariana dela', '09171234567', '0917 123 4567'] as $term) {
        $html = $this->actingAs($owner)->get(route('superadmin.monitoring.index', ['search' => $term]))->assertOk()->getContent();
        expect($html)->toContain($match->job_code, $term)->and($html)->not->toContain('data-code-' . $other->job_code);
        expect(str_contains($html, '>' . $other->job_code . '<'))->toBeFalse($term);
    }
});

it('applies the period filter by date created, and says so', function () {
    $owner = omaUser(1);
    $old = omaBooking(['status' => 'requested', 'pickup_address' => 'Old Period Pickup']);
    DB::table('bookings')->where('id', $old->id)->update(['created_at' => now()->subDays(20)]);
    $new = omaBooking(['status' => 'requested', 'pickup_address' => 'New Period Pickup']);

    $today = $this->actingAs($owner)->get(route('superadmin.monitoring.index', ['status' => 'requested', 'period' => 'today']))->getContent();
    $all = $this->actingAs($owner)->get(route('superadmin.monitoring.index', ['status' => 'requested', 'period' => 'all']))->getContent();

    expect($today)->toContain('New Period Pickup')->not->toContain('Old Period Pickup')->toContain('created today');
    expect($all)->toContain('New Period Pickup')->toContain('Old Period Pickup')->toContain('all time');

    // Unknown filter values fall back to safe defaults.
    $this->actingAs($owner)->get(route('superadmin.monitoring.index', ['status' => 'nonsense', 'period' => 'nonsense']))->assertOk();
});

it('shows when each dispatcher last acted, from their latest audit entry only', function () {
    $active = omaUser(2, ['name' => 'OMA Active Dispatcher']);
    $idle = omaUser(2, ['name' => 'OMA Idle Dispatcher']);
    foreach ([now()->subDays(3), now()->subDays(2), now()->subMinutes(5)] as $when) {
        $log = AuditLog::create(['user_id' => $active->id, 'action' => 'assign', 'entity_type' => 'Booking', 'description' => 'x']);
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => $when]);
    }

    $html = $this->actingAs(omaUser(1))->get(route('superadmin.monitoring.index'))->assertOk()->getContent();

    expect($html)->toMatch('/OMA Active Dispatcher.*?5 minutes ago/s')
        ->and($html)->toMatch('/OMA Idle Dispatcher.*?No activity yet/s');
});

it('only sends the full board when it changed', function () {
    $owner = omaUser(1);
    omaBooking(['status' => 'requested']);

    $first = $this->actingAs($owner)->getJson(route('superadmin.monitoring.live'))->assertOk();
    $first->assertJsonStructure(['monitoringStats', 'hash', 'unchanged', 'generated_label', 'html']);
    expect($first->json('unchanged'))->toBeFalse();
    $hash = $first->json('hash');

    $same = $this->actingAs($owner)->getJson(route('superadmin.monitoring.live', ['view_hash' => $hash]))->assertOk();
    expect($same->json('unchanged'))->toBeTrue()->and($same->json())->not->toHaveKey('html');
    expect(strlen($same->getContent()))->toBeLessThan(strlen($first->getContent()) / 2);

    omaBooking(['status' => 'requested']);
    $changed = $this->actingAs($owner)->getJson(route('superadmin.monitoring.live', ['view_hash' => $hash]))->assertOk();
    expect($changed->json('unchanged'))->toBeFalse()->and($changed->json('html'))->toContain('Operations Summary');
    expect($changed->json('hash'))->not->toBe($hash);

    // The page itself starts from the same hash, so the first poll of an unchanged page is cheap.
    $page = $this->actingAs($owner)->get(route('superadmin.monitoring.index'))->getContent();
    preg_match('/id="monitorLive" data-hash="([a-f0-9]{32})"/', $page, $m);
    expect($m[1] ?? null)->not->toBeNull();
});

it('keeps the live refresh messaging honest: last good time, failure notice, filters preserved', function () {
    $html = $this->actingAs(omaUser(1))->get(route('superadmin.monitoring.index', ['search' => 'abc', 'period' => 'week']))->getContent();

    expect($html)->toContain('Could not refresh. Showing data from')
        ->toContain("url.searchParams.set('view_hash'")
        ->toContain('id="monitorLastSync"')
        ->toContain('search=abc')->toContain('period=week')   // the live URL carries the filters
        ->not->toContain('Updated a few seconds ago');
});

it('keeps the Monitor and its live endpoint Owner-only', function () {
    foreach (['superadmin.monitoring.index', 'superadmin.monitoring.live'] as $name) {
        $this->actingAs(omaUser(2))->get(route($name))->assertForbidden();
        $this->actingAs(omaUser(4))->get(route($name))->assertForbidden();
    }

    auth()->logout();
    $this->get(route('superadmin.monitoring.live'))->assertRedirect();
});

it('only claims blacklisted customers cannot book because BookingService really refuses them', function () {
    $customer = Customer::create(['full_name' => 'OMA Blocked', 'phone' => '09170000000', 'email' => 'blocked@example.test', 'risk_level' => 'blacklisted']);

    expect(fn () => app(BookingService::class)->checkCustomerEligibility($customer))->toThrow(BlacklistedCustomerException::class);

    $this->actingAs(omaUser(1))->get(route('superadmin.monitoring.index'))
        ->assertOk()->assertSee('cannot place new bookings')->assertDontSee('restricted from new bookings');
});

it('drops the iOS upload placeholder from Settings and keeps the working Android upload', function () {
    $response = $this->actingAs(omaUser(1))->get(route('superadmin.settings.index'))->assertOk();

    $response->assertSee('Upload Android App')->assertSee('name="apk_file"', false)
        ->assertSee('so there is nothing to upload here')
        ->assertDontSee('Upload iOS App')->assertDontSee('Upload New IPA')->assertDontSee('app-dropzone-disabled', false);
});
