<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

function pinGuardRole(int $id, string $name): Role
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

it('prevents system admin from changing the status of an online dispatcher', function () {
    pinGuardRole(6, 'System Admin');
    pinGuardRole(2, 'Dispatcher');

    $systemAdmin = User::factory()->create([
        'role_id' => 6,
        'status' => 'active',
    ]);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
        'status' => 'active',
        'name' => 'Online Dispatcher',
    ]);

    Cache::put("dispatcher:presence:{$dispatcher->id}", now()->timestamp, now()->addMinutes(5));

    $this->actingAs($systemAdmin)
        ->from(route('system-admin.users.index'))
        ->patch(route('system-admin.users.toggle', $dispatcher->id))
        ->assertRedirect(route('system-admin.users.index'))
        ->assertSessionHas('error');

    expect($dispatcher->fresh()->status)->toBe('active');
});
