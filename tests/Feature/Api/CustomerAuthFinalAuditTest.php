<?php

use App\Mail\RegistrationOtpMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function caaRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function caaUser(int $roleId, string $roleName, array $overrides = []): User
{
    caaRole($roleId, $roleName);

    return User::factory()->create(array_merge([
        'role_id'  => $roleId,
        'status'   => 'active',
        'password' => Hash::make('CorrectHorse!9Battery'),
    ], $overrides));
}

it('rejects an Owner account authenticating through the customer login endpoint', function () {
    $user = caaUser(1, 'Owner', ['email' => 'audit-owner@example.com']);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(401);
    expect($response->json('message'))->toBe('Invalid credentials.');
});

it('rejects a Dispatcher/Admin account authenticating through the customer login endpoint', function () {
    $user = caaUser(2, 'Admin', ['email' => 'audit-admin@example.com']);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(401);
    expect($response->json('message'))->toBe('Invalid credentials.');
});

it('rejects a System Admin account authenticating through the customer login endpoint', function () {
    $user = caaUser(6, 'System Admin', ['email' => 'audit-sysadmin@example.com']);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(401);
    expect($response->json('message'))->toBe('Invalid credentials.');
});

it('rejects a Driver account authenticating through the customer login endpoint', function () {
    $user = caaUser(4, 'Driver', ['email' => 'audit-driver@example.com']);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(401);
    expect($response->json('message'))->toBe('Invalid credentials.');
});

it('still allows a Customer account to authenticate through the customer login endpoint', function () {
    $user = caaUser(5, 'Customer', ['email' => 'audit-customer@example.com']);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertOk();
    expect($response->json('data.user.role'))->toBe('Customer');
});

it('still allows a Team Leader account to authenticate through the customer login endpoint', function () {
    $user = caaUser(3, 'Team Leader', ['email' => 'audit-tl@example.com']);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertOk();
    expect($response->json('data.user.role'))->toBe('Team Leader');
});

it('rejects a protected customer route when the bearer token is invalid/garbage', function () {
    test()->withHeader('Authorization', 'Bearer not-a-real-token-at-all')
        ->getJson('/api/v1/profile')
        ->assertStatus(401);
});

it('a token is unusable immediately after logout', function () {
    $user = caaUser(5, 'Customer', ['email' => 'audit-logout@example.com']);
    $token = $user->createToken('mobile')->plainTextToken;

    test()->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/v1/profile')
        ->assertStatus(200);

    test()->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/logout')
        ->assertOk();

    expect(\Laravel\Sanctum\PersonalAccessToken::findToken($token))->toBeNull();

    Auth::forgetGuards();

    test()->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/v1/profile')
        ->assertStatus(401);
});

it('a malformed raw JSON body on login fails safely without authenticating', function () {
    $response = test()->call(
        'POST',
        '/api/login',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        '{not valid json::'
    );

    expect($response->status())->toBeGreaterThanOrEqual(400);
    expect($response->json())->not->toHaveKey('data');
});

it('a malformed raw JSON body on Google auth fails safely without authenticating', function () {
    $response = test()->call(
        'POST',
        '/api/auth/google',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        '{not valid json::'
    );

    expect($response->status())->toBeGreaterThanOrEqual(400);
    expect($response->json())->not->toHaveKey('data');
});

function caaSendRegOtp(string $email): ?string
{
    Mail::fake();
    test()->postJson('/api/register/send-otp', ['email' => $email]);
    $otp = null;
    Mail::assertQueued(RegistrationOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    return $otp;
}

it('a registration OTP expires server-side after its TTL', function () {
    $email = 'audit-reg-expire@example.com';
    $otp = caaSendRegOtp($email);

    test()->travel(6)->minutes();

    test()->postJson('/api/register/verify-otp', ['email' => $email, 'otp' => $otp])
        ->assertStatus(422);
});

it('a registration OTP cannot be replayed after it has already been verified', function () {
    $email = 'audit-reg-replay@example.com';
    $otp = caaSendRegOtp($email);

    test()->postJson('/api/register/verify-otp', ['email' => $email, 'otp' => $otp])->assertOk();

    test()->postJson('/api/register/verify-otp', ['email' => $email, 'otp' => $otp])
        ->assertStatus(422);
});
