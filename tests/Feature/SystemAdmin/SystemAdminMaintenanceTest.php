<?php

use App\Models\Role;
use App\Models\User;

function saMaintenancePinRole(int $id, string $name): Role
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

function saMaintenanceAdmin(): User
{
    saMaintenancePinRole(1, 'Owner');
    saMaintenancePinRole(6, 'System Admin');

    return User::factory()->create([
        'role_id' => 6,
        'status' => 'active',
        'must_change_password' => false,
    ]);
}

it('lets system admin view the maintenance page', function () {
    $admin = saMaintenanceAdmin();

    $this->actingAs($admin)
        ->get(route('system-admin.maintenance.index'))
        ->assertOk()
        ->assertSee('Data Protection')
        ->assertSee('System Information');
});

it('creates an encrypted backup', function () {
    $admin = saMaintenanceAdmin();

    $this->actingAs($admin)
        ->post(route('system-admin.maintenance.backups.store'), ['dataset' => 'users'])
        ->assertRedirect(route('system-admin.maintenance.index'))
        ->assertSessionHas('success');

    expect(\Illuminate\Support\Facades\Storage::disk('local')->allFiles('backups'))->not->toBeEmpty();
});

it('downloads a previously created backup', function () {
    $admin = saMaintenanceAdmin();

    $this->actingAs($admin)->post(route('system-admin.maintenance.backups.store'), ['dataset' => 'users']);

    $path = collect(\Illuminate\Support\Facades\Storage::disk('local')->allFiles('backups'))->first();

    $this->actingAs($admin)
        ->get(route('system-admin.maintenance.backups.download', ['file' => $path]))
        ->assertOk();
});

it('rejects downloading a file outside the backups directory', function () {
    $admin = saMaintenanceAdmin();

    $this->actingAs($admin)
        ->get(route('system-admin.maintenance.backups.download', ['file' => '../.env']))
        ->assertForbidden();
});

it('owner no longer has route-level access to data protection', function () {
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);

    $this->actingAs($owner)
        ->get(route('system-admin.maintenance.index'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('system-admin.maintenance.backups.store'), ['dataset' => 'users'])
        ->assertForbidden();
});

it('renders no destructive database controls on the maintenance page', function () {
    $admin = saMaintenanceAdmin();

    $html = $this->actingAs($admin)->get(route('system-admin.maintenance.index'))->getContent();

    expect($html)->not->toContain('Reset Database');
    expect($html)->not->toContain('Clear Database');
    expect($html)->not->toContain('Run Migrations');
    expect($html)->not->toContain('Delete All Data');
    expect($html)->not->toContain('Factory Reset');
});

it('renders no secret values in system information', function () {
    $admin = saMaintenanceAdmin();

    $html = $this->actingAs($admin)->get(route('system-admin.maintenance.index'))->getContent();

    expect($html)->not->toContain(config('database.connections.pgsql.password'));
    expect($html)->not->toContain(config('app.key'));
});
