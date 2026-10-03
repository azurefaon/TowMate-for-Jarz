<?php

use App\Mail\LoginUnlockOtpMail;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function recoveryE2eUser(string $email): User
{
    if (! Role::find(2)) {
        $role = new Role(['name' => 'Dispatcher']);
        $role->id = 2;
        $role->save();
    }

    return User::factory()->create([
        'role_id' => 2,
        'status' => 'active',
        'email' => $email,
        'password' => Hash::make('CorrectPass123!'),
    ]);
}

function recoveryE2eOtpFor(User $user): string
{
    $otp = null;
    Mail::assertQueued(LoginUnlockOtpMail::class, function (LoginUnlockOtpMail $mail) use ($user, &$otp) {
        if ($mail->user->is($user)) {
            $otp = $mail->otp;
        }

        return true;
    });

    return (string) $otp;
}

it('runs the full lock, recover, verify, success, and sign-in flow without errors or loops', function () {
    Mail::fake();
    $user = recoveryE2eUser('recover-e2e@example.com');

    // Real lockout through the login endpoint.
    for ($i = 0; $i < 3; $i++) {
        sleep(11);
        $this->from('/login')->post('/login', ['role' => 'dispatcher', 'email' => $user->email, 'password' => 'Wrong-' . $i]);
    }
    expect($user->fresh()->locked_until)->not->toBeNull();

    $this->get(route('login.recover'))->assertOk();
    $this->post(route('login.recover.send'), ['email' => $user->email])->assertRedirect(route('login.recover.verify'));
    $this->get(route('login.recover.verify'))->assertOk();

    $otp = recoveryE2eOtpFor($user);

    // Follow every redirect after the correct OTP: must land on a 200 success page, never loop.
    $this->followingRedirects()
        ->post(route('login.recover.verify.submit'), ['otp' => $otp])
        ->assertOk()
        ->assertSee('Access recovered')
        ->assertSee(route('login'), false);

    $fresh = $user->fresh();
    expect($fresh->locked_until)->toBeNull()
        ->and((int) $fresh->failed_login_attempts)->toBe(0)
        ->and(AuditLog::where('user_id', $user->id)->where('action', 'account_unlocked_via_email')->exists())->toBeTrue();

    // Success page is directly loadable again and returns to login normally.
    $this->get(route('login.recover.success'))->assertOk();
    $this->get(route('login'))->assertOk();

    // The used OTP cannot be replayed.
    $this->withSession(['login_recover_email' => $user->email])
        ->post(route('login.recover.verify.submit'), ['otp' => $otp])
        ->assertSessionHasErrors('otp');

    // And the account signs in normally with its password.
    sleep(11);
    $this->post('/login', ['role' => 'dispatcher', 'email' => $user->email, 'password' => 'CorrectPass123!'])
        ->assertRedirect(route('admin.dashboard', absolute: false));
});

it('does not let one account\'s OTP unlock a different locked account', function () {
    Mail::fake();
    $alice = recoveryE2eUser('recover-alice@example.com');
    $bob = recoveryE2eUser('recover-bob@example.com');
    foreach ([$alice, $bob] as $user) {
        $user->forceFill(['failed_login_attempts' => 3, 'locked_until' => now()->addMinutes(15)])->save();
        $this->post(route('login.recover.send'), ['email' => $user->email]);
    }

    $bobOtp = recoveryE2eOtpFor($bob);

    $this->withSession(['login_recover_email' => $alice->email])
        ->post(route('login.recover.verify.submit'), ['otp' => $bobOtp])
        ->assertSessionHasErrors('otp');

    expect($alice->fresh()->locked_until)->not->toBeNull()
        ->and($bob->fresh()->locked_until)->not->toBeNull();
});
