<?php

use App\Mail\BookingAcceptedMail;
use App\Mail\BookingRejectedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

function makeDispatchScenario(): array
{
    $dispatcherRole = new Role([
        'name' => 'Dispatcher',
        'description' => 'Dispatch staff',
    ]);
    $dispatcherRole->id = 2;
    $dispatcherRole->save();

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
        'distance_fee' => '937.50',
        'discount_percentage' => '0',
        'price' => '₱2,950.00',
        'dispatcher_note' => 'Distance and recovery setup were reviewed by dispatch.',
    ]);

    $response->assertOk()->assertJson([
        'success' => true,
    ]);

    $booking->refresh();

    expect($booking->status)->toBe('quotation_sent')
        ->and((float) $booking->final_total)->toBe(2950.0)
        ->and($booking->quotation_generated)->toBeTrue()
        ->and($booking->quotation_number)->not->toBeNull()
        ->and($booking->quotation_sent_at)->not->toBeNull()
        ->and($booking->initial_quote_path)->not->toBeNull();

    Mail::assertSent(BookingAcceptedMail::class, function (BookingAcceptedMail $mail) use ($booking) {
        $html = $mail->render();

        return $mail->hasTo($booking->customer->email)
            && str_contains($html, $booking->customer->full_name)
            && str_contains($html, $booking->pickup_address)
            && str_contains(strtolower($html), 'quotation')
            && str_contains($html, '2,950.00');
    });
});

it('rejects a booking and emails the rejection reason to the customer', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'reject',
        'rejection_reason' => 'No driver is currently available in your area.',
    ]);

    $response->assertOk()->assertJson([
        'success' => true,
    ]);

    $booking->refresh();

    expect($booking->status)->toBe('cancelled')
        ->and($booking->rejection_reason)->toBe('No driver is currently available in your area.');

    Mail::assertSent(BookingRejectedMail::class, function (BookingRejectedMail $mail) use ($booking) {
        $html = $mail->render();

        return $mail->hasTo($booking->customer->email)
            && str_contains($html, $booking->customer->full_name)
            && str_contains($html, $booking->pickup_address)
            && str_contains($html, $booking->rejection_reason);
    });
});

it('stores the selected available unit when dispatch sends a quotation', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();

    $unit = makeReadyUnitForBooking($booking);

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'additional_fee' => '350',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '12.50',
        'distance_fee' => '937.50',
        'discount_percentage' => '0',
        'dispatcher_note' => 'Dispatch reserved a ready unit for this request.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();

    expect($booking->assigned_unit_id)->toBe($unit->id)
        ->and((float) $booking->additional_fee)->toBe(350.0)
        ->and($booking->status)->toBe('quotation_sent');
});

it('only shows units with online available team leaders in the dispatch quotation dropdown', function () {
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
        'distance_fee' => '937.50',
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
        'distance_fee' => '937.50',
        'discount_percentage' => '0',
        'dispatcher_note' => 'Distance is still missing.',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['distance_km']);
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
        'distance_fee' => '937.50',
        'discount_percentage' => '25',
        'dispatcher_note' => 'Regular customer discounts should stay locked.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();

    expect((float) $booking->discount_percentage)->toBe(0.0)
        ->and((float) $booking->final_total)->toBe(2437.5);
});

it('reminds the dispatcher to choose a unit before sending a quotation', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'distance_km' => '12.50',
        'distance_fee' => '937.50',
        'discount_percentage' => '0',
        'dispatcher_note' => 'Missing unit selection should be blocked.',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Please choose an available unit before sending the quotation.');
});

it('blocks dispatcher from selecting a unit without an available team leader', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();

    $unit = Unit::create([
        'name' => 'Offline Unit 01',
        'plate_number' => 'OFF-1001',
        'truck_type_id' => $booking->truck_type_id,
        'team_leader_id' => null,
        'status' => 'available',
    ]);

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '12.50',
        'distance_fee' => '937.50',
        'discount_percentage' => '0',
        'dispatcher_note' => 'Trying to reserve a unit with no active team leader.',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Team Leader not available. Please choose another unit.');
});
