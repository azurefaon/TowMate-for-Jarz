<?php

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;

function gncRole(): void
{
    if (! Role::find(5)) {
        $role = new Role(['name' => 'Customer']);
        $role->id = 5;
        $role->save();
    }
}

function gncBind(array $claims): void
{
    app()->instance(GoogleIdTokenVerifier::class, new class($claims) implements GoogleIdTokenVerifier {
        public function __construct(private array $claims)
        {
        }

        public function verify(string $idToken): ?array
        {
            return $this->claims;
        }
    });
}

function gncClaims(array $overrides = []): array
{
    return array_merge([
        'sub' => 'gnc-sub-' . uniqid(),
        'email' => 'gnc-' . uniqid() . '@example.com',
        'email_verified' => true,
        'given_name' => 'Gigi',
        'family_name' => 'Delacruz',
    ], $overrides);
}

function gncStart(array $claims): string
{
    gncBind($claims);

    return test()->postJson('/api/auth/google', ['id_token' => 'valid'])->json('completion_token');
}

function gncComplete(string $token, array $extra = [])
{
    return test()->postJson('/api/auth/google/complete', array_merge([
        'completion_token' => $token,
        'phone' => '+639' . random_int(100000000, 999999999),
        'accept_terms' => true,
    ], $extra));
}

beforeEach(fn () => gncRole());

it('stores the structured Google given and family names without any extra input', function () {
    $claims = gncClaims();
    $token = gncStart($claims);

    gncComplete($token)->assertStatus(201);

    $user = User::where('email', $claims['email'])->first();
    expect($user->first_name)->toBe('Gigi')->and($user->middle_name)->toBeNull()
        ->and($user->last_name)->toBe('Delacruz')->and($user->name)->toBe('Gigi Delacruz');
    expect(Customer::where('user_id', $user->id)->first()->full_name)->toBe('Gigi Delacruz');
});

it('refuses to complete a Google account that has no last name and invents no surname', function () {
    $claims = gncClaims(['family_name' => '']);
    $token = gncStart($claims);

    gncComplete($token)
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Last name is required.']);

    expect(User::where('email', $claims['email'])->exists())->toBeFalse();
});

it('completes once the missing last name is supplied and middle name stays optional', function () {
    $claims = gncClaims(['family_name' => null]);
    $token = gncStart($claims);

    gncComplete($token, ['last_name' => 'Reyes'])->assertStatus(201);

    $user = User::where('email', $claims['email'])->first();
    expect($user->first_name)->toBe('Gigi')->and($user->last_name)->toBe('Reyes')->and($user->middle_name)->toBeNull();
});

it('accepts first, optional middle and last name on completion', function () {
    $claims = gncClaims(['given_name' => null, 'family_name' => null]);
    $token = gncStart($claims);

    gncComplete($token, ['first_name' => 'Maria', 'middle_name' => 'Luz', 'last_name' => 'Reyes'])->assertStatus(201);

    $user = User::where('email', $claims['email'])->first();
    expect($user->name)->toBe('Maria Luz Reyes')->and($user->middle_name)->toBe('Luz');
    expect(Customer::where('user_id', $user->id)->first()->middle_name)->toBe('Luz');
});

it('requires a first name when Google supplies none and uses no placeholder', function () {
    $claims = gncClaims(['given_name' => '']);
    $token = gncStart($claims);

    gncComplete($token)
        ->assertStatus(422)
        ->assertJson(['message' => 'First name is required.']);

    expect(User::where('email', $claims['email'])->exists())->toBeFalse();
});

it('does not split the Google display name when structured names are missing', function () {
    $claims = gncClaims(['given_name' => null, 'family_name' => null, 'name' => 'Gigi Santos Delacruz']);
    $token = gncStart($claims);

    gncComplete($token)->assertStatus(422);
    expect(User::where('email', $claims['email'])->exists())->toBeFalse();

    gncComplete($token, ['first_name' => 'Gigi', 'last_name' => 'Delacruz'])->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->middle_name)->toBeNull()->and($user->name)->toBe('Gigi Delacruz');
});

it('keeps stored canonical names on later Google logins', function () {
    $claims = gncClaims();
    $token = gncStart($claims);
    gncComplete($token, ['first_name' => 'Gigi', 'middle_name' => 'Santos', 'last_name' => 'Delacruz'])->assertStatus(201);

    gncBind(array_merge($claims, ['given_name' => 'Changed', 'family_name' => 'Elsewhere']));
    test()->postJson('/api/auth/google', ['id_token' => 'valid'])->assertOk();

    $user = User::where('email', $claims['email'])->first();
    expect($user->first_name)->toBe('Gigi')->and($user->middle_name)->toBe('Santos')->and($user->last_name)->toBe('Delacruz');
});
