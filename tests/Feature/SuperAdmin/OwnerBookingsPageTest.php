<?php

use App\Models\Booking;
use App\Models\Customer;
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

function bookingsCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'Bookings Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'bookings-' . uniqid() . '@example.com',
    ]);
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

it('owner can view bookings', function () {
    $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index'))->assertOk();
});

it('dispatcher cannot access owner bookings', function () {
    $this->actingAs(bookingsDispatcher())->get(route('superadmin.bookings.index'))->assertForbidden();
});

it('owner cannot mutate bookings operationally because no such route exists', function () {
    expect(Route::has('superadmin.bookings.store'))->toBeFalse();
    expect(Route::has('superadmin.bookings.update'))->toBeFalse();
    expect(Route::has('superadmin.bookings.destroy'))->toBeFalse();
});

it('no longer renders the old period preset buttons or the filter-summary text', function () {
    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index'));

    $response->assertOk();
    $response->assertDontSee('booking-preset-btn', false);
    $response->assertDontSee('bookingPeriodTabs', false);
    $response->assertDontSee('This Week');
    $response->assertDontSee('Last 30 Days');
    $response->assertDontSee('Showing Today');
});

it('defaults to today when no filters are supplied', function () {
    bookingsCreate(['final_total' => 4321.00, 'created_at' => now()]);
    bookingsCreate(['final_total' => 9999.00, 'created_at' => now()->subDays(5)]);

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index'));

    $response->assertOk();
    $response->assertSee('₱4,321.00');
    $response->assertDontSee('₱9,999.00');
});

it('continues to support the existing week and month period presets', function () {
    $booking = bookingsCreate(['final_total' => 5555.00, 'created_at' => now()->subDays(10)]);

    $weekResponse = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', ['period' => 'week']));
    $weekResponse->assertOk();
    $weekResponse->assertDontSee('₱5,555.00');

    $monthResponse = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', ['period' => 'month']));
    $monthResponse->assertOk();
    $monthResponse->assertSee('₱5,555.00');
});

it('filters bookings by an explicit custom date range inclusive of both endpoints', function () {
    $inRange = bookingsCreate(['final_total' => 1111.00, 'created_at' => now()->subDays(3)]);
    $outOfRange = bookingsCreate(['final_total' => 2222.00, 'created_at' => now()->subDays(30)]);

    $from = now()->subDays(3)->toDateString();
    $to = now()->toDateString();

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', [
        'from' => $from,
        'to' => $to,
    ]));

    $response->assertOk();
    $response->assertSee('₱1,111.00');
    $response->assertDontSee('₱2,222.00');
});

it('falls back to the default period when only one side of the date range is supplied', function () {
    bookingsCreate(['final_total' => 3333.00, 'created_at' => now()]);

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', [
        'from' => now()->toDateString(),
    ]));

    $response->assertOk();
    $response->assertSee('₱3,333.00');
});

it('continues to support filtering bookings by status', function () {
    bookingsCreate(['final_total' => 6001.00, 'status' => 'completed', 'created_at' => now()]);
    bookingsCreate(['final_total' => 6002.00, 'status' => 'scheduled', 'created_at' => now()]);

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', [
        'period' => 'today',
        'status' => 'completed',
    ]));

    $response->assertOk();
    $response->assertSee('₱6,001.00');
    $response->assertDontSee('₱6,002.00');
});

it('renders the status filter as a single dropdown with the currently supported values', function () {
    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', ['status' => 'on_job']));

    $response->assertOk();
    $response->assertSee('id="bookingStatusSelect"', false);
    $response->assertSee('<option value="" >All statuses</option>', false);
    $response->assertSee('<option value="needs_attention" >Needs Attention</option>', false);
    $response->assertSee('<option value="completed" >Completed</option>', false);
    $response->assertSee('<option value="scheduled" >Scheduled</option>', false);
    $response->assertSee('<option value="on_job" selected>On Job</option>', false);
    $response->assertSee('<option value="returned" >Returned</option>', false);
    $response->assertDontSee('booking-status-tab', false);
});

it('filters bookings to needs-attention records combining requested and reviewed statuses', function () {
    bookingsCreate(['final_total' => 8101.00, 'status' => 'requested', 'created_at' => now()]);
    bookingsCreate(['final_total' => 8102.00, 'status' => 'reviewed', 'created_at' => now()]);
    bookingsCreate(['final_total' => 8103.00, 'status' => 'completed', 'created_at' => now()]);
    bookingsCreate(['final_total' => 8104.00, 'status' => 'scheduled', 'created_at' => now()]);

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', ['status' => 'needs_attention']));

    $response->assertOk();
    $response->assertSee('<option value="needs_attention" selected>Needs Attention</option>', false);
    $response->assertSee('₱8,101.00');
    $response->assertSee('₱8,102.00');
    $response->assertDontSee('₱8,103.00');
    $response->assertDontSee('₱8,104.00');
});

it('composes the needs-attention filter with search', function () {
    $customer = Customer::create([
        'full_name' => 'Needs Attention Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'bookings-needs-attention-' . uniqid() . '@example.com',
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => bookingsTruckType()->id,
        'pickup_address' => 'Pickup A', 'dropoff_address' => 'Dropoff B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 8201.00,
        'status' => 'requested',
    ]);

    bookingsCreate(['final_total' => 8202.00, 'status' => 'reviewed', 'created_at' => now()]);

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', [
        'search' => $customer->full_name,
        'status' => 'needs_attention',
    ]));

    $response->assertOk();
    $response->assertSee('₱8,201.00');
    $response->assertDontSee('₱8,202.00');
    $response->assertSee('<option value="needs_attention" selected>Needs Attention</option>', false);
});

it('composes the needs-attention filter with a custom date range', function () {
    bookingsCreate(['final_total' => 8301.00, 'status' => 'requested', 'created_at' => now()->subDays(2)]);
    bookingsCreate(['final_total' => 8302.00, 'status' => 'requested', 'created_at' => now()->subDays(20)]);

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', [
        'from' => now()->subDays(2)->toDateString(),
        'to' => now()->toDateString(),
        'status' => 'needs_attention',
    ]));

    $response->assertOk();
    $response->assertSee('₱8,301.00');
    $response->assertDontSee('₱8,302.00');
    $response->assertSee('<option value="needs_attention" selected>Needs Attention</option>', false);
});

it('supports each currently supported status option from the dropdown', function () {
    bookingsCreate(['final_total' => 7101.00, 'status' => 'scheduled', 'created_at' => now()]);
    bookingsCreate(['final_total' => 7102.00, 'status' => 'on_job', 'created_at' => now()]);
    bookingsCreate(['final_total' => 7103.00, 'status' => 'returned', 'returned_at' => now(), 'created_at' => now()]);

    $scheduled = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', ['status' => 'scheduled']));
    $scheduled->assertOk()->assertSee('₱7,101.00')->assertDontSee('₱7,102.00');

    $onJob = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', ['status' => 'on_job']));
    $onJob->assertOk()->assertSee('₱7,102.00')->assertDontSee('₱7,101.00');

    $returned = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', ['status' => 'returned']));
    $returned->assertOk()->assertSee('₱7,103.00')->assertDontSee('₱7,101.00');
});

it('persists the status filter alongside an active search term', function () {
    $customer = Customer::create([
        'full_name' => 'Persisted Filter Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'bookings-persist-' . uniqid() . '@example.com',
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => bookingsTruckType()->id,
        'pickup_address' => 'Pickup A', 'dropoff_address' => 'Dropoff B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 7201.00,
        'status' => 'completed',
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => bookingsTruckType()->id,
        'pickup_address' => 'Pickup A', 'dropoff_address' => 'Dropoff B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 7202.00,
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', [
        'search' => $customer->full_name,
        'status' => 'completed',
    ]));

    $response->assertOk();
    $response->assertSee('₱7,201.00');
    $response->assertDontSee('₱7,202.00');
    $response->assertSee('<option value="completed" selected>Completed</option>', false);
});

it('persists the status filter alongside a custom date range', function () {
    bookingsCreate(['final_total' => 7301.00, 'status' => 'completed', 'created_at' => now()->subDays(2)]);
    bookingsCreate(['final_total' => 7302.00, 'status' => 'scheduled', 'created_at' => now()->subDays(2)]);

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', [
        'from' => now()->subDays(2)->toDateString(),
        'to' => now()->toDateString(),
        'status' => 'completed',
    ]));

    $response->assertOk();
    $response->assertSee('₱7,301.00');
    $response->assertDontSee('₱7,302.00');
    $response->assertSee('<option value="completed" selected>Completed</option>', false);
});

it('continues to support searching bookings by customer name', function () {
    $customer = Customer::create([
        'full_name' => 'Uniquely Named Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'bookings-search-' . uniqid() . '@example.com',
    ]);
    $truckType = bookingsTruckType();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup A', 'dropoff_address' => 'Dropoff B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 7000.00,
        'status' => 'completed',
    ]);

    bookingsCreate(['final_total' => 8000.00]);

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', [
        'search' => $customer->full_name,
    ]));

    $response->assertOk();
    $response->assertSee('₱7,000.00');
    $response->assertDontSee('₱8,000.00');
});

it('renders an empty date range safely', function () {
    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index', [
        'from' => now()->addDays(10)->toDateString(),
        'to' => now()->addDays(11)->toDateString(),
    ]));

    $response->assertOk();
    $response->assertSee('No bookings found for the selected filters.');
});

it('does not expose operational status-mutation controls to the owner', function () {
    bookingsCreate();

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index'));

    $response->assertOk();
    $response->assertDontSee('updateStatus');
});

it('sidebar bookings badge counts requested and reviewed bookings globally, independent of the bookings page date filter', function () {
    bookingsCreate(['status' => 'requested', 'created_at' => now()->subDays(9)]);
    bookingsCreate(['status' => 'reviewed', 'created_at' => now()->subDays(20)]);

    $expectedBadgeCount = Booking::whereIn('status', ['requested', 'reviewed'])->count();
    expect($expectedBadgeCount)->toBe(2);

    $response = $this->actingAs(bookingsOwner())->get(route('superadmin.bookings.index'));

    $response->assertOk();
    $response->assertSee('No bookings found for the selected filters.');
    $response->assertSee('<span class="badge">2</span>', false);
});
