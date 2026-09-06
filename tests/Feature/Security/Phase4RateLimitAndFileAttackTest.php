<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function p4rRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function p4rCustomerWithBooking(): array
{
    $user = User::factory()->create(['role_id' => p4rRole(5, 'Customer')->id]);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);
    $truckType = TruckType::create(['name' => 'P4R Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id'   => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'requested',
    ]);
    $booking->update(['booking_code' => 'TM-P4R' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return [$user, $customer, $booking->fresh()];
}

it('changing X-Forwarded-For does not bypass a user-keyed authenticated rate limiter', function () {
    [$user, , $booking] = p4rCustomerWithBooking();
    Sanctum::actingAs($user, ['*']);

    for ($i = 0; $i < 8; $i++) {
        test()->withHeader('X-Forwarded-For', '10.0.0.' . $i)
            ->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", []);
    }

    test()->withHeader('X-Forwarded-For', '10.0.0.99')
        ->postJson("/api/v1/bookings/{$booking->booking_code}/cancel", [])
        ->assertStatus(429);
});

it('two separate customer accounts do not share the same rate-limit bucket', function () {
    [$userA, , $bookingA] = p4rCustomerWithBooking();
    [$userB, , $bookingB] = p4rCustomerWithBooking();

    Sanctum::actingAs($userA, ['*']);
    for ($i = 0; $i < 8; $i++) {
        test()->postJson("/api/v1/bookings/{$bookingA->booking_code}/cancel", []);
    }
    test()->postJson("/api/v1/bookings/{$bookingA->booking_code}/cancel", [])->assertStatus(429);

    Sanctum::actingAs($userB, ['*']);
    test()->postJson("/api/v1/bookings/{$bookingB->booking_code}/cancel", [])->assertOk();
});

it('email case variation cannot be used to spread OTP-send attempts across separate rate-limit buckets', function () {
    $email = 'CaseTest@Example.com';

    test()->postJson('/api/password/forgot', ['email' => $email])->assertOk();
    test()->postJson('/api/password/forgot', ['email' => strtolower($email)])->assertOk();
    test()->postJson('/api/password/forgot', ['email' => 'CASETEST@EXAMPLE.COM'])->assertOk();

    test()->postJson('/api/password/forgot', ['email' => 'casetest@example.com'])->assertStatus(429);
});

it('surrounding whitespace on the email cannot be used to bypass the OTP-send rate limiter', function () {
    $email = 'whitespace-test@example.com';

    test()->postJson('/api/password/forgot', ['email' => $email])->assertOk();
    test()->postJson('/api/password/forgot', ['email' => ' ' . $email])->assertOk();
    test()->postJson('/api/password/forgot', ['email' => $email . ' '])->assertOk();

    test()->postJson('/api/password/forgot', ['email' => $email])->assertStatus(429);
});

it('an unsigned protected-storage URL is rejected', function () {
    Storage::disk('local')->put('task-photos/p4-unsigned.jpg', 'bytes');

    test()->get('/protected-storage/task-photos/p4-unsigned.jpg')->assertStatus(403);

    Storage::disk('local')->delete('task-photos/p4-unsigned.jpg');
});

it('a tampered signature on a protected-storage URL is rejected', function () {
    Storage::disk('local')->put('task-photos/p4-tamper.jpg', 'bytes');
    $url = protected_file_url('task-photos/p4-tamper.jpg', 30);
    $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=' . str_repeat('0', 64), $url);

    test()->get($tampered)->assertStatus(403);

    Storage::disk('local')->delete('task-photos/p4-tamper.jpg');
});

it('a signed URL minted for file A cannot be repointed at file B by editing the path', function () {
    Storage::disk('local')->put('task-photos/p4-file-a.jpg', 'file-a-bytes');
    Storage::disk('local')->put('task-photos/p4-file-b.jpg', 'file-b-bytes');

    $urlForA = protected_file_url('task-photos/p4-file-a.jpg', 30);
    $swapped = str_replace('p4-file-a.jpg', 'p4-file-b.jpg', $urlForA);

    test()->get($swapped)->assertStatus(403);

    Storage::disk('local')->delete('task-photos/p4-file-a.jpg');
    Storage::disk('local')->delete('task-photos/p4-file-b.jpg');
});

it('a raw public /storage path cannot retrieve a file that only exists on the private disk', function () {
    Storage::disk('local')->put('task-photos/p4-private-only.jpg', 'bytes');
    expect(Storage::disk('public')->exists('task-photos/p4-private-only.jpg'))->toBeFalse();

    test()->get('/storage/task-photos/p4-private-only.jpg')->assertStatus(404);

    Storage::disk('local')->delete('task-photos/p4-private-only.jpg');
});

it('when a stale duplicate exists on the public disk, protected_file_url still prefers the private signed copy', function () {
    Storage::disk('public')->put('task-photos/p4-confusing.jpg', 'stale-public-bytes');
    Storage::disk('local')->put('task-photos/p4-confusing.jpg', 'current-private-bytes');

    $url = protected_file_url('task-photos/p4-confusing.jpg');

    expect($url)->toContain('/protected-storage/');
    expect($url)->not->toContain('/storage/task-photos/p4-confusing.jpg');

    Storage::disk('public')->delete('task-photos/p4-confusing.jpg');
    Storage::disk('local')->delete('task-photos/p4-confusing.jpg');
});
