<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;

function ensureAuthRole(int $id, string $name): void
{
    if (Role::find($id)) {
        return;
    }

    $role = new Role(['name' => $name]);
    $role->id = $id;
    $role->save();
}

test('super admin login screen can be rendered', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Jarz Towing')
        ->assertSee('Welcome back')
        ->assertSee('value="superadmin"', false);
});

test('dispatcher and team leader login screens can be rendered', function () {
    $this->get('/dispatcher/login')
        ->assertOk()
        ->assertSee('Jarz Towing')
        ->assertSee('value="dispatcher"', false);

    $this->get('/teamleader/login')
        ->assertOk()
        ->assertSee('Jarz Towing')
        ->assertSee('value="teamleader"', false);
});

test('seeded super admin can authenticate through the super admin flow', function () {
    ensureAuthRole(1, 'Super Admin');
    $this->seed(SuperAdminSeeder::class);

    $response = $this->post('/login', [
        'role' => 'superadmin',
        'login_method' => 'password',
        'email' => 'SuperAdmin@Gmail.com',
        'password' => 'admin123456',
    ]);

    $this->assertAuthenticated('web');
    $this->assertAuthenticated('superadmin');
    $response->assertRedirect(route('superadmin.dashboard', absolute: false));
});

test('super admin manage user page hides validation until submit', function () {
    ensureAuthRole(1, 'Super Admin');

    $admin = User::factory()->create([
        'role_id' => 1,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->get(route('superadmin.users.create'))
        ->assertOk()
        ->assertSee('Optional')
        ->assertSee('Password Requirements')
        ->assertDontSee('Email must be valid')
        ->assertDontSee('Password must be at least 12 characters')
        ->assertDontSee('>Driver<', false)
        ->assertDontSee('>Customer<', false);
});

test('super admin can create a managed user from the add user form', function () {
    ensureAuthRole(1, 'Super Admin');
    ensureAuthRole(2, 'Admin');

    $admin = User::factory()->create([
        'role_id' => 1,
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)->post(route('superadmin.users.store'), [
        'first_name' => 'Taylor',
        'middle_name' => 'Anne',
        'last_name' => 'Jones',
        'email' => 'taylor.jones@example.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role_id' => 2,
        'status' => 'active',
    ]);

    $response->assertRedirect(route('superadmin.users.index'));

    $this->assertDatabaseHas('users', [
        'email' => 'taylor.jones@example.com',
        'first_name' => 'Taylor',
        'middle_name' => 'Anne',
        'last_name' => 'Jones',
        'role_id' => 2,
        'status' => 'active',
    ]);
});

test('dispatchers are redirected only to the dispatcher dashboard after login', function () {
    ensureAuthRole(2, 'Dispatcher');

    $dispatcher = User::factory()->create([
        'role_id' => 2,
        'status' => 'active',
    ]);

    $response = $this->post('/login', [
        'role' => 'dispatcher',
        'login_method' => 'password',
        'email' => $dispatcher->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated('dispatcher');
    $response->assertRedirect(route('admin.dashboard', absolute: false));
});

test('cross role login is rejected with a generic invalid credentials message', function () {
    ensureAuthRole(2, 'Dispatcher');
    ensureAuthRole(3, 'Team Leader');

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'status' => 'active',
    ]);

    $this->from('/login')->post('/login', [
        'role' => 'dispatcher',
        'login_method' => 'password',
        'email' => $teamLeader->email,
        'password' => 'password',
    ])
        ->assertRedirect('/login')
        ->assertSessionHasErrorsIn('login', ['auth']);

    $this->assertGuest('dispatcher');
});

test('team leader can authenticate through the team leader login page', function () {
    ensureAuthRole(3, 'Team Leader');

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'status' => 'active',
    ]);

    $response = $this->post('/login', [
        'role' => 'teamleader',
        'login_method' => 'password',
        'email' => $teamLeader->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated('teamleader');
    $response->assertRedirect(route('teamleader.dashboard', absolute: false));
});

test('users can logout securely from all sessions', function () {
    ensureAuthRole(2, 'Dispatcher');

    $user = User::factory()->create([
        'role_id' => 2,
        'status' => 'active',
    ]);

    $this->actingAs($user, 'web');
    auth()->guard('dispatcher')->login($user);

    $response = $this->post('/logout');

    $this->assertGuest('web');
    $this->assertGuest('dispatcher');
    $response->assertRedirect(url('/login'));
});
