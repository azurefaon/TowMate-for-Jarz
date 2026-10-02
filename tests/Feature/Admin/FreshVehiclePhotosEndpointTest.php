<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

function fvpUser(int $roleId): User
{
    foreach ([2 => 'Dispatcher', 5 => 'Customer'] as $id => $name) {
        if (! Role::find($id)) {
            tap(new Role(['name' => $name]), function ($r) use ($id) {
                $r->id = $id;
                $r->save();
            });
        }
    }

    return User::factory()->create(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false]);
}

function fvpBooking(Customer $c, string $group, ?string $photo): Booking
{
    $truck = TruckType::create(['name' => 'FVP Truck ' . uniqid(), 'base_rate' => 1000, 'per_km_rate' => 50, 'status' => 'active']);

    return Booking::create([
        'customer_id' => $c->id,
        'truck_type_id' => $truck->id,
        'group_code' => $group,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 10,
        'base_rate' => 1000,
        'per_km_rate' => 50,
        'status' => 'requested',
        'service_type' => 'book_now',
        'vehicle_image_path' => $photo,
    ]);
}

function fvpCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'FVP',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'fvp-' . uniqid() . '@example.com',
    ]);
}

it('returns fresh per-vehicle photos for a grouped booking: A, B and none', function () {
    $c = fvpCustomer();
    $v1 = fvpBooking($c, 'GRP-FVP-1', 'vehicle_images/fvp-a.jpg');
    $v2 = fvpBooking($c, 'GRP-FVP-1', 'vehicle_images/fvp-b.jpg');
    $v3 = fvpBooking($c, 'GRP-FVP-1', null);

    // Asking from any vehicle of the group returns the whole group, in id order.
    $res = $this->actingAs(fvpUser(2))->getJson(route('admin.booking.photos', $v2))->assertOk();

    expect($res->json('booking_id'))->toBe($v2->id);
    expect(collect($res->json('vehicles'))->pluck('booking_id')->all())->toBe([$v1->id, $v2->id, $v3->id]);
    expect(collect($res->json('vehicles'))->pluck('photos')->all())->toBe([
        [Storage::disk('public')->url('vehicle_images/fvp-a.jpg')],
        [Storage::disk('public')->url('vehicle_images/fvp-b.jpg')],
        [],
    ]);
});

it('generates signed URLs at request time instead of reusing earlier ones', function () {
    Storage::fake('local');
    Storage::disk('local')->put('vehicle_images/fvp-private.jpg', 'x');
    $booking = fvpBooking(fvpCustomer(), '', 'vehicle_images/fvp-private.jpg');
    $user = fvpUser(2);

    $first = $this->actingAs($user)->getJson(route('admin.booking.photos', $booking))->json('vehicles.0.photos.0');
    $this->travel(20)->minutes();
    $second = $this->actingAs($user)->getJson(route('admin.booking.photos', $booking))->json('vehicles.0.photos.0');

    parse_str(parse_url($first, PHP_URL_QUERY), $q1);
    parse_str(parse_url($second, PHP_URL_QUERY), $q2);

    // Real local disks sign with "expires", the faked test disk with "expiration".
    $expiry = fn(array $q) => (int) ($q['expires'] ?? $q['expiration'] ?? 0);

    expect($expiry($q1))->toBeGreaterThan(0);
    // The later request's link is later than the first one,
    // so it was minted at request time rather than copied from earlier data.
    expect($expiry($q2))->toBeGreaterThan($expiry($q1));
    expect($expiry($q2))->toBeGreaterThan(now()->timestamp);
});

it('keeps single-vehicle bookings working', function () {
    $booking = fvpBooking(fvpCustomer(), '', 'vehicle_images/fvp-solo.jpg');

    $res = $this->actingAs(fvpUser(2))->getJson(route('admin.booking.photos', $booking))->assertOk();

    expect($res->json('vehicles'))->toHaveCount(1);
    expect($res->json('vehicles.0.booking_id'))->toBe($booking->id);
    expect($res->json('vehicles.0.photos'))->toBe([Storage::disk('public')->url('vehicle_images/fvp-solo.jpg')]);
});

it('rejects unauthenticated and non-dispatcher access', function () {
    $booking = fvpBooking(fvpCustomer(), '', 'vehicle_images/fvp-secret.jpg');

    $this->getJson(route('admin.booking.photos', $booking))->assertStatus(401);
    $this->actingAs(fvpUser(5))->getJson(route('admin.booking.photos', $booking))->assertForbidden();
});

it('returns 404 for an unknown booking instead of leaking anything', function () {
    $this->actingAs(fvpUser(2))->getJson(route('admin.booking.photos', 'NOPE-0000'))->assertNotFound();
});
