<?php

use App\Mail\BookingAcceptedMail;
use App\Mail\FinalQuotationConfirmedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

function makeQuotedBookingForCustomerFlow(): array
{
    $customerRole = Role::find(5);

    if (! $customerRole) {
        $customerRole = new Role([
            'name' => 'Customer',
            'description' => 'Customer role',
        ]);
        $customerRole->id = 5;
        $customerRole->save();
    }

    $user = User::factory()->create([
        'role_id' => $customerRole->id,
        'email' => 'carla@example.com',
    ]);

    $customer = Customer::create([
        'id' => $user->id,
        'full_name' => 'Carla Ramos',
        'age' => 30,
        'phone' => '09181234567',
        'email' => $user->email,
    ]);

    $truckType = TruckType::create([
        'name' => 'Flatbed Tow',
        'base_rate' => 1600,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Standard towing support',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'created_by_admin_id' => null,
        'age' => 30,
        'pickup_address' => 'Makati Avenue',
        'dropoff_address' => 'BGC Taguig',
        'distance_km' => 10,
        'base_rate' => 1600,
        'per_km_rate' => 85,
        'computed_total' => 2450,
        'final_total' => 2800,
        'quotation_generated' => true,
        'quotation_number' => 'Q-TEST-2001',
        'initial_quote_path' => 'quotations/test-initial.html',
        'quotation_sent_at' => now(),
        'status' => 'quotation_sent',
    ]);

    return [$user, $customer, $booking->fresh(['customer', 'truckType'])];
}

it('lets the customer accept the dispatcher quotation before dispatch proceeds', function () {
    Mail::fake();

    [$user, $customer, $booking] = makeQuotedBookingForCustomerFlow();

    $response = $this->actingAs($user)->post(route('customer.booking.quotation.respond', $booking), [
        'action' => 'accept',
    ]);

    $response->assertRedirect(route('customer.track', $booking));
    $response->assertSessionHas('success');

    $booking->refresh();

    expect($booking->status)->toBe('confirmed')
        ->and($booking->quotation_status)->toBe('accepted')
        ->and($booking->customer_approved_at)->not->toBeNull()
        ->and($booking->final_quote_path)->not->toBeNull();

    Mail::assertSent(FinalQuotationConfirmedMail::class, fn(FinalQuotationConfirmedMail $mail) => $mail->hasTo($customer->email));
});

it('lets the customer request negotiation with a counter offer and note', function () {
    [$user, $customer, $booking] = makeQuotedBookingForCustomerFlow();

    $response = $this->actingAs($user)->post(route('customer.booking.quotation.respond', $booking), [
        'action' => 'negotiate',
        'counter_offer_amount' => '2500',
        'customer_response_note' => 'Can you lower the quote a bit? I am only a few streets away.',
    ]);

    $response->assertRedirect(route('customer.track', $booking));
    $response->assertSessionHas('success');

    $booking->refresh();

    expect($booking->status)->toBe('reviewed')
        ->and((float) $booking->counter_offer_amount)->toBe(2500.0)
        ->and($booking->customer_response_note)->toContain('lower the quote');
});

it('shows a public quotation summary page from the email link without response buttons', function () {
    [$user, $customer, $booking] = makeQuotedBookingForCustomerFlow();

    $signedUrl = URL::temporarySignedRoute(
        'quotation.review',
        now()->addMinutes(30),
        ['booking' => $booking]
    );

    $this->get($signedUrl)
        ->assertOk()
        ->assertSee('Review your quotation', false)
        ->assertSee('Price Breakdown', false)
        ->assertSee('Final total', false)
        ->assertDontSee('Choose your response', false)
        ->assertDontSee('Accept & continue', false)
        ->assertDontSee('Counter-offer amount', false)
        ->assertDontSee('Message for dispatch', false)
        ->assertDontSee('Request adjustment', false)
        ->assertSee('Q-TEST-2001', false);
});

it('expires an old quotation after seven days and blocks customer acceptance', function () {
    [$user, $customer, $booking] = makeQuotedBookingForCustomerFlow();

    $booking->update([
        'quotation_status' => 'active',
        'quotation_sent_at' => now()->subDays(8),
        'quotation_expires_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)->post(route('customer.booking.quotation.respond', $booking), [
        'action' => 'accept',
    ]);

    $response->assertRedirect(route('customer.track', $booking));
    $response->assertSessionHas('error');

    expect($booking->fresh()->quotation_status)->toBe('expired')
        ->and($booking->fresh()->status)->toBe('quotation_sent');
});

it('sends a follow-up reminder once the quotation reaches day five', function () {
    Mail::fake();

    [$user, $customer, $booking] = makeQuotedBookingForCustomerFlow();

    $booking->update([
        'quotation_status' => 'active',
        'quotation_sent_at' => now()->subDays(5)->subMinutes(5),
        'quotation_expires_at' => now()->addDays(2),
        'quotation_follow_up_sent_at' => null,
    ]);

    $this->artisan('towmate:sync-quotation-lifecycle')->assertExitCode(0);

    expect($booking->fresh()->quotation_follow_up_sent_at)->not->toBeNull()
        ->and($booking->fresh()->quotation_status)->toBe('active');

    Mail::assertSent(BookingAcceptedMail::class, function (BookingAcceptedMail $mail) use ($customer) {
        return $mail->hasTo($customer->email) && $mail->isReminder === true;
    });
});

it('recalculates the quotation when the customer updates the booking route before dispatch', function () {
    Mail::fake();

    [$user, $customer, $booking] = makeQuotedBookingForCustomerFlow();

    $updatedTruckType = TruckType::create([
        'name' => 'Heavy Duty Tow',
        'base_rate' => 1800,
        'per_km_rate' => 95,
        'max_tonnage' => 8,
        'description' => 'Long-haul support',
    ]);

    $response = $this->actingAs($user)->patch(route('customer.booking.update', $booking), [
        'truck_type_id' => $updatedTruckType->id,
        'pickup_address' => 'Ortigas Center',
        'dropoff_address' => 'Alabang Town Center',
        'pickup_notes' => 'Please meet at the loading bay.',
        'distance_km' => '18',
    ]);

    $response->assertRedirect(route('customer.track', $booking));
    $response->assertSessionHas('success');

    $booking->refresh();
    $expectedTotal = round((float) $booking->computed_total + (float) $booking->additional_fee - (float) $booking->discount_amount, 2);

    expect($booking->pickup_address)->toBe('Ortigas Center')
        ->and($booking->dropoff_address)->toBe('Alabang Town Center')
        ->and((float) $booking->distance_km)->toBe(18.0)
        ->and((float) $booking->base_rate)->toBe(1800.0)
        ->and((float) $booking->per_km_rate)->toBe(95.0)
        ->and((float) $booking->final_total)->toBe($expectedTotal)
        ->and($booking->quotation_status)->toBe('active')
        ->and($booking->status)->toBe('quotation_sent')
        ->and($booking->quotation_follow_up_sent_at)->toBeNull()
        ->and($booking->quotation_expires_at)->not->toBeNull();

    Mail::assertSent(BookingAcceptedMail::class, function (BookingAcceptedMail $mail) use ($customer) {
        return $mail->hasTo($customer->email)
            && str_contains($mail->render(), 'Ortigas Center')
            && str_contains($mail->render(), 'Alabang Town Center');
    });
});
