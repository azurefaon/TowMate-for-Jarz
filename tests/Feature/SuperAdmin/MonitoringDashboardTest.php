<?php

use App\Models\Role;
use App\Models\User;

it('shows the superadmin operations control board sections', function () {
    foreach ([1 => 'Super Admin', 2 => 'Dispatcher', 3 => 'Team Leader'] as $id => $name) {
        if (! Role::find($id)) {
            $role = new Role(['name' => $name]);
            $role->id = $id;
            $role->save();
        }
    }

    $superAdmin = User::factory()->create([
        'role_id' => 1,
    ]);

    $this->actingAs($superAdmin)
        ->get(route('superadmin.monitoring.index'))
        ->assertOk()
        ->assertSeeText('Operations Summary')
        ->assertSeeText('Needs Attention')
        ->assertSeeText('Current Operations')
        ->assertSeeText('Team Leaders')
        ->assertSeeText('Units')
        ->assertSeeText('Dispatcher Activity')
        ->assertSeeText('Risk Watchlist');
});
