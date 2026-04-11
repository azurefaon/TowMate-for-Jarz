<?php

use App\Mail\BookingAcceptedMail;
use App\Mail\BookingRejectedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

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

it('sends a quotation only after the dispatcher reviews the request and sets the price', function () {
    Mail::fake();

    [$dispatcher, $booking] = makeDispatchScenario();

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
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
