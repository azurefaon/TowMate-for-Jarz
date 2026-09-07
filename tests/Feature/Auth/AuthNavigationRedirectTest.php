<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

function navRole(int $id, string $name): void
{
    if (Role::find($id)) {
        return;
    }

    $role = new Role(['name' => $name]);
    $role->id = $id;
    $role->save();
}

function navOwner(): User
{
    navRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function navDispatcher(): User
{
    navRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

it('owner login with no intended url redirects immediately to the owner dashboard', function () {
    $owner = navOwner();

    $response = $this->post('/login', [
        'role' => 'superadmin',
        'login_method' => 'password',
        'email' => $owner->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('superadmin.dashboard', absolute: false));
    $this->assertFalse(session()->has('url.intended'));

    $this->get($response->headers->get('Location'))->assertOk();
});

it('owner login with a stale foreign url.intended still redirects to the owner dashboard and never 403s', function () {
    $owner = navOwner();

    $response = $this->withSession(['url.intended' => url('/admin-dashboard')])
        ->post('/login', [
            'role' => 'superadmin',
            'login_method' => 'password',
            'email' => $owner->email,
            'password' => 'password',
        ]);

    $response->assertRedirect(route('superadmin.dashboard', absolute: false));
    $this->assertFalse(session()->has('url.intended'));

    $this->get($response->headers->get('Location'))->assertOk();
});

it('dispatcher login with no intended url redirects immediately to the dispatcher dashboard', function () {
    $dispatcher = navDispatcher();

    $response = $this->post('/login', [
        'role' => 'dispatcher',
        'login_method' => 'password',
        'email' => $dispatcher->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('admin.dashboard', absolute: false));
    $this->assertFalse(session()->has('url.intended'));

    $this->get($response->headers->get('Location'))->assertOk();
});

it('dispatcher login with a stale foreign url.intended still redirects to the dispatcher dashboard and never 403s', function () {
    $dispatcher = navDispatcher();

    $response = $this->withSession(['url.intended' => url('/superadmin/dashboard')])
        ->post('/login', [
            'role' => 'dispatcher',
            'login_method' => 'password',
            'email' => $dispatcher->email,
            'password' => 'password',
        ]);

    $response->assertRedirect(route('admin.dashboard', absolute: false));
    $this->assertFalse(session()->has('url.intended'));

    $this->get($response->headers->get('Location'))->assertOk();
});

it('owner logout lands on the login page immediately, never a 403', function () {
    $owner = navOwner();
    $this->actingAs($owner, 'web');
    Auth::guard('superadmin')->login($owner);

    $response = $this->post('/logout');

    $response->assertRedirect(url('/login'));
    $this->assertFalse(session()->has('url.intended'));

    $this->get($response->headers->get('Location'))->assertOk();
});

it('dispatcher logout lands on the login page immediately, never a 403', function () {
    $dispatcher = navDispatcher();
    $this->actingAs($dispatcher, 'web');
    Auth::guard('dispatcher')->login($dispatcher);

    $response = $this->post('/logout');

    $response->assertRedirect(url('/login'));
    $this->assertFalse(session()->has('url.intended'));

    $this->get($response->headers->get('Location'))->assertOk();
});

it('an authenticated owner visiting the login page is routed to the owner dashboard, not forbidden', function () {
    $owner = navOwner();
    $this->actingAs($owner, 'web');
    Auth::guard('superadmin')->login($owner);

    $response = $this->get('/login');

    expect($response->getStatusCode())->not->toBe(403);

    $this->followingRedirects()->get('/login')->assertOk();
});

it('an authenticated dispatcher visiting the login page is routed to the dispatcher dashboard, not forbidden', function () {
    $dispatcher = navDispatcher();
    $this->actingAs($dispatcher, 'web');
    Auth::guard('dispatcher')->login($dispatcher);

    $response = $this->get('/login');

    expect($response->getStatusCode())->not->toBe(403);

    $this->followingRedirects()->get('/login')->assertOk();
});

it('owner deliberately accessing the dispatcher dashboard is still forbidden', function () {
    $owner = navOwner();
    $this->actingAs($owner, 'web');
    Auth::guard('superadmin')->login($owner);

    $this->get('/admin-dashboard')->assertForbidden();
});

it('dispatcher deliberately accessing the owner dashboard is still forbidden', function () {
    $dispatcher = navDispatcher();
    $this->actingAs($dispatcher, 'web');
    Auth::guard('dispatcher')->login($dispatcher);

    $this->get('/superadmin/dashboard')->assertForbidden();
});
