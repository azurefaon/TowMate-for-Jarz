<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function cgraTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'CGRA Truck ' . fake()->unique()->word(),
        'base_rate' => 2500,
        'per_km_rate' => 300,
        'status' => 'active',
    ]);
}

function cgraGroupedBooking(Customer $customer, TruckType $truckType, string $groupCode): Booking
{
    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'confirmed',
        'service_type' => 'book_now',
    ]);
}

function cgraCustomerWithUser(): array
{
    $role = Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });

    $user = User::factory()->create(['role_id' => $role->id]);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => 'CGRA Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'cgra-' . uniqid() . '@example.com',
    ]);

    return [$user, $customer];
}

function cgraReceiptForBooking(Booking $booking, array $overrides = []): Receipt
{
    $invoice = Invoice::create(array_merge([
        'booking_id' => $booking->id,
        'subtotal' => 4159.00,
        'additional_fee' => 0,
        'discount' => 0,
        'total' => 4658.08,
        'status' => 'issued',
        'is_current' => true,
    ], $overrides['invoice'] ?? []));

    return Receipt::create(array_merge([
        'booking_id' => $booking->id,
        'invoice_id' => $invoice->id,
        'generated_by' => User::factory()->create()->id,
        'receipt_number' => 'R-CGRA-' . uniqid(),
        'pdf_path' => 'receipts/cgra-' . uniqid() . '.pdf',
    ], $overrides['receipt'] ?? []));
}

it('lets the anchor booking fetch its own direct receipt', function () {
    [$user, $customer] = cgraCustomerWithUser();
    $truckType = cgraTruckType();
    $groupCode = 'CGRA-GROUP-' . uniqid();

    $anchor = cgraGroupedBooking($customer, $truckType, $groupCode);
    $anchor->update(['status' => 'completed']);
    $receipt = cgraReceiptForBooking($anchor);

    Sanctum::actingAs($user, ['*']);
    $response = test()->getJson('/api/v1/bookings/' . $anchor->booking_code . '/receipt');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.receipt_number', $receipt->receipt_number);
});

it('lets a sibling booking fetch the group anchor receipt', function () {
    [$user, $customer] = cgraCustomerWithUser();
    $truckType = cgraTruckType();
    $groupCode = 'CGRA-GROUP-' . uniqid();

    $anchor = cgraGroupedBooking($customer, $truckType, $groupCode);
    $sibling = cgraGroupedBooking($customer, $truckType, $groupCode);
    $anchor->update(['status' => 'completed']);
    $sibling->update(['status' => 'completed']);
    $receipt = cgraReceiptForBooking($anchor);

    Sanctum::actingAs($user, ['*']);
    $response = test()->getJson('/api/v1/bookings/' . $sibling->booking_code . '/receipt');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.receipt_number', $receipt->receipt_number);

    expect(Receipt::where('booking_id', $sibling->id)->count())->toBe(0);
});

it('lets a third sibling in the same group fetch the same group receipt', function () {
    [$user, $customer] = cgraCustomerWithUser();
    $truckType = cgraTruckType();
    $groupCode = 'CGRA-GROUP-' . uniqid();

    $anchor = cgraGroupedBooking($customer, $truckType, $groupCode);
    $siblingB = cgraGroupedBooking($customer, $truckType, $groupCode);
    $siblingC = cgraGroupedBooking($customer, $truckType, $groupCode);
    foreach ([$anchor, $siblingB, $siblingC] as $b) {
        $b->update(['status' => 'completed']);
    }
    $receipt = cgraReceiptForBooking($anchor);

    Sanctum::actingAs($user, ['*']);
    $responseB = test()->getJson('/api/v1/bookings/' . $siblingB->booking_code . '/receipt')->assertOk();
    $responseC = test()->getJson('/api/v1/bookings/' . $siblingC->booking_code . '/receipt')->assertOk();

    expect($responseB->json('data.receipt_number'))->toBe($receipt->receipt_number);
    expect($responseC->json('data.receipt_number'))->toBe($receipt->receipt_number);
});

it('keeps single-booking receipt access unchanged', function () {
    [$user, $customer] = cgraCustomerWithUser();
    $truckType = cgraTruckType();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'completed',
        'service_type' => 'book_now',
    ]);
    $receipt = cgraReceiptForBooking($booking);

    Sanctum::actingAs($user, ['*']);
    $response = test()->getJson('/api/v1/bookings/' . $booking->booking_code . '/receipt');

    $response->assertOk()->assertJsonPath('data.receipt_number', $receipt->receipt_number);
});

it('returns not found for a single booking with no receipt and no group_code', function () {
    [$user, $customer] = cgraCustomerWithUser();
    $truckType = cgraTruckType();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'completed',
        'service_type' => 'book_now',
    ]);

    Sanctum::actingAs($user, ['*']);
    test()->getJson('/api/v1/bookings/' . $booking->booking_code . '/receipt')->assertNotFound();
});

it('returns not found for a sibling whose group has no receipt yet', function () {
    [$user, $customer] = cgraCustomerWithUser();
    $truckType = cgraTruckType();
    $groupCode = 'CGRA-GROUP-' . uniqid();

    $anchor = cgraGroupedBooking($customer, $truckType, $groupCode);
    $sibling = cgraGroupedBooking($customer, $truckType, $groupCode);
    $anchor->update(['status' => 'completed']);
    $sibling->update(['status' => 'completed']);

    Sanctum::actingAs($user, ['*']);
    test()->getJson('/api/v1/bookings/' . $sibling->booking_code . '/receipt')->assertNotFound();
});

it('does not let another customer fetch the group receipt through a sibling booking', function () {
    [$owner, $ownerCustomer] = cgraCustomerWithUser();
    [$intruder, ] = cgraCustomerWithUser();
    $truckType = cgraTruckType();
    $groupCode = 'CGRA-GROUP-' . uniqid();

    $anchor = cgraGroupedBooking($ownerCustomer, $truckType, $groupCode);
    $sibling = cgraGroupedBooking($ownerCustomer, $truckType, $groupCode);
    $anchor->update(['status' => 'completed']);
    $sibling->update(['status' => 'completed']);
    cgraReceiptForBooking($anchor);

    Sanctum::actingAs($intruder, ['*']);
    test()->getJson('/api/v1/bookings/' . $sibling->booking_code . '/receipt')->assertNotFound();
});

it('fails closed when the group anchor belongs to a different customer due to inconsistent data', function () {
    [$user, $customer] = cgraCustomerWithUser();
    [, $otherCustomer] = cgraCustomerWithUser();
    $truckType = cgraTruckType();
    $groupCode = 'CGRA-GROUP-' . uniqid();

    $anchor = cgraGroupedBooking($otherCustomer, $truckType, $groupCode);
    $sibling = cgraGroupedBooking($customer, $truckType, $groupCode);
    $anchor->update(['status' => 'completed']);
    $sibling->update(['status' => 'completed']);
    cgraReceiptForBooking($anchor);

    Sanctum::actingAs($user, ['*']);
    test()->getJson('/api/v1/bookings/' . $sibling->booking_code . '/receipt')->assertNotFound();
});

it('never creates a duplicate receipt row when a sibling resolves the group receipt', function () {
    [$user, $customer] = cgraCustomerWithUser();
    $truckType = cgraTruckType();
    $groupCode = 'CGRA-GROUP-' . uniqid();

    $anchor = cgraGroupedBooking($customer, $truckType, $groupCode);
    $sibling = cgraGroupedBooking($customer, $truckType, $groupCode);
    $anchor->update(['status' => 'completed']);
    $sibling->update(['status' => 'completed']);
    cgraReceiptForBooking($anchor);

    Sanctum::actingAs($user, ['*']);
    test()->getJson('/api/v1/bookings/' . $sibling->booking_code . '/receipt')->assertOk();
    test()->getJson('/api/v1/bookings/' . $sibling->booking_code . '/receipt')->assertOk();

    expect(Receipt::count())->toBe(1);
});

it('does not resolve a sibling receipt from an unrelated group with a different group_code', function () {
    [$user, $customer] = cgraCustomerWithUser();
    $truckType = cgraTruckType();

    $groupOne = 'CGRA-GROUP-ONE-' . uniqid();
    $anchorOne = cgraGroupedBooking($customer, $truckType, $groupOne);
    $siblingOne = cgraGroupedBooking($customer, $truckType, $groupOne);
    $anchorOne->update(['status' => 'completed']);
    $siblingOne->update(['status' => 'completed']);

    $groupTwo = 'CGRA-GROUP-TWO-' . uniqid();
    $anchorTwo = cgraGroupedBooking($customer, $truckType, $groupTwo);
    $anchorTwo->update(['status' => 'completed']);
    $unrelatedReceipt = cgraReceiptForBooking($anchorTwo);

    Sanctum::actingAs($user, ['*']);
    test()->getJson('/api/v1/bookings/' . $siblingOne->booking_code . '/receipt')->assertNotFound();

    expect(Receipt::count())->toBe(1);
    expect($unrelatedReceipt->fresh()->booking_id)->toBe($anchorTwo->id);
});
