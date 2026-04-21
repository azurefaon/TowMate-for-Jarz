<?php

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;

it('lets the super admin save a dynamic maximum team leader setting', function () {
    $superAdminRole = Role::firstOrCreate(['id' => 1], ['name' => 'Super Admin', 'description' => 'Super Admin']);

    $admin = User::factory()->create([
        'role_id' => $superAdminRole->id,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->post(route('superadmin.settings.update'), [
            'settings' => [
                'max_team_leaders' => 7,
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(SystemSetting::getValue('max_team_leaders'))->toBe('7');
});

it('shows the current team leader capacity inside the add user module', function () {
    $superAdminRole = Role::firstOrCreate(['id' => 1], ['name' => 'Super Admin', 'description' => 'Super Admin']);
    $teamLeaderRole = Role::firstOrCreate(['id' => 3], ['name' => 'Team Leader', 'description' => 'Team Leader']);

    SystemSetting::setValue('max_team_leaders', 2);
    User::query()->where('role_id', $teamLeaderRole->id)->delete();

    User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'status' => 'active',
    ]);

    $admin = User::factory()->create([
        'role_id' => $superAdminRole->id,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->get(route('superadmin.users.create'))
        ->assertOk()
        ->assertSee('Team Leader Capacity')
        ->assertSee('1 / 2');
});

it('blocks creating extra team leaders once the configured limit is reached', function () {
    $superAdminRole = Role::firstOrCreate(['id' => 1], ['name' => 'Super Admin', 'description' => 'Super Admin']);
    $dispatcherRole = Role::firstOrCreate(['id' => 2], ['name' => 'Dispatcher', 'description' => 'Dispatcher']);
    $teamLeaderRole = Role::firstOrCreate(['id' => 3], ['name' => 'Team Leader', 'description' => 'Team Leader']);

    SystemSetting::setValue('max_team_leaders', 1);
    User::query()->where('role_id', $teamLeaderRole->id)->delete();

    User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'status' => 'active',
    ]);

    $admin = User::factory()->create([
        'role_id' => $superAdminRole->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)
        ->from(route('superadmin.users.create'))
        ->post(route('superadmin.users.store'), [
            'first_name' => 'Jamie',
            'last_name' => 'Leader',
            'email' => 'jamie.leader@example.com',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id' => $teamLeaderRole->id,
            'status' => 'active',
        ]);

    $response->assertRedirect(route('superadmin.users.create'))
        ->assertSessionHasErrors(['role_id']);

    $this->assertDatabaseMissing('users', [
        'email' => 'jamie.leader@example.com',
    ]);

    $this->actingAs($admin)
        ->post(route('superadmin.users.store'), [
            'first_name' => 'Dana',
            'last_name' => 'Dispatch',
            'email' => 'dana.dispatch@example.com',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id' => $dispatcherRole->id,
            'status' => 'active',
        ])
        ->assertRedirect(route('superadmin.users.index'));
});
