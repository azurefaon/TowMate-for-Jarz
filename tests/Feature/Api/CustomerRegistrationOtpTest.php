<?php

use App\Mail\RegistrationOtpMail;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function sendRegistrationOtp(string $email): ?string
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

// 20 — plaintext storage ──────────────────────────────────────────────────
it('20: the registration OTP is never stored in plaintext', function () {
    $email = 'reg20@example.com';
    $otp = sendRegistrationOtp($email);

    $record = Cache::get('reg_otp_' . $email);
    expect($record)->toBeArray();
    expect($record['hash'])->not->toBe($otp);
    expect(Hash::check($otp, $record['hash']))->toBeTrue();
});

// 21 — attempt lockout ────────────────────────────────────────────────────
it('21: registration OTP verification locks out after 5 failed attempts', function () {
    $email = 'reg21@example.com';
    $otp = sendRegistrationOtp($email);

    for ($i = 0; $i < 5; $i++) {
        test()->postJson('/api/register/verify-otp', ['email' => $email, 'otp' => '000000'])
            ->assertStatus(422);
    }

    // Correct OTP now fails too — the cache entry was invalidated after the limit.
    test()->postJson('/api/register/verify-otp', ['email' => $email, 'otp' => $otp])
        ->assertStatus(422);

    expect(Cache::get('reg_otp_' . $email))->toBeNull();
});

// 22 — resend cooldown ─────────────────────────────────────────────────────
it('22: registration OTP resend cooldown is enforced', function () {
    $email = 'reg22@example.com';
    sendRegistrationOtp($email);

    Mail::fake();
    test()->postJson('/api/register/send-otp', ['email' => $email]);
    Mail::assertNothingQueued();
});

// 23 — resend invalidates old OTP ───────────────────────────────────────────
it('23: a resend after the cooldown invalidates the previous registration OTP', function () {
    $email = 'reg23@example.com';
    $firstOtp = sendRegistrationOtp($email);

    test()->travel(61)->seconds();
    $secondOtp = sendRegistrationOtp($email);

    expect($firstOtp)->not->toBe($secondOtp);
    test()->postJson('/api/register/verify-otp', ['email' => $email, 'otp' => $firstOtp])->assertStatus(422);
    test()->postJson('/api/register/verify-otp', ['email' => $email, 'otp' => $secondOtp])->assertOk();
});

// 24 — route rate limiting ────────────────────────────────────────────────
it('24: the registration OTP send route is rate limited', function () {
    $email = 'reg24-send@example.com';
    Mail::fake();

    for ($i = 0; $i < 3; $i++) {
        test()->postJson('/api/register/send-otp', ['email' => $email])->assertOk();
    }

    test()->postJson('/api/register/send-otp', ['email' => $email])->assertStatus(429);
});

it('24: the registration OTP verify route is rate limited', function () {
    $email = 'reg24-verify@example.com';
    sendRegistrationOtp($email);

    for ($i = 0; $i < 10; $i++) {
        test()->postJson('/api/register/verify-otp', ['email' => $email, 'otp' => '000000']);
    }

    test()->postJson('/api/register/verify-otp', ['email' => $email, 'otp' => '000000'])
        ->assertStatus(429);
});

// 25 — logging never leaks secrets ───────────────────────────────────────────
it('25: registration OTP values never appear in the audit log', function () {
    $email = 'reg25@example.com';
    $otp = sendRegistrationOtp($email);
    test()->postJson('/api/register/verify-otp', ['email' => $email, 'otp' => $otp])->assertOk();

    $logs = AuditLog::where('description', 'like', '%' . $email . '%')->get();
    expect($logs)->not->toBeEmpty();

    foreach ($logs as $log) {
        expect((string) $log->description)->not->toContain($otp);
    }
});
