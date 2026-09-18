<?php

use App\Mail\BookingAcceptedMail;
use App\Mail\BookingReceiptMail;
use App\Mail\BookingRejectedMail;
use App\Mail\BookingRequestReceivedMail;
use App\Mail\FinalQuotationConfirmedMail;
use App\Mail\InvoiceMail;
use App\Mail\PriceReviewCompletedMail;
use App\Mail\QuotationCancelledMail;
use App\Mail\QuotationFollowUpMail;
use App\Mail\QuotationUpdatedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function cetRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cetCustomer(): Customer
{
    cetRole(5, 'Customer');
    $user = User::factory()->create(['role_id' => 5]);

    return Customer::create([
        'user_id' => $user->id,
        'full_name' => 'Renata Cruz',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => fake()->unique()->safeEmail(),
    ]);
}

function cetTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'Email Render Truck ' . fake()->unique()->word(),
        'base_rate' => 1800,
        'per_km_rate' => 150,
    ]);
}

function cetBooking(Customer $customer, TruckType $truckType, array $overrides = []): Booking
{
    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasig City, Metro Manila',
        'dropoff_address' => 'Taguig City, Metro Manila',
        'distance_km' => 9.4,
        'base_rate' => 1800,
        'per_km_rate' => 150,
        'computed_total' => 2610,
        'final_total' => 2610,
        'status' => 'requested',
        'service_type' => 'book_now',
    ], $overrides));

    $booking->update(['booking_code' => 'TM-CET' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh(['customer', 'truckType']);
}

function cetQuotation(Booking $booking, float $additionalFee, float $estimatedPrice): Quotation
{
    return Quotation::create([
        'quotation_number' => 'Q-CET-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id' => $booking->customer_id,
        'truck_type_id' => $booking->truck_type_id,
        'pickup_address' => $booking->pickup_address,
        'dropoff_address' => $booking->dropoff_address,
        'distance_km' => $booking->distance_km,
        'estimated_price' => $estimatedPrice,
        'additional_fee' => $additionalFee,
        'status' => 'sent',
        'sent_at' => now(),
        'expires_at' => now()->addHours(168),
        'is_current' => true,
    ])->fresh(['customer', 'truckType', 'sourceBooking']);
}

it('renders the booking accepted email with the fluid mobile-safe width and real booking data', function () {
    $customer = cetCustomer();
    $truckType = cetTruckType();
    $booking = cetBooking($customer, $truckType);

    $html = (new BookingAcceptedMail($booking))->render();

    expect($html)->toContain('max-width:480px')
        ->and($html)->toContain($customer->full_name)
        ->and($html)->toContain('Pasig City, Metro Manila')
        ->and($html)->not->toContain('{{--');
});

it('renders the booking rejected email with the fluid mobile-safe width and real booking data', function () {
    $customer = cetCustomer();
    $truckType = cetTruckType();
    $booking = cetBooking($customer, $truckType, [
        'rejection_reason' => 'Vehicle exceeds unit weight capacity.',
    ]);
    $booking->load('customer');

    $html = (new BookingRejectedMail($booking))->render();

    expect($html)->toContain('max-width:480px')
        ->and($html)->toContain('Vehicle exceeds unit weight capacity.')
        ->and($html)->not->toContain('{{--');
});

it('renders the booking requested email with the fluid mobile-safe width and real booking data', function () {
    $customer = cetCustomer();
    $truckType = cetTruckType();
    $booking = cetBooking($customer, $truckType);

    $html = (new BookingRequestReceivedMail($booking))->render();

    expect($html)->toContain('max-width:480px')
        ->and($html)->toContain($customer->full_name)
        ->and($html)->toContain('2,610.00')
        ->and($html)->not->toContain('{{--');
});

it('renders the quotation cancelled email with the fluid mobile-safe width and real quotation data', function () {
    $customer = cetCustomer();
    $truckType = cetTruckType();
    $booking = cetBooking($customer, $truckType);
    $quotation = cetQuotation($booking, 0, 2610);

    $html = (new QuotationCancelledMail($quotation))->render();

    expect($html)->toContain('max-width:480px')
        ->and($html)->toContain($quotation->quotation_number)
        ->and($html)->not->toContain('{{--');
});

it('renders the quotation updated email with the fluid mobile-safe width and real price breakdown', function () {
    $customer = cetCustomer();
    $truckType = cetTruckType();
    $booking = cetBooking($customer, $truckType);
    $quotation = cetQuotation($booking, 150, 2760);

    $html = (new QuotationUpdatedMail($quotation))->render();

    expect($html)->toContain('max-width:480px')
        ->and($html)->toContain('2,760.00')
        ->and($html)->not->toContain('{{--');
});

it('renders the quotation follow-up email with the fluid mobile-safe width and real price breakdown', function () {
    $customer = cetCustomer();
    $truckType = cetTruckType();
    $booking = cetBooking($customer, $truckType);
    $quotation = cetQuotation($booking, 0, 2610);

    $html = (new QuotationFollowUpMail($quotation))->render();

    expect($html)->toContain('max-width:480px')
        ->and($html)->toContain($quotation->quotation_number)
        ->and($html)->not->toContain('{{--');
});

it('renders the price review completed email with the fluid mobile-safe width and real price breakdown', function () {
    $customer = cetCustomer();
    $truckType = cetTruckType();
    $booking = cetBooking($customer, $truckType);
    $quotation = cetQuotation($booking, 0, 2610);

    $html = (new PriceReviewCompletedMail($quotation))->render();

    expect($html)->toContain('max-width:480px')
        ->and($html)->toContain($quotation->quotation_number)
        ->and($html)->not->toContain('{{--');
});

it('renders the final quotation confirmed email with the fluid mobile-safe width and real booking data', function () {
    $customer = cetCustomer();
    $truckType = cetTruckType();
    $booking = cetBooking($customer, $truckType, [
        'final_total' => 2610,
    ]);

    $html = (new FinalQuotationConfirmedMail($booking))->render();

    expect($html)->toContain('max-width:480px')
        ->and($html)->toContain($customer->full_name)
        ->and($html)->not->toContain('{{--');
});

it('renders the invoice email with the fluid mobile-safe width and real invoice data', function () {
    $customer = cetCustomer();
    $truckType = cetTruckType();
    $booking = cetBooking($customer, $truckType, [
        'status' => 'completed',
    ]);
    $invoice = Invoice::create([
        'booking_id' => $booking->id,
        'invoice_number' => 'INV-CET-' . uniqid(),
        'subtotal' => 2610,
        'additional_fee' => 0,
        'discount' => 0,
        'total' => 2610,
    ]);

    $html = (new InvoiceMail($invoice))->render();

    expect($html)->toContain('max-width:480px')
        ->and($html)->toContain($customer->full_name)
        ->and($html)->toContain($invoice->invoice_number)
        ->and($html)->not->toContain('{{--');
});

it('renders the booking receipt email with the fluid mobile-safe width and real receipt data', function () {
    $customer = cetCustomer();
    $truckType = cetTruckType();
    $booking = cetBooking($customer, $truckType, [
        'status' => 'completed',
        'payment_method' => 'gcash',
    ]);
    cetRole(2, 'Dispatcher');
    $dispatcher = User::factory()->create(['role_id' => 2]);
    Receipt::create([
        'booking_id' => $booking->id,
        'receipt_number' => 'RCT-CET-' . uniqid(),
        'generated_by' => $dispatcher->id,
    ]);
    $booking->refresh();

    $html = (new BookingReceiptMail($booking))->render();

    expect($html)->toContain('max-width:480px')
        ->and($html)->toContain($customer->full_name)
        ->and($html)->toContain('GCash')
        ->and($html)->not->toContain('{{--');
});
