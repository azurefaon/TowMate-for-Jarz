<?php

use App\Mail\PasswordResetOtpMail;
use App\Mail\RegistrationOtpMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function p4oDispatcher(array $attrs = []): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Admin']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(array_merge([
        'role_id' => $role->id,
        'must_change_password' => false,
        'status' => 'active',
    ], $attrs));
}

function p4oSendRegistrationOtp(string $email): ?string
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

function p4oSendResetOtp(string $email): ?string
{
    Mail::fake();
    test()->postJson('/api/password/forgot', ['email' => $email]);
    $otp = null;
    Mail::assertQueued(PasswordResetOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    return $otp;
}

it('a registration OTP issued to Account A email cannot verify Account B email', function () {
    $emailA = 'p4o-reg-a@example.com';
    $emailB = 'p4o-reg-b@example.com';
    $otpA = p4oSendRegistrationOtp($emailA);
    p4oSendRegistrationOtp($emailB);

    test()->postJson('/api/register/verify-otp', ['email' => $emailB, 'otp' => $otpA])
        ->assertStatus(422);
});

it('a customer password-reset OTP issued to Account A cannot verify Account B', function () {
    $userA = User::factory()->create(['email' => 'p4o-reset-a@example.com']);
    $userB = User::factory()->create(['email' => 'p4o-reset-b@example.com']);
    $otpA = p4oSendResetOtp($userA->email);
    p4oSendResetOtp($userB->email);

    test()->postJson('/api/password/verify-otp', ['email' => $userB->email, 'otp' => $otpA])
        ->assertStatus(422);

    expect($userB->fresh()->password_reset_token_hash)->toBeNull();
});

it('a customer reset token cannot be replayed a second time after a successful reset', function () {
    $user = User::factory()->create(['email' => 'p4o-replay@example.com', 'password' => Hash::make('OriginalPass123!')]);
    $otp = p4oSendResetOtp($user->email);
    $verify = test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp]);
    $rawToken = $verify->json('reset_token');

    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $rawToken,
        'password' => 'FirstReplayPass123!',
        'password_confirmation' => 'FirstReplayPass123!',
    ])->assertOk();

    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $rawToken,
        'password' => 'SecondReplayPass123!',
        'password_confirmation' => 'SecondReplayPass123!',
    ])->assertStatus(422);

    expect(Hash::check('FirstReplayPass123!', $user->fresh()->password))->toBeTrue();
});

it('a customer reset token from Account A cannot be replayed against Account B after A already reset', function () {
    $userA = User::factory()->create(['email' => 'p4o-cross-a@example.com']);
    $userB = User::factory()->create(['email' => 'p4o-cross-b@example.com', 'password' => Hash::make('VictimPass123!')]);
    $otpA = p4oSendResetOtp($userA->email);
    $verify = test()->postJson('/api/password/verify-otp', ['email' => $userA->email, 'otp' => $otpA]);
    $rawTokenA = $verify->json('reset_token');

    test()->postJson('/api/password/reset', [
        'email' => $userB->email,
        'reset_token' => $rawTokenA,
        'password' => 'HijackAttempt123!',
        'password_confirmation' => 'HijackAttempt123!',
    ])->assertStatus(422);

    expect(Hash::check('VictimPass123!', $userB->fresh()->password))->toBeTrue();
});

it('staff correct OTP cannot bypass ineligibility introduced before issuance', function () {
    $user = p4oDispatcher(['email' => 'p4o-staff-ineligible@example.com', 'archived_at' => now()]);
    Mail::fake();

    test()->post(route('password.email'), ['email' => $user->email]);

    test()->withSession(['staff_pw_reset_email' => $user->email])
        ->post(route('password.otp.verify'), ['otp' => '000000'])
        ->assertSessionHasErrors('otp');
});

it('customer logout revokes the current Sanctum token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    test()->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/logout')
        ->assertOk();

    expect($user->fresh()->tokens()->count())->toBe(0);
});

it('staff reset still rotates remember_token and revokes Sanctum tokens together', function () {
    $user = p4oDispatcher(['email' => 'p4o-staff-tokens@example.com']);
    $oldRememberToken = $user->remember_token;
    $token = $user->createToken('web')->plainTextToken;

    Mail::fake();
    test()->post(route('password.email'), ['email' => $user->email]);
    $otp = null;
    Mail::assertQueued(\App\Mail\StaffPasswordResetOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });
    test()->withSession(['staff_pw_reset_email' => $user->email])
        ->post(route('password.otp.verify'), ['otp' => $otp])
        ->assertRedirect(route('password.reset'));

    test()->withSession([
        'staff_pw_reset_verified_email' => $user->email,
        'staff_pw_reset_verified_until' => now()->addMinutes(10),
    ])->post(route('password.store'), [
        'password' => 'RotatedTokens123!',
        'password_confirmation' => 'RotatedTokens123!',
    ])->assertRedirect(route('password.reset.success'));

    $fresh = $user->fresh();
    expect($fresh->remember_token)->not->toBe($oldRememberToken);
    expect($fresh->tokens()->count())->toBe(0);

    test()->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user')
        ->assertStatus(401);
});
