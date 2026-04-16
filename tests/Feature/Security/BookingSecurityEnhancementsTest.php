<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
