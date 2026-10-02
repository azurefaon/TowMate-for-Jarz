<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Services\QuotationService;
use Illuminate\Support\Facades\Storage;

function gbpCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'GBP Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'gbp-' . uniqid() . '@example.test',
    ]);
}

function gbpTruck(): TruckType
{
    return TruckType::create(['name' => 'GBP Truck ' . uniqid(), 'base_rate' => 1500, 'per_km_rate' => 60, 'status' => 'active']);
}

/** Production shape: extra_vehicles carry NO photo fields (only service_type, truck_type_id, estimated_price, vehicle_type_id). */
function gbpAcceptedGroup(?string $primaryPhoto, int $extras = 2): array
{
    $customer = gbpCustomer();
    $truck = gbpTruck();

    $quotation = Quotation::create([
        'quotation_number' => 'GBP-' . uniqid(),
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 10,
        'estimated_price' => 2500,
        'service_type' => 'book_now',
        'status' => 'sent',
        'is_current' => true,
        'sent_at' => now(),
        'vehicle_image_path' => $primaryPhoto ? json_encode([$primaryPhoto]) : null,
        'extra_vehicles' => collect(range(1, $extras))->map(fn() => [
            'service_type' => 'book_now',
            'truck_type_id' => gbpTruck()->id,
            'estimated_price' => 1800,
            'vehicle_type_id' => null,
        ])->all(),
    ]);

    $primary = app(QuotationService::class)->acceptQuotation($quotation);
    $siblings = Booking::where('group_code', $quotation->quotation_number)->where('id', '!=', $primary->id)->orderBy('id')->get();

    return [$primary->fresh(), $siblings];
}

it('does not copy the primary quotation photo into sibling bookings created at acceptance', function () {
    [$primary, $siblings] = gbpAcceptedGroup('vehicle_images/gbp-primary.jpg');

    expect($siblings)->toHaveCount(2);
    expect($primary->vehicle_image_paths)->toBe(['vehicle_images/gbp-primary.jpg']);

    foreach ($siblings as $sibling) {
        expect($sibling->vehicle_image_paths)->toBe([]);
        expect($sibling->getRawOriginal('vehicle_image_path'))->toBeNull();
    }
});

it('leaves every vehicle blank when the quotation has no photo at all', function () {
    [$primary, $siblings] = gbpAcceptedGroup(null);

    expect($primary->vehicle_image_paths)->toBe([]);
    expect($siblings->every(fn($s) => $s->vehicle_image_paths === []))->toBeTrue();
});

it('keeps an existing source booking photo untouched and gives new siblings none', function () {
    $customer = gbpCustomer();
    $truck = gbpTruck();
    $source = Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $truck->id, 'pickup_address' => 'P', 'dropoff_address' => 'D',
        'distance_km' => 10, 'base_rate' => 1500, 'per_km_rate' => 60, 'status' => 'requested', 'service_type' => 'book_now',
        'vehicle_image_path' => json_encode(['vehicle_images/gbp-source.jpg']),
    ]);
    $quotation = Quotation::create([
        'quotation_number' => 'GBP-' . uniqid(), 'customer_id' => $customer->id, 'truck_type_id' => $truck->id,
        'source_booking_id' => $source->id, 'pickup_address' => 'P', 'dropoff_address' => 'D', 'distance_km' => 10,
        'estimated_price' => 2500, 'service_type' => 'book_now', 'status' => 'sent', 'is_current' => true, 'sent_at' => now(),
        'vehicle_image_path' => json_encode(['vehicle_images/gbp-source.jpg']),
        'extra_vehicles' => [['service_type' => 'book_now', 'truck_type_id' => gbpTruck()->id, 'estimated_price' => 1800, 'vehicle_type_id' => null]],
    ]);

    app(QuotationService::class)->acceptQuotation($quotation);

    expect($source->fresh()->vehicle_image_paths)->toBe(['vehicle_images/gbp-source.jpg']);
    $sibling = Booking::where('group_code', $quotation->quotation_number)->where('id', '!=', $source->id)->first();
    expect($sibling)->not->toBeNull();
    expect($sibling->vehicle_image_paths)->toBe([]);
});

it('keeps a vehicle without a photo blank in the dispatch fresh-photo payload (UI shows "No vehicle photo provided")', function () {
    foreach ([1 => 'Owner', 2 => 'Dispatcher'] as $id => $name) {
        if (! Role::find($id)) {
            tap(new Role(['name' => $name]), function ($r) use ($id) { $r->id = $id; $r->save(); });
        }
    }
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
    [$primary, $siblings] = gbpAcceptedGroup('vehicle_images/gbp-primary.jpg');

    $res = $this->actingAs($dispatcher)->getJson(route('admin.booking.photos', $primary))->assertOk();
    $byId = collect($res->json('vehicles'))->keyBy('booking_id');

    expect($byId[$primary->id]['photos'])->toBe([Storage::disk('public')->url('vehicle_images/gbp-primary.jpg')]);
    foreach ($siblings as $sibling) {
        expect($byId[$sibling->id]['photos'])->toBe([]);
    }
});
