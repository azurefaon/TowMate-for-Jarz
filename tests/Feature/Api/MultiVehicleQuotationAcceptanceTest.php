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

function mvaRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function mvaDispatcher(): User
{
    return User::factory()->create(['role_id' => mvaRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function mvaCustomerUser(): array
{
    $user = User::factory()->create(['role_id' => mvaRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return [$user, $customer];
}

function mvaTruckType(float $baseRate, float $perKmRate, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function mvaVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function mvaReadyUnit(TruckType $truckType, string $label): Unit
{
    $leader = User::factory()->create(['role_id' => mvaRole(3, 'Team Leader')->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'MVA Driver',
    ]);
}

function mvaImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("mva-vehicle-$i.jpg", 300, 300), range(1, $count));
}

it('applies one customer acceptance to the shared quotation and every eligible vehicle in a 3-vehicle group, prevents duplicate acceptance, and keeps each vehicle independently dispatchable', function () {
    $dispatcher = mvaDispatcher();
    [$user, $customer] = mvaCustomerUser();

    $truckA = mvaTruckType(1500, 60, 'MVA Light Duty');
    $unitA = mvaReadyUnit($truckA, 'MVA Light Unit');
    $vehicleA = mvaVehicleType($truckA->id, 'MVA Sedan');

    $truckB = mvaTruckType(900, 40, 'MVA Small Duty');
    $unitB = mvaReadyUnit($truckB, 'MVA Small Unit');
    $vehicleB = mvaVehicleType($truckB->id, 'MVA Tricycle');

    $truckC = mvaTruckType(4000, 150, 'MVA Heavy Duty');
    $unitC = mvaReadyUnit($truckC, 'MVA Heavy Unit');
    $vehicleC = mvaVehicleType($truckC->id, 'MVA Box Truck');

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
        'vehicle_images' => mvaImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleB->id],
            ['vehicle_type_id' => $vehicleC->id],
        ]),
        'extra_vehicle_images' => [
            0 => mvaImages(1),
            1 => mvaImages(1),
        ],
    ]);
    $response->assertCreated();
    $groupCode = $response->json('group_code');

    $primary = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleA->id)->firstOrFail();
    $siblingB = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleB->id)->firstOrFail();
    $siblingC = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleC->id)->firstOrFail();

    expect($siblingB->group_code)->toBe($groupCode);
    expect($siblingC->group_code)->toBe($groupCode);

    $quotation = Quotation::find($primary->quotation_id);
    expect($quotation)->not->toBeNull();
    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(1);

    app(QuotationService::class)->sendQuotation($quotation, 168);
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect($quotation->status)->toBe('sent');

    Sanctum::actingAs($user, ['*']);
    $accept = test()->postJson("/api/v1/quotations/{$quotation->id}/accept");
    $accept->assertOk()->assertJsonPath('success', true);

    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(1);
    $quotation->refresh();
    expect($quotation->status)->toBe('accepted');

    $primary->refresh();
    $siblingB->refresh();
    $siblingC->refresh();

    foreach ([$primary, $siblingB, $siblingC] as $booking) {
        expect($booking->status)->toBe('confirmed');
        expect($booking->quotation_id)->toBe($quotation->id);
        expect((float) $booking->final_total)->toBeGreaterThan(0);
        expect($booking->vat_amount)->not->toBeNull();
        expect($booking->vat_exclusive_total)->not->toBeNull();
        expect($booking->customer_approved_at)->not->toBeNull();
        expect($booking->price_locked_at)->not->toBeNull();
    }

    expect((float) $primary->base_rate)->toBe(1500.0);
    expect((float) $siblingB->base_rate)->toBe(900.0);
    expect((float) $siblingC->base_rate)->toBe(4000.0);
    expect((float) $primary->final_total)->not->toBe((float) $siblingB->final_total);
    expect((float) $siblingB->final_total)->not->toBe((float) $siblingC->final_total);

    $secondAccept = test()->postJson("/api/v1/quotations/{$quotation->id}/accept");
    $secondAccept->assertStatus(422);
    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(1);

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $primary), [
        'action' => 'accept', 'assigned_unit_id' => $unitA->id,
    ])->assertOk();
    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $siblingB), [
        'action' => 'accept', 'assigned_unit_id' => $unitB->id,
    ])->assertOk();
    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $siblingC), [
        'action' => 'accept', 'assigned_unit_id' => $unitC->id,
    ])->assertOk();

    $primary->refresh();
    $siblingB->refresh();
    $siblingC->refresh();
    expect($primary->assigned_unit_id)->toBe($unitA->id);
    expect($siblingB->assigned_unit_id)->toBe($unitB->id);
    expect($siblingC->assigned_unit_id)->toBe($unitC->id);
    expect($primary->status)->toBe('assigned');
    expect($siblingB->status)->toBe('assigned');
    expect($siblingC->status)->toBe('assigned');
});
