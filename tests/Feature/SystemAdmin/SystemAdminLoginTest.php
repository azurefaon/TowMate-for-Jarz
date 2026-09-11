<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function loginPinRole(int $id, string $name): Role
{
    if ($existing = Role::find($id)) {
        return $existing;
    }

    $role = tap(new Role(['name' => $name]), function ($role) use ($id) {
        $role->id = $id;
        $role->save();
    });

    if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql') {
        \Illuminate\Support\Facades\DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
    }

    return $role;
}

function loginSystemAdmin(array $attrs = []): User
{
    loginPinRole(6, 'System Admin');

    return User::factory()->create(array_merge([
        'role_id' => 6,
        'status' => 'active',
        'must_change_password' => false,
        'password' => Hash::make('CorrectPass123!'),
    ], $attrs));
}

it('renders the system admin login screen', function () {
    $this->get('/system-admin/login')
        ->assertOk()
        ->assertSee('value="systemadmin"', false);
});

it('lets a system admin log in and redirects to the system admin dashboard', function () {
    $admin = loginSystemAdmin();

    $response = $this->post('/login', [
        'role' => 'systemadmin',
        'email' => $admin->email,
        'password' => 'CorrectPass123!',
    ]);

    $this->assertAuthenticated('web');
    $this->assertAuthenticated('systemadmin');
    $response->assertRedirect(route('system-admin.dashboard', absolute: false));
});

it('rejects an inactive system admin account', function () {
    $admin = loginSystemAdmin(['status' => 'inactive']);

    $response = $this->post('/login', [
        'role' => 'systemadmin',
        'email' => $admin->email,
        'password' => 'CorrectPass123!',
    ]);

    $response->assertSessionHasErrorsIn('login', ['auth']);
    $this->assertGuest('systemadmin');
});

it('rejects an archived system admin account', function () {
    $admin = loginSystemAdmin(['archived_at' => now(), 'archived_reason' => 'test']);

    $response = $this->post('/login', [
        'role' => 'systemadmin',
        'email' => $admin->email,
        'password' => 'CorrectPass123!',
    ]);

    $response->assertSessionHasErrorsIn('login', ['auth']);
    $this->assertGuest('systemadmin');
});

it('still forces a password change for a system admin flagged must_change_password', function () {
    $admin = loginSystemAdmin(['must_change_password' => true]);

    $this->post('/login', [
        'role' => 'systemadmin',
        'email' => $admin->email,
        'password' => 'CorrectPass123!',
    ])->assertRedirect(route('system-admin.dashboard', absolute: false));

    $this->actingAs($admin)
        ->get(route('system-admin.dashboard'))
        ->assertRedirect(route('password.force-change'));
});

it('applies the 3-attempt temporary lock to a system admin account', function () {
    $admin = loginSystemAdmin();

    foreach (range(1, 2) as $attempt) {
        sleep(11);
        $this->post('/login', [
            'role' => 'systemadmin',
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrorsIn('login', ['auth']);
    }

    $admin->refresh();
    expect((int) $admin->failed_login_attempts)->toBe(2);
    expect($admin->locked_until)->toBeNull();

    sleep(11);

    $lockingResponse = $this->post('/login', [
        'role' => 'systemadmin',
        'email' => $admin->email,
        'password' => 'wrong-password',
    ]);

    $lockingResponse->assertSessionHas('login_locked', true);

    $admin->refresh();
    expect((int) $admin->failed_login_attempts)->toBe(3);
    expect($admin->locked_until)->not->toBeNull();

    sleep(11);

    $this->post('/login', [
        'role' => 'systemadmin',
        'email' => $admin->email,
        'password' => 'CorrectPass123!',
    ])->assertSessionHasErrorsIn('login', ['auth']);

    $this->assertGuest('systemadmin');
});

it('lets a locked system admin recover via OTP without bypassing the password', function () {
    $admin = loginSystemAdmin();
    $admin->forceFill(['failed_login_attempts' => 3, 'locked_until' => now()->addMinutes(15)])->save();

    \Illuminate\Support\Facades\Mail::fake();
    $this->post(route('login.recover.send'), ['email' => $admin->email]);

    $otp = null;
    \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\LoginUnlockOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    $this->withSession(['login_recover_email' => $admin->email])
        ->post(route('login.recover.verify.submit'), ['otp' => $otp])
        ->assertRedirect(route('login.recover.success'));

    $admin->refresh();
    expect($admin->locked_until)->toBeNull();
    $this->assertGuest('systemadmin');
    $this->assertGuest('web');

    $this->post('/login', [
        'role' => 'systemadmin',
        'email' => $admin->email,
        'password' => 'CorrectPass123!',
    ])->assertRedirect(route('system-admin.dashboard', absolute: false));

    $this->assertAuthenticated('systemadmin');
});

it('keeps the existing owner login redirect unchanged', function () {
    loginPinRole(1, 'Owner');

    $owner = User::factory()->create([
        'role_id' => 1,
        'status' => 'active',
        'must_change_password' => false,
        'password' => Hash::make('CorrectPass123!'),
    ]);

    $this->post('/login', [
        'role' => 'superadmin',
        'email' => $owner->email,
        'password' => 'CorrectPass123!',
    ])->assertRedirect(route('superadmin.dashboard', absolute: false));
});

it('keeps the existing dispatcher login redirect unchanged', function () {
    loginPinRole(2, 'Dispatcher');

    $dispatcher = User::factory()->create([
        'role_id' => 2,
        'status' => 'active',
        'must_change_password' => false,
        'password' => Hash::make('CorrectPass123!'),
    ]);

    $this->post('/login', [
        'role' => 'dispatcher',
        'email' => $dispatcher->email,
        'password' => 'CorrectPass123!',
    ])->assertRedirect(route('admin.dashboard', absolute: false));
});
