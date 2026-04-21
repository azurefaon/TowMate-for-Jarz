<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('stores booking names in structured fields, normalizes philippine phone numbers, and computes pricing dynamically', function () {
    Storage::fake('public');

    SystemSetting::setValue('discount_percentage', '20');
    SystemSetting::setValue('discount_reason', 'PWD discount');

    TruckType::create([
        'name' => 'Flatbed Truck',
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Flatbed support',
    ]);

    $response = $this->from(route('landing.book'))
        ->post(route('landing.book.store'), [
            'first_name' => 'Maria',
            'middle_name' => 'Lopez',
            'last_name' => 'Santos',
            'age' => 31,
            'phone' => '9123456789',
            'email' => 'maria@gmail.com',
            'truck_type_id' => 'Flatbed Truck',
            'pickup_address' => 'Ortigas Center',
            'pickup_lat' => '14.5872',
            'pickup_lng' => '121.0569',
            'dropoff_address' => 'Pasig City Hall',
            'drop_lat' => '14.5764',
            'drop_lng' => '121.0851',
            'vehicle_category' => '4_wheeler',
            'customer_type' => 'pwd',
            'confirmation_type' => 'system',
            'vehicle_image' => UploadedFile::fake()->image('car.png'),
            'notes' => 'Vehicle stalled near the entrance.',
        ]);

    $response->assertRedirect(route('landing'));
    $response->assertSessionHasNoErrors();

    $customer = Customer::latest('id')->first();
    $booking = Booking::latest('id')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->first_name)->toBe('Maria')
        ->and($customer->middle_name)->toBe('Lopez')
        ->and($customer->last_name)->toBe('Santos')
        ->and($customer->full_name)->toContain('Maria')
        ->and($customer->phone)->toBe('+639123456789')
        ->and($booking->customer_type)->toBe('pwd')
        ->and((float) $booking->discount_percentage)->toBe(20.0)
        ->and((float) $booking->base_rate)->toBe(1800.0)
        ->and((float) $booking->per_km_rate)->toBe(85.0)
        ->and($booking->vehicle_image_path)->not->toBeNull();

    Storage::disk('public')->assertExists($booking->vehicle_image_path);
});

it('prevents super admin from changing an existing user role', function () {
    $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin'], ['description' => 'Super Admin']);
    $dispatcherRole = Role::firstOrCreate(['name' => 'Dispatcher'], ['description' => 'Dispatcher']);
    $teamLeaderRole = Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Team Leader']);

    $superAdmin = User::factory()->create([
        'role_id' => $superAdminRole->id,
        'email' => 'superadmin@gmail.com',
    ]);

    $user = User::factory()->create([
        'role_id' => $dispatcherRole->id,
        'email' => 'dispatcher@gmail.com',
    ]);

    $response = $this->actingAs($superAdmin)
        ->putJson(route('superadmin.users.update', $user), [
            'first_name' => 'Updated',
            'last_name' => 'User',
            'email' => 'dispatcher@gmail.com',
            'status' => 'active',
            'role_id' => $teamLeaderRole->id,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('role_id');

    expect($user->fresh()->role_id)->toBe($dispatcherRole->id);
});

it('returns a live pricing preview with breakdown and scheduled fallback when no dispatch-ready unit exists', function () {
    SystemSetting::setValue('booking_base_rate', '1000');
    SystemSetting::setValue('booking_per_km_rate', '50');
    SystemSetting::setValue('excess_km_threshold', '10');
    SystemSetting::setValue('excess_km_rate', '20');
    SystemSetting::setValue('discount_percentage', '20');
    SystemSetting::setValue('discount_reason', 'PWD and senior discount');

    $truckType = TruckType::create([
        'name' => 'Standard Carrier',
        'base_rate' => 1500,
        'per_km_rate' => 80,
        'max_tonnage' => 6,
        'description' => 'Standard support',
    ]);

    Http::fake([
        'https://api.openrouteservice.org/*' => Http::response([
            'features' => [[
                'geometry' => [
                    'coordinates' => [
                        [121.0569, 14.5872],
                        [121.0851, 14.5764],
                    ],
                ],
                'properties' => [
                    'summary' => [
                        'distance' => 12000,
                        'duration' => 900,
                    ],
                ],
            ]],
        ], 200),
    ]);

    $response = $this->postJson(route('geo.pricing.preview'), [
        'truck_type_id' => $truckType->id,
        'pickup_lat' => 14.5872,
        'pickup_lng' => 121.0569,
        'drop_lat' => 14.5764,
        'drop_lng' => 121.0851,
        'customer_type' => 'senior',
        'service_type' => 'book_now',
    ]);

    $response->assertOk()
        ->assertJsonPath('pricing.base_rate', 1500)
        ->assertJsonPath('pricing.per_km_rate', 80)
        ->assertJsonPath('pricing.distance_km', 12)
        ->assertJsonPath('pricing.distance_fee', 960)
        ->assertJsonPath('pricing.excess_km', 2)
        ->assertJsonPath('pricing.excess_fee', 40)
        ->assertJsonPath('pricing.discount_amount', 500)
        ->assertJsonPath('pricing.final_total', 2000)
        ->assertJsonPath('availability.book_now_enabled', false)
        ->assertJsonPath('availability.recommended_service_type', 'schedule');
});

it('automatically converts an unavailable book now request into a one-hour scheduled booking after customer confirmation', function () {
    Storage::fake('public');

    TruckType::create([
        'name' => 'Fallback Schedule Truck',
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Fallback schedule support',
    ]);

    $response = $this->from(route('landing.book'))
        ->post(route('landing.book.store'), [
            'first_name' => 'Aira',
            'last_name' => 'Mendoza',
            'age' => 29,
            'phone' => '9123456789',
            'email' => 'aira@gmail.com',
            'truck_type_id' => 'Fallback Schedule Truck',
            'pickup_address' => 'Ortigas Center',
            'pickup_lat' => '14.5872',
            'pickup_lng' => '121.0569',
            'dropoff_address' => 'Pasig City Hall',
            'drop_lat' => '14.5764',
            'drop_lng' => '121.0851',
            'vehicle_category' => '4_wheeler',
            'customer_type' => 'regular',
            'service_type' => 'book_now',
            'schedule_fallback_accepted' => '1',
            'confirmation_type' => 'system',
        ]);

    $response->assertRedirect(route('landing'))
        ->assertSessionHasNoErrors();

    $booking = Booking::latest('id')->first();

    expect($booking)->not->toBeNull()
        ->and($booking->service_type)->toBe('schedule')
        ->and($booking->scheduled_for)->not->toBeNull()
        ->and($booking->scheduled_for->between(now()->addMinutes(55), now()->addMinutes(65)))->toBeTrue();
});

it('stores pickup notes separately for the map booking flow', function () {
    Storage::fake('public');

    TruckType::create([
        'name' => 'Pickup Notes Truck',
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Pickup notes support',
    ]);

    $response = $this->from(route('landing.book'))
        ->post(route('landing.book.store'), [
            'first_name' => 'Paolo',
            'last_name' => 'Dela Cruz',
            'age' => 27,
            'phone' => '9123456789',
            'email' => 'paolo@gmail.com',
            'truck_type_id' => 'Pickup Notes Truck',
            'pickup_address' => 'Jollibee Ortigas',
            'pickup_lat' => '14.5872',
            'pickup_lng' => '121.0569',
            'dropoff_address' => 'Pasig City Hall',
            'drop_lat' => '14.5764',
            'drop_lng' => '121.0851',
            'pickup_notes' => 'Sa tabi ng Jollibee near gate 2',
            'distance' => '5',
            'vehicle_category' => '4_wheeler',
            'customer_type' => 'regular',
            'confirmation_type' => 'system',
        ]);

    $response->assertRedirect(route('landing'));

    $booking = Booking::latest('id')->first();

    expect($booking)->not->toBeNull()
        ->and((string) ($booking->pickup_notes ?? ''))->toContain('Jollibee');
});

it('uses the configured vehicle category multiplier instead of a hardcoded wheel rate', function () {
    SystemSetting::setValue('booking_base_rate', '1000');
    SystemSetting::setValue('booking_per_km_rate', '50');
    SystemSetting::setValue('booking_category_multiplier_4_wheeler', '1.25');
    SystemSetting::setValue('excess_km_threshold', '10');
    SystemSetting::setValue('excess_km_rate', '20');

    $truckType = TruckType::create([
        'name' => 'Wheel Category Test Truck',
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Wheel category support',
    ]);

    $pricing = app(\App\Services\BookingService::class)->calculatePricing([
        'truck_type_id' => $truckType->id,
        'distance' => '5',
        'vehicle_category' => '4_wheeler',
        'customer_type' => 'regular',
    ]);

    expect((float) $pricing['per_km_rate'])->toBe(106.25)
        ->and((float) $pricing['distance_fee'])->toBe(531.25);
});

it('rejects bookings with identical pickup and dropoff coordinates', function () {
    TruckType::create([
        'name' => 'Zero Distance Truck',
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 6,
        'description' => 'Zero distance validation support',
    ]);

    $response = $this->from(route('landing.book'))
        ->post(route('landing.book.store'), [
            'first_name' => 'Luna',
            'last_name' => 'Garcia',
            'age' => 30,
            'phone' => '9123456789',
            'email' => 'luna@gmail.com',
            'truck_type_id' => 'Zero Distance Truck',
            'pickup_address' => 'Ortigas Center',
            'pickup_lat' => '14.5872',
            'pickup_lng' => '121.0569',
            'dropoff_address' => 'Ortigas Center',
            'drop_lat' => '14.5872',
            'drop_lng' => '121.0569',
            'customer_type' => 'regular',
            'confirmation_type' => 'system',
        ]);

    $response->assertRedirect(route('landing.book'))
        ->assertSessionHasErrors(['dropoff_address']);
});

it('uses the selected truck type pricing when creating a booking estimate', function () {
    Storage::fake('public');

    SystemSetting::setValue('booking_base_rate', '1000');
    SystemSetting::setValue('booking_per_km_rate', '50');
    SystemSetting::setValue('excess_km_threshold', '10');
    SystemSetting::setValue('excess_km_rate', '20');
    SystemSetting::setValue('discount_percentage', '20');
    SystemSetting::setValue('discount_reason', 'PWD discount');

    TruckType::create([
        'name' => 'Global Rate Test Truck',
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Global test support',
    ]);

    $response = $this->from(route('landing.book'))
        ->post(route('landing.book.store'), [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'age' => 29,
            'phone' => '9123456789',
            'email' => 'ana@gmail.com',
            'truck_type_id' => 'Global Rate Test Truck',
            'pickup_address' => 'Ortigas Center',
            'pickup_lat' => '14.5872',
            'pickup_lng' => '121.0569',
            'dropoff_address' => 'Pasig City Hall',
            'drop_lat' => '14.5764',
            'drop_lng' => '121.0851',
            'distance' => '12',
            'vehicle_category' => '4_wheeler',
            'customer_type' => 'pwd',
            'confirmation_type' => 'system',
        ]);

    $response->assertRedirect(route('landing'));

    $booking = Booking::latest('id')->first();

    expect($booking)->not->toBeNull()
        ->and((float) $booking->base_rate)->toBe(1800.0)
        ->and((float) $booking->per_km_rate)->toBe(85.0)
        ->and((float) $booking->computed_total)->toBe(2860.0)
        ->and((float) $booking->final_total)->toBe(2288.0);
});

it('keeps the customer selected book now mode when an active team leader is available', function () {
    $truckType = TruckType::create([
        'name' => 'Immediate Dispatch Truck',
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'max_tonnage' => 6,
        'description' => 'Immediate dispatch support',
    ]);

    $teamLeaderRole = Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Tow unit team leader']);
    $teamLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
    ]);

    Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));

    Unit::create([
        'name' => 'Immediate Dispatch Unit',
        'plate_number' => 'IMM-1001',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    $response = $this->from(route('landing.book'))
        ->post(route('landing.book.store'), [
            'first_name' => 'Rico',
            'last_name' => 'Mendoza',
            'age' => 34,
            'phone' => '9123456789',
            'email' => 'rico@gmail.com',
            'truck_type_id' => 'Immediate Dispatch Truck',
            'pickup_address' => 'Ortigas Center',
            'pickup_lat' => '14.5872',
            'pickup_lng' => '121.0569',
            'dropoff_address' => 'Pasig City Hall',
            'drop_lat' => '14.5764',
            'drop_lng' => '121.0851',
            'distance' => '12',
            'vehicle_category' => '4_wheeler',
            'customer_type' => 'regular',
            'service_type' => 'book_now',
            'confirmation_type' => 'system',
        ]);

    $response->assertRedirect(route('landing'));

    $booking = Booking::latest('id')->first();

    expect($booking)->not->toBeNull()
        ->and($booking->service_type)->toBe('book_now')
        ->and($booking->scheduled_for)->toBeNull();
});

it('shows inactive truck types as unavailable and non-selectable in customer booking forms', function () {
    Role::firstOrCreate(['name' => 'Super Admin'], ['description' => 'Super Admin']);
    Role::firstOrCreate(['name' => 'Dispatcher'], ['description' => 'Dispatcher']);
    Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Team Leader']);
    Role::firstOrCreate(['name' => 'Driver'], ['description' => 'Driver']);
    $customerRole = Role::firstOrCreate(['name' => 'Customer'], ['description' => 'Customer']);

    $customerUser = User::factory()->create([
        'role_id' => $customerRole->id,
    ]);

    TruckType::create([
        'name' => 'Ready Carrier',
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 4,
        'description' => 'Available tow unit',
        'status' => 'active',
    ]);

    $inactiveType = TruckType::create([
        'name' => 'Busy Carrier',
        'base_rate' => 1900,
        'per_km_rate' => 95,
        'max_tonnage' => 6,
        'description' => 'Temporarily unavailable tow unit',
        'status' => 'inactive',
    ]);

    $this->get(route('landing.book'))
        ->assertOk()
        ->assertSee('Busy Carrier (Unavailable)');

    $this->actingAs($customerUser)
        ->get(route('customer.book'))
        ->assertOk()
        ->assertSee('Busy Carrier (Unavailable)')
        ->assertSee('disabled', false);
});

it('prevents super admin from disabling a busy tow truck type', function () {
    $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin'], ['description' => 'Super Admin']);

    $superAdmin = User::factory()->create([
        'role_id' => $superAdminRole->id,
        'email' => 'busytypesuperadmin@gmail.com',
    ]);

    $truckType = TruckType::create([
        'name' => 'Protected Busy Type',
        'base_rate' => 2100,
        'per_km_rate' => 120,
        'max_tonnage' => 8,
        'description' => 'Cannot be disabled while active on jobs',
        'status' => 'active',
    ]);

    Booking::create([
        'customer_id' => Customer::create([
            'full_name' => 'Busy Booking Customer',
            'phone' => '+639111111112',
            'email' => 'busybooking@gmail.com',
        ])->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Makati',
        'dropoff_address' => 'Pasig',
        'status' => 'assigned',
    ]);

    $this->actingAs($superAdmin)
        ->from(route('superadmin.truck-types.index'))
        ->patch(route('superadmin.truck-types.toggle', $truckType))
        ->assertRedirect(route('superadmin.truck-types.index'))
        ->assertSessionHas('error');

    expect($truckType->fresh()->status)->toBe('active');
});

it('returns a non-zero fallback ETA when the routing provider is unavailable', function () {
    Http::fake([
        'https://api.openrouteservice.org/*' => Http::response([], 500),
        'https://router.project-osrm.org/*' => Http::response([], 500),
    ]);

    $response = $this->postJson(route('geo.route'), [
        'pickup_lat' => 14.5872,
        'pickup_lng' => 121.0569,
        'drop_lat' => 14.5764,
        'drop_lng' => 121.0851,
    ]);

    $response->assertOk();

    expect((float) $response->json('distance_km'))->toBeGreaterThan(0)
        ->and((float) $response->json('duration_min'))->toBeGreaterThan(0)
        ->and((bool) $response->json('is_fallback'))->toBeTrue();
});

it('purges bookings customers and receipts older than 14 days', function () {
    $customer = Customer::create([
        'full_name' => 'Old Customer',
        'phone' => '+639111111111',
        'email' => 'oldcustomer@gmail.com',
    ]);

    $customer->forceFill([
        'created_at' => now()->subDays(15),
        'updated_at' => now()->subDays(15),
    ])->save();

    $truckType = TruckType::create([
        'name' => 'SUV Carrier',
        'base_rate' => 1600,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'SUV carrier',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'created_by_admin_id' => null,
        'pickup_address' => 'Old pickup',
        'dropoff_address' => 'Old dropoff',
        'status' => 'completed',
    ]);

    $booking->forceFill([
        'created_at' => now()->subDays(15),
        'updated_at' => now()->subDays(15),
    ])->save();

    $generatedBy = User::factory()->create();

    $receipt = Receipt::create([
        'booking_id' => $booking->id,
        'generated_by' => $generatedBy->id,
        'receipt_number' => 'R-OLD-1001',
        'pdf_path' => 'receipts/old.pdf',
    ]);

    $receipt->forceFill([
        'created_at' => now()->subDays(15),
        'updated_at' => now()->subDays(15),
    ])->save();

    $this->artisan('towmate:purge-expired-data')->assertExitCode(0);

    expect(Booking::find($booking->id))->toBeNull()
        ->and(Customer::find($customer->id))->toBeNull()
        ->and(Receipt::where('receipt_number', 'R-OLD-1001')->exists())->toBeFalse();
});

it('tags risky customers from dispatch rejections and blocks blacklisted rebooking', function () {
    $dispatcherRole = Role::find(2);

    if (! $dispatcherRole) {
        $dispatcherRole = new Role([
            'name' => 'Dispatcher',
            'description' => 'Dispatcher',
        ]);
        $dispatcherRole->id = 2;
        $dispatcherRole->save();
    }

    $dispatcher = User::factory()->create([
        'role_id' => $dispatcherRole->id,
    ]);

    $truckType = TruckType::create([
        'name' => 'Risk Policy Truck',
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'max_tonnage' => 5,
        'description' => 'Risk policy support',
    ]);

    $customer = Customer::create([
        'full_name' => 'Blocked Customer',
        'phone' => '+639188877766',
        'email' => 'blocked@example.com',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'created_by_admin_id' => $dispatcher->id,
        'pickup_address' => 'Makati Avenue',
        'dropoff_address' => 'Pasig City',
        'distance_km' => 7,
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'computed_total' => 2160,
        'final_total' => 2160,
        'status' => 'requested',
    ]);

    $this->actingAs($dispatcher)
        ->post(route('admin.booking.assign', $booking), [
            'action' => 'reject',
            'rejection_reason' => 'Customer was unreachable at pickup and refused to pay the agreed towing fee.',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $customer->refresh();

    expect($customer->risk_level)->toBe('blacklisted')
        ->and($customer->blacklisted_at)->not->toBeNull()
        ->and((string) $customer->risk_reason)->toContain('refused to pay');

    $response = $this->from(route('landing.book'))
        ->post(route('landing.book.store'), [
            'first_name' => 'Blocked',
            'last_name' => 'Customer',
            'age' => 35,
            'phone' => '09188877766',
            'email' => 'blocked@example.com',
            'truck_type_id' => 'Risk Policy Truck',
            'pickup_address' => 'Ortigas Center',
            'pickup_lat' => '14.5872',
            'pickup_lng' => '121.0569',
            'dropoff_address' => 'Pasig City Hall',
            'drop_lat' => '14.5764',
            'drop_lng' => '121.0851',
            'vehicle_category' => '4_wheeler',
            'customer_type' => 'regular',
            'confirmation_type' => 'system',
        ]);

    $response->assertRedirect(route('landing.book'))
        ->assertSessionHasErrors(['phone']);
});

it('shows the customer risk watchlist in the superadmin monitoring center', function () {
    $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin'], ['description' => 'Super Admin']);

    $superAdmin = User::factory()->create([
        'role_id' => $superAdminRole->id,
        'email' => 'riskwatchsuperadmin@example.com',
    ]);

    Customer::create([
        'full_name' => 'Watchlist Customer',
        'phone' => '+639177700001',
        'email' => 'watch@example.com',
        'risk_level' => 'watchlist',
        'risk_reason' => 'Customer was unreachable during the last booking.',
    ]);

    Customer::create([
        'full_name' => 'Blacklisted Customer',
        'phone' => '+639177700002',
        'email' => 'blacklisted@example.com',
        'risk_level' => 'blacklisted',
        'risk_reason' => 'Customer refused to pay after service dispatch.',
        'blacklisted_at' => now(),
    ]);

    $this->actingAs($superAdmin)
        ->get(route('superadmin.monitoring.index'))
        ->assertOk()
        ->assertSee('Risk Watchlist')
        ->assertSee('Watchlist Customer')
        ->assertSee('Blacklisted Customer')
        ->assertSee('Blacklisted');
});
