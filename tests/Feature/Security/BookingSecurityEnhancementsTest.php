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

function pinnedSecurityRole(int $id, string $name): Role
{
    if ($existing = Role::find($id)) {
        return $existing;
    }

    $role = tap(new Role(['name' => $name]), function ($role) use ($id) {
        $role->id = $id;
        $role->save();
    });

    if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql') {
        \Illuminate\Support\Facades\DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
    }

    return $role;
}

it('prevents system admin from changing an existing user role', function () {
    $systemAdminRole = pinnedSecurityRole(6, 'System Admin');
    $dispatcherRole = Role::firstOrCreate(['name' => 'Dispatcher'], ['description' => 'Dispatcher']);
    $teamLeaderRole = Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Team Leader']);

    $systemAdmin = User::factory()->create([
        'role_id' => $systemAdminRole->id,
        'email' => 'systemadmin@gmail.com',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'role_id' => $dispatcherRole->id,
        'email' => 'dispatcher@gmail.com',
    ]);

    $response = $this->actingAs($systemAdmin)
        ->putJson(route('system-admin.users.update', $user), [
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
        // Pricing uses the server's own direct distance, never the routing provider's 12 km (anti-tamper).
        ->assertJsonPath('pricing.distance_km', 3.26)
        ->assertJsonPath('pricing.distance_fee', 0)
        ->assertJsonPath('pricing.discount_amount', 300)
        ->assertJsonPath('pricing.final_total', 1344)
        ->assertJsonPath('availability.book_now_enabled', false)
        ->assertJsonPath('availability.recommended_service_type', 'schedule');
});

it('prices the per-km rate from the truck type and ignores the legacy vehicle category multiplier setting', function () {
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

    // Per-km comes from the truck type's own rate; the old category multiplier no longer applies.
    expect((float) $pricing['per_km_rate'])->toBe(85.0)
        ->and((float) $pricing['distance_fee'])->toBe(85.0);
});

it('prevents super admin from disabling a busy tow truck type', function () {
    // The Owner role is id 1; the route middleware matches on role id.
    $superAdminRole = Role::find(1) ?: tap(new Role(['name' => 'Owner']), function ($role) {
        $role->id = 1;
        $role->save();
    });

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

    // The guest web booking form was retired; booking now goes through the API, which calls this check.
    expect(fn () => app(\App\Services\BookingService::class)->checkCustomerEligibility($customer))
        ->toThrow(\App\Exceptions\Booking\BlacklistedCustomerException::class);
});

it('shows the customer risk watchlist in the superadmin monitoring center', function () {
    // The Owner role is id 1 (it used to be created here as "Super Admin" with whatever id came next).
    $superAdminRole = Role::find(1) ?: tap(new Role(['name' => 'Owner']), function ($role) {
        $role->id = 1;
        $role->save();
    });

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
