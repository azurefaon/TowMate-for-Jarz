<?php

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;

function pinnedRole(int $id, string $name): Role
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

it('lets the system admin save a dynamic maximum team leader setting', function () {
    $systemAdminRole = pinnedRole(6, 'System Admin');

    $admin = User::factory()->create([
        'role_id' => $systemAdminRole->id,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->post(route('system-admin.settings.update'), [
            'deleted_retention_days' => 30,
            'customer_inactivity_lock_days' => 90,
            'max_team_leaders' => 7,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(SystemSetting::getValue('max_team_leaders'))->toBe('7');
});

it('shows the current team leader capacity inside the add user module', function () {
    $systemAdminRole = pinnedRole(6, 'System Admin');
    $teamLeaderRole = Role::firstOrCreate(['id' => 3], ['name' => 'Team Leader']);

    SystemSetting::setValue('max_team_leaders', 2);
    User::query()->where('role_id', $teamLeaderRole->id)->delete();

    User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'status' => 'active',
    ]);

    $admin = User::factory()->create([
        'role_id' => $systemAdminRole->id,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->get(route('system-admin.users.create'))
        ->assertOk()
        ->assertSee('Team Leader Slots')
        ->assertSee('1 / 2');
});

it('blocks creating extra team leaders once the configured limit is reached', function () {
    $systemAdminRole = pinnedRole(6, 'System Admin');
    $dispatcherRole = Role::firstOrCreate(['id' => 2], ['name' => 'Dispatcher']);
    $teamLeaderRole = Role::firstOrCreate(['id' => 3], ['name' => 'Team Leader']);

    SystemSetting::setValue('max_team_leaders', 1);
    User::query()->where('role_id', $teamLeaderRole->id)->delete();

    User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'status' => 'active',
    ]);

    $admin = User::factory()->create([
        'role_id' => $systemAdminRole->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)
        ->from(route('system-admin.users.create'))
        ->post(route('system-admin.users.store'), [
            'first_name' => 'Jamie',
            'last_name' => 'Leader',
            'email' => 'jamie.leader@example.com',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id' => $teamLeaderRole->id,
            'status' => 'active',
        ]);

    $response->assertRedirect(route('system-admin.users.create'))
        ->assertSessionHasErrors(['role_id']);

    $this->assertDatabaseMissing('users', [
        'email' => 'jamie.leader@example.com',
    ]);

    $this->actingAs($admin)
        ->post(route('system-admin.users.store'), [
            'first_name' => 'Dana',
            'last_name' => 'Dispatch',
            'email' => 'dana.dispatch@example.com',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id' => $dispatcherRole->id,
            'status' => 'active',
        ])
        ->assertRedirect(route('system-admin.users.index'));
});
