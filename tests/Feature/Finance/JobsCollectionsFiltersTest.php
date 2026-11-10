<?php

use App\Models\Booking;
use App\Services\Finance\JobCollectionService;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// Jobs & Collections search and live-filter UX (booking / group code search, automatic filtering, empty state).

function jcfQuery(array $extra = []): array
{
    return array_merge(['from' => finLastWeek()->toDateString(), 'to' => finLastWeek()->addDays(5)->toDateString()], $extra);
}

function jcfJobs(array $filters = []): \Illuminate\Support\Collection
{
    return app(JobCollectionService::class)->jobs(finLastWeek(), finLastWeek()->addDays(5), $filters + ['basis' => 'service']);
}

it('finds a job by its booking reference ignoring case, spaces, hyphens and #', function () {
    $unit = finUnit();
    $target = finServicedBooking($unit, '1500.00');
    finServicedBooking($unit, '2500.00');
    $code = $target->booking_code;                      // e.g. "0000001" locally, "TM-00148" style in production
    $target->forceFill(['booking_code' => 'TM-' . substr($code, -5)])->save();
    $ref = $target->fresh()->booking_code;

    foreach ([$ref, strtolower($ref), str_replace('-', '', $ref), str_replace('-', ' ', $ref), '#' . $ref, substr($ref, -3)] as $query) {
        expect(jcfJobs(['booking' => $query])->pluck('reference')->all())->toBe([$ref], "query {$query}");
    }
    expect(jcfJobs(['booking' => 'ZZ-99999']))->toHaveCount(0)
        ->and(jcfJobs(['booking' => '---']))->toHaveCount(2)    // nothing searchable: same as no search
        ->and(jcfJobs())->toHaveCount(2);
});

it('finds group bookings by group code, including members of a group that is not a normalized trip', function () {
    $unit = finUnit();
    $code = 'GRP-' . Str::upper(Str::random(6));
    $a = finServicedBooking($unit, '1000.00', ['group_code' => $code, 'pickup_address' => 'Pickup A']);
    $b = finServicedBooking($unit, '1200.00', ['group_code' => $code, 'pickup_address' => 'Pickup B']);   // no quotation: not a normalized group trip
    finServicedBooking($unit, '900.00');

    $found = jcfJobs(['booking' => strtolower(str_replace('-', '', $code))]);
    expect($found->pluck('anchor.id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('combines the search with the other filters and keeps the summary consistent', function () {
    $unit = finUnit();
    $other = finUnit();
    $paid = finServicedBooking($unit, '1500.00');
    $unpaid = finServicedBooking($unit, '800.00', ['status' => 'waiting_verification', 'completed_at' => null]);
    finServicedBooking($other, '700.00');

    $owner = finOwner();
    $page = $this->actingAs($owner)->get(route('superadmin.finance.collections.index', jcfQuery(['booking' => $paid->booking_code, 'unit_id' => $unit->id, 'status' => 'paid'])))->assertOk();
    $page->assertSee('data-jc-total="bookings">1<', false)->assertSee('data-jc-total="collected">₱1,500.00<', false)
        ->assertSee('value="' . $paid->booking_code . '"', false);            // search text preserved

    $none = $this->actingAs($owner)->get(route('superadmin.finance.collections.index', jcfQuery(['booking' => $unpaid->booking_code, 'status' => 'paid'])))->assertOk();
    $none->assertSee('data-jc-empty', false)->assertSee('No bookings match these filters.')->assertSee('data-jc-total="bookings">0<', false);

    $cleared = $this->actingAs($owner)->get(route('superadmin.finance.collections.index', jcfQuery(['unit_id' => $unit->id])))->assertOk();
    $cleared->assertSee('data-jc-total="bookings">2<', false);
});

it('renders a live filter form with a styled search box and no Apply button', function () {
    finServicedBooking(finUnit(), '1000.00');
    $html = $this->actingAs(finOwner())->get(route('superadmin.finance.collections.index', jcfQuery()))->assertOk();

    $html->assertSee('data-fin-live data-fin-target="#jcResults"', false)
        ->assertSee('type="search" name="booking" class="fin-search"', false)
        ->assertSee('placeholder="Booking or group code, e.g. TM-00148"', false)
        ->assertSee('data-fin-live-search', false)
        ->assertSee('<div id="jcResults" class="fin-live-region"', false)
        ->assertSee('finance-live-filters.js', false)
        ->assertSee('>This week</a>', false)
        ->assertSee('<noscript><button type="submit" class="oa-btn oa-btn--primary">Apply</button></noscript>', false);
    // The only remaining Apply is inside the shared date-range popover; the filter bar has none.
    $withoutNoscriptAndRange = preg_replace(['/<noscript>.*?<\/noscript>/s', '/<div class="oa-range-actions">.*?<\/div>/s'], '', $html->getContent());
    expect(substr_count($withoutNoscriptAndRange, '>Apply</button>'))->toBe(0);
});

it('answers in-place (XHR) requests with the results region and the same totals as the service', function () {
    $unit = finUnit();
    finServicedBooking($unit, '1500.00');
    finServicedBooking($unit, '2500.00');
    $expected = app(JobCollectionService::class)->totals(jcfJobs(['unit_id' => $unit->id]));

    $res = $this->actingAs(finOwner())->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->get(route('superadmin.finance.collections.index', jcfQuery(['unit_id' => $unit->id])))->assertOk();
    $res->assertSee('<div id="jcResults"', false)
        ->assertSee('data-jc-total="collected">' . $expected['collected']->format() . '<', false)
        ->assertSee('data-jc-total="bookings">' . $expected['bookings'] . '<', false);
});

it('still refuses the page to non-Owners', function () {
    foreach ([2, 3, 4, 5, 6] as $role) {
        $this->actingAs(finUser($role))->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('superadmin.finance.collections.index', ['booking' => 'TM']))->assertForbidden();
    }
});
