<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemAdminSeeder;
use Illuminate\Support\Facades\Hash;

function fpcPinRole(int $id, string $name): Role
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

function fpcSystemAdmin(array $attrs = []): User
{
    fpcPinRole(6, 'System Admin');

    return User::factory()->create(array_merge([
        'role_id' => 6,
        'status' => 'active',
        'must_change_password' => true,
        'password' => Hash::make('TempPass123!@#'),
    ], $attrs));
}

it('provisions a newly seeded system admin with must_change_password true', function () {
    fpcPinRole(6, 'System Admin');

    config(['app.env' => 'testing']);
    putenv('SYSTEM_ADMIN_EMAIL=fresh-admin@example.com');
    putenv('SYSTEM_ADMIN_PASSWORD=');

    $this->seed(SystemAdminSeeder::class);

    $admin = User::where('email', 'fresh-admin@example.com')->first();

    expect($admin)->not->toBeNull();
    expect((int) $admin->role_id)->toBe(6);
    expect($admin->status)->toBe('active');
    expect($admin->archived_at)->toBeNull();
    expect($admin->must_change_password)->toBeTrue();

    putenv('SYSTEM_ADMIN_EMAIL');
    putenv('SYSTEM_ADMIN_PASSWORD');
});

it('authenticates with correct temporary credentials but redirects to forced password change', function () {
    $admin = fpcSystemAdmin();

    $this->post('/login', [
        'role' => 'systemadmin',
        'email' => $admin->email,
        'password' => 'TempPass123!@#',
    ])->assertRedirect(route('system-admin.dashboard', absolute: false));

    $this->assertAuthenticatedAs($admin, 'web');

    $this->get(route('system-admin.dashboard'))
        ->assertRedirect(route('password.force-change'));
});

it('blocks direct access to the dashboard while must_change_password is true', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)
        ->get(route('system-admin.dashboard'))
        ->assertRedirect(route('password.force-change'));
});

it('blocks direct access to users while must_change_password is true', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)
        ->get(route('system-admin.users.index'))
        ->assertRedirect(route('password.force-change'));
});

it('blocks direct access to security monitor while must_change_password is true', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)
        ->get(route('system-admin.security.monitor'))
        ->assertRedirect(route('password.force-change'));
});

it('blocks direct access to audit logs while must_change_password is true', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)
        ->get(route('system-admin.audit-logs.index'))
        ->assertRedirect(route('password.force-change'));
});

it('blocks direct access to system settings while must_change_password is true', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)
        ->get(route('system-admin.settings.index'))
        ->assertRedirect(route('password.force-change'));
});

it('blocks direct access to system maintenance while must_change_password is true', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)
        ->get(route('system-admin.maintenance.index'))
        ->assertRedirect(route('password.force-change'));
});

it('blocks direct access to profile settings while must_change_password is true', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)
        ->get(route('system-admin.profile.edit'))
        ->assertRedirect(route('password.force-change'));
});

it('keeps the forced password change page itself accessible', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)
        ->get(route('password.force-change'))
        ->assertOk();
});

it('keeps logout accessible during a forced password change', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)
        ->post(route('logout'))
        ->assertRedirect();

    $this->assertGuest();
});

it('rejects a weak new password', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)->post(route('password.force-change'), [
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $admin->refresh();
    expect($admin->must_change_password)->toBeTrue();
});

it('rejects a new password equal to the temporary password', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)->post(route('password.force-change'), [
        'password' => 'TempPass123!@#',
        'password_confirmation' => 'TempPass123!@#',
    ])->assertSessionHasErrors('password');

    $admin->refresh();
    expect($admin->must_change_password)->toBeTrue();
});

it('rejects a confirmation mismatch', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)->post(route('password.force-change'), [
        'password' => 'BrandNewPass123!@#',
        'password_confirmation' => 'DoesNotMatch123!@#',
    ])->assertSessionHasErrors('password');

    $admin->refresh();
    expect($admin->must_change_password)->toBeTrue();
});

it('stores the new password hashed and clears must_change_password only on success', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)->post(route('password.force-change'), [
        'password' => 'BrandNewPass123!@#',
        'password_confirmation' => 'BrandNewPass123!@#',
    ])->assertRedirect(route('system-admin.dashboard', absolute: false));

    $admin->refresh();
    expect($admin->must_change_password)->toBeFalse();
    expect(Hash::check('BrandNewPass123!@#', $admin->password))->toBeTrue();
    expect($admin->password)->not->toBe('BrandNewPass123!@#');
});

it('redirects to system admin dashboard after a successful forced password change', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)->post(route('password.force-change'), [
        'password' => 'BrandNewPass123!@#',
        'password_confirmation' => 'BrandNewPass123!@#',
    ])->assertRedirect(route('system-admin.dashboard', absolute: false));
});

it('allows normal system admin route access after the forced change completes', function () {
    $admin = fpcSystemAdmin();

    $this->actingAs($admin)->post(route('password.force-change'), [
        'password' => 'BrandNewPass123!@#',
        'password_confirmation' => 'BrandNewPass123!@#',
    ]);

    $admin->refresh();

    $this->actingAs($admin)->get(route('system-admin.dashboard'))->assertOk();
    $this->actingAs($admin)->get(route('system-admin.users.index'))->assertOk();
    $this->actingAs($admin)->get(route('system-admin.profile.edit'))->assertOk();
});

it('rerunning the seeder does not overwrite an already established system admin password', function () {
    fpcPinRole(6, 'System Admin');

    putenv('SYSTEM_ADMIN_EMAIL=idempotent-admin@example.com');
    putenv('SYSTEM_ADMIN_PASSWORD=');
    $this->seed(SystemAdminSeeder::class);

    $admin = User::where('email', 'idempotent-admin@example.com')->first();
    $admin->forceFill([
        'password' => Hash::make('MyOwnRealPassword123!@#'),
        'must_change_password' => false,
    ])->save();

    putenv('SYSTEM_ADMIN_PASSWORD=SomeDifferentSeededPass123!@#');
    $this->seed(SystemAdminSeeder::class);

    $admin->refresh();
    expect(Hash::check('MyOwnRealPassword123!@#', $admin->password))->toBeTrue();

    putenv('SYSTEM_ADMIN_EMAIL');
    putenv('SYSTEM_ADMIN_PASSWORD');
});

it('rerunning the seeder does not set must_change_password back to true', function () {
    fpcPinRole(6, 'System Admin');

    putenv('SYSTEM_ADMIN_EMAIL=idempotent-flag@example.com');
    putenv('SYSTEM_ADMIN_PASSWORD=');
    $this->seed(SystemAdminSeeder::class);

    $admin = User::where('email', 'idempotent-flag@example.com')->first();
    $admin->forceFill(['must_change_password' => false])->save();

    $this->seed(SystemAdminSeeder::class);

    $admin->refresh();
    expect($admin->must_change_password)->toBeFalse();

    putenv('SYSTEM_ADMIN_EMAIL');
    putenv('SYSTEM_ADMIN_PASSWORD');
});

it('rerunning the seeder does not overwrite an established system admin name', function () {
    fpcPinRole(6, 'System Admin');

    putenv('SYSTEM_ADMIN_EMAIL=idempotent-name@example.com');
    putenv('SYSTEM_ADMIN_NAME=Original Name');
    putenv('SYSTEM_ADMIN_PASSWORD=');
    $this->seed(SystemAdminSeeder::class);

    $admin = User::where('email', 'idempotent-name@example.com')->first();
    $admin->forceFill(['name' => 'Renamed By Admin'])->save();

    putenv('SYSTEM_ADMIN_NAME=Different Seeded Name');
    $this->seed(SystemAdminSeeder::class);

    $admin->refresh();
    expect($admin->name)->toBe('Renamed By Admin');

    putenv('SYSTEM_ADMIN_EMAIL');
    putenv('SYSTEM_ADMIN_NAME');
    putenv('SYSTEM_ADMIN_PASSWORD');
});

it('keeps the owner login redirect unchanged', function () {
    fpcPinRole(1, 'Owner');

    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false, 'password' => Hash::make('CorrectPass123!')]);
    $this->post('/login', ['role' => 'superadmin', 'email' => $owner->email, 'password' => 'CorrectPass123!'])
        ->assertRedirect(route('superadmin.dashboard', absolute: false));
});

it('keeps the dispatcher login redirect unchanged', function () {
    fpcPinRole(2, 'Dispatcher');

    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false, 'password' => Hash::make('CorrectPass123!')]);
    $this->post('/login', ['role' => 'dispatcher', 'email' => $dispatcher->email, 'password' => 'CorrectPass123!'])
        ->assertRedirect(route('admin.dashboard', absolute: false));
});

it('keeps a dispatcher with must_change_password redirected to the dispatcher dashboard after forced change', function () {
    fpcPinRole(2, 'Dispatcher');

    $dispatcher = User::factory()->create([
        'role_id' => 2,
        'status' => 'active',
        'must_change_password' => true,
        'password' => Hash::make('TempPass123!@#'),
    ]);

    $this->actingAs($dispatcher)->post(route('password.force-change'), [
        'password' => 'BrandNewPass123!@#',
        'password_confirmation' => 'BrandNewPass123!@#',
    ])->assertRedirect(route('admin.dashboard', absolute: false));
});

it('lets a system admin who has already changed their password use normal staff password recovery', function () {
    $admin = fpcSystemAdmin(['must_change_password' => false, 'email' => 'recoverable-admin@example.com']);

    \Illuminate\Support\Facades\Mail::fake();
    $this->post(route('password.email'), ['email' => $admin->email])
        ->assertRedirect(route('password.otp.show'));

    \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\StaffPasswordResetOtpMail::class);
    expect(\App\Models\StaffPasswordResetOtp::where('email', $admin->email)->exists())->toBeTrue();
});
