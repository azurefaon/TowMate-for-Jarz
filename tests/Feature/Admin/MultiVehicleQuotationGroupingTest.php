<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

function mvqDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function mvqCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'MVQ Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'mvq-' . uniqid() . '@example.com',
    ]);
}

function mvqTruckType(float $baseRate, float $perKmRate): TruckType
{
    return TruckType::create([
        'name' => 'MVQ Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function mvqScheduledBooking(Customer $customer, TruckType $truckType, string $groupCode, float $distanceKm = 12.0, string $dropoff = 'Makati'): Booking
{
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => $dropoff,
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

function mvqDraft(User $dispatcher, Booking $booking, float $price)
{
    return test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $booking), [
        'price' => (string) $price,
        'distance_km' => (string) $booking->distance_km,
    ]);
}

it('does not let the group quotation be sent until every same-route sibling has been drafted', function () {
    Mail::fake();
    $dispatcher = mvqDispatcher();
    $customer = mvqCustomer();
    $truckA = mvqTruckType(1500, 60);
    $truckB = mvqTruckType(900, 40);
    $groupCode = 'GRP-000001';

    $bookingA = mvqScheduledBooking($customer, $truckA, $groupCode);
    $bookingB = mvqScheduledBooking($customer, $truckB, $groupCode);

    mvqDraft($dispatcher, $bookingA, 1900)->assertOk();

    $quotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    expect($quotation->status)->toBe('draft');
    expect($quotation->extra_vehicles)->toHaveCount(1);
    expect($bookingB->fresh()->status)->toBe('scheduled');

    $response = test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation));

    $response->assertStatus(422);
    expect($quotation->fresh()->status)->toBe('draft');
});

it('sends one shared quotation once every same-route sibling has been drafted, each with its own independent totals', function () {
    Mail::fake();
    $dispatcher = mvqDispatcher();
    $customer = mvqCustomer();
    $truckA = mvqTruckType(1500, 60);
    $truckB = mvqTruckType(900, 40);
    $groupCode = 'GRP-000002';

    $bookingA = mvqScheduledBooking($customer, $truckA, $groupCode, 12.0);
    $bookingB = mvqScheduledBooking($customer, $truckB, $groupCode, 12.0);

    mvqDraft($dispatcher, $bookingA, 1900)->assertOk();
    mvqDraft($dispatcher, $bookingB, 1100)->assertOk();

    $quotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    expect($quotation->extra_vehicles)->toHaveCount(2);

    $response = test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation));
    $response->assertOk();

    $quotation = $quotation->fresh();
    expect($quotation->status)->toBe('sent');

    $bookingA->refresh();
    $bookingB->refresh();
    expect($bookingA->status)->toBe('quotation_sent');
    expect($bookingB->status)->toBe('quotation_sent');
    expect($bookingA->quotation_number)->toBe($quotation->quotation_number);
    expect($bookingB->quotation_number)->toBe($quotation->quotation_number);

    $expectedA = round(1500 + (12.0 - 4.0) * 60, 2);
    $expectedAWithVat = round($expectedA * 1.12, 2);
    $expectedB = round(900 + (12.0 - 4.0) * 40, 2);
    $expectedBWithVat = round($expectedB * 1.12, 2);

    $lineItemFor = fn($bookingId) => collect($quotation->extra_vehicles)->firstWhere('booking_id', $bookingId);

    expect((float) $lineItemFor($bookingA->id)['final_total'])->toBe($expectedAWithVat);
    expect((float) $lineItemFor($bookingB->id)['final_total'])->toBe($expectedBWithVat);
    expect((float) $quotation->estimated_price)->toBe(round($expectedAWithVat + $expectedBWithVat, 2));
    expect((float) $bookingA->final_total)->not->toBe((float) $bookingB->final_total);
});

it('never merges two same group_code bookings with different destinations into one quotation', function () {
    Mail::fake();
    $dispatcher = mvqDispatcher();
    $customer = mvqCustomer();
    $truckA = mvqTruckType(1500, 60);
    $truckB = mvqTruckType(900, 40);
    $groupCode = 'GRP-000003';

    $bookingA = mvqScheduledBooking($customer, $truckA, $groupCode, 12.0, 'Makati');
    $bookingB = mvqScheduledBooking($customer, $truckB, $groupCode, 12.0, 'Quezon City');

    mvqDraft($dispatcher, $bookingA, 1900)->assertOk();

    $quotationA = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    expect($quotationA->extra_vehicles)->toBeEmpty();

    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotationA))->assertOk();
    expect($quotationA->fresh()->status)->toBe('sent');
    expect($bookingB->fresh()->status)->toBe('scheduled');
});

it('removes a cancelled vehicle line item from a still-draft group quotation and recomputes the total', function () {
    Mail::fake();
    $dispatcher = mvqDispatcher();
    $customer = mvqCustomer();
    $truckA = mvqTruckType(1500, 60);
    $truckB = mvqTruckType(900, 40);
    $groupCode = 'GRP-000004';

    $bookingA = mvqScheduledBooking($customer, $truckA, $groupCode);
    $bookingB = mvqScheduledBooking($customer, $truckB, $groupCode);

    mvqDraft($dispatcher, $bookingA, 1900)->assertOk();
    mvqDraft($dispatcher, $bookingB, 1100)->assertOk();

    $quotationBefore = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    expect($quotationBefore->extra_vehicles)->toHaveCount(2);
    expect($quotationBefore->version)->toBe(1);

    $user = User::factory()->create(['role_id' => 5]);
    $customer->update(['user_id' => $user->id]);

    Sanctum::actingAs($user, ['*']);
    test()->postJson("/api/v1/bookings/{$bookingB->booking_code}/cancel")->assertOk();

    $quotationAfter = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    expect($quotationAfter->id)->toBe($quotationBefore->id);
    expect($quotationAfter->version)->toBe(1);
    expect($quotationAfter->extra_vehicles)->toHaveCount(1);
    expect(collect($quotationAfter->extra_vehicles)->pluck('booking_id')->all())->toBe([$bookingA->id]);

    $expectedA = round(1500 + (12.0 - 4.0) * 60, 2);
    $expectedAWithVat = round($expectedA * 1.12, 2);
    expect((float) $quotationAfter->estimated_price)->toBe($expectedAWithVat);
});

it('preserves a manually entered Service Discount across a later price update that does not touch it', function () {
    Mail::fake();
    $dispatcher = mvqDispatcher();
    $customer = mvqCustomer();
    $truckType = mvqTruckType(1500, 60);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12.0,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'requested',
    ]);

    $unitRole = Role::find(3) ?: tap(new Role(['name' => 'Team Leader']), function ($r) {
        $r->id = 3;
        $r->save();
    });
    $leader = User::factory()->create(['role_id' => $unitRole->id]);
    \Illuminate\Support\Facades\Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));
    $unit = \App\Models\Unit::create([
        'name' => 'MVQ Unit',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $leader->id,
        'status' => 'available',
    ]);

    test()->actingAs($dispatcher)->post(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '12.00',
        'distance_fee' => (string) round((12.0 - 4.0) * 60, 2),
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->fresh()->id)->current()->first();

    test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => '2000',
        'discount' => '150',
        'note' => 'Multi-vehicle service discount',
    ])->assertOk();

    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect((float) $quotation->discount)->toBe(150.0);

    test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => '2100',
        'note' => 'Distance correction',
    ])->assertOk();

    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect((float) $quotation->discount)->toBe(150.0);
});

it('applies a Service Discount to a grouped quotation total without disturbing any sibling booking own final_total', function () {
    Mail::fake();
    $dispatcher = mvqDispatcher();
    $customer = mvqCustomer();
    $truckA = mvqTruckType(1500, 60);
    $truckB = mvqTruckType(900, 40);
    $groupCode = 'GRP-000007';

    $bookingA = mvqScheduledBooking($customer, $truckA, $groupCode);
    $bookingB = mvqScheduledBooking($customer, $truckB, $groupCode);

    mvqDraft($dispatcher, $bookingA, 1900)->assertOk();
    mvqDraft($dispatcher, $bookingB, 1100)->assertOk();

    $bookingAFinalTotalBefore = (float) $bookingA->fresh()->final_total;
    $bookingBFinalTotalBefore = (float) $bookingB->fresh()->final_total;

    $quotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    $subtotalBeforeDiscount = (float) $quotation->estimated_price;

    test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $quotation), [
        'new_price' => (string) round($subtotalBeforeDiscount - 100, 2),
        'discount' => '100',
        'note' => 'Multi-vehicle service discount',
    ])->assertOk();

    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect((float) $quotation->discount)->toBe(100.0);
    expect((float) $quotation->estimated_price)->toBe(round($subtotalBeforeDiscount - 100, 2));
    expect($quotation->extra_vehicles)->toHaveCount(2);

    expect((float) $bookingA->fresh()->final_total)->toBe($bookingAFinalTotalBefore);
    expect((float) $bookingB->fresh()->final_total)->toBe($bookingBFinalTotalBefore);
});

it('reprices a group quotation into a new draft version when one vehicle is cancelled after the quotation was already sent, requiring dispatcher resend', function () {
    Mail::fake();
    $dispatcher = mvqDispatcher();
    $customer = mvqCustomer();
    $truckA = mvqTruckType(1500, 60);
    $truckB = mvqTruckType(900, 40);
    $groupCode = 'GRP-000008';

    $bookingA = mvqScheduledBooking($customer, $truckA, $groupCode);
    $bookingB = mvqScheduledBooking($customer, $truckB, $groupCode);

    mvqDraft($dispatcher, $bookingA, 1900)->assertOk();
    mvqDraft($dispatcher, $bookingB, 1100)->assertOk();

    $draftQuotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();

    test()->actingAs($dispatcher)->patch(route('admin.quotations.update-price', $draftQuotation), [
        'new_price' => (string) round((float) $draftQuotation->estimated_price - 100, 2),
        'discount' => '100',
        'note' => 'Multi-vehicle service discount',
    ])->assertOk();

    $draftQuotation = Quotation::where('quotation_number', $draftQuotation->quotation_number)->current()->first();
    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $draftQuotation))->assertOk();

    $sentQuotation = Quotation::where('quotation_number', $draftQuotation->quotation_number)->current()->first();
    expect($sentQuotation->status)->toBe('sent');
    expect((float) $sentQuotation->discount)->toBe(100.0);

    $bookingA->refresh();
    $bookingB->refresh();
    expect($bookingA->status)->toBe('quotation_sent');
    expect($bookingB->status)->toBe('quotation_sent');
    $bookingAFinalTotalBefore = (float) $bookingA->final_total;

    $response = test()->actingAs($dispatcher)->post(route('admin.booking.assign', $bookingB), [
        'action' => 'reject',
        'rejection_reason' => 'Customer no longer needs this vehicle towed',
    ]);

    $response->assertOk();

    $bookingB->refresh();
    $bookingA->refresh();

    expect($bookingB->status)->toBe('cancelled');
    expect($bookingA->status)->toBe('quotation_sent');
    expect((float) $bookingA->final_total)->toBe($bookingAFinalTotalBefore);

    $oldQuotation = Quotation::where('id', $sentQuotation->id)->first();
    expect($oldQuotation->is_current)->toBeFalse();
    expect($oldQuotation->status)->toBe('sent');

    $newQuotation = Quotation::where('quotation_number', $sentQuotation->quotation_number)->current()->first();
    expect($newQuotation->id)->not->toBe($oldQuotation->id);
    expect($newQuotation->status)->toBe('draft');
    expect($newQuotation->version)->toBe($oldQuotation->version + 1);
    expect($newQuotation->extra_vehicles)->toHaveCount(1);
    expect(collect($newQuotation->extra_vehicles)->pluck('booking_id')->all())->toBe([$bookingA->id]);
    expect((float) $newQuotation->discount)->toBe(100.0);

    $expectedA = round(1500 + (12.0 - 4.0) * 60, 2);
    $expectedAWithVat = round($expectedA * 1.12, 2);
    expect((float) $newQuotation->estimated_price)->toBe(round($expectedAWithVat - 100.0, 2));
});

it('requires a reason to remove a vehicle from an already-sent group quotation', function () {
    Mail::fake();
    $dispatcher = mvqDispatcher();
    $customer = mvqCustomer();
    $truckA = mvqTruckType(1500, 60);
    $truckB = mvqTruckType(900, 40);
    $groupCode = 'GRP-000009';

    $bookingA = mvqScheduledBooking($customer, $truckA, $groupCode);
    $bookingB = mvqScheduledBooking($customer, $truckB, $groupCode);

    mvqDraft($dispatcher, $bookingA, 1900)->assertOk();
    mvqDraft($dispatcher, $bookingB, 1100)->assertOk();

    $draftQuotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $draftQuotation))->assertOk();

    $response = test()->actingAs($dispatcher)->post(route('admin.booking.assign', $bookingB), [
        'action' => 'reject',
    ]);

    $response->assertStatus(422);
    expect($bookingB->fresh()->status)->toBe('quotation_sent');

    $quotation = Quotation::where('quotation_number', $draftQuotation->quotation_number)->current()->first();
    expect($quotation->status)->toBe('sent');
    expect($quotation->version)->toBe(1);
});

it('exposes each grouped vehicle own base rate, distance fee, VAT and total in the quotation details response', function () {
    Mail::fake();
    $dispatcher = mvqDispatcher();
    $customer = mvqCustomer();
    $truckA = mvqTruckType(1500, 60);
    $truckB = mvqTruckType(900, 40);
    $groupCode = 'GRP-000010';

    $bookingA = mvqScheduledBooking($customer, $truckA, $groupCode);
    $bookingB = mvqScheduledBooking($customer, $truckB, $groupCode);

    mvqDraft($dispatcher, $bookingA, 1900)->assertOk();
    mvqDraft($dispatcher, $bookingB, 1100)->assertOk();

    $quotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();

    $response = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation));

    $response->assertOk();
    $payload = $response->json('quotation');
    expect($payload['extra_vehicles'])->toHaveCount(2);

    foreach ($payload['extra_vehicles'] as $ev) {
        expect($ev)->toHaveKeys(['booking_id', 'truck_type_name', 'base_rate', 'distance_fee', 'vat_amount', 'final_total']);
        expect($ev['base_rate'])->not->toBeNull();
        expect($ev['distance_fee'])->not->toBeNull();
        expect($ev['vat_amount'])->not->toBeNull();
        expect($ev['final_total'])->not->toBeNull();
    }

    $lineItemFor = fn($bookingId) => collect($payload['extra_vehicles'])->firstWhere('booking_id', $bookingId);
    expect((float) $lineItemFor($bookingA->id)['final_total'])->not->toBe((float) $lineItemFor($bookingB->id)['final_total']);
    expect((float) $payload['estimated_price'])->toBe(
        round((float) $lineItemFor($bookingA->id)['final_total'] + (float) $lineItemFor($bookingB->id)['final_total'], 2)
    );
});
