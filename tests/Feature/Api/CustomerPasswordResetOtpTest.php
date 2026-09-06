<?php

use App\Mail\PasswordResetOtpMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function sendCustomerResetOtp(string $email): ?string
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

function verifiedCustomerResetToken(User $user): string
{
    $otp = sendCustomerResetOtp($user->email);
    $response = test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp]);

    return $response->json('reset_token');
}

// 1/2 — generic response regardless of account existence ────────────────
it('1: OTP request returns a generic response for an existing account', function () {
    $user = User::factory()->create(['email' => 'pr1@example.com']);

    $response = test()->postJson('/api/password/forgot', ['email' => $user->email]);

    $response->assertOk()->assertJson(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);
});

it('2: OTP request returns the identical generic response for a nonexistent account', function () {
    $response = test()->postJson('/api/password/forgot', ['email' => 'nobody-' . uniqid() . '@example.com']);

    $response->assertOk()->assertJson(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);
});

// 3 — plaintext storage ───────────────────────────────────────────────────
it('3: the OTP is never stored in plaintext', function () {
    $user = User::factory()->create(['email' => 'pr3@example.com']);
    $otp = sendCustomerResetOtp($user->email);

    $fresh = $user->fresh();
    expect($fresh->password_reset_otp_hash)->not->toBe($otp);
    expect(Hash::check($otp, $fresh->password_reset_otp_hash))->toBeTrue();
});

// 4 — expiration ──────────────────────────────────────────────────────────
it('4: the OTP expires server-side after its TTL', function () {
    $user = User::factory()->create(['email' => 'pr4@example.com']);
    $otp = sendCustomerResetOtp($user->email);

    test()->travel(6)->minutes();

    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
});

// 5/6 — attempt lockout ────────────────────────────────────────────────────
it('5: a wrong OTP increments the failed-attempt counter', function () {
    $user = User::factory()->create(['email' => 'pr5@example.com']);
    sendCustomerResetOtp($user->email);

    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => '000000'])
        ->assertStatus(422);

    expect($user->fresh()->password_reset_attempts)->toBe(1);
});

it('6: the fifth wrong attempt invalidates the OTP entirely', function () {
    $user = User::factory()->create(['email' => 'pr6@example.com']);
    $otp = sendCustomerResetOtp($user->email);

    for ($i = 0; $i < 5; $i++) {
        test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => '000000'])
            ->assertStatus(422);
    }

    // The correct OTP now fails too — it was deleted after the limit.
    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp])
        ->assertStatus(422);

    expect($user->fresh()->password_reset_otp_hash)->toBeNull();
});

// 7 — correct OTP before the limit succeeds ────────────────────────────────
it('7: the correct OTP verifies successfully before the attempt limit is hit', function () {
    $user = User::factory()->create(['email' => 'pr7@example.com']);
    $otp = sendCustomerResetOtp($user->email);

    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => '000000'])->assertStatus(422);
    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => '000001'])->assertStatus(422);

    $response = test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp]);

    $response->assertOk();
    expect($response->json('reset_token'))->not->toBeEmpty();
});

// 8/9 — resend behavior ─────────────────────────────────────────────────────
it('8: a resend after the cooldown invalidates the previous OTP', function () {
    $user = User::factory()->create(['email' => 'pr8@example.com']);
    $firstOtp = sendCustomerResetOtp($user->email);

    test()->travel(61)->seconds();
    $secondOtp = sendCustomerResetOtp($user->email);

    expect($firstOtp)->not->toBe($secondOtp);
    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $firstOtp])->assertStatus(422);
    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $secondOtp])->assertOk();
});

it('9: a resend within the cooldown window does not issue a new OTP', function () {
    $user = User::factory()->create(['email' => 'pr9@example.com']);
    $firstOtp = sendCustomerResetOtp($user->email);

    Mail::fake();
    test()->postJson('/api/password/forgot', ['email' => $user->email]);
    Mail::assertNothingQueued();

    // The original OTP is still the only valid one.
    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $firstOtp])->assertOk();
});

// 10/11 — route rate limiting ────────────────────────────────────────────────
it('10: the OTP send route is rate limited', function () {
    $user = User::factory()->create(['email' => 'pr10@example.com']);
    Mail::fake();

    for ($i = 0; $i < 3; $i++) {
        test()->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();
    }

    test()->postJson('/api/password/forgot', ['email' => $user->email])->assertStatus(429);
});

it('11: the OTP verify route is rate limited', function () {
    $user = User::factory()->create(['email' => 'pr11@example.com']);
    sendCustomerResetOtp($user->email);

    for ($i = 0; $i < 10; $i++) {
        test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => '000000']);
    }

    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => '000000'])
        ->assertStatus(429);
});

// 12/13/14 — reset token security ────────────────────────────────────────────
it('12: the reset token is never stored in plaintext', function () {
    $user = User::factory()->create(['email' => 'pr12@example.com']);
    $rawToken = verifiedCustomerResetToken($user);

    $fresh = $user->fresh();
    expect($fresh->password_reset_token_hash)->not->toBe($rawToken);
    expect($fresh->password_reset_token_hash)->toBe(hash('sha256', $rawToken));
});

it('13: the reset token is single-use', function () {
    $user = User::factory()->create(['email' => 'pr13@example.com', 'password' => Hash::make('OriginalPass123!')]);
    $rawToken = verifiedCustomerResetToken($user);

    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $rawToken,
        'password' => 'FirstNewPassword123!',
        'password_confirmation' => 'FirstNewPassword123!',
    ])->assertOk();

    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $rawToken,
        'password' => 'SecondNewPassword123!',
        'password_confirmation' => 'SecondNewPassword123!',
    ])->assertStatus(422);

    expect(Hash::check('FirstNewPassword123!', $user->fresh()->password))->toBeTrue();
});

it('14: an expired reset token is rejected', function () {
    $user = User::factory()->create(['email' => 'pr14@example.com']);
    $rawToken = verifiedCustomerResetToken($user);

    test()->travel(11)->minutes();

    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $rawToken,
        'password' => 'TooLatePassword123!',
        'password_confirmation' => 'TooLatePassword123!',
    ])->assertStatus(422);
});

// 15 — cross-account isolation ────────────────────────────────────────────
it('15: Account A reset authorization cannot be used to reset Account B', function () {
    $victim = User::factory()->create(['email' => 'pr15-victim@example.com', 'password' => Hash::make('VictimPass123!')]);
    $attacker = User::factory()->create(['email' => 'pr15-attacker@example.com']);

    $attackerToken = verifiedCustomerResetToken($attacker);

    test()->postJson('/api/password/reset', [
        'email' => $victim->email,
        'reset_token' => $attackerToken,
        'password' => 'HijackedPassword123!',
        'password_confirmation' => 'HijackedPassword123!',
    ])->assertStatus(422);

    expect(Hash::check('VictimPass123!', $victim->fresh()->password))->toBeTrue();
});

// 16/17 — successful reset ───────────────────────────────────────────────────
it('16/17: a successful reset changes the password and the old one stops working', function () {
    $user = User::factory()->create(['email' => 'pr1617@example.com', 'password' => Hash::make('OldPassword123!')]);
    $rawToken = verifiedCustomerResetToken($user);

    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $rawToken,
        'password' => 'BrandNewPassword123!',
        'password_confirmation' => 'BrandNewPassword123!',
    ])->assertOk();

    $fresh = $user->fresh();
    expect(Hash::check('BrandNewPassword123!', $fresh->password))->toBeTrue();
    expect(Hash::check('OldPassword123!', $fresh->password))->toBeFalse();
});

// 18 — Sanctum revocation ───────────────────────────────────────────────────
it('18: existing Sanctum tokens are revoked after a successful reset', function () {
    $user = User::factory()->create(['email' => 'pr18@example.com']);
    $oldToken = $user->createToken('mobile')->plainTextToken;

    $rawToken = verifiedCustomerResetToken($user);
    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $rawToken,
        'password' => 'PostResetPassword123!',
        'password_confirmation' => 'PostResetPassword123!',
    ])->assertOk();

    test()->withHeader('Authorization', 'Bearer ' . $oldToken)
        ->getJson('/api/user')
        ->assertStatus(401);
});

// 19 — reset without verified OTP ────────────────────────────────────────────
it('19: reset is rejected without a prior verified OTP', function () {
    $user = User::factory()->create(['email' => 'pr19@example.com', 'password' => Hash::make('OriginalPass123!')]);

    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => bin2hex(random_bytes(32)),
        'password' => 'NeverVerifiedPassword123!',
        'password_confirmation' => 'NeverVerifiedPassword123!',
    ])->assertStatus(422);

    expect(Hash::check('OriginalPass123!', $user->fresh()->password))->toBeTrue();
});

// 25 — logging never leaks secrets ───────────────────────────────────────────
it('25: OTP, reset token, and password values never appear in the audit log', function () {
    $user = User::factory()->create(['email' => 'pr25@example.com', 'password' => Hash::make('OriginalPass123!')]);
    $otp = sendCustomerResetOtp($user->email);
    $response = test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp]);
    $rawToken = $response->json('reset_token');

    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $rawToken,
        'password' => 'FinalNewPassword123!',
        'password_confirmation' => 'FinalNewPassword123!',
    ])->assertOk();

    $logs = AuditLog::where('user_id', $user->id)->get();
    expect($logs)->not->toBeEmpty();

    foreach ($logs as $log) {
        foreach ([$otp, $rawToken, 'FinalNewPassword123!'] as $secret) {
            expect((string) $log->action)->not->toContain($secret);
            expect((string) $log->description)->not->toContain($secret);
            expect(json_encode($log->old_value))->not->toContain($secret);
            expect(json_encode($log->new_value))->not->toContain($secret);
        }
    }
});

// 26 — restricted account states stay generic ───────────────────────────────
it('26: an inactive account cannot complete a password reset while the response stays generic', function () {
    $user = User::factory()->create(['email' => 'pr26-inactive@example.com', 'status' => 'inactive']);
    Mail::fake();

    $response = test()->postJson('/api/password/forgot', ['email' => $user->email]);
    $response->assertOk()->assertJson(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);

    Mail::assertNothingQueued();
    expect($user->fresh()->password_reset_otp_hash)->toBeNull();
});

it('26: an archived account cannot complete a password reset while the response stays generic', function () {
    $user = User::factory()->create(['email' => 'pr26-archived@example.com', 'archived_at' => now()]);
    Mail::fake();

    $response = test()->postJson('/api/password/forgot', ['email' => $user->email]);
    $response->assertOk()->assertJson(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);

    Mail::assertNothingQueued();
    expect($user->fresh()->password_reset_otp_hash)->toBeNull();
});

it('26: an anonymized account cannot complete a password reset while the response stays generic', function () {
    $user = User::factory()->create(['email' => 'pr26-anon@example.com', 'anonymized_at' => now()]);
    Mail::fake();

    test()->postJson('/api/password/forgot', ['email' => $user->email])
        ->assertOk()->assertJson(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);

    Mail::assertNothingQueued();
});

it('26: a pending-deletion account cannot complete a password reset while the response stays generic', function () {
    $user = User::factory()->create(['email' => 'pr26-pending@example.com', 'pending_delete_at' => now()]);
    Mail::fake();

    test()->postJson('/api/password/forgot', ['email' => $user->email])
        ->assertOk()->assertJson(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);

    Mail::assertNothingQueued();
});

it('26: a locked account IS eligible (the documented recovery path) and reactivates on reset', function () {
    $user = User::factory()->create(['email' => 'pr26-locked@example.com', 'status' => 'locked']);
    $rawToken = verifiedCustomerResetToken($user);

    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $rawToken,
        'password' => 'ReactivatedPassword123!',
        'password_confirmation' => 'ReactivatedPassword123!',
    ])->assertOk();

    expect($user->fresh()->status)->toBe('active');
});
