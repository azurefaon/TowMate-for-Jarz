<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

/**
 * Regression coverage for JobsController::activeGroupTotal() — the Active
 * Jobs table's displayed "Amount Due" for a grouped booking must use the
 * same authoritative formula as TLTaskController::completeGroup(), never
 * the frozen quotation.estimated_price snapshot, which silently dropped any
 * legitimate post-acceptance per-vehicle correction whenever nobody in the
 * group happened to be cancelled/rejected.
 */
function ajgtDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'status' => 'active', 'must_change_password' => false]);
}

function ajgtCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'AJGT Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'ajgt-' . uniqid() . '@example.com',
    ]);
}

function ajgtTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'AJGT Truck ' . fake()->unique()->word(),
        'base_rate' => 4000,
        'per_km_rate' => 0,
    ]);
}

function ajgtMember(Customer $customer, TruckType $truckType, string $groupCode, array $overrides = []): Booking
{
    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'AJGT Pickup Point',
        'dropoff_address' => 'AJGT Dropoff Point',
        'distance_km' => 10,
        'base_rate' => 4000,
        'per_km_rate' => 0,
        'computed_total' => 4000,
        'discount_percentage' => 0,
        'additional_fee' => 0,
        'vat_exclusive_total' => 4000,
        'vat_amount' => 480,
        'final_total' => 4480,
        'status' => 'waiting_verification',
        'service_type' => 'book_now',
        'payment_method' => 'cash',
        'cash_received' => 20000,
    ], $overrides));
    $booking->update(['booking_code' => 'TM-AJGT' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

/**
 * 3-vehicle normalized group: 3 x 4,480 + (additional_fee 2,000 - discount
 * 440) = 15,000 — same convention as GroupCompletionTotalConsistencyTest
 * and GroupedInvoiceVoidReplaceTest.
 */
function ajgtScenario(?string $extraMemberStatus = null): array
{
    $customer = ajgtCustomer();
    $truckType = ajgtTruckType();
    $groupCode = 'GRP-AJGT-' . uniqid();

    $vehicle1 = ajgtMember($customer, $truckType, $groupCode);
    $vehicle2 = ajgtMember($customer, $truckType, $groupCode);
    $vehicle3 = ajgtMember($customer, $truckType, $groupCode);

    $extraVehicles = [
        ['booking_id' => $vehicle2->id, 'truck_type_id' => $truckType->id],
        ['booking_id' => $vehicle3->id, 'truck_type_id' => $truckType->id],
    ];

    if ($extraMemberStatus !== null) {
        $vehicle4 = ajgtMember($customer, $truckType, $groupCode, [
            'final_total' => 9999,
            'status' => $extraMemberStatus,
        ]);
        $extraVehicles[] = ['booking_id' => $vehicle4->id, 'truck_type_id' => $truckType->id];
    }

    $quotation = Quotation::create([
        'quotation_number' => 'QT-AJGT-' . uniqid(),
        'source_booking_id' => $vehicle1->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $vehicle1->pickup_address,
        'dropoff_address' => $vehicle1->dropoff_address,
        'distance_km' => $vehicle1->distance_km,
        'estimated_price' => 15000,
        'additional_fee' => 2000,
        'discount' => 440,
        'status' => 'accepted',
        'is_current' => true,
        'extra_vehicles' => $extraVehicles,
    ]);

    foreach ([$vehicle1, $vehicle2, $vehicle3] as $member) {
        $member->update(['quotation_id' => $quotation->id]);
    }

    return compact('customer', 'truckType', 'vehicle1', 'vehicle2', 'vehicle3', 'quotation');
}

function ajgtRowHtml(string $html, string $bookingCode): string
{
    $codePos = strpos($html, 'data-booking-code="' . $bookingCode . '"');
    $trStart = strrpos(substr($html, 0, $codePos), '<tr');
    $trEnd = strpos($html, '</tr>', $codePos);

    return substr($html, $trStart, $trEnd - $trStart);
}

it('1: an untouched 3-vehicle group displays the same accepted total as before (₱15,000.00)', function () {
    ['vehicle1' => $vehicle1] = ajgtScenario();

    $html = test()->actingAs(ajgtDispatcher())->get(route('admin.jobs'))->assertOk()->getContent();

    $row = ajgtRowHtml($html, $vehicle1->booking_code);
    expect($row)->toContain('data-total="15,000.00"');
});

it('2+3: a +500 correction to Vehicle 2 alone raises the displayed Amount Due to ₱15,500.00, counting the quotation fee/discount exactly once', function () {
    ['vehicle1' => $vehicle1, 'vehicle2' => $vehicle2, 'quotation' => $quotation] = ajgtScenario();

    $vehicle2->update(['additional_fee' => 500, 'vat_exclusive_total' => 4000, 'vat_amount' => 480, 'final_total' => 4980]);

    $html = test()->actingAs(ajgtDispatcher())->get(route('admin.jobs'))->assertOk()->getContent();

    $row = ajgtRowHtml($html, $vehicle1->booking_code);
    expect($row)->toContain('data-total="15,500.00"')
        ->and($row)->not->toContain('data-total="15,000.00"');

    // member_total (4480+4980+4480=13940) + additional_fee (2000) - discount (440) = 15500.
    // If either quotation-level field were dropped or doubled, this would not equal 15500.
    expect((float) $quotation->fresh()->additional_fee)->toBe(2000.0)
        ->and((float) $quotation->fresh()->discount)->toBe(440.0);
});

it('4: a cancelled group member is excluded from the displayed total', function () {
    ['vehicle1' => $vehicle1] = ajgtScenario('cancelled');

    $html = test()->actingAs(ajgtDispatcher())->get(route('admin.jobs'))->assertOk()->getContent();

    $row = ajgtRowHtml($html, $vehicle1->booking_code);
    expect($row)->toContain('data-total="15,000.00"');
});

it('4b: a rejected group member is excluded from the displayed total', function () {
    ['vehicle1' => $vehicle1] = ajgtScenario('rejected');

    $html = test()->actingAs(ajgtDispatcher())->get(route('admin.jobs'))->assertOk()->getContent();

    $row = ajgtRowHtml($html, $vehicle1->booking_code);
    expect($row)->toContain('data-total="15,000.00"');
});

it('5: the accepted Quotation is left completely unchanged by viewing the Active Jobs table', function () {
    ['vehicle2' => $vehicle2, 'quotation' => $quotation] = ajgtScenario();
    $vehicle2->update(['additional_fee' => 500, 'vat_exclusive_total' => 4000, 'vat_amount' => 480, 'final_total' => 4980]);

    $before = $quotation->fresh();

    test()->actingAs(ajgtDispatcher())->get(route('admin.jobs'))->assertOk();

    $after = $quotation->fresh();
    expect($after->version)->toBe($before->version)
        ->and($after->status)->toBe($before->status)
        ->and((float) $after->estimated_price)->toBe((float) $before->estimated_price)
        ->and((float) $after->additional_fee)->toBe((float) $before->additional_fee)
        ->and((float) $after->discount)->toBe((float) $before->discount);
});
