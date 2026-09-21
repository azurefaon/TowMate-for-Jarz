<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function pvcDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function pvcCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'PVC Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'pvc-' . uniqid() . '@example.com',
    ]);
}

function pvcTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'PVC Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function pvcConfirmedBooking(Customer $customer, TruckType $truckType, string $groupCode): Booking
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
        'final_total' => 4658.08,
        'status' => 'confirmed',
        'service_type' => 'book_now',
        'customer_approved_at' => now(),
        'price_locked_at' => now(),
    ]);

    return $booking->fresh(['customer', 'truckType']);
}

function pvcAcceptedGroupQuotation(Customer $customer, TruckType $truckType, array $bookings): Quotation
{
    $extraVehicles = collect($bookings)->slice(1)->map(fn (Booking $b) => [
        'booking_id' => $b->id,
        'truck_type_id' => $truckType->id,
        'final_total' => 4658.08,
    ])->values()->all();

    $quotation = Quotation::create([
        'quotation_number' => 'QT-PVC-' . uniqid(),
        'source_booking_id' => $bookings[0]->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => 4658.08 * count($bookings),
        'service_type' => 'book_now',
        'status' => 'accepted',
        'extra_vehicles' => $extraVehicles,
    ]);

    foreach ($bookings as $b) {
        $b->update(['quotation_id' => $quotation->id]);
    }

    return $quotation;
}

it('cancels only the targeted vehicle in an accepted 3-vehicle group, leaving the others active and the shared quotation accepted', function () {
    $dispatcher = pvcDispatcher();
    $customer = pvcCustomer();
    $truckType = pvcTruckType();
    $groupCode = 'PVC-GROUP-001';

    $bookingA = pvcConfirmedBooking($customer, $truckType, $groupCode);
    $bookingB = pvcConfirmedBooking($customer, $truckType, $groupCode);
    $bookingC = pvcConfirmedBooking($customer, $truckType, $groupCode);
    $quotation = pvcAcceptedGroupQuotation($customer, $truckType, [$bookingA, $bookingB, $bookingC]);

    $response = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'reject',
        'rejection_reason' => 'Customer no longer needs this vehicle towed',
    ]);

    $response->assertOk()->assertJson(['success' => true]);

    $bookingA->refresh();
    $bookingB->refresh();
    $bookingC->refresh();
    $quotation->refresh();

    expect($bookingB->status)->toBe('cancelled');
    expect($bookingA->status)->toBe('confirmed');
    expect($bookingC->status)->toBe('confirmed');
    expect($quotation->status)->toBe('accepted');
    expect($quotation->is_current)->toBeTrue();
    expect((float) $quotation->estimated_price)->toBe(4658.08 * 3);

    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(1);
});

it('requires a reason to cancel one vehicle from an already-accepted group', function () {
    $dispatcher = pvcDispatcher();
    $customer = pvcCustomer();
    $truckType = pvcTruckType();
    $groupCode = 'PVC-GROUP-002';

    $bookingA = pvcConfirmedBooking($customer, $truckType, $groupCode);
    $bookingB = pvcConfirmedBooking($customer, $truckType, $groupCode);
    pvcAcceptedGroupQuotation($customer, $truckType, [$bookingA, $bookingB]);

    $response = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'reject',
    ]);

    $response->assertStatus(422)->assertJson(['success' => false]);
    expect($bookingB->fresh()->status)->toBe('confirmed');
});

it('cancels the parent request without creating a new Pending quotation when the last remaining vehicle in an accepted group is cancelled', function () {
    $dispatcher = pvcDispatcher();
    $customer = pvcCustomer();
    $truckType = pvcTruckType();
    $groupCode = 'PVC-GROUP-003';

    $bookingA = pvcConfirmedBooking($customer, $truckType, $groupCode);
    $bookingB = pvcConfirmedBooking($customer, $truckType, $groupCode);
    $quotation = pvcAcceptedGroupQuotation($customer, $truckType, [$bookingA, $bookingB]);

    $bookingB->update(['status' => 'cancelled']);

    $response = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'reject',
        'rejection_reason' => 'Customer changed their mind',
    ]);

    $response->assertOk()->assertJson(['success' => true]);

    $bookingA->refresh();
    $bookingB->refresh();
    $quotation->refresh();
    expect($bookingA->status)->toBe('cancelled');
    expect($bookingB->status)->toBe('cancelled');
    expect($quotation->status)->toBe('accepted');
    expect($quotation->is_current)->toBeTrue();
    expect((float) $quotation->estimated_price)->toBe(4658.08 * 2);
    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(1);
    expect(Quotation::where('customer_id', $customer->id)->where('status', 'pending')->count())->toBe(0);
});

it('leaves no cancelled vehicle reserving a unit or reachable through dispatch after full-group cancellation', function () {
    $dispatcher = pvcDispatcher();
    $customer = pvcCustomer();
    $truckType = pvcTruckType();
    $groupCode = 'PVC-GROUP-004';

    $bookingA = pvcConfirmedBooking($customer, $truckType, $groupCode);
    $bookingB = pvcConfirmedBooking($customer, $truckType, $groupCode);
    pvcAcceptedGroupQuotation($customer, $truckType, [$bookingA, $bookingB]);

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'reject',
        'rejection_reason' => 'Customer changed their mind',
    ])->assertOk();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'reject',
        'rejection_reason' => 'Customer changed their mind',
    ])->assertOk();

    expect($bookingA->fresh()->status)->toBe('cancelled');
    expect($bookingB->fresh()->status)->toBe('cancelled');
    expect(Booking::whereIn('id', [$bookingA->id, $bookingB->id])->whereIn('status', Booking::REVIEWABLE_STATUSES)->exists())->toBeFalse();
    expect(Booking::whereIn('id', [$bookingA->id, $bookingB->id])->unitReservations()->exists())->toBeFalse();
});

it('keeps standalone accepted-booking rejection re-quoting the customer as before', function () {
    $dispatcher = pvcDispatcher();
    $customer = pvcCustomer();
    $truckType = pvcTruckType();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'confirmed',
        'service_type' => 'book_now',
        'customer_approved_at' => now(),
        'price_locked_at' => now(),
    ]);

    $quotation = Quotation::create([
        'quotation_number' => 'QT-PVC-SOLO-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => 4658.08,
        'service_type' => 'book_now',
        'status' => 'accepted',
    ]);
    $booking->update(['quotation_id' => $quotation->id]);

    $response = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), [
        'action' => 'reject',
        'rejection_reason' => 'Truck unavailable',
    ]);

    $response->assertOk()->assertJson(['success' => true]);

    $booking->refresh();
    $quotation->refresh();
    expect($booking->status)->toBe('cancelled');
    expect($quotation->status)->toBe('accepted');
    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(2);
});
