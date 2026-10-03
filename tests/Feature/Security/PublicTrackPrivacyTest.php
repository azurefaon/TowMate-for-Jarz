<?php

use App\Http\Controllers\PublicTrackController;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Public tracker privacy â€” /track-booking must not reveal anything from a
 * (sequential) booking reference alone.
 */

const PTP_PHONE = '+639171234567';
const PTP_LAST4 = '4567';

function ptpRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ptpCustomer(string $phone = PTP_PHONE): Customer
{
    $user = User::factory()->create(['role_id' => ptpRole(5, 'Customer')->id, 'status' => 'active']);

    return Customer::create([
        'user_id'   => $user->id,
        'full_name' => 'Private Customer Name',
        'phone'     => $phone,
        'email'     => 'private.customer@example.com',
    ]);
}

function ptpTruckType(): TruckType
{
    return TruckType::create([
        'name'        => 'PTP Flatbed ' . fake()->unique()->numerify('###'),
        'base_rate'   => 1500,
        'per_km_rate' => 60,
        'status'      => 'active',
    ]);
}

function ptpUnit(TruckType $truck): Unit
{
    $leader = User::factory()->create([
        'role_id' => ptpRole(3, 'Team Leader')->id,
        'status'  => 'active',
        'name'    => 'Leaky Leader Name',
    ]);

    return Unit::create([
        'name'           => 'PTP Unit 7',
        'plate_number'   => 'PTP-9911',
        'truck_type_id'  => $truck->id,
        'team_leader_id' => $leader->id,
        'status'         => 'available',
    ]);
}

function ptpBooking(Customer $customer, string $status = 'on_the_way', array $overrides = []): Booking
{
    $truck = $overrides['truck'] ?? ptpTruckType();
    unset($overrides['truck']);

    return Booking::create(array_merge([
        'customer_id'     => $customer->id,
        'truck_type_id'   => $truck->id,
        'pickup_address'  => '12 Secret Street, Barangay Bagong Silang, Quezon City, Metro Manila, Philippines',
        'pickup_lat'      => 14.676,
        'pickup_lng'      => 121.043,
        'dropoff_address' => '99 Hidden Avenue, Poblacion, Makati City, Metro Manila',
        'dropoff_lat'     => 14.554,
        'dropoff_lng'     => 121.024,
        'distance_km'     => 12,
        'base_rate'       => 1500,
        'per_km_rate'     => 60,
        'final_total'     => 2217.60,
        'status'          => $status,
        'service_type'    => 'book_now',
    ], $overrides))->fresh();
}

function ptpVerify(?string $ref, ?string $digits, string $ip = '10.0.0.1')
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post('/track-booking', array_filter(['ref' => $ref, 'phone_last4' => $digits], fn ($v) => $v !== null));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-02 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

// 1
it('does not reveal any booking details from the reference alone', function () {
    $booking = ptpBooking(ptpCustomer());

    $response = test()->get('/track-booking?ref=' . $booking->booking_code);

    $response->assertOk();
    $response->assertSee($booking->booking_code); // pre-filled into the form only
    $response->assertDontSee('Quezon City');
    $response->assertDontSee('Makati');
    $response->assertDontSee('Unit On The Way');
    $response->assertDontSee('Booking Reference');
});

// 2
it('rejects a submission without phone digits and shows no details', function () {
    $booking = ptpBooking(ptpCustomer());

    $response = ptpVerify($booking->booking_code, null);

    $response->assertStatus(422);
    $response->assertSee('Enter exactly 4 digits from your phone number.');
    $response->assertDontSee('Quezon City');
});

// 3
it('does not reveal details for wrong phone digits', function () {
    $booking = ptpBooking(ptpCustomer());

    $response = ptpVerify($booking->booking_code, '0000');

    $response->assertStatus(422);
    $response->assertSee(PublicTrackController::GENERIC_FAILURE);
    $response->assertDontSee('Quezon City');
    $response->assertDontSee('Unit On The Way');
});

// 4 + 12
it('shows an active booking after the reference and last 4 phone digits verify', function () {
    $booking = ptpBooking(ptpCustomer());

    $response = ptpVerify($booking->booking_code, PTP_LAST4);

    $response->assertOk();
    $response->assertSee('Booking Reference');
    $response->assertSee($booking->booking_code);
    $response->assertSee('Unit On The Way');
    $response->assertSee('Quezon City, Metro Manila');
    $response->assertSee('Makati City, Metro Manila');
    $response->assertSee($booking->truckType->name);
});

it('normalizes formatting characters in the stored phone', function () {
    $booking = ptpBooking(ptpCustomer('0917-123 4567'));

    ptpVerify($booking->booking_code, '4567')->assertOk()->assertSee('Unit On The Way');
});

it('trims whitespace around the submitted values', function () {
    $booking = ptpBooking(ptpCustomer());

    ptpVerify('  ' . $booking->booking_code . '  ', ' ' . PTP_LAST4 . ' ')->assertOk()->assertSee('Unit On The Way');
});

// 5
it("does not accept another customer's phone digits", function () {
    $booking = ptpBooking(ptpCustomer());
    ptpCustomer('+639179998888');

    $response = ptpVerify($booking->booking_code, '8888');

    $response->assertStatus(422);
    $response->assertSee(PublicTrackController::GENERIC_FAILURE);
    $response->assertDontSee('Quezon City');
});

// 6 + 7
it('uses the identical failure response for an unknown booking and for wrong digits', function () {
    $booking = ptpBooking(ptpCustomer());

    $wrongDigits = ptpVerify($booking->booking_code, '0000');
    $unknown = ptpVerify('9999999', PTP_LAST4);

    $unknown->assertStatus(422);
    expect($wrongDigits->status())->toBe($unknown->status());

    $alertOf = fn ($response) => preg_match('/<div class="alert alert-error">(.*?)<\/div>/s', $response->getContent(), $m) ? trim($m[1]) : null;

    expect($alertOf($unknown))->toBe(e(PublicTrackController::GENERIC_FAILURE));
    expect($alertOf($wrongDigits))->toBe($alertOf($unknown));
});

it('treats a customer without a phone like a wrong guess', function () {
    $booking = ptpBooking(ptpCustomer(''));

    ptpVerify($booking->booking_code, '0000')
        ->assertStatus(422)
        ->assertSee(PublicTrackController::GENERIC_FAILURE);
});

// 8
it('rejects non-numeric or wrong-length phone input safely', function (string $digits) {
    $booking = ptpBooking(ptpCustomer());

    $response = ptpVerify($booking->booking_code, $digits);

    $response->assertStatus(422);
    $response->assertSee('Enter exactly 4 digits from your phone number.');
    $response->assertDontSee('Quezon City');
})->with(['ab12', '45 67', '4-67', '12345', '123', '45%7']);

// 9
it('never renders the Team Leader, unit, invoice, amount, contact info or street address', function () {
    $customer = ptpCustomer();
    $truck = ptpTruckType();
    $unit = ptpUnit($truck);
    $booking = ptpBooking($customer, 'in_progress', ['truck' => $truck, 'assigned_unit_id' => $unit->id]);
    $invoice = Invoice::create([
        'booking_id' => $booking->id,
        'subtotal'   => 1980,
        'total'      => 2217.60,
        'status'     => 'issued',
        'is_current' => true,
    ]);

    $response = ptpVerify($booking->booking_code, PTP_LAST4);

    $response->assertOk();
    $response->assertSee('Towing In Progress');
    foreach ([
        'Leaky Leader Name', 'Team Leader', 'PTP Unit 7', 'PTP-9911',
        $invoice->invoice_number, '2,217.60', 'â‚±',
        'Private Customer Name', PTP_PHONE, '9171234567', 'private.customer@example.com',
        '12 Secret Street', 'Bagong Silang', '99 Hidden Avenue', 'Poblacion',
    ] as $secret) {
        $response->assertDontSee($secret, false);
    }
});

it('never renders amounts or Team Leaders for additional vehicles in a group', function () {
    $customer = ptpCustomer();
    $truck = ptpTruckType();
    $unit = ptpUnit($truck);
    $primary = ptpBooking($customer, 'on_the_way', ['truck' => $truck, 'group_code' => 'GRP-PTP-1']);
    ptpBooking($customer, 'on_the_way', [
        'truck' => $truck, 'group_code' => 'GRP-PTP-1', 'assigned_unit_id' => $unit->id, 'final_total' => 3333.33,
    ]);

    $response = ptpVerify($primary->booking_code, PTP_LAST4);

    $response->assertOk();
    $response->assertSee('Additional Vehicles in This Booking');
    $response->assertDontSee('3,333.33');
    $response->assertDontSee('Leaky Leader Name');
});

// 10
it('keeps a completed booking visible during the 24-hour grace period', function () {
    $booking = ptpBooking(ptpCustomer(), 'completed', ['completed_at' => now()->subHours(23)]);

    ptpVerify($booking->booking_code, PTP_LAST4)->assertOk()->assertSee('Completed');
});

// 11
it('hides a completed booking once the grace period has passed', function () {
    $booking = ptpBooking(ptpCustomer(), 'completed', ['completed_at' => now()->subHours(25)]);

    $response = ptpVerify($booking->booking_code, PTP_LAST4);

    $response->assertStatus(422);
    $response->assertSee(PublicTrackController::GENERIC_FAILURE);
    $response->assertDontSee('Quezon City');
});

it('never shows cancelled, rejected or not-responding bookings', function (string $status) {
    $booking = ptpBooking(ptpCustomer(), $status);

    ptpVerify($booking->booking_code, PTP_LAST4)
        ->assertStatus(422)
        ->assertSee(PublicTrackController::GENERIC_FAILURE);
})->with(['cancelled', 'rejected', 'not_responding']);

// 13
it('throttles verification attempts per IP', function () {
    for ($i = 0; $i < 10; $i++) {
        ptpVerify('900000' . $i, '0000', '10.9.9.9')->assertStatus(422);
    }

    ptpVerify('9000099', '0000', '10.9.9.9')->assertStatus(429);
    ptpVerify('9000099', '0000', '10.9.9.10')->assertStatus(422);
});

it('locks a reference after repeated failures even across IPs, without revealing whether it exists', function () {
    $booking = ptpBooking(ptpCustomer());

    for ($i = 0; $i < PublicTrackController::MAX_FAILURES_PER_REFERENCE; $i++) {
        ptpVerify($booking->booking_code, '0000', '10.1.1.' . $i)->assertStatus(422);
        ptpVerify('8888888', '0000', '10.2.2.' . $i)->assertStatus(422);
    }

    // Even the correct digits are refused while locked, and an unknown
    // reference gets the very same lockout.
    ptpVerify($booking->booking_code, PTP_LAST4, '10.3.3.3')
        ->assertStatus(429)
        ->assertSee(PublicTrackController::TOO_MANY_ATTEMPTS)
        ->assertDontSee('Quezon City');
    ptpVerify('8888888', PTP_LAST4, '10.3.3.4')->assertStatus(429);
});

it('keeps the existing throttle on the public tracking page', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('public.track')->gatherMiddleware())->toContain('throttle:30,1');
    expect($routes->getByName('public.track.verify')->gatherMiddleware())->toContain('throttle:10,1,public-track-verify:');
});

// 14
it('never reflects markup from the query string or form input', function () {
    $payload = '<script>alert(1)</script>';

    test()->get('/track-booking?ref=' . urlencode($payload))
        ->assertOk()
        ->assertDontSee($payload, false);

    ptpVerify($payload, PTP_LAST4)->assertDontSee($payload, false);
    ptpVerify('1234567', $payload)->assertDontSee($payload, false);
});

it('escapes stored address text on the verified page', function () {
    $booking = ptpBooking(ptpCustomer(), 'on_the_way', [
        'pickup_address' => 'Street, <img src=x onerror=alert(1)>, Metro Manila',
    ]);

    ptpVerify($booking->booking_code, PTP_LAST4)
        ->assertOk()
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
});

it('does not keep the submitted phone digits in the session', function () {
    $booking = ptpBooking(ptpCustomer());

    ptpVerify($booking->booking_code, '0000');
    ptpVerify($booking->booking_code, PTP_LAST4);

    expect(json_encode(session()->all()))->not->toContain('0000');
    expect(json_encode(session()->all()))->not->toContain(PTP_LAST4);
});

function ptpQuotation(Customer $customer): \App\Models\Quotation
{
    return \App\Models\Quotation::create([
        'quotation_number' => 'QT-PTP-0001',
        'customer_id'      => $customer->id,
        'truck_type_id'    => ptpTruckType()->id,
        'pickup_address'   => '12 Secret Street, Bagong Silang, Quezon City',
        'dropoff_address'  => '99 Hidden Avenue, Poblacion, Makati City',
        'distance_km'      => 12,
        'estimated_price'  => 4321.00,
        'status'           => 'sent',
    ]);
}

it('keeps the quotation-page ?ref= link working as a pre-fill only', function () {
    ptpQuotation(ptpCustomer());

    test()->get('/track-booking?ref=QT-PTP-0001')
        ->assertOk()
        ->assertSee('value="QT-PTP-0001"', false)
        ->assertDontSee('Quotation Reference')
        ->assertDontSee('Bagong Silang');
});

it('requires the phone digits for a quotation reference and hides its price', function () {
    ptpQuotation(ptpCustomer());

    ptpVerify('QT-PTP-0001', '0000')
        ->assertStatus(422)
        ->assertSee(PublicTrackController::GENERIC_FAILURE)
        ->assertDontSee('Quotation Reference');

    ptpVerify('QT-PTP-0001', PTP_LAST4)
        ->assertOk()
        ->assertSee('Quotation Reference')
        ->assertSee('Quezon City')
        ->assertDontSee('4,321.00')
        ->assertDontSee('Secret Street')
        ->assertDontSee('Private Customer Name');
});
