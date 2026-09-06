<?php

use App\Mail\StaffPasswordResetOtpMail;
use App\Models\Role;
use App\Models\StaffPasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function phase2Dispatcher(array $attrs = []): User
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

// 20 — inactive/archived/pending-delete staff reset response stays generic ──
it('20a: an inactive staff account gets the identical generic response and no OTP is issued', function () {
    $user = phase2Dispatcher(['email' => 'phase2-inactive@example.com', 'status' => 'inactive']);
    Mail::fake();

    $response = test()->post(route('password.email'), ['email' => $user->email]);

    $response->assertRedirect(route('password.otp.show'));
    $response->assertSessionHas('status', 'If an account exists for that email, a verification code has been sent.');
    expect(StaffPasswordResetOtp::where('email', $user->email)->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

it('20b: an archived staff account gets the identical generic response and no OTP is issued', function () {
    $user = phase2Dispatcher(['email' => 'phase2-archived@example.com', 'archived_at' => now()]);
    Mail::fake();

    test()->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', 'If an account exists for that email, a verification code has been sent.');

    expect(StaffPasswordResetOtp::where('email', $user->email)->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

it('20c: a pending-deletion staff account gets the identical generic response and no OTP is issued', function () {
    $user = phase2Dispatcher(['email' => 'phase2-pending@example.com', 'pending_delete_at' => now()]);
    Mail::fake();

    test()->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', 'If an account exists for that email, a verification code has been sent.');

    expect(StaffPasswordResetOtp::where('email', $user->email)->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

// 21 — ineligible staff cannot actually complete a reset ────────────────────
it('21: an inactive staff account cannot obtain a working OTP even though the response looks successful', function () {
    $user = phase2Dispatcher(['email' => 'phase2-noreset@example.com', 'status' => 'inactive']);
    Mail::fake();

    test()->post(route('password.email'), ['email' => $user->email]);

    // Nothing was ever created for this email — verifying any code fails.
    test()->withSession(['staff_pw_reset_email' => $user->email])
        ->post(route('password.otp.verify'), ['otp' => '000000'])
        ->assertSessionHasErrors('otp');
});

// 22 — successful staff reset clears must_change_password ───────────────────
it('22: a successful staff reset clears must_change_password', function () {
    $user = phase2Dispatcher(['email' => 'phase2-mustchange@example.com', 'must_change_password' => true]);
    $session = phase2VerifiedResetSession($user);

    $session->post(route('password.store'), [
        'password' => 'BrandNewCleared123!',
        'password_confirmation' => 'BrandNewCleared123!',
    ])->assertRedirect(route('password.reset.success'));

    expect($user->fresh()->must_change_password)->toBeFalse();
});

// 23 — old staff password reuse rejected ────────────────────────────────────
it('23: reusing the current password on staff reset is rejected', function () {
    $user = phase2Dispatcher(['email' => 'phase2-reuse@example.com', 'password' => Hash::make('SamePassword123!')]);
    $session = phase2VerifiedResetSession($user);

    $session->post(route('password.store'), [
        'password' => 'SamePassword123!',
        'password_confirmation' => 'SamePassword123!',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('SamePassword123!', $user->fresh()->password))->toBeTrue();
});

// Web-session invalidation (section 11) ──────────────────────────────────────
it('other database-backed web sessions for the account are invalidated after a staff reset', function () {
    config(['session.driver' => 'database']);

    $user = phase2Dispatcher(['email' => 'phase2-sessions@example.com']);

    // Simulate an already-authenticated browser session for this account
    // (e.g. a forgotten or hijacked session) sitting in the sessions table.
    DB::table('sessions')->insert([
        'id' => 'other-session-id-' . uniqid(),
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->timestamp,
    ]);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

    $session = phase2VerifiedResetSession($user);
    $session->post(route('password.store'), [
        'password' => 'SessionsInvalidated123!',
        'password_confirmation' => 'SessionsInvalidated123!',
    ])->assertRedirect(route('password.reset.success'));

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

function phase2VerifiedResetSession(User $user)
{
    $withSession = test()->withSession([
        'staff_pw_reset_verified_email' => $user->email,
        'staff_pw_reset_verified_until' => now()->addMinutes(10),
    ]);
    $withSession->get(route('password.reset'))->assertOk();

    return $withSession;
}
