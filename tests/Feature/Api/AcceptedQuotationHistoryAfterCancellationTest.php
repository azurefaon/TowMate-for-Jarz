<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function aqhRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function aqhDispatcher(): User
{
    return User::factory()->create(['role_id' => aqhRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function aqhCustomer(): array
{
    $user = User::factory()->create(['role_id' => aqhRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function aqhTruckType(float $baseRate, float $perKmRate): TruckType
{
    return TruckType::create([
        'name' => 'AQH Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function aqhConfirmedBooking(Customer $customer, TruckType $truckType, string $groupCode, float $finalTotal): Booking
{
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => $finalTotal,
        'status' => 'confirmed',
        'service_type' => 'book_now',
        'customer_approved_at' => now(),
        'price_locked_at' => now(),
    ]);

    return $booking->fresh(['customer', 'truckType']);
}

function aqhAcceptedGroupQuotation(
    Customer $customer,
    TruckType $truckType,
    array $bookings,
    array $lineItemTotals,
    float $additionalFee,
    float $discount,
    float $estimatedPrice
): Quotation {
    $extraVehicles = collect($bookings)->slice(1)->values()->map(fn (Booking $b, int $i) => [
        'booking_id' => $b->id,
        'truck_type_id' => $truckType->id,
        'base_rate' => $truckType->base_rate,
        'distance_fee' => 100,
        'vat_amount' => 50,
        'final_total' => $lineItemTotals[$i + 1],
    ])->values()->all();

    $quotation = Quotation::create([
        'quotation_number' => 'QT-AQH-' . uniqid(),
        'source_booking_id' => $bookings[0]->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => $estimatedPrice,
        'additional_fee' => $additionalFee,
        'discount' => $discount,
        'service_type' => 'book_now',
        'status' => 'accepted',
        'extra_vehicles' => $extraVehicles,
    ]);

    foreach ($bookings as $b) {
        $b->update(['quotation_id' => $quotation->id]);
    }

    return $quotation;
}

it('preserves the accepted quotation, per-vehicle prices, adjustments and total after partially cancelling the anchor vehicle', function () {
    [$user, $customer] = aqhCustomer();
    $dispatcher = aqhDispatcher();
    $truckType = aqhTruckType(1500, 60);
    $groupCode = 'AQH-GROUP-001';

    $bookingA = aqhConfirmedBooking($customer, $truckType, $groupCode, 2217.60);
    $bookingB = aqhConfirmedBooking($customer, $truckType, $groupCode, 1366.40);
    $bookingC = aqhConfirmedBooking($customer, $truckType, $groupCode, 1366.40);
    $quotation = aqhAcceptedGroupQuotation(
        $customer,
        $truckType,
        [$bookingA, $bookingB, $bookingC],
        [2217.60, 1366.40, 1366.40],
        additionalFee: 200,
        discount: 100,
        estimatedPrice: 5050.40,
    );

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'reject',
        'rejection_reason' => 'Customer changed their mind about this vehicle',
    ])->assertOk();

    $quotation->refresh();
    expect($quotation->status)->toBe('accepted');
    expect($quotation->is_current)->toBeTrue();
    expect((float) $quotation->estimated_price)->toBe(5050.40);
    expect((float) $quotation->additional_fee)->toBe(200.0);
    expect((float) $quotation->discount)->toBe(100.0);
    expect($quotation->extra_vehicles)->toHaveCount(2);
    expect((float) collect($quotation->extra_vehicles)->firstWhere('booking_id', $bookingB->id)['final_total'])->toBe(1366.40);
    expect((float) collect($quotation->extra_vehicles)->firstWhere('booking_id', $bookingC->id)['final_total'])->toBe(1366.40);
    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(1);

    Sanctum::actingAs($user, ['*']);

    $historyBefore = test()->getJson('/api/v1/bookings/history');
    $historyBefore->assertOk();
    $rows = collect($historyBefore->json('data'));
    $rowA = $rows->firstWhere('booking_code', $bookingA->booking_code);
    $rowB = $rows->firstWhere('booking_code', $bookingB->booking_code);
    $rowC = $rows->firstWhere('booking_code', $bookingC->booking_code);

    expect($rowA['status'])->toBe('cancelled');
    expect($rowB['status'])->toBe('confirmed');
    expect($rowC['status'])->toBe('confirmed');
    expect($rowA['quotation_number'])->toBe($quotation->quotation_number);
    expect($rowB['quotation_number'])->toBe($quotation->quotation_number);
    expect($rowC['quotation_number'])->toBe($quotation->quotation_number);
    expect($rowA['group_booking_code'])->toBe($bookingA->booking_code);
    expect($rowB['group_booking_code'])->toBe($bookingA->booking_code);
    expect($rowC['group_booking_code'])->toBe($bookingA->booking_code);

    $detailA = test()->getJson("/api/v1/bookings/{$bookingA->booking_code}/detail");
    $detailA->assertOk();
    expect($detailA->json('data.status'))->toBe('cancelled');
    expect($detailA->json('data.quotation_number'))->toBe($quotation->quotation_number);
    expect($detailA->json('data.group_booking_code'))->toBe($bookingA->booking_code);

    $detailB = test()->getJson("/api/v1/bookings/{$bookingB->booking_code}/detail");
    $detailB->assertOk();
    expect($detailB->json('data.quotation_number'))->toBe($quotation->quotation_number);
    expect($detailB->json('data.group_booking_code'))->toBe($bookingA->booking_code);
});

it('preserves the accepted quotation, per-vehicle prices, adjustments and total after full-group cancellation', function () {
    [$user, $customer] = aqhCustomer();
    $dispatcher = aqhDispatcher();
    $truckType = aqhTruckType(1500, 60);
    $groupCode = 'AQH-GROUP-002';

    $bookingA = aqhConfirmedBooking($customer, $truckType, $groupCode, 2217.60);
    $bookingB = aqhConfirmedBooking($customer, $truckType, $groupCode, 1366.40);
    $quotation = aqhAcceptedGroupQuotation(
        $customer,
        $truckType,
        [$bookingA, $bookingB],
        [2217.60, 1366.40],
        additionalFee: 150,
        discount: 50,
        estimatedPrice: 3684.00,
    );

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'reject',
        'rejection_reason' => 'Full cancellation requested',
    ])->assertOk();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'reject',
        'rejection_reason' => 'Full cancellation requested',
    ])->assertOk();

    $quotation->refresh();
    expect($quotation->status)->toBe('accepted');
    expect($quotation->is_current)->toBeTrue();
    expect((float) $quotation->estimated_price)->toBe(3684.00);
    expect((float) $quotation->additional_fee)->toBe(150.0);
    expect((float) $quotation->discount)->toBe(50.0);
    expect((float) collect($quotation->extra_vehicles)->firstWhere('booking_id', $bookingB->id)['final_total'])->toBe(1366.40);
    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(1);

    Sanctum::actingAs($user, ['*']);

    $history = test()->getJson('/api/v1/bookings/history');
    $history->assertOk();
    $rows = collect($history->json('data'));
    $rowA = $rows->firstWhere('booking_code', $bookingA->booking_code);
    $rowB = $rows->firstWhere('booking_code', $bookingB->booking_code);

    expect($rowA['status'])->toBe('cancelled');
    expect($rowB['status'])->toBe('cancelled');
    expect($rowA['quotation_number'])->toBe($quotation->quotation_number);
    expect($rowB['quotation_number'])->toBe($quotation->quotation_number);

    $detailB = test()->getJson("/api/v1/bookings/{$bookingB->booking_code}/detail");
    $detailB->assertOk();
    expect($detailB->json('data.status'))->toBe('cancelled');
    expect($detailB->json('data.quotation_number'))->toBe($quotation->quotation_number);
});
