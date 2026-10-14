<?php

/*
 * Owner analytics pages (Revenue, Reports, Detailed Booking Report, Business
 * Activity): the approved layout is in place, the existing controls and links
 * are preserved, nothing unlinked was added, and the real pagination is kept.
 */

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function oauUser(int $roleId): User
{
    foreach ([1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader', 4 => 'Driver'] as $id => $name) {
        if (! Role::find($id)) {
            $role = new Role(['name' => $name]);
            $role->id = $id;
            $role->save();
        }
    }

    return User::factory()->create(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false]);
}

function oauBooking(string $status = 'requested', array $attrs = []): Booking
{
    $customer = Customer::create(['full_name' => 'OAU Customer', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'oau-' . uniqid() . '@example.test']);
    $truck = TruckType::first() ?? TruckType::create(['name' => 'OAU Truck', 'base_rate' => 1500, 'per_km_rate' => 60]);

    return Booking::create(array_merge([
        'customer_id' => $customer->id, 'truck_type_id' => $truck->id,
        'pickup_address' => 'OAU Pickup', 'dropoff_address' => 'OAU Dropoff', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 1800, 'status' => $status,
    ], $attrs));
}

it('renders Revenue in the approved layout with every existing figure and control', function () {
    $html = $this->actingAs(oauUser(1))->get(route('superadmin.revenue.index'))->assertOk()->getContent();

    expect($html)->toContain('ob-page oa-page')->toContain('analytics.css')
        ->toContain('Reporting period')->toContain('data-oa-range')->toContain('name="from"')->toContain('name="to"')->toContain('>Apply<')->toContain('>Reset<')
        ->toContain('Completed revenue, by completion date')->toContain('Completed Jobs')->toContain('Avg. Revenue / Job')
        ->toContain('VAT Collected')->toContain('Additional Fees')->toContain('Cash Received')
        ->toContain('Revenue Trend')->toContain('Revenue Breakdown')->toContain('Top Performing Units')
        ->toContain('Completed revenue, by completion date: completed, paid jobs only');
    // No filter the page did not already have.
    expect($html)->not->toContain('name="truck_type_id"')->not->toContain('name="min_amount"');
});

it('keeps the Revenue empty states', function () {
    $this->actingAs(oauUser(1))->get(route('superadmin.revenue.index', ['from' => '2031-01-01', 'to' => '2031-01-31']))
        ->assertOk()
        ->assertSee('No completed revenue recorded for this period.')
        ->assertSee('No completed jobs recorded for this period.')
        ->assertSee('No completed unit activity recorded for this period.');
});

it('renders Reports with the existing filters, drill-down links and only the PDF exports that were already linked', function () {
    oauBooking('completed', ['completed_at' => now()]);
    oauBooking('requested');

    $html = $this->actingAs(oauUser(1))->get(route('superadmin.reports.index'))->assertOk()->getContent();

    expect($html)->toContain('ob-page oa-page')->toContain('Reporting period')->toContain('Truck type')->toContain('All Truck Types')
        ->toContain('Total Bookings')->toContain('Cancelled Jobs')->toContain('Completion Rate')
        ->toContain('Bookings Trend')->toContain('Booking Performance')->toContain('Financial Summary')
        ->toContain('Truck Type Performance')->toContain('Fleet Performance')->toContain('Detailed Booking Report')
        ->toContain('Created in period')->toContain('Completed in period')
        ->toContain('Job counts above zero open the booking report for that status.');

    // Drill-down links from the pipeline.
    expect($html)->toContain('reports/bookings?')->toContain('bucket=completed');

    // Exactly the three PDF exports that were linked before; no CSV control.
    expect(substr_count($html, 'reports/export-pdf'))->toBe(3);
    expect($html)->toContain('section=financial')->toContain('section=vehicle')->toContain('section=booking');
    expect($html)->not->toContain('reports/export?')->not->toContain('Export CSV')->not->toContain('.csv');
});

it('keeps the created-date counts and the completed-date revenue apart on Reports', function () {
    $html = $this->actingAs(oauUser(1))->get(route('superadmin.reports.index'))->assertOk()->getContent();

    expect($html)->toContain('count bookings <strong>created</strong> in the period')
        ->toContain('Revenue counts jobs <strong>completed</strong> in the period');
});

it('renders the Detailed Booking Report with the existing columns, badges, links and 20 per page', function () {
    foreach (range(1, 23) as $i) {
        oauBooking('completed', ['completed_at' => now()]);
    }

    $page1 = $this->actingAs(oauUser(1))->get(route('superadmin.reports.bookings', ['bucket' => 'completed']))->assertOk();
    $html = $page1->getContent();

    foreach (['Booking #', 'Date', 'Customer', 'Truck Type', 'Unit', 'Status', 'Amount'] as $column) {
        expect($html)->toContain('>' . $column . '<');
    }
    expect($html)->toContain('Back to Reports')->toContain('oa-badge tone-')->toContain('Completed')
        ->toContain('Showing 1–20 of 23 bookings')->toContain('superadmin/bookings?search=');
    expect($page1->viewData('bookings')->perPage())->toBe(20);
    expect(substr_count($html, 'class="oa-code"'))->toBe(20);

    $this->actingAs(oauUser(1))->get(route('superadmin.reports.bookings', ['bucket' => 'completed', 'page' => 2]))
        ->assertOk()->assertSee('Showing 21–23 of 23 bookings');
});

it('keeps the booking report empty state', function () {
    $this->actingAs(oauUser(1))->get(route('superadmin.reports.bookings', ['from' => '2031-01-01', 'to' => '2031-01-31']))
        ->assertOk()->assertSee('No bookings in this range.');
});

it('renders Business Activity from real records with the existing filters and no unlinked export', function () {
    $owner = oauUser(1);
    foreach (range(1, 12) as $i) {
        AuditLog::create(['user_id' => $owner->id, 'action' => 'unit_status_override', 'entity_type' => 'Unit', 'entity_id' => $i, 'reference' => 'OAU Unit ' . $i, 'description' => 'Status set',
            'old_value' => ['status' => 'available'], 'new_value' => ['status' => 'maintenance']]);
    }

    $page = $this->actingAs($owner)->get(route('superadmin.reports.activity'))->assertOk();
    $html = $page->getContent();

    expect($html)->toContain('ob-page oa-page')->toContain('Search description or reference')
        ->toContain('All Activity')->toContain('All Records')->toContain('All Users')->toContain(now()->startOfMonth()->format('M j, Y') . ' – ' . now()->endOfMonth()->format('M j, Y'))->not->toContain('Select date range')
        ->toContain('name="category"')->toContain('name="entity_type"')->toContain('name="user_id"')
        ->toContain('>Date &amp; Time<')->toContain('>Activity<')->toContain('>Details<')->toContain('>Performed By<')
        ->toContain('12 records')->toContain('Showing 1–10 of 12 records')
        ->not->toContain('>available<')->not->toContain('>maintenance<');
    // Values come from the data, slugs shown as words, and the dropdown options are backend-generated.
    expect($html)->toContain('Available')->toContain('Maintenance')->toContain('OAU Unit');
    expect($html)->not->toContain('activity/export')->not->toContain('Export PDF');
    expect($page->viewData('logs')->perPage())->toBe(10);
});

it('keeps the Business Activity empty state and filter behaviour', function () {
    $owner = oauUser(1);

    $this->actingAs($owner)->get(route('superadmin.reports.activity'))
        ->assertOk()->assertSee('No business activity found.')->assertSee('0 records');

    $this->actingAs($owner)->get(route('superadmin.reports.activity', ['search' => 'zzzzzz', 'category' => 'update']))
        ->assertOk()->assertSee('No business activity found.');
});

it('keeps all four pages Owner-only', function () {
    foreach (['superadmin.revenue.index', 'superadmin.reports.index', 'superadmin.reports.bookings', 'superadmin.reports.activity'] as $name) {
        $this->actingAs(oauUser(2))->get(route($name))->assertForbidden();
        $this->actingAs(oauUser(4))->get(route($name))->assertForbidden();
    }
});

it('does not let wide content push the page sideways: responsive rules and a scrollable table wrapper exist', function () {
    $css = file_get_contents(public_path('superadmin/css/analytics.css'));

    expect($css)->toContain('@media (max-width: 1100px)')->toContain('@media (max-width: 760px)')->toContain('overflow-x: auto');
});

it('shows the range Business Activity is really using, and offers Reset only when something is filtered', function () {
    $owner = oauUser(1);
    $monthLabel = now()->startOfMonth()->format('M j, Y') . ' – ' . now()->endOfMonth()->format('M j, Y');

    // Default view: this month's range is shown and filled in, and there is nothing to reset.
    $default = $this->actingAs($owner)->get(route('superadmin.reports.activity'))->assertOk()->getContent();
    expect($default)->toContain($monthLabel)
        ->toContain('value="' . now()->startOfMonth()->toDateString() . '"')
        ->toContain('value="' . now()->endOfMonth()->toDateString() . '"')
        ->not->toContain('>Reset<');

    // A custom range replaces it, and Reset returns to the plain URL (this month again).
    $custom = $this->actingAs($owner)->get(route('superadmin.reports.activity', ['from' => '2026-01-05', 'to' => '2026-01-20']))->assertOk()->getContent();
    expect($custom)->toContain('Jan 5, 2026 – Jan 20, 2026')->toContain('>Reset<')->toContain('href="' . route('superadmin.reports.activity') . '"');

    // Any other filter also offers Reset, and the filtering itself is unchanged.
    $this->actingAs($owner)->get(route('superadmin.reports.activity', ['category' => 'update']))->assertOk()->assertSee('>Reset<', false)->assertSee($monthLabel);
    $this->actingAs($owner)->get(route('superadmin.reports.activity', ['search' => 'abc']))->assertOk()->assertSee('>Reset<', false);

    // Filtering behaviour: an old record is outside the default month and inside a range that covers it.
    $log = AuditLog::create(['user_id' => $owner->id, 'action' => 'unit_archived', 'entity_type' => 'Unit', 'entity_id' => 1, 'reference' => 'OAU OLD UNIT', 'description' => 'archived']);
    DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => '2026-01-10 10:00:00']);
    $this->actingAs($owner)->get(route('superadmin.reports.activity'))->assertDontSee('OAU OLD UNIT');
    $this->actingAs($owner)->get(route('superadmin.reports.activity', ['from' => '2026-01-01', 'to' => '2026-01-31']))->assertSee('OAU OLD UNIT');
});
