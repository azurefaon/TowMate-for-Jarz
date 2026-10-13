<?php

// TC004.02 regression coverage: validation, enumeration safety, code isolation
// and atomic single-use consumption for both the staff web flow and the
// customer API flow.

use App\Mail\PasswordResetOtpMail;
use App\Mail\StaffPasswordResetOtpMail;
use App\Models\Role;
use App\Models\StaffPasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

const RECOVERY_CONFIRMATION = "If an account uses this email, we'll send password reset instructions. Please check your inbox.";

function hardeningStaff(string $email, array $attrs = []): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher', 'description' => 'Dispatch staff']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(array_merge([
        'email' => $email,
        'role_id' => $role->id,
        'must_change_password' => false,
        'status' => 'active',
        'password' => Hash::make('OldPassword123!'),
    ], $attrs));
}

function hardeningStaffOtp(string $email): string
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

function hardeningApiOtp(string $email): string
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

function hardeningApiToken(string $email): string
{
    $otp = hardeningApiOtp($email);

    return test()->postJson('/api/password/verify-otp', ['email' => $email, 'otp' => $otp])->json('reset_token');
}

// ── Request validation ────────────────────────────────────────────────────
it('staff: a blank email is rejected with a required error', function () {
    test()->post(route('password.email'), ['email' => ''])->assertSessionHasErrors('email');
});

it('staff: a malformed email is rejected with a format error', function () {
    test()->post(route('password.email'), ['email' => 'not-an-email'])->assertSessionHasErrors('email');
});

it('api: a blank email is rejected', function () {
    test()->postJson('/api/password/forgot', ['email' => ''])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('api: a malformed email is rejected', function () {
    test()->postJson('/api/password/forgot', ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email');
});

// ── Enumeration safety: identical confirmation and navigation ──────────────
it('staff: registered and unregistered emails get identical confirmation text and navigation', function () {
    Mail::fake();
    hardeningStaff('real@example.com');

    $known = test()->post(route('password.email'), ['email' => 'real@example.com']);
    $unknown = test()->post(route('password.email'), ['email' => 'ghost@example.com']);

    $known->assertRedirect(route('password.otp.show'))->assertSessionHas('status', RECOVERY_CONFIRMATION);
    $unknown->assertRedirect(route('password.otp.show'))->assertSessionHas('status', RECOVERY_CONFIRMATION);
    expect($unknown->getStatusCode())->toBe($known->getStatusCode());

    Mail::assertQueued(StaffPasswordResetOtpMail::class, 1);
    expect(StaffPasswordResetOtp::where('email', 'ghost@example.com')->exists())->toBeFalse();
});

it('staff: the code-entry page renders for an unregistered email exactly like a registered one', function () {
    hardeningStaff('real@example.com');

    foreach (['real@example.com', 'ghost@example.com'] as $email) {
        test()->withSession(['staff_pw_reset_email' => $email, 'staff_pw_reset_last_sent_at' => now()])
            ->get(route('password.otp.show'))
            ->assertOk()
            ->assertSee('name="otp"', false)
            ->assertSee(route('password.otp.resend'), false);
    }
});

it('api: registered and unregistered emails get identical status and body', function () {
    Mail::fake();
    User::factory()->create(['email' => 'real@example.com']);

    $known = test()->postJson('/api/password/forgot', ['email' => 'real@example.com']);
    $unknown = test()->postJson('/api/password/forgot', ['email' => 'ghost@example.com']);

    expect($unknown->getStatusCode())->toBe($known->getStatusCode())
        ->and($unknown->json())->toBe($known->json())
        ->and($known->json('message'))->toBe(RECOVERY_CONFIRMATION);
    Mail::assertQueued(PasswordResetOtpMail::class, 1);
});

it('api: verify-otp gives one identical response for unknown, wrong, expired and consumed codes', function () {
    $user = User::factory()->create(['email' => 'real@example.com']);
    $otp = hardeningApiOtp($user->email);

    $unknown = test()->postJson('/api/password/verify-otp', ['email' => 'ghost@example.com', 'otp' => '123456']);
    $wrong = test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp === '000000' ? '000001' : '000000']);

    test()->travel(6)->minutes();
    $expired = test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp]);
    test()->travelBack();

    test()->travel(2)->minutes();
    $otp2 = hardeningApiOtp($user->email);
    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp2])->assertOk();
    $consumed = test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp2]);

    foreach ([$wrong, $expired, $consumed] as $response) {
        expect($response->getStatusCode())->toBe($unknown->getStatusCode())
            ->and($response->json())->toBe($unknown->json());
    }
});

// ── Staff: code isolation, expiry, single use ─────────────────────────────
it('staff: a valid code resets only the correct account', function () {
    $target = hardeningStaff('target@example.com');
    $other = hardeningStaff('other@example.com');
    $otp = hardeningStaffOtp($target->email);

    test()->withSession(['staff_pw_reset_email' => $target->email])
        ->post(route('password.otp.verify'), ['otp' => $otp])
        ->assertRedirect(route('password.reset'));

    test()->withSession(['staff_pw_reset_verified_email' => $target->email, 'staff_pw_reset_verified_until' => now()->addMinutes(5)])
        ->post(route('password.store'), ['password' => 'BrandNewPassword123!', 'password_confirmation' => 'BrandNewPassword123!'])
        ->assertRedirect(route('password.reset.success'));

    expect(Hash::check('BrandNewPassword123!', $target->fresh()->password))->toBeTrue()
        ->and(Hash::check('OldPassword123!', $other->fresh()->password))->toBeTrue();
});

it('staff: an incorrect, expired, other-account or missing code is rejected with one message', function () {
    $a = hardeningStaff('a@example.com');
    $b = hardeningStaff('b@example.com');
    $otpA = hardeningStaffOtp($a->email);

    $messages = [];
    $attempt = function (string $email, string $otp) use (&$messages) {
        $r = test()->withSession(['staff_pw_reset_email' => $email])->post(route('password.otp.verify'), ['otp' => $otp]);
        $r->assertSessionHasErrors('otp');
        $messages[] = session('errors')->first('otp');
    };

    $attempt($a->email, $otpA === '111111' ? '222222' : '111111');   // incorrect
    $attempt($b->email, $otpA);                                       // another account's code (b has no code)
    $attempt('ghost@example.com', $otpA);                             // no account
    test()->travel(301)->seconds();
    $attempt($a->email, $otpA);                                       // expired
    test()->travelBack();

    expect(array_unique($messages))->toHaveCount(1);

    test()->withSession(['staff_pw_reset_email' => $a->email])->post(route('password.otp.verify'), [])->assertSessionHasErrors('otp');
});

it('staff: password reset without a verified code is refused and changes nothing', function () {
    $user = hardeningStaff('nocode@example.com');

    test()->post(route('password.store'), ['password' => 'BrandNewPassword123!', 'password_confirmation' => 'BrandNewPassword123!'])
        ->assertRedirect(route('password.request'));

    // Authorized in session but the stored code is gone (e.g. already consumed).
    test()->withSession(['staff_pw_reset_verified_email' => $user->email, 'staff_pw_reset_verified_until' => now()->addMinutes(5)])
        ->post(route('password.store'), ['password' => 'BrandNewPassword123!', 'password_confirmation' => 'BrandNewPassword123!'])
        ->assertRedirect(route('password.request'));

    expect(Hash::check('OldPassword123!', $user->fresh()->password))->toBeTrue();
});

it('staff: a consumed code cannot reset the password a second time', function () {
    $user = hardeningStaff('reuse@example.com');
    $otp = hardeningStaffOtp($user->email);
    test()->withSession(['staff_pw_reset_email' => $user->email])->post(route('password.otp.verify'), ['otp' => $otp]);

    $session = ['staff_pw_reset_verified_email' => $user->email, 'staff_pw_reset_verified_until' => now()->addMinutes(5)];
    test()->withSession($session)->post(route('password.store'), ['password' => 'FirstNewPassword123!', 'password_confirmation' => 'FirstNewPassword123!'])
        ->assertRedirect(route('password.reset.success'));
    test()->withSession($session)->post(route('password.store'), ['password' => 'SecondNewPassword123!', 'password_confirmation' => 'SecondNewPassword123!'])
        ->assertRedirect(route('password.request'));

    expect(Hash::check('FirstNewPassword123!', $user->fresh()->password))->toBeTrue();
});

it('staff: a competing reset that consumes the code first wins and the late request fails', function () {
    $user = hardeningStaff('race@example.com');
    $otp = hardeningStaffOtp($user->email);
    test()->withSession(['staff_pw_reset_email' => $user->email])->post(route('password.otp.verify'), ['otp' => $otp]);

    // Simulate a concurrent request consuming the code between our read and our write.
    $fired = false;
    DB::beforeExecuting(function (string $query) use (&$fired, $user) {
        if (! $fired && str_starts_with(strtolower($query), 'delete from "staff_password_reset_otps"')) {
            $fired = true;
            DB::table('staff_password_reset_otps')->where('email', $user->email)->delete();
        }
    });

    test()->withSession(['staff_pw_reset_verified_email' => $user->email, 'staff_pw_reset_verified_until' => now()->addMinutes(5)])
        ->post(route('password.store'), ['password' => 'LateNewPassword123!', 'password_confirmation' => 'LateNewPassword123!'])
        ->assertRedirect(route('password.request'));

    expect($fired)->toBeTrue()
        ->and(Hash::check('OldPassword123!', $user->fresh()->password))->toBeTrue();
});

// ── API: code isolation and atomic consumption ────────────────────────────
it('api: a valid code resets the correct account, not another one', function () {
    $target = User::factory()->create(['email' => 'target@example.com', 'password' => Hash::make('OldPassword123!')]);
    $other = User::factory()->create(['email' => 'other@example.com', 'password' => Hash::make('OtherPassword123!')]);
    $token = hardeningApiToken($target->email);

    test()->postJson('/api/password/reset', [
        'email' => $target->email, 'reset_token' => $token,
        'password' => 'BrandNewPassword123!', 'password_confirmation' => 'BrandNewPassword123!',
    ])->assertOk();

    expect(Hash::check('BrandNewPassword123!', $target->fresh()->password))->toBeTrue()
        ->and(Hash::check('OtherPassword123!', $other->fresh()->password))->toBeTrue();
});

it('api: another account\'s OTP cannot be verified for this account and a missing token cannot reset', function () {
    $a = User::factory()->create(['email' => 'a@example.com', 'password' => Hash::make('OldPassword123!')]);
    $b = User::factory()->create(['email' => 'b@example.com', 'password' => Hash::make('OldPassword123!')]);
    $otpA = hardeningApiOtp($a->email);

    test()->postJson('/api/password/verify-otp', ['email' => $b->email, 'otp' => $otpA])->assertStatus(422);

    test()->postJson('/api/password/reset', [
        'email' => $a->email, 'password' => 'BrandNewPassword123!', 'password_confirmation' => 'BrandNewPassword123!',
    ])->assertStatus(422);
    test()->postJson('/api/password/reset', [
        'email' => $a->email, 'reset_token' => 'x', 'password' => 'BrandNewPassword123!', 'password_confirmation' => 'BrandNewPassword123!',
    ])->assertStatus(422);

    expect(Hash::check('OldPassword123!', $a->fresh()->password))->toBeTrue();
});

it('api: a competing request that consumes the token first wins and the late request fails', function () {
    $user = User::factory()->create(['email' => 'race@example.com', 'password' => Hash::make('OldPassword123!')]);
    $token = hardeningApiToken($user->email);

    $fired = false;
    DB::beforeExecuting(function (string $query) use (&$fired, $user) {
        if (! $fired && str_starts_with(strtolower($query), 'update "users" set "password_reset_token_hash"')) {
            $fired = true;
            DB::table('users')->where('id', $user->id)->update(['password_reset_token_hash' => null]);
        }
    });

    test()->postJson('/api/password/reset', [
        'email' => $user->email, 'reset_token' => $token,
        'password' => 'LateNewPassword123!', 'password_confirmation' => 'LateNewPassword123!',
    ])->assertStatus(422);

    expect($fired)->toBeTrue()
        ->and(Hash::check('OldPassword123!', $user->fresh()->password))->toBeTrue();
});

it('api: a competing request that consumes the OTP first wins and the late verify gets no token', function () {
    $user = User::factory()->create(['email' => 'race2@example.com']);
    $otp = hardeningApiOtp($user->email);

    $fired = false;
    DB::beforeExecuting(function (string $query) use (&$fired, $user) {
        if (! $fired && str_starts_with(strtolower($query), 'update "users" set "password_reset_otp_hash"')) {
            $fired = true;
            DB::table('users')->where('id', $user->id)->update(['password_reset_otp_hash' => null]);
        }
    });

    $response = test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp]);

    expect($fired)->toBeTrue();
    $response->assertStatus(422);
    expect($response->json('reset_token'))->toBeNull();
});

// ── Existing auth behaviour is unaffected ─────────────────────────────────
it('login and logout still work after a completed staff reset', function () {
    $user = hardeningStaff('flow@example.com');
    $otp = hardeningStaffOtp($user->email);
    test()->withSession(['staff_pw_reset_email' => $user->email])->post(route('password.otp.verify'), ['otp' => $otp]);
    test()->withSession(['staff_pw_reset_verified_email' => $user->email, 'staff_pw_reset_verified_until' => now()->addMinutes(5)])
        ->post(route('password.store'), ['password' => 'BrandNewPassword123!', 'password_confirmation' => 'BrandNewPassword123!'])
        ->assertRedirect(route('password.reset.success'));

    test()->post(route('login'), ['email' => $user->email, 'password' => 'BrandNewPassword123!']);
    test()->assertAuthenticatedAs($user->fresh());

    test()->post(route('logout'));
    test()->assertGuest();
});
