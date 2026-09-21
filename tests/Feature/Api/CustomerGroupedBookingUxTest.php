<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

function gbuRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function gbuCustomer(): array
{
    $user = User::factory()->create(['role_id' => gbuRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function gbuTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'GBU Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function gbuVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function gbuReadyUnit(TruckType $truckType): Unit
{
    $leader = User::factory()->create(['role_id' => gbuRole(3, 'Team Leader')->id]);

    return Unit::create([
        'name' => 'GBU Ready Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'GBU Ready Driver',
    ]);
}

function gbuImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("vehicle-$i.jpg", 300, 300), range(1, $count));
}

function gbuCreateScheduledGroupRequest(User $user): array
{
    $primaryTruck = gbuTruckType();
    $primaryVehicle = gbuVehicleType($primaryTruck->id, 'GBU Sedan');

    $extraTruck = gbuTruckType();
    $extraVehicle = gbuVehicleType($extraTruck->id, 'GBU Motorcycle');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $primaryVehicle->id,
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
        'vehicle_images' => gbuImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id],
        ]),
        'extra_vehicle_images' => [0 => gbuImages(1)],
    ]);

    return [$response, $primaryVehicle, $extraVehicle];
}

function gbuCreateBookNowGroupRequest(User $user): array
{
    $primaryTruck = gbuTruckType();
    gbuReadyUnit($primaryTruck);
    $primaryVehicle = gbuVehicleType($primaryTruck->id, 'GBU Group Sedan');

    $extraTruck = gbuTruckType();
    gbuReadyUnit($extraTruck);
    $extraVehicle = gbuVehicleType($extraTruck->id, 'GBU Group Motorcycle');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $primaryVehicle->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'book_now',
        'vehicle_images' => gbuImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extraVehicle->id],
        ]),
        'extra_vehicle_images' => [0 => gbuImages(1)],
    ]);

    return [$response, $primaryVehicle, $extraVehicle];
}

function gbuCreateBookNowRequest(User $user): array
{
    $truck = gbuTruckType();
    gbuReadyUnit($truck);
    $vehicle = gbuVehicleType($truck->id, 'GBU Book Now Sedan');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $vehicle->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'book_now',
        'vehicle_images' => gbuImages(1),
    ]);

    return [$response, $vehicle];
}

it('createBooking returns a booking summary for every created booking in an all-Scheduled multi-vehicle request', function () {
    [$user] = gbuCustomer();
    [$response, $primaryVehicle, $extraVehicle] = gbuCreateScheduledGroupRequest($user);

    $response->assertCreated();
    $bookings = $response->json('bookings');
    expect($bookings)->toHaveCount(2);

    expect($bookings[0]['booking_code'])->toBe($response->json('booking_code'));
    expect($bookings[0]['service_type'])->toBe('schedule');
    expect($bookings[0]['status'])->toBe('scheduled');
    expect($bookings[0]['vehicle_type_name'])->toBe($primaryVehicle->name);
    expect($bookings[0]['is_current'])->toBeTrue();

    expect($bookings[1]['service_type'])->toBe('schedule');
    expect($bookings[1]['status'])->toBe('scheduled');
    expect($bookings[1]['vehicle_type_name'])->toBe($extraVehicle->name);
    expect($bookings[1]['scheduled_date'])->not->toBeNull();
    expect($bookings[1]['is_current'])->toBeFalse();
});

it('createBooking returns exactly one booking summary for a single-vehicle request', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateBookNowRequest($user);

    $response->assertCreated();
    expect($response->json('bookings'))->toHaveCount(1);
    expect($response->json('group_code'))->toBeNull();
});

it('currentBooking exposes group_vehicle_count and siblings for an all-Scheduled group', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $current = test()->getJson('/api/v1/bookings/current');
    $current->assertOk();
    expect($current->json('data.booking_code'))->toBeIn([$primaryCode, $siblingCode]);
    expect($current->json('data.group_vehicle_count'))->toBe(2);
    expect($current->json('data.group_siblings'))->toHaveCount(1);
});

it('currentBooking prioritizes an active in-progress job over a Book Now Requested booking', function () {
    [$user, $customer] = gbuCustomer();
    $truck = gbuTruckType();
    $vehicle = gbuVehicleType($truck->id, 'GBU Active Vehicle');

    $requested = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'vehicle_type_id' => $vehicle->id,
        'pickup_address' => 'A', 'pickup_lat' => 14.5, 'pickup_lng' => 120.9,
        'dropoff_address' => 'B', 'dropoff_lat' => 14.6, 'dropoff_lng' => 120.9,
        'distance_km' => 5, 'base_rate' => 1500, 'per_km_rate' => 60,
        'computed_total' => 1500, 'final_total' => 1680,
        'status' => 'requested', 'service_type' => 'book_now',
        'confirmation_type' => 'mobile',
    ]);
    $requested->update(['booking_code' => 'TM-' . str_pad($requested->id, 5, '0', STR_PAD_LEFT)]);

    $active = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'vehicle_type_id' => $vehicle->id,
        'pickup_address' => 'A', 'pickup_lat' => 14.5, 'pickup_lng' => 120.9,
        'dropoff_address' => 'B', 'dropoff_lat' => 14.6, 'dropoff_lng' => 120.9,
        'distance_km' => 5, 'base_rate' => 1500, 'per_km_rate' => 60,
        'computed_total' => 1500, 'final_total' => 1680,
        'status' => 'in_progress', 'service_type' => 'book_now',
        'confirmation_type' => 'mobile',
    ]);
    $active->update(['booking_code' => 'TM-' . str_pad($active->id, 5, '0', STR_PAD_LEFT)]);

    Sanctum::actingAs($user, ['*']);
    $current = test()->getJson('/api/v1/bookings/current');
    $current->assertOk();
    expect($current->json('data.booking_code'))->toBe($active->booking_code);
});

it('cancelling one booking in a Scheduled group leaves its sibling untouched', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $cancel = test()->postJson("/api/v1/bookings/{$siblingCode}/cancel");
    $cancel->assertOk();

    $siblingBooking = Booking::where('booking_code', $siblingCode)->first();
    $primaryBooking = Booking::where('booking_code', $primaryCode)->first();

    expect($siblingBooking->status)->toBe('cancelled');
    expect($primaryBooking->status)->toBe('scheduled');
});

it('detail marks a Scheduled sibling pricing as provisional and omits a misleading Distance Fee', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $detail = test()->getJson("/api/v1/bookings/{$siblingCode}/detail");
    $detail->assertOk();
    expect($detail->json('data.pricing_is_provisional'))->toBeTrue();
    expect($detail->json('data.distance_fee'))->toBeNull();
});

it('detail keeps canonical Distance Fee for a Book Now booking', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateBookNowRequest($user);
    $response->assertCreated();
    $bookNowCode = $response->json('booking_code');

    $detail = test()->getJson("/api/v1/bookings/{$bookNowCode}/detail");
    $detail->assertOk();
    expect($detail->json('data.pricing_is_provisional'))->toBeFalse();
    expect($detail->json('data.distance_fee'))->not->toBeNull();
    expect((float) $detail->json('data.distance_fee'))->toBeGreaterThan(0);
});

it('detail exposes group_siblings for a grouped booking and an empty list for a standalone one', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $detail = test()->getJson("/api/v1/bookings/{$primaryCode}/detail");
    $detail->assertOk();
    expect($detail->json('data.group_siblings'))->toHaveCount(1);
    expect($detail->json('data.group_siblings.0.booking_code'))->toBe($siblingCode);

    [$soloUser] = gbuCustomer();
    [$soloResponse] = gbuCreateBookNowRequest($soloUser);
    $soloResponse->assertCreated();
    $soloDetail = test()->getJson("/api/v1/bookings/{$soloResponse->json('booking_code')}/detail");
    $soloDetail->assertOk();
    expect($soloDetail->json('data.group_siblings'))->toBe([]);
});

it('detail exposes a group_totals sum matching each active member\'s own final_total for an all-Scheduled group', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $primaryBooking = Booking::where('booking_code', $primaryCode)->first();
    $siblingBooking = Booking::where('booking_code', $siblingCode)->first();
    $expectedTotal = round((float) $primaryBooking->final_total + (float) $siblingBooking->final_total, 2);

    $detail = test()->getJson("/api/v1/bookings/{$primaryCode}/detail");
    $detail->assertOk();
    expect($detail->json('data.group_totals.vehicle_count'))->toBe(2);
    expect((float) $detail->json('data.group_totals.final_total'))->toBe($expectedTotal);
    expect((float) $detail->json('data.group_totals.final_total'))->not->toBe((float) $primaryBooking->final_total);
});

it('detail excludes a cancelled sibling from group_totals while still listing it in group_siblings', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    test()->postJson("/api/v1/bookings/{$siblingCode}/cancel")->assertOk();

    $primaryBooking = Booking::where('booking_code', $primaryCode)->first();

    $detail = test()->getJson("/api/v1/bookings/{$primaryCode}/detail");
    $detail->assertOk();
    expect($detail->json('data.group_totals.vehicle_count'))->toBe(2);
    expect((float) $detail->json('data.group_totals.final_total'))->toBe(round((float) $primaryBooking->final_total, 2));

    $siblingSummary = collect($detail->json('data.group_siblings'))->firstWhere('booking_code', $siblingCode);
    expect($siblingSummary)->not->toBeNull();
    expect($siblingSummary['status'])->toBe('cancelled');
});

it('detail exposes the true group total for a Book Now group, not just the primary vehicle\'s own share', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateBookNowGroupRequest($user);
    $response->assertCreated();
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('group_siblings')[0]['booking_code'];

    $primaryBooking = Booking::where('booking_code', $primaryCode)->first();
    $siblingBooking = Booking::where('booking_code', $siblingCode)->first();
    $expectedTotal = round((float) $primaryBooking->final_total + (float) $siblingBooking->final_total, 2);

    $detail = test()->getJson("/api/v1/bookings/{$primaryCode}/detail");
    $detail->assertOk();
    expect((float) $detail->json('data.final_total'))->toBe((float) $primaryBooking->final_total);
    expect((float) $detail->json('data.group_totals.final_total'))->toBe($expectedTotal);
    expect((float) $detail->json('data.group_totals.final_total'))->toBeGreaterThan((float) $detail->json('data.final_total'));
});

it('detail returns a null group_totals for a standalone booking', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateBookNowRequest($user);
    $response->assertCreated();

    $detail = test()->getJson("/api/v1/bookings/{$response->json('booking_code')}/detail");
    $detail->assertOk();
    expect($detail->json('data.group_totals'))->toBeNull();
});

it('cancelGroupBookings rejects an empty selection', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();

    $cancel = test()->postJson("/api/v1/bookings/group/{$response->json('group_code')}/cancel", [
        'booking_codes' => [],
    ]);
    $cancel->assertStatus(422);
    $cancel->assertJsonValidationErrors('booking_codes');
});

it('cancelGroupBookings rejects a duplicate selection', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $primaryCode = $response->json('booking_code');

    $cancel = test()->postJson("/api/v1/bookings/group/{$response->json('group_code')}/cancel", [
        'booking_codes' => [$primaryCode, $primaryCode],
    ]);
    $cancel->assertStatus(422);
    $cancel->assertJsonValidationErrors('booking_codes.0');
});

it('cancelGroupBookings rejects a booking code that does not exist', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();

    $cancel = test()->postJson("/api/v1/bookings/group/{$response->json('group_code')}/cancel", [
        'booking_codes' => ['TM-99999'],
    ]);
    $cancel->assertStatus(422);
    expect($cancel->json('invalid_booking_codes'))->toContain('TM-99999');

    $primary = Booking::where('booking_code', $response->json('booking_code'))->first();
    expect($primary->status)->toBe('scheduled');
});

it('cancelGroupBookings rejects a booking code that belongs to a different group', function () {
    [$user] = gbuCustomer();
    [$responseA] = gbuCreateScheduledGroupRequest($user);
    $responseA->assertCreated();

    Booking::where('customer_id', Customer::where('user_id', $user->id)->first()->id)
        ->update(['status' => 'completed']);

    [$responseB] = gbuCreateBookNowGroupRequest($user);
    $responseB->assertCreated();

    $codeFromGroupA = $responseA->json('booking_code');
    $groupCodeB = $responseB->json('group_code');
    $codeFromGroupB = $responseB->json('group_siblings')[0]['booking_code'];

    $cancel = test()->postJson("/api/v1/bookings/group/{$groupCodeB}/cancel", [
        'booking_codes' => [$codeFromGroupA, $codeFromGroupB],
    ]);
    $cancel->assertStatus(422);
    expect($cancel->json('invalid_booking_codes'))->toContain($codeFromGroupA);

    $siblingB = Booking::where('booking_code', $codeFromGroupB)->first();
    expect($siblingB->status)->toBe('requested');
});

it('cancelGroupBookings rejects booking codes owned by another customer (BOLA)', function () {
    [$ownerUser] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($ownerUser);
    $response->assertCreated();
    $groupCode = $response->json('group_code');
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    [$attackerUser] = gbuCustomer();
    Sanctum::actingAs($attackerUser, ['*']);

    $cancel = test()->postJson("/api/v1/bookings/group/{$groupCode}/cancel", [
        'booking_codes' => [$primaryCode, $siblingCode],
    ]);
    $cancel->assertStatus(422);
    expect($cancel->json('invalid_booking_codes'))->toContain($primaryCode, $siblingCode);

    $primary = Booking::where('booking_code', $primaryCode)->first();
    expect($primary->status)->toBe('scheduled');
});

it('cancelGroupBookings cancels none when one selected vehicle is no longer eligible (atomic rollback)', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $groupCode = $response->json('group_code');
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    test()->postJson("/api/v1/bookings/{$siblingCode}/cancel")->assertOk();

    $cancel = test()->postJson("/api/v1/bookings/group/{$groupCode}/cancel", [
        'booking_codes' => [$primaryCode, $siblingCode],
    ]);
    $cancel->assertStatus(422);
    expect(collect($cancel->json('ineligible'))->pluck('booking_code'))->toContain($siblingCode);

    $primary = Booking::where('booking_code', $primaryCode)->first();
    expect($primary->status)->toBe('scheduled');
});

it('cancelGroupBookings cancels one selected vehicle, strips it from the group quotation, and leaves the sibling untouched', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateBookNowGroupRequest($user);
    $response->assertCreated();
    $groupCode = $response->json('group_code');
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('group_siblings')[0]['booking_code'];

    $primary = Booking::where('booking_code', $primaryCode)->first();
    $sibling = Booking::where('booking_code', $siblingCode)->first();
    $quotation = \App\Models\Quotation::where('source_booking_id', $primary->id)->current()->first();
    expect($quotation->status)->toBe('pending');
    expect(collect($quotation->extra_vehicles)->pluck('booking_id'))->toContain($sibling->id);

    $cancel = test()->postJson("/api/v1/bookings/group/{$groupCode}/cancel", [
        'booking_codes' => [$siblingCode],
    ]);
    $cancel->assertOk();
    expect($cancel->json('cancelled_booking_codes'))->toBe([$siblingCode]);
    expect((float) $cancel->json('remaining_total.final_total'))->toBe(round((float) $primary->final_total, 2));
    expect($cancel->json('accepted_quotation_amount'))->toBeNull();

    $primary->refresh();
    $sibling->refresh();
    expect($sibling->status)->toBe('cancelled');
    expect($primary->status)->toBe('requested');

    $quotation = \App\Models\Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect(collect($quotation->extra_vehicles ?? [])->pluck('booking_id'))->not->toContain($sibling->id);
    expect((float) $quotation->estimated_price)->toBe(round((float) $primary->final_total, 2));
});

it('cancelGroupBookings cancels every vehicle in a full group cancellation', function () {
    [$user] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $groupCode = $response->json('group_code');
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $cancel = test()->postJson("/api/v1/bookings/group/{$groupCode}/cancel", [
        'booking_codes' => [$primaryCode, $siblingCode],
    ]);
    $cancel->assertOk();
    expect((float) $cancel->json('remaining_total.final_total'))->toBe(0.0);
    expect($cancel->json('remaining_total.vehicle_count'))->toBe(2);

    expect(Booking::where('booking_code', $primaryCode)->first()->status)->toBe('cancelled');
    expect(Booking::where('booking_code', $siblingCode)->first()->status)->toBe('cancelled');
});

it('cancelGroupBookings preserves an accepted group quotation amount and releases capacity for the cancelled vehicle only', function () {
    [$user, $customer] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $groupCode = $response->json('group_code');
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $primary = Booking::where('booking_code', $primaryCode)->first();
    $sibling = Booking::where('booking_code', $siblingCode)->first();
    $scheduledDate = $primary->scheduled_date->toDateString();

    $quotation = app(\App\Services\QuotationService::class)->createQuotation([
        'source_booking_id' => $primary->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $primary->truck_type_id,
        'pickup_address' => $primary->pickup_address,
        'dropoff_address' => $primary->dropoff_address,
        'distance_km' => $primary->distance_km,
        'estimated_price' => round((float) $primary->final_total + (float) $sibling->final_total, 2),
        'service_type' => 'schedule',
        'scheduled_date' => $scheduledDate,
        'scheduled_time' => $primary->scheduled_time,
        'extra_vehicles' => [[
            'booking_id' => $sibling->id,
            'truck_type_id' => $sibling->truck_type_id,
            'service_type' => 'schedule',
            'final_total' => (float) $sibling->final_total,
            'base_rate' => (float) $sibling->base_rate,
            'distance_fee' => 0,
        ]],
    ]);
    app(\App\Services\QuotationService::class)->sendQuotation($quotation, 168);

    Sanctum::actingAs($user, ['*']);
    test()->postJson("/api/v1/quotations/{$quotation->id}/accept")->assertOk();

    $primary->refresh();
    $sibling->refresh();
    expect($primary->status)->toBe('scheduled_confirmed');
    expect($sibling->status)->toBe('scheduled_confirmed');
    expect($primary->price_locked_at)->not->toBeNull();
    expect($sibling->price_locked_at)->not->toBeNull();

    $capacityBefore = \Illuminate\Support\Facades\DB::table('booking_capacity')->where('booking_date', $scheduledDate)->first();
    expect($capacityBefore->slots_used)->toBe(2);

    $originalEstimatedPrice = (float) $quotation->fresh()->estimated_price;

    $cancel = test()->postJson("/api/v1/bookings/group/{$groupCode}/cancel", [
        'booking_codes' => [$siblingCode],
    ]);
    $cancel->assertOk();
    expect((float) $cancel->json('accepted_quotation_amount'))->toBe($originalEstimatedPrice);
    expect((float) $cancel->json('remaining_total.final_total'))->toBe(round((float) $primary->final_total, 2));

    $quotation = \App\Models\Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    expect($quotation->status)->toBe('accepted');
    expect((float) $quotation->estimated_price)->toBe($originalEstimatedPrice);

    $primary->refresh();
    $sibling->refresh();
    expect($sibling->status)->toBe('cancelled');
    expect($primary->status)->toBe('scheduled_confirmed');

    $capacityAfter = \Illuminate\Support\Facades\DB::table('booking_capacity')->where('booking_date', $scheduledDate)->first();
    expect($capacityAfter->slots_used)->toBe(1);
});

it('cancelGroupBookings does not decrement capacity twice on a repeated request', function () {
    [$user, $customer] = gbuCustomer();
    [$response] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();
    $groupCode = $response->json('group_code');
    $primaryCode = $response->json('booking_code');
    $siblingCode = $response->json('bookings')[1]['booking_code'];

    $primary = Booking::where('booking_code', $primaryCode)->first();
    $sibling = Booking::where('booking_code', $siblingCode)->first();
    $scheduledDate = $primary->scheduled_date->toDateString();

    $quotation = app(\App\Services\QuotationService::class)->createQuotation([
        'source_booking_id' => $primary->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $primary->truck_type_id,
        'pickup_address' => $primary->pickup_address,
        'dropoff_address' => $primary->dropoff_address,
        'distance_km' => $primary->distance_km,
        'estimated_price' => round((float) $primary->final_total + (float) $sibling->final_total, 2),
        'service_type' => 'schedule',
        'scheduled_date' => $scheduledDate,
        'scheduled_time' => $primary->scheduled_time,
        'extra_vehicles' => [[
            'booking_id' => $sibling->id,
            'truck_type_id' => $sibling->truck_type_id,
            'service_type' => 'schedule',
            'final_total' => (float) $sibling->final_total,
            'base_rate' => (float) $sibling->base_rate,
            'distance_fee' => 0,
        ]],
    ]);
    app(\App\Services\QuotationService::class)->sendQuotation($quotation, 168);

    Sanctum::actingAs($user, ['*']);
    test()->postJson("/api/v1/quotations/{$quotation->id}/accept")->assertOk();

    $first = test()->postJson("/api/v1/bookings/group/{$groupCode}/cancel", [
        'booking_codes' => [$siblingCode],
    ]);
    $first->assertOk();

    $capacityAfterFirst = \Illuminate\Support\Facades\DB::table('booking_capacity')->where('booking_date', $scheduledDate)->first();
    expect($capacityAfterFirst->slots_used)->toBe(1);

    $second = test()->postJson("/api/v1/bookings/group/{$groupCode}/cancel", [
        'booking_codes' => [$siblingCode],
    ]);
    $second->assertStatus(422);
    expect(collect($second->json('ineligible'))->pluck('booking_code'))->toContain($siblingCode);

    $capacityAfterSecond = \Illuminate\Support\Facades\DB::table('booking_capacity')->where('booking_date', $scheduledDate)->first();
    expect($capacityAfterSecond->slots_used)->toBe(1);
});

it('cancelGroupBookings leaves an unrelated customer\'s standalone booking untouched', function () {
    [$groupUser] = gbuCustomer();
    [$groupResponse] = gbuCreateScheduledGroupRequest($groupUser);
    $groupResponse->assertCreated();
    $groupCode = $groupResponse->json('group_code');
    $siblingCode = $groupResponse->json('bookings')[1]['booking_code'];

    [$soloUser] = gbuCustomer();
    [$soloResponse] = gbuCreateBookNowRequest($soloUser);
    $soloResponse->assertCreated();
    $soloCode = $soloResponse->json('booking_code');

    Sanctum::actingAs($groupUser, ['*']);
    $cancel = test()->postJson("/api/v1/bookings/group/{$groupCode}/cancel", [
        'booking_codes' => [$siblingCode],
    ]);
    $cancel->assertOk();

    $solo = Booking::where('booking_code', $soloCode)->first();
    expect($solo->status)->toBe('requested');
});

it('bookingHistory returns the customer-facing vehicle_type_name for each booking', function () {
    [$user] = gbuCustomer();
    [$response, $primaryVehicle] = gbuCreateScheduledGroupRequest($user);
    $response->assertCreated();

    $history = test()->getJson('/api/v1/bookings/history');
    $history->assertOk();
    $names = collect($history->json('data'))->pluck('vehicle_type_name');
    expect($names)->toContain($primaryVehicle->name);
});
