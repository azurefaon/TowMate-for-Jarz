<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\PriceAdjustment;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function pahDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function pahCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'PAH Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'pah-' . uniqid() . '@example.com',
    ]);
}

function pahTruckType(float $baseRate, float $perKmRate): TruckType
{
    return TruckType::create([
        'name' => 'PAH Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function pahBooking(Customer $customer, TruckType $truckType, string $groupCode, float $distanceKm = 12.0): Booking
{
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => $distanceKm,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'status' => 'scheduled',
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    return $booking->fresh(['customer', 'truckType']);
}

function pahDraft(User $dispatcher, Booking $booking, float $price, array $extra = [])
{
    return test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $booking), array_merge([
        'price' => (string) $price,
        'distance_km' => (string) $booking->distance_km,
    ], $extra));
}

it('saves multiple staged adjustments as separate database records, never merged', function () {
    $dispatcher = pahDispatcher();
    $customer = pahCustomer();
    $truck = pahTruckType(1500, 60);
    $booking = pahBooking($customer, $truck, 'PAH-1', 12.0);

    $subtotal = 1500 + (12.0 - 4.0) * 60;
    $serviceTotal = round($subtotal * 1.12, 2);

    pahDraft($dispatcher, $booking, round($serviceTotal + 300 - 100, 2), [
        'adjustments' => [
            ['type' => 'add', 'amount' => 300, 'reason' => 'Extra crew'],
            ['type' => 'deduct', 'amount' => 100, 'reason' => 'Loyalty discount'],
        ],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();

    expect(PriceAdjustment::forQuotation($quotation->quotation_number)->count())->toBe(2);

    $add = PriceAdjustment::forQuotation($quotation->quotation_number)->where('type', 'add')->first();
    $deduct = PriceAdjustment::forQuotation($quotation->quotation_number)->where('type', 'deduct')->first();

    expect((float) $add->amount)->toBe(300.0)
        ->and($add->reason)->toBe('Extra crew')
        ->and($add->status)->toBe('active')
        ->and((float) $deduct->amount)->toBe(100.0)
        ->and($deduct->reason)->toBe('Loyalty discount')
        ->and($deduct->status)->toBe('active');

    expect((float) $quotation->additional_fee)->toBe(200.0);
    expect((float) $quotation->estimated_price)->toBe(round($serviceTotal + 200, 2));
});

it('computes the net adjustment from active records only and reconciles with the quotation total', function () {
    $dispatcher = pahDispatcher();
    $customer = pahCustomer();
    $truck = pahTruckType(1500, 60);
    $booking = pahBooking($customer, $truck, 'PAH-2', 12.0);

    $subtotal = 1500 + (12.0 - 4.0) * 60;
    $serviceTotal = round($subtotal * 1.12, 2);

    pahDraft($dispatcher, $booking, round($serviceTotal + 500 - 200 + 150, 2), [
        'adjustments' => [
            ['type' => 'add', 'amount' => 500, 'reason' => 'A'],
            ['type' => 'deduct', 'amount' => 200, 'reason' => 'B'],
            ['type' => 'add', 'amount' => 150, 'reason' => 'C'],
        ],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    $quotationService = app(\App\Services\QuotationService::class);

    expect($quotationService->netActiveAdjustment($quotation->quotation_number))->toBe(450.0);
    expect((float) $quotation->additional_fee)->toBe(450.0);
    expect((float) $quotation->estimated_price)->toBe(round($serviceTotal + 450, 2));
});

it('supports multiple additions and deductions for a grouped quotation and reconciles the total', function () {
    Mail::fake();
    $dispatcher = pahDispatcher();
    $customer = pahCustomer();
    $truckA = pahTruckType(1500, 60);
    $truckB = pahTruckType(900, 40);
    $groupCode = 'PAH-GRP-1';

    $bookingA = pahBooking($customer, $truckA, $groupCode, 12.0);
    $bookingB = pahBooking($customer, $truckB, $groupCode, 12.0);

    pahDraft($dispatcher, $bookingA, 1900)->assertOk();
    pahDraft($dispatcher, $bookingB, 1100, [
        'adjustments' => [
            ['type' => 'add', 'amount' => 400, 'reason' => 'Extra fee'],
            ['type' => 'deduct', 'amount' => 150, 'reason' => 'Group discount'],
        ],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();

    expect(PriceAdjustment::forQuotation($quotation->quotation_number)->count())->toBe(2);
    expect((float) $quotation->additional_fee)->toBe(250.0);

    $expectedA = round(1500 + (12.0 - 4.0) * 60, 2);
    $expectedAWithVat = round($expectedA * 1.12, 2);
    $expectedB = round(900 + (12.0 - 4.0) * 40, 2);
    $expectedBWithVat = round($expectedB * 1.12, 2);
    $groupServiceTotal = round($expectedAWithVat + $expectedBWithVat, 2);

    expect((float) $quotation->estimated_price)->toBe(round($groupServiceTotal + 250, 2));
});

it('undoes an active adjustment, marks it reverted without deleting it, and recalculates the persisted total', function () {
    $dispatcher = pahDispatcher();
    $customer = pahCustomer();
    $truck = pahTruckType(1500, 60);
    $booking = pahBooking($customer, $truck, 'PAH-3', 12.0);

    $subtotal = 1500 + (12.0 - 4.0) * 60;
    $serviceTotal = round($subtotal * 1.12, 2);

    pahDraft($dispatcher, $booking, round($serviceTotal + 500, 2), [
        'adjustments' => [['type' => 'add', 'amount' => 500, 'reason' => 'Extra']],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    $adjustment = PriceAdjustment::forQuotation($quotation->quotation_number)->first();

    test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjustments.undo', [
        'quotation' => $quotation->id,
        'adjustment' => $adjustment->id,
    ]))->assertOk();

    $adjustment->refresh();
    expect($adjustment->status)->toBe('reverted');
    expect($adjustment->reverted_at)->not->toBeNull();
    expect($adjustment->reverted_by)->toBe($dispatcher->id);
    expect((float) $adjustment->amount)->toBe(500.0);
    expect($adjustment->reason)->toBe('Extra');
    expect(PriceAdjustment::find($adjustment->id))->not->toBeNull();

    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect((float) $quotation->additional_fee)->toBe(0.0);
    expect((float) $quotation->estimated_price)->toBe($serviceTotal);
});

it('excludes reverted adjustments from Price History active net calculation but keeps them recorded', function () {
    $dispatcher = pahDispatcher();
    $customer = pahCustomer();
    $truck = pahTruckType(1500, 60);
    $booking = pahBooking($customer, $truck, 'PAH-4', 12.0);

    pahDraft($dispatcher, $booking, 5000, [
        'adjustments' => [
            ['type' => 'add', 'amount' => 300, 'reason' => 'Keep me'],
            ['type' => 'add', 'amount' => 500, 'reason' => 'Undo me'],
        ],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    $toRevert = PriceAdjustment::forQuotation($quotation->quotation_number)->where('reason', 'Undo me')->first();

    test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjustments.undo', [
        'quotation' => $quotation->id,
        'adjustment' => $toRevert->id,
    ]))->assertOk();

    $quotationService = app(\App\Services\QuotationService::class);
    expect($quotationService->netActiveAdjustment($quotation->quotation_number))->toBe(300.0);
    expect(PriceAdjustment::forQuotation($quotation->quotation_number)->count())->toBe(2);
    expect(PriceAdjustment::forQuotation($quotation->quotation_number)->active()->count())->toBe(1);
});

it('rejects a duplicate Undo request on the same adjustment', function () {
    $dispatcher = pahDispatcher();
    $customer = pahCustomer();
    $truck = pahTruckType(1500, 60);
    $booking = pahBooking($customer, $truck, 'PAH-5', 12.0);

    pahDraft($dispatcher, $booking, 5000, [
        'adjustments' => [['type' => 'add', 'amount' => 500, 'reason' => 'Extra']],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    $adjustment = PriceAdjustment::forQuotation($quotation->quotation_number)->first();

    test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjustments.undo', [
        'quotation' => $quotation->id,
        'adjustment' => $adjustment->id,
    ]))->assertOk();

    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    $second = test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjustments.undo', [
        'quotation' => $quotation->id,
        'adjustment' => $adjustment->id,
    ]));

    $second->assertStatus(422);
    expect($adjustment->fresh()->status)->toBe('reverted');
});

it('prevents undoing an adjustment on an accepted, price-locked quotation', function () {
    Mail::fake();
    $dispatcher = pahDispatcher();
    $customer = pahCustomer();
    $truck = pahTruckType(1500, 60);
    $booking = pahBooking($customer, $truck, 'PAH-6', 12.0);

    pahDraft($dispatcher, $booking, 5000, [
        'adjustments' => [['type' => 'add', 'amount' => 500, 'reason' => 'Extra']],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    $adjustment = PriceAdjustment::forQuotation($quotation->quotation_number)->first();

    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    app(\App\Services\QuotationService::class)->acceptQuotation($quotation);
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect($quotation->status)->toBe('accepted');

    $response = test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjustments.undo', [
        'quotation' => $quotation->id,
        'adjustment' => $adjustment->id,
    ]));

    $response->assertStatus(422);
    expect($adjustment->fresh()->status)->toBe('active');
});

it('bumps the quotation version when undoing an adjustment on an already-sent quotation', function () {
    $dispatcher = pahDispatcher();
    $customer = pahCustomer();
    $truck = pahTruckType(1500, 60);
    $booking = pahBooking($customer, $truck, 'PAH-7', 12.0);

    pahDraft($dispatcher, $booking, 5000, [
        'adjustments' => [['type' => 'add', 'amount' => 500, 'reason' => 'Extra']],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    $adjustment = PriceAdjustment::forQuotation($quotation->quotation_number)->first();
    $originalVersion = $quotation->version;
    $originalId = $quotation->id;

    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjustments.undo', [
        'quotation' => $quotation->id,
        'adjustment' => $adjustment->id,
    ]))->assertOk();

    $current = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect($current->version)->toBeGreaterThan($originalVersion);
    expect($current->id)->not->toBe($originalId);
    expect($current->is_current)->toBeTrue();
    expect(Quotation::find($originalId)->is_current)->toBeFalse();
    expect((float) $current->additional_fee)->toBe(0.0);
});

it('preserves the quotation saved VAT rate when an adjustment is undone', function () {
    $dispatcher = pahDispatcher();
    $customer = pahCustomer();
    $truck = pahTruckType(1500, 60);
    $booking = pahBooking($customer, $truck, 'PAH-8', 12.0);

    pahDraft($dispatcher, $booking, 5000, [
        'adjustments' => [['type' => 'add', 'amount' => 500, 'reason' => 'Extra']],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    $quotation->update(['vat_rate' => 0.15]);
    $adjustment = PriceAdjustment::forQuotation($quotation->quotation_number)->first();

    \App\Models\SystemSetting::setValue('vat_rate_percentage', 20);

    test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjustments.undo', [
        'quotation' => $quotation->id,
        'adjustment' => $adjustment->id,
    ]))->assertOk();

    $current = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect((float) $current->vat_rate)->toBe(0.15);

    \App\Models\SystemSetting::setValue('vat_rate_percentage', 12);
});

it('does not fabricate individual adjustment records for a legacy quotation with a pre-existing net additional_fee', function () {
    $customer = pahCustomer();
    $truck = pahTruckType(1500, 60);
    $booking = pahBooking($customer, $truck, 'PAH-9', 12.0);

    $quotation = Quotation::create([
        'quotation_number' => 'QT-LEGACY-0001',
        'source_booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12.0,
        'estimated_price' => 3000,
        'additional_fee' => 700,
        'discount' => 0,
        'vat_rate' => 0.12,
        'service_type' => 'schedule',
        'status' => 'sent',
        'sent_at' => now(),
        'expires_at' => now()->addDays(3),
        'is_current' => true,
    ]);

    expect(PriceAdjustment::forQuotation($quotation->quotation_number)->count())->toBe(0);
    expect((float) $quotation->additional_fee)->toBe(700.0);
});

it('separates Price History adjustment records from Quote History lifecycle entries in the quotation details response', function () {
    Mail::fake();
    $dispatcher = pahDispatcher();
    $customer = pahCustomer();
    $truck = pahTruckType(1500, 60);
    $booking = pahBooking($customer, $truck, 'PAH-10', 12.0);

    pahDraft($dispatcher, $booking, 5000, [
        'adjustments' => [['type' => 'add', 'amount' => 500, 'reason' => 'Extra']],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    $response = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation));
    $response->assertOk();

    $data = $response->json('quotation');

    expect($data['price_adjustments'])->toHaveCount(1);
    expect($data['price_adjustments'][0]['type'])->toBe('add');
    expect((float) $data['price_adjustments'][0]['amount'])->toBe(500.0);
    expect($data['price_adjustments'][0]['reason'])->toBe('Extra');
    expect($data['price_adjustments'][0]['status'])->toBe('active');

    $sentEntries = collect($data['price_change_log'])->where('type', 'quotation_sent');
    expect($sentEntries)->not->toBeEmpty();
});
