<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function srtRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function srtCustomer(array $overrides = []): User
{
    srtRole(5, 'Customer');

    return User::factory()->create(array_merge([
        'role_id'  => 5,
        'status'   => 'active',
        'password' => Hash::make('CorrectHorse!9Battery'),
    ], $overrides));
}

it('the profile endpoint reports requires_terms_acceptance=false when the account is on the current terms', function () {
    $user = srtCustomer([
        'terms_version'     => config('towmate.current_terms_version'),
        'privacy_version'   => config('towmate.current_privacy_version'),
        'terms_accepted_at' => now(),
    ]);
    $token = $user->createToken('mobile')->plainTextToken;

    $response = test()->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/v1/profile');

    $response->assertOk();
    expect($response->json('requires_terms_acceptance'))->toBeFalse();
});

it('the profile endpoint reports requires_terms_acceptance=true when terms are stale, without returning 401', function () {
    $user = srtCustomer([
        'terms_version'     => null,
        'privacy_version'   => null,
        'terms_accepted_at' => null,
    ]);
    $token = $user->createToken('mobile')->plainTextToken;

    $response = test()->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/v1/profile');

    $response->assertStatus(200);
    expect($response->json('requires_terms_acceptance'))->toBeTrue();
});

it('accepting terms afterward flips the profile endpoint back to requires_terms_acceptance=false', function () {
    $user = srtCustomer([
        'terms_version'     => null,
        'privacy_version'   => null,
        'terms_accepted_at' => null,
    ]);
    $token = $user->createToken('mobile')->plainTextToken;

    test()->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/auth/accept-terms')
        ->assertOk();

    $response = test()->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/v1/profile');

    $response->assertOk();
    expect($response->json('requires_terms_acceptance'))->toBeFalse();
});

it('a Team Leader profile never requires terms acceptance regardless of stored terms state', function () {
    srtRole(3, 'Team Leader');
    $user = User::factory()->create([
        'role_id'       => 3,
        'status'        => 'active',
        'password'      => Hash::make('CorrectHorse!9Battery'),
        'terms_version' => null,
    ]);
    $token = $user->createToken('mobile')->plainTextToken;

    $response = test()->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/v1/profile');

    $response->assertOk();
    expect($response->json('requires_terms_acceptance'))->toBeFalse();
});
