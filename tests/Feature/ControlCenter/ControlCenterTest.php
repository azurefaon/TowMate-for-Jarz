<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

function seedControlCenterRoles(): void
{
    DB::table('roles')->insert([
        ['id' => 1, 'name' => 'Super Admin'],
        ['id' => 2, 'name' => 'Dispatcher'],
        ['id' => 5, 'name' => 'Customer'],
    ]);
}

it('shows the shared control center for a super admin with governance access', function () {
    seedControlCenterRoles();

    $superAdmin = User::factory()->create([
        'role_id' => 1,
    ]);

    actingAs($superAdmin);

    get(route('control-center.index'))
        ->assertOk()
        ->assertSeeText('System Control Center')
        ->assertSeeText('Manage Users')
        ->assertSeeText('Control Center');
});

it('shows the shared control center for a dispatcher without governance access', function () {
    seedControlCenterRoles();

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    actingAs($dispatcher);

    get(route('control-center.index'))
        ->assertOk()
        ->assertSeeText('System Control Center')
        ->assertDontSeeText('Protection & Governance');
});

it('blocks customer access to the shared control center', function () {
    seedControlCenterRoles();

    $customer = User::factory()->create([
        'role_id' => 5,
    ]);

    actingAs($customer);

    get(route('control-center.index'))
        ->assertForbidden();
});

it('returns live control center data for dispatcher and superadmin roles', function () {
    seedControlCenterRoles();

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    actingAs($dispatcher);

    getJson(route('control-center.live'))
        ->assertOk()
        ->assertJsonStructure([
            'summary_cards',
            'attention_alerts',
            'booking_pipeline',
            'active_bookings',
            'recent_bookings',
            'team_leader_statuses',
            'dispatchers',
            'units_monitor',
            'flagged_customers',
            'recent_activities',
            'quick_links',
            'highlights',
        ]);
});
