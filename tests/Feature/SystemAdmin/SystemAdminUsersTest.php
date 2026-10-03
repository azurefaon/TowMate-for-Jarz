<?php

use App\Models\Role;
use App\Models\Unit;
use App\Models\User;

function usersPinRole(int $id, string $name): Role
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

function usersAllRoles(): void
{
    usersPinRole(1, 'Owner');
    usersPinRole(2, 'Admin');
    usersPinRole(3, 'Team Leader');
    usersPinRole(4, 'Driver');
    usersPinRole(5, 'Customer');
    usersPinRole(6, 'System Admin');
}

function usersSystemAdmin(array $attrs = []): User
{
    usersAllRoles();

    return User::factory()->create(array_merge([
        'role_id' => 6,
        'status' => 'active',
        'must_change_password' => false,
    ], $attrs));
}

it('includes customer accounts in the main users list with a Customer role label', function () {
    $admin = usersSystemAdmin();
    User::factory()->create(['role_id' => 5, 'status' => 'active', 'email' => 'customer@example.com']);

    $response = $this->actingAs($admin)->get(route('system-admin.users.index'))->assertOk();

    $response->assertSee('customer@example.com');
    expect($response->viewData('users')->getCollection()->firstWhere('email', 'customer@example.com')->role->name)->toBe('Customer');
});

it('filters the users list by role and account status', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'email' => 'active-dispatcher@example.com']);
    $inactiveDispatcher = User::factory()->create(['role_id' => 2, 'status' => 'inactive', 'email' => 'inactive-dispatcher@example.com']);

    $response = $this->actingAs($admin)
        ->get(route('system-admin.users.index', ['role' => 2, 'status' => 'active']));

    $response->assertOk()
        ->assertSee('active-dispatcher@example.com')
        ->assertDontSee('inactive-dispatcher@example.com');
});

it('displays account timestamps on the users list', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create([
        'role_id' => 2,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->get(route('system-admin.users.index'))
        ->assertOk()
        ->assertSee($dispatcher->created_at->format('M d, Y'), false);
});

it('labels the account creation date column as created at using the real created_at value', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active']);

    $response = $this->actingAs($admin)->get(route('system-admin.users.index'));

    $response->assertOk()
        ->assertSee('Created At')
        ->assertDontSee('>Joined<', false)
        ->assertSee($dispatcher->created_at->format('M d, Y'), false);
});

it('shows the self tag beside the name and not beside the status', function () {
    $admin = usersSystemAdmin(['first_name' => 'Sam', 'last_name' => 'Admin']);

    $response = $this->actingAs($admin)->get(route('system-admin.users.index'));

    $response->assertOk();
    $html = $response->getContent();

    $nameStart = strpos($html, 'user-name');
    $nameEnd = strpos($html, '</span>', $nameStart);
    $nameBlock = substr($html, $nameStart, $nameEnd - $nameStart + 200);

    expect($nameBlock)->toContain('(you)');

    $statusStart = strpos($html, 'ua-status-active');
    $statusEnd = strpos($html, '</span>', $statusStart);
    $statusBlock = substr($html, $statusStart, $statusEnd - $statusStart);

    expect($statusBlock)->not->toContain('you');
});

it('creates a supported staff account', function () {
    $admin = usersSystemAdmin();

    $response = $this->actingAs($admin)->post(route('system-admin.users.store'), [
        'first_name' => 'New',
        'last_name' => 'Dispatcher',
        'email' => 'new.dispatcher@example.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role_id' => 2,
        'status' => 'active',
    ]);

    $response->assertRedirect(route('system-admin.users.index'));
    $this->assertDatabaseHas('users', ['email' => 'new.dispatcher@example.com', 'role_id' => 2]);
});

it('rejects creating a customer or driver account from this panel', function () {
    $admin = usersSystemAdmin();

    $response = $this->actingAs($admin)->post(route('system-admin.users.store'), [
        'first_name' => 'Sneaky',
        'last_name' => 'Customer',
        'email' => 'sneaky@example.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role_id' => 5,
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors(['role_id']);
    $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
});

it('does not assign a truck or unit when creating a team leader account', function () {
    $admin = usersSystemAdmin();

    $response = $this->actingAs($admin)->post(route('system-admin.users.store'), [
        'first_name' => 'New',
        'last_name' => 'Leader',
        'email' => 'new.leader@example.com',
        'phone' => '09171234567',
        'driver_personnel_id' => \App\Models\Personnel::create(['first_name' => 'Driver', 'last_name' => 'Leader', 'role' => 'driver', 'personnel_status' => 'active'])->id,
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role_id' => 3,
        'status' => 'active',
    ]);

    $response->assertRedirect(route('system-admin.users.index'));

    $newLeader = User::where('email', 'new.leader@example.com')->first();
    expect($newLeader)->not->toBeNull();
    expect(Unit::where('team_leader_id', $newLeader->id)->exists())->toBeFalse();
});

it('edits an account without exposing truck or unit assignment fields', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active']);

    $html = $this->actingAs($admin)->get(route('system-admin.users.edit', $dispatcher->id))->getContent();

    expect($html)->not->toContain('name="truck_type_id"');
    expect($html)->not->toContain('name="assigned_unit_id"');
    expect($html)->not->toContain('duty_status');
});

it('activates and deactivates an account', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active']);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.toggle', $dispatcher->id))
        ->assertRedirect();

    expect($dispatcher->fresh()->status)->toBe('inactive');

    $this->actingAs($admin)
        ->patch(route('system-admin.users.toggle', $dispatcher->id))
        ->assertRedirect();

    expect($dispatcher->fresh()->status)->toBe('active');
});

it('archives and restores an account', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active']);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.archive', $dispatcher->id), ['reason' => 'No longer needed'])
        ->assertRedirect(route('system-admin.users.index'));

    expect($dispatcher->fresh()->archived_at)->not->toBeNull();

    $this->actingAs($admin)
        ->patch(route('system-admin.users.restore', $dispatcher->id))
        ->assertRedirect(route('system-admin.users.archived'));

    expect($dispatcher->fresh()->archived_at)->toBeNull();
});

it('queues an account for deletion and can cancel it before purge', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active']);

    $this->actingAs($admin)
        ->delete(route('system-admin.users.queue-for-deletion', $dispatcher->id), ['reason' => 'Left the company'])
        ->assertRedirect(route('system-admin.users.index'));

    expect($dispatcher->fresh()->pending_delete_at)->not->toBeNull();

    $this->actingAs($admin)
        ->patch(route('system-admin.users.restore-from-deleted', $dispatcher->id))
        ->assertRedirect(route('system-admin.users.index'));

    expect($dispatcher->fresh()->pending_delete_at)->toBeNull();
});

it('permanently purges an account queued for deletion with the anonymization safeguard intact', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create([
        'role_id' => 2,
        'status' => 'inactive',
        'pending_delete_at' => now(),
        'pending_delete_reason' => 'Left',
    ]);

    $this->actingAs($admin)
        ->delete(route('system-admin.users.purge-now', $dispatcher->id))
        ->assertRedirect(route('system-admin.users.deleted'));

    $fresh = $dispatcher->fresh();
    expect($fresh === null || $fresh->anonymized_at !== null)->toBeTrue();
});

it('rejects a system admin deactivating themselves', function () {
    $admin = usersSystemAdmin();

    $this->actingAs($admin)
        ->patch(route('system-admin.users.toggle', $admin->id))
        ->assertRedirect();

    expect($admin->fresh()->status)->toBe('active');
});

it('rejects a system admin archiving themselves', function () {
    $admin = usersSystemAdmin();

    $this->actingAs($admin)
        ->patch(route('system-admin.users.archive', $admin->id), ['reason' => 'test'])
        ->assertRedirect();

    expect($admin->fresh()->archived_at)->toBeNull();
});

it('rejects a system admin queueing themselves for deletion', function () {
    $admin = usersSystemAdmin();

    $this->actingAs($admin)
        ->delete(route('system-admin.users.queue-for-deletion', $admin->id), ['reason' => 'test'])
        ->assertRedirect();

    expect($admin->fresh()->pending_delete_at)->toBeNull();
});

it('rejects a system admin purging themselves', function () {
    $admin = usersSystemAdmin();
    $admin->forceFill(['pending_delete_at' => now(), 'pending_delete_reason' => 'test'])->save();

    $this->actingAs($admin)
        ->delete(route('system-admin.users.purge-now', $admin->id))
        ->assertRedirect();

    expect($admin->fresh())->not->toBeNull();
});

it('blocks archiving the last active system admin even when acted on by an inactive admin session', function () {
    $lastAdmin = usersSystemAdmin();
    $inactiveActor = usersSystemAdmin(['status' => 'inactive']);

    $this->actingAs($inactiveActor)
        ->patch(route('system-admin.users.archive', $lastAdmin->id), ['reason' => 'test'])
        ->assertRedirect(route('login'));

    expect($lastAdmin->fresh()->archived_at)->toBeNull();
});

it('preserves owner-account protection', function () {
    $admin = usersSystemAdmin();
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active']);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.archive', $owner->id), ['reason' => 'test'])
        ->assertForbidden();

    expect($owner->fresh()->archived_at)->toBeNull();
});

function usersFillTeamLeaders(int $count): void
{
    User::factory()->count($count)->create(['role_id' => 3, 'status' => 'active']);
}

function usersTeamLeaderCount(): int
{
    return User::where('role_id', 3)->whereNull('archived_at')->count();
}

function usersCreateTeamLeaderPayload(string $email): array
{
    return [
        'first_name' => 'New',
        'last_name' => 'Leader',
        'email' => $email,
        'phone' => '09171234567',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role_id' => 3,
        'driver_personnel_id' => \App\Models\Personnel::create(['first_name' => 'Drv', 'last_name' => 'Name' . uniqid(), 'role' => 'driver', 'personnel_status' => 'active'])->id,
    ];
}

it('restores an archived team leader while below the limit', function () {
    $admin = usersSystemAdmin();
    \App\Models\SystemSetting::setValue('max_team_leaders', 10);
    usersFillTeamLeaders(8);
    $archived = User::factory()->create(['role_id' => 3, 'status' => 'inactive', 'archived_at' => now(), 'archived_reason' => 'x']);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.restore', $archived->id))
        ->assertRedirect(route('system-admin.users.archived'))
        ->assertSessionHas('success');

    expect($archived->fresh()->archived_at)->toBeNull()
        ->and(usersTeamLeaderCount())->toBe(9);
});

it('rejects restoring an archived team leader when the limit is reached', function () {
    $admin = usersSystemAdmin();
    \App\Models\SystemSetting::setValue('max_team_leaders', 10);
    usersFillTeamLeaders(10);
    $archived = User::factory()->create(['role_id' => 3, 'status' => 'inactive', 'archived_at' => now(), 'archived_reason' => 'x']);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.restore', $archived->id))
        ->assertRedirect()
        ->assertSessionHas('error', 'Team Leader limit reached. Archive another Team Leader before restoring this account.');

    $fresh = $archived->fresh();
    expect($fresh->archived_at)->not->toBeNull()
        ->and($fresh->archived_reason)->toBe('x')
        ->and($fresh->status)->toBe('inactive')
        ->and(usersTeamLeaderCount())->toBe(10)
        ->and(\App\Models\AuditLog::where('action', 'user_restored')->where('entity_id', $archived->id)->exists())->toBeFalse();
});

it('blocks the archive, create, restore bypass of the team leader limit', function () {
    $admin = usersSystemAdmin();
    \App\Models\SystemSetting::setValue('max_team_leaders', 10);
    usersFillTeamLeaders(10);
    $victim = User::where('role_id', 3)->first();

    $this->actingAs($admin)
        ->patch(route('system-admin.users.archive', $victim->id), ['reason' => 'rotate'])
        ->assertRedirect(route('system-admin.users.index'));
    expect(usersTeamLeaderCount())->toBe(9);

    $this->actingAs($admin)
        ->post(route('system-admin.users.store'), usersCreateTeamLeaderPayload('tl.new@example.com'))
        ->assertRedirect(route('system-admin.users.index'));
    expect(usersTeamLeaderCount())->toBe(10);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.restore', $victim->id))
        ->assertSessionHas('error');

    expect($victim->fresh()->archived_at)->not->toBeNull()
        ->and(usersTeamLeaderCount())->toBe(10);
});

it('rejects cancelling deletion of an archived team leader when the limit is reached', function () {
    $admin = usersSystemAdmin();
    \App\Models\SystemSetting::setValue('max_team_leaders', 10);
    usersFillTeamLeaders(10);
    $archived = User::factory()->create([
        'role_id' => 3, 'status' => 'inactive', 'archived_at' => now(), 'pending_delete_at' => now(),
    ]);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.restore-from-deleted', $archived->id))
        ->assertSessionHas('error');

    $fresh = $archived->fresh();
    expect($fresh->archived_at)->not->toBeNull()
        ->and($fresh->pending_delete_at)->not->toBeNull()
        ->and(usersTeamLeaderCount())->toBe(10);
});

it('does not apply the team leader limit when restoring other roles', function () {
    $admin = usersSystemAdmin();
    \App\Models\SystemSetting::setValue('max_team_leaders', 10);
    usersFillTeamLeaders(10);
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'inactive', 'archived_at' => now()]);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.restore', $dispatcher->id))
        ->assertSessionHas('success');

    expect($dispatcher->fresh()->archived_at)->toBeNull();
});

it('still rejects creating a team leader once the limit is reached', function () {
    $admin = usersSystemAdmin();
    \App\Models\SystemSetting::setValue('max_team_leaders', 10);
    usersFillTeamLeaders(10);

    $this->actingAs($admin)
        ->post(route('system-admin.users.store'), usersCreateTeamLeaderPayload('tl.over@example.com'))
        ->assertSessionHasErrors('role_id');

    expect(usersTeamLeaderCount())->toBe(10);
});

it('archives through the resource destroy route instead of throwing', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active']);

    $this->actingAs($admin)
        ->delete(route('system-admin.users.destroy', $dispatcher->id), ['reason' => 'Cleanup'])
        ->assertRedirect(route('system-admin.users.index'));

    expect($dispatcher->fresh()->archived_at)->not->toBeNull()
        ->and($dispatcher->fresh()->archived_reason)->toBe('Cleanup');
});

it('keeps the archive reason requirement on the destroy route', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active']);

    $this->actingAs($admin)
        ->delete(route('system-admin.users.destroy', $dispatcher->id))
        ->assertSessionHasErrors('reason');

    expect($dispatcher->fresh()->archived_at)->toBeNull();
});

it('keeps a user in the main list marked Pending Deletion after Delete', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'name' => 'Pending Pat', 'email' => 'pending.pat@example.com']);

    $this->actingAs($admin)
        ->delete(route('system-admin.users.queue-for-deletion', $dispatcher->id), ['reason' => 'Left the company'])
        ->assertRedirect(route('system-admin.users.index'));

    expect($dispatcher->fresh()->pending_delete_at)->not->toBeNull();

    $this->actingAs($admin)->get(route('system-admin.users.index'))
        ->assertOk()->assertSee('pending.pat@example.com')->assertSee('Pending Deletion')->assertSee('Cancel deletion');

    // Still present in the dedicated Pending Deletion view.
    $this->actingAs($admin)->get(route('system-admin.users.deleted'))
        ->assertOk()->assertSee('pending.pat@example.com');

    // Searchable and filterable from the main list.
    $this->actingAs($admin)->get(route('system-admin.users.index', ['search' => 'pending.pat']))
        ->assertSee('pending.pat@example.com');
    $this->actingAs($admin)->get(route('system-admin.users.index', ['status' => 'pending_deletion']))
        ->assertSee('pending.pat@example.com');
    $this->actingAs($admin)->get(route('system-admin.users.index', ['status' => 'active']))
        ->assertDontSee('pending.pat@example.com');
});

it('cancelling deletion from the main list restores the user to active', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'inactive', 'pending_delete_at' => now(), 'pending_delete_reason' => 'x']);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.restore-from-deleted', $dispatcher->id))
        ->assertRedirect(route('system-admin.users.index'));

    $fresh = $dispatcher->fresh();
    expect($fresh->pending_delete_at)->toBeNull()->and($fresh->status)->toBe('active');
});

it('keeps an archived user in the main list marked Archived and filterable', function () {
    $admin = usersSystemAdmin();
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'email' => 'archived.al@example.com']);

    $this->actingAs($admin)
        ->patch(route('system-admin.users.archive', $dispatcher->id), ['reason' => 'No longer needed'])
        ->assertRedirect(route('system-admin.users.index'));

    $this->actingAs($admin)->get(route('system-admin.users.index'))
        ->assertSee('archived.al@example.com')->assertSee('Archived');
    $this->actingAs($admin)->get(route('system-admin.users.index', ['status' => 'archived']))
        ->assertSee('archived.al@example.com');
    $this->actingAs($admin)->get(route('system-admin.users.index', ['status' => 'inactive']))
        ->assertDontSee('archived.al@example.com');
    $this->actingAs($admin)->get(route('system-admin.users.archived'))
        ->assertSee('archived.al@example.com');
});

it('does not list permanently anonymized users in the main list', function () {
    $admin = usersSystemAdmin();
    User::factory()->create(['role_id' => 2, 'email' => 'gone.gary@example.com', 'anonymized_at' => now()]);

    $this->actingAs($admin)->get(route('system-admin.users.index'))->assertDontSee('gone.gary@example.com');
});

it('still purges only users past the retention period', function () {
    usersSystemAdmin();
    $expired = User::factory()->create(['role_id' => 2, 'pending_delete_at' => now()->subDays(60), 'pending_delete_reason' => 'x']);
    $recent = User::factory()->create(['role_id' => 2, 'pending_delete_at' => now()->subDays(2), 'pending_delete_reason' => 'x']);

    $this->artisan('towmate:purge-deleted-users')->assertSuccessful();

    $freshExpired = $expired->fresh();
    expect($freshExpired === null || $freshExpired->anonymized_at !== null)->toBeTrue()
        ->and($recent->fresh()->anonymized_at)->toBeNull()
        ->and($recent->fresh()->pending_delete_at)->not->toBeNull();
});

function usersCustomer(array $attrs = []): User
{
    return User::factory()->create(array_merge(['role_id' => 5, 'status' => 'active'], $attrs));
}

it('filters the users list to customers only and keeps them in All Roles', function () {
    $admin = usersSystemAdmin();
    usersCustomer(['email' => 'only.customer@example.com']);
    User::factory()->create(['role_id' => 2, 'status' => 'active', 'email' => 'staff.dispatcher@example.com']);

    $this->actingAs($admin)->get(route('system-admin.users.index', ['role' => 5]))
        ->assertOk()->assertSee('only.customer@example.com')->assertDontSee('staff.dispatcher@example.com');

    $this->actingAs($admin)->get(route('system-admin.users.index'))
        ->assertSee('only.customer@example.com')->assertSee('staff.dispatcher@example.com');

    // Staff role filter is unchanged and excludes customers.
    $this->actingAs($admin)->get(route('system-admin.users.index', ['role' => 2]))
        ->assertSee('staff.dispatcher@example.com')->assertDontSee('only.customer@example.com');
});

it('offers Customer in the role filter but never as a creatable role', function () {
    $admin = usersSystemAdmin();

    $index = $this->actingAs($admin)->get(route('system-admin.users.index'))->assertOk();
    expect($index->viewData('roles')->pluck('name')->all())->toContain('Customer', 'Admin', 'Team Leader', 'System Admin')
        ->not->toContain('Owner', 'Driver');

    $create = $this->actingAs($admin)->get(route('system-admin.users.create'))->assertOk();
    expect($create->viewData('roles')->pluck('name')->all())->not->toContain('Customer');

    $this->actingAs($admin)->post(route('system-admin.users.store'), [
        'first_name' => 'New', 'last_name' => 'Customer', 'email' => 'new.customer@gmail.com',
        'password' => 'Password@12345', 'password_confirmation' => 'Password@12345', 'role_id' => 5,
    ])->assertSessionHasErrors('role_id');
    expect(User::where('email', 'new.customer@gmail.com')->exists())->toBeFalse();
});

it('searches customers by name and email', function () {
    $admin = usersSystemAdmin();
    usersCustomer(['name' => 'Searchable Customer', 'email' => 'findme.customer@example.com']);

    $this->actingAs($admin)->get(route('system-admin.users.index', ['search' => 'Searchable']))
        ->assertSee('findme.customer@example.com');
    $this->actingAs($admin)->get(route('system-admin.users.index', ['search' => 'findme.customer']))
        ->assertSee('findme.customer@example.com');
});

it('counts customers in pagination and sorts them by created date', function () {
    $admin = usersSystemAdmin();
    foreach (range(1, 12) as $i) {
        usersCustomer(['email' => "page-customer-{$i}@example.com", 'created_at' => now()->subMinutes(100 - $i)]);
    }

    $users = $this->actingAs($admin)->get(route('system-admin.users.index', ['role' => 5]))->assertOk()->viewData('users');
    expect($users->total())->toBe(12)->and($users->count())->toBe(10)
        ->and($users->first()->email)->toBe('page-customer-12@example.com');

    $oldest = $this->actingAs($admin)->get(route('system-admin.users.index', ['role' => 5, 'sort' => 'oldest']))->viewData('users')->first();
    expect($oldest->email)->toBe('page-customer-1@example.com');
});

it('applies the active, inactive, archived and pending deletion filters to customers', function () {
    $admin = usersSystemAdmin();
    usersCustomer(['email' => 'c-active@example.com']);
    usersCustomer(['email' => 'c-inactive@example.com', 'status' => 'inactive']);
    usersCustomer(['email' => 'c-archived@example.com', 'status' => 'inactive', 'archived_at' => now(), 'archived_reason' => 'x']);
    usersCustomer(['email' => 'c-pending@example.com', 'pending_delete_at' => now(), 'pending_delete_reason' => 'x']);

    $seen = fn (string $status) => $this->actingAs($admin)
        ->get(route('system-admin.users.index', ['role' => 5, 'status' => $status]))
        ->viewData('users')->getCollection()->pluck('email')->all();

    expect($seen('active'))->toBe(['c-active@example.com'])
        ->and($seen('inactive'))->toBe(['c-inactive@example.com'])
        ->and($seen('archived'))->toBe(['c-archived@example.com'])
        ->and($seen('pending_deletion'))->toBe(['c-pending@example.com']);

    // All four stay visible in the main list and in the dedicated views.
    $this->actingAs($admin)->get(route('system-admin.users.index', ['role' => 5]))
        ->assertSee('c-archived@example.com')->assertSee('c-pending@example.com');
    $this->actingAs($admin)->get(route('system-admin.users.archived'))->assertSee('c-archived@example.com');
    $this->actingAs($admin)->get(route('system-admin.users.deleted'))->assertSee('c-pending@example.com');
});

it('archives and queues a customer for deletion through the existing lifecycle', function () {
    $admin = usersSystemAdmin();
    $customer = usersCustomer();
    $other = usersCustomer();

    $this->actingAs($admin)->patch(route('system-admin.users.archive', $customer), ['reason' => 'Requested'])
        ->assertRedirect(route('system-admin.users.index'));
    expect($customer->fresh()->archived_at)->not->toBeNull();

    $this->actingAs($admin)->delete(route('system-admin.users.queue-for-deletion', $other->id), ['reason' => 'Requested'])
        ->assertRedirect(route('system-admin.users.index'));
    expect($other->fresh()->pending_delete_at)->not->toBeNull();
});

it('does not expose staff-only or owner-only actions on customer rows', function () {
    $admin = usersSystemAdmin();
    $customer = usersCustomer(['email' => 'actions.customer@example.com']);
    $locked = usersCustomer(['email' => 'locked.customer@example.com', 'status' => 'locked']);

    $html = $this->actingAs($admin)->get(route('system-admin.users.index', ['role' => 5]))->assertOk()->getContent();

    expect($html)->not->toContain(route('system-admin.users.edit', $customer->id))
        ->and($html)->not->toContain(route('system-admin.users.edit', $locked->id))
        ->and($html)->not->toContain(route('superadmin.users.unlock', $locked->id))
        ->and($html)->toContain('Locked (inactivity)')
        ->and($html)->toContain(route('system-admin.users.archive', $customer))
        ->and($html)->toContain(route('system-admin.users.queue-for-deletion', $customer->id));

    $this->actingAs($admin)->get(route('system-admin.users.edit', $customer->id))->assertForbidden();
});
