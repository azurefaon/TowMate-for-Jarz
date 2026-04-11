<?php

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

it('shows a public quotation review page from the email link and lets the customer accept directly', function () {
    [$user, $customer, $booking] = makeQuotedBookingForCustomerFlow();

    $signedUrl = URL::temporarySignedRoute(
        'quotation.review',
        now()->addMinutes(30),
        ['booking' => $booking]
    );

    $this->get($signedUrl)
        ->assertOk()
        ->assertSee('Review your quotation', false)
        ->assertDontSee('Choose your response', false)
        ->assertDontSee('You can approve the quotation now or request a price adjustment from dispatch.', false)
        ->assertSee('Accept & continue', false)
        ->assertDontSee('Counter-offer amount', false)
        ->assertDontSee('Message for dispatch', false)
        ->assertDontSee('Request adjustment', false)
        ->assertSee('Q-TEST-2001', false);

    $this->post($signedUrl, [
        'action' => 'accept',
    ])
        ->assertRedirect();

    expect($booking->fresh()->status)->toBe('confirmed');
});
