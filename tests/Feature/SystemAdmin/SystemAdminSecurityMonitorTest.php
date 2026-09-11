<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

function secMonPinRole(int $id, string $name): Role
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

function secMonSystemAdmin(): User
{
    secMonPinRole(2, 'Dispatcher');
    secMonPinRole(6, 'System Admin');

    return User::factory()->create([
        'role_id' => 6,
        'status' => 'active',
        'must_change_password' => false,
    ]);
}

it('counts temporarily locked staff accurately', function () {
    $admin = secMonSystemAdmin();
    $locked = User::factory()->create(['role_id' => 2, 'status' => 'active', 'locked_until' => now()->addMinutes(10)]);
    $notLocked = User::factory()->create(['role_id' => 2, 'status' => 'active', 'locked_until' => now()->subMinutes(10)]);

    $response = $this->actingAs($admin)->get(route('system-admin.security.monitor'));

    $response->assertOk();
    expect($response->viewData('lockedStaffCount'))->toBe(1);
});

it('counts failed login events for today only', function () {
    $admin = secMonSystemAdmin();

    AuditLog::create(['user_id' => null, 'action' => 'failed_login']);

    $old = AuditLog::create(['user_id' => null, 'action' => 'failed_login']);
    $old->forceFill(['created_at' => now()->subDays(2)])->save();

    $response = $this->actingAs($admin)->get(route('system-admin.security.monitor'));

    expect($response->viewData('failedLoginsToday'))->toBe(1);
});

it('counts successful account recoveries', function () {
    $admin = secMonSystemAdmin();

    AuditLog::create(['user_id' => $admin->id, 'action' => 'account_unlocked_via_email', 'category' => 'security']);

    $response = $this->actingAs($admin)->get(route('system-admin.security.monitor'));

    expect($response->viewData('recoveriesTotal'))->toBe(1);
});

it('renders the security events table using real audit data', function () {
    $admin = secMonSystemAdmin();

    AuditLog::create([
        'user_id' => $admin->id,
        'action' => 'account_temporarily_locked',
        'category' => 'security',
        'ip_address' => '10.0.0.5',
    ]);

    $this->actingAs($admin)
        ->get(route('system-admin.security.monitor'))
        ->assertOk()
        ->assertSee('Account Temporarily Locked')
        ->assertSee('10.0.0.5');
});

it('does not render invented risk score or severity fields', function () {
    $admin = secMonSystemAdmin();

    $html = $this->actingAs($admin)->get(route('system-admin.security.monitor'))->getContent();

    expect($html)->not->toContain('risk score');
    expect($html)->not->toContain('Risk Score');
    expect($html)->not->toContain('Threat Level');
    expect($html)->not->toContain('Severity');
});

it('is only reachable by system admin', function () {
    secMonPinRole(1, 'Owner');
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);

    $this->actingAs($owner)
        ->get(route('system-admin.security.monitor'))
        ->assertForbidden();
});
