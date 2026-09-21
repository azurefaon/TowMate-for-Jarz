<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\User;

function palRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function palDispatcher(): User
{
    palRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function palQuotation(float $estimatedPrice = 2000): Quotation
{
    $user = User::factory()->create(['role_id' => palRole(5, 'Customer')->id]);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);
    $truckType = TruckType::create(['name' => 'PAL Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id'   => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => $estimatedPrice,
        'status' => 'requested',
    ]);
    $booking->update(['booking_code' => 'TM-PAL' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return Quotation::create([
        'quotation_number'  => 'Q-PAL-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id'       => $customer->id,
        'truck_type_id'     => $truckType->id,
        'pickup_address'    => $booking->pickup_address,
        'dropoff_address'   => $booking->dropoff_address,
        'distance_km'       => 5,
        'estimated_price'   => $estimatedPrice,
        'status'            => 'sent',
        'sent_at'           => now(),
        'is_current'        => true,
    ]);
}

it('rejects a dispatcher discount beyond the configured maximum percentage', function () {
    SystemSetting::setValue('dispatcher_discount_enabled', '1');
    SystemSetting::setValue('max_dispatcher_discount_percentage', '10');

    $quotation = palQuotation(2000);

    $response = $this->actingAs(palDispatcher())->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 1500,
    ]);

    $response->assertStatus(422);
    $response->assertJsonFragment(['success' => false]);
    expect($quotation->fresh()->estimated_price)->toEqual(2000);
});

it('allows a dispatcher discount within the configured maximum percentage', function () {
    SystemSetting::setValue('dispatcher_discount_enabled', '1');
    SystemSetting::setValue('max_dispatcher_discount_percentage', '25');

    $quotation = palQuotation(2000);

    $response = $this->actingAs(palDispatcher())->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 1600,
    ]);

    $response->assertOk();
});

it('rejects any price reduction when dispatcher discounts are disabled', function () {
    SystemSetting::setValue('dispatcher_discount_enabled', '0');

    $quotation = palQuotation(2000);

    $response = $this->actingAs(palDispatcher())->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 1900,
    ]);

    $response->assertStatus(422);
    $response->assertJsonFragment(['success' => false]);
});

it('requires a reason for a discount when configured to require one', function () {
    SystemSetting::setValue('dispatcher_discount_enabled', '1');
    SystemSetting::setValue('dispatcher_discount_require_reason', '1');

    $quotation = palQuotation(2000);

    $response = $this->actingAs(palDispatcher())->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 1900,
    ]);

    $response->assertStatus(422);
});

it('rejects an additional charge beyond the configured maximum amount', function () {
    SystemSetting::setValue('max_additional_charge', '200');

    $quotation = palQuotation(2000);

    $response = $this->actingAs(palDispatcher())->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 2500,
        'additional_fee' => 300,
        'note' => 'Extra winching required.',
    ]);

    $response->assertStatus(422);
    $response->assertJsonFragment(['success' => false]);
});

it('allows an additional charge within the configured maximum amount', function () {
    SystemSetting::setValue('max_additional_charge', '500');

    $quotation = palQuotation(2000);

    $response = $this->actingAs(palDispatcher())->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 2300,
        'additional_fee' => 300,
        'note' => 'Extra winching required.',
    ]);

    $response->assertOk();
});

it('does not enforce a discount cap when no maximum is configured', function () {
    $quotation = palQuotation(2000);

    $response = $this->actingAs(palDispatcher())->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 1000,
    ]);

    $response->assertOk();
});

it('cannot be bypassed by calling the price-review adjustment endpoint directly', function () {
    SystemSetting::setValue('dispatcher_discount_enabled', '1');
    SystemSetting::setValue('max_dispatcher_discount_percentage', '10');

    $quotation = palQuotation(2000);
    $quotation->update(['status' => 'price_review_requested']);

    $response = $this->actingAs(palDispatcher())->post(route('admin.quotations.adjust-price', $quotation), [
        'new_price' => 1500,
    ]);

    $response->assertStatus(422);
    $response->assertJsonFragment(['success' => false]);
});
