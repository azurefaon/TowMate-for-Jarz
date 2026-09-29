<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\PriceAdjustment;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Services\BookingService;
use Laravel\Sanctum\Sanctum;

/**
 * Regression coverage: customer-facing Price History must only contain
 * quotation prices that were actually sent (QuotationService::
 * sentPriceHistory(), keyed off the existing `quotation_sent` marker
 * contract) — never raw internal draft/adjustment deltas.
 */
function cphDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
}

function cphCustomerUser(): array
{
    $role = Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });
    $user = User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return [$user, $customer];
}

function cphTruckType(float $baseRate): TruckType
{
    return TruckType::create([
        'name' => 'CPH Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

/** distance_km pinned at 4 (the free-km threshold) so distance_fee is 0 — makes the resulting estimated_price fully predictable from additional_fee deltas alone. */
function cphBooking(Customer $customer, TruckType $truckType): Booking
{
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'CPH Pickup',
        'dropoff_address' => 'CPH Dropoff',
        'distance_km' => 4,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        // Deliberately NOT 'quoted'/'pending'/'reviewed' — Booking::booted()'s
        // updated() listener calls QuotationService::updateQuotation() for
        // those statuses, a pre-existing method that does not currently
        // exist on QuotationService (unrelated to this task; reported
        // separately rather than fixed here).
        'status' => 'requested',
        'service_type' => 'book_now',
    ]);

    return $booking->fresh(['customer', 'truckType']);
}

function cphSaveDraft(User $dispatcher, Booking $booking, float $additionalFeeDelta)
{
    return test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $booking), [
        'price' => '1',
        'distance_km' => (string) $booking->distance_km,
        'additional_fee' => (string) $additionalFeeDelta,
        'dispatcher_note' => $additionalFeeDelta != 0.0 ? 'Internal working note' : null,
    ]);
}

/**
 * Drives Save Draft through four unsent edits (5000 -> 5300 -> 5500 -> 5200)
 * via real additional_fee deltas relative to the truck's own service total,
 * so the resulting estimated_price sequence is exact regardless of VAT
 * rounding, then sends. Returns the current (sent) Quotation.
 */
function cphFirstCycleSentAt5200(User $dispatcher, Booking $booking): Quotation
{
    $vatRate = app(BookingService::class)->vatRate();
    $serviceTotal = round((float) $booking->base_rate * (1 + $vatRate), 2);

    cphSaveDraft($dispatcher, $booking, round(5000 - $serviceTotal, 2))->assertOk();
    cphSaveDraft($dispatcher, $booking, 300)->assertOk();
    cphSaveDraft($dispatcher, $booking, 200)->assertOk();
    cphSaveDraft($dispatcher, $booking, -300)->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    expect((float) $quotation->estimated_price)->toBe(5200.0)
        ->and($quotation->status)->toBe('draft');

    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();

    return Quotation::where('source_booking_id', $booking->id)->current()->first();
}

it('1: multiple unsent Save Draft edits before the first send — customer sees only the final sent price', function () {
    $dispatcher = cphDispatcher();
    [$user, $customer] = cphCustomerUser();
    $truckType = cphTruckType(1000);
    $booking = cphBooking($customer, $truckType);

    $quotation = cphFirstCycleSentAt5200($dispatcher, $booking);
    expect((float) $quotation->estimated_price)->toBe(5200.0)
        ->and($quotation->status)->toBe('sent')
        ->and($quotation->sent_at)->not->toBeNull();

    Sanctum::actingAs($user, ['*']);

    $pending = test()->getJson('/api/v1/quotations/pending');
    $pending->assertOk();
    // toEqual, not toBe: JSON round-tripping can decode a whole-number
    // float (5200.0) back as a PHP int (5200) — the API contract cares
    // about numeric value, not int/float type.
    expect($pending->json('data.price_history'))->toEqual([
        ['version' => 1, 'price' => 5200.0, 'sent_at' => $quotation->sent_at->toIso8601String()],
    ]);

    $detail = test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail");
    $detail->assertOk();
    expect($detail->json('data.price_history'))->toEqual([
        ['version' => 1, 'price' => 5200.0, 'sent_at' => $quotation->sent_at->toIso8601String()],
    ]);
});

it('2+3: a second adjustment cycle with multiple internal tries — customer sees exactly two sent prices, nothing else', function () {
    $dispatcher = cphDispatcher();
    [$user, $customer] = cphCustomerUser();
    $truckType = cphTruckType(1000);
    $booking = cphBooking($customer, $truckType);

    $quotation = cphFirstCycleSentAt5200($dispatcher, $booking);

    // The dispatcher's internal tries at 5400 and 5600 are local/unsaved UI
    // state that never reach the backend (updateQuotationPrice() always
    // sends immediately — there is no "stage without sending" step once a
    // quotation has already been sent, per the audit). Only the final
    // submitted price (5500) is ever persisted or emailed.
    test()->actingAs($dispatcher)->patchJson(route('admin.quotations.update-price', $quotation), [
        'new_price' => '5500',
    ])->assertOk();

    $current = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect((int) $current->version)->toBe(2)
        ->and((float) $current->estimated_price)->toBe(5500.0)
        ->and($current->status)->toBe('sent');

    Sanctum::actingAs($user, ['*']);

    $pending = test()->getJson('/api/v1/quotations/pending');
    $pending->assertOk();
    $history = $pending->json('data.price_history');
    expect($history)->toHaveCount(2)
        ->and($history[0])->toEqual(['version' => 1, 'price' => 5200.0, 'sent_at' => $quotation->sent_at->toIso8601String()])
        ->and($history[1]['version'])->toBe(2)
        ->and((float) $history[1]['price'])->toBe(5500.0);

    $detail = test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail");
    $detail->assertOk();
    expect($detail->json('data.price_history'))->toHaveCount(2);
});

it('4: updateQuotationPrice() records a quotation_sent marker with a fresh sent_at when it actually sends', function () {
    $dispatcher = cphDispatcher();
    [, $customer] = cphCustomerUser();
    $truckType = cphTruckType(1000);
    $booking = cphBooking($customer, $truckType);

    $quotation = cphFirstCycleSentAt5200($dispatcher, $booking);
    $firstSentAt = $quotation->sent_at;

    test()->actingAs($dispatcher)->patchJson(route('admin.quotations.update-price', $quotation), [
        'new_price' => '5500',
    ])->assertOk();

    $current = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    $markers = collect($current->price_change_log)->where('type', 'quotation_sent');

    // sent_at columns are timestamp(0) (whole-second precision) and both
    // sends happen within the same test-execution second, so the two
    // timestamps can legitimately be equal — greaterThanOrEqualTo (never
    // regresses/goes stale) plus the marker's own distinct version number
    // is the real proof this wasn't copied forward from version 1.
    expect($markers->pluck('version')->all())->toBe([1, 2])
        ->and($current->sent_at)->not->toBeNull()
        ->and($current->sent_at->greaterThanOrEqualTo($firstSentAt))->toBeTrue();
});

it('3: raw draft/internal delta entries never appear in the customer response shape', function () {
    $dispatcher = cphDispatcher();
    [$user, $customer] = cphCustomerUser();
    $truckType = cphTruckType(1000);
    $booking = cphBooking($customer, $truckType);

    $quotation = cphFirstCycleSentAt5200($dispatcher, $booking);
    // The raw log has 5 entries (4 draft deltas + 1 sent marker) — proves
    // the leak surface exists — but the customer response must reduce that
    // to exactly 1 clean {version, price, sent_at} entry.
    expect(count($quotation->price_change_log))->toBeGreaterThan(1);

    Sanctum::actingAs($user, ['*']);
    $pending = test()->getJson('/api/v1/quotations/pending');

    $entry = $pending->json('data.price_history.0');
    expect(array_keys($entry))->toEqualCanonicalizing(['version', 'price', 'sent_at']);
});

it('10: the customer API never returns a fake ₱0 -> ₱0 marker entry', function () {
    $dispatcher = cphDispatcher();
    [$user, $customer] = cphCustomerUser();
    $truckType = cphTruckType(1000);
    $booking = cphBooking($customer, $truckType);

    cphFirstCycleSentAt5200($dispatcher, $booking);

    Sanctum::actingAs($user, ['*']);
    $pending = test()->getJson('/api/v1/quotations/pending');

    foreach ($pending->json('data.price_history') as $entry) {
        expect($entry['price'])->not->toBe(0.0);
        expect($entry)->not->toHaveKeys(['old', 'new']);
    }
});

it('5: an accepted quotation retains its sent price history on the booking detail endpoint', function () {
    $dispatcher = cphDispatcher();
    [$user, $customer] = cphCustomerUser();
    $truckType = cphTruckType(1000);
    $booking = cphBooking($customer, $truckType);

    $quotation = cphFirstCycleSentAt5200($dispatcher, $booking);
    $quotation->update(['status' => 'accepted']);
    $booking->update(['status' => 'confirmed', 'quotation_id' => $quotation->id]);

    Sanctum::actingAs($user, ['*']);
    $detail = test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail");
    $detail->assertOk();
    expect($detail->json('data.price_history'))->toHaveCount(1)
        ->and((float) $detail->json('data.price_history.0.price'))->toBe(5200.0);
});

it('6: a rejected quotation retains its sent price history on the booking detail endpoint', function () {
    $dispatcher = cphDispatcher();
    [$user, $customer] = cphCustomerUser();
    $truckType = cphTruckType(1000);
    $booking = cphBooking($customer, $truckType);

    $quotation = cphFirstCycleSentAt5200($dispatcher, $booking);
    $quotation->update(['status' => 'rejected']);
    $booking->update(['status' => 'cancelled']);

    Sanctum::actingAs($user, ['*']);
    $detail = test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail");
    $detail->assertOk();
    expect($detail->json('data.price_history'))->toHaveCount(1)
        ->and((float) $detail->json('data.price_history.0.price'))->toBe(5200.0);
});

it('7: an expired quotation retains its sent price history on the booking detail endpoint', function () {
    $dispatcher = cphDispatcher();
    [$user, $customer] = cphCustomerUser();
    $truckType = cphTruckType(1000);
    $booking = cphBooking($customer, $truckType);

    $quotation = cphFirstCycleSentAt5200($dispatcher, $booking);
    $quotation->update(['status' => 'expired']);

    Sanctum::actingAs($user, ['*']);
    $detail = test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail");
    $detail->assertOk();
    expect($detail->json('data.price_history'))->toHaveCount(1)
        ->and((float) $detail->json('data.price_history.0.price'))->toBe(5200.0);
});

it('8: a grouped quotation follows the same sent-only rule', function () {
    [$user, $customer] = cphCustomerUser();
    $truckType = cphTruckType(1000);
    $groupCode = 'CPH-GRP-' . uniqid();

    $anchor = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'CPH Pickup',
        'dropoff_address' => 'CPH Dropoff',
        'distance_km' => 4,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'status' => 'confirmed',
        'service_type' => 'book_now',
    ]);
    $sibling = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'CPH Pickup',
        'dropoff_address' => 'CPH Dropoff',
        'distance_km' => 4,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'status' => 'confirmed',
        'service_type' => 'book_now',
    ]);

    // Version 1: an unsent draft-only price (must not leak), then version 2
    // is the genuinely sent one, matching the same quotation_sent-marker
    // contract real sendQuotation() writes — grouped quotations use the
    // identical price_change_log/version mechanism, no special-casing.
    $quotation = Quotation::create([
        'quotation_number' => 'QT-CPH-' . uniqid(),
        'source_booking_id' => $anchor->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $anchor->pickup_address,
        'dropoff_address' => $anchor->dropoff_address,
        'distance_km' => 4,
        'estimated_price' => 9000,
        'status' => 'draft',
        'version' => 1,
        'is_current' => true,
        'extra_vehicles' => [['booking_id' => $sibling->id, 'truck_type_id' => $truckType->id]],
    ]);
    $quotation->update(['is_current' => false]);
    $sent = Quotation::create([
        'quotation_number' => $quotation->quotation_number,
        'source_booking_id' => $anchor->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $anchor->pickup_address,
        'dropoff_address' => $anchor->dropoff_address,
        'distance_km' => 4,
        'estimated_price' => 9500,
        'status' => 'sent',
        'sent_at' => now(),
        'version' => 2,
        'is_current' => true,
        'extra_vehicles' => [['booking_id' => $sibling->id, 'truck_type_id' => $truckType->id]],
        'price_change_log' => [
            ['at' => now()->toISOString(), 'old' => 9000, 'new' => 9500, 'reason' => 'internal recompute'],
            ['at' => now()->toISOString(), 'type' => 'quotation_sent', 'version' => 2],
        ],
    ]);
    $anchor->update(['quotation_id' => $sent->id]);

    Sanctum::actingAs($user, ['*']);
    $detail = test()->getJson("/api/v1/bookings/{$anchor->booking_code}/detail");
    $detail->assertOk();
    expect($detail->json('data.price_history'))->toEqual([
        ['version' => 2, 'price' => 9500.0, 'sent_at' => $sent->sent_at->toIso8601String()],
    ]);
});

it('9: dispatcher-facing PriceAdjustment/Undo/raw-log history remains completely unchanged', function () {
    $dispatcher = cphDispatcher();
    [, $customer] = cphCustomerUser();
    $truckType = cphTruckType(1000);
    $booking = cphBooking($customer, $truckType);

    cphSaveDraft($dispatcher, $booking, 300)->assertOk();
    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();

    expect(PriceAdjustment::forQuotation($quotation->quotation_number)->count())->toBe(1);

    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();

    // Dispatcher-facing endpoint must still expose the raw, unfiltered log —
    // this task only changes the two customer-facing endpoints.
    $details = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation));
    $details->assertOk();
    expect($details->json('quotation.price_change_log'))->toHaveCount(2);
    expect($details->json('quotation.price_adjustments'))->toHaveCount(1);
});
