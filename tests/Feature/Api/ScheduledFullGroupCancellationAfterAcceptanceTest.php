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

function sfcRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function sfcDispatcher(): User
{
    return User::factory()->create(['role_id' => sfcRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function sfcCustomerUser(): array
{
    $user = User::factory()->create(['role_id' => sfcRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return [$user, $customer];
}

function sfcTruckType(float $baseRate, float $perKmRate, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function sfcVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function sfcReadyUnit(TruckType $truckType, string $label): Unit
{
    $leader = User::factory()->create(['role_id' => sfcRole(3, 'Team Leader')->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'SFC Driver',
    ]);
}

function sfcImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("sfc-vehicle-$i.jpg", 300, 300), range(1, $count));
}

it('cancels all 3 vehicles of an accepted scheduled group, leaves nothing dispatchable, preserves the original quotation, and reflects correctly in My Bookings History', function () {
    $dispatcher = sfcDispatcher();
    [$user, $customer] = sfcCustomerUser();

    $truckA = sfcTruckType(1500, 60, 'SFC Light Duty');
    sfcReadyUnit($truckA, 'SFC Light Unit');
    $vehicleA = sfcVehicleType($truckA->id, 'SFC Sedan');

    $truckB = sfcTruckType(900, 40, 'SFC Small Duty');
    sfcReadyUnit($truckB, 'SFC Small Unit');
    $vehicleB = sfcVehicleType($truckB->id, 'SFC Tricycle');

    $truckC = sfcTruckType(4000, 150, 'SFC Heavy Duty');
    sfcReadyUnit($truckC, 'SFC Heavy Unit');
    $vehicleC = sfcVehicleType($truckC->id, 'SFC Box Truck');

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
        'vehicle_images' => sfcImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleB->id],
            ['vehicle_type_id' => $vehicleC->id],
        ]),
        'extra_vehicle_images' => [
            0 => sfcImages(1),
            1 => sfcImages(1),
        ],
    ]);
    $response->assertCreated();
    $groupCode = $response->json('group_code');

    $primary = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleA->id)->firstOrFail();
    $siblingB = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleB->id)->firstOrFail();
    $siblingC = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleC->id)->firstOrFail();

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

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $siblingB), [
        'action' => 'reject', 'rejection_reason' => 'Customer no longer needs this vehicle',
    ])->assertOk();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $siblingC), [
        'action' => 'reject', 'rejection_reason' => 'Customer no longer needs this vehicle',
    ])->assertOk();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $primary), [
        'action' => 'reject', 'rejection_reason' => 'Customer cancelled the entire request',
    ])->assertOk();

    $primary->refresh();
    $siblingB->refresh();
    $siblingC->refresh();
    $quotation->refresh();

    expect($primary->status)->toBe('cancelled');
    expect($siblingB->status)->toBe('cancelled');
    expect($siblingC->status)->toBe('cancelled');

    $ids = [$primary->id, $siblingB->id, $siblingC->id];
    expect(Booking::whereIn('id', $ids)->whereIn('status', Booking::REVIEWABLE_STATUSES)->exists())->toBeFalse();
    expect(Booking::whereIn('id', $ids)->unitReservations()->exists())->toBeFalse();

    $redispatchAttempt = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $primary), [
        'action' => 'accept', 'assigned_unit_id' => Unit::where('truck_type_id', $truckA->id)->first()->id,
    ]);
    $redispatchAttempt->assertStatus(422);

    expect($quotation->status)->toBe('accepted');
    expect($quotation->is_current)->toBeTrue();
    expect((float) $quotation->estimated_price)->toBe($originalEstimatedPrice);
    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(1);
    expect(Quotation::where('customer_id', $customer->id)->where('status', 'pending')->count())->toBe(0);
    expect(Quotation::where('customer_id', $customer->id)->where('status', 'sent')->count())->toBe(0);

    $dispatcherView = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation));
    $dispatcherView->assertOk();
    $dispatcherGroupVehicles = collect($dispatcherView->json('quotation.group_vehicles'));
    expect($dispatcherGroupVehicles->pluck('status')->unique()->all())->toBe(['cancelled']);

    Sanctum::actingAs($user, ['*']);
    $history = test()->getJson('/api/v1/bookings/history');
    $history->assertOk();
    $rows = collect($history->json('data'));

    foreach ([$primary->booking_code, $siblingB->booking_code, $siblingC->booking_code] as $code) {
        $row = $rows->firstWhere('booking_code', $code);
        expect($row)->not->toBeNull();
        expect($row['status'])->toBe('cancelled');
        expect($row['group_booking_code'])->toBe($primary->booking_code);
        expect($row['quotation_number'])->toBe($quotation->quotation_number);
    }
});
