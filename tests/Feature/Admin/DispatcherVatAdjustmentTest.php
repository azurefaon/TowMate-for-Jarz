<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

function dvatRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function dvatDispatcher(): User
{
    dvatRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function dvatTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'VAT Test Truck ' . fake()->unique()->word(),
        'base_rate' => 2500,
        'per_km_rate' => 120,
    ]);
}

function dvatCustomer(): Customer
{
    $user = User::factory()->create(['role_id' => dvatRole(5, 'Customer')->id]);

    return Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);
}

function dvatReadyUnit(TruckType $truckType): Unit
{
    $teamLeaderRole = dvatRole(3, 'Team Leader');
    $teamLeader = User::factory()->create(['role_id' => $teamLeaderRole->id, 'must_change_password' => false]);
    \Illuminate\Support\Facades\Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => 'VAT Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);
}

function dvatBooking(string $serviceType = 'book_now', array $overrides = []): Booking
{
    $truckType = dvatTruckType();
    $customer = dvatCustomer();

    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup Point',
        'dropoff_address' => 'Dropoff Point',
        'distance_km' => 15,
        'base_rate' => 2500,
        'per_km_rate' => 120,
        'computed_total' => 3820,
        'discount_percentage' => 0,
        'final_total' => 3820,
        'status' => 'requested',
        'service_type' => $serviceType,
    ], $overrides));

    $booking->update(['booking_code' => 'TM-VAT' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh(['customer', 'truckType']);
}

function dvatSentQuotation(Booking $booking, float $additionalFee, float $estimatedPrice): Quotation
{
    return Quotation::create([
        'quotation_number' => 'Q-VAT-' . uniqid(),
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
        'is_current' => true,
    ]);
}

// TEST 1 — Base calculation: Base 2500, Distance 1320, Subtotal 3820, VAT 458.40, Base Total 4278.40
it('computes base rate + distance fee into a subtotal with 12% VAT and no adjustment', function () {
    $booking = dvatBooking();

    $totals = app(BookingService::class)->calculateQuotationTotals($booking);

    expect($totals['computed_total'])->toBe(3820.0)
        ->and($totals['subtotal'])->toBe(3820.0)
        ->and($totals['vat_amount'])->toBe(458.4)
        ->and($totals['base_total'])->toBe(4278.4)
        ->and($totals['final_total'])->toBe(4278.4);
});

// TEST 2 — +500 adjustment: Expected VAT 458.40, Expected Final 4778.40 (NOT 4838.40)
it('applies a positive manual adjustment after VAT without recalculating VAT on it', function () {
    $booking = dvatBooking();

    $totals = app(BookingService::class)->calculateQuotationTotals($booking, '500');

    expect($totals['vat_amount'])->toBe(458.4)
        ->and($totals['final_total'])->toBe(4778.4)
        ->and($totals['final_total'])->not->toBe(4838.4);
});

// TEST 3 — -500 adjustment: Expected VAT 458.40, Expected Final 3778.40
it('applies a negative manual adjustment (discount) after VAT without touching VAT', function () {
    $booking = dvatBooking();

    $totals = app(BookingService::class)->calculateQuotationTotals($booking, '-500');

    expect($totals['vat_amount'])->toBe(458.4)
        ->and($totals['final_total'])->toBe(3778.4);
});

// TEST 4 — adjustment changed +500 -> +700: VAT remains 458.40, Final 4978.40
it('keeps VAT fixed when a manual adjustment is revised from +500 to +700', function () {
    $booking = dvatBooking();

    $first = app(BookingService::class)->calculateQuotationTotals($booking, '500');
    $second = app(BookingService::class)->calculateQuotationTotals($booking, '700');

    expect($first['vat_amount'])->toBe(458.4)
        ->and($second['vat_amount'])->toBe(458.4)
        ->and($first['final_total'])->toBe(4778.4)
        ->and($second['final_total'])->toBe(4978.4);
});

// TEST 5 — Book Now: follows the exact canonical rule end-to-end through the real dispatch endpoint
it('sends a Book Now quotation with a +500 adjustment applied after VAT', function () {
    Mail::fake();

    $booking = dvatBooking('book_now');
    $unit = dvatReadyUnit($booking->truckType);
    $dispatcher = dvatDispatcher();

    $response = test()->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '15',
        'distance_fee' => '1320',
        'discount_percentage' => '0',
        'additional_fee' => '500',
        'dispatcher_note' => 'Recovery winching required.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();

    expect((float) $booking->computed_total)->toBe(3820.0)
        ->and((float) $booking->vat_exclusive_total)->toBe(3820.0)
        ->and((float) $booking->vat_amount)->toBe(458.4)
        ->and((float) $booking->final_total)->toBe(4778.4);
});

// TEST 6 — Scheduled: Book Now and Scheduled must not produce different totals from the same inputs
it('sends a Scheduled quotation with the exact same VAT rule as Book Now', function () {
    Mail::fake();

    $booking = dvatBooking('schedule');
    $unit = dvatReadyUnit($booking->truckType);
    $dispatcher = dvatDispatcher();

    $response = test()->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '15',
        'distance_fee' => '1320',
        'discount_percentage' => '0',
        'additional_fee' => '500',
        'dispatcher_note' => 'Recovery winching required.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();

    expect((float) $booking->vat_exclusive_total)->toBe(3820.0)
        ->and((float) $booking->vat_amount)->toBe(458.4)
        ->and((float) $booking->final_total)->toBe(4778.4);
});

// TEST 7 — price review revision: no VAT compounding, no recompute from the prior final total
it('does not compound VAT when a price-review adjustment revises the quotation', function () {
    Mail::fake();

    $booking = dvatBooking('book_now', [
        'additional_fee' => 500,
        'final_total' => 4778.4,
        'vat_amount' => 458.4,
        'vat_exclusive_total' => 3820,
    ]);
    $quotation = dvatSentQuotation($booking, 500, 4778.4);
    $quotation->update(['status' => 'price_review_requested']);
    $dispatcher = dvatDispatcher();

    $response = test()->actingAs($dispatcher)->post(route('admin.quotations.adjust-price', $quotation), [
        'new_price' => 4978.4,
        'additional_fee' => 700,
        'note' => 'Customer requested a second review; extra fee confirmed.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();

    expect((float) $booking->vat_amount)->toBe(458.4)
        ->and((float) $booking->vat_exclusive_total)->toBe(3820.0)
        ->and((float) $booking->final_total)->toBe(4978.4);
});

// TEST 8 — server tampering: a fabricated final total must not corrupt the stored VAT breakdown
it('keeps the stored VAT breakdown canonical even when the submitted new_price is fabricated', function () {
    Mail::fake();

    $booking = dvatBooking('book_now', [
        'final_total' => 3820,
        'vat_amount' => 0,
        'vat_exclusive_total' => 0,
    ]);
    $quotation = dvatSentQuotation($booking, 0, 3820);
    $dispatcher = dvatDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 999999,
    ]);

    $response->assertOk();

    $booking->refresh();

    expect((float) $booking->vat_exclusive_total)->toBe(3820.0)
        ->and((float) $booking->vat_amount)->toBe(458.4)
        ->and((float) $booking->final_total)->not->toBe(458.4 * 999999);
});

// TEST 9 — customer quotation: sees the same VAT figure the dispatcher panel does
it('shows the customer the same canonical VAT amount as the dispatcher', function () {
    $booking = dvatBooking('book_now', [
        'additional_fee' => 500,
        'final_total' => 4778.4,
        'vat_amount' => 458.4,
        'vat_exclusive_total' => 3820,
    ]);
    $customerUser = User::find($booking->customer->user_id);
    $quotation = dvatSentQuotation($booking, 500, 4778.4);

    Sanctum::actingAs($customerUser, ['*']);

    $response = test()->getJson('/api/v1/quotations/pending');

    $response->assertOk();
    expect((float) $response->json('data.vat_amount'))->toBe(458.4)
        ->and((float) $response->json('data.estimated_price'))->toBe(4778.4);
});

// TEST 10 — PDF: the quotation document renders the same final total, no independent recalculation
it('renders the quotation PDF view with the same final total as the booking', function () {
    $booking = dvatBooking('book_now', [
        'additional_fee' => 500,
        'final_total' => 4778.4,
        'vat_amount' => 458.4,
        'vat_exclusive_total' => 3820,
    ]);
    $booking->loadMissing(['customer', 'truckType']);

    $html = view('documents.quotation', [
        'booking' => $booking,
        'settings' => app(\App\Services\DocumentGenerationService::class)->documentSettings(),
        'isFinal' => false,
        'generatedAt' => now(),
    ])->render();

    expect($html)->toContain('4,778.40');
});

// TEST 11 — accepted quotation history: a later revision must not rewrite the superseded version's own record
it('does not rewrite a superseded quotation version price when a later revision is created', function () {
    Mail::fake();

    $booking = dvatBooking('book_now', [
        'additional_fee' => 500,
        'final_total' => 4778.4,
        'vat_amount' => 458.4,
        'vat_exclusive_total' => 3820,
    ]);
    $quotationV1 = dvatSentQuotation($booking, 500, 4778.4);
    $quotationV1->update(['status' => 'price_review_requested']);
    $dispatcher = dvatDispatcher();

    $response = test()->actingAs($dispatcher)->post(route('admin.quotations.adjust-price', $quotationV1), [
        'new_price' => 4978.4,
        'additional_fee' => 700,
        'note' => 'Second review adjustment.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $staleV1 = Quotation::find($quotationV1->id);

    expect((float) $staleV1->estimated_price)->toBe(4778.4)
        ->and($staleV1->is_current)->toBeFalse();

    $currentVersion = Quotation::where('quotation_number', $quotationV1->quotation_number)
        ->where('is_current', true)
        ->first();

    expect((float) $currentVersion->estimated_price)->toBe(4978.4);
});

// TEST 12 — multiple/net adjustments: VAT remains fixed to the original taxable subtotal regardless of the net amount
it('keeps VAT fixed across a sequence of net adjustments (+500, +700 net, +600 net)', function () {
    $booking = dvatBooking();
    $service = app(BookingService::class);

    $step1 = $service->calculateQuotationTotals($booking, '500');
    $step2 = $service->calculateQuotationTotals($booking, '700');
    $step3 = $service->calculateQuotationTotals($booking, '600');

    expect($step1['vat_amount'])->toBe(458.4)
        ->and($step2['vat_amount'])->toBe(458.4)
        ->and($step3['vat_amount'])->toBe(458.4)
        ->and($step1['final_total'])->toBe(4778.4)
        ->and($step2['final_total'])->toBe(4978.4)
        ->and($step3['final_total'])->toBe(4878.4);
});
