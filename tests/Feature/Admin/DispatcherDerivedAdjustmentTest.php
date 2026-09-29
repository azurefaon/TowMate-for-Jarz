<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\User;
use App\Services\QuotationService;
use Illuminate\Support\Facades\Mail;

function ddaRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ddaDispatcher(): User
{
    ddaRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function ddaQuotation(string $status = 'sent'): array
{
    $truckType = TruckType::create([
        'name' => 'DDA Truck ' . fake()->unique()->word(),
        'base_rate' => 2500,
        'per_km_rate' => 120,
    ]);

    $user = User::factory()->create(['role_id' => ddaRole(5, 'Customer')->id]);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup Point',
        'dropoff_address' => 'Dropoff Point',
        'distance_km' => 15,
        'base_rate' => 2500,
        'per_km_rate' => 120,
        'computed_total' => 3820,
        'discount_percentage' => 0,
        'final_total' => 4278.4,
        'vat_amount' => 458.4,
        'vat_exclusive_total' => 3820,
        'status' => 'quotation_sent',
    ]);
    $booking->update(['booking_code' => 'TM-DDA' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    $quotation = Quotation::create([
        'quotation_number' => 'Q-DDA-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $booking->pickup_address,
        'dropoff_address' => $booking->dropoff_address,
        'distance_km' => $booking->distance_km,
        'estimated_price' => 4278.4,
        'additional_fee' => 0,
        'status' => $status,
        'sent_at' => now(),
        'is_current' => true,
    ]);

    return [$booking->fresh(), $quotation->fresh()];
}

it('derives a +500 adjustment when the dispatcher types a final price above the base total', function () {
    [$booking, $quotation] = ddaQuotation();
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 4778.4,
        'note' => 'Extra winching required.',
    ]);

    $response->assertOk();

    $current = Quotation::where('quotation_number', $quotation->quotation_number)
        ->where('is_current', true)
        ->first();
    $booking->refresh();

    expect((float) $current->additional_fee)->toBe(500.0)
        ->and((float) $booking->vat_amount)->toBe(458.4)
        ->and((float) $booking->vat_exclusive_total)->toBe(3820.0)
        ->and((float) $booking->final_total)->toBe(4778.4);
});

it('derives a -500 adjustment when the dispatcher types a final price below the base total', function () {
    [$booking, $quotation] = ddaQuotation();
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 3778.4,
        'note' => 'Loyal customer discount.',
    ]);

    $response->assertOk();

    $current = Quotation::where('quotation_number', $quotation->quotation_number)
        ->where('is_current', true)
        ->first();
    $booking->refresh();

    expect((float) $current->additional_fee)->toBe(-500.0)
        ->and((float) $booking->vat_amount)->toBe(458.4)
        ->and((float) $booking->vat_exclusive_total)->toBe(3820.0)
        ->and((float) $booking->final_total)->toBe(3778.4);
});

it('does not let a typed final price bypass the maximum additional charge limit', function () {
    SystemSetting::setValue('max_additional_charge', '200');
    [$booking, $quotation] = ddaQuotation();
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 4778.4,
        'note' => 'Extra winching required.',
    ]);

    $response->assertStatus(422)->assertJsonFragment(['success' => false]);

    expect((float) $quotation->fresh()->estimated_price)->toBe(4278.4)
        ->and((float) $booking->fresh()->final_total)->toBe(4278.4);
});

it('does not let a typed final price bypass the maximum dispatcher discount percentage', function () {
    SystemSetting::setValue('dispatcher_discount_enabled', '1');
    SystemSetting::setValue('max_dispatcher_discount_percentage', '5');
    [$booking, $quotation] = ddaQuotation();
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 3778.4,
        'note' => 'Loyal customer discount.',
    ]);

    $response->assertStatus(422)->assertJsonFragment(['success' => false]);

    expect((float) $quotation->fresh()->estimated_price)->toBe(4278.4)
        ->and((float) $booking->fresh()->final_total)->toBe(4278.4);
});

it('still requires a reason for a derived additional charge when configured to require one', function () {
    SystemSetting::setValue('additional_charge_require_reason', '1');
    [$booking, $quotation] = ddaQuotation();
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 4778.4,
    ]);

    $response->assertStatus(422)->assertJsonFragment(['success' => false]);
});

it('stores the derived adjustment (not the typed price) in additional_fee and price history', function () {
    [$booking, $quotation] = ddaQuotation();
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 4778.4,
        'note' => 'Extra winching required.',
    ]);

    $response->assertOk();

    $current = Quotation::where('quotation_number', $quotation->quotation_number)
        ->where('is_current', true)
        ->first();

    expect((float) $current->additional_fee)->toBe(500.0)
        ->and((float) $current->additional_fee)->not->toBe(4778.4);

    $log = $current->price_change_log;
    expect($log)->not->toBeEmpty();
    // The last array element is now the quotation_sent marker this endpoint
    // correctly appends when it actually sends (see QuotationService::
    // appendSentVersionEntry()) — find the price-delta entry specifically
    // rather than assuming it's the final element.
    $lastEntry = collect($log)->last(fn ($entry) => array_key_exists('old', $entry));
    expect($lastEntry)->not->toBeNull();
    expect((float) $lastEntry['old'])->toBe(4278.4)
        ->and((float) $lastEntry['new'])->toBe(4778.4);

    $sentMarker = collect($log)->last(fn ($entry) => ($entry['type'] ?? null) === 'quotation_sent');
    expect($sentMarker)->not->toBeNull();
});

it('applies the same derived-adjustment invariant to the price-review adjustment endpoint', function () {
    [$booking, $quotation] = ddaQuotation('price_review_requested');
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->post(route('admin.quotations.adjust-price', $quotation), [
        'new_price' => 4978.4,
        'note' => 'Second review — extra fee confirmed.',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $current = Quotation::where('quotation_number', $quotation->quotation_number)
        ->where('is_current', true)
        ->first();
    $booking->refresh();

    expect((float) $current->additional_fee)->toBe(700.0)
        ->and((float) $booking->vat_amount)->toBe(458.4)
        ->and((float) $booking->vat_exclusive_total)->toBe(3820.0)
        ->and((float) $booking->final_total)->toBe(4978.4);
});

it('rejects a price-review adjustment whose typed price implies a charge over the maximum', function () {
    SystemSetting::setValue('max_additional_charge', '200');
    [$booking, $quotation] = ddaQuotation('price_review_requested');
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->post(route('admin.quotations.adjust-price', $quotation), [
        'new_price' => 4978.4,
        'note' => 'Second review — extra fee confirmed.',
    ]);

    $response->assertStatus(422)->assertJsonFragment(['success' => false]);
});

/**
 * Regression coverage for the updateQuotationPrice() communication gate:
 * customer email/push/quotation_sent marker must fire iff $newStatus ===
 * 'sent' — this endpoint is also reachable from the "Edit Price" button on
 * a still-draft quotation (_quotation-modal.blade.php), which must stay
 * completely silent.
 */
it('keeps a draft price edit completely silent: saves the price, no email, no notification, no marker', function () {
    Mail::fake();
    [$booking, $quotation] = ddaQuotation('draft');
    $originalSentAt = $quotation->sent_at;
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 4778.4,
        'note' => 'Draft correction before first send.',
    ]);

    $response->assertOk()->assertJsonPath('message', 'Quotation price updated.');

    $current = Quotation::where('quotation_number', $quotation->quotation_number)
        ->where('is_current', true)
        ->first();

    expect($current->status)->toBe('draft')
        ->and((float) $current->estimated_price)->toBe(4778.4)
        ->and($current->sent_at?->toDateTimeString())->toBe($originalSentAt?->toDateTimeString());

    $marker = collect($current->price_change_log)->firstWhere('type', 'quotation_sent');
    expect($marker)->toBeNull();

    Mail::assertNothingSent();
    expect(CustomerNotification::where('type', 'quotation_updated')->count())->toBe(0);
});

it('keeps a pending-fallback status price edit silent too, with no marker', function () {
    Mail::fake();
    // Any status other than draft/sent/negotiating falls into the $newStatus
    // = 'pending' branch.
    [$booking, $quotation] = ddaQuotation('pending');
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 4778.4,
        'note' => 'Adjustment before any send.',
    ]);

    $response->assertOk()->assertJsonPath('message', 'Quotation price updated.');

    $current = Quotation::where('quotation_number', $quotation->quotation_number)
        ->where('is_current', true)
        ->first();

    expect($current->status)->toBe('pending')
        ->and((float) $current->estimated_price)->toBe(4778.4);

    $marker = collect($current->price_change_log)->firstWhere('type', 'quotation_sent');
    expect($marker)->toBeNull();

    Mail::assertNothingSent();
    expect(CustomerNotification::where('type', 'quotation_updated')->count())->toBe(0);
});

it('sends communication and creates exactly one quotation_sent marker with the correct version and a fresh sent_at when the adjustment actually sends', function () {
    Mail::fake();
    [$booking, $quotation] = ddaQuotation('sent');
    $dispatcher = ddaDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 4778.4,
        'note' => 'Extra winching required.',
    ]);

    $response->assertOk()->assertJsonPath('message', 'Quotation price updated and email sent to customer successfully.');

    $current = Quotation::where('quotation_number', $quotation->quotation_number)
        ->where('is_current', true)
        ->first();

    expect($current->status)->toBe('sent')
        ->and((int) $current->version)->toBe(2)
        ->and($current->sent_at)->not->toBeNull();

    $markers = collect($current->price_change_log)->where('type', 'quotation_sent');
    expect($markers)->toHaveCount(1);
    expect($markers->first()['version'])->toBe(2);

    Mail::assertSent(\App\Mail\QuotationUpdatedMail::class, 1);
    expect(CustomerNotification::where('type', 'quotation_updated')->count())->toBe(1);
});

it('does not append a duplicate quotation_sent marker for the same version', function () {
    $log = [
        ['at' => now()->toISOString(), 'type' => 'quotation_sent', 'version' => 2],
    ];

    $result = app(QuotationService::class)->appendSentVersionEntry($log, 2);

    expect(collect($result)->where('type', 'quotation_sent')->where('version', 2))->toHaveCount(1);
});
