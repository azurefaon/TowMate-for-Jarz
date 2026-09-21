<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function cbgRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cbgCustomer(): array
{
    $user = User::factory()->create(['role_id' => cbgRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function cbgTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'CBG Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

it('shows the primary booking_code as the group reference on the sibling detail response, plus the shared quotation number', function () {
    [$user, $customer] = cbgCustomer();
    $truck = cbgTruckType();

    $primary = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'group_code' => 'CBG-GROUP-1',
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'final_total' => 2217.6,
        'status' => 'requested',
        'service_type' => 'book_now',
    ]);
    $primary->update(['booking_code' => 'TM-' . str_pad((string) $primary->id, 5, '0', STR_PAD_LEFT)]);

    $sibling = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'group_code' => 'CBG-GROUP-1',
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12,
        'base_rate' => 900,
        'per_km_rate' => 40,
        'final_total' => 1366.4,
        'status' => 'requested',
        'service_type' => 'book_now',
    ]);
    $sibling->update(['booking_code' => 'TM-' . str_pad((string) $sibling->id, 5, '0', STR_PAD_LEFT)]);

    $quotation = Quotation::create([
        'quotation_number' => 'QT-CBG-' . uniqid(),
        'source_booking_id' => $primary->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12,
        'estimated_price' => 3584.0,
        'service_type' => 'book_now',
        'status' => 'sent',
        'sent_at' => now(),
        'is_current' => true,
    ]);
    $primary->update(['quotation_id' => $quotation->id]);

    Sanctum::actingAs($user, ['*']);

    $siblingDetail = test()->getJson("/api/v1/bookings/{$sibling->booking_code}/detail");
    $siblingDetail->assertOk();
    expect($siblingDetail->json('data.booking_code'))->toBe($sibling->booking_code);
    expect($siblingDetail->json('data.group_booking_code'))->toBe($primary->booking_code);
    expect($siblingDetail->json('data.quotation_number'))->toBe($quotation->quotation_number);

    $primaryDetail = test()->getJson("/api/v1/bookings/{$primary->booking_code}/detail");
    $primaryDetail->assertOk();
    expect($primaryDetail->json('data.group_booking_code'))->toBe($primary->booking_code);
    expect($primaryDetail->json('data.quotation_number'))->toBe($quotation->quotation_number);

    $history = test()->getJson('/api/v1/bookings/history');
    $history->assertOk();
    $rows = collect($history->json('data'));
    $primaryRow = $rows->firstWhere('booking_code', $primary->booking_code);
    $siblingRow = $rows->firstWhere('booking_code', $sibling->booking_code);

    expect($primaryRow['group_booking_code'])->toBe($primary->booking_code);
    expect($siblingRow['group_booking_code'])->toBe($primary->booking_code);
    expect($primaryRow['quotation_number'])->toBe($quotation->quotation_number);
    expect($siblingRow['quotation_number'])->toBe($quotation->quotation_number);
});

it('keeps a standalone booking pointing to its own code with no group reference confusion', function () {
    [$user, $customer] = cbgCustomer();
    $truck = cbgTruckType();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'final_total' => 2217.6,
        'status' => 'requested',
        'service_type' => 'book_now',
    ]);
    $booking->update(['booking_code' => 'TM-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT)]);

    Sanctum::actingAs($user, ['*']);

    $detail = test()->getJson("/api/v1/bookings/{$booking->booking_code}/detail");
    $detail->assertOk();
    expect($detail->json('data.group_booking_code'))->toBe($booking->booking_code);
    expect($detail->json('data.quotation_number'))->toBeNull();

    $history = test()->getJson('/api/v1/bookings/history');
    $history->assertOk();
    $row = collect($history->json('data'))->firstWhere('booking_code', $booking->booking_code);
    expect($row['group_booking_code'])->toBe($booking->booking_code);
    expect($row['quotation_number'])->toBeNull();
});
