<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

function passwordPolicyCustomerRole(): Role
{
    return Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });
}

// 18 — customer registration requires the final selected policy ─────────────
it('18: customer registration rejects a password below the final policy', function () {
    passwordPolicyCustomerRole();
    $email = 'policy18@example.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $this->postJson('/api/register', [
        'first_name' => 'Policy',
        'last_name'  => 'Test',
        'email'      => $email,
        'phone'      => '09171234567',
        'password'   => 'short8ok', // 8 chars, no symbol/uppercase — passes the OLD rule, fails the new one
        'password_confirmation' => 'short8ok',
    ])->assertStatus(422)->assertJsonPath('success', false);

    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('18b: customer registration accepts a password meeting the final policy', function () {
    passwordPolicyCustomerRole();
    $email = 'policy18b@example.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $this->postJson('/api/register', [
        'first_name' => 'Policy',
        'last_name'  => 'Test',
        'email'      => $email,
        'phone'      => '09171234568',
        'password'   => 'Xk7!TowMateSecure91',
        'password_confirmation' => 'Xk7!TowMateSecure91',
    ])->assertCreated()->assertJsonPath('success', true);

    expect(User::where('email', $email)->exists())->toBeTrue();
});

// 19 — customer change-password requires the final selected policy ──────────
it('19: customer change-password rejects a new password below the final policy', function () {
    $user = User::factory()->create(['role_id' => passwordPolicyCustomerRole()->id, 'password' => Hash::make('CurrentPass123!')]);
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/profile/change-password', [
        'current_password' => 'CurrentPass123!',
        'new_password' => 'short8ok',
        'new_password_confirmation' => 'short8ok',
    ])->assertStatus(422);

    expect(Hash::check('CurrentPass123!', $user->fresh()->password))->toBeTrue();
});

it('19b: customer change-password accepts a new password meeting the final policy', function () {
    $user = User::factory()->create(['role_id' => passwordPolicyCustomerRole()->id, 'password' => Hash::make('CurrentPass123!')]);
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/profile/change-password', [
        'current_password' => 'CurrentPass123!',
        'new_password' => 'BrandNewStrong456!',
        'new_password_confirmation' => 'BrandNewStrong456!',
    ])->assertOk();

    expect(Hash::check('BrandNewStrong456!', $user->fresh()->password))->toBeTrue();
});
