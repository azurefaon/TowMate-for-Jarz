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

function qdedupRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function qdedupDispatcher(): User
{
    return User::factory()->create(['role_id' => qdedupRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function qdedupTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'QDedup Light Duty',
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function qdedupVehicleType(int $requiredTruckTypeId): VehicleType
{
    return VehicleType::create([
        'name' => 'QDedup Sedan',
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function qdedupCustomerUser(): array
{
    $user = User::factory()->create(['role_id' => qdedupRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return [$user, $customer];
}

function qdedupReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => qdedupRole(3, 'Team Leader')->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => 'QDedup Unit',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $leader->id,
        'status' => 'available',
        'driver_name' => 'QDedup Driver',
    ]);
}

function qdedupAcceptPayload(Booking $booking, float $price): array
{
    $perKmRate = (float) ($booking->truckType?->per_km_rate ?? 0);
    $distanceKm = (float) ($booking->distance_km ?: 10);
    $distanceFee = app(\App\Services\BookingService::class)->distanceFeeFor($distanceKm, $perKmRate);

    return [
        'action' => 'accept',
        'price' => (string) $price,
        'distance_km' => $distanceKm,
        'distance_fee' => $distanceFee,
    ];
}

function qdedupSubmitBooking(array $overrides = []): array
{
    $truckType = qdedupTruckType();
    $vehicleType = qdedupVehicleType($truckType->id);
    qdedupReadyUnit($truckType);
    [$user, $customer] = qdedupCustomerUser();

    Sanctum::actingAs($user, ['*']);

    $payload = array_merge([
        'vehicle_type_id' => $vehicleType->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 10,
        'service_type' => 'book_now',
        'vehicle_images' => [UploadedFile::fake()->image('qdedup-vehicle.jpg', 300, 300)],
    ], $overrides);

    $response = test()->postJson('/api/v1/bookings', $payload);
    $response->assertCreated();

    $booking = Booking::where('booking_code', $response->json('booking_code'))->firstOrFail();

    return [$booking, $customer, $user];
}

it('creates exactly one logical current quotation chain for an Immediate booking, reused by dispatcher accept', function () {
    [$booking] = qdedupSubmitBooking();

    $shadowQuotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    expect($shadowQuotation)->not->toBeNull();
    expect($shadowQuotation->status)->toBe('pending');
    expect($booking->fresh()->quotation_id)->toBe($shadowQuotation->id);

    $dispatcher = qdedupDispatcher();
    test()->actingAs($dispatcher)->postJson(
        route('admin.booking.assign', $booking),
        qdedupAcceptPayload($booking, 2500)
    )->assertOk();

    $currentQuotations = Quotation::where('source_booking_id', $booking->id)->current()->get();
    expect($currentQuotations)->toHaveCount(1);

    $sentQuotation = $currentQuotations->first();
    expect($sentQuotation->id)->toBe($shadowQuotation->id);
    expect($sentQuotation->status)->toBe('sent');

    $allQuotationsForBooking = Quotation::where('source_booking_id', $booking->id)->get();
    expect($allQuotationsForBooking)->toHaveCount(1);
});

it('does not create an unrelated duplicate quotation when the dispatcher sends the quotation', function () {
    [$booking] = qdedupSubmitBooking();
    $originalQuotationId = Quotation::where('source_booking_id', $booking->id)->current()->value('id');

    $dispatcher = qdedupDispatcher();
    test()->actingAs($dispatcher)->postJson(
        route('admin.booking.assign', $booking),
        qdedupAcceptPayload($booking, 3000)
    )->assertOk();

    expect(Quotation::where('source_booking_id', $booking->id)->count())->toBe(1);
    expect(Quotation::find($originalQuotationId)->status)->toBe('sent');
    expect($booking->fresh()->quotation_id)->toBe($originalQuotationId);
});

it('resolves the same current quotation from the booking, the dispatcher endpoint, and the customer endpoint', function () {
    [$booking, , $user] = qdedupSubmitBooking();

    $dispatcher = qdedupDispatcher();
    test()->actingAs($dispatcher)->postJson(
        route('admin.booking.assign', $booking),
        qdedupAcceptPayload($booking, 2750)
    )->assertOk();

    $authoritativeQuotation = Quotation::where('source_booking_id', $booking->id)->current()->firstOrFail();

    Sanctum::actingAs($user, ['*']);
    $pending = test()->getJson('/api/v1/quotations/pending')->assertOk();
    expect($pending->json('data.id'))->toBe($authoritativeQuotation->id);

    expect($booking->fresh()->quotation_id)->toBe($authoritativeQuotation->id);
});

it('still allows the customer to accept the Immediate quotation after the dedup fix, confirming the booking', function () {
    [$booking, , $user] = qdedupSubmitBooking();

    $dispatcher = qdedupDispatcher();
    test()->actingAs($dispatcher)->postJson(
        route('admin.booking.assign', $booking),
        qdedupAcceptPayload($booking, 4200)
    )->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->firstOrFail();

    Sanctum::actingAs($user, ['*']);
    test()->postJson("/api/v1/quotations/{$quotation->id}/accept")->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe('confirmed');
    expect($booking->quotation_id)->toBe($quotation->fresh()->id);
    expect($quotation->fresh()->status)->toBe('accepted');
});

it('still starts a Scheduled booking without any auto-created quotation', function () {
    [$booking] = qdedupSubmitBooking([
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
    ]);

    expect($booking->status)->toBe('scheduled');
    expect($booking->quotation_id)->toBeNull();
    expect(Quotation::where('source_booking_id', $booking->id)->count())->toBe(0);
});

it('still supports the Scheduled dispatcher draft-then-send flow with exactly one quotation', function () {
    [$booking] = qdedupSubmitBooking([
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
    ]);

    $dispatcher = qdedupDispatcher();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.save-draft', $booking), [
        'price' => 3500,
    ])->assertOk();

    $draftQuotation = Quotation::where('source_booking_id', $booking->id)->current()->firstOrFail();
    expect($draftQuotation->status)->toBe('draft');

    test()->actingAs($dispatcher)->postJson(route('admin.quotations.send', $draftQuotation))->assertOk();

    expect(Quotation::where('source_booking_id', $booking->id)->count())->toBe(1);
    expect($draftQuotation->fresh()->status)->toBe('sent');
});

it('still produces scheduled_confirmed when the customer accepts a Scheduled quotation', function () {
    [$booking, , $user] = qdedupSubmitBooking([
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
    ]);

    $dispatcher = qdedupDispatcher();
    test()->actingAs($dispatcher)->postJson(route('admin.booking.save-draft', $booking), [
        'price' => 3900,
    ])->assertOk();
    $draftQuotation = Quotation::where('source_booking_id', $booking->id)->current()->firstOrFail();
    test()->actingAs($dispatcher)->postJson(route('admin.quotations.send', $draftQuotation))->assertOk();

    $quotation = $draftQuotation->fresh();

    Sanctum::actingAs($user, ['*']);
    test()->postJson("/api/v1/quotations/{$quotation->id}/accept")->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe('scheduled_confirmed');
});
