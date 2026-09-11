<?php

use App\Models\Role;
use App\Models\User;

function saPinRole(int $id, string $name): Role
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

function saAllRoles(): void
{
    saPinRole(1, 'Owner');
    saPinRole(2, 'Dispatcher');
    saPinRole(3, 'Team Leader');
    saPinRole(6, 'System Admin');
}

function saSystemAdmin(array $attrs = []): User
{
    saAllRoles();

    return User::factory()->create(array_merge([
        'role_id' => 6,
        'status' => 'active',
        'must_change_password' => false,
    ], $attrs));
}

function saOwner(array $attrs = []): User
{
    saAllRoles();

    return User::factory()->create(array_merge([
        'role_id' => 1,
        'status' => 'active',
        'must_change_password' => false,
    ], $attrs));
}

function saDispatcher(array $attrs = []): User
{
    saAllRoles();

    return User::factory()->create(array_merge([
        'role_id' => 2,
        'status' => 'active',
        'must_change_password' => false,
    ], $attrs));
}

function saTeamLeader(array $attrs = []): User
{
    saAllRoles();

    return User::factory()->create(array_merge([
        'role_id' => 3,
        'status' => 'active',
        'must_change_password' => false,
    ], $attrs));
}

it('lets system admin access every system admin page', function () {
    $admin = saSystemAdmin();

    $this->actingAs($admin)->get(route('system-admin.dashboard'))->assertOk();
    $this->actingAs($admin)->get(route('system-admin.users.index'))->assertOk();
    $this->actingAs($admin)->get(route('system-admin.security.monitor'))->assertOk();
    $this->actingAs($admin)->get(route('system-admin.audit-logs.index'))->assertOk();
    $this->actingAs($admin)->get(route('system-admin.settings.index'))->assertOk();
    $this->actingAs($admin)->get(route('system-admin.maintenance.index'))->assertOk();
});

it('forbids system admin from every owner-only business route', function () {
    $admin = saSystemAdmin();

    $this->actingAs($admin)->get(route('superadmin.dashboard'))->assertForbidden();
    $this->actingAs($admin)->get(route('superadmin.revenue.index'))->assertForbidden();
    $this->actingAs($admin)->get(route('superadmin.reports.index'))->assertForbidden();
    $this->actingAs($admin)->get(route('superadmin.unit-truck.index'))->assertForbidden();
    $this->actingAs($admin)->get(route('superadmin.bookings.index'))->assertForbidden();
    $this->actingAs($admin)->get(route('superadmin.settings.index'))->assertForbidden();
    $this->actingAs($admin)->get(route('superadmin.monitoring.index'))->assertForbidden();
});

it('forbids system admin from dispatcher and team leader routes', function () {
    $admin = saSystemAdmin();

    $this->actingAs($admin)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.dispatch'))->assertForbidden();
});

it('lets owner continue accessing every existing owner business route', function () {
    $owner = saOwner();

    $this->actingAs($owner)->get(route('superadmin.dashboard'))->assertOk();
    $this->actingAs($owner)->get(route('superadmin.revenue.index'))->assertOk();
    $this->actingAs($owner)->get(route('superadmin.reports.index'))->assertOk();
    $this->actingAs($owner)->get(route('superadmin.unit-truck.index'))->assertOk();
    $this->actingAs($owner)->get(route('superadmin.bookings.index'))->assertOk();
    $this->actingAs($owner)->get(route('superadmin.monitoring.index'))->assertOk();
    $this->actingAs($owner)->get(route('superadmin.settings.index'))->assertOk();
});

it('forbids owner from every system admin route', function () {
    $owner = saOwner();

    $this->actingAs($owner)->get(route('system-admin.dashboard'))->assertForbidden();
    $this->actingAs($owner)->get(route('system-admin.users.index'))->assertForbidden();
    $this->actingAs($owner)->get(route('system-admin.security.monitor'))->assertForbidden();
    $this->actingAs($owner)->get(route('system-admin.audit-logs.index'))->assertForbidden();
    $this->actingAs($owner)->get(route('system-admin.settings.index'))->assertForbidden();
    $this->actingAs($owner)->get(route('system-admin.maintenance.index'))->assertForbidden();
});

it('forbids dispatcher and team leader from every system admin route', function () {
    $dispatcher = saDispatcher();
    $teamLeader = saTeamLeader();

    foreach ([$dispatcher, $teamLeader] as $actor) {
        $this->actingAs($actor)->get(route('system-admin.dashboard'))->assertForbidden();
        $this->actingAs($actor)->get(route('system-admin.users.index'))->assertForbidden();
        $this->actingAs($actor)->get(route('system-admin.security.monitor'))->assertForbidden();
        $this->actingAs($actor)->get(route('system-admin.audit-logs.index'))->assertForbidden();
        $this->actingAs($actor)->get(route('system-admin.settings.index'))->assertForbidden();
        $this->actingAs($actor)->get(route('system-admin.maintenance.index'))->assertForbidden();
    }
});

it('guests are redirected to login for every system admin route', function () {
    $this->get(route('system-admin.dashboard'))->assertRedirect(route('login'));
    $this->get(route('system-admin.users.index'))->assertRedirect(route('login'));
});
