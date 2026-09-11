<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

function profilePinRole(int $id, string $name): Role
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

function profileAdmin(array $attrs = []): User
{
    profilePinRole(6, 'System Admin');

    return User::factory()->create(array_merge([
        'role_id' => 6,
        'status' => 'active',
        'must_change_password' => false,
    ], $attrs));
}

it('lets a system admin view their own profile', function () {
    $admin = profileAdmin(['first_name' => 'Sam', 'last_name' => 'Admin']);

    $this->actingAs($admin)
        ->get(route('system-admin.profile.edit'))
        ->assertOk()
        ->assertSee('Sam')
        ->assertSee($admin->email);
});

it('updates the allowed name fields', function () {
    $admin = profileAdmin();

    $response = $this->actingAs($admin)->patch(route('system-admin.profile.update'), [
        'first_name' => 'Updated',
        'middle_name' => 'M',
        'last_name' => 'Name',
    ]);

    $response->assertRedirect(route('system-admin.profile.edit'));

    $admin->refresh();
    expect($admin->first_name)->toBe('Updated');
    expect($admin->middle_name)->toBe('M');
    expect($admin->last_name)->toBe('Name');
});

it('uploads a valid profile image', function () {
    Storage::fake('public');
    $admin = profileAdmin();

    $response = $this->actingAs($admin)->patch(route('system-admin.profile.update'), [
        'first_name' => $admin->first_name ?: 'Sam',
        'last_name' => $admin->last_name ?: 'Admin',
        'profile_image' => UploadedFile::fake()->image('avatar.jpg', 200, 200)->size(500),
    ]);

    $response->assertRedirect(route('system-admin.profile.edit'));

    $admin->refresh();
    expect($admin->profile_image)->not->toBeNull();
    Storage::disk('public')->assertExists($admin->profile_image);
});

it('rejects an invalid non image file as profile image', function () {
    Storage::fake('public');
    $admin = profileAdmin();

    $response = $this->actingAs($admin)->patch(route('system-admin.profile.update'), [
        'first_name' => $admin->first_name ?: 'Sam',
        'last_name' => $admin->last_name ?: 'Admin',
        'profile_image' => UploadedFile::fake()->create('malicious.php', 10, 'application/x-php'),
    ]);

    $response->assertSessionHasErrors('profile_image');
});

it('does not allow the email to be changed via the profile update endpoint', function () {
    $admin = profileAdmin(['email' => 'original@towmate.local']);

    $this->actingAs($admin)->patch(route('system-admin.profile.update'), [
        'first_name' => $admin->first_name ?: 'Sam',
        'last_name' => $admin->last_name ?: 'Admin',
        'email' => 'hijacked@evil.com',
    ]);

    $admin->refresh();
    expect($admin->email)->toBe('original@towmate.local');
});

it('does not allow role_id status or lock fields to be changed via the profile update endpoint', function () {
    profilePinRole(2, 'Dispatcher');
    $admin = profileAdmin();

    $this->actingAs($admin)->patch(route('system-admin.profile.update'), [
        'first_name' => $admin->first_name ?: 'Sam',
        'last_name' => $admin->last_name ?: 'Admin',
        'role_id' => 2,
        'status' => 'inactive',
        'archived_at' => now()->toDateTimeString(),
        'locked_until' => now()->addDay()->toDateTimeString(),
        'password' => 'ShouldNotApply123!',
    ]);

    $admin->refresh();
    expect((int) $admin->role_id)->toBe(6);
    expect($admin->status)->toBe('active');
    expect($admin->archived_at)->toBeNull();
    expect($admin->locked_until)->toBeNull();
});

it('cannot modify another users profile through the self profile endpoint', function () {
    $admin = profileAdmin();
    $other = profileAdmin(['first_name' => 'Other', 'last_name' => 'Admin']);

    $this->actingAs($admin)->patch(route('system-admin.profile.update'), [
        'first_name' => 'Hijacked',
        'last_name' => 'Name',
    ]);

    $other->refresh();
    expect($other->first_name)->toBe('Other');
});

it('logs a profile_updated audit entry without sensitive values', function () {
    $admin = profileAdmin();

    $this->actingAs($admin)->patch(route('system-admin.profile.update'), [
        'first_name' => 'Changed',
        'last_name' => 'Name',
    ]);

    $log = AuditLog::where('action', 'profile_updated')->where('user_id', $admin->id)->first();
    expect($log)->not->toBeNull();
    expect($log->description)->not->toContain('password');
});

it('requires the correct current password to change password', function () {
    $admin = profileAdmin(['password' => Hash::make('CorrectPass123!@#')]);

    $response = $this->actingAs($admin)->put(route('system-admin.profile.password.update'), [
        'current_password' => 'WrongPassword123!',
        'password' => 'BrandNewPass123!@#',
        'password_confirmation' => 'BrandNewPass123!@#',
    ]);

    $response->assertSessionHasErrors('current_password');
});

it('requires password confirmation to match', function () {
    $admin = profileAdmin(['password' => Hash::make('CorrectPass123!@#')]);

    $response = $this->actingAs($admin)->put(route('system-admin.profile.password.update'), [
        'current_password' => 'CorrectPass123!@#',
        'password' => 'BrandNewPass123!@#',
        'password_confirmation' => 'Mismatch123!@#',
    ]);

    $response->assertSessionHasErrors('password');
});

it('enforces the existing password policy on the new password', function () {
    $admin = profileAdmin(['password' => Hash::make('CorrectPass123!@#')]);

    $response = $this->actingAs($admin)->put(route('system-admin.profile.password.update'), [
        'current_password' => 'CorrectPass123!@#',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertSessionHasErrors('password');
});

it('updates the password and stores it hashed', function () {
    $admin = profileAdmin(['password' => Hash::make('CorrectPass123!@#')]);

    $response = $this->actingAs($admin)->put(route('system-admin.profile.password.update'), [
        'current_password' => 'CorrectPass123!@#',
        'password' => 'BrandNewPass123!@#',
        'password_confirmation' => 'BrandNewPass123!@#',
    ]);

    $response->assertRedirect(route('system-admin.profile.edit'));

    $admin->refresh();
    expect(Hash::check('BrandNewPass123!@#', $admin->password))->toBeTrue();
    expect($admin->password)->not->toBe('BrandNewPass123!@#');
});

it('does not write the plaintext password into the audit log', function () {
    $admin = profileAdmin(['password' => Hash::make('CorrectPass123!@#')]);

    $this->actingAs($admin)->put(route('system-admin.profile.password.update'), [
        'current_password' => 'CorrectPass123!@#',
        'password' => 'BrandNewPass123!@#',
        'password_confirmation' => 'BrandNewPass123!@#',
    ]);

    $log = AuditLog::where('action', 'password_changed')->where('user_id', $admin->id)->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->description)->not->toContain('BrandNewPass123!@#');
    expect(json_encode($log->new_value))->not->toContain('BrandNewPass123!@#');
});

it('shows the profile menu and no permanent sidebar logout', function () {
    $admin = profileAdmin();

    $response = $this->actingAs($admin)->get(route('system-admin.dashboard'));

    $response->assertOk()
        ->assertSee('id="saAccountTrigger"', false)
        ->assertSee('Profile Settings')
        ->assertSee('Change Password')
        ->assertDontSee('sa-sidebar-footer', false)
        ->assertDontSee('sa-logout-btn', false);
});

it('displays the authenticated name without a redundant system admin subtitle in the account trigger', function () {
    $admin = profileAdmin(['first_name' => 'Sam', 'last_name' => 'Admin']);

    $response = $this->actingAs($admin)->get(route('system-admin.dashboard'));

    $response->assertOk();
    $html = $response->getContent();

    $triggerStart = strpos($html, 'id="saAccountTrigger"');
    $triggerEnd = strpos($html, '</button>', $triggerStart);
    $triggerHtml = substr($html, $triggerStart, $triggerEnd - $triggerStart);

    expect($triggerHtml)->toContain($admin->full_name);
    expect($triggerHtml)->not->toContain('>System Admin<');
});

it('forbids an owner from accessing the system admin profile routes', function () {
    profilePinRole(1, 'Owner');
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);

    $this->actingAs($owner)->get(route('system-admin.profile.edit'))->assertForbidden();
    $this->actingAs($owner)->get(route('system-admin.profile.password.edit'))->assertForbidden();
});

it('forbids a dispatcher from accessing the system admin profile routes', function () {
    profilePinRole(2, 'Dispatcher');
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);

    $this->actingAs($dispatcher)->get(route('system-admin.profile.edit'))->assertForbidden();
});

it('forbids a team leader from accessing the system admin profile routes', function () {
    profilePinRole(3, 'Team Leader');
    $tl = User::factory()->create(['role_id' => 3, 'status' => 'active', 'must_change_password' => false]);

    $this->actingAs($tl)->get(route('system-admin.profile.edit'))->assertForbidden();
});
