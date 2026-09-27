<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;

function bdqRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function bdqDispatcher(): User
{
    return User::factory()->create(['role_id' => bdqRole(2, 'Dispatcher')->id, 'status' => 'active', 'must_change_password' => false]);
}

function bdqCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'BDQ Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'bdq-' . uniqid() . '@example.com',
    ]);
}

function bdqTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'BDQ Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function bdqBooking(TruckType $truckType, Customer $customer, array $overrides = []): Booking
{
    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Origin',
        'dropoff_address' => 'Destination',
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 2016,
        'service_type' => 'book_now',
        'status' => 'requested',
    ], $overrides));
}

function bdqQuotation(TruckType $truckType, Customer $customer, Booking $sourceBooking, array $overrides = []): Quotation
{
    return Quotation::create(array_merge([
        'quotation_number' => 'QT-BDQ-' . uniqid(),
        'source_booking_id' => $sourceBooking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $sourceBooking->pickup_address,
        'dropoff_address' => $sourceBooking->dropoff_address,
        'distance_km' => $sourceBooking->distance_km,
        'estimated_price' => 2016,
        'version' => 1,
        'status' => 'sent',
        'is_current' => true,
    ], $overrides));
}

it('exposes complete quotation metadata (number, status, version, amount) for the View Quotation action', function () {
    $customer = bdqCustomer();
    $truckType = bdqTruckType();
    $booking = bdqBooking($truckType, $customer, ['status' => 'quotation_sent']);
    $quotation = bdqQuotation($truckType, $customer, $booking, [
        'quotation_number' => 'QT-BDQ-META',
        'status' => 'sent',
        'version' => 2,
        'estimated_price' => 2500,
    ]);

    $dispatcher = bdqDispatcher();
    $response = test()->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $booking))->assertOk();

    expect($response->json('quotation.id'))->toBe($quotation->id);
    expect($response->json('quotation.quotation_number'))->toBe('QT-BDQ-META');
    expect($response->json('quotation.status'))->toBe('sent');
    expect($response->json('quotation.version'))->toBe(2);
    expect((float) $response->json('quotation.estimated_price'))->toBe(2500.0);
});

it('keeps the quotation accessible once a booking is assigned/active (post-dispatch lifecycle state)', function () {
    $customer = bdqCustomer();
    $truckType = bdqTruckType();
    $leaderRole = bdqRole(3, 'Team Leader');
    $leader = User::factory()->create(['role_id' => $leaderRole->id]);
    $unit = Unit::create([
        'name' => 'BDQ Unit',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $leader->id,
        'status' => 'available',
    ]);

    $booking = bdqBooking($truckType, $customer, [
        'status' => 'assigned',
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $leader->id,
    ]);
    $quotation = bdqQuotation($truckType, $customer, $booking, ['status' => 'accepted']);

    $dispatcher = bdqDispatcher();
    $response = test()->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $booking))->assertOk();

    expect($response->json('quotation.id'))->toBe($quotation->id);
    expect($response->json('quotation.status'))->toBe('accepted');
    expect($response->json('assignment.unit.id'))->toBe($unit->id);
});

it('returns an accepted quotation as the historical/agreed record without altering its status', function () {
    $customer = bdqCustomer();
    $truckType = bdqTruckType();
    $booking = bdqBooking($truckType, $customer, ['status' => 'confirmed']);
    $quotation = bdqQuotation($truckType, $customer, $booking, [
        'status' => 'accepted',
        'estimated_price' => 2016,
    ]);

    $dispatcher = bdqDispatcher();
    $response = test()->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $booking))->assertOk();

    expect($response->json('quotation.status'))->toBe('accepted');
    expect((float) $response->json('quotation.estimated_price'))->toBe(2016.0);
    // The bundle endpoint is read-only — merely fetching it must never mutate the quotation.
    expect($quotation->fresh()->status)->toBe('accepted');
});

it('degrades cleanly with quotation=null for a booking that has never been quoted', function () {
    $customer = bdqCustomer();
    $truckType = bdqTruckType();
    $booking = bdqBooking($truckType, $customer, ['status' => 'requested']);

    $dispatcher = bdqDispatcher();
    $response = test()->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $booking))->assertOk();

    expect($response->json('quotation'))->toBeNull();
    expect($response->json('success'))->toBeTrue();
});
