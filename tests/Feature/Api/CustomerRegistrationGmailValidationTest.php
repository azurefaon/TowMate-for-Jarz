<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

function crgvRole(): Role
{
    return Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });
}

function crgvPayload(string $email): array
{
    return [
        'first_name'            => 'Gmail',
        'last_name'             => 'Check',
        'email'                 => $email,
        'phone'                 => '+639' . random_int(100000000, 999999999),
        'password'              => 'ValidPass!2024xy',
        'password_confirmation' => 'ValidPass!2024xy',
    ];
}

beforeEach(function () {
    crgvRole();
});

it('accepts a valid Gmail registration when all other data is valid', function () {
    $email = 'gmail-valid-' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $response = test()->postJson('/api/register', crgvPayload($email));

    $response->assertCreated()->assertJsonPath('success', true);
    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('normalizes an uppercase Gmail domain and accepts it', function () {
    $local = 'gmail-upper-' . uniqid();
    $email = strtoupper($local) . '@GMAIL.COM';
    Cache::put('reg_verified_' . strtolower(trim($email)), true, now()->addMinutes(15));

    $response = test()->postJson('/api/register', crgvPayload($email));

    $response->assertCreated()->assertJsonPath('success', true);
    expect(User::where('email', strtolower($email))->exists())->toBeTrue();
});

it('handles a Gmail address with surrounding spaces correctly', function () {
    $email = 'gmail-spaced-' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $response = test()->postJson('/api/register', crgvPayload('  ' . $email . '  '));

    $response->assertCreated()->assertJsonPath('success', true);
    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('rejects a Yahoo registration email', function () {
    $email = 'yahoo-reject-' . uniqid() . '@yahoo.com';

    $response = test()->postJson('/api/register', crgvPayload($email));

    $response->assertStatus(422)->assertJsonPath('message', 'Please use a Gmail address.');
    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('rejects an Outlook registration email', function () {
    $email = 'outlook-reject-' . uniqid() . '@outlook.com';

    $response = test()->postJson('/api/register', crgvPayload($email));

    $response->assertStatus(422)->assertJsonPath('message', 'Please use a Gmail address.');
    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('rejects a Hotmail registration email', function () {
    $email = 'hotmail-reject-' . uniqid() . '@hotmail.com';

    $response = test()->postJson('/api/register', crgvPayload($email));

    $response->assertStatus(422)->assertJsonPath('message', 'Please use a Gmail address.');
    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('rejects an arbitrary custom-domain registration email', function () {
    $email = 'custom-reject-' . uniqid() . '@hesoyam.com';

    $response = test()->postJson('/api/register', crgvPayload($email));

    $response->assertStatus(422)->assertJsonPath('message', 'Please use a Gmail address.');
    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('rejects a near-miss Gmail domain such as gmail.co', function () {
    $email = 'gmailco-reject-' . uniqid() . '@gmail.co';

    $response = test()->postJson('/api/register', crgvPayload($email));

    $response->assertStatus(422)->assertJsonPath('message', 'Please use a Gmail address.');
    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('rejects a spoofed Gmail-looking subdomain such as gmail.com.example.com', function () {
    $email = 'gmail-spoof-' . uniqid() . '@gmail.com.example.com';

    $response = test()->postJson('/api/register', crgvPayload($email));

    $response->assertStatus(422)->assertJsonPath('message', 'Please use a Gmail address.');
    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('rejects a malformed email with a Gmail-specific message', function () {
    $response = test()->postJson('/api/register', crgvPayload('not-an-email'));

    $response->assertStatus(422)->assertJsonPath('message', 'Enter a valid Gmail address.');
});
