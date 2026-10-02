<?php

use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\Role;
use App\Models\User;

function idleUser(int $roleId): User
{
    foreach ([1 => 'Owner', 2 => 'Dispatcher', 6 => 'System Admin'] as $id => $name) {
        if (! Role::find($id)) {
            tap(new Role(['name' => $name]), function ($r) use ($id) {
                $r->id = $id;
                $r->save();
            });
        }
    }

    return User::factory()->create(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false]);
}

dataset('staff roles', [
    'owner' => [1, '/superadmin/dashboard'],
    'dispatcher' => [2, '/admin-dashboard/dispatch'],
    'system admin' => [6, '/system-admin/dashboard'],
]);

it('logs out staff whose last user activity is older than the idle timeout', function (int $role, string $url) {
    $user = idleUser($role);
    $stale = now()->subSeconds(config('session.idle_timeout_seconds') + 5)->timestamp;

    $this->actingAs($user)
        ->withSession([EnforceIdleTimeout::SESSION_KEY => $stale])
        ->get($url)
        ->assertRedirect(route('login'));

    $this->assertGuest();
})->with('staff roles');

it('does not let background JSON polling renew the idle clock', function () {
    $user = idleUser(2);
    $last = now()->subMinutes(10)->timestamp;

    $this->actingAs($user)
        ->withSession([EnforceIdleTimeout::SESSION_KEY => $last])
        ->getJson('/admin-dashboard/dispatch');

    expect(session(EnforceIdleTimeout::SESSION_KEY))->toBe($last);
});

it('answers a stale JSON request with 401 and a login redirect', function () {
    $user = idleUser(2);

    $this->actingAs($user)
        ->withSession([EnforceIdleTimeout::SESSION_KEY => now()->subHour()->timestamp])
        ->getJson('/admin-dashboard/dispatch')
        ->assertStatus(401)
        ->assertJsonPath('redirect', route('login'));
});

it('renews the idle clock via the keep-alive endpoint', function () {
    $user = idleUser(2);
    $before = now()->subMinutes(20)->timestamp;

    $this->actingAs($user)
        ->withSession([EnforceIdleTimeout::SESSION_KEY => $before])
        ->postJson(route('session.keep-alive'))
        ->assertOk();

    expect(session(EnforceIdleTimeout::SESSION_KEY))->toBeGreaterThan($before);
});

it('renders the idle warning modal in the dashboard layouts', function (int $role, string $url) {
    $this->actingAs(idleUser($role))->get($url)->assertOk()->assertSee('Are you still there?');
})->with('staff roles');

it('rejects a keep-alive from an already expired session instead of reviving it', function () {
    $user = idleUser(2);
    $stale = now()->subSeconds(config('session.idle_timeout_seconds') + 5)->timestamp;

    $this->actingAs($user)
        ->withSession([EnforceIdleTimeout::SESSION_KEY => $stale])
        ->postJson(route('session.keep-alive'))
        ->assertStatus(401);

    $this->assertGuest();
});

it('lets a keep-alive just inside the timeout window save the session', function () {
    $user = idleUser(2);
    $almost = now()->subSeconds(config('session.idle_timeout_seconds') - 10)->timestamp;

    $this->actingAs($user)
        ->withSession([EnforceIdleTimeout::SESSION_KEY => $almost])
        ->postJson(route('session.keep-alive'))
        ->assertOk();

    expect(session(EnforceIdleTimeout::SESSION_KEY))->toBeGreaterThan($almost);
});

it('does not let background polling endpoints keep a user alive across the full timeout', function () {
    $user = idleUser(2);
    $start = now()->subSeconds(config('session.idle_timeout_seconds') - 60)->timestamp;

    // Polling in the final minute leaves the clock alone...
    $this->actingAs($user)
        ->withSession([EnforceIdleTimeout::SESSION_KEY => $start])
        ->getJson('/admin-dashboard/pending-bookings-count');
    expect(session(EnforceIdleTimeout::SESSION_KEY))->toBe($start);

    // ...so once the window passes, the next poll is refused.
    $this->travel(2)->minutes();
    $this->getJson('/admin-dashboard/pending-bookings-count')->assertStatus(401);
    $this->assertGuest();
});
