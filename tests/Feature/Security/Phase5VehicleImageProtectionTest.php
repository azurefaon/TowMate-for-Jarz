<?php

use App\Console\Commands\MigrateProtectedFilesToPrivateDisk;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function p5Role(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function p5CustomerWithBooking(): array
{
    $user = User::factory()->create(['role_id' => p5Role(5, 'Customer')->id]);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);
    $truckType = TruckType::create(['name' => 'P5 Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);

    return [$user, $customer, $truckType];
}

function p5TlWithBooking(): array
{
    $tl = User::factory()->create(['role_id' => p5Role(3, 'Team Leader')->id, 'must_change_password' => false]);
    $truckType = TruckType::create(['name' => 'P5 TL Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $unit = Unit::create([
        'name' => 'P5 Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $tl->id,
        'status' => 'on_job',
    ]);
    $customer = Customer::create(['full_name' => 'P5 Customer', 'phone' => '09170000007', 'email' => 'p5-' . uniqid() . '@example.com']);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'accepted', 'assigned_at' => now(),
    ]);

    return [$tl, $booking->fresh()];
}

it('1: a newly uploaded vehicle image is stored on the private disk, not the public disk', function () {
    [$user, $customer, $truckType] = p5CustomerWithBooking();
    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5,
        'pickup_lng' => 121.0,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6,
        'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ]);
    $response->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    $paths = json_decode($booking->vehicle_image_path, true);

    expect($paths)->not->toBeEmpty();
    foreach ($paths as $path) {
        expect(Storage::disk('local')->exists($path))->toBeTrue();
        expect(Storage::disk('public')->exists($path))->toBeFalse();
    }
});

it('2: the raw /storage path cannot retrieve the newly uploaded vehicle image', function () {
    [$user, $customer, $truckType] = p5CustomerWithBooking();
    Sanctum::actingAs($user, ['*']);

    test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Origin', 'pickup_lat' => 14.5, 'pickup_lng' => 121.0,
        'dropoff_address' => 'Destination', 'dropoff_lat' => 14.6, 'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ])->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    $path = json_decode($booking->vehicle_image_path, true)[0];

    test()->get('/storage/' . $path)->assertStatus(404);
});

it('3: the owning Customer receives a working signed URL for their own vehicle image via booking detail', function () {
    [$user, $customer, $truckType] = p5CustomerWithBooking();
    Sanctum::actingAs($user, ['*']);

    test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Origin', 'pickup_lat' => 14.5, 'pickup_lng' => 121.0,
        'dropoff_address' => 'Destination', 'dropoff_lat' => 14.6, 'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ])->assertCreated();

    $booking = Booking::where('customer_id', $customer->id)->latest()->first();
    $path = json_decode($booking->vehicle_image_path, true)[0];
    $url = protected_file_url($path);

    expect($url)->toContain('/protected-storage/');
    test()->get($url)->assertOk();
});

it('4: Customer A cannot derive or use a working signed URL for Customer B vehicle image without going through Customer B booking access', function () {
    [$userA] = p5CustomerWithBooking();
    [$userB, $customerB, $truckTypeB] = p5CustomerWithBooking();
    Sanctum::actingAs($userB, ['*']);
    test()->postJson('/api/v1/bookings', [
        'truck_type_id' => $truckTypeB->id,
        'pickup_address' => 'Origin', 'pickup_lat' => 14.5, 'pickup_lng' => 121.0,
        'dropoff_address' => 'Destination', 'dropoff_lat' => 14.6, 'dropoff_lng' => 121.1,
        'distance_km' => 5,
        'vehicle_images' => [UploadedFile::fake()->image('vehicle.jpg')],
    ])->assertCreated();
    $bookingB = Booking::where('customer_id', $customerB->id)->latest()->first();

    Sanctum::actingAs($userA, ['*']);
    $response = test()->getJson("/api/v1/bookings/{$bookingB->booking_code}/detail");

    $response->assertStatus(404);
    expect($response->getContent())->not->toContain('/protected-storage/');
});

it('5: the assigned Team Leader receives a working vehicle image URL for their own task', function () {
    [$tl, $booking] = p5TlWithBooking();
    $booking->update(['vehicle_image_path' => json_encode(['vehicle_images/p5-tl-owned.jpg'])]);
    Storage::disk('local')->put('vehicle_images/p5-tl-owned.jpg', 'bytes');
    Sanctum::actingAs($tl, ['*']);

    $response = test()->getJson('/api/v1/team-leader/task');

    $response->assertOk();
    expect($response->json('data.vehicle_image_url'))->toContain('/protected-storage/');

    Storage::disk('local')->delete('vehicle_images/p5-tl-owned.jpg');
});

it('6: an unrelated Team Leader cannot retrieve another job vehicle image through the task endpoints', function () {
    [, $bookingB] = p5TlWithBooking();
    $bookingB->update(['vehicle_image_path' => json_encode(['vehicle_images/p5-unrelated.jpg'])]);
    Storage::disk('local')->put('vehicle_images/p5-unrelated.jpg', 'bytes');
    [$tlA] = p5TlWithBooking();
    Sanctum::actingAs($tlA, ['*']);

    test()->patchJson("/api/v1/team-leader/task/{$bookingB->booking_code}/status", ['status' => 'on_the_way'])
        ->assertStatus(403);

    Storage::disk('local')->delete('vehicle_images/p5-unrelated.jpg');
});

it('7: the Dispatcher quotation-details endpoint resolves vehicle images to protected URLs, not raw public paths', function () {
    $dispatcher = User::factory()->create(['role_id' => p5Role(2, 'Admin')->id, 'must_change_password' => false]);
    $truckType = TruckType::create(['name' => 'P5 Dispatch Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $customer = Customer::create(['full_name' => 'P5 Dispatch Customer', 'phone' => '09170000008', 'email' => 'p5disp-' . uniqid() . '@example.com']);
    Storage::disk('local')->put('vehicle_images/p5-dispatch.jpg', 'bytes');
    $quotation = \App\Models\Quotation::create([
        'quotation_number' => 'Q-P5DISP-' . uniqid(),
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 3,
        'estimated_price' => 1500,
        'status' => 'sent',
        'sent_at' => now(),
        'vehicle_image_path' => json_encode(['vehicle_images/p5-dispatch.jpg']),
    ]);

    $response = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation));

    $response->assertOk();
    $urls = $response->json('quotation.vehicle_image_paths');
    expect($urls[0])->toContain('/protected-storage/');

    Storage::disk('local')->delete('vehicle_images/p5-dispatch.jpg');
});

it('8: a signed vehicle image URL expires', function () {
    Storage::disk('local')->put('vehicle_images/p5-expiring.jpg', 'bytes');
    $url = protected_file_url('vehicle_images/p5-expiring.jpg', 1);

    test()->travel(2)->minutes();

    test()->get($url)->assertStatus(403);

    Storage::disk('local')->delete('vehicle_images/p5-expiring.jpg');
});

it('9: a tampered vehicle image signed URL is rejected', function () {
    Storage::disk('local')->put('vehicle_images/p5-tamper.jpg', 'bytes');
    $url = protected_file_url('vehicle_images/p5-tamper.jpg', 30);
    $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=' . str_repeat('1', 64), $url);

    test()->get($tampered)->assertStatus(403);

    Storage::disk('local')->delete('vehicle_images/p5-tamper.jpg');
});

it('10: a legacy vehicle image still on the public disk resolves through the fallback before migration', function () {
    Storage::disk('public')->put('vehicle_images/p5-legacy.jpg', 'legacy-bytes');

    $url = protected_file_url('vehicle_images/p5-legacy.jpg');

    expect($url)->toContain('/storage/vehicle_images/p5-legacy.jpg');
    expect(Storage::disk('public')->exists('vehicle_images/p5-legacy.jpg'))->toBeTrue();

    Storage::disk('public')->delete('vehicle_images/p5-legacy.jpg');
});

it('11: the migration command moves a legacy vehicle image to the private disk and removes the public copy', function () {
    [, $customer] = p5CustomerWithBooking();
    $truckType = TruckType::create(['name' => 'P5 Legacy Truck', 'base_rate' => 1000, 'per_km_rate' => 50]);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1000, 'per_km_rate' => 50, 'computed_total' => 1250, 'final_total' => 1400,
        'status' => 'requested',
        'vehicle_image_path' => json_encode(['vehicle_images/p5-migrate-me.jpg']),
    ]);
    Storage::disk('public')->put('vehicle_images/p5-migrate-me.jpg', 'legacy-bytes');

    $this->artisan('towmate:migrate-protected-files')->assertExitCode(0);

    expect(Storage::disk('local')->exists('vehicle_images/p5-migrate-me.jpg'))->toBeTrue();
    expect(Storage::disk('public')->exists('vehicle_images/p5-migrate-me.jpg'))->toBeFalse();
    expect(Storage::disk('local')->get('vehicle_images/p5-migrate-me.jpg'))->toBe('legacy-bytes');

    Storage::disk('local')->delete('vehicle_images/p5-migrate-me.jpg');
});

it('12: a dry-run of the migration command does not move or delete any file', function () {
    [, $customer] = p5CustomerWithBooking();
    $truckType = TruckType::create(['name' => 'P5 DryRun Truck', 'base_rate' => 1000, 'per_km_rate' => 50]);
    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1000, 'per_km_rate' => 50, 'computed_total' => 1250, 'final_total' => 1400,
        'status' => 'requested',
        'vehicle_image_path' => json_encode(['vehicle_images/p5-dryrun.jpg']),
    ]);
    Storage::disk('public')->put('vehicle_images/p5-dryrun.jpg', 'dryrun-bytes');

    $this->artisan('towmate:migrate-protected-files', ['--dry-run' => true])->assertExitCode(0);

    expect(Storage::disk('public')->exists('vehicle_images/p5-dryrun.jpg'))->toBeTrue();
    expect(Storage::disk('local')->exists('vehicle_images/p5-dryrun.jpg'))->toBeFalse();

    Storage::disk('public')->delete('vehicle_images/p5-dryrun.jpg');
});

it('13: running the migration command twice is idempotent and safe', function () {
    [, $customer] = p5CustomerWithBooking();
    $truckType = TruckType::create(['name' => 'P5 Idempotent Truck', 'base_rate' => 1000, 'per_km_rate' => 50]);
    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1000, 'per_km_rate' => 50, 'computed_total' => 1250, 'final_total' => 1400,
        'status' => 'requested',
        'vehicle_image_path' => json_encode(['vehicle_images/p5-idempotent.jpg']),
    ]);
    Storage::disk('public')->put('vehicle_images/p5-idempotent.jpg', 'idempotent-bytes');

    $this->artisan('towmate:migrate-protected-files')->assertExitCode(0);
    $this->artisan('towmate:migrate-protected-files')->assertExitCode(0);

    expect(Storage::disk('local')->exists('vehicle_images/p5-idempotent.jpg'))->toBeTrue();
    expect(Storage::disk('local')->get('vehicle_images/p5-idempotent.jpg'))->toBe('idempotent-bytes');

    Storage::disk('local')->delete('vehicle_images/p5-idempotent.jpg');
});
