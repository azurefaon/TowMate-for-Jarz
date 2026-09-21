<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\TruckType;
use App\Models\Unit;
use App\Services\ReportMetricsService;

function rmgCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'RMG Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'rmg-' . uniqid() . '@example.com',
    ]);
}

function rmgTruckType(string $name): TruckType
{
    return TruckType::create([
        'name' => $name . ' ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function rmgUnit(TruckType $truckType, string $label): Unit
{
    return Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);
}

function rmgGroupedBooking(Customer $customer, TruckType $truckType, Unit $unit, string $groupCode, float $finalTotal, string $status = 'completed'): Booking
{
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => $finalTotal,
        'status' => $status,
        'service_type' => 'book_now',
        'completed_at' => $status === 'completed' ? now() : null,
    ]);

    return $booking->fresh();
}

function rmgAcceptedQuotation(Customer $customer, array $bookings, float $additionalFee = 0, float $discount = 0): Quotation
{
    $extraVehicles = collect($bookings)->slice(1)->map(fn (Booking $b) => [
        'booking_id' => $b->id,
        'truck_type_id' => $b->truck_type_id,
        'final_total' => (float) $b->final_total,
    ])->values()->all();

    $quotation = Quotation::create([
        'quotation_number' => 'QT-RMG-' . uniqid(),
        'source_booking_id' => $bookings[0]->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $bookings[0]->truck_type_id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => collect($bookings)->sum(fn (Booking $b) => (float) $b->final_total) + $additionalFee - $discount,
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

function rmgService(): ReportMetricsService
{
    return app(ReportMetricsService::class);
}

it('sums grouped booking revenue with the quotation-level adjustment applied exactly once, not per vehicle', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $customer = rmgCustomer();
    $truckA = rmgTruckType('RMG Light');
    $truckB = rmgTruckType('RMG Heavy');
    $unitA = rmgUnit($truckA, 'RMG Unit A');
    $unitB = rmgUnit($truckB, 'RMG Unit B');
    $groupCode = 'RMG-GROUP-1';

    $bookingA = rmgGroupedBooking($customer, $truckA, $unitA, $groupCode, 2000.0);
    $bookingB = rmgGroupedBooking($customer, $truckB, $unitB, $groupCode, 3000.0);
    rmgAcceptedQuotation($customer, [$bookingA, $bookingB], additionalFee: 500.0);

    $result = rmgService()->averageRevenuePerJob($start, $end);

    expect($result['completed_jobs'])->toBe(2);
    expect($result['revenue'])->toBe(5500.0);
});

it('allocates the group adjustment proportionally to each active vehicle service total for per-truck-type revenue', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $customer = rmgCustomer();
    $truckA = rmgTruckType('RMG Proportional Light');
    $truckB = rmgTruckType('RMG Proportional Heavy');
    $unitA = rmgUnit($truckA, 'RMG Prop Unit A');
    $unitB = rmgUnit($truckB, 'RMG Prop Unit B');
    $groupCode = 'RMG-GROUP-2';

    $bookingA = rmgGroupedBooking($customer, $truckA, $unitA, $groupCode, 2000.0);
    $bookingB = rmgGroupedBooking($customer, $truckB, $unitB, $groupCode, 3000.0);
    rmgAcceptedQuotation($customer, [$bookingA, $bookingB], additionalFee: 500.0);

    $result = rmgService()->revenueByTruckType($start, $end)->keyBy('truck_type_name');

    expect($result[$truckA->name]['revenue'])->toBe(2200.0);
    expect($result[$truckB->name]['revenue'])->toBe(3300.0);
    expect(round($result[$truckA->name]['revenue'] + $result[$truckB->name]['revenue'], 2))->toBe(5500.0);
});

it('allocates the group discount proportionally to unit performance revenue', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $customer = rmgCustomer();
    $truckA = rmgTruckType('RMG Discount Light');
    $truckB = rmgTruckType('RMG Discount Heavy');
    $unitA = rmgUnit($truckA, 'RMG Discount Unit A');
    $unitB = rmgUnit($truckB, 'RMG Discount Unit B');
    $groupCode = 'RMG-GROUP-3';

    $bookingA = rmgGroupedBooking($customer, $truckA, $unitA, $groupCode, 2000.0);
    $bookingB = rmgGroupedBooking($customer, $truckB, $unitB, $groupCode, 3000.0);
    rmgAcceptedQuotation($customer, [$bookingA, $bookingB], discount: 250.0);

    $result = rmgService()->unitPerformance($start, $end)->keyBy('unit_name');

    expect($result[$unitA->name]['revenue'])->toBe(1900.0);
    expect($result[$unitB->name]['revenue'])->toBe(2850.0);
    expect(round($result[$unitA->name]['revenue'] + $result[$unitB->name]['revenue'], 2))->toBe(4750.0);
});

it('excludes a cancelled vehicle from grouped revenue and reallocates the adjustment across the remaining active vehicles', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $customer = rmgCustomer();
    $truckA = rmgTruckType('RMG Cancel Light');
    $truckB = rmgTruckType('RMG Cancel Heavy');
    $truckC = rmgTruckType('RMG Cancel Extra');
    $unitA = rmgUnit($truckA, 'RMG Cancel Unit A');
    $unitB = rmgUnit($truckB, 'RMG Cancel Unit B');
    $unitC = rmgUnit($truckC, 'RMG Cancel Unit C');
    $groupCode = 'RMG-GROUP-4';

    $bookingA = rmgGroupedBooking($customer, $truckA, $unitA, $groupCode, 2000.0);
    $bookingB = rmgGroupedBooking($customer, $truckB, $unitB, $groupCode, 3000.0);
    $bookingC = rmgGroupedBooking($customer, $truckC, $unitC, $groupCode, 1000.0, status: 'cancelled');
    rmgAcceptedQuotation($customer, [$bookingA, $bookingB, $bookingC], additionalFee: 500.0);

    $result = rmgService()->averageRevenuePerJob($start, $end);

    expect($result['completed_jobs'])->toBe(2);
    expect($result['revenue'])->toBe(5500.0);

    $byTruckType = rmgService()->revenueByTruckType($start, $end)->keyBy('truck_type_name');
    expect($byTruckType->has($truckC->name))->toBeFalse();
    expect($byTruckType[$truckA->name]['revenue'])->toBe(2200.0);
    expect($byTruckType[$truckB->name]['revenue'])->toBe(3300.0);
});

it('keeps solo booking revenue exactly as its own final_total when not part of a normalized group', function () {
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $customer = rmgCustomer();
    $truck = rmgTruckType('RMG Solo');
    $unit = rmgUnit($truck, 'RMG Solo Unit');

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truck->base_rate,
        'per_km_rate' => $truck->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'completed',
        'service_type' => 'book_now',
        'completed_at' => now(),
    ]);

    $quotation = Quotation::create([
        'quotation_number' => 'QT-RMG-SOLO-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => 4658.08,
        'additional_fee' => 300.0,
        'service_type' => 'book_now',
        'status' => 'accepted',
    ]);
    $booking->update(['quotation_id' => $quotation->id]);

    $result = rmgService()->averageRevenuePerJob($start, $end);

    expect($result['completed_jobs'])->toBe(1);
    expect($result['revenue'])->toBe(4658.08);
});
