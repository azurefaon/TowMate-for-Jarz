<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

// Production shape (TM-00148 + TM-00149): the quotation created at booking time lists only the EXTRA
// vehicle in extra_vehicles; the anchor booking is not in that list.
function drawerRosterScenario(): array
{
    $role = Role::find(2) ?? tap(new Role(['name' => 'Dispatcher', 'description' => 'Dispatch staff']), function ($r) {
        $r->id = 2;
        $r->save();
    });
    $dispatcher = User::factory()->create(['role_id' => $role->id]);
    $truck = TruckType::create(['name' => 'Light Duty ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 75]);
    $customer = Customer::create(['full_name' => 'Roster Customer', 'phone' => '09170000' . fake()->numerify('###'), 'email' => 'roster-' . uniqid() . '@example.com']);

    $make = fn() => Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $truck->id, 'group_code' => 'GRP-RSTR',
        'pickup_address' => 'Pickup', 'dropoff_address' => 'Dropoff', 'distance_km' => 0, 'base_rate' => 1500,
        'per_km_rate' => 75, 'computed_total' => 1500, 'final_total' => 1680, 'status' => 'requested',
        'service_type' => 'book_now',
    ]);
    $anchor = $make();
    $second = $make();

    $quotation = Quotation::create([
        'quotation_number' => 'QT-RSTR-1', 'distance_km' => 0, 'source_booking_id' => $anchor->id,
        'customer_id' => $customer->id, 'truck_type_id' => $truck->id, 'pickup_address' => 'Pickup',
        'dropoff_address' => 'Dropoff', 'estimated_price' => 3360, 'status' => 'pending', 'is_current' => true,
        'extra_vehicles' => [['booking_id' => $second->id, 'truck_type_id' => $truck->id, 'base_rate' => 1500, 'distance_fee' => 0, 'vat_amount' => 180, 'final_total' => 1680]],
    ]);
    $anchor->update(['quotation_id' => $quotation->id]);

    return [$dispatcher, $anchor, $second, $quotation];
}

it('gives the drawer the full two-vehicle roster for a group whose quotation lists only the extra vehicle', function () {
    [$dispatcher, $anchor, $second] = drawerRosterScenario();

    $response = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();
    $roster = $response->viewData('bookNowGroupRosters')['GRP-RSTR'];

    expect(collect($roster)->pluck('booking_id')->all())->toBe([$anchor->id, $second->id])
        ->and(collect($roster)->sum('final_total'))->toBe(3360.0);
});

it('documents why the drawer must not trust group_vehicles alone: the details payload lists one vehicle', function () {
    [$dispatcher, , $second, $quotation] = drawerRosterScenario();

    $details = $this->actingAs($dispatcher)->getJson(route('admin.quotations.details', $quotation))->assertOk()->json('quotation');

    expect(collect($details['group_vehicles'])->pluck('booking_id')->all())->toBe([$second->id])
        ->and((float) $details['estimated_price'])->toBe(3360.0);
});
