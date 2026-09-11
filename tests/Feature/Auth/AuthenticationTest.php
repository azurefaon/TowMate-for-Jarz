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
    putenv('SUPERADMIN_PASSWORD=SeededSuperAdminPass123!');
    $this->seed(SuperAdminSeeder::class);
    putenv('SUPERADMIN_PASSWORD');

    $response = $this->post('/login', [
        'role' => 'superadmin',
        'login_method' => 'password',
        'email' => 'SuperAdmin@Gmail.com',
        'password' => 'SeededSuperAdminPass123!',
    ]);

    $this->assertAuthenticated('web');
    $this->assertAuthenticated('superadmin');
    $response->assertRedirect(route('superadmin.dashboard', absolute: false));
});

test('system admin add user page does not present driver or customer roles', function () {
    ensureAuthRole(6, 'System Admin');

    $admin = User::factory()->create([
        'role_id' => 6,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->get(route('system-admin.users.create'))
        ->assertOk()
        ->assertDontSee('>Driver<', false)
        ->assertDontSee('>Customer<', false);
});

test('system admin can create a managed user from the add user form', function () {
    ensureAuthRole(6, 'System Admin');
    ensureAuthRole(2, 'Admin');

    $admin = User::factory()->create([
        'role_id' => 6,
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)->post(route('system-admin.users.store'), [
        'first_name' => 'Taylor',
        'middle_name' => 'Anne',
        'last_name' => 'Jones',
        'email' => 'taylor.jones@example.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role_id' => 2,
        'status' => 'active',
    ]);

    $response->assertRedirect(route('system-admin.users.index'));

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

test('a third consecutive failed login attempt locks the account without hitting the raw rate-limit lockout', function () {
    ensureAuthRole(3, 'Team Leader');

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'status' => 'active',
    ]);

    foreach (range(1, 2) as $attempt) {
        // The existing TokenBucketRateLimiter uses real wall-clock time
        // (microtime()), not Carbon — a real sleep is required between
        // attempts so this test exercises the new account-level lock
        // instead of tripping the unrelated burst limiter.
        sleep(11);

        $response = $this->from('/teamleader/login')->post('/login', [
            'role' => 'teamleader',
            'login_method' => 'password',
            'email' => $teamLeader->email,
            'password' => 'wrong-password',
        ]);

        expect($response->getStatusCode())->not->toBe(429);
        $response->assertSessionHasErrorsIn('login', ['auth']);
    }

    $teamLeader->refresh();
    expect((int) $teamLeader->failed_login_attempts)->toBe(2);
    expect($teamLeader->locked_until)->toBeNull();

    sleep(11);

    $lockingResponse = $this->from('/teamleader/login')->post('/login', [
        'role' => 'teamleader',
        'login_method' => 'password',
        'email' => $teamLeader->email,
        'password' => 'wrong-password',
    ]);

    $lockingResponse->assertSessionHasErrorsIn('login', ['auth']);
    $lockingResponse->assertSessionHas('login_locked', true);

    $teamLeader->refresh();
    expect((int) $teamLeader->failed_login_attempts)->toBe(3);
    expect($teamLeader->locked_until)->not->toBeNull();

    sleep(11);

    $response = $this->from('/teamleader/login')->post('/login', [
        'role' => 'teamleader',
        'login_method' => 'password',
        'email' => $teamLeader->email,
        'password' => 'password',
    ]);

    $this->assertGuest('teamleader');
    $response->assertSessionHasErrorsIn('login', ['auth']);
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
