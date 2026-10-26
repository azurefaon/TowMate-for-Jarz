<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

function gfxDispatcher(): User
{
    $role = Role::find(2) ?? tap(new Role(['name' => 'Dispatcher', 'description' => 'Dispatch staff']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function gfxTruck(string $name = 'Light Duty'): TruckType
{
    return TruckType::create(['name' => $name . ' ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 75]);
}

function gfxUnit(TruckType $truck, string $name): Unit
{
    $role = Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Tow unit team leader']);
    $leader = User::factory()->create(['role_id' => $role->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => $name,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truck->id,
        'team_leader_id' => $leader->id,
        'status' => 'available',
        'driver_name' => 'Fixture Driver',
    ]);
}

function gfxBooking(TruckType $truck, Customer $customer, string $group, string $status, array $extra = []): Booking
{
    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'group_code' => $group,
        'pickup_address' => 'Pickup',
        'dropoff_address' => 'Dropoff',
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 2250,
        'final_total' => 2250,
        'status' => $status,
        'service_type' => 'book_now',
    ], $extra));
}

function gfxCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'Group Customer',
        'phone' => '09170000' . fake()->numerify('###'),
        'email' => 'gfx-' . uniqid() . '@example.com',
    ]);
}

function gfxQuotation(Booking $anchor, string $status, array $coveredBookingIds): Quotation
{
    return Quotation::create([
        'quotation_number' => 'QT-GFX-' . $anchor->id,
        'distance_km' => 10,
        'source_booking_id' => $anchor->id,
        'customer_id' => $anchor->customer_id,
        'truck_type_id' => $anchor->truck_type_id,
        'pickup_address' => 'Pickup',
        'dropoff_address' => 'Dropoff',
        'estimated_price' => 4500,
        'status' => $status,
        'is_current' => true,
        'extra_vehicles' => array_map(fn($id) => ['booking_id' => $id], $coveredBookingIds),
    ]);
}

function gfxQueueRow($response, Booking $booking): ?Booking
{
    return collect($response->viewData('bookNowRequests'))->firstWhere('id', $booking->id);
}

it('keeps an accepted multi-vehicle quotation and assigns each vehicle its own unit', function () {
    $truck = gfxTruck();
    $customer = gfxCustomer();
    $a = gfxBooking($truck, $customer, 'GRP-GFX1', 'confirmed', ['quotation_status' => 'accepted']);
    $b = gfxBooking($truck, $customer, 'GRP-GFX1', 'confirmed', ['quotation_status' => 'accepted']);
    gfxQuotation($a, 'accepted', [$b->id]);
    $unitA = gfxUnit($truck, 'JARZ 3');
    $unitB = gfxUnit($truck, 'JARZ 5');
    $dispatcher = gfxDispatcher();

    $page = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();
    expect(gfxQueueRow($page, $a)->active_quotation_status)->toBe('accepted');

    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $a), ['action' => 'accept', 'assigned_unit_id' => $unitA->id])->assertOk();
    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $b), ['action' => 'accept', 'assigned_unit_id' => $unitB->id])->assertOk();

    expect($a->fresh()->assigned_unit_id)->toBe($unitA->id)
        ->and($b->fresh()->assigned_unit_id)->toBe($unitB->id);
});

it('does not assign a unit while the group quotation is only sent', function () {
    Mail::fake();
    $truck = gfxTruck();
    $customer = gfxCustomer();
    $a = gfxBooking($truck, $customer, 'GRP-GFX2', 'quotation_sent');
    $b = gfxBooking($truck, $customer, 'GRP-GFX2', 'quotation_sent');
    gfxQuotation($a, 'sent', [$b->id]);
    $unit = gfxUnit($truck, 'JARZ 3');
    $dispatcher = gfxDispatcher();

    $page = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();
    expect(gfxQueueRow($page, $a)->active_quotation_status)->toBe('sent');

    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $b), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
        'distance_km' => '10',
        'distance_fee' => '450',
    ]);

    expect($b->fresh()->assigned_unit_id)->toBeNull();
});

it('reports no quotation for a confirmed booking whose quotation record is missing and never invents one', function () {
    $truck = gfxTruck();
    $customer = gfxCustomer();
    $lone = gfxBooking($truck, $customer, 'GRP-GFX3', 'confirmed', ['quotation_status' => 'accepted']);
    $dispatcher = gfxDispatcher();

    $row = gfxQueueRow($this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk(), $lone);

    expect($row->active_quotation_status)->toBeNull()->and($row->active_quotation_id)->toBeNull();
});

it('lets the surviving vehicle keep the accepted state when its group anchor was cancelled', function () {
    $truck = gfxTruck();
    $customer = gfxCustomer();
    $anchor = gfxBooking($truck, $customer, 'GRP-GFX4', 'cancelled', ['quotation_status' => 'cancelled']);
    $survivor = gfxBooking($truck, $customer, 'GRP-GFX4', 'confirmed', ['quotation_status' => 'accepted']);
    $quotation = gfxQuotation($anchor, 'accepted', [$survivor->id]);
    $unit = gfxUnit($truck, 'JARZ 3');
    $dispatcher = gfxDispatcher();

    $row = gfxQueueRow($this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk(), $survivor);

    // Status and number come from the real anchor quotation; its group-wide id/price are never exposed.
    expect($row->active_quotation_status)->toBe('accepted')
        ->and($row->active_quotation_number)->toBe($quotation->quotation_number)
        ->and($row->active_quotation_id)->toBeNull();

    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $survivor), ['action' => 'accept', 'assigned_unit_id' => $unit->id])->assertOk();
    expect($survivor->fresh()->assigned_unit_id)->toBe($unit->id);
});

it('does not borrow the cancelled anchor quotation for a vehicle that quotation never covered', function () {
    $truck = gfxTruck();
    $customer = gfxCustomer();
    $anchor = gfxBooking($truck, $customer, 'GRP-GFX5', 'cancelled', ['quotation_status' => 'cancelled']);
    $other = gfxBooking($truck, $customer, 'GRP-GFX5', 'confirmed', ['quotation_status' => 'accepted']);
    gfxQuotation($anchor, 'accepted', []);

    $row = gfxQueueRow($this->actingAs(gfxDispatcher())->get(route('admin.dispatch'))->assertOk(), $other);

    expect($row->active_quotation_status)->toBeNull();
});

it('rejects a wrong-type, busy or unauthorized unit assignment', function () {
    $truck = gfxTruck();
    $heavy = gfxTruck('Heavy Duty');
    $customer = gfxCustomer();
    $a = gfxBooking($truck, $customer, 'GRP-GFX6', 'confirmed', ['quotation_status' => 'accepted']);
    $b = gfxBooking($truck, $customer, 'GRP-GFX6', 'confirmed', ['quotation_status' => 'accepted']);
    gfxQuotation($a, 'accepted', [$b->id]);
    $wrongType = gfxUnit($heavy, 'HEAVY 1');
    $busy = gfxUnit($truck, 'JARZ 3');
    Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $truck->id, 'pickup_address' => 'P', 'dropoff_address' => 'D',
        'distance_km' => 5, 'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'on_the_way', 'service_type' => 'book_now', 'assigned_unit_id' => $busy->id,
        'assigned_team_leader_id' => $busy->team_leader_id, 'assigned_at' => now(),
    ]);
    $dispatcher = gfxDispatcher();

    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $a), ['action' => 'accept', 'assigned_unit_id' => $wrongType->id])->assertStatus(422);
    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $b), ['action' => 'accept', 'assigned_unit_id' => $busy->id])->assertStatus(422);

    $customerRole = Role::firstOrCreate(['name' => 'Customer'], ['description' => 'Customer']);
    $outsider = User::factory()->create(['role_id' => $customerRole->id]);
    $this->actingAs($outsider)->postJson(route('admin.booking.assign', $a), ['action' => 'accept', 'assigned_unit_id' => $busy->id])->assertForbidden();

    expect($a->fresh()->assigned_unit_id)->toBeNull()->and($b->fresh()->assigned_unit_id)->toBeNull();
});
