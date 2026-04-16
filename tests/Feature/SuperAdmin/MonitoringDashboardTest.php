<?php

use App\Models\Role;
use App\Models\User;

it('shows the superadmin operations control board sections', function () {
    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

    $superAdmin = User::factory()->create([
        'role_id' => 1,
    ]);

    $this->actingAs($superAdmin)
        ->get(route('superadmin.monitoring.index'))
        ->assertOk()
        ->assertSeeText('Attention Needed')
        ->assertSeeText('Attention Needed')
        ->assertSeeText('Booking Pipeline')
        ->assertSeeText('Team Leader Tracker')
        ->assertSeeText('Dispatcher Tracker')
        ->assertSeeText('Schedule Monitor');
});
