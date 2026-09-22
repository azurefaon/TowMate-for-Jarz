<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function sgrDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function sgrCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'SGR Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'sgr-' . uniqid() . '@example.com',
    ]);
}

function sgrTruckType(float $baseRate, float $perKmRate): TruckType
{
    return TruckType::create([
        'name' => 'SGR Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

it('exposes a group roster on the Scheduled queue row so every vehicle in the group can be opened and priced', function () {
    $dispatcher = sgrDispatcher();
    $customer = sgrCustomer();
    $truckA = sgrTruckType(1500, 60);
    $truckB = sgrTruckType(900, 40);
    $groupCode = 'GRP-SGR001';

    $bookingA = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckA->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12.0,
        'base_rate' => $truckA->base_rate,
        'per_km_rate' => $truckA->per_km_rate,
        'status' => 'scheduled',
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    $bookingB = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckB->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12.0,
        'base_rate' => $truckB->base_rate,
        'per_km_rate' => $truckB->per_km_rate,
        'status' => 'scheduled',
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    $response = test()->actingAs($dispatcher)->get(route('admin.dispatch'));

    $response->assertOk();

    $html = $response->getContent();
    preg_match('/data-booking-code="' . preg_quote($bookingA->booking_code, '/') . '"[^>]*data-group-roster="([^"]*)"/', $html, $matches);

    expect($matches)->toHaveCount(2);

    $roster = json_decode(html_entity_decode($matches[1]), true);

    expect($roster)->toHaveCount(2);
    expect(collect($roster)->pluck('booking_code')->all())->toEqualCanonicalizing([
        $bookingA->booking_code,
        $bookingB->booking_code,
    ]);
    expect(collect($roster)->firstWhere('booking_code', $bookingB->booking_code))
        ->toMatchArray([
            'base_rate' => 900.0,
            'per_km_rate' => 40.0,
        ]);
});

it('includes each unpriced sibling own base rate and per-km rate in the quotation details group_siblings once a draft exists', function () {
    $dispatcher = sgrDispatcher();
    $customer = sgrCustomer();
    $truckA = sgrTruckType(1500, 60);
    $truckB = sgrTruckType(900, 40);
    $groupCode = 'GRP-SGR002';

    $bookingA = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckA->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12.0,
        'base_rate' => $truckA->base_rate,
        'per_km_rate' => $truckA->per_km_rate,
        'status' => 'scheduled',
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    $bookingB = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckB->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12.0,
        'base_rate' => $truckB->base_rate,
        'per_km_rate' => $truckB->per_km_rate,
        'status' => 'scheduled',
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $bookingA), [
        'price' => '1900',
        'distance_km' => '12.0',
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();

    $response = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation));

    $response->assertOk();
    $siblings = $response->json('quotation.group_siblings');

    expect($siblings)->toHaveCount(1);
    expect($siblings[0]['booking_code'])->toBe($bookingB->booking_code);
    expect((float) $siblings[0]['base_rate'])->toBe(900.0);
    expect((float) $siblings[0]['per_km_rate'])->toBe(40.0);
});
