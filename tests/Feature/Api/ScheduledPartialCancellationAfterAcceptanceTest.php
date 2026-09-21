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
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

function svcRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function svcDispatcher(): User
{
    return User::factory()->create(['role_id' => svcRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function svcCustomerUser(): array
{
    $user = User::factory()->create(['role_id' => svcRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return [$user, $customer];
}

function svcTruckType(float $baseRate, float $perKmRate, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function svcVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function svcReadyUnit(TruckType $truckType, string $label): Unit
{
    $leader = User::factory()->create(['role_id' => svcRole(3, 'Team Leader')->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'SVC Driver',
    ]);
}

function svcImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("svc-vehicle-$i.jpg", 300, 300), range(1, $count));
}

it('keeps the shared quotation accepted, the other 2 vehicles active, and the group total excluding the cancelled vehicle, for a scheduled 3-vehicle request', function () {
    $dispatcher = svcDispatcher();
    [$user, $customer] = svcCustomerUser();

    $truckA = svcTruckType(1500, 60, 'SVC Light Duty');
    $unitA = svcReadyUnit($truckA, 'SVC Light Unit');
    $vehicleA = svcVehicleType($truckA->id, 'SVC Sedan');

    $truckB = svcTruckType(900, 40, 'SVC Small Duty');
    $unitB = svcReadyUnit($truckB, 'SVC Small Unit');
    $vehicleB = svcVehicleType($truckB->id, 'SVC Tricycle');

    $truckC = svcTruckType(4000, 150, 'SVC Heavy Duty');
    svcReadyUnit($truckC, 'SVC Heavy Unit');
    $vehicleC = svcVehicleType($truckC->id, 'SVC Box Truck');

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
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'vehicle_images' => svcImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleB->id],
            ['vehicle_type_id' => $vehicleC->id],
        ]),
        'extra_vehicle_images' => [
            0 => svcImages(1),
            1 => svcImages(1),
        ],
    ]);
    $response->assertCreated();
    $groupCode = $response->json('group_code');

    $primary = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleA->id)->firstOrFail();
    $siblingB = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleB->id)->firstOrFail();
    $siblingC = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleC->id)->firstOrFail();

    expect($primary->service_type)->toBe('schedule');
    expect($siblingB->service_type)->toBe('schedule');
    expect($siblingC->service_type)->toBe('schedule');

    foreach ([$primary, $siblingB, $siblingC] as $member) {
        $distanceFee = round(max(0, (float) $member->distance_km - 4) * (float) $member->per_km_rate, 2);
        $subtotal = round((float) $member->base_rate + $distanceFee, 2);
        $price = round($subtotal * 1.12, 2);
        test()->actingAs($dispatcher)->postJson(route('admin.booking.save-draft', $member), [
            'price' => (string) $price,
            'distance_km' => (string) $member->distance_km,
        ])->assertOk();
    }

    $quotation = Quotation::where('source_booking_id', $primary->id)->current()->first();
    expect($quotation)->not->toBeNull();
    expect($quotation->extra_vehicles)->toHaveCount(3);

    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    Sanctum::actingAs($user, ['*']);
    test()->postJson("/api/v1/quotations/{$quotation->id}/accept")->assertOk();

    $primary->refresh();
    $siblingB->refresh();
    $siblingC->refresh();
    $quotation->refresh();

    expect($primary->status)->toBe('scheduled_confirmed');
    expect($siblingB->status)->toBe('scheduled_confirmed');
    expect($siblingC->status)->toBe('scheduled_confirmed');
    expect($quotation->status)->toBe('accepted');

    $originalEstimatedPrice = (float) $quotation->estimated_price;

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $siblingC), [
        'action' => 'reject', 'rejection_reason' => 'Customer no longer needs this vehicle',
    ])->assertOk();

    $primary->refresh();
    $siblingB->refresh();
    $siblingC->refresh();
    $quotation->refresh();

    expect($siblingC->status)->toBe('cancelled');
    expect($primary->status)->toBe('scheduled_confirmed');
    expect($siblingB->status)->toBe('scheduled_confirmed');

    expect($quotation->status)->toBe('accepted');
    expect($quotation->is_current)->toBeTrue();
    expect((float) $quotation->estimated_price)->toBe($originalEstimatedPrice);
    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(1);

    $expectedActiveTotal = round((float) $primary->final_total + (float) $siblingB->final_total, 2);
    expect($expectedActiveTotal)->not->toBe($originalEstimatedPrice);

    Sanctum::actingAs($user, ['*']);
    $mobileDetail = test()->getJson("/api/v1/bookings/{$primary->booking_code}/detail");
    $mobileDetail->assertOk();
    $mobileData = $mobileDetail->json('data');
    expect($mobileData['quotation_number'])->toBe($quotation->quotation_number);
    $cancelledSiblingRow = collect($mobileData['group_siblings'])->firstWhere('booking_code', $siblingC->booking_code);
    expect($cancelledSiblingRow['status'])->toBe('cancelled');

    $dispatcherView = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation));
    $dispatcherView->assertOk();
    expect((float) $dispatcherView->json('quotation.estimated_price'))->toBe($originalEstimatedPrice);
    expect($dispatcherView->json('quotation.status'))->toBe('accepted');

    $dispatcherGroupVehicles = collect($dispatcherView->json('quotation.group_vehicles'));
    $cancelledRow = $dispatcherGroupVehicles->firstWhere('booking_id', $siblingC->id);
    expect($cancelledRow['status'])->toBe('cancelled');
    $activeRows = $dispatcherGroupVehicles->reject(fn ($row) => $row['status'] === 'cancelled');
    expect(round($activeRows->sum('final_total'), 2))->toBe($expectedActiveTotal);
});
