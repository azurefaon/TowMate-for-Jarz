<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

function auditLogPinRole(int $id, string $name): Role
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

function auditLogSystemAdmin(): User
{
    auditLogPinRole(1, 'Owner');
    auditLogPinRole(2, 'Dispatcher');
    auditLogPinRole(6, 'System Admin');

    return User::factory()->create([
        'role_id' => 6,
        'status' => 'active',
        'must_change_password' => false,
    ]);
}

it('renders technical and security logs that are excluded from owner business activity', function () {
    $admin = auditLogSystemAdmin();

    AuditLog::create([
        'user_id' => null,
        'action' => 'failed_login',
        'ip_address' => '203.0.113.7',
    ]);

    $this->actingAs($admin)
        ->get(route('system-admin.audit-logs.index'))
        ->assertOk()
        ->assertSee('Failed Login')
        ->assertSee('203.0.113.7');
});

it('filters logs by category', function () {
    $admin = auditLogSystemAdmin();

    AuditLog::create(['user_id' => null, 'action' => 'failed_login', 'reference' => 'unreachable.login@example.com']);
    AuditLog::create(['user_id' => $admin->id, 'action' => 'user_archived', 'reference' => 'Archived Person']);

    $response = $this->actingAs($admin)
        ->get(route('system-admin.audit-logs.index', ['category' => 'archive']));

    $response->assertOk()
        ->assertSee('Archived Person')
        ->assertDontSee('unreachable.login@example.com');
});

it('filters logs by search text', function () {
    $admin = auditLogSystemAdmin();

    AuditLog::create(['user_id' => $admin->id, 'action' => 'user_archived', 'reference' => 'FindThisPerson']);
    AuditLog::create(['user_id' => $admin->id, 'action' => 'user_restored', 'reference' => 'SomeoneElse']);

    $this->actingAs($admin)
        ->get(route('system-admin.audit-logs.index', ['search' => 'FindThisPerson']))
        ->assertOk()
        ->assertSee('FindThisPerson')
        ->assertDontSee('SomeoneElse');
});

it('filters logs by user', function () {
    $admin = auditLogSystemAdmin();
    $otherAdmin = auditLogSystemAdmin();

    AuditLog::create(['user_id' => $admin->id, 'action' => 'user_archived', 'reference' => 'ByFirstAdmin']);
    AuditLog::create(['user_id' => $otherAdmin->id, 'action' => 'user_archived', 'reference' => 'BySecondAdmin']);

    $this->actingAs($admin)
        ->get(route('system-admin.audit-logs.index', ['user_id' => $admin->id]))
        ->assertOk()
        ->assertSee('ByFirstAdmin')
        ->assertDontSee('BySecondAdmin');
});

it('filters logs by date range', function () {
    $admin = auditLogSystemAdmin();

    AuditLog::create(['user_id' => $admin->id, 'action' => 'user_archived', 'reference' => 'Recent']);

    $old = AuditLog::create(['user_id' => $admin->id, 'action' => 'user_archived', 'reference' => 'OldOne']);
    $old->forceFill(['created_at' => now()->subDays(60)])->save();

    $this->actingAs($admin)
        ->get(route('system-admin.audit-logs.index', [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]))
        ->assertOk()
        ->assertSee('Recent')
        ->assertDontSee('OldOne');
});

it('paginates audit log results', function () {
    $admin = auditLogSystemAdmin();

    foreach (range(1, 20) as $i) {
        AuditLog::create(['user_id' => $admin->id, 'action' => 'user_updated']);
    }

    $this->actingAs($admin)
        ->get(route('system-admin.audit-logs.index'))
        ->assertOk()
        ->assertSee('owner-pagination', false);
});

it('displays the ip address column', function () {
    $admin = auditLogSystemAdmin();

    AuditLog::create(['user_id' => null, 'action' => 'failed_login', 'ip_address' => '198.51.100.9']);

    $this->actingAs($admin)
        ->get(route('system-admin.audit-logs.index'))
        ->assertOk()
        ->assertSee('198.51.100.9');
});

it('is only reachable by system admin, not owner', function () {
    auditLogPinRole(1, 'Owner');
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);

    $this->actingAs($owner)
        ->get(route('system-admin.audit-logs.index'))
        ->assertForbidden();
});

it('does not break owner business activity, which remains independently accessible', function () {
    auditLogPinRole(1, 'Owner');
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);

    $this->actingAs($owner)
        ->get(route('superadmin.reports.activity'))
        ->assertOk();
});
