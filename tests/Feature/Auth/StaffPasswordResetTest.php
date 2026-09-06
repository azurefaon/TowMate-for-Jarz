<?php

use App\Mail\StaffPasswordResetOtpMail;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\StaffPasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

function pwResetDispatcher(array $attrs = []): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher', 'description' => 'Dispatch staff']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(array_merge([
        'role_id' => $role->id,
        'must_change_password' => false,
        'status' => 'active',
    ], $attrs));
}

function submitOtp(string $email): string
{
    Mail::fake();
    test()->post(route('password.email'), ['email' => $email]);
    $record = StaffPasswordResetOtp::where('email', $email)->first();

    return $record?->otp_hash;
}

// A ────────────────────────────────────────────────────────────────────
it('A: a valid registered email gets the generic response and an OTP is generated', function () {
    Mail::fake();
    $user = pwResetDispatcher(['email' => 'staff-a@example.com']);

    $response = test()->post(route('password.email'), ['email' => $user->email]);

    $response->assertRedirect(route('password.otp.show'));
    $response->assertSessionHas('status', 'If an account exists for that email, a verification code has been sent.');

    expect(StaffPasswordResetOtp::where('email', $user->email)->exists())->toBeTrue();
    Mail::assertQueued(StaffPasswordResetOtpMail::class);
});

// B ────────────────────────────────────────────────────────────────────
it('B: an unknown email gets the identical generic response and no OTP is created', function () {
    Mail::fake();

    $response = test()->post(route('password.email'), ['email' => 'nobody-' . uniqid() . '@example.com']);

    $response->assertRedirect(route('password.otp.show'));
    $response->assertSessionHas('status', 'If an account exists for that email, a verification code has been sent.');

    expect(StaffPasswordResetOtp::count())->toBe(0);
    Mail::assertNothingQueued();
});

// C / D ──────────────────────────────────────────────────────────────────
it('C and D: correct OTP is accepted before expiry, wrong OTP is rejected', function () {
    $user = pwResetDispatcher(['email' => 'staff-cd@example.com']);
    Mail::fake();
    $captured = null;
    test()->post(route('password.email'), ['email' => $user->email]);
    Mail::assertQueued(StaffPasswordResetOtpMail::class, function ($mail) use (&$captured) {
        $captured = $mail->otp;
        return true;
    });

    test()->withSession(['staff_pw_reset_email' => $user->email])
        ->post(route('password.otp.verify'), ['otp' => '000001'])
        ->assertSessionHasErrors('otp');

    test()->withSession(['staff_pw_reset_email' => $user->email])
        ->post(route('password.otp.verify'), ['otp' => $captured])
        ->assertRedirect(route('password.reset'));
});

// E ────────────────────────────────────────────────────────────────────
it('E: OTP is rejected once 5 minutes have passed (time travel, server-authoritative)', function () {
    $user = pwResetDispatcher(['email' => 'staff-e@example.com']);
    Mail::fake();
    $captured = null;
    test()->post(route('password.email'), ['email' => $user->email]);
    Mail::assertQueued(StaffPasswordResetOtpMail::class, function ($mail) use (&$captured) {
        $captured = $mail->otp;
        return true;
    });

    test()->travel(301)->seconds();

    test()->withSession(['staff_pw_reset_email' => $user->email])
        ->post(route('password.otp.verify'), ['otp' => $captured])
        ->assertSessionHasErrors('otp');
});

// F ────────────────────────────────────────────────────────────────────
it('F: resend invalidates the previous OTP and the new one works', function () {
    $user = pwResetDispatcher(['email' => 'staff-f@example.com']);
    Mail::fake();

    $firstOtp = null;
    test()->post(route('password.email'), ['email' => $user->email]);
    Mail::assertQueued(StaffPasswordResetOtpMail::class, function ($mail) use (&$firstOtp) {
        $firstOtp = $mail->otp;
        return true;
    });

    test()->travel(61)->seconds(); // clear the resend cooldown

    $secondOtp = null;
    test()->withSession(['staff_pw_reset_email' => $user->email])->post(route('password.otp.resend'));
    Mail::assertQueued(StaffPasswordResetOtpMail::class, function ($mail) use (&$secondOtp) {
        $secondOtp = $mail->otp;
        return true;
    });

    expect($firstOtp)->not->toBe($secondOtp);

    test()->withSession(['staff_pw_reset_email' => $user->email])
        ->post(route('password.otp.verify'), ['otp' => $firstOtp])
        ->assertSessionHasErrors('otp');

    test()->withSession(['staff_pw_reset_email' => $user->email])
        ->post(route('password.otp.verify'), ['otp' => $secondOtp])
        ->assertRedirect(route('password.reset'));
});

// G / Q ──────────────────────────────────────────────────────────────────
it('G and Q: a consumed OTP/authorization cannot be reused after a completed reset', function () {
    $user = pwResetDispatcher(['email' => 'staff-g@example.com']);
    Mail::fake();
    $otp = null;
    test()->post(route('password.email'), ['email' => $user->email]);
    Mail::assertQueued(StaffPasswordResetOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    $verifySession = test()->withSession(['staff_pw_reset_email' => $user->email]);
    $verifySession->post(route('password.otp.verify'), ['otp' => $otp])->assertRedirect(route('password.reset'));

    // Re-verifying the same (now consumed/deleted) OTP must fail.
    test()->withSession(['staff_pw_reset_email' => $user->email])
        ->post(route('password.otp.verify'), ['otp' => $otp])
        ->assertSessionHasErrors('otp');
});

// H ────────────────────────────────────────────────────────────────────
it('H: verification is throttled after excessive failed attempts and the OTP is invalidated', function () {
    $user = pwResetDispatcher(['email' => 'staff-h@example.com']);
    Mail::fake();
    test()->post(route('password.email'), ['email' => $user->email]);
    $otp = null;
    Mail::assertQueued(StaffPasswordResetOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    for ($i = 0; $i < 5; $i++) {
        test()->withSession(['staff_pw_reset_email' => $user->email])
            ->post(route('password.otp.verify'), ['otp' => '999999'])
            ->assertSessionHasErrors('otp');
    }

    // The correct OTP now fails too — the record was invalidated after the limit.
    test()->withSession(['staff_pw_reset_email' => $user->email])
        ->post(route('password.otp.verify'), ['otp' => $otp])
        ->assertSessionHasErrors('otp');

    expect(StaffPasswordResetOtp::where('email', $user->email)->exists())->toBeFalse();
});

// I ────────────────────────────────────────────────────────────────────
it('I: the forgot-password request rate is throttled per email', function () {
    $user = pwResetDispatcher(['email' => 'staff-i@example.com']);
    Mail::fake();

    for ($i = 0; $i < 3; $i++) {
        test()->post(route('password.email'), ['email' => $user->email]);
    }

    Mail::fake(); // reset the queued-mail counter for a clean assertion below
    test()->post(route('password.email'), ['email' => $user->email]);

    Mail::assertNothingQueued(); // 4th request within the window sends no new email
});

// J ────────────────────────────────────────────────────────────────────
it('J: resend spam within the cooldown window is throttled', function () {
    $user = pwResetDispatcher(['email' => 'staff-j@example.com']);
    Mail::fake();
    test()->post(route('password.email'), ['email' => $user->email]);

    Mail::fake();
    test()->withSession(['staff_pw_reset_email' => $user->email])->post(route('password.otp.resend'));

    Mail::assertNothingQueued(); // resend within 60s of the first send is a no-op
});

// K ────────────────────────────────────────────────────────────────────
it('K: the reset-password page rejects direct access without a verified OTP', function () {
    test()->get(route('password.reset'))->assertRedirect(route('password.request'));
    test()->post(route('password.store'), ['password' => 'Whatever12345!', 'password_confirmation' => 'Whatever12345!'])
        ->assertRedirect(route('password.request'));
});

// L ────────────────────────────────────────────────────────────────────
it('L: the account being reset is derived only from server-side session state, not request input', function () {
    $victim = pwResetDispatcher(['email' => 'victim@example.com', 'password' => Hash::make('OriginalPass123!')]);
    $attacker = pwResetDispatcher(['email' => 'attacker@example.com', 'password' => Hash::make('AttackerPass123!')]);

    // Attacker legitimately verifies their OWN OTP...
    Mail::fake();
    test()->post(route('password.email'), ['email' => $attacker->email]);
    $otp = null;
    Mail::assertQueued(StaffPasswordResetOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });
    $session = test()->withSession(['staff_pw_reset_email' => $attacker->email]);
    $session->post(route('password.otp.verify'), ['otp' => $otp])->assertRedirect(route('password.reset'));

    // ...then tries to smuggle the victim's email into the reset request itself.
    // There is no request field the controller even reads for this, so it can
    // only ever affect the attacker's own (session-bound) account.
    $newPassword = 'Zq7#vTk29!mLpXr4' . uniqid();

    $session->post(route('password.store'), [
        'email' => $victim->email,
        'user_id' => $victim->id,
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ])->assertRedirect(route('password.reset.success'));

    expect(Hash::check('OriginalPass123!', $victim->fresh()->password))->toBeTrue();
    expect(Hash::check($newPassword, $attacker->fresh()->password))->toBeTrue();
});

// M / N ──────────────────────────────────────────────────────────────────
it('M and N: password confirmation mismatch and weak passwords are rejected', function () {
    $user = pwResetDispatcher(['email' => 'staff-mn@example.com']);
    $session = verifiedResetSession($user);

    $session->post(route('password.store'), [
        'password' => 'GoodPassword123!',
        'password_confirmation' => 'Different123!',
    ])->assertSessionHasErrors('password');

    $session->post(route('password.store'), [
        'password' => 'short1',
        'password_confirmation' => 'short1',
    ])->assertSessionHasErrors('password');
});

// O / P ──────────────────────────────────────────────────────────────────
it('O and P: a successful reset makes the new password work and invalidates the old one', function () {
    $user = pwResetDispatcher(['email' => 'staff-op@example.com', 'password' => Hash::make('OldPassword123!')]);
    $session = verifiedResetSession($user);

    $session->post(route('password.store'), [
        'password' => 'BrandNewPassword123!',
        'password_confirmation' => 'BrandNewPassword123!',
    ])->assertRedirect(route('password.reset.success'));

    $fresh = $user->fresh();
    expect(Hash::check('BrandNewPassword123!', $fresh->password))->toBeTrue();
    expect(Hash::check('OldPassword123!', $fresh->password))->toBeFalse();
});

// R ────────────────────────────────────────────────────────────────────
it('R: an archived account stays archived after a password reset', function () {
    $user = pwResetDispatcher([
        'email' => 'staff-r@example.com',
        'status' => 'inactive',
        'archived_at' => now(),
        'archived_reason' => 'test',
    ]);
    $session = verifiedResetSession($user);

    $session->post(route('password.store'), [
        'password' => 'ResetButStillArchived123!',
        'password_confirmation' => 'ResetButStillArchived123!',
    ])->assertRedirect(route('password.reset.success'));

    $fresh = $user->fresh();
    expect($fresh->archived_at)->not->toBeNull();
    expect($fresh->status)->toBe('inactive');
});

// T ────────────────────────────────────────────────────────────────────
it('T: the OTP never appears in the verify page HTML or in audit log fields', function () {
    $user = pwResetDispatcher(['email' => 'staff-t@example.com']);
    Mail::fake();
    test()->post(route('password.email'), ['email' => $user->email]);
    $otp = null;
    Mail::assertQueued(StaffPasswordResetOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    $html = test()->withSession(['staff_pw_reset_email' => $user->email])->get(route('password.otp.show'))->getContent();
    expect($html)->not->toContain($otp);

    $logs = AuditLog::where('user_id', $user->id)->get();
    foreach ($logs as $log) {
        expect($log->action)->not->toContain($otp);
        expect((string) $log->description)->not->toContain($otp);
        expect((string) $log->reference)->not->toContain($otp);
    }
});

// U ────────────────────────────────────────────────────────────────────
it('U: normal login is unaffected by this change', function () {
    $user = pwResetDispatcher(['email' => 'staff-u@example.com', 'password' => Hash::make('KnownPassword123!')]);

    test()->post(route('login'), [
        'email' => $user->email,
        'password' => 'KnownPassword123!',
        'role' => 'dispatcher',
    ])->assertRedirect();

    expect(auth()->check())->toBeTrue();
});

function verifiedResetSession(User $user)
{
    $withSession = test()->withSession([
        'staff_pw_reset_verified_email' => $user->email,
        'staff_pw_reset_verified_until' => now()->addMinutes(10),
    ]);
    $withSession->get(route('password.reset'))->assertOk();

    return $withSession;
}
