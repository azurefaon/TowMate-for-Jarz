<?php

use App\Mail\LoginUnlockOtpMail;
use App\Models\AuditLog;
use App\Models\LoginUnlockOtp;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function lockoutRole(int $id, string $name): void
{
    if (Role::find($id)) {
        return;
    }

    $role = new Role(['name' => $name]);
    $role->id = $id;
    $role->save();
}

function lockoutUser(int $roleId, array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => $roleId,
        'status' => 'active',
        'password' => Hash::make('CorrectPass123!'),
    ], $attrs));
}

function lockoutAttempt(User $user, string $password, string $role): \Illuminate\Testing\TestResponse
{
    sleep(11);

    return test()->from('/login')->post('/login', [
        'role' => $role,
        'email' => $user->email,
        'password' => $password,
    ]);
}

it('logs in successfully with the correct password when the account has no prior failures', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'sec-1@example.com']);

    test()->post('/login', [
        'role' => 'dispatcher',
        'email' => $user->email,
        'password' => 'CorrectPass123!',
    ])->assertRedirect(route('admin.dashboard', absolute: false));

    expect(auth('dispatcher')->check())->toBeTrue();
});

it('does not lock the account after one or two consecutive wrong passwords', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'sec-2@example.com']);

    lockoutAttempt($user, 'wrong-one', 'dispatcher')->assertSessionHasErrorsIn('login', ['auth']);
    lockoutAttempt($user, 'wrong-two', 'dispatcher')->assertSessionHasErrorsIn('login', ['auth']);

    $fresh = $user->fresh();
    expect((int) $fresh->failed_login_attempts)->toBe(2);
    expect($fresh->locked_until)->toBeNull();
});

it('locks the account on the third consecutive wrong password with the exact required copy', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'sec-3@example.com']);

    lockoutAttempt($user, 'wrong-one', 'dispatcher');
    lockoutAttempt($user, 'wrong-two', 'dispatcher');
    $response = lockoutAttempt($user, 'wrong-three', 'dispatcher');

    $response->assertSessionHas('login_locked', true);
    $response->assertSessionHas('login_locked_email', $user->email);
    $response->assertSessionHasErrorsIn('login', [
        'auth' => 'Too many unsuccessful sign-in attempts. Your sign-in access is temporarily locked. Verify your email to recover access.',
    ]);

    $fresh = $user->fresh();
    expect((int) $fresh->failed_login_attempts)->toBe(3);
    expect($fresh->locked_until)->not->toBeNull();

    expect(AuditLog::where('user_id', $user->id)->where('action', 'account_temporarily_locked')->where('category', 'security')->exists())->toBeTrue();
});

it('rejects even the correct password while the account is locked', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'sec-4@example.com']);

    lockoutAttempt($user, 'wrong-one', 'dispatcher');
    lockoutAttempt($user, 'wrong-two', 'dispatcher');
    lockoutAttempt($user, 'wrong-three', 'dispatcher');

    $response = lockoutAttempt($user, 'CorrectPass123!', 'dispatcher');

    $response->assertSessionHasErrorsIn('login', ['auth']);
    expect(auth('dispatcher')->check())->toBeFalse();
});

it('does not increment the failed-attempt counter for a nonexistent email', function () {
    lockoutRole(2, 'Dispatcher');

    test()->post('/login', [
        'role' => 'dispatcher',
        'email' => 'nobody-' . uniqid() . '@example.com',
        'password' => 'whatever',
    ])->assertSessionHasErrorsIn('login', ['auth']);

    expect(AuditLog::where('action', 'account_temporarily_locked')->exists())->toBeFalse();
});

it('does not increment the failed-attempt counter for an inactive account', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'sec-6@example.com', 'status' => 'inactive']);

    lockoutAttempt($user, 'wrong-one', 'dispatcher');
    lockoutAttempt($user, 'wrong-two', 'dispatcher');
    lockoutAttempt($user, 'wrong-three', 'dispatcher');

    $fresh = $user->fresh();
    expect((int) $fresh->failed_login_attempts)->toBe(0);
    expect($fresh->locked_until)->toBeNull();
});

it('resets the failed-attempt counter after a successful login', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'sec-7@example.com']);

    lockoutAttempt($user, 'wrong-one', 'dispatcher');

    $fresh = $user->fresh();
    expect((int) $fresh->failed_login_attempts)->toBe(1);

    lockoutAttempt($user, 'CorrectPass123!', 'dispatcher')
        ->assertRedirect(route('admin.dashboard', absolute: false));

    $fresh = $user->fresh();
    expect((int) $fresh->failed_login_attempts)->toBe(0);
    expect($fresh->locked_until)->toBeNull();
});

it('sends an unlock OTP for a locked, eligible account with a generic response', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'otp-1@example.com']);
    Mail::fake();

    $response = test()->post(route('login.recover.send'), ['email' => $user->email]);

    $response->assertRedirect(route('login.recover.verify'));
    $response->assertSessionHas('status', 'If that account is locked, a verification code has been sent to its email address.');

    expect(LoginUnlockOtp::where('email', $user->email)->exists())->toBeTrue();
    Mail::assertQueued(LoginUnlockOtpMail::class);
});

it('returns an identical generic response for an unknown email and creates no OTP', function () {
    Mail::fake();

    $response = test()->post(route('login.recover.send'), ['email' => 'nobody-' . uniqid() . '@example.com']);

    $response->assertRedirect(route('login.recover.verify'));
    $response->assertSessionHas('status', 'If that account is locked, a verification code has been sent to its email address.');

    expect(LoginUnlockOtp::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('allows an Owner account to receive an unlock OTP, unlike the password-reset flow', function () {
    lockoutRole(1, 'Owner');
    $owner = lockoutUser(1, ['email' => 'otp-owner@example.com']);
    Mail::fake();

    test()->post(route('login.recover.send'), ['email' => $owner->email]);

    expect(LoginUnlockOtp::where('email', $owner->email)->exists())->toBeTrue();
    Mail::assertQueued(LoginUnlockOtpMail::class);
});

it('clears the lock on correct OTP verification without logging the user in', function () {
    lockoutRole(3, 'Team Leader');
    $user = lockoutUser(3, ['email' => 'otp-2@example.com']);
    $user->forceFill(['failed_login_attempts' => 3, 'locked_until' => now()->addMinutes(15)])->save();

    Mail::fake();
    test()->post(route('login.recover.send'), ['email' => $user->email]);
    $otp = null;
    Mail::assertQueued(LoginUnlockOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    $session = test()->withSession(['login_recover_email' => $user->email]);
    $session->post(route('login.recover.verify.submit'), ['otp' => $otp])
        ->assertRedirect(route('login.recover.success'));

    $fresh = $user->fresh();
    expect((int) $fresh->failed_login_attempts)->toBe(0);
    expect($fresh->locked_until)->toBeNull();
    expect(auth('teamleader')->check())->toBeFalse();
    expect(auth('web')->check())->toBeFalse();

    expect(AuditLog::where('user_id', $user->id)->where('action', 'account_unlocked_via_email')->where('category', 'security')->exists())->toBeTrue();
});

it('rejects an incorrect OTP with a generic error and audits the failure', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'otp-3@example.com']);

    Mail::fake();
    test()->post(route('login.recover.send'), ['email' => $user->email]);

    test()->withSession(['login_recover_email' => $user->email])
        ->post(route('login.recover.verify.submit'), ['otp' => '000000'])
        ->assertSessionHasErrors('otp');

    expect(AuditLog::where('user_id', $user->id)->where('action', 'unlock_otp_failed')->where('category', 'security')->exists())->toBeTrue();
});

it('deletes the OTP record after exceeding the maximum verification attempts', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'otp-4@example.com']);

    Mail::fake();
    test()->post(route('login.recover.send'), ['email' => $user->email]);
    $otp = null;
    Mail::assertQueued(LoginUnlockOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    for ($i = 0; $i < 5; $i++) {
        test()->withSession(['login_recover_email' => $user->email])
            ->post(route('login.recover.verify.submit'), ['otp' => '000000'])
            ->assertSessionHasErrors('otp');
    }

    test()->withSession(['login_recover_email' => $user->email])
        ->post(route('login.recover.verify.submit'), ['otp' => $otp])
        ->assertSessionHasErrors('otp');

    expect(LoginUnlockOtp::where('email', $user->email)->exists())->toBeFalse();
});

it('rejects an unlock OTP once it has expired', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'otp-5@example.com']);

    Mail::fake();
    test()->post(route('login.recover.send'), ['email' => $user->email]);
    $otp = null;
    Mail::assertQueued(LoginUnlockOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    test()->travel(301)->seconds();

    test()->withSession(['login_recover_email' => $user->email])
        ->post(route('login.recover.verify.submit'), ['otp' => $otp])
        ->assertSessionHasErrors('otp');
});

it('does not allow a verified unlock OTP to be reused', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'otp-6@example.com']);

    Mail::fake();
    test()->post(route('login.recover.send'), ['email' => $user->email]);
    $otp = null;
    Mail::assertQueued(LoginUnlockOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    $session = test()->withSession(['login_recover_email' => $user->email]);
    $session->post(route('login.recover.verify.submit'), ['otp' => $otp])
        ->assertRedirect(route('login.recover.success'));

    test()->withSession(['login_recover_email' => $user->email])
        ->post(route('login.recover.verify.submit'), ['otp' => $otp])
        ->assertSessionHasErrors('otp');
});

it('ignores a resend within the cooldown and sends a fresh OTP that invalidates the old one afterward', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'otp-7@example.com']);

    Mail::fake();
    test()->post(route('login.recover.send'), ['email' => $user->email]);
    $firstOtp = null;
    Mail::assertQueued(LoginUnlockOtpMail::class, function ($mail) use (&$firstOtp) {
        $firstOtp = $mail->otp;
        return true;
    });

    Mail::fake();
    test()->withSession(['login_recover_email' => $user->email])->post(route('login.recover.resend'));
    Mail::assertNothingQueued();

    test()->travel(61)->seconds();

    Mail::fake();
    test()->withSession(['login_recover_email' => $user->email])->post(route('login.recover.resend'));
    $secondOtp = null;
    Mail::assertQueued(LoginUnlockOtpMail::class, function ($mail) use (&$secondOtp) {
        $secondOtp = $mail->otp;
        return true;
    });

    expect($firstOtp)->not->toBe($secondOtp);

    test()->withSession(['login_recover_email' => $user->email])
        ->post(route('login.recover.verify.submit'), ['otp' => $firstOtp])
        ->assertSessionHasErrors('otp');

    test()->withSession(['login_recover_email' => $user->email])
        ->post(route('login.recover.verify.submit'), ['otp' => $secondOtp])
        ->assertRedirect(route('login.recover.success'));
});

it('still requires the correct password after a successful recovery and audits the follow-up login', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'otp-8@example.com']);
    $user->forceFill(['failed_login_attempts' => 3, 'locked_until' => now()->addMinutes(15)])->save();

    Mail::fake();
    test()->post(route('login.recover.send'), ['email' => $user->email]);
    $otp = null;
    Mail::assertQueued(LoginUnlockOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    $recoverySession = test()->withSession(['login_recover_email' => $user->email]);
    $recoverySession->post(route('login.recover.verify.submit'), ['otp' => $otp])
        ->assertRedirect(route('login.recover.success'));

    $recoverySession->post('/login', [
        'role' => 'dispatcher',
        'email' => $user->email,
        'password' => 'CorrectPass123!',
    ])->assertRedirect(route('admin.dashboard', absolute: false));

    expect(auth('dispatcher')->check())->toBeTrue();
    expect(AuditLog::where('user_id', $user->id)->where('action', 'login_success_after_recovery')->where('category', 'security')->exists())->toBeTrue();
});

it('keeps an inactive account blocked even after its lock is cleared via OTP', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'otp-9@example.com']);
    $user->forceFill(['failed_login_attempts' => 3, 'locked_until' => now()->addMinutes(15)])->save();

    Mail::fake();
    test()->post(route('login.recover.send'), ['email' => $user->email]);
    $otp = null;
    Mail::assertQueued(LoginUnlockOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    test()->withSession(['login_recover_email' => $user->email])
        ->post(route('login.recover.verify.submit'), ['otp' => $otp])
        ->assertRedirect(route('login.recover.success'));

    $fresh = $user->fresh();
    expect($fresh->locked_until)->toBeNull();

    $user->forceFill(['status' => 'inactive'])->save();

    test()->post('/login', [
        'role' => 'dispatcher',
        'email' => $user->email,
        'password' => 'CorrectPass123!',
    ])->assertSessionHasErrorsIn('login', ['auth']);

    expect(auth('dispatcher')->check())->toBeFalse();
});

it('never exposes the unlock OTP in page HTML or audit log fields', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'otp-10@example.com']);

    Mail::fake();
    test()->post(route('login.recover.send'), ['email' => $user->email]);
    $otp = null;
    Mail::assertQueued(LoginUnlockOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    $html = test()->withSession(['login_recover_email' => $user->email])->get(route('login.recover.verify'))->getContent();
    expect($html)->not->toContain($otp);

    $logs = AuditLog::where('user_id', $user->id)->get();
    foreach ($logs as $log) {
        expect((string) $log->description)->not->toContain($otp);
        expect((string) $log->reference)->not->toContain($otp);
    }
});

it('renders the login page', function () {
    test()->get('/login')->assertOk();
});

it('renders the new JARZ logo asset', function () {
    test()->get('/login')->assertSee('dispatcher/images/jarz-logo.png', false);
});

it('references the login background image asset', function () {
    $css = file_get_contents(public_path('admin/css/login.css'));

    expect(file_exists(public_path('admin/images/login-background-image.png')))->toBeTrue();
    expect($css)->toContain('/admin/images/login-background-image.png');
    expect($css)->toContain('background-size: cover;');
});

it('renders the dark overlay and login-page background style hook', function () {
    $html = test()->get('/login')->getContent();
    $css = file_get_contents(public_path('admin/css/login.css'));

    expect($html)->toContain('class="login-page"');
    expect($css)->toContain('.login-page::before');
    expect($css)->toContain('rgba(0, 0, 0, 0.42)');
});

it('reduces the desktop logo width for better balance with the heading', function () {
    $css = file_get_contents(public_path('admin/css/login.css'));

    expect($css)->toContain(".brand-logo {\n    width: 290px;");
});

it('lifts the left content block and wraps it for the localized readability layer', function () {
    $html = test()->get('/login')->getContent();
    $css = file_get_contents(public_path('admin/css/login.css'));

    expect($html)->toContain('class="login-left-content"');
    expect($css)->toContain('.login-left::before');
    expect($css)->toMatch('/\.login-left\s*\{[^}]*transform:\s*translateY\(-40px\);/');
});

it('no longer renders the old admin logo asset', function () {
    $html = test()->get('/login')->getContent();

    expect($html)->not->toContain('admin/images/logo.png');
});

it('points the favicon at the new JARZ logo', function () {
    $html = test()->get('/login')->getContent();

    expect($html)->toContain('rel="icon"');
    expect($html)->toContain('dispatcher/images/jarz-logo.png');
    expect(substr_count($html, 'rel="icon"'))->toBe(1);
});

it('does not apply a border or outline to the inner input on focus', function () {
    $css = file_get_contents(public_path('admin/css/login.css'));

    expect($css)->toContain(".input-shell input {\n    flex: 1;\n    min-width: 0;\n    border: 0;\n    outline: none;\n    box-shadow: none;");
    expect($css)->toContain(".input-shell input:focus,\n.input-shell input:focus-visible {\n    border: 0;\n    outline: none;\n    box-shadow: none;\n}");
    expect($css)->not->toMatch('/input:focus-visible\s*\{\s*outline:\s*3px/');
});

it('applies the focus-within treatment on the input wrapper', function () {
    $css = file_get_contents(public_path('admin/css/login.css'));

    expect($css)->toContain(".input-shell:focus-within {\n    border-color: #171717;\n    background: #fcfcfc;\n    box-shadow: 0 0 0 2px rgba(23, 23, 23, 0.08);\n}");
});

it('renders the approved heading copy', function () {
    $response = test()->get('/login');

    $response->assertSee('Towing operations');
    $response->assertSee('made', false);
    $response->assertSee('simple.', false);
});

it('renders the Android download with the real existing APK target', function () {
    $response = test()->get('/login');

    $response->assertSee('Android');
    $response->assertSee(route('download.android'), false);
});

it('styles the Android and iOS controls as black store-style badges with truthful wording', function () {
    $html = test()->get('/login')->getContent();
    $css = file_get_contents(public_path('admin/css/login.css'));

    expect($html)->not->toContain('Get it on Google Play');
    expect($html)->not->toContain('Download on the App Store');
    expect($html)->not->toContain('play.google.com');
    expect($html)->not->toContain('apps.apple.com');
    expect($html)->not->toContain('testflight');

    expect($css)->toContain(".app-btn-android {\n    background: #171717;\n    border: 1px solid rgba(255, 255, 255, 0.18);\n}");
    expect($css)->toContain(".app-btn-ios {\n    background: #171717;\n    border: 1px solid rgba(255, 255, 255, 0.18);\n    cursor: not-allowed;");
});

it('renders a disabled iOS Coming soon control', function () {
    $html = test()->get('/login')->getContent();

    expect($html)->toContain('iOS');
    expect($html)->toContain('Coming soon');
    expect($html)->toContain('app-btn-ios');
    expect($html)->toContain('aria-disabled="true"');
});

it('renders the Welcome back heading', function () {
    test()->get('/login')->assertSee('Welcome back');
});

it('renders the email field', function () {
    $response = test()->get('/login');

    $response->assertSee('Email address');
    $response->assertSee('id="email"', false);
});

it('renders the password field', function () {
    $response = test()->get('/login');

    $response->assertSee('Password', false);
    $response->assertSee('id="password"', false);
});

it('keeps the Show/Hide password toggle markup and script functional', function () {
    $html = test()->get('/login')->getContent();

    expect($html)->toContain('id="togglePassword"');
    expect($html)->toContain('>Show<');
    expect($html)->toContain("passwordInput.type = nextType");
});

it('keeps the forgot-password route unchanged', function () {
    test()->get('/login')->assertSee(route('password.request'), false);
});

it('places Forgot password after the password field, right-aligned, and Sign in after that', function () {
    $html = test()->get('/login')->getContent();

    $passwordFieldPos = strpos($html, 'id="password"');
    $forgotPos = strpos($html, 'Forgot password?');
    $formFooterPos = strpos($html, 'class="form-footer"');
    $signInPos = strpos($html, 'id="loginButton"');

    expect($passwordFieldPos)->not->toBeFalse();
    expect($forgotPos)->not->toBeFalse();
    expect($formFooterPos)->not->toBeFalse();
    expect($signInPos)->not->toBeFalse();

    expect($passwordFieldPos)->toBeLessThan($forgotPos);
    expect($forgotPos)->toBeLessThan($signInPos);

    $css = file_get_contents(public_path('admin/css/login.css'));
    expect($css)->toContain("justify-content: flex-end;");
});

it('keeps Sign in full-width below Forgot password', function () {
    $html = test()->get('/login')->getContent();
    expect($html)->toContain('class="primary-btn" id="loginButton"');

    $css = file_get_contents(public_path('admin/css/login.css'));
    expect($css)->toContain("width: 100%;\n    height: 48px;");
});

it('renders the authorized access note', function () {
    test()->get('/login')->assertSee('Authorized access only.');
});

it('shows the locked-state block with the exact required copy and a recover-access action', function () {
    $response = test()->withSession([
        'login_locked' => true,
        'login_locked_email' => 'locked-user@example.com',
    ])->get('/login');

    $response->assertOk();
    $response->assertSee('Too many unsuccessful sign-in attempts. Your sign-in access is temporarily locked. Verify your email to recover access.');
    $response->assertSee(route('login.recover', ['email' => 'locked-user@example.com']), false);
});

it('renders validation errors on the login page', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'ui-err@example.com']);

    $response = test()->from('/login')->post('/login', [
        'role' => 'dispatcher',
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('login', ['auth']);
    test()->get('/login')->assertSee('Unable to sign in with the provided credentials.');
});

it('renders the recovery request, verify, and success pages with the new JARZ favicon', function () {
    lockoutRole(2, 'Dispatcher');
    $user = lockoutUser(2, ['email' => 'favicon-1@example.com']);

    $requestHtml = test()->get(route('login.recover'))->assertOk()->getContent();
    expect($requestHtml)->toContain('dispatcher/images/jarz-logo.png');
    expect($requestHtml)->not->toContain('admin/images/logo.png');
    expect(substr_count($requestHtml, 'rel="icon"'))->toBe(1);

    Mail::fake();
    test()->post(route('login.recover.send'), ['email' => $user->email]);

    $verifyHtml = test()->withSession(['login_recover_email' => $user->email])
        ->get(route('login.recover.verify'))
        ->assertOk()
        ->getContent();
    expect($verifyHtml)->toContain('dispatcher/images/jarz-logo.png');
    expect($verifyHtml)->not->toContain('admin/images/logo.png');
    expect(substr_count($verifyHtml, 'rel="icon"'))->toBe(1);

    $successHtml = test()->get(route('login.recover.success'))->assertOk()->getContent();
    expect($successHtml)->toContain('dispatcher/images/jarz-logo.png');
    expect($successHtml)->not->toContain('admin/images/logo.png');
    expect(substr_count($successHtml, 'rel="icon"'))->toBe(1);
});
