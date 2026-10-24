<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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

it('renders the login page', function () {
    test()->get('/login')->assertOk();
});

it('renders the new JARZ logo asset', function () {
    test()->get('/login')->assertSee('dispatcher/images/jarz-logo.png', false);
});

it('renders the current Staff Login page with email, password and a hidden role', function () {
    $response = test()->get('/login');

    $response->assertOk()
        ->assertSee('Staff Login')
        ->assertSee('name="email"', false)
        ->assertSee('name="password"', false)
        ->assertSee('name="role"', false)
        ->assertSee('Forgot password?');
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

it('renders the Android download with the real existing APK target', function () {
    $response = test()->get('/');

    $response->assertSee('Android');
    $response->assertSee(route('download.android'), false);
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
    // The sentence wraps across lines in the view, so compare with whitespace normalised.
    expect(preg_replace('/\s+/', ' ', $response->getContent()))
        ->toContain('Too many unsuccessful sign-in attempts. Your sign-in access is temporarily locked. Verify your email to recover access.');
    $response->assertSee('Recover your account');
    $response->assertSee(route('password.request'), false);
    $response->assertDontSee('locked-user@example.com%40', false);
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
