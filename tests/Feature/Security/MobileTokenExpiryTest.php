<?php

/*
 * Mobile API tokens expire 60 days after they were issued. The app treats a 401 as "sign in again"
 * (SessionCoordinator), so an expired token is a clean re-login, not a broken session.
 */

use App\Models\Role;
use App\Models\User;

function mteUser(int $roleId): User
{
    Role::find($roleId) ?: tap(new Role(['name' => 'Role ' . $roleId]), function ($r) use ($roleId) {
        $r->id = $roleId;
        $r->save();
    });

    return User::factory()->create(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false]);
}

/** One request as a fresh client: the auth guard caches its user, so it is reset between calls. */
function mteAs($test, string $token): \Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    return $test->withToken($token)->getJson('/api/user');
}

/** A real, stored token (not Sanctum::actingAs) that is $days old. */
function mteTokenAged(User $user, int $days): string
{
    $token = $user->createToken('mobile');
    $token->accessToken->forceFill(['created_at' => now()->subDays($days)])->save();

    return $token->plainTextToken;
}

it('expires mobile tokens after 60 days by default', function () {
    expect(config('sanctum.expiration'))->toBe(60 * 24 * 60);
});

it('keeps a 59-day-old token working and refuses a 61-day-old one with a plain 401', function () {
    $user = mteUser(5);

    $fresh = mteTokenAged($user, 59);
    mteAs($this, $fresh)->assertOk();

    $stale = mteTokenAged($user, 61);
    mteAs($this, $stale)->assertStatus(401)->assertJsonStructure(['message']);
});

it('applies to Team Leader tokens the same way as Customer tokens', function () {
    foreach ([3, 5] as $roleId) {
        $user = mteUser($roleId);

        mteAs($this, mteTokenAged($user, 30))->assertOk();
        mteAs($this, mteTokenAged($user, 90))->assertStatus(401);
    }
});

it('lets the user sign in again after expiry: a new token is valid and the expired one stays refused', function () {
    $user = mteUser(5);
    $expired = mteTokenAged($user, 70);
    mteAs($this, $expired)->assertStatus(401);

    $new = $user->createToken('mobile')->plainTextToken;
    mteAs($this, $new)->assertOk();
    mteAs($this, $expired)->assertStatus(401);
});

it('still revokes a token on logout and leaves other users untouched', function () {
    $alice = mteUser(5);
    $bob = mteUser(5);
    $aliceToken = mteTokenAged($alice, 5);
    $bobToken = mteTokenAged($bob, 5);

    $this->withToken($aliceToken)->postJson('/api/logout')->assertOk();

    mteAs($this, $aliceToken)->assertStatus(401);
    mteAs($this, $bobToken)->assertOk();
});

it('does not touch the staff web sessions: the expiry applies to API tokens only', function () {
    $dispatcher = mteUser(2);

    $this->actingAs($dispatcher)->get(route('admin.dashboard'))->assertOk();
});
