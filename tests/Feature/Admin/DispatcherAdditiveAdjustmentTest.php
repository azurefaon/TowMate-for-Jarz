<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\User;

function daaRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function daaDispatcher(): User
{
    daaRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function daaBooking(): Booking
{
    $truckType = TruckType::create([
        'name' => 'DAA Truck ' . fake()->unique()->word(),
        'base_rate' => 2500,
        'per_km_rate' => 120,
    ]);

    $user = User::factory()->create(['role_id' => daaRole(5, 'Customer')->id]);
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
        'final_total' => 3820,
        'status' => 'requested',
        'service_type' => 'book_now',
    ]);
    $booking->update(['booking_code' => 'TM-DAA' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

function daaSaveDraft($booking, float $additionalFeeDelta, ?string $note = 'Adjustment reason')
{
    return test()->actingAs(daaDispatcher())->postJson(route('admin.booking.save-draft', $booking), [
        'price' => 999999,
        'additional_fee' => $additionalFeeDelta,
        'dispatcher_note' => $note,
        'distance_km' => $booking->distance_km,
    ]);
}

// A. Base 4278.40, saved +500, no staging -> adjustment +500, final 4778.40
it('A: saves a +500 adjustment producing final 4778.40', function () {
    $booking = daaBooking();

    $response = daaSaveDraft($booking, 500);
    $response->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->latest()->first();

    expect((float) $quotation->additional_fee)->toBe(500.0)
        ->and((float) $quotation->estimated_price)->toBe(4778.4);
});

// B. saved +500, stage add +500 -> preview adjustment +1000, preview final 5278.40 (server authoritative sum)
it('B: adding +500 on top of a saved +500 produces an effective +1000 and final 5278.40', function () {
    $booking = daaBooking();
    daaSaveDraft($booking, 500)->assertOk();

    $response = daaSaveDraft($booking, 500);
    $response->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->latest()->first();

    expect((float) $quotation->additional_fee)->toBe(1000.0)
        ->and((float) $quotation->estimated_price)->toBe(5278.4);
});

// C. save the second +500 -> persisted adjustment +1000, reopen final 5278.40
it('C: reopening after the second +500 shows the persisted effective adjustment of +1000', function () {
    $booking = daaBooking();
    daaSaveDraft($booking, 500)->assertOk();
    daaSaveDraft($booking, 500)->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->latest()->first();
    $booking->refresh();

    expect((float) $quotation->additional_fee)->toBe(1000.0)
        ->and((float) $quotation->estimated_price)->toBe(5278.4)
        ->and((float) $booking->final_total)->toBe(5278.4)
        ->and((float) $booking->vat_amount)->toBe(458.4)
        ->and((float) $booking->vat_exclusive_total)->toBe(3820.0);
});

// D. saved +500, stage deduct 500 -> preview adjustment 0, preview final 4278.40
it('D: deducting 500 against a saved +500 produces an effective 0 and final 4278.40', function () {
    $booking = daaBooking();
    daaSaveDraft($booking, 500)->assertOk();

    $response = daaSaveDraft($booking, -500);
    $response->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->latest()->first();

    expect((float) $quotation->additional_fee)->toBe(0.0)
        ->and((float) $quotation->estimated_price)->toBe(4278.4);
});

// E. save deduct 500 -> persisted adjustment 0, reopen final 4278.40
it('E: reopening after the offsetting deduction shows a net-zero adjustment', function () {
    $booking = daaBooking();
    daaSaveDraft($booking, 500)->assertOk();
    daaSaveDraft($booking, -500)->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->latest()->first();

    expect((float) $quotation->additional_fee)->toBe(0.0)
        ->and((float) $quotation->estimated_price)->toBe(4278.4);
});

// F. saved +500, stage deduct 700 -> effective adjustment -200, final 4078.40
it('F: deducting 700 against a saved +500 produces an effective -200 and final 4078.40', function () {
    $booking = daaBooking();
    daaSaveDraft($booking, 500)->assertOk();

    $response = daaSaveDraft($booking, -700);
    $response->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->latest()->first();

    expect((float) $quotation->additional_fee)->toBe(-200.0)
        ->and((float) $quotation->estimated_price)->toBe(4078.4);
});

// G. VAT stays 458.40 throughout every scenario above
it('G: VAT remains 458.40 across every additive adjustment scenario', function () {
    $booking = daaBooking();
    daaSaveDraft($booking, 500)->assertOk();
    daaSaveDraft($booking, 500)->assertOk();
    daaSaveDraft($booking, -1200)->assertOk();

    $booking->refresh();
    expect((float) $booking->vat_amount)->toBe(458.4)
        ->and((float) $booking->vat_exclusive_total)->toBe(3820.0);
});

// H. limit enforcement uses the resulting effective adjustment, not the raw delta
it('H: rejects a second increment whose resulting effective adjustment exceeds the maximum charge', function () {
    SystemSetting::setValue('max_additional_charge', '800');
    $booking = daaBooking();
    daaSaveDraft($booking, 500)->assertOk();

    $response = daaSaveDraft($booking, 500);

    $response->assertStatus(422)->assertJsonFragment(['success' => false]);

    $quotation = Quotation::where('source_booking_id', $booking->id)->latest()->first();
    expect((float) $quotation->additional_fee)->toBe(500.0);
});

// I. client cannot fake final_total — price field is ignored, server derives it
it('I: a fabricated price field is ignored and the server derives the real total', function () {
    $booking = daaBooking();

    $response = test()->actingAs(daaDispatcher())->postJson(route('admin.booking.save-draft', $booking), [
        'price' => 1.00,
        'additional_fee' => 500,
        'dispatcher_note' => 'Adjustment reason',
        'distance_km' => $booking->distance_km,
    ]);
    $response->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->latest()->first();
    expect((float) $quotation->estimated_price)->toBe(4778.4)
        ->and((float) $quotation->estimated_price)->not->toBe(1.00);
});

// J. price history preserves both saved adjustment events separately
it('J: price history preserves both +500 adjustment events as separate entries', function () {
    $booking = daaBooking();
    daaSaveDraft($booking, 500, 'Reason A')->assertOk();
    daaSaveDraft($booking, 500, 'Reason B')->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->latest()->first();
    $log = $quotation->price_change_log;

    expect($log)->toHaveCount(2)
        ->and((float) $log[0]['old'])->toBe(4278.4)
        ->and((float) $log[0]['new'])->toBe(4778.4)
        ->and($log[0]['reason'])->toBe('Reason A')
        ->and((float) $log[1]['old'])->toBe(4778.4)
        ->and((float) $log[1]['new'])->toBe(5278.4)
        ->and($log[1]['reason'])->toBe('Reason B');
});
