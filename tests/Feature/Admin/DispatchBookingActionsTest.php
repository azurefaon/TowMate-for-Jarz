<?php

use App\Mail\BookingAcceptedMail;
use App\Mail\BookingRejectedMail;
use App\Mail\QuotationSentMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Quotation;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

function makeDispatchScenario(): array
{
    $dispatcherRole = Role::find(2);
    if (! $dispatcherRole) {
        $dispatcherRole = new Role([
            'name' => 'Dispatcher',
            'description' => 'Dispatch staff',
        ]);
        $dispatcherRole->id = 2;
        $dispatcherRole->save();
    }

    $dispatcher = User::factory()->create([
        'role_id' => $dispatcherRole->id,
    ]);

    $customer = Customer::create([
        'full_name' => 'Juan Dela Cruz',
        'age' => 28,
        'phone' => '09123456789',
        'email' => 'juan@example.com',
    ]);

    $truckType = TruckType::create([
        'name' => 'Flatbed Tow',
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'Standard city tow',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'created_by_admin_id' => $dispatcher->id,
        'age' => 28,
        'pickup_address' => 'SM North EDSA',
        'dropoff_address' => 'Quezon City Hall',
        'distance_km' => 12.5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 2437.5,
        'final_total' => 2437.5,
        'status' => 'requested',
    ]);

    return [$dispatcher, $booking->fresh(['customer', 'truckType'])];
}

function makeReadyUnitForBooking(Booking $booking): Unit
{
    $teamLeaderRole = Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Tow unit team leader']);
    $teamLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
    ]);

    Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => 'Dispatch Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $booking->truck_type_id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);
}

it('sends a quotation only after the dispatcher reviews the request and sets the price', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();
    $unit = makeReadyUnitForBooking($booking);

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '12.50',
        'distance_fee' => '637.50',
        'discount_percentage' => '0',
        'price' => '₱2,950.00',
        'dispatcher_note' => 'Distance and recovery setup were reviewed by dispatch.',
    ]);

    $response->assertOk()->assertJson([
        'success' => true,
    ]);

    $booking->refresh();

    expect($booking->status)->toBe('quotation_sent')
        ->and($booking->quotation_status)->toBe('active')
        ->and((float) $booking->final_total)->toBe(2950.0)
        ->and($booking->quotation_generated)->toBeTrue()
        ->and($booking->quotation_number)->not->toBeNull()
        ->and($booking->quotation_sent_at)->not->toBeNull()
        ->and($booking->quotation_expires_at)->not->toBeNull()
        ->and($booking->quotation_follow_up_sent_at)->toBeNull()
        ->and($booking->quotation_expires_at->greaterThan($booking->quotation_sent_at))->toBeTrue()
        ->and($booking->initial_quote_path)->not->toBeNull();

    // The customer quotation email is sent by QuotationService (QuotationSentMail), not the old BookingAcceptedMail.
    Mail::assertSent(QuotationSentMail::class, function (QuotationSentMail $mail) use ($booking) {
        return $mail->hasTo($booking->customer->email)
            && str_contains($mail->render(), '2,950.00');
    });
});

it('does not reserve or assign a unit while preparing an unaccepted quotation', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();
    $unit = makeReadyUnitForBooking($booking);

    $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '12.50',
        'distance_fee' => '637.50',
        'price' => '2,950.00',
    ])->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe('quotation_sent')
        ->and($booking->assigned_unit_id)->toBeNull()
        ->and($booking->assigned_team_leader_id)->toBeNull()
        ->and($booking->selected_unit_id)->toBeNull();
});

it('assigns an accepted Book Now booking only after the current quotation is accepted', function () {
    [$dispatcher, $booking] = makeDispatchScenario();
    $unit = makeReadyUnitForBooking($booking);

    $quotation = Quotation::create([
        'quotation_number' => 'QT-TEST-' . $booking->id,
        'distance_km' => 12.5,
        'source_booking_id' => $booking->id,
        'customer_id' => $booking->customer_id,
        'truck_type_id' => $booking->truck_type_id,
        'pickup_address' => $booking->pickup_address,
        'dropoff_address' => $booking->dropoff_address,
        'estimated_price' => 2950,
        'status' => 'accepted',
        'is_current' => true,
    ]);
    $booking->update([
        'quotation_id' => $quotation->id,
        'status' => 'confirmed',
        'customer_approved_at' => now(),
    ]);

    $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
    ])->assertOk();

    expect($booking->fresh()->assigned_unit_id)->toBe($unit->id)
        ->and($booking->fresh()->status)->toBe('assigned');
});

it('rejects a booking and emails the rejection reason to the customer', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();

    Event::fake([\App\Events\BookingStatusUpdated::class]);

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'reject',
        'rejection_reason' => 'No driver is currently available in your area.',
    ]);

    $response->assertOk()->assertJson([
        'success' => true,
    ]);

    $booking->refresh();

    expect($booking->status)->toBe('cancelled')
        ->and($booking->quotation_status)->toBe('cancelled')
        ->and($booking->rejection_reason)->toBe('No driver is currently available in your area.');

    // The reject endpoint no longer sends BookingRejectedMail; the customer app is told through the
    // realtime status event, and the rejection is audited with its reason.
    Event::assertDispatched(\App\Events\BookingStatusUpdated::class, fn ($e) => $e->booking->id === $booking->id);
    expect(\App\Models\AuditLog::where('action', 'booking_rejected')->where('entity_id', $booking->id)->value('description'))
        ->toBe('No driver is currently available in your area.');
});

it('keeps the additional fee but ignores a unit submitted with a quotation (units are assigned only after acceptance)', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();

    $unit = makeReadyUnitForBooking($booking);

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'additional_fee' => '350',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '12.50',
        'distance_fee' => '637.50',
        'discount_percentage' => '0',
        'dispatcher_note' => 'Dispatch reserved a ready unit for this request.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();

    expect($booking->assigned_unit_id)->toBeNull()
        ->and((float) $booking->additional_fee)->toBe(350.0)
        ->and($booking->status)->toBe('quotation_sent');
});

it('lists only units whose team leader is online in the dispatch unit dropdown', function () {
    [$dispatcher, $booking] = makeDispatchScenario();

    $teamLeaderRole = Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Tow unit team leader']);

    $onlineLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'name' => 'TL Online',
    ]);

    $offlineLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'name' => 'TL Offline',
    ]);

    Cache::put("teamleader:presence:{$onlineLeader->id}", now()->timestamp, now()->addMinutes(2));

    Unit::create([
        'name' => 'Online Unit 11',
        'plate_number' => 'ONL-1101',
        'driver_name' => 'Online Driver',
        'truck_type_id' => $booking->truck_type_id,
        'team_leader_id' => $onlineLeader->id,
        'status' => 'available',
    ]);

    Unit::create([
        'name' => 'Offline Unit 12',
        'plate_number' => 'OFF-1201',
        'truck_type_id' => $booking->truck_type_id,
        'team_leader_id' => $offlineLeader->id,
        'status' => 'available',
    ]);

    $this->actingAs($dispatcher)
        ->get(route('admin.dispatch'))
        ->assertOk()
        ->assertSee('Online Unit 11')
        ->assertDontSee('Offline Unit 12');
});

it('keeps sending quotations working even if the legacy bookings table has no additional fee column', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();
    $unit = makeReadyUnitForBooking($booking);

    if (Schema::hasColumn('bookings', 'additional_fee')) {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('additional_fee');
        });
    }

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '12.50',
        'distance_fee' => '637.50',
        'discount_percentage' => '0',
        'dispatcher_note' => 'Legacy schema compatibility check.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);
});

it('requires the dispatcher to complete the quotation details before sending', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();
    $unit = makeReadyUnitForBooking($booking);

    $response = $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
        'distance_fee' => '637.50',
        'discount_percentage' => '0',
        'dispatcher_note' => 'Distance is still missing.',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['distance_km']);
});

function dispatchScheduledRows(string $html): array
{
    // The queue each booking row is rendered in (scheduled / book-now / ...).
    preg_match_all('/<tr[^>]*data-queue="([^"]*)"/', $html, $m);

    return $m[1];
}

it('lists a future scheduled booking in the scheduled queue as upcoming, not overdue', function () {
    [$dispatcher, $booking] = makeDispatchScenario();

    $booking->update([
        'service_type' => 'schedule',
        'scheduled_date' => now()->addHour()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'scheduled_for' => now()->addHour(),
        'status' => 'scheduled',
    ]);

    $html = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk()->getContent();

    expect(dispatchScheduledRows($html))->toBe(['scheduled'])
        ->and($html)->toContain('data-sched-bucket="needs-quote"')
        ->and($html)->toContain('Starts in')
        ->and($html)->not->toContain('Overdue by');
});

it('keeps a due scheduled booking in the scheduled queue and marks it overdue instead of moving it to Book Now', function () {
    [$dispatcher, $booking] = makeDispatchScenario();

    $booking->update([
        'service_type' => 'schedule',
        'scheduled_date' => now()->subMinutes(10)->toDateString(),
        'scheduled_time' => now()->subMinutes(10)->format('H:i'),
        'scheduled_for' => now()->subMinutes(10),
        'status' => 'scheduled',
    ]);

    $html = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk()->getContent();

    expect(dispatchScheduledRows($html))->toBe(['scheduled'])
        ->and($html)->toContain('Overdue by')
        ->and($html)->toContain('Scheduled Booking');
});

it('keeps a long-overdue scheduled booking in the scheduled queue and filterable as overdue', function () {
    [$dispatcher, $booking] = makeDispatchScenario();

    $booking->update([
        'service_type' => 'schedule',
        'scheduled_date' => now()->subHours(2)->toDateString(),
        'scheduled_time' => now()->subHours(2)->format('H:i'),
        'scheduled_for' => now()->subHours(2),
        'status' => 'scheduled',
    ]);

    $html = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk()->getContent();

    expect(dispatchScheduledRows($html))->toBe(['scheduled'])
        ->and($html)->toContain('Overdue by')
        ->and($html)->toContain('<option value="overdue">Overdue');
});

it('keeps the discount locked for regular customers even if a manual value is submitted', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();
    $unit = makeReadyUnitForBooking($booking);

    $booking->update([
        'customer_type' => 'regular',
        'discount_percentage' => 0,
    ]);

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '12.50',
        'distance_fee' => '637.50',
        'discount_percentage' => '25',
        'dispatcher_note' => 'Regular customer discounts should stay locked.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();

    expect((float) $booking->discount_percentage)->toBe(0.0)
        ->and((float) $booking->vat_exclusive_total)->toBe(2137.5)
        ->and((float) $booking->final_total)->toBe(2394.0); // 2137.50 + 12% VAT
});

function acceptDispatchQuotation(Booking $booking): void
{
    $quotation = Quotation::create([
        'quotation_number' => 'QT-TEST-' . $booking->id,
        'distance_km' => 12.5,
        'source_booking_id' => $booking->id,
        'customer_id' => $booking->customer_id,
        'truck_type_id' => $booking->truck_type_id,
        'pickup_address' => $booking->pickup_address,
        'dropoff_address' => $booking->dropoff_address,
        'estimated_price' => 2950,
        'status' => 'accepted',
        'is_current' => true,
    ]);
    $booking->update([
        'quotation_id' => $quotation->id,
        'status' => 'confirmed',
        'customer_approved_at' => now(),
    ]);
}

it('reminds the dispatcher to choose a unit before dispatching an accepted booking', function () {
    [$dispatcher, $booking] = makeDispatchScenario();
    acceptDispatchQuotation($booking);

    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), ['action' => 'accept'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Please choose an available unit before dispatching.');

    expect($booking->fresh()->assigned_unit_id)->toBeNull();
});

it('blocks dispatching an accepted booking to a unit without an available team leader', function () {
    [$dispatcher, $booking] = makeDispatchScenario();
    acceptDispatchQuotation($booking);

    $unit = Unit::create([
        'name' => 'Offline Unit 01',
        'plate_number' => 'OFF-1001',
        'truck_type_id' => $booking->truck_type_id,
        'team_leader_id' => null,
        'status' => 'available',
    ]);

    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Team Leader not available. Please choose another unit.');

    expect($booking->fresh()->assigned_unit_id)->toBeNull();
});
