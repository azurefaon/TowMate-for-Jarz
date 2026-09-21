<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

function gcaqRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function gcaqDispatcher(): User
{
    return User::factory()->create(['role_id' => gcaqRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function gcaqCustomer(): array
{
    $user = User::factory()->create(['role_id' => gcaqRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return [$user, $customer];
}

function gcaqTruckType(float $baseRate, float $perKmRate, string $class, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'class' => $class,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function gcaqVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function gcaqImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("gcaq-vehicle-$i.jpg", 300, 300), range(1, $count));
}

function gcaqDraft(User $dispatcher, Booking $booking, float $price): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $booking), [
        'price' => (string) $price,
        'distance_km' => (string) $booking->distance_km,
    ]);
}

it('lets a customer cancel one of three vehicles after quotation acceptance without disturbing the accepted quotation or the other two vehicles', function () {
    $dispatcher = gcaqDispatcher();
    [$user] = gcaqCustomer();

    $truckLight = gcaqTruckType(1500, 60, 'light', 'GCAQ Light Duty');
    $truckMedium = gcaqTruckType(2500, 90, 'medium', 'GCAQ Medium Duty');
    $truckHeavy = gcaqTruckType(4000, 150, 'heavy', 'GCAQ Heavy Duty');

    $vehicleLight = gcaqVehicleType($truckLight->id, 'GCAQ Sedan');
    $vehicleMedium = gcaqVehicleType($truckMedium->id, 'GCAQ Van');
    $vehicleHeavy = gcaqVehicleType($truckHeavy->id, 'GCAQ Box Truck');

    Sanctum::actingAs($user, ['*']);
    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $vehicleLight->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '13:00',
        'vehicle_images' => gcaqImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleMedium->id],
            ['vehicle_type_id' => $vehicleHeavy->id],
        ]),
        'extra_vehicle_images' => [0 => gcaqImages(1), 1 => gcaqImages(1)],
    ]);
    $response->assertCreated();
    $groupCode = $response->json('group_code');

    $members = Booking::where('group_code', $groupCode)->orderBy('id')->get();
    expect($members)->toHaveCount(3);
    [$anchor, $siblingB, $siblingC] = $members->all();

    gcaqDraft($dispatcher, $anchor, 1900)->assertOk();
    gcaqDraft($dispatcher, $siblingB, 3200)->assertOk();
    gcaqDraft($dispatcher, $siblingC, 5000)->assertOk();

    $quotation = Quotation::where('source_booking_id', $anchor->id)->current()->first();
    expect($quotation->status)->toBe('draft');

    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect($quotation->status)->toBe('sent');

    Sanctum::actingAs($user, ['*']);
    test()->postJson("/api/v1/quotations/{$quotation->id}/accept")->assertOk();

    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect($quotation->status)->toBe('accepted');
    $acceptedEstimatedPrice = (float) $quotation->estimated_price;
    $acceptedExtraVehicles = $quotation->extra_vehicles;

    $anchor->refresh();
    $siblingB->refresh();
    $siblingC->refresh();
    expect($anchor->status)->toBe('scheduled_confirmed');
    expect($siblingB->status)->toBe('scheduled_confirmed');
    expect($siblingC->status)->toBe('scheduled_confirmed');

    Sanctum::actingAs($user, ['*']);
    $cancel = test()->postJson("/api/v1/bookings/group/{$groupCode}/cancel", [
        'booking_codes' => [$siblingC->booking_code],
    ]);
    $cancel->assertOk();

    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect($quotation->status)->toBe('accepted');
    expect((float) $quotation->estimated_price)->toBe($acceptedEstimatedPrice);
    expect($quotation->extra_vehicles)->toBe($acceptedExtraVehicles);

    $anchor->refresh();
    $siblingB->refresh();
    $siblingC->refresh();
    expect($anchor->status)->toBe('scheduled_confirmed');
    expect($siblingB->status)->toBe('scheduled_confirmed');
    expect($siblingC->status)->toBe('cancelled');

    $details = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation));
    $details->assertOk();
    $groupVehicles = collect($details->json('quotation.group_vehicles'));
    $cancelledEntry = $groupVehicles->firstWhere('booking_code', $siblingC->booking_code);
    expect($cancelledEntry)->not->toBeNull();
    expect($cancelledEntry['status'])->toBe('cancelled');
    expect((float) $details->json('quotation.estimated_price'))->toBe($acceptedEstimatedPrice);

    $priceUpdate = test()->actingAs($dispatcher)->patchJson(route('admin.quotations.update-price', $quotation), [
        'new_price' => 4200,
    ]);
    $priceUpdate->assertStatus(422);

    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect($quotation->status)->toBe('accepted');
    expect((float) $quotation->estimated_price)->toBe($acceptedEstimatedPrice);
    expect($quotation->extra_vehicles)->toBe($acceptedExtraVehicles);

    $anchor->refresh();
    $siblingB->refresh();
    $siblingC->refresh();
    expect($anchor->status)->toBe('scheduled_confirmed');
    expect($siblingB->status)->toBe('scheduled_confirmed');
    expect($siblingC->status)->toBe('cancelled');
});
