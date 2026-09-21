<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\PriceAdjustment;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

function cqaRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cqaDispatcher(): User
{
    return User::factory()->create(['role_id' => cqaRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function cqaCustomerUser(): array
{
    $user = User::factory()->create(['role_id' => cqaRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return [$user, $customer];
}

function cqaTruckType(float $baseRate, float $perKmRate, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function cqaVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function cqaReadyUnit(TruckType $truckType, string $label): Unit
{
    $leader = User::factory()->create(['role_id' => cqaRole(3, 'Team Leader')->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'CQA Driver',
    ]);
}

function cqaImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("cqa-vehicle-$i.jpg", 300, 300), range(1, $count));
}

it('shows the customer the same net adjustment amount and active reasons the dispatcher sees, applied once to the group total and not per vehicle', function () {
    $dispatcher = cqaDispatcher();
    [$user, $customer] = cqaCustomerUser();

    $truckA = cqaTruckType(1500, 60, 'CQA Light Duty');
    cqaReadyUnit($truckA, 'CQA Light Unit');
    $vehicleSedan = cqaVehicleType($truckA->id, 'CQA Sedan');

    $truckB = cqaTruckType(900, 40, 'CQA Small Duty');
    cqaReadyUnit($truckB, 'CQA Small Unit');
    $vehicleMotor = cqaVehicleType($truckB->id, 'CQA Tricycle');

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
        'vehicle_images' => cqaImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleMotor->id],
        ]),
        'extra_vehicle_images' => [
            0 => cqaImages(1),
        ],
    ]);
    $response->assertCreated();
    $groupCode = $response->json('group_code');

    $primary = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleSedan->id)->firstOrFail();
    $sibling = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleMotor->id)->firstOrFail();

    $distanceKm = (float) $primary->distance_km;
    $expectedPrimary = round(1500 + max(0, $distanceKm - 4.0) * 60, 2);
    $expectedPrimaryWithVat = round($expectedPrimary * 1.12, 2);
    $expectedSibling = round(900 + max(0, $distanceKm - 4.0) * 40, 2);
    $expectedSiblingWithVat = round($expectedSibling * 1.12, 2);
    $groupServiceTotal = round($expectedPrimaryWithVat + $expectedSiblingWithVat, 2);

    test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $primary), [
        'price' => (string) round($groupServiceTotal + 250, 2),
        'distance_km' => (string) $distanceKm,
        'adjustments' => [
            ['type' => 'add', 'amount' => 400, 'reason' => 'Extra fee'],
            ['type' => 'deduct', 'amount' => 150, 'reason' => 'Group discount'],
        ],
    ])->assertOk();

    $quotation = Quotation::find($primary->fresh()->quotation_id);
    expect($quotation)->not->toBeNull();

    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    expect(PriceAdjustment::forQuotation($quotation->quotation_number)->count())->toBe(2);
    expect((float) $quotation->additional_fee)->toBe(250.0);

    Sanctum::actingAs($user, ['*']);
    $pending = test()->getJson('/api/v1/quotations/pending');
    $pending->assertOk();
    $data = $pending->json('data');

    expect((float) $data['additional_fee'])->toBe(250.0);
    expect((float) $data['estimated_price'])->toBe(round($groupServiceTotal + 250, 2));
    expect($data['additional_fee_note'])->toContain('Extra fee');
    expect($data['additional_fee_note'])->toContain('Group discount');

    $primaryLine = collect($data['extra_vehicles'])->firstWhere('booking_id', $primary->id);
    $siblingLine = collect($data['extra_vehicles'])->firstWhere('booking_id', $sibling->id);
    expect((float) $primaryLine['final_total'])->toBe($expectedPrimaryWithVat);
    expect((float) $siblingLine['final_total'])->toBe($expectedSiblingWithVat);

    $toRevert = PriceAdjustment::forQuotation($quotation->quotation_number)->where('reason', 'Extra fee')->first();
    test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjustments.undo', [
        'quotation' => $quotation->id,
        'adjustment' => $toRevert->id,
    ]))->assertOk();

    $refreshedQuotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    Sanctum::actingAs($user, ['*']);
    $pendingAfterUndo = test()->getJson('/api/v1/quotations/pending');
    $pendingAfterUndo->assertOk();
    $dataAfterUndo = $pendingAfterUndo->json('data');

    expect((float) $dataAfterUndo['additional_fee'])->toBe(-150.0);
    expect((float) $dataAfterUndo['estimated_price'])->toBe(round($groupServiceTotal - 150, 2));
    expect($dataAfterUndo['additional_fee_note'])->toBe('Group discount');
    expect($dataAfterUndo['additional_fee_note'])->not->toContain('Extra fee');

    $afterUndoSiblingLine = collect($dataAfterUndo['extra_vehicles'])->firstWhere('booking_id', $sibling->id);
    expect((float) $afterUndoSiblingLine['final_total'])->toBe($expectedSiblingWithVat);

    $dispatcherView = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $refreshedQuotation));
    $dispatcherAdjustments = $dispatcherView->json('quotation.price_adjustments');
    expect($dispatcherAdjustments)->toHaveCount(2);
    expect(collect($dispatcherAdjustments)->firstWhere('reason', 'Extra fee')['status'])->toBe('reverted');
    expect(collect($dispatcherAdjustments)->firstWhere('reason', 'Group discount')['status'])->toBe('active');
});
