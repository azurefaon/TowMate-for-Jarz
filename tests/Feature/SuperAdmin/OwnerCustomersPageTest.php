<?php

/*
 * Owner → Customers is read-only: list with search and filters, a customer
 * detail page with a paginated booking history, owner-only access, and no
 * way to change a customer from either page.
 */

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Route;

function ocUser(int $roleId): User
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

function ocCustomer(string $name, array $attrs = []): Customer
{
    return Customer::create(array_merge([
        'full_name' => $name,
        'phone' => '09' . random_int(100000000, 999999999),
        'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        'customer_type' => 'regular',
    ], $attrs));
}

function ocBooking(Customer $customer, array $attrs = []): Booking
{
    $type = TruckType::first() ?? TruckType::create(['name' => 'OC Truck', 'base_rate' => 1500, 'per_km_rate' => 75]);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $type->id,
        'age' => 30,
        'pickup_address' => 'Quezon City Circle', 'pickup_lat' => 14.676, 'pickup_lng' => 121.0437,
        'dropoff_address' => 'SM Megamall', 'dropoff_lat' => 14.5842, 'dropoff_lng' => 121.0568,
        'distance_km' => 6, 'base_rate' => 1500, 'per_km_rate' => 75,
        'computed_total' => 1950, 'final_total' => 1950, 'vat_exclusive_total' => 1950,
        'status' => 'requested',
    ], $attrs));
}

it('registers the Owner customers routes behind the Owner middleware', function () {
    foreach (['superadmin.customers.index', 'superadmin.customers.show'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull();
        expect($route->methods())->toBe(['GET', 'HEAD']);
        expect($route->gatherMiddleware())->toContain('auth')->toContain('role:1');
    }
});

it('lets the owner open the list and a customer, and denies every other role and guests', function () {
    $customer = ocCustomer('Access Customer');

    foreach ([route('superadmin.customers.index'), route('superadmin.customers.show', $customer)] as $url) {
        $this->actingAs(ocUser(1))->get($url)->assertOk();

        foreach ([2, 3, 4] as $roleId) {
            $this->actingAs(ocUser($roleId))->get($url)->assertForbidden();
        }

        auth()->logout();
        $this->get($url)->assertRedirect();
    }
});

it('has no way to change a customer: no write routes and no forms except the search', function () {
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'superadmin/customers')) {
            expect($route->methods())->not->toContain('POST')->not->toContain('PATCH')->not->toContain('PUT')->not->toContain('DELETE');
        }
    }

    $customer = ocCustomer('Read Only Customer', ['risk_level' => 'watchlist', 'risk_reason' => 'Late payments']);
    $owner = ocUser(1);

    $list = $this->actingAs($owner)->get(route('superadmin.customers.index'))->getContent();
    $show = $this->actingAs($owner)->get(route('superadmin.customers.show', $customer))->getContent();

    // The list has its GET search form; the only POST form on either page is the layout's logout.
    expect(substr_count($list, 'method="GET"'))->toBe(1);
    expect(preg_match_all('/<form[^>]*method="POST"[^>]*>/i', $list))->toBe(1);
    expect(preg_match_all('/<form[^>]*method="POST"[^>]*>/i', $show))->toBe(1);
    expect($show)->not->toContain('method="GET"')->not->toContain('name="risk_level"')->not->toContain('name="_method"');
});

it('shows name, contact, type, risk, booking count and date joined on the list', function () {
    $owner = ocUser(1);
    $customer = ocCustomer('Maria Santos', ['phone' => '09171234567', 'email' => 'maria@example.test', 'customer_type' => 'senior', 'risk_level' => 'blacklisted']);
    ocBooking($customer);
    ocBooking($customer);

    $this->actingAs($owner)->get(route('superadmin.customers.index'))
        ->assertOk()
        ->assertSee('Maria Santos')
        ->assertSee('maria@example.test')
        ->assertSee('Senior')
        ->assertSee('Blacklisted')
        ->assertSee($customer->created_at->format('M j, Y'))
        ->assertSee('Showing 1–1 of 1 customer');

    $html = $this->actingAs($owner)->get(route('superadmin.customers.index'))->getContent();
    expect($html)->toMatch('/<td>2<\/td>/');
});

it('searches by name, phone and email, and filters by risk level and type', function () {
    $owner = ocUser(1);
    ocCustomer('Alice Watch', ['phone' => '09170000001', 'email' => 'alice@example.test', 'risk_level' => 'watchlist', 'customer_type' => 'pwd']);
    ocCustomer('Bob Black', ['phone' => '09170000002', 'email' => 'bob@sample.test', 'risk_level' => 'blacklisted', 'customer_type' => 'regular']);
    ocCustomer('Carol Clear', ['phone' => '09170000003', 'email' => 'carol@example.test', 'customer_type' => 'senior']);

    $names = fn (array $query) => collect(['Alice Watch', 'Bob Black', 'Carol Clear'])
        ->filter(fn ($name) => str_contains($this->actingAs($owner)->get(route('superadmin.customers.index', $query))->getContent(), $name))
        ->values()->all();

    expect($names([]))->toBe(['Alice Watch', 'Bob Black', 'Carol Clear']);
    expect($names(['search' => 'alice']))->toBe(['Alice Watch']);
    expect($names(['search' => 'SAMPLE.test']))->toBe(['Bob Black']);
    expect($names(['search' => '0917000000' . '3']))->toBe(['Carol Clear']);
    expect($names(['risk_level' => 'watchlist']))->toBe(['Alice Watch']);
    expect($names(['risk_level' => 'blacklisted']))->toBe(['Bob Black']);
    expect($names(['customer_type' => 'senior']))->toBe(['Carol Clear']);
    expect($names(['risk_level' => 'blacklisted', 'customer_type' => 'pwd']))->toBe([]);
    // Unknown filter values are ignored, not applied.
    expect($names(['risk_level' => 'nonsense', 'customer_type' => 'nonsense']))->toBe(['Alice Watch', 'Bob Black', 'Carol Clear']);
    // LIKE wildcards in the search are literal text.
    expect($names(['search' => '%']))->toBe([]);
});

it('paginates the list and keeps filters in the page links', function () {
    $owner = ocUser(1);
    foreach (range(1, 12) as $i) {
        ocCustomer(sprintf('Page Customer %02d', $i), ['risk_level' => 'watchlist']);
    }

    $first = $this->actingAs($owner)->get(route('superadmin.customers.index', ['risk_level' => 'watchlist']))->getContent();
    expect($first)->toContain('Page Customer 01')->toContain('Page Customer 10')->not->toContain('Page Customer 11')
        ->toContain('Showing 1–10 of 12 customers')->toContain('risk_level=watchlist');

    $second = $this->actingAs($owner)->get(route('superadmin.customers.index', ['risk_level' => 'watchlist', 'page' => 2]))->getContent();
    expect($second)->toContain('Page Customer 11')->toContain('Page Customer 12')->not->toContain('Page Customer 01')
        ->toContain('Showing 11–12 of 12 customers');
});

it('shows clear empty states for no customers and for no matches', function () {
    $owner = ocUser(1);

    $this->actingAs($owner)->get(route('superadmin.customers.index'))->assertOk()->assertSee('No customers yet');

    ocCustomer('Only Customer');
    $this->actingAs($owner)->get(route('superadmin.customers.index', ['search' => 'zzzzz']))
        ->assertOk()->assertSee('No customers match these filters')->assertSee('Clear filters');
});

it('shows customer details, risk reason and the booking history with truck and unit handling', function () {
    $owner = ocUser(1);
    $customer = ocCustomer('Detail Customer', ['phone' => '09175550000', 'risk_level' => 'blacklisted', 'risk_reason' => 'Repeated no-shows', 'blacklisted_at' => now()->subDays(3)]);
    $unit = Unit::create(['name' => 'Unit Detail 9', 'plate_number' => 'DET-' . uniqid(), 'truck_type_id' => (TruckType::first() ?? TruckType::create(['name' => 'OC Truck', 'base_rate' => 1500, 'per_km_rate' => 75]))->id, 'status' => 'available']);
    ocBooking($customer, ['status' => 'completed', 'assigned_unit_id' => $unit->id]);
    ocBooking($customer, ['status' => 'requested']);

    $this->actingAs($owner)->get(route('superadmin.customers.show', $customer))
        ->assertOk()
        ->assertSee('Detail Customer')
        ->assertSee('0917 555 0000', false)
        ->assertSee('Blacklisted')
        ->assertSee('Repeated no-shows')
        ->assertSee('Blacklisted on:')
        ->assertSee('Unit Detail 9')
        ->assertSee('Not assigned')
        ->assertSee('Showing 1–2 of 2 bookings');
});

it('uses the Owner Bookings amount rules: a dash while no figure is confirmed, never a computed total', function () {
    $owner = ocUser(1);
    $customer = ocCustomer('Amount Customer');
    ocBooking($customer, ['status' => 'requested', 'final_total' => 9999, 'computed_total' => 9999]);

    $html = $this->actingAs($owner)->get(route('superadmin.customers.show', $customer))->assertOk()->getContent();

    expect($html)->not->toContain('9,999.00')->toContain('No amount yet');
});

it('shows an empty state and no risk note for a clear customer with no bookings, and paginates bookings', function () {
    $owner = ocUser(1);
    $clear = ocCustomer('Clear Customer');

    $this->actingAs($owner)->get(route('superadmin.customers.show', $clear))
        ->assertOk()->assertSee('No bookings yet')->assertSee('Clear')->assertDontSee('Risk reason:');

    $busy = ocCustomer('Busy Customer');
    foreach (range(1, 12) as $i) {
        ocBooking($busy);
    }
    $this->actingAs($owner)->get(route('superadmin.customers.show', $busy))->assertSee('Showing 1–10 of 12 bookings');
    $this->actingAs($owner)->get(route('superadmin.customers.show', ['customer' => $busy, 'page' => 2]))->assertSee('Showing 11–12 of 12 bookings');
});

it('returns 404 for an unknown or non-numeric customer and has a responsive layout hook', function () {
    $owner = ocUser(1);

    $this->actingAs($owner)->get(route('superadmin.customers.show', 999999))->assertNotFound();
    $this->actingAs($owner)->get('/superadmin/customers/abc')->assertNotFound();

    $css = file_get_contents(public_path('superadmin/css/customers.css'));
    expect($css)->toContain('@media (max-width: 760px)');
});
