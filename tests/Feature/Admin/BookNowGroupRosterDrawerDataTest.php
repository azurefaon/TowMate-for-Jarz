<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function bnrDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function bnrCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'BNR Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'bnr-' . uniqid() . '@example.com',
    ]);
}

function bnrTruckType(float $baseRate, float $perKmRate): TruckType
{
    return TruckType::create([
        'name' => 'BNR Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function bnrBooking(Customer $customer, TruckType $truckType, string $groupCode, float $distanceKm = 12.0, string $dropoff = 'Makati'): Booking
{
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => $dropoff,
        'distance_km' => $distanceKm,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'status' => 'requested',
        'service_type' => 'book_now',
    ]);

    return $booking->fresh(['customer', 'truckType']);
}

function bnrDraft(User $dispatcher, Booking $booking, float $price)
{
    return test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $booking), [
        'price' => (string) $price,
        'distance_km' => (string) $booking->distance_km,
    ]);
}

it('exposes a pre-save vehicle roster on the Book Now/Intermediate queue row whose identities and pricing exactly match what one grouped Save Quote will persist', function () {
    Mail::fake();
    $dispatcher = bnrDispatcher();
    $customer = bnrCustomer();
    $truckA = bnrTruckType(1500, 60);
    $truckB = bnrTruckType(900, 40);
    $truckC = bnrTruckType(2200, 75);
    $groupCode = 'GRP-BNR-1';

    $bookingA = bnrBooking($customer, $truckA, $groupCode, 12.0);
    $bookingB = bnrBooking($customer, $truckB, $groupCode, 12.0);
    $bookingC = bnrBooking($customer, $truckC, $groupCode, 12.0);

    $response = test()->actingAs($dispatcher)->get(route('admin.dispatch'));
    $response->assertOk();
    $html = $response->getContent();

    preg_match('/<tr[^>]*data-booking-code="' . preg_quote($bookingA->booking_code, '/') . '"[^>]*>/s', $html, $rowMatch);
    expect($rowMatch)->not->toBeEmpty();
    preg_match('/data-group-roster="([^"]*)"/', $rowMatch[0], $rosterMatch);
    expect($rosterMatch)->not->toBeEmpty();
    $preSaveRoster = json_decode(html_entity_decode($rosterMatch[1]), true);

    expect($preSaveRoster)->toHaveCount(3);
    $preSaveIds = collect($preSaveRoster)->pluck('booking_id')->sort()->values()->all();
    expect($preSaveIds)->toBe(collect([$bookingA->id, $bookingB->id, $bookingC->id])->sort()->values()->all());
    // Canonical ascending-by-booking_id order, same rule as the Scheduled roster.
    expect(collect($preSaveRoster)->pluck('booking_id')->all())->toBe([$bookingA->id, $bookingB->id, $bookingC->id]);

    // Dispatcher clicks Save Quote from the middle sibling's own row — the anchor
    // must still resolve to the lowest id, same as Scheduled.
    bnrDraft($dispatcher, $bookingB, 1900)->assertOk();
    $quotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    expect($quotation)->not->toBeNull();

    $persistedIds = collect($quotation->extra_vehicles)->pluck('booking_id')->sort()->values()->all();
    expect($preSaveIds)->toBe($persistedIds);

    $preSaveByBooking = collect($preSaveRoster)->keyBy('booking_id');
    foreach ($quotation->extra_vehicles as $ev) {
        expect((float) $preSaveByBooking[$ev['booking_id']]['final_total'])->toBe((float) $ev['final_total']);
        expect((float) $preSaveByBooking[$ev['booking_id']]['base_rate'])->toBe((float) $ev['base_rate']);
    }
});

it('persists pricing for every sibling in a 3-vehicle Book Now/Intermediate group from a single Save Quote', function () {
    Mail::fake();
    $dispatcher = bnrDispatcher();
    $customer = bnrCustomer();
    $truckA = bnrTruckType(1500, 60);
    $truckB = bnrTruckType(900, 40);
    $truckC = bnrTruckType(2200, 75);
    $groupCode = 'GRP-BNR-SAVEONCE';

    $bookingA = bnrBooking($customer, $truckA, $groupCode, 12.0);
    $bookingB = bnrBooking($customer, $truckB, $groupCode, 12.0);
    $bookingC = bnrBooking($customer, $truckC, $groupCode, 12.0);

    // ONE Save Quote on the anchor — no per-sibling calls required.
    bnrDraft($dispatcher, $bookingA, 1900)->assertOk();

    $quotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    expect($quotation->status)->toBe('draft');
    expect($quotation->extra_vehicles)->toHaveCount(3);
    expect(collect($quotation->extra_vehicles)->pluck('booking_id')->sort()->values()->all())
        ->toBe(collect([$bookingA->id, $bookingB->id, $bookingC->id])->sort()->values()->all());
    expect($bookingB->fresh()->status)->toBe('requested');
    expect($bookingC->fresh()->status)->toBe('requested');

    $expectedA = round((1500 + (12.0 - 4.0) * 60) * 1.12, 2);
    $expectedB = round((900 + (12.0 - 4.0) * 40) * 1.12, 2);
    $expectedC = round((2200 + (12.0 - 4.0) * 75) * 1.12, 2);
    $lineItemFor = fn($bookingId) => collect($quotation->extra_vehicles)->firstWhere('booking_id', $bookingId);
    expect((float) $lineItemFor($bookingA->id)['final_total'])->toBe($expectedA);
    expect((float) $lineItemFor($bookingB->id)['final_total'])->toBe($expectedB);
    expect((float) $lineItemFor($bookingC->id)['final_total'])->toBe($expectedC);
    expect((float) $quotation->estimated_price)->toBe(round($expectedA + $expectedB + $expectedC, 2));

    // Re-saving (existing quotation update) must not duplicate or drop siblings.
    bnrDraft($dispatcher, $bookingA, 1900)->assertOk();
    $quotationAfterResave = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    expect($quotationAfterResave->id)->toBe($quotation->id);
    expect($quotationAfterResave->extra_vehicles)->toHaveCount(3);
    $idsAfterResave = collect($quotationAfterResave->extra_vehicles)->pluck('booking_id')->all();
    expect($idsAfterResave)->toBe(array_unique($idsAfterResave));
});

it('keeps single-vehicle Book Now Save Quote behavior unchanged', function () {
    Mail::fake();
    $dispatcher = bnrDispatcher();
    $customer = bnrCustomer();
    $truckType = bnrTruckType(1500, 60);
    $booking = bnrBooking($customer, $truckType, '', 12.0);

    bnrDraft($dispatcher, $booking, 1900)->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    expect($quotation)->not->toBeNull();
    expect($quotation->extra_vehicles)->toBeEmpty();
    expect((float) $quotation->estimated_price)->toBeGreaterThan(0);
    // Single-vehicle saves still update the booking's own pricing columns
    // (only grouped saves skip this), unchanged from before this fix.
    expect((float) $booking->fresh()->final_total)->toBe((float) $quotation->estimated_price);
});

it('shows no pre-save vehicle tabs for a same-group_code Book Now pair with different destinations, matching groupSiblingBookings() exclusion', function () {
    Mail::fake();
    $dispatcher = bnrDispatcher();
    $customer = bnrCustomer();
    $truckA = bnrTruckType(1500, 60);
    $truckB = bnrTruckType(900, 40);
    $groupCode = 'GRP-BNR-2';

    $bookingA = bnrBooking($customer, $truckA, $groupCode, 12.0, 'Makati');
    $bookingB = bnrBooking($customer, $truckB, $groupCode, 12.0, 'Quezon City');

    $response = test()->actingAs($dispatcher)->get(route('admin.dispatch'));
    $response->assertOk();
    $html = $response->getContent();

    $codesPattern = preg_quote($bookingA->booking_code, '/') . '|' . preg_quote($bookingB->booking_code, '/');
    preg_match('/<tr[^>]*data-booking-code="(' . $codesPattern . ')"[^>]*>/s', $html, $rowMatch);
    expect($rowMatch)->not->toBeEmpty();
    preg_match('/data-group-roster="([^"]*)"/', $rowMatch[0], $rosterMatch);
    $roster = json_decode(html_entity_decode($rosterMatch[1] ?? '[]'), true);

    expect($roster)->toBeEmpty();
});

it('does not disturb the /details group_vehicles order for a Book Now group whose persisted extra_vehicles are stored out of canonical order (frontend sortGroupVehiclesCanonical() is responsible for display order)', function () {
    Mail::fake();
    $dispatcher = bnrDispatcher();
    $customer = bnrCustomer();
    $truckA = bnrTruckType(1500, 60);
    $truckB = bnrTruckType(900, 40);
    $truckC = bnrTruckType(2200, 75);
    $groupCode = 'GRP-BNR-3';

    $bookingA = bnrBooking($customer, $truckA, $groupCode, 12.0);
    $bookingB = bnrBooking($customer, $truckB, $groupCode, 12.0);
    $bookingC = bnrBooking($customer, $truckC, $groupCode, 12.0);

    bnrDraft($dispatcher, $bookingA, 1900)->assertOk();
    $quotation = Quotation::where('source_booking_id', $bookingA->id)->current()->first();
    expect(collect($quotation->extra_vehicles)->pluck('booking_id')->all())->toBe([$bookingA->id, $bookingB->id, $bookingC->id]);

    // Deliberately store extra_vehicles out of canonical order, exactly like the
    // real stale production row found for Scheduled (id 296: [295,293,294]).
    $outOfOrder = [
        $quotation->extra_vehicles[2],
        $quotation->extra_vehicles[0],
        $quotation->extra_vehicles[1],
    ];
    $quotation->update(['extra_vehicles' => $outOfOrder]);

    $response = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation->fresh()));
    $response->assertOk();

    // The backend response legitimately preserves whatever order is stored —
    // it is the FRONTEND's sortGroupVehiclesCanonical() that must re-sort this
    // into canonical booking_id-ascending order before it ever reaches the tabs.
    $order = collect($response->json('quotation.group_vehicles'))->pluck('booking_id')->all();
    expect($order)->toBe([$bookingC->id, $bookingA->id, $bookingB->id]);
});
