<?php

use App\Models\Role;
use App\Models\User;

function seedManagedRoles(): void
{
    pinRole(1, 'Owner');
    pinRole(2, 'Dispatcher');
    pinRole(3, 'Team Leader');
    pinRole(6, 'System Admin');
}

function pinRole(int $id, string $name): Role
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

test('forgot password request screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('no route exists to submit a manual account access request anymore', function () {
    expect(\Illuminate\Support\Facades\Route::has('system-admin.users.password-request.set-password'))->toBeFalse();
    expect(\Illuminate\Support\Facades\Route::has('system-admin.users.password-request.resolve'))->toBeFalse();
});

test('the Users index no longer shows the Account Access Requests panel', function () {
    seedManagedRoles();

    $systemAdmin = User::factory()->create(['role_id' => 6, 'status' => 'active']);

    User::factory()->create([
        'role_id' => 2,
    ]);

    $this->actingAs($systemAdmin)
        ->get(route('system-admin.users.index'))
        ->assertOk()
        ->assertDontSeeText('Account Access Requests')
        ->assertDontSeeText('Password request pending');
});
