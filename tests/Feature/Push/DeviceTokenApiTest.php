<?php

use App\Models\DeviceToken;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function dtUser(): User
{
    $role = Role::find(4) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 4;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

const DT_TOKEN_A = 'fcm-token-aaaaaaaaaaaaaaaaaaaaaaaa';
const DT_TOKEN_B = 'fcm-token-bbbbbbbbbbbbbbbbbbbbbbbb';

it('7: requires authentication to register or remove a token', function () {
    $this->postJson('/api/v1/device-tokens', ['token' => DT_TOKEN_A])->assertUnauthorized();
    $this->deleteJson('/api/v1/device-tokens', ['token' => DT_TOKEN_A])->assertUnauthorized();
});

it('8: ties the token to the authenticated user and ignores any client-supplied user_id', function () {
    $user = dtUser();
    $other = dtUser();
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/device-tokens', ['token' => DT_TOKEN_A, 'user_id' => $other->id])->assertOk();

    expect(DeviceToken::where('token', DT_TOKEN_A)->value('user_id'))->toBe($user->id);
});

it('9: same-token registration is idempotent', function () {
    $user = dtUser();
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/device-tokens', ['token' => DT_TOKEN_A])->assertOk();
    $this->postJson('/api/v1/device-tokens', ['token' => DT_TOKEN_A])->assertOk();

    expect(DeviceToken::count())->toBe(1);
});

it('10: moves a token to the new owner when the device changes account', function () {
    $first = dtUser();
    $second = dtUser();

    Sanctum::actingAs($first, ['*']);
    $this->postJson('/api/v1/device-tokens', ['token' => DT_TOKEN_A])->assertOk();

    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($second, ['*']);
    $this->postJson('/api/v1/device-tokens', ['token' => DT_TOKEN_A])->assertOk();

    expect(DeviceToken::count())->toBe(1)
        ->and(DeviceToken::first()->user_id)->toBe($second->id);
});

it('11: supports multiple devices per user', function () {
    $user = dtUser();
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/device-tokens', ['token' => DT_TOKEN_A])->assertOk();
    $this->postJson('/api/v1/device-tokens', ['token' => DT_TOKEN_B])->assertOk();

    expect(DeviceToken::where('user_id', $user->id)->count())->toBe(2);
});

it('12: deletion only removes the caller\'s own token', function () {
    $owner = dtUser();
    $intruder = dtUser();
    DeviceToken::create(['user_id' => $owner->id, 'token' => DT_TOKEN_A, 'platform' => 'android']);

    Sanctum::actingAs($intruder, ['*']);
    $this->deleteJson('/api/v1/device-tokens', ['token' => DT_TOKEN_A])->assertOk();
    expect(DeviceToken::where('token', DT_TOKEN_A)->exists())->toBeTrue();

    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($owner, ['*']);
    $this->deleteJson('/api/v1/device-tokens', ['token' => DT_TOKEN_A])->assertOk();
    expect(DeviceToken::where('token', DT_TOKEN_A)->exists())->toBeFalse();
});

it('rejects malformed tokens', function () {
    Sanctum::actingAs(dtUser(), ['*']);

    $this->postJson('/api/v1/device-tokens', ['token' => 'short'])->assertStatus(422);
    $this->postJson('/api/v1/device-tokens', [])->assertStatus(422);
});
