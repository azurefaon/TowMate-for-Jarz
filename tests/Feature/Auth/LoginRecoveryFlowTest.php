<?php

// Account recovery after a sign-in lock: locked screen -> email -> six-digit code -> new password
// -> back to login. The legacy unlock-only routes must never short-circuit that.

use App\Mail\StaffPasswordResetOtpMail;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\StaffPasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

const RECOVERY_NEUTRAL = 'If an account is associated with this email, a verification code will be sent.';
const RECOVERY_NEW_PASSWORD = 'Brand-New-Recovery-Pass-2026!';

beforeEach(function () {
    // The breach check calls an external API; keep every test offline and deterministic.
    $GLOBALS['recovery_pwned_body'] = '';
    Http::fake(['api.pwnedpasswords.com/*' => fn () => Http::response($GLOBALS['recovery_pwned_body'], 200)]);
});

function recoveryRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function (Role $role) use ($id) {
        $role->id = $id;
        $role->save();
    });
}

function recoveryUser(int $roleId, string $email, array $attrs = []): User
{
    recoveryRole($roleId, match ($roleId) {
        1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader', 5 => 'Customer', 6 => 'System Admin', default => 'Role ' . $roleId,
    });

    return User::factory()->create(array_merge([
        'email' => $email,
        'role_id' => $roleId,
        'status' => 'active',
        'must_change_password' => false,
        'password' => Hash::make('OldPassword123!'),
    ], $attrs));
}

function recoveryRequestCode(string $email): ?string
{
    Mail::fake();
    test()->post(route('password.email'), ['email' => $email]);

    $otp = null;
    Mail::assertQueued(StaffPasswordResetOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;

        return true;
    });

    return $otp;
}

function recoveryVerified(string $email): string
{
    $otp = recoveryRequestCode($email);
    test()->post(route('password.otp.verify'), ['otp' => $otp])->assertRedirect(route('password.reset'));

    return $otp;
}

function recoverySavePassword(string $password, ?string $confirmation = null): \Illuminate\Testing\TestResponse
{
    return test()->post(route('password.store'), [
        'password' => $password,
        'password_confirmation' => $confirmation ?? $password,
    ]);
}

// ── 1-2: three failed attempts lead into the modern recovery ──────────────
it('locks after three failed attempts and the locked screen opens the modern recovery page', function () {
    $user = recoveryUser(2, 'locked@example.com', ['password' => Hash::make('CorrectPass123!')]);

    foreach (['one', 'two', 'three'] as $n) {
        if ($n === 'three') {
            sleep(11); // the sign-in token bucket refills 5 tokens per 10 seconds
        }
        $response = test()->from('/login')->post('/login', ['role' => 'dispatcher', 'email' => $user->email, 'password' => 'wrong-' . $n]);
    }

    $response->assertSessionHas('login_locked', true);
    expect($user->fresh()->locked_until)->not->toBeNull();

    $page = test()->withSession(['login_locked' => true, 'login_locked_email' => $user->email])->get('/login')->assertOk();
    $page->assertSee('Sign-in locked');
    // The email travels in the session, never in the URL.
    $page->assertSee(route('password.request'), false);
    $page->assertDontSee('password.request?email', false);
    $page->assertDontSee('forgot-password?email', false);
    $page->assertDontSee('login/recover', false);

    test()->get(route('password.request'))
        ->assertOk()
        ->assertSee('Recover Your Account')
        ->assertSee('value="' . $user->email . '"', false);
});

// ── 3-6: only eligible accounts get a code; the public answer never differs ─
it('sends a code to a registered, active account', function () {
    recoveryUser(2, 'real@example.com');

    $otp = recoveryRequestCode('real@example.com');

    expect($otp)->toMatch('/^\d{6}$/')
        ->and(StaffPasswordResetOtp::where('email', 'real@example.com')->exists())->toBeTrue();
});

it('creates no code, mail or reset authorization for an unregistered email and answers neutrally', function () {
    recoveryUser(2, 'real@example.com');
    Mail::fake();

    $known = test()->post(route('password.email'), ['email' => 'real@example.com']);
    $unknown = test()->post(route('password.email'), ['email' => 'nobody@example.com']);

    $known->assertRedirect(route('password.otp.show'))->assertSessionHas('status', RECOVERY_NEUTRAL);
    $unknown->assertRedirect(route('password.otp.show'))->assertSessionHas('status', RECOVERY_NEUTRAL);
    expect($unknown->getStatusCode())->toBe($known->getStatusCode());

    Mail::assertQueued(StaffPasswordResetOtpMail::class, 1);
    expect(StaffPasswordResetOtp::where('email', 'nobody@example.com')->exists())->toBeFalse();

    test()->post(route('password.otp.verify'), ['otp' => '123456'])->assertSessionHasErrors('otp');
    test()->get(route('password.reset'))->assertRedirect(route('password.request'));
    recoverySavePassword(RECOVERY_NEW_PASSWORD)->assertRedirect(route('password.request'));
});

it('creates no code for disabled, archived, pending-deletion, Team Leader or customer accounts', function (array $attrs, int $role) {
    recoveryUser($role, 'blocked@example.com', $attrs);
    Mail::fake();

    test()->post(route('password.email'), ['email' => 'blocked@example.com'])
        ->assertRedirect(route('password.otp.show'))
        ->assertSessionHas('status', RECOVERY_NEUTRAL);

    Mail::assertNothingQueued();
    expect(StaffPasswordResetOtp::count())->toBe(0);
})->with([
    'inactive' => [['status' => 'inactive'], 2],
    'archived' => [['archived_at' => now()], 2],
    'pending deletion' => [['pending_delete_at' => now()->addDays(3)], 2],
    'team leader' => [[], 3],
    'customer' => [[], 5],
]);

// ── 7-12: code security ───────────────────────────────────────────────────
it('rejects an incorrect code with the same message as an expired or unknown one', function () {
    recoveryUser(2, 'otp@example.com');
    $otp = recoveryRequestCode('otp@example.com');
    $wrong = $otp === '111111' ? '222222' : '111111';

    $messages = [];
    $try = function (string $code) use (&$messages) {
        test()->post(route('password.otp.verify'), ['otp' => $code])->assertSessionHasErrors('otp');
        $messages[] = session('errors')->first('otp');
    };

    $try($wrong);
    test()->travel(301)->seconds();
    $try($otp);
    test()->travelBack();

    expect(array_unique($messages))->toHaveCount(1);
    test()->get(route('password.reset'))->assertRedirect(route('password.request'));
});

it('rejects an expired code', function () {
    recoveryUser(2, 'expired@example.com');
    $otp = recoveryRequestCode('expired@example.com');

    test()->travel(301)->seconds();
    test()->post(route('password.otp.verify'), ['otp' => $otp])->assertSessionHasErrors('otp');
});

it('does not accept a code twice', function () {
    recoveryUser(2, 'replay@example.com');
    $otp = recoveryVerified('replay@example.com');

    test()->post(route('password.otp.verify'), ['otp' => $otp])->assertSessionHasErrors('otp');

    recoverySavePassword(RECOVERY_NEW_PASSWORD)->assertRedirect(route('password.reset.success'));

    // After the reset the code and the reset authorization are gone, along with the recovery session.
    test()->post(route('password.otp.verify'), ['otp' => $otp])->assertRedirect(route('password.request'));
    recoverySavePassword('Another-Strong-Pass-2027!')->assertRedirect(route('password.request'));
    expect(Hash::check(RECOVERY_NEW_PASSWORD, User::where('email', 'replay@example.com')->first()->password))->toBeTrue();
});

it('does not let one account\'s code verify another account', function () {
    $a = recoveryUser(2, 'a@example.com');
    $b = recoveryUser(2, 'b@example.com');
    $otpA = recoveryRequestCode($a->email);

    // The session is for b; a's code must not work for it.
    test()->withSession(['staff_pw_reset_email' => $b->email])
        ->post(route('password.otp.verify'), ['otp' => $otpA])
        ->assertSessionHasErrors('otp');

    test()->get(route('password.reset'))->assertRedirect(route('password.request'));
});

it('invalidates the code after five wrong attempts, even if the right code is then entered', function () {
    recoveryUser(2, 'limit@example.com');
    $otp = recoveryRequestCode('limit@example.com');
    $wrong = $otp === '000000' ? '000001' : '000000';

    foreach (range(1, 6) as $i) {
        test()->post(route('password.otp.verify'), ['otp' => $wrong])->assertSessionHasErrors('otp');
    }

    test()->post(route('password.otp.verify'), ['otp' => $otp])->assertSessionHasErrors('otp');
    expect(StaffPasswordResetOtp::where('email', 'limit@example.com')->exists())->toBeFalse();
});

it('enforces the resend cooldown', function () {
    recoveryUser(2, 'resend@example.com');
    recoveryRequestCode('resend@example.com');

    Mail::fake();
    test()->post(route('password.otp.resend'))->assertSessionHasErrors('otp');
    Mail::assertNothingQueued();

    test()->travel(301)->seconds();
    test()->post(route('password.otp.resend'))->assertSessionHas('otp_resent', true);
    Mail::assertQueued(StaffPasswordResetOtpMail::class, 1);
});

// ── 13-19: password policy (Password::defaults()) ─────────────────────────
it('rejects passwords that break the policy, with a clear message, and keeps the code usable', function (string $password, string $message) {
    $user = recoveryUser(2, 'policy@example.com');
    recoveryVerified($user->email);

    recoverySavePassword($password)->assertSessionHasErrors(['password' => $message]);

    expect(Hash::check('OldPassword123!', $user->fresh()->password))->toBeTrue();

    // The verified code survives a rejected attempt, so a corrected password still works.
    recoverySavePassword(RECOVERY_NEW_PASSWORD)->assertRedirect(route('password.reset.success'));
})->with([
    'shorter than 12' => ['Short1!aB', 'Use at least 12 characters.'],
    'no uppercase' => ['alllowercase123!x', 'Include both uppercase and lowercase letters.'],
    'no lowercase' => ['ALLUPPERCASE123!X', 'Include both uppercase and lowercase letters.'],
    'no number' => ['NoNumbersHere!!!!', 'Include at least one number.'],
    'no special character' => ['NoSpecial12345abc', 'Include at least one special character.'],
]);

it('rejects a password found in a known data breach', function () {
    $user = recoveryUser(2, 'breach@example.com');
    recoveryVerified($user->email);

    $compromised = 'Compromised-Pass-2026!';
    $GLOBALS['recovery_pwned_body'] = strtoupper(substr(sha1($compromised), 5)) . ':3500';

    recoverySavePassword($compromised)->assertSessionHasErrors(['password' => 'This password has appeared in a known data breach. Choose a different one.']);
    expect(Hash::check('OldPassword123!', $user->fresh()->password))->toBeTrue();
});

it('rejects a confirmation mismatch', function () {
    $user = recoveryUser(2, 'mismatch@example.com');
    recoveryVerified($user->email);

    recoverySavePassword(RECOVERY_NEW_PASSWORD, 'Different-Pass-2026!!')->assertSessionHasErrors(['password' => 'The passwords do not match.']);
    expect(Hash::check('OldPassword123!', $user->fresh()->password))->toBeTrue();
});

it('rejects reusing the current password', function () {
    $user = recoveryUser(2, 'same@example.com', ['password' => Hash::make('Current-Pass-2026!')]);
    recoveryVerified($user->email);

    recoverySavePassword('Current-Pass-2026!')->assertSessionHasErrors(['password' => 'Your new password cannot be the same as your current password.']);
});

// ── 20-24: the full journey ───────────────────────────────────────────────
it('resets the password, lifts the lock only then, ends old access and requires a fresh sign-in', function () {
    $user = recoveryUser(2, 'journey@example.com', ['password' => Hash::make('CorrectPass123!')]);
    $user->forceFill(['failed_login_attempts' => 3, 'last_failed_login_at' => now(), 'locked_until' => now()->addMinutes(15)])->save();
    $token = $user->createToken('mobile')->plainTextToken;

    // A verified code alone must not unlock the account.
    recoveryVerified($user->email);
    expect($user->fresh()->locked_until)->not->toBeNull();

    recoverySavePassword(RECOVERY_NEW_PASSWORD)
        ->assertRedirect(route('password.reset.success'));

    $fresh = $user->fresh();
    expect($fresh->locked_until)->toBeNull()
        ->and((int) $fresh->failed_login_attempts)->toBe(0)
        ->and($fresh->last_failed_login_at)->toBeNull()
        ->and($fresh->tokens()->count())->toBe(0)
        ->and(StaffPasswordResetOtp::where('email', $user->email)->exists())->toBeFalse();

    // Not signed in by the reset itself.
    test()->assertGuest('dispatcher');
    test()->assertGuest('web');

    test()->get(route('password.reset.success'))->assertOk()->assertSee('Password Updated')->assertSee(route('login'), false);

    // Old password is refused, the new one signs in.
    test()->post('/login', ['role' => 'dispatcher', 'email' => $user->email, 'password' => 'CorrectPass123!'])
        ->assertSessionHasErrorsIn('login', ['auth']);
    test()->assertGuest('dispatcher');

    test()->post('/login', ['role' => 'dispatcher', 'email' => $user->email, 'password' => RECOVERY_NEW_PASSWORD])
        ->assertRedirect(route('admin.dashboard', absolute: false));
    test()->assertAuthenticated('dispatcher');

    expect(AuditLog::where('user_id', $user->id)->where('action', 'staff_password_reset_completed')->exists())->toBeTrue()
        ->and(AuditLog::where('user_id', $user->id)->where('action', 'login_success_after_recovery')->exists())->toBeTrue();
});

it('cannot finish a reset once the account has been disabled mid-flow', function () {
    $user = recoveryUser(2, 'disabled-midway@example.com');
    recoveryVerified($user->email);

    $user->forceFill(['status' => 'inactive'])->save();

    recoverySavePassword(RECOVERY_NEW_PASSWORD)->assertRedirect(route('password.request'));
    expect(Hash::check('OldPassword123!', $user->fresh()->password))->toBeTrue();
});

// ── 25-27: roles ──────────────────────────────────────────────────────────
it('lets Owner, System Admin and Dispatcher recover', function (int $role) {
    $user = recoveryUser($role, 'role' . $role . '@example.com');
    recoveryVerified($user->email);

    recoverySavePassword(RECOVERY_NEW_PASSWORD)->assertRedirect(route('password.reset.success'));
    expect(Hash::check(RECOVERY_NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
})->with([
    'owner' => [1],
    'dispatcher' => [2],
    'system admin' => [6],
]);

it('keeps Team Leaders off the web portal and its recovery', function () {
    $tl = recoveryUser(3, 'tl@example.com', ['password' => Hash::make('CorrectPass123!')]);
    Mail::fake();

    test()->post(route('password.email'), ['email' => $tl->email])->assertSessionHas('status', RECOVERY_NEUTRAL);
    Mail::assertNothingQueued();

    test()->post('/login', ['role' => 'teamleader', 'email' => $tl->email, 'password' => 'CorrectPass123!'])
        ->assertSessionHasErrorsIn('login', ['auth']);
    test()->assertGuest('teamleader');
    test()->assertGuest('web');
});

// ── 28: legacy unlock routes ──────────────────────────────────────────────
it('sends every legacy recovery route to the password reset flow and never unlocks', function () {
    $user = recoveryUser(2, 'legacy@example.com');
    $user->forceFill(['failed_login_attempts' => 3, 'locked_until' => now()->addMinutes(15)])->save();
    Mail::fake();

    test()->get('/login/recover')->assertRedirect(route('password.request'));
    test()->get('/login/recover?email=' . urlencode($user->email))->assertRedirect(route('password.request'));
    test()->get(route('password.request'))->assertSee('value="' . $user->email . '"', false);
    test()->get('/login/recover/verify')->assertRedirect(route('password.request'));
    test()->get('/login/recover/success')->assertRedirect(route('password.request'));
    test()->post('/login/recover', ['email' => $user->email])->assertRedirect(route('password.request'));
    test()->post('/login/recover/resend')->assertRedirect(route('password.request'));

    // A legacy-style session and code cannot unlock or authorize a reset.
    test()->withSession(['login_recover_email' => $user->email])
        ->post('/login/recover/verify', ['otp' => '123456'])
        ->assertRedirect(route('password.request'));

    Mail::assertNothingQueued();
    expect($user->fresh()->locked_until)->not->toBeNull();
    test()->get(route('password.reset'))->assertRedirect(route('password.request'));
    recoverySavePassword(RECOVERY_NEW_PASSWORD)->assertRedirect(route('password.request'));
});

// ── pages ─────────────────────────────────────────────────────────────────
it('renders the recovery pages with the JARZ logo, one favicon and the password checklist', function () {
    $user = recoveryUser(2, 'pages@example.com');

    $pages = [test()->get(route('password.request'))->assertOk()->getContent()];

    recoveryRequestCode($user->email);
    $pages[] = test()->get(route('password.otp.show'))->assertOk()->getContent();

    $otp = StaffPasswordResetOtp::where('email', $user->email)->first();
    $otp->update(['otp_hash' => Hash::make('123456')]);
    test()->post(route('password.otp.verify'), ['otp' => '123456']);
    $reset = test()->get(route('password.reset'))->assertOk()->getContent();
    $pages[] = $reset;
    $pages[] = test()->get(route('password.reset.success'))->assertOk()->getContent();

    foreach ($pages as $html) {
        expect($html)->toContain('dispatcher/images/jarz-logo.png')
            ->and($html)->not->toContain('admin/images/logo.png')
            ->and(substr_count($html, 'rel="icon"'))->toBe(1);
    }

    foreach (['length', 'upper', 'lower', 'number', 'special', 'match'] as $rule) {
        expect($reset)->toContain('data-rule="' . $rule . '"');
    }
    expect($reset)->toContain('\p{Lu}')->toContain('[\p{Z}\p{S}\p{P}]');
});

// ── email prefill is session-based and short-lived ────────────────────────
it('prefills the email once from the session and never from the URL', function () {
    test()->get(route('password.request', ['email' => 'urlonly@example.com']))
        ->assertOk()
        ->assertDontSee('urlonly@example.com');

    test()->withSession(['recovery_prefill' => ['email' => 'kept@example.com', 'until' => now()->addMinutes(5)]])
        ->get(route('password.request'))
        ->assertSee('value="kept@example.com"', false);

    // Consumed by the first view.
    test()->get(route('password.request'))->assertDontSee('kept@example.com');
});

it('ignores an expired prefill and still answers neutrally for any email', function () {
    test()->withSession(['recovery_prefill' => ['email' => 'stale@example.com', 'until' => now()->subMinute()]])
        ->get(route('password.request'))
        ->assertDontSee('stale@example.com');

    Mail::fake();
    test()->post(route('password.email'), ['email' => 'stale@example.com'])->assertSessionHas('status', RECOVERY_NEUTRAL);
    Mail::assertNothingQueued();
});

// ── OTP countdown follows the real expiry ─────────────────────────────────
function recoveryCountdownSeconds(): int
{
    $html = test()->get(route('password.otp.show'))->assertOk()->getContent();
    preg_match('/let secondsLeft = (\d+);/', $html, $m);

    return (int) $m[1];
}

it('does not restart the countdown on refresh and never extends the code', function () {
    recoveryUser(2, 'timer@example.com');
    recoveryRequestCode('timer@example.com');

    $first = recoveryCountdownSeconds();
    expect($first)->toBeBetween(298, 300);

    test()->travel(90)->seconds();
    $afterRefresh = recoveryCountdownSeconds();
    expect($afterRefresh)->toBeBetween($first - 92, $first - 88);

    // A refresh asks for no new code, so validity is unchanged.
    expect(StaffPasswordResetOtp::where('email', 'timer@example.com')->first()->expires_at->diffInSeconds(now(), true))->toBeLessThan(215);
});

it('shows zero seconds once the code has expired and resets the countdown on resend', function () {
    recoveryUser(2, 'timer2@example.com');
    recoveryRequestCode('timer2@example.com');

    test()->travel(301)->seconds();
    expect(recoveryCountdownSeconds())->toBe(0);

    Mail::fake();
    test()->post(route('password.otp.resend'))->assertSessionHas('otp_resent', true);
    expect(recoveryCountdownSeconds())->toBeBetween(298, 300);
});

it('gives an unregistered email the same countdown behaviour as a real one', function () {
    Mail::fake();
    test()->post(route('password.email'), ['email' => 'ghost-timer@example.com']);

    $first = recoveryCountdownSeconds();
    test()->travel(60)->seconds();
    $second = recoveryCountdownSeconds();

    expect($first)->toBeBetween(298, 300)
        ->and($second)->toBeBetween($first - 62, $first - 58);
});

it('shows the same countdown for a real and an unknown email even after the request rate limit is hit', function () {
    recoveryUser(2, 'rate-real@example.com');
    Mail::fake();

    $seen = [];
    foreach (['rate-real@example.com', 'rate-ghost@example.com'] as $email) {
        // Use up the per-email request limit, then ask again from a fresh browser session: that request
        // is rate limited and issues nothing, so only the page's own countdown source can differ.
        test()->travel(61)->seconds(); // keeps the route-level throttle (6 per minute) out of the way
        test()->flushSession();
        foreach (range(1, 3) as $i) {
            test()->post(route('password.email'), ['email' => $email]);
        }
        test()->flushSession();
        test()->post(route('password.email'), ['email' => $email]);
        $seen[$email] = recoveryCountdownSeconds();
    }

    // Neither shows a live countdown: no code was issued to this browser, and nothing reveals which email is real.
    expect($seen['rate-real@example.com'])->toBe($seen['rate-ghost@example.com'])
        ->and($seen['rate-real@example.com'])->toBe(0);
});
