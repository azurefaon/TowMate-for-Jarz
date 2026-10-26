<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Services\DocumentPresenter;
use Illuminate\Support\Facades\Mail;

// Production shape (TM-00148 + TM-00149): two Light Duty vehicles at P1,680 each (P1,500 base + 12% VAT, within
// the first 4 km), and a pending booking-time quotation that lists only the EXTRA vehicle. Saving the quote
// must produce ONE complete group quotation naming both vehicles, and the customer-facing document must itemise both.
it('turns the booking-time pending quotation into one complete two-vehicle quotation after Save Quote', function () {
    Mail::fake();
    $role = Role::find(2) ?? tap(new Role(['name' => 'Dispatcher', 'description' => 'Dispatch staff']), function ($r) {
        $r->id = 2;
        $r->save();
    });
    $dispatcher = User::factory()->create(['role_id' => $role->id]);
    $truck = TruckType::create(['name' => 'Light Duty ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 75, 'status' => 'active']);
    $customer = Customer::create(['full_name' => 'Content Customer', 'phone' => '0917' . fake()->unique()->numerify('#######'), 'email' => 'content-' . uniqid() . '@example.com']);

    $make = fn() => Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $truck->id, 'group_code' => 'GRP-CONTENT',
        'pickup_address' => 'Pickup', 'dropoff_address' => 'Dropoff', 'distance_km' => 3,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1500, 'final_total' => 1680,
        'status' => 'requested', 'service_type' => 'book_now',
    ]);
    $anchor = $make();
    $second = $make();

    Quotation::create([
        'quotation_number' => 'QT-CONTENT-1', 'distance_km' => 3, 'source_booking_id' => $anchor->id,
        'customer_id' => $customer->id, 'truck_type_id' => $truck->id, 'pickup_address' => 'Pickup',
        'dropoff_address' => 'Dropoff', 'estimated_price' => 3360, 'status' => 'pending', 'is_current' => true,
        'extra_vehicles' => [['booking_id' => $second->id, 'truck_type_id' => $truck->id, 'base_rate' => 1500, 'distance_fee' => 0, 'vat_amount' => 180, 'final_total' => 1680]],
    ]);

    $this->actingAs($dispatcher)->post(route('admin.booking.save-draft', $anchor), [
        'price' => '3360',
        'distance_km' => '3',
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $anchor->id)->current()->first();
    $vehicles = collect($quotation->extra_vehicles);

    expect($vehicles->pluck('booking_id')->sort()->values()->all())->toBe([$anchor->id, $second->id])
        ->and($vehicles->pluck('final_total')->map(fn($v) => (float) $v)->all())->toBe([1680.0, 1680.0])
        ->and((float) $quotation->estimated_price)->toBe(3360.0);

    // What the customer is shown: both vehicles itemised and a matching total.
    $document = app(DocumentPresenter::class)->quotation($quotation->fresh());
    expect($document['reliable'])->toBeTrue()
        ->and(collect($document['charges'])->pluck('label')->implode('|'))->toContain('Vehicle 1')->toContain('Vehicle 2')
        ->and(collect($document['charges'])->pluck('amount'))->toHaveCount(2)
        ->and($document['total'])->toContain('3,360.00');
});
