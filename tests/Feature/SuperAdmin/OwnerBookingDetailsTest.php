<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function bdOwner(): User
{
    if (! Role::find(1)) {
        $role = new Role(['name' => 'Owner']);
        $role->id = 1;
        $role->save();
    }

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function bdCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'Andrea Lim',
        'phone' => '09175550105',
        'email' => 'andrea-' . uniqid() . '@example.com',
    ]);
}

function bdTruck(string $name = 'Light'): TruckType
{
    return TruckType::firstOrCreate(['name' => $name], ['base_rate' => 1500, 'per_km_rate' => 60]);
}

function bdBooking(array $overrides = []): Booking
{
    return Booking::create(array_merge([
        'customer_id' => $overrides['customer_id'] ?? bdCustomer()->id,
        'truck_type_id' => bdTruck()->id,
        'pickup_address' => 'Ortigas Ave. Extension, Cainta, Rizal',
        'dropoff_address' => 'Marcos Hwy., Antipolo City',
        'distance_km' => 8.4,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 1800,
        'service_type' => 'book_now',
        'status' => 'requested',
    ], $overrides));
}

function bdQuotation(Booking $source, array $overrides = []): Quotation
{
    return Quotation::create(array_merge([
        'quotation_number' => 'Q-' . uniqid(),
        'source_booking_id' => $source->id,
        'customer_id' => $source->customer_id,
        'truck_type_id' => $source->truck_type_id,
        'pickup_address' => $source->pickup_address,
        'dropoff_address' => $source->dropoff_address,
        'distance_km' => 8.4,
        'estimated_price' => $source->final_total,
        'status' => 'sent',
    ], $overrides));
}

/**
 * A normalized three-vehicle group (one cancelled), plus a same-group_code
 * booking on a different route that must never be treated as a member.
 */
function bdGroup(string $quotationStatus, array $memberStatuses = ['quotation_sent', 'quotation_sent', 'cancelled']): array
{
    $customer = bdCustomer();
    $code = 'GRP-' . fake()->unique()->numerify('######');
    $lines = array_slice([3980.00, 5450.00, 2000.00], 0, count($memberStatuses));

    $members = collect($memberStatuses)->map(fn ($status, $i) => bdBooking([
        'customer_id' => $customer->id,
        'group_code' => $code,
        'status' => $status,
        'final_total' => $lines[$i],
        'truck_type_id' => bdTruck($i === 0 ? 'Light' : 'Medium')->id,
    ]));

    $anchor = $members->first();
    $lineItems = $members->map(fn (Booking $b, $i) => ['booking_id' => $b->id, 'final_total' => $lines[$i], 'estimated_price' => $lines[$i]])->all();
    $anchor->update(['extra_vehicles' => $lineItems]);

    $quotation = bdQuotation($anchor, [
        'status' => $quotationStatus,
        'extra_vehicles' => $lineItems,
        'additional_fee' => 500,
        'discount' => 300,
        // Lines - discount + fee, as the grouped quote save stores it.
        'estimated_price' => array_sum($lines) - 300 + 500,
        'expires_at' => now()->addDays(7),
        'responded_at' => $quotationStatus === 'accepted' ? now() : null,
    ]);
    $members->each(fn (Booking $b) => $b->update(['quotation_id' => $quotation->id]));

    $outsider = bdBooking(['customer_id' => $customer->id, 'group_code' => $code, 'dropoff_address' => 'Somewhere else', 'final_total' => 99999]);

    return [$members->map->fresh(), $quotation, $outsider];
}

function bdShow($test, Booking $booking)
{
    return $test->actingAs(bdOwner())->getJson(route('superadmin.bookings.show', $booking->job_code))->assertOk();
}

it('shows a completed single booking with its fee, quotation, payment and receipt', function () {
    $booking = bdBooking(['status' => 'completed', 'final_total' => 7350, 'additional_fee' => 350, 'payment_method' => 'cash',
        'completed_at' => now()->setDate(2026, 10, 10)->setTime(13, 40), 'notes' => 'Car has low ground clearance.']);
    $quotation = bdQuotation($booking, ['status' => 'accepted', 'estimated_price' => 7350, 'responded_at' => now()->setDate(2026, 10, 10)->setTime(11, 46)]);
    $booking->update(['quotation_id' => $quotation->id]);
    Receipt::create(['booking_id' => $booking->id, 'generated_by' => bdOwner()->id, 'receipt_number' => 'R-1', 'pdf_path' => 'documents/receipts/r1.pdf']);

    $data = bdShow($this, $booking)->json();

    expect($data['status'])->toBe(['label' => 'Completed', 'tone' => 'green']);
    expect($data['customer']['phone'])->toBe('0917 555 0105');
    expect($data['pricing']['kind'])->toBe('single');
    expect($data['pricing']['total'])->toMatchArray(['label' => 'Total', 'amount' => '₱7,350.00', 'note' => 'Includes ₱350.00 additional fee']);
    expect($data['pricing']['quotation'])->toBe(['value' => 'Accepted', 'note' => 'Oct 10, 2026, 11:46 AM']);
    expect($data['pricing']['payment'])->toBe(['value' => 'Confirmed', 'note' => 'Cash · Oct 10, 2026, 1:40 PM']);
    expect($data['pricing']['receipt']['code'])->not->toBeEmpty();
    expect($data['notes'])->toBe('Car has low ground clearance.');
    expect($data)->not->toHaveKey('vehicles');
});

it('labels a single booking estimate before quotation and the quoted total once sent', function () {
    $requested = bdBooking(['status' => 'requested', 'final_total' => 2100]);
    expect(bdShow($this, $requested)->json('pricing.total'))->toMatchArray(['label' => 'Estimated total', 'amount' => '₱2,100.00']);
    expect(bdShow($this, $requested)->json('pricing.quotation.value'))->toBe('Not sent yet');

    $sent = bdBooking(['status' => 'quotation_sent', 'final_total' => 2500]);
    $sent->update(['quotation_id' => bdQuotation($sent, ['estimated_price' => 2500, 'expires_at' => now()->addHour()])->id]);
    $data = bdShow($this, $sent)->json('pricing');
    expect($data['total'])->toMatchArray(['label' => 'Quoted total', 'amount' => '₱2,500.00']);
    expect($data['quotation']['value'])->toBe('Sent, awaiting customer');
    expect($data['payment']['value'])->toBe('Not submitted');
});

it('prices a sent group from its quotation lines, not the anchor booking total', function () {
    [$members, $quotation] = bdGroup('sent', ['quotation_sent', 'quotation_sent']);
    // sendQuotation() writes the whole quoted total onto the anchor booking.
    $members[0]->update(['final_total' => $quotation->estimated_price]);

    $data = bdShow($this, $members[0])->json();

    expect($data['pricing']['state'])->toBe('quoted');
    expect($data['pricing']['rows'])->toBe([
        ['label' => 'Vehicle subtotal', 'amount' => '₱9,430.00', 'note' => '2 vehicles'],
        ['label' => 'Group fee', 'amount' => '+₱500.00', 'note' => null],
        ['label' => 'Group discount', 'amount' => '−₱300.00', 'note' => null],
    ]);
    expect($data['pricing']['total'])->toBe(['label' => 'Quoted total', 'amount' => '₱9,630.00', 'note' => null]);
    expect($data['vehicles']['amount_label'])->toBe('Quoted');
    expect($data['vehicles']['note'])->toBe('Amounts from the sent quotation lines');
    expect(collect($data['vehicles']['rows'])->pluck('amount')->all())->toBe(['₱3,980.00', '₱5,450.00']);
});

it('uses each accepted vehicle total and applies the group fee and discount once', function () {
    [$members] = bdGroup('accepted', ['on_the_way', 'assigned', 'cancelled']);
    // A post-acceptance correction to one vehicle must be reflected.
    $members[1]->update(['final_total' => 5600]);

    $data = bdShow($this, $members[0])->json();

    expect($data['pricing']['rows'][0])->toBe(['label' => 'Vehicle subtotal', 'amount' => '₱9,580.00', 'note' => '2 charged vehicles']);
    expect($data['pricing']['total'])->toBe(['label' => 'Group total', 'amount' => '₱9,780.00', 'note' => null]);
    expect($data['vehicles']['note'])->toBe('Same customer and route');
    expect(collect($data['vehicles']['rows'])->pluck('amount')->all())->toBe(['₱3,980.00', '₱5,600.00', 'Not Charged']);
    expect(collect($data['vehicles']['rows'])->pluck('is_current')->all())->toBe([true, false, false]);
});

it('uses the stored invoice total once an invoice exists', function () {
    [$members, $quotation] = bdGroup('accepted', ['waiting_verification', 'waiting_verification', 'cancelled']);
    $invoice = Invoice::create(['booking_id' => $members[0]->id, 'quotation_id' => $quotation->id, 'subtotal' => 8419.64,
        'additional_fee' => 500, 'discount' => 300, 'total' => 9630, 'status' => 'issued', 'is_current' => true]);

    $data = bdShow($this, $members[1])->json('pricing');

    expect($data['total'])->toBe(['label' => 'Group total', 'amount' => '₱9,630.00', 'note' => 'Invoice ' . $invoice->invoice_number]);
    expect($data['rows'])->toHaveCount(3);
});

it('hides the breakdown when it does not add up to the invoice total', function () {
    [$members, $quotation] = bdGroup('accepted', ['waiting_verification', 'waiting_verification', 'cancelled']);
    Invoice::create(['booking_id' => $members[0]->id, 'quotation_id' => $quotation->id, 'subtotal' => 1,
        'additional_fee' => 0, 'discount' => 0, 'total' => 10000, 'status' => 'issued', 'is_current' => true]);

    $data = bdShow($this, $members[0])->json('pricing');

    expect($data['total']['amount'])->toBe('₱10,000.00');
    expect($data['rows'])->toBe([]);
});

it('hides amounts when the group quotation expired', function () {
    [$members] = bdGroup('expired', ['not_responding', 'quotation_sent', 'quotation_sent']);

    $data = bdShow($this, $members[1])->json();

    expect($data['pricing']['unavailable'])->toBe('No accepted quote');
    expect($data['pricing']['quotation']['value'])->toBe('Expired');
    expect($data['vehicles']['show_amounts'])->toBeFalse();
});

it('shows an estimated group total before the quotation is sent', function () {
    [$members] = bdGroup('pending', ['requested', 'requested', 'cancelled']);

    $data = bdShow($this, $members[0])->json('pricing');

    expect($data['total'])->toBe(['label' => 'Estimated group total', 'amount' => '₱9,430.00', 'note' => '2 vehicles']);
    expect($data['rows'])->toBe([]);
});

it('never treats a shared group_code on a different route as a member', function () {
    [$members, , $outsider] = bdGroup('accepted', ['assigned', 'assigned', 'assigned']);

    $data = bdShow($this, $members[0])->json();
    expect(collect($data['vehicles']['rows'])->pluck('code'))->not->toContain($outsider->job_code);
    expect($data['pricing']['total']['amount'])->toBe('₱11,630.00');

    $outsiderData = bdShow($this, $outsider)->json();
    expect($outsiderData['pricing']['kind'])->toBe('single');
    expect($outsiderData)->not->toHaveKey('vehicles');
});

it('shows an older group without per-vehicle pricing as related bookings', function () {
    $customer = bdCustomer();
    $first = bdBooking(['customer_id' => $customer->id, 'group_code' => 'Q-LEGACY-1', 'status' => 'completed', 'completed_at' => now(), 'payment_method' => 'gcash']);
    $second = bdBooking(['customer_id' => $customer->id, 'group_code' => 'Q-LEGACY-1', 'status' => 'completed', 'completed_at' => now()]);
    $quotation = bdQuotation($first, ['source_booking_id' => null, 'status' => 'accepted', 'extra_vehicles' => [['truck_type_id' => 1, 'estimated_price' => 1800]], 'responded_at' => now()]);
    $first->update(['quotation_id' => $quotation->id]);
    $second->update(['quotation_id' => $quotation->id]);

    $data = bdShow($this, $first)->json();

    expect($data['pricing']['kind'])->toBe('legacy');
    expect($data['pricing']['unavailable'])->toBe('Not available');
    expect($data['pricing']['unavailable_note'])->toBe('This older request has no reliable per-vehicle pricing.');
    expect($data['pricing']['payment']['value'])->toBe('Confirmed');
    expect(collect($data['related'])->pluck('code')->all())->toBe([$second->job_code]);
    expect($data)->not->toHaveKey('vehicles');
});

it('finds a booking without a booking code by its displayed number', function () {
    $booking = bdBooking();
    // Older bookings may have no booking_code; they are shown by their padded id.
    $booking->forceFill(['booking_code' => null])->saveQuietly();

    expect($booking->job_code)->toBe(str_pad((string) $booking->id, 7, '0', STR_PAD_LEFT));
    $this->actingAs(bdOwner())->getJson(route('superadmin.bookings.show', $booking->job_code))
        ->assertOk()
        ->assertJsonPath('code', $booking->job_code);
});

it('opens the booking whose code matches, even when the code equals another booking id', function () {
    $other = bdBooking();
    $target = bdBooking(['status' => 'completed', 'completed_at' => now()]);
    // A numeric booking code that happens to equal a different booking's id.
    $target->forceFill(['booking_code' => str_pad((string) $other->id, 7, '0', STR_PAD_LEFT)])->saveQuietly();

    $this->actingAs(bdOwner())->getJson(route('superadmin.bookings.show', $target->booking_code))
        ->assertOk()
        ->assertJsonPath('status.label', 'Completed');
});
