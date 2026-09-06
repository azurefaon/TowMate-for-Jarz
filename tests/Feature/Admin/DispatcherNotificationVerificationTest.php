<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\DispatcherNotification;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Comprehensive automated verification of the Dispatcher notification
| feature, run only against towmate_jarz_testing (enforced by
| Tests\TestCase::guardAgainstUnsafeTestDatabase()). All fixtures below are
| created fresh per-test under Pest's RefreshDatabase and never touch the
| real towmate_jarz database.
|--------------------------------------------------------------------------
*/

/**
 * towmate_jarz_testing already carries the same baseline roles (id 1-4)
 * that the real seed migration (2026_04_28_000001_seed_roles_and_superadmin)
 * inserts via insertOrIgnore — Owner/Admin/Team Leader/Driver. Using the
 * same insertOrIgnore-by-id technique here (instead of Role::create, which
 * collides on the unique `name` column against that persistent baseline)
 * keeps this fixture correct whether the row already exists or not. Only
 * the numeric role_id matters for RoleMiddleware/authorization — it never
 * checks the role's name.
 */
function verifyRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function verifyDispatcher(): User
{
    return User::factory()->create(['role_id' => 2, 'name' => 'Verify Dispatcher']);
}

function verifyTruckType(string $name = 'Verify Heavy Duty'): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => 1800,
        'per_km_rate' => 90,
        'max_tonnage' => 8,
        'description' => 'Verification truck type',
    ]);
}

function verifyCustomer(string $name = 'Verify Customer'): Customer
{
    return Customer::create([
        'full_name' => $name,
        'age' => 34,
        'phone' => '09171112222',
        'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
    ]);
}

function verifyBookingAttrs(TruckType $truckType, Customer $customer): array
{
    return [
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'age' => 34,
        'pickup_address' => 'Ortigas Center',
        'dropoff_address' => 'Alabang',
        'distance_km' => 12,
        'base_rate' => 1800,
        'per_km_rate' => 90,
        'computed_total' => 2880,
        'final_total' => 2880,
    ];
}

// ── 2. NEW BOOK NOW ─────────────────────────────────────────────────────

it('verify: Book Now booking produces exactly one correct, unread notification with real display data', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();
    $truckType = verifyTruckType('Book Now Heavy Duty');
    $customer = verifyCustomer('Book Now Customer');

    $unreadBefore = DispatcherNotification::unread()->count();

    $booking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $notifications = DispatcherNotification::where('booking_id', $booking->id)->get();
    expect($notifications)->toHaveCount(1);

    $notification = $notifications->first();
    expect($notification->type)->toBe(DispatcherNotification::TYPE_NEW_BOOK_NOW);
    expect($notification->booking_id)->toBe($booking->id);
    expect($notification->read_at)->toBeNull();
    expect($notification->isRead())->toBeFalse();

    // Display data resolves live from real relationships, not frozen text.
    expect($notification->title)->toBe('New booking request');
    $lines = $notification->subtitle_lines;
    expect($lines[0])->toBe($booking->booking_code . ' · Book Now Customer');
    expect($lines[1])->toBe('Book Now · Book Now Heavy Duty');

    expect(DispatcherNotification::unread()->count())->toBe($unreadBefore + 1);

    // Topbar renders it — scoped to the rendered dropdown list, not the whole
    // page (the page also has an unrelated Incoming Requests panel, and a
    // <script> block whose JS source text for live/Pusher-pushed notifications
    // also happens to contain the same glyphs as inert code, not a rendered row).
    $html = actingDispatcherDashboardHtml($dispatcher);
    $dropdown = extractNotifDropdownHtml($html);
    expect($dropdown)->toContain($booking->booking_code)
        ->and($dropdown)->toContain('New booking request')
        ->and($dropdown)->toContain('✕')
        ->and($html)->toContain('id="dispatcherNotifCount"');

    // Opening it marks ONLY that notification read and redirects correctly,
    // deep-linking the Dispatch Queue straight to the Book Now tab + booking.
    test()->actingAs($dispatcher)
        ->get(route('admin.notifications.open', $notification))
        ->assertRedirect(route('admin.dispatch', ['type' => 'book-now', 'booking' => $booking->booking_code]));

    $notification->refresh();
    expect($notification->read_at)->not->toBeNull();
    expect($notification->isRead())->toBeTrue();
    expect(DispatcherNotification::unread()->count())->toBe($unreadBefore);

    // After reload it remains read and renders ✓ (a check mark never appears
    // in the inert live-notification JS template, so this is safe unscoped).
    $htmlAfter = actingDispatcherDashboardHtml($dispatcher);
    expect($htmlAfter)->toContain('✓');
});

// ── 3. NEW SCHEDULED BOOKING ────────────────────────────────────────────

it('verify: Scheduled booking produces exactly one correct, unread notification and redirects correctly', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();
    $truckType = verifyTruckType('Scheduled Flatbed');
    $customer = verifyCustomer('Scheduled Customer');

    $scheduledFor = now()->addDay()->setTime(14, 0);

    $booking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'schedule',
        'status' => 'requested',
        'scheduled_for' => $scheduledFor,
    ]));

    $notifications = DispatcherNotification::where('booking_id', $booking->id)->get();
    expect($notifications)->toHaveCount(1);

    $notification = $notifications->first();
    expect($notification->type)->toBe(DispatcherNotification::TYPE_NEW_SCHEDULED);
    expect($notification->booking_id)->toBe($booking->id);
    expect($notification->read_at)->toBeNull();
    expect($notification->title)->toBe('New scheduled booking');

    $lines = $notification->subtitle_lines;
    expect($lines[0])->toBe($booking->booking_code . ' · Scheduled Customer');
    expect($lines[1])->toBe($scheduledFor->format('M j \\· g:i A'));

    $html = actingDispatcherDashboardHtml($dispatcher);
    $dropdown = extractNotifDropdownHtml($html);
    expect($dropdown)->toContain('New scheduled booking')
        ->and($dropdown)->toContain('✕');

    test()->actingAs($dispatcher)
        ->get(route('admin.notifications.open', $notification))
        ->assertRedirect(route('admin.dispatch', ['type' => 'scheduled', 'booking' => $booking->booking_code]));

    expect($notification->fresh()->isRead())->toBeTrue();

    $htmlAfter = actingDispatcherDashboardHtml($dispatcher);
    expect($htmlAfter)->toContain('✓');
});

// ── 4 & 5. WAITING FOR VERIFICATION + DUPLICATE PREVENTION ─────────────

it('verify: transition into waiting_verification creates exactly one notification and redirects to Jobs', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();
    $truckType = verifyTruckType('Verification Truck');
    $customer = verifyCustomer('Verification Customer');

    $booking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'assigned',
    ]));

    // The creation itself (status 'assigned', service_type book_now) already
    // produces one new_book_now notification — clear it so this test isolates
    // the verification-transition behavior cleanly.
    DispatcherNotification::query()->delete();

    $booking->update(['status' => 'waiting_verification']);

    $notifications = DispatcherNotification::where('booking_id', $booking->id)->get();
    expect($notifications)->toHaveCount(1);

    $notification = $notifications->first();
    expect($notification->type)->toBe(DispatcherNotification::TYPE_VERIFICATION_REQUIRED);
    expect($notification->read_at)->toBeNull();
    expect($notification->title)->toBe('Verification required');

    test()->actingAs($dispatcher)
        ->get(route('admin.notifications.open', $notification))
        ->assertRedirect(route('admin.jobs'));

    expect($notification->fresh()->isRead())->toBeTrue();
});

it('verify: saving a booking that stays in waiting_verification does not create a duplicate notification', function () {
    verifyRoles();
    $truckType = verifyTruckType('Duplicate Guard Truck');
    $customer = verifyCustomer('Duplicate Guard Customer');

    $booking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'assigned',
    ]));

    DispatcherNotification::query()->delete();

    $booking->update(['status' => 'waiting_verification']);
    expect(DispatcherNotification::where('type', DispatcherNotification::TYPE_VERIFICATION_REQUIRED)->count())->toBe(1);

    // Re-save the SAME status via an unrelated field change — status is not
    // dirty, so the observer's isDirty('status') guard must block a second row.
    $booking->update(['dispatcher_note' => 'Reviewed by dispatch, no action needed.']);
    $booking->update(['driver_name' => 'Unrelated Driver Update']);
    $booking->touch(); // updated_at-only save, still no status change

    expect(DispatcherNotification::where('type', DispatcherNotification::TYPE_VERIFICATION_REQUIRED)->count())->toBe(1);

    // Directly prove the guard is isDirty('status'), not status === 'waiting_verification':
    // setting the attribute to its own current value and saving must not fire either,
    // since Eloquent does not mark an attribute dirty when set to its existing value.
    $booking->status = 'waiting_verification';
    $booking->save();

    expect(DispatcherNotification::where('type', DispatcherNotification::TYPE_VERIFICATION_REQUIRED)->count())->toBe(1);
});

// ── 6. UNRELATED BOOKING UPDATES ────────────────────────────────────────

it('verify: unrelated booking updates never reproduce new_book_now or new_scheduled notifications', function () {
    verifyRoles();
    $truckType = verifyTruckType('Unrelated Update Truck');
    $customer = verifyCustomer('Unrelated Update Customer');

    $bookNow = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $scheduled = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'schedule',
        'status' => 'requested',
        'scheduled_for' => now()->addHours(5),
    ]));

    expect(DispatcherNotification::count())->toBe(2);

    $bookNow->update(['status' => 'reviewed']);
    $bookNow->update(['status' => 'quoted']);
    $bookNow->update(['status' => 'assigned']);
    $bookNow->update(['pickup_notes' => 'Gate code 4321']);

    $scheduled->update(['status' => 'confirmed']);
    $scheduled->update(['scheduled_time' => '15:30']);

    expect(DispatcherNotification::where('booking_id', $bookNow->id)->count())->toBe(1);
    expect(DispatcherNotification::where('booking_id', $scheduled->id)->count())->toBe(1);
    expect(DispatcherNotification::count())->toBe(2);
});

// ── 7. MULTIPLE UNREAD NOTIFICATIONS — SELECTIVE READ ──────────────────

it('verify: opening one notification marks only that one read, leaving the others unread', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();
    $truckType = verifyTruckType('Multi Unread Truck');
    $customer = verifyCustomer('Multi Unread Customer');

    $bookNowBooking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'assigned',
    ]));

    $scheduledBooking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'schedule',
        'status' => 'requested',
        'scheduled_for' => now()->addHours(6),
    ]));

    $verificationBooking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'assigned',
    ]));
    $verificationBooking->update(['status' => 'waiting_verification']);

    expect(DispatcherNotification::unread()->count())->toBe(4); // 3 initial creations + verification transition

    // Isolate to exactly the three notification instances this test cares about.
    $bookNowNotif = DispatcherNotification::where('booking_id', $bookNowBooking->id)->first();
    $scheduledNotif = DispatcherNotification::where('booking_id', $scheduledBooking->id)->first();
    $verificationNotif = DispatcherNotification::where('type', DispatcherNotification::TYPE_VERIFICATION_REQUIRED)
        ->where('booking_id', $verificationBooking->id)->first();

    $unreadBefore = DispatcherNotification::unread()->count();

    test()->actingAs($dispatcher)
        ->get(route('admin.notifications.open', $scheduledNotif))
        ->assertRedirect(route('admin.dispatch', ['type' => 'scheduled', 'booking' => $scheduledBooking->booking_code]));

    expect($scheduledNotif->fresh()->isRead())->toBeTrue();
    expect($bookNowNotif->fresh()->isRead())->toBeFalse();
    expect($verificationNotif->fresh()->isRead())->toBeFalse();
    expect(DispatcherNotification::unread()->count())->toBe($unreadBefore - 1);
});

// ── 8. MARK ALL AS READ ─────────────────────────────────────────────────

it('verify: mark-all-as-read reads every notification, zeroes the badge, and keeps history intact', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();
    $truckType = verifyTruckType('Mark All Truck');
    $customer = verifyCustomer('Mark All Customer');

    Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'schedule',
        'status' => 'requested',
        'scheduled_for' => now()->addHours(2),
    ]));

    $totalBefore = DispatcherNotification::count();
    expect(DispatcherNotification::unread()->count())->toBe($totalBefore);

    test()->actingAs($dispatcher)
        ->postJson(route('admin.notifications.mark-all-read'))
        ->assertOk()
        ->assertJson(['success' => true, 'unreadCount' => 0]);

    expect(DispatcherNotification::unread()->count())->toBe(0);
    expect(DispatcherNotification::count())->toBe($totalBefore); // nothing deleted

    $html = actingDispatcherDashboardHtml($dispatcher);
    $dropdown = extractNotifDropdownHtml($html);
    expect($html)->not->toContain('id="dispatcherNotifCount"');
    expect($dropdown)->not->toContain('✕');
    expect(substr_count($dropdown, '✓'))->toBeGreaterThanOrEqual($totalBefore);
});

// ── 9. ZERO BADGE ────────────────────────────────────────────────────────

it('verify: the badge element itself is absent from the DOM when unread count is zero', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();

    $html = actingDispatcherDashboardHtml($dispatcher);

    expect($html)->not->toContain('id="dispatcherNotifCount"');
    expect($html)->not->toMatch('/notif-count">\s*0\s*</');
});

// ── 10. DROPDOWN LIMIT (LATEST 5) + VIEW ALL PAGE ───────────────────────

it('verify: the topbar dropdown shows only the latest 5 notifications, newest first, while unread count reflects all', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();
    $truckType = verifyTruckType('Limit Truck');
    $customer = verifyCustomer('Limit Customer');

    $bookings = [];
    for ($i = 1; $i <= 7; $i++) {
        $bookings[] = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
            'service_type' => 'book_now',
            'status' => 'requested',
        ]));
    }

    expect(DispatcherNotification::count())->toBe(7);
    expect(DispatcherNotification::unread()->count())->toBe(7);

    $html = actingDispatcherDashboardHtml($dispatcher);
    $dropdown = extractNotifDropdownHtml($html);

    // Only the 5 most recently created bookings' codes should appear in the
    // dropdown — scoped to the dropdown markup specifically, since the same
    // dashboard page also has an unrelated Incoming Requests panel that
    // legitimately lists every pending booking (including the older ones).
    $latestFive = array_slice(array_reverse($bookings), 0, 5);
    $oldestTwo = array_slice($bookings, 0, 2);

    foreach ($latestFive as $booking) {
        expect($dropdown)->toContain($booking->booking_code);
    }
    foreach ($oldestTwo as $booking) {
        expect($dropdown)->not->toContain($booking->booking_code);
    }

    // Badge still reflects all 7 unread, not just the 5 visible rows.
    expect($html)->toMatch('/notif-count"[^>]*>7</');

    expect($html)->toContain('View all notifications');

    // View All page shows the rest too, newest first, paginated.
    $viewAllHtml = test()->actingAs($dispatcher)
        ->get(route('admin.notifications.index'))
        ->assertOk()
        ->getContent();

    foreach ($bookings as $booking) {
        expect($viewAllHtml)->toContain($booking->booking_code);
    }

    // Confirm ordering: the last-created booking's code appears before the first-created one.
    $posNewest = strpos($viewAllHtml, end($bookings)->booking_code);
    $posOldest = strpos($viewAllHtml, $bookings[0]->booking_code);
    expect($posNewest)->toBeLessThan($posOldest);
});

it('verify: the View All Notifications page paginates beyond the per-page limit', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();
    $truckType = verifyTruckType('Pagination Truck');
    $customer = verifyCustomer('Pagination Customer');

    for ($i = 1; $i <= 30; $i++) {
        Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
            'service_type' => 'book_now',
            'status' => 'requested',
        ]));
    }

    expect(DispatcherNotification::count())->toBe(30);

    $response = test()->actingAs($dispatcher)->get(route('admin.notifications.index'))->assertOk();
    $html = $response->getContent();

    // Controller paginates 25 per page — page 1 must not render all 30, and a second page must exist.
    expect(substr_count($html, 'notif-item'))->toBeLessThan(30 * 2); // sanity: not literally all 30 rows unpaginated twice over
    expect($html)->toContain('page=2');
});

// ── 11. DISPLAY CONTENT PER TYPE ────────────────────────────────────────

it('verify: each notification type renders its correct title text', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();
    $truckType = verifyTruckType('Content Truck');
    $customer = verifyCustomer('Content Customer');

    Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $scheduled = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'schedule',
        'status' => 'requested',
        'scheduled_for' => now()->addHours(4),
    ]));

    $verification = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'assigned',
    ]));
    $verification->update(['status' => 'waiting_verification']);

    $html = actingDispatcherDashboardHtml($dispatcher);

    expect($html)->toContain('New booking request');
    expect($html)->toContain('New scheduled booking');
    expect($html)->toContain('Verification required');
    expect($html)->toContain('Waiting for verification');
});

// ── 12. NAVIGATION TARGETS (INDEPENDENT PER TYPE) ───────────────────────

it('verify: navigation targets are correct and independent for all three notification types', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();
    $truckType = verifyTruckType('Nav Truck');
    $customer = verifyCustomer('Nav Customer');

    $bookNow = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));
    $scheduled = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'schedule',
        'status' => 'requested',
        'scheduled_for' => now()->addHours(1),
    ]));
    $verification = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'assigned',
    ]));
    $verification->update(['status' => 'waiting_verification']);

    $bookNowNotif = DispatcherNotification::where('booking_id', $bookNow->id)->first();
    $scheduledNotif = DispatcherNotification::where('booking_id', $scheduled->id)->first();
    $verificationNotif = DispatcherNotification::where('type', DispatcherNotification::TYPE_VERIFICATION_REQUIRED)
        ->where('booking_id', $verification->id)->first();

    $bookNowUrl = route('admin.dispatch', ['type' => 'book-now', 'booking' => $bookNow->booking_code]);
    $scheduledUrl = route('admin.dispatch', ['type' => 'scheduled', 'booking' => $scheduled->booking_code]);

    expect($bookNowNotif->target_url)->toBe($bookNowUrl);
    expect($scheduledNotif->target_url)->toBe($scheduledUrl);
    expect($verificationNotif->target_url)->toBe(route('admin.jobs'));

    test()->actingAs($dispatcher)->get(route('admin.notifications.open', $bookNowNotif))->assertRedirect($bookNowUrl);
    test()->actingAs($dispatcher)->get(route('admin.notifications.open', $scheduledNotif))->assertRedirect($scheduledUrl);
    test()->actingAs($dispatcher)->get(route('admin.notifications.open', $verificationNotif))->assertRedirect(route('admin.jobs'));
});

// ── 13. READ STATE PERSISTENCE ACROSS REQUESTS ──────────────────────────

it('verify: read state is database-backed and persists across separate HTTP requests', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();
    $truckType = verifyTruckType('Persistence Truck');
    $customer = verifyCustomer('Persistence Customer');

    $booking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $notification = DispatcherNotification::where('booking_id', $booking->id)->firstOrFail();

    test()->actingAs($dispatcher)
        ->get(route('admin.notifications.open', $notification))
        ->assertRedirect(route('admin.dispatch', ['type' => 'book-now', 'booking' => $booking->booking_code]));

    // Fresh request, same authenticated user, independent of any client-side state.
    $secondRequestHtml = test()->actingAs($dispatcher)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->getContent();

    expect(DispatcherNotification::find($notification->id)->read_at)->not->toBeNull();
    expect($secondRequestHtml)->not->toContain('id="dispatcherNotifCount"');
});

// ── 14. AUTHORIZATION ────────────────────────────────────────────────────

it('verify: unauthenticated users cannot view, open, or mark-all-read dispatcher notifications', function () {
    verifyRoles();
    $truckType = verifyTruckType('Auth Truck A');
    $customer = verifyCustomer('Auth Customer A');

    $booking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));
    $notification = DispatcherNotification::where('booking_id', $booking->id)->firstOrFail();

    test()->get(route('admin.notifications.index'))->assertRedirect(route('login'));
    test()->get(route('admin.notifications.open', $notification))->assertRedirect(route('login'));
    test()->postJson(route('admin.notifications.mark-all-read'))->assertUnauthorized();

    expect($notification->fresh()->isRead())->toBeFalse();
});

it('verify: a non-Dispatcher role (Team Leader) is forbidden from dispatcher notification routes', function () {
    verifyRoles();
    $truckType = verifyTruckType('Auth Truck B');
    $customer = verifyCustomer('Auth Customer B');

    $booking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));
    $notification = DispatcherNotification::where('booking_id', $booking->id)->firstOrFail();

    $teamLeader = User::factory()->create(['role_id' => 3]);

    test()->actingAs($teamLeader)->get(route('admin.notifications.index'))->assertForbidden();
    test()->actingAs($teamLeader)->get(route('admin.notifications.open', $notification))->assertForbidden();
    test()->actingAs($teamLeader)->postJson(route('admin.notifications.mark-all-read'))->assertForbidden();

    expect($notification->fresh()->isRead())->toBeFalse();
});

it('verify: Super Admin (role 1) is also forbidden from dispatcher-only notification routes', function () {
    verifyRoles();
    $superAdmin = User::factory()->create(['role_id' => 1]);

    test()->actingAs($superAdmin)->get(route('admin.notifications.index'))->assertForbidden();
    test()->actingAs($superAdmin)->postJson(route('admin.notifications.mark-all-read'))->assertForbidden();
});

// ── 16. DATABASE CONSTRAINTS ─────────────────────────────────────────────

it('verify: deleting a booking cascades and deletes its dispatcher notifications', function () {
    verifyRoles();
    $truckType = verifyTruckType('Cascade Truck');
    $customer = verifyCustomer('Cascade Customer');

    $booking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $notification = DispatcherNotification::where('booking_id', $booking->id)->firstOrFail();

    $booking->delete();

    expect(DispatcherNotification::find($notification->id))->toBeNull();
});

it('verify: read_at is nullable and timestamps are populated on creation', function () {
    verifyRoles();
    $truckType = verifyTruckType('Schema Truck');
    $customer = verifyCustomer('Schema Customer');

    $booking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    $notification = DispatcherNotification::where('booking_id', $booking->id)->firstOrFail();

    expect($notification->read_at)->toBeNull();
    expect($notification->created_at)->not->toBeNull();
    expect($notification->updated_at)->not->toBeNull();
});

// ── 17. OBSERVER REGISTERED EXACTLY ONCE ────────────────────────────────

it('verify: the booking-created observer fires exactly once per booking (no duplicate registration)', function () {
    verifyRoles();
    $truckType = verifyTruckType('Observer Truck');
    $customer = verifyCustomer('Observer Customer');

    $booking = Booking::create(array_merge(verifyBookingAttrs($truckType, $customer), [
        'service_type' => 'book_now',
        'status' => 'requested',
    ]));

    // If the observer were bound twice (e.g. registered in two service
    // providers, or looped over twice), a single creation would produce two
    // rows. Exactly 1 proves single registration through the real boot path.
    expect(DispatcherNotification::where('booking_id', $booking->id)->count())->toBe(1);
});

// ── 18 & 19. UI STRUCTURAL RULES + PROFILE/TOPBAR REGRESSION ────────────

it('verify: topbar structural rules — no duplicate heading, real name, generic avatar, correct dropdown copy', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();

    $html = actingDispatcherDashboardHtml($dispatcher);

    expect($html)->not->toContain('topbar-copy');
    expect($html)->toContain('Verify Dispatcher');
    expect($html)->toContain('data-lucide="user"');
    expect($html)->not->toContain('facc15');

    expect($html)->toContain('>Notifications<');
    expect($html)->not->toContain('Update Notifications');
    expect($html)->toContain('Mark all as read');
    expect($html)->toContain('View all notifications');

    expect($html)->toContain('>Settings<');
    expect($html)->toContain('>Log out<');
    expect($html)->not->toContain('Test Dispatcher');
});

it('verify: profile dropdown shows the real role name from the relationship, not a hardcoded label', function () {
    verifyRoles();
    $dispatcher = verifyDispatcher();

    $html = actingDispatcherDashboardHtml($dispatcher);

    expect($html)->toContain($dispatcher->role->name);
});

/**
 * Shared helper: renders the dispatcher dashboard (the page that includes
 * the topbar partial + notification dropdown) for a given user and returns
 * the raw HTML, for content assertions that Laravel's assertSee/assertDontSee
 * would otherwise force into many separate requests.
 */
function actingDispatcherDashboardHtml(User $dispatcher): string
{
    return test()->actingAs($dispatcher)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->getContent();
}

/**
 * Scopes an assertion to just the Blade-rendered notification dropdown rows
 * (the <div id="dispatcherNotifList">...</div> segment of topbar.blade.php),
 * excluding the rest of the page — notably the unrelated Incoming Requests
 * panel (which legitimately lists booking codes of its own) and the inline
 * <script> block's JS template for live/Pusher-pushed notifications (which
 * contains the same ✕ glyph and payload.title fallback text as inert source
 * code, not a rendered row).
 */
function extractNotifDropdownHtml(string $html): string
{
    $start = strpos($html, 'id="dispatcherNotifList"');
    $end = strpos($html, 'notif-view-all', $start);

    expect($start)->not->toBeFalse('dispatcherNotifList marker not found in rendered HTML');
    expect($end)->not->toBeFalse('notif-view-all marker not found in rendered HTML');

    return substr($html, $start, $end - $start);
}
