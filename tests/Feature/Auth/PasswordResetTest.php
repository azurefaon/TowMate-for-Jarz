<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function seedManagedRoles(): void
{
    Role::firstOrCreate(['id' => 1], ['name' => 'Super Admin']);
    Role::firstOrCreate(['id' => 2], ['name' => 'Dispatcher']);
    Role::firstOrCreate(['id' => 3], ['name' => 'Team Leader']);
}

test('forgot password request screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('staff member can submit an account access request for superadmin review', function () {
    seedManagedRoles();

    $user = User::factory()->create([
        'role_id' => 2,
        'email' => 'dispatcher@example.com',
    ]);

    $this->post('/forgot-password', [
        'email' => $user->email,
        'note' => 'Locked out before shift start.',
    ])->assertSessionHas('status');

    $user->refresh();

    expect($user->password_request_status)->toBe('pending');
    expect($user->password_request_note)->toBe('Locked out before shift start.');
    expect($user->password_requested_at)->not->toBeNull();
});

test('superadmin can set a default password for a pending access request', function () {
    seedManagedRoles();

    $superAdmin = User::factory()->create([
        'role_id' => 1,
        'email' => 'superadmin@example.com',
    ]);

    $user = User::factory()->create([
        'role_id' => 2,
        'password' => Hash::make('OldPassword!123'),
        'password_request_status' => 'pending',
        'password_requested_at' => now(),
        'password_request_note' => 'Need help resetting access.',
    ]);

    $this->actingAs($superAdmin)
        ->get(route('superadmin.users.index'))
        ->assertOk()
        ->assertSeeText('Account Access Requests')
        ->assertSeeText($user->email);

    $this->actingAs($superAdmin)
        ->patch(route('superadmin.users.password-request.set-password', $user), [
            'password' => 'TowMate123A',
            'password_confirmation' => 'TowMate123A',
        ])
        ->assertRedirect(route('superadmin.users.index'));

    $user->refresh();

    expect($user->password_request_status)->toBe('resolved');
    expect($user->password_request_resolved_at)->not->toBeNull();
    expect(Hash::check('TowMate123A', $user->password))->toBeTrue();
    expect(Hash::check('OldPassword!123', $user->password))->toBeFalse();
});
