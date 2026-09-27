<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function bdbRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function bdbDispatcher(): User
{
    return User::factory()->create(['role_id' => bdbRole(2, 'Dispatcher')->id, 'status' => 'active', 'must_change_password' => false]);
}

function bdbCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'BDB Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'bdb-' . uniqid() . '@example.com',
    ]);
}

function bdbTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'BDB Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function bdbBooking(TruckType $truckType, Customer $customer, array $overrides = []): Booking
{
    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Origin',
        'dropoff_address' => 'Destination',
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 2016,
        'service_type' => 'book_now',
        'status' => 'requested',
    ], $overrides));
}

function bdbQuotation(TruckType $truckType, Customer $customer, Booking $sourceBooking, array $overrides = []): Quotation
{
    return Quotation::create(array_merge([
        'quotation_number' => 'QT-BDB-' . uniqid(),
        'source_booking_id' => $sourceBooking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $sourceBooking->pickup_address,
        'dropoff_address' => $sourceBooking->dropoff_address,
        'distance_km' => $sourceBooking->distance_km,
        'estimated_price' => 2016,
        'status' => 'sent',
        'is_current' => true,
    ], $overrides));
}

function bdbInvoice(Booking $booking, array $overrides = []): Invoice
{
    return Invoice::create(array_merge([
        'booking_id' => $booking->id,
        'subtotal' => 1800,
        'additional_fee' => 0,
        'discount' => 0,
        'total' => 2016,
        'status' => 'issued',
        'is_current' => true,
    ], $overrides));
}

it('returns the current quotation for an Immediate booking', function () {
    $customer = bdbCustomer();
    $truckType = bdbTruckType();
    $booking = bdbBooking($truckType, $customer, ['service_type' => 'book_now', 'status' => 'quotation_sent']);
    $quotation = bdbQuotation($truckType, $customer, $booking);

    $dispatcher = bdbDispatcher();
    $response = test()->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $booking))->assertOk();

    expect($response->json('booking.is_scheduled'))->toBeFalse();
    expect($response->json('quotation.id'))->toBe($quotation->id);
    expect($response->json('quotation.status'))->toBe('sent');
    expect($response->json('invoice'))->toBeNull();
    expect($response->json('receipt'))->toBeNull();
});

it('returns the current quotation for a Scheduled booking after draft/send', function () {
    $customer = bdbCustomer();
    $truckType = bdbTruckType();
    $booking = bdbBooking($truckType, $customer, [
        'service_type' => 'schedule',
        'status' => 'quotation_sent',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
    ]);
    $quotation = bdbQuotation($truckType, $customer, $booking, ['service_type' => 'schedule']);

    $dispatcher = bdbDispatcher();
    $response = test()->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $booking))->assertOk();

    expect($response->json('booking.is_scheduled'))->toBeTrue();
    expect($response->json('quotation.id'))->toBe($quotation->id);
});

it('returns a clean invoice=null and receipt=null bundle before any invoice exists', function () {
    $customer = bdbCustomer();
    $truckType = bdbTruckType();
    $booking = bdbBooking($truckType, $customer, ['status' => 'confirmed']);

    $dispatcher = bdbDispatcher();
    $response = test()->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $booking))->assertOk();

    expect($response->json('invoice'))->toBeNull();
    expect($response->json('receipt'))->toBeNull();
});

it('returns the current invoice once a booking reaches arrived_dropoff', function () {
    $customer = bdbCustomer();
    $truckType = bdbTruckType();
    $booking = bdbBooking($truckType, $customer, ['status' => 'arrived_dropoff']);
    $invoice = bdbInvoice($booking);

    $dispatcher = bdbDispatcher();
    $response = test()->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $booking))->assertOk();

    expect($response->json('invoice.id'))->toBe($invoice->id);
    expect($response->json('invoice.status'))->toBe('issued');
    expect($response->json('invoice.is_current'))->toBeTrue();
    expect($response->json('receipt'))->toBeNull();
});

it('returns both invoice and receipt for a completed booking', function () {
    $customer = bdbCustomer();
    $truckType = bdbTruckType();
    $booking = bdbBooking($truckType, $customer, ['status' => 'completed']);
    $invoice = bdbInvoice($booking);
    $generatedBy = bdbDispatcher();
    $receipt = Receipt::create([
        'booking_id' => $booking->id,
        'invoice_id' => $invoice->id,
        'generated_by' => $generatedBy->id,
        'receipt_number' => 'R-BDB-' . uniqid(),
    ]);

    $dispatcher = bdbDispatcher();
    $response = test()->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $booking))->assertOk();

    expect($response->json('invoice.id'))->toBe($invoice->id);
    expect($response->json('receipt.id'))->toBe($receipt->id);
    expect($response->json('receipt.invoice_id'))->toBe($invoice->id);
});

it('returns a normalized multi-vehicle roster for a grouped booking, resolved from any sibling', function () {
    $customer = bdbCustomer();
    $truckType = bdbTruckType();
    $groupCode = 'GRP-BDB-' . uniqid();

    $anchor = bdbBooking($truckType, $customer, ['group_code' => $groupCode, 'status' => 'confirmed']);
    $sibling = bdbBooking($truckType, $customer, ['group_code' => $groupCode, 'status' => 'confirmed']);

    $quotation = bdbQuotation($truckType, $customer, $anchor);

    $dispatcher = bdbDispatcher();

    $responseFromSibling = test()->actingAs($dispatcher)
        ->getJson(route('admin.booking.detail-bundle', $sibling))
        ->assertOk();

    $vehicleIds = collect($responseFromSibling->json('vehicles'))->pluck('booking_id')->sort()->values()->all();
    expect($vehicleIds)->toBe(collect([$anchor->id, $sibling->id])->sort()->values()->all());
    expect($responseFromSibling->json('quotation.id'))->toBe($quotation->id);
});

it('returns one consolidated invoice for a grouped booking regardless of which sibling is queried', function () {
    $customer = bdbCustomer();
    $truckType = bdbTruckType();
    $groupCode = 'GRP-BDB-' . uniqid();

    $anchor = bdbBooking($truckType, $customer, ['group_code' => $groupCode, 'status' => 'arrived_dropoff']);
    $sibling = bdbBooking($truckType, $customer, ['group_code' => $groupCode, 'status' => 'arrived_dropoff']);
    $invoice = bdbInvoice($anchor, ['total' => 4032]);

    expect(Invoice::count())->toBe(1);

    $dispatcher = bdbDispatcher();
    $response = test()->actingAs($dispatcher)
        ->getJson(route('admin.booking.detail-bundle', $sibling))
        ->assertOk();

    expect($response->json('invoice.id'))->toBe($invoice->id);
    expect((float) $response->json('invoice.total'))->toBe(4032.0);
});

it('resolves the current quotation via source_booking_id, not a stale booking.quotation_id', function () {
    $customer = bdbCustomer();
    $truckType = bdbTruckType();
    $booking = bdbBooking($truckType, $customer, ['status' => 'quotation_sent']);

    $abandonedQuotation = bdbQuotation($truckType, $customer, $booking, [
        'quotation_number' => 'QT-BDB-abandoned-' . uniqid(),
        'status' => 'pending',
        'is_current' => false,
    ]);
    $booking->update(['quotation_id' => $abandonedQuotation->id]);

    $realQuotation = bdbQuotation($truckType, $customer, $booking, [
        'quotation_number' => 'QT-BDB-real-' . uniqid(),
        'status' => 'sent',
        'is_current' => true,
    ]);

    expect($booking->fresh()->quotation_id)->toBe($abandonedQuotation->id);

    $dispatcher = bdbDispatcher();
    $response = test()->actingAs($dispatcher)
        ->getJson(route('admin.booking.detail-bundle', $booking))
        ->assertOk();

    expect($response->json('quotation.id'))->toBe($realQuotation->id);
    expect($response->json('quotation.id'))->not->toBe($abandonedQuotation->id);
});
