<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function sbNavOwner(): User
{
    if (! Role::find(1)) {
        $role = new Role(['name' => 'Owner']);
        $role->id = 1;
        $role->save();
    }

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function sbNavDispatcher(): User
{
    if (! Role::find(2)) {
        $role = new Role(['name' => 'Dispatcher']);
        $role->id = 2;
        $role->save();
    }

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

function sbNavBooking(array $overrides = []): Booking
{
    $customer = Customer::create([
        'full_name' => 'Nav Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'nav-' . uniqid() . '@example.com',
    ]);

    $truckType = TruckType::create([
        'name' => 'NAV Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'completed',
    ], $overrides));
}

it('renders Dashboard instead of Overview in the sidebar', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('>Dashboard<', false);
    $response->assertDontSee('>Overview<', false);
});

it('keeps the dashboard route unchanged', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    expect(route('superadmin.dashboard'))->toContain('/superadmin/dashboard');
});

it('renders Business, Fleet Management, Oversight, and Management as sidebar destinations', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('>Business<', false);
    $response->assertSee('>Fleet Management<', false);
    $response->assertSee('>Oversight<', false);
    $response->assertSee('>Management<', false);
});

it('renders Fleet Management as a single sidebar link rather than a collapsible group', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('<a href="' . route('superadmin.truck-types.index') . '" title="Fleet Management"', false);
    $response->assertDontSee('sidebarGroupFleet', false);
});

it('lists Revenue, Reports, and Bookings under Business', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('id="sidebarGroupBusiness"', false);
    $response->assertSee('Revenue');
    $response->assertSee('Reports');
    $response->assertSee('Bookings');
});

it('no longer lists Trucks, Truck Types and Rates, or Vehicle Types as separate sidebar children', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertDontSee('title="Trucks"', false);
    $response->assertDontSee('title="Truck Types &amp; Rates"', false);
    $response->assertDontSee('title="Vehicle Types"', false);
});

it('lists Operations Monitor and Business Activity under Oversight', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('id="sidebarGroupOversight"', false);
    $response->assertSee('Operations Monitor');
    $response->assertSee('Business Activity');
});

it('lists Business Settings under Management', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('id="sidebarGroupManagement"', false);
    $response->assertSee('Business Settings');
});

it('expands Business when on the Revenue page', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.revenue.index'));

    $response->assertOk();
    $response->assertSee('aria-expanded="true" aria-controls="sidebarGroupBusiness"', false);
    $response->assertSee('aria-expanded="false" aria-controls="sidebarGroupOversight"', false);
    $response->assertSee('aria-expanded="false" aria-controls="sidebarGroupManagement"', false);
});

it('expands Business when on the Reports page', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.reports.index'));

    $response->assertOk();
    $response->assertSee('aria-expanded="true" aria-controls="sidebarGroupBusiness"', false);
});

it('expands Business when on the Bookings page', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.bookings.index'));

    $response->assertOk();
    $response->assertSee('aria-expanded="true" aria-controls="sidebarGroupBusiness"', false);
});

it('highlights Fleet Management as active on the Trucks page without expanding a submenu', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.unit-truck.index'));

    $response->assertOk();
    expect(preg_match('/title="Fleet Management"\s*class="active"/', $response->getContent()))->toBe(1);
    $response->assertDontSee('sidebarGroupFleet', false);
});

it('highlights Fleet Management as active on the Truck Types page', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.truck-types.index'));

    $response->assertOk();
    expect(preg_match('/title="Fleet Management"\s*class="active"/', $response->getContent()))->toBe(1);
});

it('highlights Fleet Management as active on the Vehicle Types page', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.vehicle-types.index'));

    $response->assertOk();
    expect(preg_match('/title="Fleet Management"\s*class="active"/', $response->getContent()))->toBe(1);
});

it('expands Oversight when on the Operations Monitor page', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.monitoring.index'));

    $response->assertOk();
    $response->assertSee('aria-expanded="true" aria-controls="sidebarGroupOversight"', false);
});

it('expands Oversight when on the Business Activity page', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('aria-expanded="true" aria-controls="sidebarGroupOversight"', false);
});

it('expands Management when on the Business Settings page', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('aria-expanded="true" aria-controls="sidebarGroupManagement"', false);
});

it('does not mark any child navigation item active while on the dashboard', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    expect(substr_count($response->getContent(), 'class="active"'))->toBe(1);
});

it('keeps all groups collapsed while on the dashboard', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('aria-expanded="false" aria-controls="sidebarGroupBusiness"', false);
    $response->assertSee('aria-expanded="false" aria-controls="sidebarGroupOversight"', false);
    $response->assertSee('aria-expanded="false" aria-controls="sidebarGroupManagement"', false);
});

it('still renders the existing bookings needs-attention badge from its existing source', function () {
    sbNavBooking(['status' => 'requested']);
    sbNavBooking(['status' => 'reviewed']);

    $expectedBadgeCount = Booking::whereIn('status', ['requested', 'reviewed'])->count();
    expect($expectedBadgeCount)->toBe(2);

    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('<span class="badge">2</span>', false);
});

it('does not let the dispatcher gain access to owner routes because of the sidebar restructure', function () {
    $dispatcher = sbNavDispatcher();

    $this->actingAs($dispatcher)->get(route('superadmin.dashboard'))->assertForbidden();
    $this->actingAs($dispatcher)->get(route('superadmin.revenue.index'))->assertForbidden();
    $this->actingAs($dispatcher)->get(route('superadmin.unit-truck.index'))->assertForbidden();
    $this->actingAs($dispatcher)->get(route('superadmin.settings.index'))->assertForbidden();
});

it('does not rely on a fragile fixed max-height for the expanded submenu', function () {
    $css = file_get_contents(public_path('superadmin/css/panel.css'));

    expect($css)->not->toContain('max-height: 240px');
    expect($css)->toContain('grid-template-rows: 0fr');
    expect($css)->toContain('grid-template-rows: 1fr');
});

it('scopes the top-level sidebar list padding so it no longer leaks into nested submenus', function () {
    $css = file_get_contents(public_path('superadmin/css/panel.css'));

    expect($css)->toContain('.sidebar > ul');
    expect($css)->not->toContain('.sidebar ul {');
});
