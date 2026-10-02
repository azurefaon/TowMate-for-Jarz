<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

function gvpDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function gvpBooking(Customer $customer, TruckType $truck, string $group, string $serviceType, ?string $photoPath): Booking
{
    $attrs = [
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'group_code' => $group,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 12.0,
        'base_rate' => $truck->base_rate,
        'per_km_rate' => $truck->per_km_rate,
        'status' => $serviceType === 'schedule' ? 'scheduled' : 'requested',
        'service_type' => $serviceType,
        'vehicle_image_path' => $photoPath,
    ];

    if ($serviceType === 'schedule') {
        $attrs['scheduled_date'] = now()->addDay()->toDateString();
        $attrs['scheduled_time'] = '10:00';
    }

    return Booking::create($attrs)->fresh(['customer', 'truckType']);
}

/** Vehicle 1 → photo A, Vehicle 2 → photo B, Vehicle 3 → no photo. */
function gvpGroup(string $serviceType, string $group): array
{
    $customer = Customer::create([
        'full_name' => 'GVP Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'gvp-' . uniqid() . '@example.com',
    ]);
    $trucks = collect([[1500, 60], [900, 40], [2200, 75]])->map(fn($r) => TruckType::create([
        'name' => 'GVP Truck ' . fake()->unique()->word(),
        'base_rate' => $r[0],
        'per_km_rate' => $r[1],
        'status' => 'active',
    ]));

    return [
        gvpBooking($customer, $trucks[0], $group, $serviceType, 'vehicle_images/gvp-photo-a.jpg'),
        gvpBooking($customer, $trucks[1], $group, $serviceType, json_encode(['vehicle_images/gvp-photo-b.jpg'])),
        gvpBooking($customer, $trucks[2], $group, $serviceType, null),
    ];
}

function gvpRosterFromQueueRow(User $dispatcher, Booking $anchor): array
{
    $html = test()->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk()->getContent();
    preg_match('/<tr[^>]*data-booking-code="' . preg_quote($anchor->booking_code, '/') . '"[^>]*>/s', $html, $row);
    expect($row)->not->toBeEmpty();
    preg_match('/data-group-roster="([^"]*)"/', $row[0], $m);
    expect($m)->not->toBeEmpty();

    return json_decode(html_entity_decode($m[1]), true);
}

function gvpAssertPhotos(array $vehicles, array $bookings): void
{
    $byId = collect($vehicles)->keyBy('booking_id');
    expect($byId[$bookings[0]->id]['photos'])->toBe([Storage::disk('public')->url('vehicle_images/gvp-photo-a.jpg')]);
    expect($byId[$bookings[1]->id]['photos'])->toBe([Storage::disk('public')->url('vehicle_images/gvp-photo-b.jpg')]);
    expect($byId[$bookings[2]->id]['photos'])->toBe([]);
}

it('gives every vehicle in the Book Now / Intermediate pre-save roster its own photos', function () {
    $dispatcher = gvpDispatcher();
    $bookings = gvpGroup('book_now', 'GRP-GVP-1');

    gvpAssertPhotos(gvpRosterFromQueueRow($dispatcher, $bookings[0]), $bookings);
});

it('gives every vehicle in the Scheduled pre-save roster its own photos', function () {
    $dispatcher = gvpDispatcher();
    $bookings = gvpGroup('schedule', 'GRP-GVP-2');

    $html = test()->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk()->getContent();
    preg_match('/data-booking-code="' . preg_quote($bookings[0]->booking_code, '/') . '"[^>]*data-group-roster="([^"]*)"/', $html, $m);
    expect($m)->toHaveCount(2);

    gvpAssertPhotos(json_decode(html_entity_decode($m[1]), true), $bookings);
});

it('keeps per-vehicle photos in the saved quotation group_vehicles payload', function () {
    Mail::fake();
    $dispatcher = gvpDispatcher();
    $bookings = gvpGroup('book_now', 'GRP-GVP-3');

    test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $bookings[0]), [
        'price' => '1900',
        'distance_km' => '12',
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $bookings[0]->id)->current()->first();
    $response = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation))->assertOk();

    expect($response->json('quotation.group_vehicles'))->toHaveCount(3);
    gvpAssertPhotos($response->json('quotation.group_vehicles'), $bookings);
});

it('leaves a single-vehicle booking card photos untouched and exposes no group roster', function () {
    $dispatcher = gvpDispatcher();
    $customer = Customer::create([
        'full_name' => 'GVP Solo',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'gvp-solo-' . uniqid() . '@example.com',
    ]);
    $truck = TruckType::create(['name' => 'GVP Solo Truck', 'base_rate' => 1500, 'per_km_rate' => 60, 'status' => 'active']);
    $solo = gvpBooking($customer, $truck, '', 'book_now', 'vehicle_images/gvp-solo.jpg');

    $html = test()->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk()->getContent();
    preg_match('/<tr[^>]*data-booking-code="' . preg_quote($solo->booking_code, '/') . '"[^>]*>/s', $html, $row);
    preg_match('/data-photos="([^"]*)"/', $row[0], $photos);
    preg_match('/data-group-roster="([^"]*)"/', $row[0], $roster);

    expect(json_decode(html_entity_decode($photos[1]), true))->toBe([Storage::disk('public')->url('vehicle_images/gvp-solo.jpg')]);
    expect(json_decode(html_entity_decode($roster[1] ?? '[]'), true))->toBe([]);
});
