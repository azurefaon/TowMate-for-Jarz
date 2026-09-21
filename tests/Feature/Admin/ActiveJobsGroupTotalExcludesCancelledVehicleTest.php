<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\QuotationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

function ajgRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ajgDispatcher(): User
{
    return User::factory()->create(['role_id' => ajgRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function ajgCustomerUser(): array
{
    $user = User::factory()->create(['role_id' => ajgRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return [$user, $customer];
}

function ajgTruckType(float $baseRate, float $perKmRate, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function ajgVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function ajgReadyUnit(TruckType $truckType, string $label): Unit
{
    $leader = User::factory()->create(['role_id' => ajgRole(3, 'Team Leader')->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'AJG Driver',
    ]);
}

function ajgImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("ajg-vehicle-$i.jpg", 300, 300), range(1, $count));
}

it('shows the Active Jobs group total recalculated without a cancelled vehicle, not the stale original 3-vehicle total', function () {
    $dispatcher = ajgDispatcher();
    [$user, $customer] = ajgCustomerUser();

    $truckA = ajgTruckType(1500, 60, 'AJG Light Duty');
    $unitA = ajgReadyUnit($truckA, 'AJG Light Unit');
    $vehicleA = ajgVehicleType($truckA->id, 'AJG Sedan');

    $truckB = ajgTruckType(900, 40, 'AJG Small Duty');
    $unitB = ajgReadyUnit($truckB, 'AJG Small Unit');
    $vehicleB = ajgVehicleType($truckB->id, 'AJG Tricycle');

    $truckC = ajgTruckType(4000, 150, 'AJG Heavy Duty');
    ajgReadyUnit($truckC, 'AJG Heavy Unit');
    $vehicleC = ajgVehicleType($truckC->id, 'AJG Box Truck');

    Sanctum::actingAs($user, ['*']);
    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $vehicleA->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'book_now',
        'vehicle_images' => ajgImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleB->id],
            ['vehicle_type_id' => $vehicleC->id],
        ]),
        'extra_vehicle_images' => [
            0 => ajgImages(1),
            1 => ajgImages(1),
        ],
    ]);
    $response->assertCreated();
    $groupCode = $response->json('group_code');

    $primary = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleA->id)->firstOrFail();
    $siblingB = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleB->id)->firstOrFail();
    $siblingC = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleC->id)->firstOrFail();

    $quotation = Quotation::find($primary->quotation_id);
    app(QuotationService::class)->sendQuotation($quotation, 168);
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    Sanctum::actingAs($user, ['*']);
    test()->postJson("/api/v1/quotations/{$quotation->id}/accept")->assertOk();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $siblingC), [
        'action' => 'reject', 'rejection_reason' => 'Customer no longer needs this vehicle',
    ])->assertOk();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $primary), [
        'action' => 'accept', 'assigned_unit_id' => $unitA->id,
    ])->assertOk();
    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $siblingB), [
        'action' => 'accept', 'assigned_unit_id' => $unitB->id,
    ])->assertOk();

    $primary->refresh();
    $siblingB->refresh();
    $siblingC->refresh();
    $quotation->refresh();

    expect($siblingC->status)->toBe('cancelled');
    $expectedActiveTotal = round((float) $primary->final_total + (float) $siblingB->final_total, 2);
    $staleFullTotal = (float) $quotation->estimated_price;
    expect($expectedActiveTotal)->not->toBe($staleFullTotal);

    $html = test()->actingAs($dispatcher)->get(route('admin.jobs'))->assertOk()->getContent();

    expect($html)->toContain('data-total="' . number_format($expectedActiveTotal, 2) . '"');
    expect($html)->not->toContain('data-total="' . number_format($staleFullTotal, 2) . '"');
});
