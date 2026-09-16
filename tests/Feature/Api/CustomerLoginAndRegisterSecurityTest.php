<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

function clrRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function clrCustomer(array $overrides = []): User
{
    clrRole(5, 'Customer');

    return User::factory()->create(array_merge([
        'role_id'  => 5,
        'status'   => 'active',
        'password' => Hash::make('CorrectHorse!9Battery'),
    ], $overrides));
}

beforeEach(function () {
    RateLimiter::clear('login-email:' . strtolower('login-throttle-probe@example.com'));
});

it('rejects invalid credentials with a generic message', function () {
    $user = clrCustomer(['email' => 'loginsec1@example.com']);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'WrongPassword!123',
    ]);

    $response->assertStatus(401);
    expect($response->json('message'))->toBe('Invalid credentials.');
});

it('rejects login for a nonexistent email with the identical generic message', function () {
    $response = test()->postJson('/api/login', [
        'email'    => 'doesnotexist-' . uniqid() . '@example.com',
        'password' => 'WhateverPassword!123',
    ]);

    $response->assertStatus(401);
    expect($response->json('message'))->toBe('Invalid credentials.');
});

it('rejects a malformed login request without leaking framework internals', function () {
    $response = test()->postJson('/api/login', ['email' => 'not-an-email']);

    $response->assertStatus(422);
    expect($response->json())->not->toHaveKey('exception');
    expect($response->json())->not->toHaveKey('trace');
});

it('rejects login for a locked account even with the correct password', function () {
    $user = clrCustomer(['email' => 'loginsec2@example.com', 'status' => 'locked']);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(423);
    expect($response->json('success'))->toBeFalse();
});

it('rejects login for an inactive account even with the correct password', function () {
    $user = clrCustomer(['email' => 'loginsec3@example.com', 'status' => 'inactive']);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(403);
    expect($response->json('success'))->toBeFalse();
});

it('ignores a client-submitted role on login and returns the server-assigned role', function () {
    $user = clrCustomer(['email' => 'loginsec4@example.com']);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
        'role'     => 'System Administrator',
        'role_id'  => 1,
        'is_admin' => true,
    ]);

    $response->assertStatus(200);
    expect($response->json('data.user.role'))->toBe('Customer');
});

it('issues a fresh token on login and revokes any previously issued tokens', function () {
    $user = clrCustomer(['email' => 'loginsec5@example.com']);
    $oldToken = $user->createToken('mobile')->plainTextToken;

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(200);
    $newToken = $response->json('data.token');
    expect($newToken)->not->toBe($oldToken);

    test()->withHeader('Authorization', 'Bearer ' . $oldToken)
        ->getJson('/api/v1/profile')
        ->assertStatus(401);

    test()->withHeader('Authorization', 'Bearer ' . $newToken)
        ->getJson('/api/v1/profile')
        ->assertStatus(200);
});

it('throttles the login route after repeated attempts from the same email', function () {
    $user = clrCustomer(['email' => 'loginsec6@example.com']);

    $last = null;
    for ($i = 0; $i < 8; $i++) {
        $last = test()->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'WrongPassword!123',
        ]);
    }

    expect($last->status())->toBe(429);
});

it('rejects an unauthenticated request to a protected customer route', function () {
    test()->getJson('/api/v1/profile')->assertStatus(401);
});

it('cannot bypass registration OTP verification by calling the final register endpoint directly', function () {
    $email = 'regsec1-' . uniqid() . '@example.com';

    $response = test()->postJson('/api/register', [
        'first_name'             => 'Reg',
        'last_name'              => 'Sec',
        'email'                  => $email,
        'phone'                  => '+639' . random_int(100000000, 999999999),
        'password'               => 'ValidPass!2024xy',
        'password_confirmation'  => 'ValidPass!2024xy',
    ]);

    $response->assertStatus(422);
    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('always creates the Customer role regardless of a client-submitted role_id', function () {
    clrRole(1, 'Owner');
    clrRole(5, 'Customer');

    $email = 'regsec2-' . uniqid() . '@example.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $response = test()->postJson('/api/register', [
        'first_name'             => 'Reg',
        'last_name'              => 'Sec',
        'email'                  => $email,
        'phone'                  => '+639' . random_int(100000000, 999999999),
        'password'               => 'ValidPass!2024xy',
        'password_confirmation'  => 'ValidPass!2024xy',
        'role_id'                => 1,
        'role'                   => 'Owner',
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $email)->first();
    expect($user)->not->toBeNull();
    expect((int) $user->role_id)->toBe(5);
});

it('ignores unexpected sensitive fields submitted on registration', function () {
    clrRole(5, 'Customer');

    $email = 'regsec3-' . uniqid() . '@example.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $response = test()->postJson('/api/register', [
        'first_name'             => 'Reg',
        'last_name'              => 'Sec',
        'email'                  => $email,
        'phone'                  => '+639' . random_int(100000000, 999999999),
        'password'               => 'ValidPass!2024xy',
        'password_confirmation'  => 'ValidPass!2024xy',
        'status'                 => 'suspended',
        'is_admin'               => true,
        'must_change_password'   => true,
        'locked_until'           => now()->addYears(10)->toDateTimeString(),
        'archived_at'            => now()->toDateTimeString(),
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $email)->first();
    expect($user->status)->toBe('active');
    expect($user->archived_at)->toBeNull();
});

it('rejects registration with a duplicate email', function () {
    clrRole(5, 'Customer');
    $existing = clrCustomer(['email' => 'regsec4@example.com']);

    Cache::put('reg_verified_' . $existing->email, true, now()->addMinutes(15));

    $response = test()->postJson('/api/register', [
        'first_name'             => 'Reg',
        'last_name'              => 'Sec',
        'email'                  => $existing->email,
        'phone'                  => '+639' . random_int(100000000, 999999999),
        'password'               => 'ValidPass!2024xy',
        'password_confirmation'  => 'ValidPass!2024xy',
    ]);

    $response->assertStatus(422);
});

it('throttles the register route after repeated attempts from the same email', function () {
    clrRole(5, 'Customer');
    $email = 'regsec5-throttle@example.com';

    $last = null;
    for ($i = 0; $i < 7; $i++) {
        $last = test()->postJson('/api/register', [
            'first_name'             => 'Reg',
            'last_name'              => 'Sec',
            'email'                  => $email,
            'phone'                  => '+639' . random_int(100000000, 999999999),
            'password'               => 'ValidPass!2024xy',
            'password_confirmation'  => 'ValidPass!2024xy',
        ]);
    }

    expect($last->status())->toBe(429);
});

it('never writes the plaintext password to the audit log during login', function () {
    $user = clrCustomer(['email' => 'loginsec7@example.com']);

    test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $entries = AuditLog::query()->get();
    foreach ($entries as $entry) {
        $haystack = json_encode($entry->toArray());
        expect($haystack)->not->toContain('CorrectHorse!9Battery');
    }
});

it('exactly one /api/login route exists, resolving to the throttled AuthController action', function () {
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->filter(fn ($route) => $route->uri() === 'api/login' && in_array('POST', $route->methods(), true));

    expect($routes->count())->toBe(1);

    $route = $routes->first();
    expect($route->getActionName())->toBe(\App\Http\Controllers\Api\AuthController::class . '@login');
    expect($route->middleware())->toContain('throttle:customer-login');
});
