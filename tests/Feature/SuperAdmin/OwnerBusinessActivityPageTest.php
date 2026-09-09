<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

function baRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function baOwner(): User
{
    baRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function baDispatcher(): User
{
    baRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

function baLog(array $overrides = []): AuditLog
{
    return AuditLog::create(array_merge([
        'action' => 'update_truck_type',
        'category' => 'update',
        'entity_type' => 'TruckType',
        'entity_id' => 1,
        'reference' => 'Heavy Duty',
        'description' => 'Truck type updated.',
    ], $overrides));
}

it('owner can open business activity', function () {
    $this->actingAs(baOwner())->get(route('superadmin.reports.activity'))->assertOk();
});

it('dispatcher cannot access business activity', function () {
    $this->actingAs(baDispatcher())->get(route('superadmin.reports.activity'))->assertForbidden();
});

it('does not crash when an audit value is a sequential array of scalars', function () {
    baLog([
        'old_value' => ['truck_types' => ['Light Duty']],
        'new_value' => ['truck_types' => ['Light Duty', 'Medium Duty']],
    ]);

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('Light Duty, Medium Duty', false);
    $response->assertDontSee('Array to string conversion');
});

it('does not crash when an audit value is a list of nested associative arrays', function () {
    baLog([
        'action' => 'update_booking',
        'entity_type' => 'Booking',
        'old_value' => ['extra_vehicles' => []],
        'new_value' => [
            'extra_vehicles' => [
                ['truck_type_id' => 2, 'vehicle_type_id' => 5],
                ['truck_type_id' => 3, 'vehicle_type_id' => 6],
            ],
        ],
    ]);

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertDontSee('Array', false);
    $content = $response->getContent();
    expect($content)->toContain('Truck Type Id: 2');
    expect($content)->toContain('Vehicle Type Id: 5');
});

it('does not crash when an audit value is an associative array', function () {
    baLog([
        'action' => 'update_quotation',
        'entity_type' => 'Quotation',
        'old_value' => ['price_change_log' => null],
        'new_value' => [
            'price_change_log' => ['at' => '2026-09-09T10:00:00Z', 'old' => 2000, 'new' => 2500],
        ],
    ]);

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $content = $response->getContent();
    expect($content)->toContain('Old: 2000');
    expect($content)->toContain('New: 2500');
});

it('renders null audit values safely as none', function () {
    baLog([
        'old_value' => ['dispatcher_note' => null],
        'new_value' => ['dispatcher_note' => 'Leaving early today'],
    ]);

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('(none)');
    $response->assertSee('Leaving early today');
});

it('renders boolean audit values as yes or no', function () {
    baLog([
        'old_value' => ['zone_confirmed' => false],
        'new_value' => ['zone_confirmed' => true],
    ]);

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('No');
    $response->assertSee('Yes');
});

it('renders plain scalar audit values correctly', function () {
    baLog([
        'old_value' => ['base_rate' => 2000],
        'new_value' => ['base_rate' => 2500],
    ]);

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('2000');
    $response->assertSee('2500');
});

it('excludes security categories from the default unfiltered view', function () {
    baLog(['category' => 'login', 'action' => 'login', 'description' => 'BA_SECURITY_LOGIN_EVENT']);

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertDontSee('BA_SECURITY_LOGIN_EVENT');
});

it('still allows viewing security category logs when explicitly filtered', function () {
    baLog(['category' => 'security', 'action' => 'customer_password_reset_otp_locked', 'description' => 'BA_SECURITY_FILTERED_EVENT']);

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity', ['category' => 'security']));

    $response->assertOk();
    $response->assertSee('BA_SECURITY_FILTERED_EVENT');
});

it('continues to support the date range filter', function () {
    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity', [
        'from' => now()->subDays(2)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
});

it('paginates business activity results', function () {
    for ($i = 0; $i < 15; $i++) {
        baLog(['reference' => 'Truck ' . $i]);
    }

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('records');
});

it('loads page one and page two when enough records exist', function () {
    for ($i = 0; $i < 15; $i++) {
        baLog(['reference' => 'Truck ' . $i]);
    }

    $page1 = $this->actingAs(baOwner())->get(route('superadmin.reports.activity', ['page' => 1]));
    $page2 = $this->actingAs(baOwner())->get(route('superadmin.reports.activity', ['page' => 2]));

    $page1->assertOk();
    $page2->assertOk();
    $page1->assertSee('Showing 1', false);
    $page2->assertSee('Showing 11', false);
});

it('preserves filter query parameters inside the pagination links', function () {
    for ($i = 0; $i < 15; $i++) {
        baLog(['reference' => 'Truck ' . $i]);
    }

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity', [
        'category' => 'update',
        'search' => 'Truck',
    ]));

    $response->assertOk();
    $response->assertSee('category=update', false);
    $response->assertSee('search=Truck', false);
});

it('does not expose mutation controls on business activity', function () {
    baLog();

    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertDontSee('Delete Log');
    $response->assertDontSee('Undo');
    $response->assertDontSee('Retry');
    $response->assertDontSee('<form method="POST"', false);
});

it('renders a clean empty state when no activity matches the filters', function () {
    $response = $this->actingAs(baOwner())->get(route('superadmin.reports.activity', ['search' => 'zzz-no-match']));

    $response->assertOk();
    $response->assertSee('No business activity found.');
    $response->assertSee('Try adjusting the selected filters or date range.');
});
