<?php

// TC015.12 — customer-wide, informational risk flag controlled by Dispatchers.

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function crfRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function crfUser(int $roleId, string $name): User
{
    crfRole($roleId, $name);

    return User::factory()->create(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false]);
}

function crfCustomer(string $name = 'Juan Dela Cruz'): Customer
{
    $user = User::factory()->create(['role_id' => crfRole(5, 'Customer')->id]);

    return Customer::create([
        'user_id' => $user->id,
        'full_name' => $name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);
}

function crfBooking(Customer $customer, string $status = 'requested'): Booking
{
    $truckType = TruckType::firstOrCreate(['name' => 'CRF Truck'], ['base_rate' => 1500, 'per_km_rate' => 60]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => $status,
    ]);
    $booking->update(['booking_code' => 'TM-CRF' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

// ── Authorization ─────────────────────────────────────────────────────────
it('lets an authorized dispatcher flag the correct customer only', function () {
    $dispatcher = crfUser(2, 'Dispatcher');
    $target = crfCustomer('Target Customer');
    $other = crfCustomer('Other Customer');
    $booking = crfBooking($target);
    crfBooking($other);

    $this->actingAs($dispatcher)->postJson(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'high', 'risk_reason' => 'Repeated no-shows.',
    ])->assertOk()->assertJsonPath('risk.label', 'High risk');

    expect($target->fresh()->risk_level)->toBe('high')
        ->and($target->fresh()->risk_reason)->toBe('Repeated no-shows.')
        ->and($other->fresh()->risk_level)->toBeNull();
});

it('denies risk changes to every non-dispatcher role and to guests, including direct API calls', function () {
    $customer = crfCustomer();
    $booking = crfBooking($customer);
    $payload = ['risk_level' => 'high', 'risk_reason' => 'Nope.'];

    foreach ([[1, 'Owner'], [3, 'Team Leader'], [4, 'Driver'], [5, 'Customer'], [6, 'System Admin']] as [$id, $name]) {
        $this->actingAs(crfUser($id, $name))->postJson(route('admin.booking.mark-risk', $booking), $payload)->assertForbidden();
        $this->actingAs(crfUser($id, $name))->postJson(route('admin.booking.clear-risk', $booking), ['risk_reason' => 'Nope.'])->assertForbidden();
    }

    auth()->logout();
    $this->postJson(route('admin.booking.mark-risk', $booking), $payload)->assertUnauthorized();

    expect($customer->fresh()->risk_level)->toBeNull();
});

// ── Validation ────────────────────────────────────────────────────────────
it('requires a level and a reason to flag, and a reason to clear', function () {
    $dispatcher = crfUser(2, 'Dispatcher');
    $customer = crfCustomer();
    $booking = crfBooking($customer);

    $this->actingAs($dispatcher)->postJson(route('admin.booking.mark-risk', $booking), ['risk_reason' => 'x'])->assertStatus(422);
    $this->actingAs($dispatcher)->postJson(route('admin.booking.mark-risk', $booking), ['risk_level' => 'high'])->assertStatus(422);
    $this->actingAs($dispatcher)->postJson(route('admin.booking.mark-risk', $booking), ['risk_level' => 'bogus', 'risk_reason' => 'x'])->assertStatus(422);

    $customer->update(['risk_level' => 'high', 'risk_reason' => 'Existing']);
    $this->actingAs($dispatcher)->postJson(route('admin.booking.clear-risk', $booking), [])->assertStatus(422);
    $this->actingAs($dispatcher)->postJson(route('admin.booking.clear-risk', $booking), ['risk_reason' => '   '])->assertStatus(422);

    expect($customer->fresh()->risk_level)->toBe('high');
});

// ── Clearing and audit ────────────────────────────────────────────────────
it('clears the flag with a reason and records the actor, time and transition in the audit trail', function () {
    $dispatcher = crfUser(2, 'Dispatcher');
    $customer = crfCustomer();
    $booking = crfBooking($customer);

    $this->actingAs($dispatcher)->postJson(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'blacklist', 'risk_reason' => 'Fraud.',
    ])->assertOk();
    expect($customer->fresh()->blacklisted_at)->not->toBeNull();

    $this->actingAs($dispatcher)->postJson(route('admin.booking.clear-risk', $booking), [
        'risk_reason' => 'Resolved after review.',
    ])->assertOk()->assertJson(['success' => true, 'risk_level' => null, 'risk' => null]);

    $fresh = $customer->fresh();
    expect($fresh->risk_level)->toBeNull()
        ->and($fresh->risk_reason)->toBeNull()
        ->and($fresh->blacklisted_at)->toBeNull();

    $logs = AuditLog::where('action', 'customer_risk_updated')->where('entity_id', $customer->id)->orderBy('id')->get();
    expect($logs)->toHaveCount(2);

    $mark = $logs[0];
    $clear = $logs[1];
    expect($mark->user_id)->toBe($dispatcher->id)
        ->and($clear->user_id)->toBe($dispatcher->id)
        ->and($clear->created_at)->not->toBeNull()
        ->and($clear->old_value['risk'])->toBe('Blacklisted')
        ->and($clear->new_value['risk'])->toBe('Normal')
        ->and($clear->new_value['reason'])->toBe('Resolved after review.')
        // history is preserved: the earlier entry is untouched.
        ->and($mark->new_value['reason'])->toBe('Fraud.');
});

it('keeps a consistent audit chain when flags change back to back', function () {
    $dispatcher = crfUser(2, 'Dispatcher');
    $customer = crfCustomer();
    $booking = crfBooking($customer);

    foreach (['low', 'high', 'medium'] as $level) {
        $this->actingAs($dispatcher)->postJson(route('admin.booking.mark-risk', $booking), [
            'risk_level' => $level, 'risk_reason' => "Set {$level}.",
        ])->assertOk();
    }

    $logs = AuditLog::where('action', 'customer_risk_updated')->where('entity_id', $customer->id)->orderBy('id')->get();
    expect($logs)->toHaveCount(3)
        ->and($logs[1]->old_value['risk'])->toBe($logs[0]->new_value['risk'])
        ->and($logs[2]->old_value['risk'])->toBe($logs[1]->new_value['risk'])
        ->and($customer->fresh()->risk_level)->toBe('medium')
        ->and($customer->fresh()->risk_reason)->toBe('Set medium.');
});

// ── Visibility: badge on dispatcher views, persistence, future bookings ────
it('shows the badge in the dispatcher detail bundle and keeps it after a refresh and a fresh login', function () {
    $customer = crfCustomer();
    $booking = crfBooking($customer);
    $dispatcher = crfUser(2, 'Dispatcher');

    $this->actingAs($dispatcher)->postJson(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'high', 'risk_reason' => 'Chargeback history.',
    ])->assertOk();

    // New session / new login: a different authenticated dispatcher request.
    auth()->logout();
    $second = crfUser(2, 'Dispatcher');
    $this->actingAs($second)->getJson(route('admin.booking.detail-bundle', $booking))
        ->assertOk()
        ->assertJsonPath('customer.risk.level', 'high')
        ->assertJsonPath('customer.risk.label', 'High risk')
        ->assertJsonPath('customer.risk.reason', 'Chargeback history.');
});

it('shows no risk payload for a customer without a flag', function () {
    $booking = crfBooking(crfCustomer());

    $this->actingAs(crfUser(2, 'Dispatcher'))->getJson(route('admin.booking.detail-bundle', $booking))
        ->assertOk()
        ->assertJsonPath('customer.risk', null);
});

it('applies the current flag to future bookings but leaves other customers\' bookings unflagged', function () {
    $dispatcher = crfUser(2, 'Dispatcher');
    $flagged = crfCustomer('Flagged Customer');
    $clean = crfCustomer('Clean Customer');
    $firstBooking = crfBooking($flagged);
    $cleanBooking = crfBooking($clean);

    $this->actingAs($dispatcher)->postJson(route('admin.booking.mark-risk', $firstBooking), [
        'risk_level' => 'medium', 'risk_reason' => 'Late cancellations.',
    ])->assertOk();

    $futureBooking = crfBooking($flagged);

    $this->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $futureBooking))
        ->assertJsonPath('customer.risk.label', 'Medium risk');
    $this->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $cleanBooking))
        ->assertJsonPath('customer.risk', null);
});

it('renders the badge on dispatcher booking pages, labelled as current status in history', function () {
    $dispatcher = crfUser(2, 'Dispatcher');
    $customer = crfCustomer('Badge Customer');
    $customer->update(['risk_level' => 'high', 'risk_reason' => 'Badge reason']);
    crfBooking($customer, 'completed');
    crfBooking($customer, 'on_job');

    $this->actingAs($dispatcher)->get(route('admin.booking-history'))
        ->assertOk()
        ->assertSee('data-customer-risk="high"', false)
        ->assertSee('Current: High risk');

    $this->actingAs($dispatcher)->get('/admin-dashboard/active-bookings')
        ->assertOk()
        ->assertSee('data-customer-risk="high"', false);

    $this->actingAs($dispatcher)->get(route('admin.jobs'))->assertOk();
});

it('shows no badge for customers without a flag', function () {
    $dispatcher = crfUser(2, 'Dispatcher');
    crfBooking(crfCustomer('Clean Person'), 'completed');

    $this->actingAs($dispatcher)->get(route('admin.booking-history'))
        ->assertOk()
        ->assertDontSee('data-customer-risk', false);
});

// ── Informational only ────────────────────────────────────────────────────
it('does not block, reprice or change any booking when a customer is flagged', function () {
    $dispatcher = crfUser(2, 'Dispatcher');
    $customer = crfCustomer();
    $booking = crfBooking($customer, 'assigned');
    $before = $booking->only(['status', 'final_total', 'computed_total', 'assigned_unit_id']);

    $this->actingAs($dispatcher)->postJson(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'blacklist', 'risk_reason' => 'Fraud.',
    ])->assertOk();

    expect($booking->fresh()->only(['status', 'final_total', 'computed_total', 'assigned_unit_id']))->toBe($before);

    // A new booking from a blacklisted customer is still creatable.
    expect(crfBooking($customer->fresh())->status)->toBe('requested');
});

// ── Never exposed to customers ────────────────────────────────────────────
it('never serializes risk data into customer-facing output', function () {
    $customer = crfCustomer();
    $customer->update(['risk_level' => 'blacklisted', 'risk_reason' => 'SECRET_RISK_REASON']);

    expect($customer->fresh()->toArray())->not->toHaveKeys(['risk_level', 'risk_reason', 'blacklisted_at'])
        ->and($customer->fresh()->toJson())->not->toContain('SECRET_RISK_REASON');

    Sanctum::actingAs(User::find($customer->user_id));
    $response = $this->getJson('/api/v1/profile');
    expect($response->getContent())->not->toContain('SECRET_RISK_REASON')->not->toContain('blacklisted');
});

// ── The controls must be reachable from the visible dispatch queue ─────────
// (Regression: the only Details trigger used to sit on cards that no tab displayed, so a
// Dispatcher had no clickable way to open the customer-risk controls.)
it('gives the dispatcher a visible way to open customer and risk details from the dispatch queue', function () {
    $dispatcher = crfUser(2, 'Dispatcher');
    $queued = crfBooking(crfCustomer('Queue Customer'), 'requested');
    $returned = crfBooking(crfCustomer('Returned Customer'), 'returned');
    $returned->forceFill(['returned_at' => now(), 'return_reason' => 'customer_cancelled'])->save();

    $html = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk()->getContent();

    // Intermediate Booking row opens the unified drawer on click; the drawer reads the customer's
    // risk from the row's data attributes and shows the controls only when the row opts in.
    preg_match('/<tr[^>]*data-booking-code="' . preg_quote($queued->booking_code, '/') . '"[^>]*>/s', $html, $row);
    expect($row)->not->toBeEmpty()
        ->and($row[0])->toContain('data-risk-controls="1"')
        ->and($row[0])->toContain('data-customer-risk-level=""');
    // ...and the team's design (no per-row modal trigger) is preserved.
    expect($html)->not->toContain("window.openBookingDetailModal('{$queued->booking_code}')");

    // Returned card (no drawer) gets an explicit button in its footer.
    expect($html)->toContain("openBookingDetailModal('{$returned->job_code}')")
        ->and($html)->toContain('Customer &amp; Risk');
});

it('passes a flagged customer\'s current risk to the drawer through the row data attributes', function () {
    $dispatcher = crfUser(2, 'Dispatcher');
    $customer = crfCustomer('Flagged Queue Customer');
    $customer->update(['risk_level' => 'high', 'risk_reason' => 'Chargeback history']);
    $queued = crfBooking($customer, 'requested');

    $html = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk()->getContent();

    preg_match('/<tr[^>]*data-booking-code="' . preg_quote($queued->booking_code, '/') . '"[^>]*>/s', $html, $row);
    expect($row[0])->toContain('data-customer-risk-level="high"')
        ->and($row[0])->toContain('data-customer-risk-label="High risk"')
        ->and($row[0])->toContain('data-customer-risk-reason="Chargeback history"');
});
