<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\User;

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
    $lastEntry = end($log);
    expect((float) $lastEntry['old'])->toBe(4278.4)
        ->and((float) $lastEntry['new'])->toBe(4778.4);
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
