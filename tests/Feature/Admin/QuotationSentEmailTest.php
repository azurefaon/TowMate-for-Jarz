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

it('renders every required field in the quotation email', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType, [
        'dispatcher_note' => 'Tight access requires a smaller flatbed',
    ]);
    $quotation = qseQuotation($booking, 200, 4478.40);

    $html = (new QuotationSentMail($quotation))->render();

    expect($html)->toContain($quotation->quotation_number)
        ->and($html)->toContain('Quezon City, Metro Manila')
        ->and($html)->toContain('Makati City, Metro Manila')
        ->and($html)->toContain($truckType->name)
        ->and($html)->toContain('15.00')
        ->and($html)->toContain('2,500.00')
        ->and($html)->toContain('VAT (12%)')
        ->and($html)->toContain('Additional fees')
        ->and($html)->toContain('Tight access requires a smaller flatbed')
        ->and($html)->toContain('4,478.40')
        ->and($html)->toContain($quotation->expires_at->format('M d, Y'))
        ->and($html)->toContain('Open the TowMate app to review and accept this quotation.')
        ->and($html)->not->toContain('support@towmate.com')
        ->and($html)->not->toContain('@towmate.com');
});

it('shows a negative adjustment as a discount instead of hiding it from the total', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType, [
        'dispatcher_note' => 'Loyalty discount applied',
    ]);
    $quotation = qseQuotation($booking, -300, 3520);

    $html = (new QuotationSentMail($quotation))->render();

    expect($html)->toContain('Discount')
        ->and($html)->toContain('300.00')
        ->and($html)->toContain('Loyalty discount applied')
        ->and($html)->not->toContain('Additional fees');
});

it('omits the adjustment row entirely when there is no additional fee or discount', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType);
    $quotation = qseQuotation($booking, 0, 3820);

    $html = (new QuotationSentMail($quotation))->render();

    expect($html)->not->toContain('Additional fees')
        ->and($html)->not->toContain('Discount');
});

it('uses a fluid mobile-safe width for the email card', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType);
    $quotation = qseQuotation($booking, 0, 3820);

    $html = (new QuotationSentMail($quotation))->render();

    expect($html)->toContain('name="viewport"')
        ->and($html)->toContain('max-width:480px');
});

it('embeds the two top logos as inline CID attachments instead of base64 data URIs', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType);
    $quotation = qseQuotation($booking, 0, 3820);

    $mailer = Mail::mailer('array');
    $mailer->to($customer->email)->send(new QuotationSentMail($quotation));

    $sent = $mailer->getSymfonyTransport()->messages()->last();
    $email = $sent->getOriginalMessage();

    expect($email->getHtmlBody())
        ->not->toContain('data:image/png;base64,')
        ->toContain('cid:');

    $inlineImages = collect($email->getAttachments())
        ->filter(fn ($part) => $part->getMediaType() === 'image');

    expect($inlineImages)->toHaveCount(2);
});

it('renders without failing when a top logo source file is temporarily missing', function () {
    $customer = qseCustomer();
    $truckType = qseTruckType();
    $booking = qseBooking($customer, $truckType);
    $quotation = qseQuotation($booking, 0, 3820);

    $logoPath = public_path('customer/image/TowingLogo-email.png');
    $movedPath = $logoPath . '.test-backup';

    expect(file_exists($logoPath))->toBeTrue();

    rename($logoPath, $movedPath);

    try {
        $html = (new QuotationSentMail($quotation))->render();
    } finally {
        rename($movedPath, $logoPath);
    }

    expect($html)->toContain('MMDA Accredited')
        ->and($html)->not->toContain('alt="Jarz Towing"');
});
