<?php

use App\Models\Role;
use App\Models\User;

function tlMobileUser(array $overrides = []): User
{
    $role = Role::find(3) ?: tap(new Role(['name' => 'Team Leader']), function ($r) {
        $r->id = 3;
        $r->save();
    });

    return User::factory()->create(array_merge(['role_id' => $role->id, 'status' => 'active'], $overrides));
}

it('still lets a team leader sign in through the mobile API and returns a token', function () {
    $tl = tlMobileUser();

    $this->postJson('/api/login', ['email' => $tl->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.role', 'Team Leader')
        ->assertJsonStructure(['data' => ['token']]);
});

it('rejects a team leader with the wrong password on the mobile API', function () {
    $tl = tlMobileUser();

    $this->postJson('/api/login', ['email' => $tl->email, 'password' => 'not-the-password'])
        ->assertStatus(401);
});

it('does not give a web session to a team leader who signed in on the mobile API', function () {
    $tl = tlMobileUser();

    $this->postJson('/api/login', ['email' => $tl->email, 'password' => 'password'])->assertOk();

    $this->get('/superadmin/dashboard')->assertRedirect();
    $this->assertGuest('web');
});
