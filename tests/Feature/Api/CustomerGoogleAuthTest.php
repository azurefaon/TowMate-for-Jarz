<?php

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

function cgaRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cgaClaims(array $overrides = []): array
{
    return array_merge([
        'sub'            => 'google-sub-' . uniqid(),
        'email'          => 'googleuser-' . uniqid() . '@example.com',
        'email_verified' => true,
        'given_name'     => 'Gigi',
        'family_name'    => 'Delacruz',
    ], $overrides);
}

function cgaBindVerifier(?array $claims): void
{
    $fake = new class($claims) implements GoogleIdTokenVerifier {
        public function __construct(private ?array $claims)
        {
        }

        public function verify(string $idToken): ?array
        {
            return $this->claims;
        }
    };

    app()->instance(GoogleIdTokenVerifier::class, $fake);
}

beforeEach(function () {
    cgaRole(5, 'Customer');
});

it('rejects a request with no id_token', function () {
    $response = test()->postJson('/api/auth/google', []);

    $response->assertStatus(422);
});

it('rejects an unverifiable/malformed credential', function () {
    cgaBindVerifier(null);

    $response = test()->postJson('/api/auth/google', ['id_token' => 'not-a-real-jwt']);

    $response->assertStatus(422);
    expect($response->json('success'))->toBeFalse();
});

it('a fabricated request cannot substitute a claimed email for real verification', function () {
    cgaBindVerifier(null);

    $response = test()->postJson('/api/auth/google', [
        'id_token' => 'anything',
        'email'    => 'someone@gmail.com',
    ]);

    $response->assertStatus(422);
    expect(User::where('email', 'someone@gmail.com')->exists())->toBeFalse();
});

it('a verified new Google identity is staged for phone completion, no account is created yet', function () {
    $claims = cgaClaims();
    cgaBindVerifier($claims);

    $response = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);

    $response->assertStatus(202);
    expect($response->json('needs_phone'))->toBeTrue();
    expect($response->json('completion_token'))->not->toBeEmpty();
    expect($response->json('data.token'))->toBeNull();
    expect(User::where('email', $claims['email'])->exists())->toBeFalse();
});

it('completes a staged Google signup into a Customer-only account and issues a Sanctum token', function () {
    $claims = cgaClaims();
    cgaBindVerifier($claims);
    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone'            => '+639171234567',
    ]);

    $response->assertStatus(201);
    expect($response->json('data.token'))->not->toBeEmpty();
    expect($response->json('data.user.role'))->toBe('Customer');

    $user = User::where('email', $claims['email'])->first();
    expect($user)->not->toBeNull();
    expect((int) $user->role_id)->toBe(5);
    expect($user->auth_provider)->toBe('google');
    expect($user->google_sub)->toBe($claims['sub']);
    expect($user->password)->toBeNull();
    expect(Customer::where('user_id', $user->id)->exists())->toBeTrue();
});

it('cannot inject a privileged role during Google phone completion', function () {
    cgaRole(1, 'Owner');
    $claims = cgaClaims();
    cgaBindVerifier($claims);
    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone'            => '+639171234568',
        'role_id'          => 1,
        'role'             => 'Owner',
        'status'           => 'active',
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect((int) $user->role_id)->toBe(5);
});

it('rejects phone completion with a malformed phone number', function (string $phone) {
    $claims = cgaClaims();
    cgaBindVerifier($claims);
    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone'            => $phone,
    ]);

    $response->assertStatus(422);
    expect(User::where('email', $claims['email'])->exists())->toBeFalse();
})->with([
    'local 09 format' => ['09171234567'],
    'missing +63'      => ['9171234567'],
    'letters'          => ['+63917ABC456'],
    'empty'            => [''],
]);

it('rejects an expired or unknown completion token', function () {
    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => 'never-issued-token',
        'phone'            => '+639171234567',
    ]);

    $response->assertStatus(422);
});

it('a completion token cannot be replayed after it has been used', function () {
    $claims = cgaClaims();
    cgaBindVerifier($claims);
    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone'            => '+639171234569',
    ])->assertStatus(201);

    $replay = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone'            => '+639171234570',
    ]);

    $replay->assertStatus(422);
});

it('signs into the existing account when google_sub matches a previously linked identity', function () {
    $sub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id'       => 5,
        'status'        => 'active',
        'password'      => null,
        'auth_provider' => 'google',
        'google_sub'    => $sub,
    ]);

    cgaBindVerifier(cgaClaims(['sub' => $sub, 'email' => $user->email]));

    $response = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);

    $response->assertStatus(200);
    expect($response->json('data.user.id'))->toBe($user->id);
    expect(User::where('email', $user->email)->count())->toBe(1);
});

it('a different google_sub cannot take over an existing linked account via a matching email', function () {
    $existingSub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id'       => 5,
        'status'        => 'active',
        'password'      => null,
        'auth_provider' => 'google',
        'google_sub'    => $existingSub,
    ]);

    cgaBindVerifier(cgaClaims(['sub' => 'a-totally-different-sub', 'email' => $user->email]));

    $response = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);

    $response->assertStatus(409);
    expect(User::where('google_sub', 'a-totally-different-sub')->exists())->toBeFalse();
});

it('does not auto-link when a password account already owns the verified email', function () {
    $existing = User::factory()->create([
        'role_id'  => 5,
        'status'   => 'active',
        'password' => Hash::make('SomeExistingPass!1'),
    ]);

    cgaBindVerifier(cgaClaims(['email' => $existing->email]));

    $response = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);

    $response->assertStatus(409);
    expect($response->json('message'))->toContain('Sign in with your password first');

    $fresh = $existing->fresh();
    expect($fresh->auth_provider)->toBe('password');
    expect($fresh->google_sub)->toBeNull();
});

it('an inactive linked Google account cannot bypass account-status restrictions', function () {
    $sub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id'       => 5,
        'status'        => 'inactive',
        'password'      => null,
        'auth_provider' => 'google',
        'google_sub'    => $sub,
    ]);

    cgaBindVerifier(cgaClaims(['sub' => $sub, 'email' => $user->email]));

    $response = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);

    $response->assertStatus(403);
});

it('a locked linked Google account cannot bypass the lock', function () {
    $sub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id'       => 5,
        'status'        => 'locked',
        'password'      => null,
        'auth_provider' => 'google',
        'google_sub'    => $sub,
    ]);

    cgaBindVerifier(cgaClaims(['sub' => $sub, 'email' => $user->email]));

    $response = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);

    $response->assertStatus(423);
});

it('a Google-only account cannot receive a local password-reset flow, while staying anti-enumeration generic', function () {
    $sub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id'       => 5,
        'status'        => 'active',
        'password'      => null,
        'auth_provider' => 'google',
        'google_sub'    => $sub,
    ]);

    $response = test()->postJson('/api/password/forgot', ['email' => $user->email]);

    $response->assertOk();
    expect($response->json('message'))->toBe('If this email is registered, an OTP has been sent.');
    expect($user->fresh()->password_reset_otp_hash)->toBeNull();
});

it('a Google-only account cannot change a password it does not have', function () {
    $sub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id'       => 5,
        'status'        => 'active',
        'password'      => null,
        'auth_provider' => 'google',
        'google_sub'    => $sub,
    ]);

    $response = test()->actingAs($user, 'sanctum')->postJson('/api/v1/profile/change-password', [
        'current_password'          => 'whatever',
        'new_password'              => 'BrandNewStrong!456',
        'new_password_confirmation' => 'BrandNewStrong!456',
    ]);

    $response->assertStatus(422);
});

it('a Google-only account cannot log in through the password login endpoint', function () {
    $sub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id'       => 5,
        'status'        => 'active',
        'password'      => null,
        'auth_provider' => 'google',
        'google_sub'    => $sub,
    ]);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'anything-at-all',
    ]);

    $response->assertStatus(401);
});

it('the Google auth route is rate limited', function () {
    $last = null;
    for ($i = 0; $i < 12; $i++) {
        cgaBindVerifier(null);
        $last = test()->postJson('/api/auth/google', ['id_token' => 'bad-token']);
    }

    expect($last->status())->toBe(429);
});

it('a Google-authenticated Customer cannot access another customer booking by substituting its code', function () {
    $sub = 'google-sub-' . uniqid();
    $googleUser = User::factory()->create([
        'role_id'       => 5,
        'status'        => 'active',
        'password'      => null,
        'auth_provider' => 'google',
        'google_sub'    => $sub,
    ]);

    $otherUser = User::factory()->create(['role_id' => 5]);
    $otherCustomer = Customer::create([
        'user_id'   => $otherUser->id,
        'full_name' => $otherUser->name,
        'phone'     => '+639' . random_int(100000000, 999999999),
        'email'     => $otherUser->email,
    ]);
    $truckType = TruckType::create(['name' => 'CGA Truck ' . uniqid(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id'   => $otherCustomer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'requested',
    ]);
    $booking->update(['booking_code' => 'TM-CGA' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    $response = test()->actingAs($googleUser, 'sanctum')->getJson('/api/v1/bookings/' . $booking->fresh()->booking_code . '/detail');

    $response->assertStatus(404);
});

it('rejects a credential simulating an expired token', function () {
    cgaBindVerifier(null);

    $response = test()->postJson('/api/auth/google', ['id_token' => 'expired-token']);

    $response->assertStatus(422);
    expect($response->json('data.token'))->toBeNull();
});

it('rejects a credential simulating a wrong audience', function () {
    cgaBindVerifier(null);

    $response = test()->postJson('/api/auth/google', ['id_token' => 'wrong-audience-token']);

    $response->assertStatus(422);
    expect($response->json('data.token'))->toBeNull();
});

it('rejects a credential simulating a wrong issuer', function () {
    cgaBindVerifier(null);

    $response = test()->postJson('/api/auth/google', ['id_token' => 'wrong-issuer-token']);

    $response->assertStatus(422);
    expect($response->json('data.token'))->toBeNull();
});

it('rejects a credential simulating an invalid signature', function () {
    cgaBindVerifier(null);

    $response = test()->postJson('/api/auth/google', ['id_token' => 'bad-signature-token']);

    $response->assertStatus(422);
    expect($response->json('data.token'))->toBeNull();
});

it('ignores a spoofed role, sub, and customer identity in the request body during authenticate', function () {
    $claims = cgaClaims();
    cgaBindVerifier($claims);

    $response = test()->postJson('/api/auth/google', [
        'id_token'      => 'valid-token',
        'role'          => 'System Administrator',
        'role_id'       => 1,
        'sub'           => 'attacker-controlled-sub',
        'google_user_id' => 'attacker-controlled-sub',
        'customer_id'   => 999999,
        'email'         => 'attacker@example.com',
    ]);

    $response->assertStatus(202);

    $start = Cache::get('google_pending_' . $response->json('completion_token'));
    expect($start['sub'])->toBe($claims['sub']);
    expect($start['email'])->toBe($claims['email']);
    expect(User::where('email', 'attacker@example.com')->exists())->toBeFalse();
});

it('ignores a spoofed role and identity in the request body during complete', function () {
    $claims = cgaClaims();
    cgaBindVerifier($claims);
    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone'            => '+639171234599',
        'sub'              => 'attacker-controlled-sub',
        'google_sub'       => 'attacker-controlled-sub',
        'auth_provider'    => 'password',
        'role_id'          => 1,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->google_sub)->toBe($claims['sub']);
    expect($user->auth_provider)->toBe('google');
    expect((int) $user->role_id)->toBe(5);
});
