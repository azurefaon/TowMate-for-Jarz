<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

function cpvRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cpvRegisterPayload(string $email, string $phone): array
{
    return [
        'first_name'             => 'Phone',
        'last_name'              => 'Check',
        'email'                  => $email,
        'phone'                  => $phone,
        'password'               => 'ValidPass!2024xy',
        'password_confirmation'  => 'ValidPass!2024xy',
    ];
}

beforeEach(function () {
    cpvRole(5, 'Customer');
});

it('accepts the canonical +639XXXXXXXXX phone format on registration', function () {
    $email = 'phoneok-' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $response = test()->postJson('/api/register', cpvRegisterPayload($email, '+639171234567'));

    $response->assertStatus(201);
    $user = User::where('email', $email)->first();
    expect($user->phone)->toBe('+639171234567');
});

it('rejects malformed phone numbers on registration', function (string $phone) {
    $email = 'phonebad-' . uniqid() . '@example.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $response = test()->postJson('/api/register', cpvRegisterPayload($email, $phone));

    $response->assertStatus(422);
    expect(User::where('email', $email)->exists())->toBeFalse();
})->with([
    'double zero prefix'        => ['+6309171234567'],
    'local 09 format'           => ['09171234567'],
    'missing +63'               => ['9171234567'],
    'one digit short'           => ['+63917123456'],
    'one digit too many'        => ['+6391712345678'],
    'letters embedded'          => ['+63917ABC4567'],
    'plain letters'             => ['abc'],
    'empty'                     => [''],
    'starts with 8 after +63'   => ['+638171234567'],
    'starts with 0 after +63'   => ['+630171234567'],
]);

it('rejects a request that omits the phone field entirely', function () {
    $email = 'phonemissing-' . uniqid() . '@example.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $payload = cpvRegisterPayload($email, '+639171234567');
    unset($payload['phone']);

    $response = test()->postJson('/api/register', $payload);

    $response->assertStatus(422);
});

it('still enforces phone uniqueness under the canonical format', function () {
    $existingEmail = 'phoneuniq1-' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $existingEmail, true, now()->addMinutes(15));
    test()->postJson('/api/register', cpvRegisterPayload($existingEmail, '+639171234567'))
        ->assertStatus(201);

    $secondEmail = 'phoneuniq2-' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $secondEmail, true, now()->addMinutes(15));
    $response = test()->postJson('/api/register', cpvRegisterPayload($secondEmail, '+639171234567'));

    $response->assertStatus(422);
});
