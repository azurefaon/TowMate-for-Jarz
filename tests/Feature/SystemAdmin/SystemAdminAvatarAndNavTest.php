<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function saAvatarRole(int $id, string $name): void
{
    if (Role::find($id)) {
        return;
    }

    $role = new Role(['name' => $name]);
    $role->id = $id;
    $role->save();

    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
    }
}

function saAvatarAdmin(array $attrs = []): User
{
    saAvatarRole(1, 'Owner');
    saAvatarRole(5, 'Customer');
    saAvatarRole(6, 'System Admin');

    return User::factory()->create($attrs + ['role_id' => 6, 'status' => 'active', 'must_change_password' => false]);
}

function saAvatarPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

beforeEach(function () {
    Storage::fake('profile_images');
    Storage::fake('public');
});

it('no longer lists System Maintenance in the sidebar but keeps the page reachable for System Admins', function () {
    $admin = saAvatarAdmin();

    $this->actingAs($admin)->get(route('system-admin.dashboard'))
        ->assertOk()
        ->assertDontSee('System Maintenance')
        ->assertDontSee(route('system-admin.maintenance.index'), false);

    $this->actingAs($admin)->get(route('system-admin.maintenance.index'))->assertOk();
});

it('keeps the maintenance page forbidden for other roles', function () {
    saAvatarAdmin();
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);

    $this->actingAs($owner)->get(route('system-admin.maintenance.index'))->assertForbidden();
});

it('serves a photo stored on the private profile_images disk (mobile/Google uploads)', function () {
    $admin = saAvatarAdmin();
    $customer = User::factory()->create(['role_id' => 5, 'profile_image' => '77/photo.png']);
    Storage::disk('profile_images')->put('77/photo.png', saAvatarPng());

    $this->actingAs($admin)->get(route('system-admin.users.avatar', $customer))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('serves a legacy photo stored on the public disk', function () {
    $user = saAvatarAdmin(['profile_image' => 'avatars/legacy.png']);
    Storage::disk('public')->put('avatars/legacy.png', saAvatarPng());

    $this->actingAs($user)->get(route('system-admin.users.avatar', $user))->assertOk();
});

it('returns 404 when the stored file is missing and never reveals the path', function () {
    $admin = saAvatarAdmin();
    $customer = User::factory()->create(['role_id' => 5, 'profile_image' => 'profile-images/42/gone.jpg']);

    $this->actingAs($admin)->get(route('system-admin.users.avatar', $customer))->assertNotFound();
});

it('does not serve avatars to guests or to non System Admins', function () {
    $admin = saAvatarAdmin();
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
    $customer = User::factory()->create(['role_id' => 5, 'profile_image' => '78/p.png']);
    Storage::disk('profile_images')->put('78/p.png', saAvatarPng());

    $this->get(route('system-admin.users.avatar', $customer))->assertRedirect();
    $this->actingAs($owner)->get(route('system-admin.users.avatar', $customer))->assertForbidden();
});

it('shows working same-origin photos in the user list and a default avatar when the file is missing', function () {
    $admin = saAvatarAdmin();
    $with = User::factory()->create(['role_id' => 5, 'profile_image' => '79/p.png', 'first_name' => 'Withphoto']);
    $missing = User::factory()->create(['role_id' => 5, 'profile_image' => 'profile-images/9/gone.jpg', 'first_name' => 'Nophoto']);
    Storage::disk('profile_images')->put('79/p.png', saAvatarPng());

    $html = $this->actingAs($admin)->get(route('system-admin.users.index'))->assertOk()->getContent();

    expect($html)->toContain('/system-admin/users/' . $with->id . '/avatar')
        ->and($html)->not->toContain('/system-admin/users/' . $missing->id . '/avatar')
        ->and($html)->not->toContain('/storage/79/p.png')
        ->and($html)->not->toContain('gone.jpg');
});

it('stores a System Admin upload on the persistent private disk and renders it after refresh', function () {
    $admin = saAvatarAdmin();

    $this->actingAs($admin)->patch(route('system-admin.profile.update'), [
        'first_name' => 'Ada',
        'last_name' => 'Admin',
        'profile_image' => UploadedFile::fake()->image('me.png', 120, 120),
    ])->assertRedirect(route('system-admin.profile.edit'));

    $admin->refresh();
    expect($admin->profile_image)->not->toBeNull();
    Storage::disk('profile_images')->assertExists($admin->profile_image);

    $url = '/system-admin/users/' . $admin->id . '/avatar';
    $this->actingAs($admin)->get(route('system-admin.profile.edit'))->assertSee($url, false);
    $this->actingAs($admin)->get(route('system-admin.dashboard'))->assertSee($url, false);
    $this->actingAs($admin)->get($url)->assertOk();
});

it('replaces and removes the old photo from whichever disk held it, and still validates the image', function () {
    $admin = saAvatarAdmin(['profile_image' => 'avatars/old.png']);
    Storage::disk('public')->put('avatars/old.png', saAvatarPng());

    $this->actingAs($admin)->patch(route('system-admin.profile.update'), [
        'first_name' => 'Ada', 'last_name' => 'Admin',
        'profile_image' => UploadedFile::fake()->image('new.png', 100, 100),
    ])->assertRedirect();

    Storage::disk('public')->assertMissing('avatars/old.png');

    $this->actingAs($admin->refresh())->patch(route('system-admin.profile.update'), [
        'first_name' => 'Ada', 'last_name' => 'Admin',
        'profile_image' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php'),
    ])->assertSessionHasErrors('profile_image');
});
