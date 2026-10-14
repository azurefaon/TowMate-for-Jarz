<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Route;

function bookingsOwner(): User
{
    if (! Role::find(1)) {
        $role = new Role(['name' => 'Owner']);
        $role->id = 1;
        $role->save();
    }

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function bookingsDispatcher(): User
{
    if (! Role::find(2)) {
        $role = new Role(['name' => 'Dispatcher']);
        $role->id = 2;
        $role->save();
    }

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

function bookingsCustomer(array $overrides = []): Customer
{
    return Customer::create(array_merge([
        'full_name' => 'Bookings Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'bookings-' . uniqid() . '@example.com',
    ], $overrides));
}

function bookingsTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'BKT Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function bookingsCreate(array $overrides = []): Booking
{
    $createdAt = $overrides['created_at'] ?? now();
    unset($overrides['created_at']);

    $booking = Booking::create(array_merge([
        'customer_id' => bookingsCustomer()->id,
        'truck_type_id' => bookingsTruckType()->id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'completed',
    ], $overrides));

    $booking->forceFill(['created_at' => $createdAt])->save();

    return $booking;
}

function bookingsIndex($test, array $query = [])
{
    return $test->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', $query));
}

it('owner can view bookings', function () {
    bookingsIndex($this)->assertOk()->assertSee('All towing bookings, newest first');
});

it('dispatcher cannot access owner bookings or booking details', function () {
    $booking = bookingsCreate();

    $this->actingAs(bookingsDispatcher())->get(route('superadmin.bookings.index'))->assertForbidden();
    $this->actingAs(bookingsDispatcher())->getJson(route('superadmin.bookings.show', $booking->job_code))->assertForbidden();
});

it('owner cannot mutate bookings operationally because no such route exists', function () {
    expect(Route::has('superadmin.bookings.store'))->toBeFalse();
    expect(Route::has('superadmin.bookings.update'))->toBeFalse();
    expect(Route::has('superadmin.bookings.destroy'))->toBeFalse();
});

it('shows bookings from all dates by default, newest first', function () {
    $old = bookingsCreate(['created_at' => now()->subDays(90)]);
    $recent = bookingsCreate(['created_at' => now()->subDay()]);
    $today = bookingsCreate(['created_at' => now()]);

    $response = bookingsIndex($this);

    $response->assertOk();
    $response->assertSeeInOrder([$today->job_code, $recent->job_code, $old->job_code]);
    $response->assertSee('All dates');
    $response->assertSee('Showing 1–3 of 3 bookings');
});

it('applies the today, last 7 days and last 30 days presets by booking date', function () {
    $today = bookingsCreate(['created_at' => now()->startOfDay()->addMinute()]);
    $sixDays = bookingsCreate(['created_at' => now()->subDays(6)->startOfDay()->addMinute()]);
    $eightDays = bookingsCreate(['created_at' => now()->subDays(8)]);
    $fortyDays = bookingsCreate(['created_at' => now()->subDays(40)]);

    $todayResponse = bookingsIndex($this, ['date' => 'today']);
    $todayResponse->assertSee($today->job_code)->assertDontSee($sixDays->job_code);

    $weekResponse = bookingsIndex($this, ['date' => '7d']);
    $weekResponse->assertSee($sixDays->job_code)->assertDontSee($eightDays->job_code);
    $weekResponse->assertSee('Last 7 days');

    $monthResponse = bookingsIndex($this, ['date' => '30d']);
    $monthResponse->assertSee($eightDays->job_code)->assertDontSee($fortyDays->job_code);
});

it('filters an inclusive custom range and labels it', function () {
    $inRange = bookingsCreate(['created_at' => now()->setDate(2026, 10, 5)->setTime(0, 1)]);
    $edge = bookingsCreate(['created_at' => now()->setDate(2026, 10, 11)->setTime(23, 59)]);
    $outOfRange = bookingsCreate(['created_at' => now()->setDate(2026, 10, 4)->setTime(23, 59)]);

    $response = bookingsIndex($this, ['date' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-11']);

    $response->assertOk();
    $response->assertSee($inRange->job_code)->assertSee($edge->job_code)->assertDontSee($outOfRange->job_code);
    $response->assertSee('Oct 5 – Oct 11, 2026');
});

it('falls back to all dates for an incomplete, reversed or malformed custom range', function (array $query) {
    $old = bookingsCreate(['created_at' => now()->subDays(200)]);

    $response = bookingsIndex($this, ['date' => 'custom'] + $query);

    $response->assertOk();
    $response->assertSee($old->job_code);
    $response->assertSee('data-date="all"', false);
    $response->assertSee('class="ob-clear" data-clear hidden', false);
})->with([
    'only from' => [['from' => '2026-10-05']],
    'reversed' => [['from' => '2026-10-11', 'to' => '2026-10-05']],
    'malformed' => [['from' => '2026-13-40', 'to' => 'tomorrow']],
]);

it('searches case-insensitively by customer name and address', function () {
    $customer = bookingsCustomer(['full_name' => 'Maria Santos']);
    $match = bookingsCreate(['customer_id' => $customer->id]);
    $byAddress = bookingsCreate(['pickup_address' => 'Katipunan Ave., Quezon City']);
    $other = bookingsCreate();

    bookingsIndex($this, ['search' => 'maria santos'])->assertSee($match->job_code)->assertDontSee($other->job_code);
    bookingsIndex($this, ['search' => 'KATIPUNAN'])->assertSee($byAddress->job_code)->assertDontSee($other->job_code);
});

it('searches by booking code, by the displayed padded number and by phone digits', function () {
    $coded = bookingsCreate(['booking_code' => 'TM-01048']);
    $uncoded = bookingsCreate();
    $phoneCustomer = bookingsCustomer(['phone' => '09175550142']);
    $byPhone = bookingsCreate(['customer_id' => $phoneCustomer->id]);

    bookingsIndex($this, ['search' => 'tm-01048'])->assertSee('TM-01048')->assertDontSee($uncoded->job_code);
    bookingsIndex($this, ['search' => $uncoded->job_code])->assertSee($uncoded->job_code)->assertDontSee('TM-01048');
    bookingsIndex($this, ['search' => '0917 555 0142'])->assertSee($byPhone->job_code)->assertDontSee('TM-01048');
});

it('filters each status group by its documented statuses', function (string $group, array $included, array $excluded) {
    $in = collect($included)->map(fn ($status) => bookingsCreate(['status' => $status]));
    $out = collect($excluded)->map(fn ($status) => bookingsCreate(['status' => $status]));

    $response = bookingsIndex($this, ['status' => $group]);

    $response->assertOk();
    $in->each(fn ($b) => $response->assertSee($b->job_code));
    $out->each(fn ($b) => $response->assertDontSee($b->job_code));
})->with([
    'needs review' => ['needs_review', ['requested', 'reviewed'], ['quoted', 'completed']],
    'quotation' => ['quotation', ['quoted', 'quotation_sent', 'confirmed'], ['requested', 'scheduled']],
    'scheduled' => ['scheduled', ['scheduled', 'scheduled_confirmed'], ['confirmed']],
    'on job' => ['on_job', ['accepted', 'assigned', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'], ['waiting_verification', 'completed']],
    'payment' => ['payment', ['waiting_verification', 'payment_pending', 'payment_submitted'], ['completed', 'on_job']],
    'returned' => ['returned', ['returned'], ['assigned']],
    'completed' => ['completed', ['completed'], ['cancelled']],
    'cancelled' => ['cancelled', ['cancelled'], ['rejected']],
    'rejected' => ['rejected', ['rejected'], ['cancelled']],
]);

it('renders the status menu with the approved groups and wording', function () {
    $response = bookingsIndex($this);

    $response->assertSeeInOrder([
        'All statuses', 'Needs review', 'Requested or under review', 'Quotation &amp; Confirmation',
        'Quoted, quotation sent, or confirmed', 'Scheduled', 'On job', 'Accepted through arrival at drop-off',
        'Payment &amp; verification', 'Verification or payment pending', 'Returned', 'Needs reassignment',
        'Completed', 'Cancelled', 'Rejected',
    ], false);
    $response->assertSeeInOrder(['All dates', 'Today', 'Last 7 days', 'Last 30 days', 'Custom range']);
});

it('ignores an unknown status value', function () {
    $booking = bookingsCreate();

    bookingsIndex($this, ['status' => 'bogus'])->assertSee($booking->job_code)->assertSee('data-status=""', false);
});

it('keeps search, status and date filters on pagination links', function () {
    $customer = bookingsCustomer(['full_name' => 'Paged Customer']);
    foreach (range(1, 12) as $i) {
        bookingsCreate(['customer_id' => $customer->id, 'status' => 'completed', 'created_at' => now()->subMinutes($i)]);
    }

    $response = bookingsIndex($this, ['search' => 'paged', 'status' => 'completed', 'date' => '7d']);

    $response->assertOk();
    $response->assertSee('Showing 1–10 of 12 bookings');
    expect(preg_match('/href="[^"]*page=2[^"]*"/', $response->getContent(), $m))->toBe(1);
    expect(html_entity_decode($m[0]))->toContain('search=paged')->toContain('status=completed')->toContain('date=7d');

    bookingsIndex($this, ['search' => 'paged', 'status' => 'completed', 'date' => '7d', 'page' => 2])
        ->assertSee('Showing 11–12 of 12 bookings');
});

it('shows Clear filters only while a filter is active', function () {
    bookingsCreate();

    bookingsIndex($this)->assertSee('class="ob-clear" data-clear hidden', false);
    bookingsIndex($this, ['status' => 'completed'])->assertSee('class="ob-clear" data-clear >', false);
});

it('distinguishes no bookings yet from no matching bookings', function () {
    bookingsIndex($this)
        ->assertSee('No bookings yet')
        ->assertDontSee('No bookings match these filters');

    bookingsCreate();

    bookingsIndex($this, ['search' => 'no-such-booking'])
        ->assertSee('No bookings match these filters')
        ->assertSee('Try a different search, status, or booking date.');
});

it('renders solid status badges with full labels and one details action per row', function () {
    $booking = bookingsCreate(['status' => 'scheduled_confirmed']);

    $response = bookingsIndex($this);
    $html = $response->getContent();

    $response->assertSee('<span class="ob-badge tone-indigo">Scheduled, confirmed</span>', false);
    expect(substr_count($html, 'data-booking="' . $booking->job_code . '"'))->toBe(1);
    $response->assertDontSee('class="view-btn"', false);
});

it('shows list amounts only once they are quoted or confirmed', function () {
    $requested = bookingsCreate(['status' => 'requested', 'final_total' => 1111]);
    $quotedBooking = bookingsCreate(['status' => 'quotation_sent', 'final_total' => 2222]);
    $quotation = Quotation::create([
        'quotation_number' => 'Q-LIST-1', 'source_booking_id' => $quotedBooking->id, 'customer_id' => $quotedBooking->customer_id,
        'truck_type_id' => $quotedBooking->truck_type_id, 'pickup_address' => 'Pickup A', 'dropoff_address' => 'Dropoff B',
        'distance_km' => 5, 'estimated_price' => 2222, 'status' => 'sent',
    ]);
    $quotedBooking->update(['quotation_id' => $quotation->id]);
    $paid = bookingsCreate(['status' => 'completed', 'final_total' => 3333, 'completed_at' => now()]);
    $cancelled = bookingsCreate(['status' => 'cancelled', 'final_total' => 4444]);

    $response = bookingsIndex($this);

    $response->assertDontSee('₱1,111.00', false);
    $response->assertSeeInOrder(['₱2,222.00', 'Quotation sent'], false);
    $response->assertSeeInOrder(['₱3,333.00', 'Payment confirmed'], false);
    $response->assertDontSee('₱4,444.00', false);
});

it('returns only the results fragment for in-page refreshes', function () {
    bookingsCreate();

    $response = $this->actingAs(bookingsOwner())
        ->get(route('superadmin.bookings.index'), ['X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk();
    $response->assertSee('data-results', false);
    $response->assertDontSee('<html', false);
});

it('does not expose operational status-mutation controls to the owner', function () {
    bookingsCreate();

    bookingsIndex($this)->assertDontSee('updateStatus');
});

it('sidebar bookings badge counts requested and reviewed bookings globally, independent of the bookings page date filter', function () {
    bookingsCreate(['status' => 'requested', 'created_at' => now()->subDays(9)]);
    bookingsCreate(['status' => 'reviewed', 'created_at' => now()->subDays(20)]);

    $response = bookingsIndex($this, ['date' => 'today']);

    $response->assertOk();
    $response->assertSee('No bookings match these filters');
    $response->assertSee('<span class="sidebar-count">2<span class="sidebar-sr"> pending</span></span>', false);
});
