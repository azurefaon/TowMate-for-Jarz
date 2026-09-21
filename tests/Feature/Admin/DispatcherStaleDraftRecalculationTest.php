<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function dsdRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function dsdDispatcher(): User
{
    dsdRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function dsdDraft(array $bookingOverrides, array $quotationOverrides): array
{
    $truckType = TruckType::create([
        'name' => 'DSD Truck ' . fake()->unique()->word(),
        'base_rate' => 2500,
        'per_km_rate' => 120,
    ]);

    $user = User::factory()->create(['role_id' => dsdRole(5, 'Customer')->id]);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup Point',
        'dropoff_address' => 'Dropoff Point',
        'distance_km' => 15,
        'base_rate' => 2500,
        'per_km_rate' => 120,
        'discount_percentage' => 0,
        'status' => 'requested',
        'service_type' => 'book_now',
    ], $bookingOverrides));
    $booking->update(['booking_code' => 'TM-DSD' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    $quotation = Quotation::create(array_merge([
        'quotation_number' => 'Q-DSD-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $booking->pickup_address,
        'dropoff_address' => $booking->dropoff_address,
        'distance_km' => 15,
        'status' => 'draft',
        'is_current' => true,
    ], $quotationOverrides));

    return [$booking->fresh(), $quotation->fresh()];
}

it('recalculates a stale-persisted Draft final_total (old double-VAT output) without changing VAT', function () {
    [$booking, $quotation] = dsdDraft(
        [
            'computed_total' => 3820,
            'vat_amount' => 458.4,
            'vat_exclusive_total' => 3820,
            'final_total' => 4838.4,
        ],
        [
            'additional_fee' => 500,
            'estimated_price' => 4838.4,
        ],
    );

    $response = test()->actingAs(dsdDispatcher())->getJson(route('admin.quotations.details', $quotation));

    $response->assertOk();
    expect((float) $response->json('quotation.vat_amount'))->toBe(458.4)
        ->and((float) $response->json('quotation.subtotal'))->toBe(3820.0)
        ->and((float) $response->json('quotation.base_total'))->toBe(4278.4)
        ->and((float) $response->json('quotation.estimated_price'))->toBe(4778.4)
        ->and((float) $response->json('quotation.estimated_price'))->not->toBe(4838.4);
});

it('recalculates a stale computed_total (drifted from base_rate + distance_fee) for an editable Draft', function () {
    [$booking, $quotation] = dsdDraft(
        [
            'distance_km' => 8.4,
            'per_km_rate' => 300,
            'computed_total' => 5020,
            'vat_amount' => 602.4,
            'vat_exclusive_total' => 5020,
            'final_total' => 4278.4,
        ],
        [
            'distance_km' => 8.4,
            'additional_fee' => 0,
            'estimated_price' => 4278.4,
        ],
    );

    $response = test()->actingAs(dsdDispatcher())->getJson(route('admin.quotations.details', $quotation));

    $response->assertOk();
    expect((float) $response->json('quotation.subtotal'))->toBe(3820.0)
        ->and((float) $response->json('quotation.vat_amount'))->toBe(458.4)
        ->and((float) $response->json('quotation.base_total'))->toBe(4278.4);
});

it('does not recalculate or rewrite a Sent quotation\'s frozen VAT snapshot', function () {
    [$booking, $quotation] = dsdDraft(
        [
            'distance_km' => 8.4,
            'per_km_rate' => 300,
            'computed_total' => 5020,
            'vat_amount' => 602.4,
            'vat_exclusive_total' => 5020,
            'final_total' => 5622.4,
        ],
        [
            'distance_km' => 8.4,
            'additional_fee' => 0,
            'estimated_price' => 5622.4,
            'status' => 'sent',
            'sent_at' => now(),
        ],
    );

    $response = test()->actingAs(dsdDispatcher())->getJson(route('admin.quotations.details', $quotation));

    $response->assertOk();
    expect((float) $response->json('quotation.subtotal'))->toBe(5020.0)
        ->and((float) $response->json('quotation.vat_amount'))->toBe(602.4)
        ->and((float) $response->json('quotation.estimated_price'))->toBe(5622.4);

    $booking->refresh();
    expect((float) $booking->computed_total)->toBe(5020.0);
});

it('gives a newly-created Draft with the same inputs the correct 4778.40 final total', function () {
    [$booking, $quotation] = dsdDraft(
        [
            'computed_total' => 3820,
            'final_total' => 3820,
        ],
        [
            'additional_fee' => 500,
            'estimated_price' => 4278.4,
        ],
    );

    $response = test()->actingAs(dsdDispatcher())->getJson(route('admin.quotations.details', $quotation));

    $response->assertOk();
    expect((float) $response->json('quotation.vat_amount'))->toBe(458.4)
        ->and((float) $response->json('quotation.estimated_price'))->toBe(4778.4);
});

it('self-heals the booking computed_total when a Draft price update is saved', function () {
    [$booking, $quotation] = dsdDraft(
        [
            'distance_km' => 8.4,
            'per_km_rate' => 300,
            'computed_total' => 5020,
            'vat_amount' => 602.4,
            'vat_exclusive_total' => 5020,
            'final_total' => 4278.4,
        ],
        [
            'distance_km' => 8.4,
            'additional_fee' => 0,
            'estimated_price' => 4278.4,
        ],
    );
    $dispatcher = dsdDispatcher();

    $response = test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => 4778.4,
        'note' => 'Extra winching required.',
    ]);

    $response->assertOk();

    $booking->refresh();
    $current = Quotation::where('quotation_number', $quotation->quotation_number)->where('is_current', true)->first();

    expect((float) $booking->computed_total)->toBe(3820.0)
        ->and((float) $booking->vat_amount)->toBe(458.4)
        ->and((float) $booking->vat_exclusive_total)->toBe(3820.0)
        ->and((float) $booking->final_total)->toBe(4778.4)
        ->and((float) $current->additional_fee)->toBe(500.0);
});
