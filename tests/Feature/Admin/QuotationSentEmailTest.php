<?php

use App\Mail\QuotationSentMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Services\QuotationService;
use Illuminate\Support\Facades\Mail;

function qseRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function qseTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'Quotation Email Truck ' . fake()->unique()->word(),
        'base_rate' => 2500,
        'per_km_rate' => 120,
    ]);
}

function qseCustomer(): Customer
{
    qseRole(5, 'Customer');
    $user = User::factory()->create(['role_id' => 5]);

    return Customer::create([
        'user_id' => $user->id,
        'full_name' => 'Maria Santos',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => fake()->unique()->safeEmail(),
    ]);
}

function qseBooking(Customer $customer, TruckType $truckType, array $overrides = []): Booking
{
    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Quezon City, Metro Manila',
        'dropoff_address' => 'Makati City, Metro Manila',
        'distance_km' => 15,
        'base_rate' => 2500,
        'per_km_rate' => 120,
        'computed_total' => 3820,
        'final_total' => 3820,
        'status' => 'requested',
        'service_type' => 'book_now',
    ], $overrides));

    $booking->update(['booking_code' => 'TM-QSE' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh(['customer', 'truckType']);
}

function qseQuotation(Booking $booking, float $additionalFee, float $estimatedPrice): Quotation
{
    return Quotation::create([
        'quotation_number' => 'Q-QSE-' . uniqid(),
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

it('sends the quotation email to the customer and updates status/expiry exactly as before', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType);
    $quotation = qseQuotation($booking, 200, 4278.40);

    Mail::fake();
    $svc = app(QuotationService::class)->sendQuotation($quotation, 168);

    expect($svc->status)->toBe('sent')
        ->and($svc->sent_at)->not->toBeNull()
        ->and($svc->expires_at)->not->toBeNull();

    Mail::assertSent(QuotationSentMail::class, function ($mail) use ($customer) {
        return $mail->hasTo($customer->email);
    });
});

it('renders the quotation number, total and attachment name in the quotation email, and the full breakdown in its PDF', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType);
    $quotation = qseQuotation($booking, 200, 4478.40);

    $html = (new QuotationSentMail($quotation))->render();
    $doc = app(\App\Services\DocumentPresenter::class)->quotation($quotation);
    $pdfHtml = view('documents.quotation', ['doc' => $doc])->render();

    expect($html)->toContain($quotation->quotation_number)
        ->and($html)->toContain('₱4,478.40')
        ->and($html)->toContain('Quotation ' . $quotation->quotation_number . '.pdf')
        ->and($html)->toContain('Open the TowMate app to review and accept this quotation.')
        ->and($html)->toContain($quotation->expires_at->format('M d, Y'))
        ->and($html)->not->toContain('@towmate.com');

    expect($pdfHtml)->toContain('Quezon City, Metro Manila')
        ->and($pdfHtml)->toContain('Makati City, Metro Manila')
        ->and($pdfHtml)->toContain($truckType->name)
        ->and($pdfHtml)->toContain('15.00 km')
        ->and($pdfHtml)->toContain('₱2,500.00')
        ->and($pdfHtml)->toContain('₱1,320.00')
        ->and($pdfHtml)->toContain('VAT (12%)')
        ->and($pdfHtml)->toContain('₱458.40')
        ->and($pdfHtml)->toContain('Additional fee')
        ->and($pdfHtml)->toContain('₱4,478.40');
});

it('shows a negative adjustment in the quotation PDF only when it reconciles with the stored total', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType);
    $presenter = app(\App\Services\DocumentPresenter::class);

    $reconciling = $presenter->quotation(qseQuotation($booking, -300, 3978.40));
    expect($reconciling['reliable'])->toBeTrue()
        ->and(collect($reconciling['summary'])->pluck('label')->all())->toContain('Adjustment')
        ->and(collect($reconciling['summary'])->pluck('value')->all())->toContain('−₱300.00');

    $stale = $presenter->quotation(qseQuotation($booking, -300, 3520));
    expect($stale['reliable'])->toBeFalse()
        ->and($stale['summary'])->toBe([])
        ->and($stale['total'])->toBe('₱3,520.00');
});

it('omits the adjustment row entirely when there is no additional fee or discount', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType);
    $doc = app(\App\Services\DocumentPresenter::class)->quotation(qseQuotation($booking, 0, 4278.40));

    expect(collect($doc['summary'])->pluck('label')->all())->toBe(['Subtotal', 'VAT (12%)']);
});

it('uses a fluid, mobile-safe card for the email', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType);
    $quotation = qseQuotation($booking, 0, 4278.40);

    $html = (new QuotationSentMail($quotation))->render();

    expect($html)->toContain('name="viewport"')
        ->and($html)->toContain('max-width:600px')
        ->and($html)->not->toContain('max-width:480px;');
});

it('attaches exactly one PDF and embeds no images', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType);
    $quotation = qseQuotation($booking, 0, 4278.40);

    $mailer = Mail::mailer('array');
    $mailer->to($customer->email)->send(new QuotationSentMail($quotation));

    $email = $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    expect($email->getHtmlBody())->not->toContain('data:image/png;base64,')->not->toContain('cid:')
        ->and($email->getAttachments())->toHaveCount(1)
        ->and($email->getAttachments()[0]->getMediaType() . '/' . $email->getAttachments()[0]->getMediaSubtype())->toBe('application/pdf');
});
