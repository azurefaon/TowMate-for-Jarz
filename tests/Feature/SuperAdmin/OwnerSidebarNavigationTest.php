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

/** Matches a top-level sidebar row link, optionally requiring the active state. */
function sbNavLinkPattern(string $route, bool $active): string
{
    $class = $active ? 'sidebar-link active' : 'sidebar-link ';

    return '/href="' . preg_quote(route($route), '/') . '"\s*class="' . preg_quote($class, '/') . '"/';
}

/** Matches a submenu link, optionally requiring the active state and aria-current. */
function sbNavSublinkPattern(string $route, string $label, bool $active): string
{
    $tail = $active ? 'class="sidebar-sublink active"\s*aria-current="page">' : 'class="sidebar-sublink "\s*>';

    return '/href="' . preg_quote(route($route), '/') . '"\s*' . $tail . preg_quote($label, '/') . '<\/a>/';
}

function sbNavGroupPattern(string $key, bool $expanded): string
{
    return '/id="sidebarGroup' . $key . 'Toggle"\s*aria-expanded="' . ($expanded ? 'true' : 'false') . '"\s*aria-controls="sidebarGroup' . $key . '"/';
}

it('renders the Revision 2 navigation structure and wording', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder([
        '>Dashboard<',
        '>Operations<',
        '>Bookings<',
        '>Customers<',
        '>Operations Monitor<',
        '>Management<',
        '>Fleet Management<',
        '>Truck Types &amp; Rates<',
        '>Trucks<',
        '>Vehicle Types<',
        '>Personnel<',
        '>Home Assignments<',
        '>Reports &amp; Revenue<',
        '>Revenue<',
        '>Reports<',
        '>Activity Report<',
        '>Business Settings<',
    ], false);

    $response->assertDontSee('>Overview<', false);
    $response->assertDontSee('>Oversight<', false);
    $response->assertDontSee('>Business Activity<', false);
    $response->assertDontSee('sidebarGroupBusiness', false);
    $response->assertDontSee('sidebarGroupOversight', false);
});

it('keeps the logo, collapse toggle, scrolling nav and fixed footer regions', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('dispatcher/images/jarz-logo.png', false);
    $response->assertSee('onclick="toggleSidebar()"', false);
    $response->assertSee('aria-label="Collapse sidebar"', false);
    $response->assertSee('data-lucide="panel-left-close"', false);
    $response->assertSee('data-lucide="panel-left-open"', false);
    $response->assertSee('class="sidebar-nav" id="sidebarNav"', false);
    $response->assertSee('class="sidebar-footer"', false);
    expect(strpos($response->getContent(), 'class="sidebar-footer"'))
        ->toBeGreaterThan(strpos($response->getContent(), '>Activity Report<'));
});

it('links every sidebar destination to its existing route', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();

    foreach ([
        'superadmin.dashboard',
        'superadmin.bookings.index',
        'superadmin.customers.index',
        'superadmin.monitoring.index',
        'superadmin.truck-types.index',
        'superadmin.unit-truck.index',
        'superadmin.vehicle-types.index',
        'superadmin.personnel.index',
        'superadmin.home-assignments.index',
        'superadmin.revenue.index',
        'superadmin.reports.index',
        'superadmin.reports.activity',
        'superadmin.settings.index',
    ] as $route) {
        $response->assertSee('href="' . route($route) . '"', false);
    }
});

it('marks only Dashboard active and keeps both groups closed on the dashboard', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));
    $html = $response->getContent();

    $response->assertOk();
    expect(preg_match(sbNavLinkPattern('superadmin.dashboard', true), $html))->toBe(1);
    expect(substr_count($html, 'aria-current="page"'))->toBe(1);
    expect(preg_match(sbNavGroupPattern('Fleet', false), $html))->toBe(1);
    expect(preg_match(sbNavGroupPattern('Reports', false), $html))->toBe(1);
});

it('highlights top-level rows on their own pages', function (string $route) {
    $response = $this->actingAs(sbNavOwner())->get(route($route));
    $html = $response->getContent();

    $response->assertOk();
    expect(preg_match(sbNavLinkPattern($route, true), $html))->toBe(1);
    expect(substr_count($html, 'aria-current="page"'))->toBe(1);
})->with([
    'bookings' => 'superadmin.bookings.index',
    'customers' => 'superadmin.customers.index',
    'operations monitor' => 'superadmin.monitoring.index',
    'business settings' => 'superadmin.settings.index',
]);

it('opens the owning group and highlights the submenu item', function (string $route, string $label, string $group, string $otherGroup) {
    $response = $this->actingAs(sbNavOwner())->get(route($route));
    $html = $response->getContent();

    $response->assertOk();
    expect(preg_match(sbNavSublinkPattern($route, $label, true), $html))->toBe(1);
    expect(substr_count($html, 'aria-current="page"'))->toBe(1);
    expect(preg_match(sbNavGroupPattern($group, true), $html))->toBe(1);
    expect(preg_match(sbNavGroupPattern($otherGroup, false), $html))->toBe(1);
    $response->assertSee('class="sidebar-group has-active"', false);
    $response->assertSee('class="sidebar-subnav is-open" id="sidebarGroup' . $group . '"', false);
})->with([
    'truck types' => ['superadmin.truck-types.index', 'Truck Types &amp; Rates', 'Fleet', 'Reports'],
    'trucks' => ['superadmin.unit-truck.index', 'Trucks', 'Fleet', 'Reports'],
    'vehicle types' => ['superadmin.vehicle-types.index', 'Vehicle Types', 'Fleet', 'Reports'],
    'personnel' => ['superadmin.personnel.index', 'Personnel', 'Fleet', 'Reports'],
    'home assignments' => ['superadmin.home-assignments.index', 'Home Assignments', 'Fleet', 'Reports'],
    'revenue' => ['superadmin.revenue.index', 'Revenue', 'Reports', 'Fleet'],
    'reports' => ['superadmin.reports.index', 'Reports', 'Reports', 'Fleet'],
    'activity report' => ['superadmin.reports.activity', 'Activity Report', 'Reports', 'Fleet'],
]);

it('keeps Trucks highlighted on the Archived Units page', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.units.archived'));
    $html = $response->getContent();

    $response->assertOk();
    expect(preg_match(sbNavSublinkPattern('superadmin.unit-truck.index', 'Trucks', true), $html))->toBe(1);
    expect(preg_match(sbNavGroupPattern('Fleet', true), $html))->toBe(1);
});

it('still renders the existing bookings needs-attention badge from its existing source', function () {
    sbNavBooking(['status' => 'requested']);
    sbNavBooking(['status' => 'reviewed']);

    $expectedBadgeCount = Booking::whereIn('status', ['requested', 'reviewed'])->count();
    expect($expectedBadgeCount)->toBe(2);

    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('<span class="sidebar-count">2<span class="sidebar-sr"> pending</span></span>', false);
});

it('hides the bookings badge when nothing needs attention', function () {
    $response = $this->actingAs(sbNavOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertDontSee('class="sidebar-count"', false);
});

it('does not let the dispatcher gain access to owner routes because of the sidebar restructure', function () {
    $dispatcher = sbNavDispatcher();

    $this->actingAs($dispatcher)->get(route('superadmin.dashboard'))->assertForbidden();
    $this->actingAs($dispatcher)->get(route('superadmin.revenue.index'))->assertForbidden();
    $this->actingAs($dispatcher)->get(route('superadmin.unit-truck.index'))->assertForbidden();
    $this->actingAs($dispatcher)->get(route('superadmin.settings.index'))->assertForbidden();
});

it('uses the approved sidebar sizes, colours and font', function () {
    $css = file_get_contents(public_path('superadmin/css/panel.css'));

    expect($css)->toContain('width: 256px;');
    expect($css)->toContain('width: 92px;');
    expect($css)->toContain('--sb-bg: #171717;');
    expect($css)->toContain('--sb-text: #a6acb6;');
    expect($css)->toContain('--sb-accent: #facc15;');
    expect($css)->toContain('font-family: "JARZ Sidebar Inter"');
    expect(file_exists(public_path('superadmin/fonts/inter-latin-400.woff2')))->toBeTrue();
});

it('limits the collapsed rail to desktop so the mobile drawer keeps its full layout', function () {
    $css = file_get_contents(public_path('superadmin/css/panel.css'));

    expect($css)->toContain('@media (min-width: 1025px)');
    expect($css)->not->toMatch('/^\.sidebar\.collapsed \{/m');
});
