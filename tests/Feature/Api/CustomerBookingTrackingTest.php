<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/*
 * Customer Live Tracking v1 — GET /api/v1/bookings/{code}/tracking
 */

const CBT_PICKUP_LAT  = 14.6760123;
const CBT_PICKUP_LNG  = 121.0437456;
const CBT_DROPOFF_LAT = 14.5547789;
const CBT_DROPOFF_LNG = 121.0244321;

function cbtRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cbtCustomer(): array
{
    $user = User::factory()->create(['role_id' => cbtRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function cbtTruckType(): TruckType
{
    return TruckType::create([
        'name'        => 'CBT Truck ' . fake()->unique()->word(),
        'base_rate'   => 1500,
        'per_km_rate' => 60,
        'status'      => 'active',
    ]);
}

/**
 * A unit with a real Team Leader + crew attached, so the tests can prove
 * none of that personal data leaks into the tracking payload.
 */
function cbtUnit(TruckType $truck, ?float $lat = null, ?float $lng = null, ?Carbon $updatedAt = null, ?float $accuracy = null): Unit
{
    $leader = User::factory()->create([
        'role_id' => cbtRole(3, 'Team Leader')->id,
        'status'  => 'active',
        'name'    => 'Leaky Leader ' . fake()->unique()->numerify('###'),
    ]);

    return Unit::create([
        'name'                => 'CBT Unit ' . fake()->unique()->numerify('###'),
        'plate_number'        => fake()->unique()->bothify('CBT-####'),
        'truck_type_id'       => $truck->id,
        'team_leader_id'      => $leader->id,
        'driver_name'         => 'Secret Driver',
        'crew_member_1_name'  => 'Secret Crew',
        'status'              => 'available',
        'current_lat'         => $lat,
        'current_lng'         => $lng,
        'location_accuracy'   => $accuracy,
        'location_updated_at' => $updatedAt,
    ]);
}

function cbtBooking(Customer $customer, TruckType $truck, string $status, ?Unit $unit = null, ?string $groupCode = null): Booking
{
    $booking = Booking::create([
        'customer_id'      => $customer->id,
        'truck_type_id'    => $truck->id,
        'assigned_unit_id' => $unit?->id,
        'group_code'       => $groupCode,
        'pickup_address'   => 'Quezon City Pickup',
        'pickup_lat'       => CBT_PICKUP_LAT,
        'pickup_lng'       => CBT_PICKUP_LNG,
        'dropoff_address'  => 'Makati Dropoff',
        'dropoff_lat'      => CBT_DROPOFF_LAT,
        'dropoff_lng'      => CBT_DROPOFF_LNG,
        'distance_km'      => 12,
        'base_rate'        => 1500,
        'per_km_rate'      => 60,
        'final_total'      => 2217.6,
        'status'           => $status,
        'service_type'     => 'book_now',
    ]);
    $booking->update(['booking_code' => 'TM-CBT-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

function cbtTrack(Booking $booking)
{
    return test()->getJson("/api/v1/bookings/{$booking->booking_code}/tracking");
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-02 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

// 1
it('returns live pickup tracking for the owner while on_the_way', function () {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $unit = cbtUnit($truck, 14.6001, 121.0002, now()->subSeconds(12), 8.5);
    $booking = cbtBooking($customer, $truck, 'on_the_way', $unit);

    Sanctum::actingAs($user);
    $response = cbtTrack($booking);

    $response->assertOk()
        ->assertHeader('Cache-Control')
        ->assertExactJson([
            'success' => true,
            'data' => [
                'tracking'     => true,
                'booking_code' => $booking->booking_code,
                'status'       => 'on_the_way',
                'phase'        => 'pickup',
                'freshness'    => 'live',
                'location'     => ['lat' => 14.6001, 'lng' => 121.0002, 'accuracy' => 8.5, 'age_seconds' => 12],
                'last_seen'    => null,
                'destination'  => ['lat' => CBT_PICKUP_LAT, 'lng' => CBT_PICKUP_LNG],
            ],
        ]);

    // Privacy: no unit / Team Leader / crew data in the payload.
    $raw = $response->getContent();
    expect($raw)->not->toContain($unit->plate_number)
        ->and($raw)->not->toContain($unit->name)
        ->and($raw)->not->toContain('Leaky Leader')
        ->and($raw)->not->toContain('Secret Driver')
        ->and($raw)->not->toContain('Secret Crew')
        ->and($raw)->not->toContain('team_leader')
        ->and($raw)->not->toContain('unit_id');
});

// 2
it('returns drop-off tracking for the owner while on_job', function () {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $unit = cbtUnit($truck, 14.5801, 121.0101, now()->subSeconds(5));
    $booking = cbtBooking($customer, $truck, 'on_job', $unit);

    Sanctum::actingAs($user);

    cbtTrack($booking)->assertOk()
        ->assertJsonPath('data.tracking', true)
        ->assertJsonPath('data.phase', 'dropoff')
        ->assertJsonPath('data.freshness', 'live')
        ->assertJsonPath('data.location.lat', 14.5801)
        ->assertJsonPath('data.location.lng', 121.0101)
        ->assertJsonPath('data.location.accuracy', null)
        ->assertJsonPath('data.destination', ['lat' => CBT_DROPOFF_LAT, 'lng' => CBT_DROPOFF_LNG]);
});

// 3
it('returns 404 when another customer requests the booking', function () {
    [, $owner] = cbtCustomer();
    [$intruder] = cbtCustomer();
    $truck = cbtTruckType();
    $unit = cbtUnit($truck, 14.6001, 121.0002, now()->subSeconds(3));
    $booking = cbtBooking($owner, $truck, 'on_the_way', $unit);

    Sanctum::actingAs($intruder);
    $response = cbtTrack($booking);

    $response->assertNotFound()->assertJsonPath('success', false);
    expect($response->getContent())->not->toContain('14.6001')
        ->and($response->getContent())->not->toContain('121.0002');
});

it('returns 404 for an authenticated user with no customer profile', function () {
    [, $owner] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($owner, $truck, 'on_the_way', cbtUnit($truck, 14.6, 121.0, now()));

    $leader = User::factory()->create(['role_id' => cbtRole(3, 'Team Leader')->id, 'status' => 'active']);
    Sanctum::actingAs($leader);

    cbtTrack($booking)->assertNotFound();
});

it('returns 404 for an unknown booking code', function () {
    [$user] = cbtCustomer();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookings/TM-DOES-NOT-EXIST/tracking')->assertNotFound();
});

// 4
it('returns tracking=false with no coordinates for non-trackable statuses', function (string $status) {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $unit = cbtUnit($truck, 14.6001, 121.0002, now()->subSeconds(2));
    $booking = cbtBooking($customer, $truck, $status, $unit);

    Sanctum::actingAs($user);
    $response = cbtTrack($booking);

    $response->assertOk()->assertExactJson([
        'success' => true,
        'data' => [
            'tracking'     => false,
            'booking_code' => $booking->booking_code,
            'status'       => $status,
            'phase'        => null,
            'freshness'    => null,
            'location'     => null,
            'last_seen'    => null,
            'destination'  => null,
        ],
    ]);
    expect($response->getContent())->not->toContain('14.6001')
        ->and($response->getContent())->not->toContain('121.0002');
})->with(['requested', 'accepted', 'assigned', 'in_progress', 'waiting_verification', 'completed', 'cancelled']);

// 5
it('reports live when the location is at most 30 seconds old', function (int $age) {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($customer, $truck, 'on_the_way', cbtUnit($truck, 14.6, 121.0, now()->subSeconds($age)));

    Sanctum::actingAs($user);

    cbtTrack($booking)->assertOk()
        ->assertJsonPath('data.freshness', 'live')
        ->assertJsonPath('data.location.age_seconds', $age);
})->with([0, 30]);

// 6
it('reports updating when the location is 31 to 90 seconds old', function (int $age) {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($customer, $truck, 'on_the_way', cbtUnit($truck, 14.6, 121.0, now()->subSeconds($age)));

    Sanctum::actingAs($user);

    cbtTrack($booking)->assertOk()
        ->assertJsonPath('data.freshness', 'updating')
        ->assertJsonPath('data.location.lat', 14.6)
        ->assertJsonPath('data.location.age_seconds', $age)
        ->assertJsonPath('data.last_seen', null);
})->with([31, 90]);

// 7
it('reports unavailable and withholds coordinates when the location is older than 90 seconds', function (int $age) {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($customer, $truck, 'on_job', cbtUnit($truck, 14.6001, 121.0002, now()->subSeconds($age)));

    Sanctum::actingAs($user);
    $response = cbtTrack($booking);

    $response->assertOk()
        ->assertJsonPath('data.tracking', true)
        ->assertJsonPath('data.phase', 'dropoff')
        ->assertJsonPath('data.freshness', 'unavailable')
        ->assertJsonPath('data.location', null)
        ->assertJsonPath('data.last_seen', ['age_seconds' => $age])
        ->assertJsonPath('data.destination', ['lat' => CBT_DROPOFF_LAT, 'lng' => CBT_DROPOFF_LNG]);

    expect($response->getContent())->not->toContain('14.6001')
        ->and($response->getContent())->not->toContain('121.0002');
})->with([91, 3600]);

it('computes age from server time and ignores any client-supplied clock', function () {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($customer, $truck, 'on_the_way', cbtUnit($truck, 14.6, 121.0, now()->subSeconds(120)));

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/bookings/{$booking->booking_code}/tracking?now=" . urlencode(now()->subSeconds(120)->toIso8601String()), [
        'Date' => now()->subSeconds(120)->toRfc7231String(),
    ])->assertOk()
        ->assertJsonPath('data.freshness', 'unavailable')
        ->assertJsonPath('data.location', null);
});

// 8
it('keeps the tracking phase active but unavailable when no unit is assigned', function () {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($customer, $truck, 'on_the_way');

    Sanctum::actingAs($user);

    cbtTrack($booking)->assertOk()
        ->assertJsonPath('data.tracking', true)
        ->assertJsonPath('data.phase', 'pickup')
        ->assertJsonPath('data.freshness', 'unavailable')
        ->assertJsonPath('data.location', null)
        ->assertJsonPath('data.last_seen', null)
        ->assertJsonPath('data.destination', ['lat' => CBT_PICKUP_LAT, 'lng' => CBT_PICKUP_LNG]);
});

// 9
it('keeps the tracking phase active but unavailable when the unit has no location', function () {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($customer, $truck, 'on_job', cbtUnit($truck));

    Sanctum::actingAs($user);

    cbtTrack($booking)->assertOk()
        ->assertJsonPath('data.tracking', true)
        ->assertJsonPath('data.phase', 'dropoff')
        ->assertJsonPath('data.freshness', 'unavailable')
        ->assertJsonPath('data.location', null)
        ->assertJsonPath('data.last_seen', null);
});

it('treats coordinates without a timestamp as unavailable', function () {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($customer, $truck, 'on_the_way', cbtUnit($truck, 14.6001, 121.0002, null));

    Sanctum::actingAs($user);
    $response = cbtTrack($booking);

    $response->assertOk()
        ->assertJsonPath('data.freshness', 'unavailable')
        ->assertJsonPath('data.location', null);
    expect($response->getContent())->not->toContain('14.6001');
});

// 10
it('never gives grouped sibling A the location of sibling B unit', function () {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();

    $unitA = cbtUnit($truck); // no location yet
    $unitB = cbtUnit($truck, 14.7777, 121.8888, now()->subSeconds(4));

    $siblingA = cbtBooking($customer, $truck, 'on_the_way', $unitA, 'CBT-GROUP-1');
    $siblingB = cbtBooking($customer, $truck, 'on_the_way', $unitB, 'CBT-GROUP-1');
    $siblingC = cbtBooking($customer, $truck, 'on_the_way', null, 'CBT-GROUP-1'); // unassigned

    Sanctum::actingAs($user);

    foreach ([$siblingA, $siblingC] as $sibling) {
        $response = cbtTrack($sibling);
        $response->assertOk()
            ->assertJsonPath('data.booking_code', $sibling->booking_code)
            ->assertJsonPath('data.freshness', 'unavailable')
            ->assertJsonPath('data.location', null);
        expect($response->getContent())->not->toContain('14.7777')
            ->and($response->getContent())->not->toContain('121.8888');
    }

    cbtTrack($siblingB)->assertOk()
        ->assertJsonPath('data.booking_code', $siblingB->booking_code)
        ->assertJsonPath('data.freshness', 'live')
        ->assertJsonPath('data.location.lat', 14.7777)
        ->assertJsonPath('data.location.lng', 121.8888);
});

// 11
it('switches the destination from pickup to drop-off as the job progresses', function () {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($customer, $truck, 'on_the_way', cbtUnit($truck, 14.6, 121.0, now()));

    Sanctum::actingAs($user);

    cbtTrack($booking)->assertOk()
        ->assertJsonPath('data.phase', 'pickup')
        ->assertJsonPath('data.destination', ['lat' => CBT_PICKUP_LAT, 'lng' => CBT_PICKUP_LNG]);

    $booking->update(['status' => 'on_job']);

    cbtTrack($booking)->assertOk()
        ->assertJsonPath('data.phase', 'dropoff')
        ->assertJsonPath('data.destination', ['lat' => CBT_DROPOFF_LAT, 'lng' => CBT_DROPOFF_LNG]);

    $booking->update(['status' => 'completed']);

    cbtTrack($booking)->assertOk()
        ->assertJsonPath('data.tracking', false)
        ->assertJsonPath('data.destination', null)
        ->assertJsonPath('data.location', null);
});

// 12
it('rejects unauthenticated requests', function () {
    [, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($customer, $truck, 'on_the_way', cbtUnit($truck, 14.6001, 121.0002, now()));

    $response = cbtTrack($booking);

    $response->assertUnauthorized();
    expect($response->getContent())->not->toContain('14.6001');
});

it('throttles tracking polls at 30 per minute per customer', function () {
    [$user, $customer] = cbtCustomer();
    $truck = cbtTruckType();
    $booking = cbtBooking($customer, $truck, 'on_the_way', cbtUnit($truck, 14.6, 121.0, now()));

    Sanctum::actingAs($user);

    for ($i = 0; $i < 30; $i++) {
        cbtTrack($booking)->assertOk();
    }

    cbtTrack($booking)->assertStatus(429);
});

it('registers exactly one customer tracking route and drops the dangling /track route', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());

    expect($uris)->toContain('api/v1/bookings/{code}/tracking')
        ->and($uris)->not->toContain('api/v1/bookings/{booking}/track');

    expect(Route::getRoutes()->match(request()->create('/api/v1/bookings/TM-1/tracking', 'GET'))->getActionName())
        ->toBe(\App\Http\Controllers\Api\CustomerBookingController::class . '@tracking');
});
