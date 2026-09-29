<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function cbhmCustomerWithUser(): array
{
    $role = Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });

    $user = User::factory()->create(['role_id' => $role->id]);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => 'CBHM Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'cbhm-' . uniqid() . '@example.com',
    ]);

    return [$user, $customer];
}

function cbhmTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'CBHM Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function cbhmBooking(Customer $customer, TruckType $truckType, array $overrides = []): Booking
{
    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'computed_total' => 1800,
        'final_total' => 2016,
        'status' => 'completed',
        'service_type' => 'book_now',
    ], $overrides));
}

const CBHM_META_KEYS = ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'];

it('returns meta as an object with booking history present', function () {
    [$user, $customer] = cbhmCustomerWithUser();
    $truckType = cbhmTruckType();
    cbhmBooking($customer, $truckType);

    Sanctum::actingAs($user, ['*']);
    $response = test()->getJson('/api/v1/bookings/history');

    $response->assertOk()->assertJsonPath('success', true);
    expect($response->json('meta'))->toBeArray();
    expect(array_keys($response->json('meta')))->toEqualCanonicalizing(CBHM_META_KEYS);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data'))->toHaveCount(1);
});

it('returns the same meta shape for a customer with zero bookings', function () {
    [$user, ] = cbhmCustomerWithUser();

    Sanctum::actingAs($user, ['*']);
    $response = test()->getJson('/api/v1/bookings/history');

    $response->assertOk()->assertJsonPath('success', true);
    expect($response->json('data'))->toBe([]);
    expect(array_keys($response->json('meta')))->toEqualCanonicalizing(CBHM_META_KEYS);
    expect($response->json('meta.total'))->toBe(0);
});

it('returns data=[] and the same meta shape for an authenticated user with no Customer profile', function () {
    $role = Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });
    $user = User::factory()->create(['role_id' => $role->id]);

    Sanctum::actingAs($user, ['*']);
    $response = test()->getJson('/api/v1/bookings/history');

    $response->assertOk()->assertJsonPath('success', true);
    expect($response->json('data'))->toBe([]);
    expect(array_keys($response->json('meta')))->toEqualCanonicalizing(CBHM_META_KEYS);
    expect($response->json('meta.total'))->toBe(0);
});

it('never serializes meta as an empty JSON array in any branch', function () {
    [$userWithHistory, $customerWithHistory] = cbhmCustomerWithUser();
    $truckType = cbhmTruckType();
    cbhmBooking($customerWithHistory, $truckType);

    [$userEmpty, ] = cbhmCustomerWithUser();

    $roleOnly = Role::find(5);
    $userNoProfile = User::factory()->create(['role_id' => $roleOnly->id]);

    foreach ([$userWithHistory, $userEmpty, $userNoProfile] as $u) {
        Sanctum::actingAs($u, ['*']);
        $response = test()->getJson('/api/v1/bookings/history');
        $rawMeta = json_decode($response->getContent(), true)['meta'];
        expect($rawMeta)->not->toBe([]);
        expect(array_keys($rawMeta))->not->toBe(range(0, count($rawMeta) - 1));
    }
});

it('rejects an unauthenticated request to booking history', function () {
    test()->getJson('/api/v1/bookings/history')->assertStatus(401);
});

it('keeps pagination working correctly across multiple booking history records', function () {
    [$user, $customer] = cbhmCustomerWithUser();
    $truckType = cbhmTruckType();

    for ($i = 0; $i < 12; $i++) {
        cbhmBooking($customer, $truckType, ['created_at' => now()->subMinutes($i)]);
    }

    Sanctum::actingAs($user, ['*']);
    $page1 = test()->getJson('/api/v1/bookings/history?page=1')->assertOk();
    expect($page1->json('meta.total'))->toBe(12);
    expect($page1->json('meta.per_page'))->toBe(10);
    expect($page1->json('meta.last_page'))->toBe(2);
    expect($page1->json('meta.current_page'))->toBe(1);
    expect($page1->json('data'))->toHaveCount(10);

    $page2 = test()->getJson('/api/v1/bookings/history?page=2')->assertOk();
    expect($page2->json('meta.current_page'))->toBe(2);
    expect($page2->json('data'))->toHaveCount(2);
});

it('keeps the existing booking item field names unchanged', function () {
    [$user, $customer] = cbhmCustomerWithUser();
    $truckType = cbhmTruckType();
    $booking = cbhmBooking($customer, $truckType);

    Sanctum::actingAs($user, ['*']);
    $response = test()->getJson('/api/v1/bookings/history')->assertOk();

    $row = $response->json('data.0');
    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'booking_code', 'status', 'pickup_address', 'dropoff_address',
        'distance_km', 'computed_total', 'final_total', 'truck_type_name',
        'vehicle_type_name', 'created_at', 'group_code', 'group_booking_code',
        'quotation_number', 'service_type', 'scheduled_date', 'scheduled_time',
    ]);
    expect($row['booking_code'])->toBe($booking->booking_code);
});
