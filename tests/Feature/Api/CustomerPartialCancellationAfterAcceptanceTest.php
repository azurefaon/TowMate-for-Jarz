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

function cpcRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cpcDispatcher(): User
{
    return User::factory()->create(['role_id' => cpcRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function cpcCustomerUser(): array
{
    $user = User::factory()->create(['role_id' => cpcRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return [$user, $customer];
}

function cpcTruckType(float $baseRate, float $perKmRate, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function cpcVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function cpcReadyUnit(TruckType $truckType, string $label): Unit
{
    $leader = User::factory()->create(['role_id' => cpcRole(3, 'Team Leader')->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'CPC Driver',
    ]);
}

function cpcImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("cpc-vehicle-$i.jpg", 300, 300), range(1, $count));
}

it('keeps the customer self-service cancel endpoint blocked for an already-confirmed vehicle in an accepted 3-vehicle group', function () {
    [$user, $customer] = cpcCustomerUser();

    $truckA = cpcTruckType(1500, 60, 'CPC Light Duty');
    cpcReadyUnit($truckA, 'CPC Light Unit');
    $vehicleA = cpcVehicleType($truckA->id, 'CPC Sedan');

    $truckB = cpcTruckType(900, 40, 'CPC Small Duty');
    cpcReadyUnit($truckB, 'CPC Small Unit');
    $vehicleB = cpcVehicleType($truckB->id, 'CPC Tricycle');

    $truckC = cpcTruckType(4000, 150, 'CPC Heavy Duty');
    cpcReadyUnit($truckC, 'CPC Heavy Unit');
    $vehicleC = cpcVehicleType($truckC->id, 'CPC Box Truck');

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
        'vehicle_images' => cpcImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleB->id],
            ['vehicle_type_id' => $vehicleC->id],
        ]),
        'extra_vehicle_images' => [
            0 => cpcImages(1),
            1 => cpcImages(1),
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

    $siblingB->refresh();
    expect($siblingB->status)->toBe('confirmed');

    Sanctum::actingAs($user, ['*']);
    $cancel = test()->postJson("/api/v1/bookings/{$siblingB->booking_code}/cancel", [
        'reason' => 'Changed my mind about this vehicle',
    ]);

    $cancel->assertStatus(422)->assertJsonPath('success', false);

    $siblingB->refresh();
    $quotation->refresh();
    $siblingC->refresh();
    expect($siblingB->status)->toBe('confirmed');
    expect($quotation->status)->toBe('accepted');
    expect($siblingC->status)->toBe('confirmed');
});
