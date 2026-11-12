<?php

use App\Models\Finance\ClientReviewItem;
use App\Models\Unit;
use App\Services\Finance\Demo\FinanceDemoGuard;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/finance_helpers.php';

// Finance navigation: Job Crew + Driver Records are tabs of "Crew & Driver Records"; Commission (Draft) + Commission
// Statements are tabs of "Commissions". Pages, URLs, controllers, permissions and records stay separate and unchanged.

afterEach(function () {
    FinanceDemoGuard::$simulatedDatabaseName = null;
    config(['finance.demo.enabled' => false]);
});

/** The Owner sidebar's Finance links, as [label => href]. */
function fnvSidebar(string $html): array
{
    preg_match('/<aside class="sidebar".*?<\/aside>/s', $html, $aside);
    preg_match('/id="sidebarGroupFinance".*?<\/ul>/s', $aside[0] ?? '', $finance);
    preg_match_all('/<a href="([^"]+)"\s*class="sidebar-sublink( active)?\s*"[^>]*>([^<]+)<\/a>/', $finance[0] ?? '', $m, PREG_SET_ORDER);

    return collect($m)->mapWithKeys(fn ($x) => [html_entity_decode(trim($x[3])) => ['href' => html_entity_decode($x[1]), 'active' => trim($x[2]) === 'active']])->all();
}

/** The tab links inside one tab bar, as [label => ['href' =>, 'active' =>]]. */
function fnvTabs(string $html, string $group): array
{
    preg_match('/<nav class="fin-tabs"[^>]*data-fin-tabs="' . $group . '">(.*?)<\/nav>/s', $html, $nav);
    preg_match_all('/<a href="([^"]+)"\s*class="fin-tab( is-active)?"([^>]*)>([^<]+)<\/a>/', $nav[1] ?? '', $m, PREG_SET_ORDER);

    return collect($m)->mapWithKeys(fn ($x) => [html_entity_decode(trim($x[4])) => ['href' => html_entity_decode($x[1]), 'active' => trim($x[2]) === 'is-active', 'current' => str_contains($x[3], 'aria-current="page"')]])->all();
}

it('shows eight Finance sidebar entries, with the four grouped pages as two', function () {
    $links = fnvSidebar($this->actingAs(finOwner())->get(route('superadmin.finance.overview'))->assertOk()->getContent());

    expect(array_keys($links))->toBe(['Finance Overview', 'Jobs & Collections', 'Unit Funds', 'Expenses', 'Crew & Driver Records', 'Commissions', 'Finance Reports', 'Client Review'])
        ->and($links['Crew & Driver Records']['href'])->toBe(route('superadmin.finance.crew.index'))
        ->and($links['Commissions']['href'])->toBe(route('superadmin.finance.commission.index'))
        ->and($links['Finance Overview']['active'])->toBeTrue()
        ->and(collect($links)->where('active', true))->toHaveCount(1);
});

it('keeps every page URL and route name, so bookmarks and links keep working', function () {
    foreach ([
        'superadmin.finance.crew.index' => '/superadmin/finance/job-crew',
        'superadmin.finance.driver-records.index' => '/superadmin/finance/driver-records',
        'superadmin.finance.commission.index' => '/superadmin/finance/commission',
        'superadmin.finance.statements.index' => '/superadmin/finance/commission-statements',
        'superadmin.finance.client-review.index' => '/superadmin/finance/client-review',
    ] as $name => $uri) {
        expect(route($name, [], false))->toBe($uri);
    }
    $owner = finOwner();
    foreach (['crew.index', 'driver-records.index', 'commission.index', 'statements.index'] as $name) {
        $this->actingAs($owner)->get(route("superadmin.finance.{$name}"))->assertOk();
    }
});

it('highlights the module in the sidebar and the page in the tab bar on every page of a group', function () {
    $owner = finOwner();
    $unit = finUnit();
    $booking = finServicedBooking($unit, '1000.00');
    $week = finLastWeek()->toDateString();

    $cases = [
        ['superadmin.finance.crew.index', [], 'Crew & Driver Records', 'crew', 'Job Crew'],
        ['superadmin.finance.crew.show', [$booking->id], 'Crew & Driver Records', null, null],
        ['superadmin.finance.driver-records.index', [], 'Crew & Driver Records', 'crew', 'Driver Records'],
        ['superadmin.finance.commission.index', [], 'Commissions', 'commission', 'Draft Breakdown'],
        ['superadmin.finance.statements.index', [], 'Commissions', 'commission', 'Statements & Payouts'],
    ];
    foreach ($cases as [$route, $params, $sidebarLabel, $group, $tab]) {
        $html = $this->actingAs($owner)->get(route($route, $params))->assertOk()->getContent();
        $sidebar = fnvSidebar($html);
        expect(collect($sidebar)->where('active', true)->keys()->all())->toBe([$sidebarLabel], $route);
        if ($group) {
            $tabs = fnvTabs($html, $group);
            expect(collect($tabs)->where('active', true)->keys()->all())->toBe([$tab], $route)
                ->and(collect($tabs)->where('current', true)->keys()->all())->toBe([$tab], "{$route} aria-current");
        }
    }
    // The statement pages (prepare, detail) stay inside Commissions.
    $s = finReviewStatement($owner);
    foreach ([['superadmin.finance.statements.show', [$s['statement']->id]], ['superadmin.finance.statements.create', ['week' => $week, 'unit_id' => $s['unit']->id]]] as [$route, $params]) {
        expect(collect(fnvSidebar($this->actingAs($owner)->get(route($route, $params))->assertOk()->getContent()))->where('active', true)->keys()->all())->toBe(['Commissions'], $route);
    }
});

it('offers both tabs of each group, as separate pages', function () {
    $owner = finOwner();
    $crew = fnvTabs($this->actingAs($owner)->get(route('superadmin.finance.crew.index'))->getContent(), 'crew');
    $commission = fnvTabs($this->actingAs($owner)->get(route('superadmin.finance.commission.index'))->getContent(), 'commission');

    expect(array_keys($crew))->toBe(['Job Crew', 'Driver Records'])
        ->and(parse_url($crew['Driver Records']['href'], PHP_URL_PATH))->toBe(route('superadmin.finance.driver-records.index', [], false))
        ->and(array_keys($commission))->toBe(['Draft Breakdown', 'Statements & Payouts'])
        ->and(parse_url($commission['Statements & Payouts']['href'], PHP_URL_PATH))->toBe(route('superadmin.finance.statements.index', [], false));
});

it('carries the chosen unit and week across the tabs, and ignores anything invalid', function () {
    $owner = finOwner();
    $unit = finUnit();
    $monday = finLastWeek();
    $q = fn (string $href) => (function () use ($href) { parse_str((string) parse_url($href, PHP_URL_QUERY), $out); return $out; })();

    $crewTabs = fnvTabs($this->actingAs($owner)->get(route('superadmin.finance.crew.index', ['week' => $monday->toDateString(), 'unit_id' => $unit->id]))->getContent(), 'crew');
    expect($q($crewTabs['Driver Records']['href']))->toBe(['unit_id' => (string) $unit->id, 'from' => $monday->toDateString(), 'to' => $monday->addDays(5)->toDateString()]);

    $drTabs = fnvTabs($this->actingAs($owner)->get(route('superadmin.finance.driver-records.index', ['from' => $monday->toDateString(), 'to' => $monday->addDays(5)->toDateString(), 'unit_id' => $unit->id]))->getContent(), 'crew');
    expect($q($drTabs['Job Crew']['href']))->toBe(['unit_id' => (string) $unit->id, 'week' => $monday->toDateString()]);

    $cmTabs = fnvTabs($this->actingAs($owner)->get(route('superadmin.finance.commission.index', ['week' => $monday->toDateString(), 'unit_id' => $unit->id]))->getContent(), 'commission');
    expect($q($cmTabs['Statements & Payouts']['href']))->toBe(['unit_id' => (string) $unit->id]);

    $stTabs = fnvTabs($this->actingAs($owner)->get(route('superadmin.finance.statements.index', ['unit_id' => $unit->id]))->getContent(), 'commission');
    expect($q($stTabs['Draft Breakdown']['href']))->toBe(['unit_id' => (string) $unit->id]);

    $bad = fnvTabs($this->actingAs($owner)->get(route('superadmin.finance.crew.index', ['week' => 'not-a-date', 'unit_id' => 'abc']))->getContent(), 'crew');
    expect($q($bad['Driver Records']['href']))->toBe([]);
});

it('keeps every grouped page Owner-only, with its own permissions', function () {
    $owner = finOwner();
    $routes = ['superadmin.finance.crew.index', 'superadmin.finance.driver-records.index', 'superadmin.finance.commission.index', 'superadmin.finance.statements.index'];
    foreach ([2, 3, 4, 5, 6] as $role) {
        foreach ($routes as $route) {
            $this->actingAs(finUser($role))->get(route($route))->assertForbidden();
        }
    }
    auth()->logout();
    foreach ($routes as $route) {
        $this->get(route($route))->assertRedirect();
    }
    // Writes keep their own, separate permissions (an authenticated non-Owner cannot use them from any tab).
    $unit = finUnit();
    $booking = finServicedBooking($unit, '1000.00');
    $this->actingAs(finUser(2))->post(route('superadmin.finance.crew.confirm-pending', $booking->id), ['unit_id' => $unit->id])->assertForbidden();
    $this->actingAs(finUser(2))->post(route('superadmin.finance.statements.store'), ['unit_id' => $unit->id, 'week' => finLastWeek()->toDateString()])->assertForbidden();
    $this->actingAs($owner)->get(route('superadmin.finance.crew.index'))->assertOk();
});

it('lists Client Review as real Owner functionality, never as demo, and keeps its history', function () {
    $owner = finOwner();
    $before = ClientReviewItem::count();
    $closed = ClientReviewItem::first();
    DB::table('finance_client_review_items')->where('id', $closed->id)->update(['status' => 'closed']);

    $html = $this->actingAs($owner)->get(route('superadmin.finance.client-review.index'))->assertOk()
        ->assertSee('Commission sharing base')->assertSee('data-cr-count="pending">10<', false)->getContent();
    expect(fnvSidebar($html))->toHaveKey('Client Review')
        ->and(collect(fnvSidebar($html))->where('active', true)->keys()->all())->toBe(['Client Review'])
        ->and(ClientReviewItem::count())->toBe($before)
        ->and($html)->not->toContain('data-demo-banner')->not->toContain('DEMONSTRATION');
    // each item names its status once (the "Pending client decision" label used to be printed twice)
    expect($html)->not->toMatch('/Pending client decision · C2/')
        ->and($html)->toMatch('/C2 · <span class="fin-status[^"]*" data-cr-status="pending">Pending client decision<\/span>/')
        ->and($html)->toMatch('/Pending client decision · C1 · <span class="fin-status[^"]*" data-cr-status="closed">Closed<\/span>/');   // a closed item still says what it was
    foreach ([2, 3, 4, 5, 6] as $role) {
        $this->actingAs(finUser($role))->get(route('superadmin.finance.client-review.index'))->assertForbidden();
    }
});

it('keeps the demo page and reset unreachable in production for every role, with no demo sidebar entry', function () {
    $owner = finOwner();
    $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);   // production enforces CSRF; this test is about the 404
    config(['finance.demo.enabled' => true]);   // even when the flag is on: production is refused
    $this->app['env'] = 'production';
    FinanceDemoGuard::$simulatedDatabaseName = 'towmate_finance_demo';
    expect(FinanceDemoGuard::enabled())->toBeFalse();

    // The Owner is answered 404; every other role is refused (403) by the Owner-area guard that wraps all /superadmin URLs.
    $this->actingAs($owner)->get(route('superadmin.finance.demo.index'))->assertNotFound();
    $this->actingAs($owner)->post(route('superadmin.finance.demo.reset'), ['confirm' => 'RESET DEMO'])->assertNotFound();
    foreach ([finUser(2), finUser(3), finUser(5)] as $user) {
        $this->actingAs($user)->get(route('superadmin.finance.demo.index'))->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.demo.reset'), ['confirm' => 'RESET DEMO'])->assertForbidden();
    }
    $html = $this->actingAs($owner)->get(route('superadmin.finance.overview'))->assertOk()->assertDontSee('data-demo-banner', false)->getContent();
    expect(fnvSidebar($html))->not->toHaveKey('Finance Demo');
    $this->actingAs($owner)->get(route('superadmin.finance.client-review.index'))->assertOk()->assertDontSee('DEMONSTRATION');

    // a genuine demo installation still gets the page (Owner only) and the sidebar entry
    $this->app['env'] = 'testing';
    config(['finance.demo.enabled' => true]);
    expect(FinanceDemoGuard::enabled())->toBeTrue();
    $this->actingAs($owner)->get(route('superadmin.finance.demo.index'))->assertOk();
    $this->actingAs(finUser(2))->get(route('superadmin.finance.demo.index'))->assertForbidden();
    expect(fnvSidebar($this->actingAs($owner)->get(route('superadmin.finance.overview'))->getContent()))->toHaveKey('Finance Demo');
});

it('has no stale development wording and shows the crew legend once', function () {
    $owner = finOwner();
    $a = finUnit();
    $b = finUnit();
    $booking = finServicedBooking($a, '1000.00');

    $this->actingAs($owner)->get(route('superadmin.finance.statements.index'))->assertOk()
        ->assertDontSee('later phase')->assertSee('Payouts are recorded on each approved statement.');
    $page = $this->actingAs($owner)->get(route('superadmin.finance.crew.show', $booking->id))->assertOk()->getContent();
    expect(substr_count($page, 'data-crew-legend'))->toBe(1)
        ->and(substr_count($page, 'means Dispatch put the person on the job'))->toBe(1);
    $this->actingAs($owner)->get(route('superadmin.finance.collections.index', ['from' => finLastWeek()->toDateString(), 'to' => finLastWeek()->addDays(5)->toDateString()]))->assertOk()
        ->assertDontSee('job crew phase');
    $this->actingAs($owner)->get(route('superadmin.finance.commission.index'))->assertOk()->assertSee('Statements &amp; Payouts', false)->assertDontSee('under Commission Statements');
});
