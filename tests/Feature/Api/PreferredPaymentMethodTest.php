<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\OwnerBookingPresenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

function ppmRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ppmCustomer(): User
{
    $user = User::factory()->create(['role_id' => ppmRole(5, 'Customer')->id, 'status' => 'active']);
    Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return $user;
}

/** @return array{0: TruckType, 1: VehicleType} a vehicle type with a ready unit behind it */
function ppmVehicle(string $name): array
{
    $truck = TruckType::create([
        'name' => 'PPM Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
    $leader = User::factory()->create(['role_id' => ppmRole(3, 'Team Leader')->id]);
    Unit::create([
        'name' => 'PPM Unit ' . fake()->unique()->word(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truck->id,
        'status' => 'available',
        'team_leader_id' => $leader->id,
        'driver_name' => 'PPM Driver',
    ]);
    $vehicle = VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $truck->id,
        'status' => 'active',
    ]);

    return [$truck, $vehicle];
}

function ppmPayload(VehicleType $vehicle, array $overrides = []): array
{
    return array_merge([
        'vehicle_type_id' => $vehicle->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'book_now',
        'vehicle_images' => [UploadedFile::fake()->image('ppm.jpg', 300, 300)],
    ], $overrides);
}

function ppmAssertStillUnpaid(Booking $booking): void
{
    expect($booking->payment_method)->toBeNull();
    expect($booking->payment_submitted_at)->toBeNull();
    expect($booking->payment_proof_path)->toBeNull();
    expect($booking->cash_received)->toBeNull();
    expect($booking->status)->not->toBeIn(['waiting_verification', 'completed']);
}

it('stores each allowed preference on the booking without touching the collected payment fields', function (string $method) {
    $user = ppmCustomer();
    [, $vehicle] = ppmVehicle('PPM Sedan ' . $method);
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/bookings', ppmPayload($vehicle, ['preferred_payment_method' => $method]))
        ->assertCreated();

    $booking = Booking::where('booking_code', $response->json('booking_code'))->firstOrFail();

    expect($booking->preferred_payment_method)->toBe($method);
    ppmAssertStillUnpaid($booking);
    expect($response->json('bookings.0.preferred_payment_method'))->toBe($method);

    // Survives retrieval: detail and the "current booking" endpoints echo it, payment_method stays null.
    $this->getJson("/api/v1/bookings/{$booking->booking_code}/detail")
        ->assertOk()
        ->assertJsonPath('data.preferred_payment_method', $method)
        ->assertJsonPath('data.payment_method', null);
})->with(['cash', 'gcash', 'bank_transfer']);

it('rejects an unknown preference and creates nothing', function (mixed $bad) {
    $user = ppmCustomer();
    [, $vehicle] = ppmVehicle('PPM Reject');
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/bookings', ppmPayload($vehicle, ['preferred_payment_method' => $bad]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('preferred_payment_method');

    expect(Booking::count())->toBe(0);
})->with(['online_banking', 'paypal', 'CASH', 'array' => [['cash']]]);

it('keeps older app versions working: no preference field means a null preference, not an error', function () {
    $user = ppmCustomer();
    [, $vehicle] = ppmVehicle('PPM Legacy');
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/bookings', ppmPayload($vehicle))->assertCreated();

    $booking = Booking::where('booking_code', $response->json('booking_code'))->firstOrFail();
    expect($booking->preferred_payment_method)->toBeNull();
    expect($response->json('bookings.0.preferred_payment_method'))->toBeNull();
    ppmAssertStillUnpaid($booking);
});

it('never stores wallet or bank credentials sent alongside the preference', function () {
    $user = ppmCustomer();
    [, $vehicle] = ppmVehicle('PPM Creds');
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/bookings', ppmPayload($vehicle, [
        'preferred_payment_method' => 'gcash',
        'gcash_number' => '09171234567',
        'bank_account_number' => '1234567890',
        'otp' => '123456',
        'payment_method' => 'gcash',
        'payment_status' => 'paid',
    ]))->assertCreated();

    $booking = Booking::where('booking_code', $response->json('booking_code'))->firstOrFail();
    $json = json_encode($booking->getAttributes());

    expect($booking->payment_method)->toBeNull();
    expect($json)->not->toContain('09171234567')->not->toContain('1234567890')->not->toContain('123456"');
    expect(Schema::hasColumn('bookings', 'payment_status'))->toBeFalse();
});

it('records the preference on scheduled bookings and leaves them unpaid', function () {
    $user = ppmCustomer();
    [, $vehicle] = ppmVehicle('PPM Scheduled');
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/bookings', ppmPayload($vehicle, [
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDays(3)->toDateString(),
        'scheduled_time' => '10:00',
        'preferred_payment_method' => 'bank_transfer',
    ]))->assertCreated();

    $booking = Booking::where('booking_code', $response->json('booking_code'))->firstOrFail();
    expect($booking->status)->toBe('scheduled');
    expect($booking->preferred_payment_method)->toBe('bank_transfer');
    ppmAssertStillUnpaid($booking);
});

it('applies one preference to every booking and the quotation of a grouped Book Now request', function () {
    $user = ppmCustomer();
    [, $primary] = ppmVehicle('PPM Group Primary');
    [, $extra1] = ppmVehicle('PPM Group Extra 1');
    [, $extra2] = ppmVehicle('PPM Group Extra 2');
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/bookings', ppmPayload($primary, [
        'preferred_payment_method' => 'gcash',
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $extra1->id],
            ['vehicle_type_id' => $extra2->id],
        ]),
        'extra_vehicle_images' => [
            0 => [UploadedFile::fake()->image('e1.jpg', 300, 300)],
            1 => [UploadedFile::fake()->image('e2.jpg', 300, 300)],
        ],
    ]))->assertCreated();

    $bookings = Booking::where('group_code', $response->json('group_code'))->get();
    expect($bookings)->toHaveCount(3);
    expect($bookings->pluck('preferred_payment_method')->unique()->all())->toBe(['gcash']);
    $bookings->each(fn (Booking $b) => ppmAssertStillUnpaid($b));

    $quotation = Quotation::where('source_booking_id', $response->json('bookings.0') ? Booking::where('booking_code', $response->json('booking_code'))->value('id') : 0)->first();
    expect($quotation?->preferred_payment_method)->toBe('gcash');
});

it('applies one preference to every sibling of a grouped scheduled request', function () {
    $user = ppmCustomer();
    [, $primary] = ppmVehicle('PPM Sched Group Primary');
    [, $extra] = ppmVehicle('PPM Sched Group Extra');
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/bookings', ppmPayload($primary, [
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'preferred_payment_method' => 'cash',
        'extra_vehicles' => json_encode([['vehicle_type_id' => $extra->id]]),
        'extra_vehicle_images' => [0 => [UploadedFile::fake()->image('e1.jpg', 300, 300)]],
    ]))->assertCreated();

    $bookings = Booking::where('group_code', $response->json('group_code'))->get();
    expect($bookings)->toHaveCount(2);
    expect($bookings->pluck('preferred_payment_method')->unique()->all())->toBe(['cash']);
});

it('keeps the preference and the collected method as two separate facts', function () {
    $customer = Customer::create([
        'full_name' => 'Maria Santos',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'ppm-' . uniqid() . '@example.com',
    ]);
    $truck = TruckType::firstOrCreate(['name' => 'PPM Display'], ['base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 2100,
        'vat_exclusive_total' => 2100,
        'vat_amount' => 252,
        'final_total' => 2352,
        'service_type' => 'book_now',
        'status' => 'waiting_verification',
        'preferred_payment_method' => 'gcash',
        'payment_method' => 'cash',
        'payment_submitted_at' => now(),
        'cash_received' => 2500,
    ]);

    $payment = app(OwnerBookingPresenter::class)->invoiceReceipt($booking)['payment'];

    expect($payment['preferred'])->toBe('GCash');
    expect($payment['value'])->toBe('Submitted');
    expect($payment['note'])->toContain('Cash'); // collected method shown separately
    expect(Booking::preferredPaymentMethodLabel('bank_transfer'))->toBe('Online Banking');
    expect(Booking::preferredPaymentMethodLabel(null))->toBeNull();
});

it('shows the preferred method to the dispatcher and Owner without marking anything paid', function () {
    $customer = Customer::create([
        'full_name' => 'Dispatch View',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'ppm-d-' . uniqid() . '@example.com',
    ]);
    $truck = TruckType::firstOrCreate(['name' => 'PPM Dispatch View'], ['base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 2100,
        'vat_exclusive_total' => 2100,
        'vat_amount' => 252,
        'final_total' => 2352,
        'service_type' => 'book_now',
        'status' => 'waiting_verification',
        'preferred_payment_method' => 'bank_transfer',
        'payment_method' => 'cash',
        'payment_submitted_at' => now(),
        'cash_received' => 2500,
    ]);

    $dispatcher = User::factory()->create(['role_id' => ppmRole(2, 'Dispatcher')->id, 'must_change_password' => false]);
    ppmRole(1, 'Super Admin');

    $this->actingAs($dispatcher)
        ->get(route('admin.dispatch'))
        ->assertOk()
        ->assertSee('data-preferred-payment-label="Online Banking"', false)
        ->assertSee('data-payment-method-label="Cash"', false)
        ->assertSee('Preferred Payment Method');

    $booking->refresh();
    expect($booking->payment_method)->toBe('cash'); // collected method untouched by the preference
    expect($booking->preferred_payment_method)->toBe('bank_transfer');
    expect($booking->status)->toBe('waiting_verification');
});

it('carries the preference from a web quotation onto the booking and group sibling created at acceptance', function () {
    $customer = Customer::create([
        'full_name' => 'Web Quotation',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'ppm-q-' . uniqid() . '@example.com',
    ]);
    $truck = TruckType::create(['name' => 'PPM Q1 ' . uniqid(), 'base_rate' => 1500, 'per_km_rate' => 60, 'status' => 'active']);
    $truck2 = TruckType::create(['name' => 'PPM Q2 ' . uniqid(), 'base_rate' => 1800, 'per_km_rate' => 60, 'status' => 'active']);
    $date = now()->addDays(3)->toDateString();

    $quotation = Quotation::create([
        'quotation_number' => 'PPM-' . uniqid(),
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 10,
        'estimated_price' => 2500,
        'service_type' => 'schedule',
        'scheduled_date' => $date,
        'scheduled_time' => '11:15',
        'preferred_payment_method' => 'gcash',
        'status' => 'sent',
        'is_current' => true,
        'sent_at' => now(),
        'extra_vehicles' => [[
            'truck_type_id' => $truck2->id,
            'service_type' => 'schedule',
            'scheduled_date' => $date,
            'scheduled_time' => '12:30',
            'estimated_price' => 1800,
        ]],
    ]);

    $primary = app(\App\Services\QuotationService::class)->acceptQuotation($quotation);

    $members = Booking::where('group_code', $primary->fresh()->group_code)->get();
    expect($members)->toHaveCount(2);
    expect($members->pluck('preferred_payment_method')->unique()->all())->toBe(['gcash']);
    $members->each(fn (Booking $b) => ppmAssertStillUnpaid($b));
});

it('requires a payment preference on the customer web booking form and keeps it on the quotation', function () {
    $user = ppmCustomer();
    $truck = TruckType::create(['name' => 'PPM Web ' . uniqid(), 'base_rate' => 1500, 'per_km_rate' => 60, 'status' => 'active']);
    $payload = [
        'first_name' => 'Web',
        'last_name' => 'Customer',
        'phone' => '+639171234567',
        'email' => 'ppm.web.customer@gmail.com', // the form only accepts public mail domains
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pickup A',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Dropoff B',
        'drop_lat' => 14.6905,
        'drop_lng' => 120.9842,
        'distance_km' => 12,
        'vehicle_category' => '4_wheeler',
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDays(3)->toDateString(),
        'scheduled_time' => '10:00',
    ];

    $this->actingAs($user)->post(route('customer.book.store'), $payload)
        ->assertSessionHasErrors('preferred_payment_method');
    expect(Quotation::count())->toBe(0);

    $this->actingAs($user)->post(route('customer.book.store'), $payload + ['preferred_payment_method' => 'paypal'])
        ->assertSessionHasErrors('preferred_payment_method');
    expect(Quotation::count())->toBe(0);

    $this->actingAs($user)->post(route('customer.book.store'), $payload + ['preferred_payment_method' => 'bank_transfer'])
        ->assertSessionHasNoErrors();

    $quotation = Quotation::firstOrFail();
    expect($quotation->preferred_payment_method)->toBe('bank_transfer');
    expect(Booking::whereNotNull('payment_method')->count())->toBe(0);
});

it('shows the saved preference on the customer web booking page and the quotation page, and nothing when there is none', function () {
    $user = ppmCustomer();
    $customer = $user->customer;
    $truck = TruckType::create(['name' => 'PPM Show ' . uniqid(), 'base_rate' => 1500, 'per_km_rate' => 60, 'status' => 'active']);
    $base = [
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 2100,
        'vat_exclusive_total' => 2100,
        'vat_amount' => 252,
        'final_total' => 2352,
        'service_type' => 'book_now',
        'status' => 'requested',
    ];
    $with = Booking::create($base + ['preferred_payment_method' => 'bank_transfer']);
    $without = Booking::create($base);

    $this->actingAs($user)->get(route('customer.track', $with->booking_code ?: $with->id))
        ->assertOk()
        ->assertSee('Preferred payment')
        ->assertSee('Online Banking');

    $this->actingAs($user)->get(route('customer.track', $without->booking_code ?: $without->id))
        ->assertOk()
        ->assertDontSee('Preferred payment');

    $quotation = Quotation::create([
        'quotation_number' => 'PPM-V-' . uniqid(),
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 10,
        'estimated_price' => 2352,
        'service_type' => 'book_now',
        'preferred_payment_method' => 'gcash',
        'status' => 'sent',
        'is_current' => true,
        'sent_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    $this->get(\Illuminate\Support\Facades\URL::signedRoute('quotation.show', $quotation))
        ->assertOk()
        ->assertSee('Preferred payment')
        ->assertSee('GCash');
});
