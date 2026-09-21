<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

function pvpRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function pvpCustomer(): array
{
    $user = User::factory()->create(['role_id' => pvpRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function pvpTruckType(float $baseRate, float $perKmRate, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function pvpVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function pvpReadyUnit(TruckType $truckType, string $label): Unit
{
    $leader = User::factory()->create(['role_id' => pvpRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'PVP Driver',
    ]);
}

function pvpImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("pvp-vehicle-$i.jpg", 300, 300), range(1, $count));
}

function pvpExpected(float $baseRate, float $perKmRate, float $distanceKm): array
{
    $distanceFee = round(max(0, $distanceKm - 4) * $perKmRate, 2);
    $subtotal = round($baseRate + $distanceFee, 2);
    $vatAmount = round($subtotal * 0.12, 2);
    $finalTotal = round($subtotal + $vatAmount, 2);

    return compact('distanceFee', 'subtotal', 'vatAmount', 'finalTotal');
}

it('keeps each vehicle on its own base rate, distance fee, taxable subtotal, VAT and service total from submission through quotation acceptance', function () {
    [$user, $customer] = pvpCustomer();

    $truckLight = pvpTruckType(1500, 60, 'PVP Light Duty');
    pvpReadyUnit($truckLight, 'PVP Light Unit');
    $vehicleSedan = pvpVehicleType($truckLight->id, 'PVP Sedan');

    $truckMedium = pvpTruckType(2500, 100, 'PVP Medium Duty');
    pvpReadyUnit($truckMedium, 'PVP Medium Unit');
    $vehicleVan = pvpVehicleType($truckMedium->id, 'PVP Van');

    $truckHeavy = pvpTruckType(4000, 150, 'PVP Heavy Duty');
    pvpReadyUnit($truckHeavy, 'PVP Heavy Unit');
    $vehicleTruck = pvpVehicleType($truckHeavy->id, 'PVP Box Truck');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $vehicleSedan->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'book_now',
        'vehicle_images' => pvpImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleVan->id],
            ['vehicle_type_id' => $vehicleTruck->id],
        ]),
        'extra_vehicle_images' => [
            0 => pvpImages(1),
            1 => pvpImages(1),
        ],
    ]);

    $response->assertCreated();
    $groupCode = $response->json('group_code');

    $primary = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleSedan->id)->firstOrFail();
    $siblingVan = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleVan->id)->firstOrFail();
    $siblingTruck = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleTruck->id)->firstOrFail();

    $distanceKm = (float) $primary->distance_km;
    $expectedLight = pvpExpected(1500, 60, $distanceKm);
    $expectedMedium = pvpExpected(2500, 100, $distanceKm);
    $expectedHeavy = pvpExpected(4000, 150, $distanceKm);

    expect((float) $primary->base_rate)->toBe(1500.0);
    expect((float) $primary->final_total)->toBe($expectedLight['finalTotal']);

    $quotation = Quotation::find($primary->quotation_id);
    expect($quotation)->not->toBeNull();

    $lineItemFor = fn (int $bookingId) => collect($quotation->extra_vehicles)->firstWhere('booking_id', $bookingId);
    $vanLine = $lineItemFor($siblingVan->id);
    $truckLine = $lineItemFor($siblingTruck->id);

    expect((float) $vanLine['base_rate'])->toBe(2500.0);
    expect((float) $vanLine['distance_fee'])->toBe($expectedMedium['distanceFee']);
    expect((float) $vanLine['vat_amount'])->toBe($expectedMedium['vatAmount']);
    expect((float) $vanLine['final_total'])->toBe($expectedMedium['finalTotal']);

    expect((float) $truckLine['base_rate'])->toBe(4000.0);
    expect((float) $truckLine['distance_fee'])->toBe($expectedHeavy['distanceFee']);
    expect((float) $truckLine['vat_amount'])->toBe($expectedHeavy['vatAmount']);
    expect((float) $truckLine['final_total'])->toBe($expectedHeavy['finalTotal']);

    expect((float) $vanLine['base_rate'])->not->toBe((float) $truckLine['base_rate']);
    expect((float) $vanLine['final_total'])->not->toBe((float) $primary->final_total);
    expect((float) $truckLine['final_total'])->not->toBe((float) $vanLine['final_total']);

    $quotationService = app(\App\Services\QuotationService::class);
    $quotationService->sendQuotation($quotation, 168);
    $quotationService->acceptQuotation($quotation->fresh());

    $primary->refresh();
    $siblingVan->refresh();
    $siblingTruck->refresh();

    expect((float) $primary->base_rate)->toBe(1500.0);
    expect((float) $primary->vat_exclusive_total)->toBe($expectedLight['subtotal']);
    expect((float) $primary->vat_amount)->toBe($expectedLight['vatAmount']);
    expect((float) $primary->final_total)->toBe($expectedLight['finalTotal']);
    expect($primary->status)->toBe('confirmed');

    expect((float) $siblingVan->base_rate)->toBe(2500.0);
    expect((float) $siblingVan->vat_exclusive_total)->toBe($expectedMedium['subtotal']);
    expect((float) $siblingVan->vat_amount)->toBe($expectedMedium['vatAmount']);
    expect((float) $siblingVan->final_total)->toBe($expectedMedium['finalTotal']);
    expect($siblingVan->status)->toBe('confirmed');

    expect((float) $siblingTruck->base_rate)->toBe(4000.0);
    expect((float) $siblingTruck->vat_exclusive_total)->toBe($expectedHeavy['subtotal']);
    expect((float) $siblingTruck->vat_amount)->toBe($expectedHeavy['vatAmount']);
    expect((float) $siblingTruck->final_total)->toBe($expectedHeavy['finalTotal']);
    expect($siblingTruck->status)->toBe('confirmed');

    expect((float) $siblingVan->vat_exclusive_total)->not->toBe((float) $primary->vat_exclusive_total);
    expect((float) $siblingTruck->vat_exclusive_total)->not->toBe((float) $siblingVan->vat_exclusive_total);
    expect((float) $siblingVan->final_total)->not->toBe((float) $siblingTruck->final_total);
});
