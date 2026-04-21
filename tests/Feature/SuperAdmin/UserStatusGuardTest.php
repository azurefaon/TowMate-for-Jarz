<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('prevents super admin from changing the status of an online dispatcher', function () {
    Role::query()->create(['id' => 1, 'name' => 'Super Admin']);
    Role::query()->create(['id' => 2, 'name' => 'Dispatcher']);

    $superAdmin = User::factory()->create([
        'role_id' => 1,
        'status' => 'active',
    ]);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
        'status' => 'active',
        'name' => 'Online Dispatcher',
    ]);

    Cache::put("dispatcher:presence:{$dispatcher->id}", now()->timestamp, now()->addMinutes(5));

    $this->actingAs($superAdmin)
        ->from(route('superadmin.users.index'))
        ->patch(route('superadmin.users.toggle', $dispatcher->id))
        ->assertRedirect(route('superadmin.users.index'))
        ->assertSessionHas('error');

    expect($dispatcher->fresh()->status)->toBe('active');
});
