<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\User;

function givrDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'status' => 'active', 'must_change_password' => false]);
}

function givrTeamLeader(): User
{
    $role = Role::find(3) ?: tap(new Role(['name' => 'Team Leader']), function ($r) {
        $r->id = 3;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'must_change_password' => false]);
}

function givrCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'GIVR Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'givr-' . uniqid() . '@example.com',
    ]);
}

function givrTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'GIVR Truck ' . fake()->unique()->word(),
        'base_rate' => 4000,
        'per_km_rate' => 0,
    ]);
}

/**
 * gross 4000, 0% discount, 12% VAT -> vat_exclusive_total 4000, vat_amount
 * 480, final_total 4480 — same convention as GroupCompletionTotalConsistencyTest.
 */
function givrMember(Customer $customer, TruckType $truckType, string $groupCode, array $overrides = []): Booking
{
    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'GIVR Pickup Point',
        'dropoff_address' => 'GIVR Dropoff Point',
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
    $booking->update(['booking_code' => 'TM-GIVR' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

/**
 * 3-vehicle normalized group already at waiting_verification with its
 * consolidated invoice already issued: 3 x 4,480 + (additional_fee 2,000 -
 * discount 440) = 15,000, matching completeGroup()'s (now-fixed) formula.
 */
function givrScenario(bool $withCancelledSibling = false): array
{
    $customer = givrCustomer();
    $truckType = givrTruckType();
    $groupCode = 'GRP-GIVR-' . uniqid();

    $vehicle1 = givrMember($customer, $truckType, $groupCode);
    $vehicle2 = givrMember($customer, $truckType, $groupCode);
    $vehicle3 = givrMember($customer, $truckType, $groupCode);

    $extraVehicles = [
        ['booking_id' => $vehicle2->id, 'truck_type_id' => $truckType->id],
        ['booking_id' => $vehicle3->id, 'truck_type_id' => $truckType->id],
    ];

    if ($withCancelledSibling) {
        $vehicle4 = givrMember($customer, $truckType, $groupCode, [
            'final_total' => 9999,
            'status' => 'cancelled',
        ]);
        $extraVehicles[] = ['booking_id' => $vehicle4->id, 'truck_type_id' => $truckType->id];
    }

    $quotation = Quotation::create([
        'quotation_number' => 'QT-GIVR-' . uniqid(),
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

    $invoice = Invoice::create([
        'booking_id' => $vehicle1->id,
        'quotation_id' => $quotation->id,
        'subtotal' => 12000,
        'additional_fee' => 2000,
        'discount' => 440,
        'total' => 15000,
        'status' => 'issued',
        'is_current' => true,
    ]);

    return compact('customer', 'truckType', 'vehicle1', 'vehicle2', 'vehicle3', 'quotation', 'invoice');
}

it('1: a reason-only grouped replacement preserves every amount exactly', function () {
    ['vehicle1' => $vehicle1, 'vehicle2' => $vehicle2, 'vehicle3' => $vehicle3, 'invoice' => $invoice] = givrScenario();

    $response = test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Fix customer name on the PDF only']
    );

    $response->assertOk()->assertJsonPath('success', true);

    $newInvoice = Invoice::where('is_current', true)->first();
    expect((float) $newInvoice->subtotal)->toBe(12000.0)
        ->and((float) $newInvoice->total)->toBe(15000.0)
        ->and((float) $newInvoice->additional_fee)->toBe(2000.0)
        ->and((float) $newInvoice->discount)->toBe(440.0);

    foreach ([$vehicle1, $vehicle2, $vehicle3] as $member) {
        expect((float) $member->fresh()->final_total)->toBe(4480.0)
            ->and((float) $member->fresh()->additional_fee)->toBe(0.0);
    }
});

it('2: a +500 correction to Vehicle 2 alone raises the consolidated invoice to 15,500 and only touches Vehicle 2', function () {
    ['vehicle1' => $vehicle1, 'vehicle2' => $vehicle2, 'vehicle3' => $vehicle3, 'invoice' => $invoice] = givrScenario();

    $response = test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Vehicle 2 needed a legitimate surcharge', 'member_booking_id' => $vehicle2->id, 'additional_fee' => 500]
    );

    $response->assertOk()->assertJsonPath('success', true);

    $newInvoice = Invoice::where('is_current', true)->first();
    expect((float) $newInvoice->total)->toBe(15500.0)
        ->and((float) $newInvoice->subtotal)->toBe(12000.0)
        ->and((float) $newInvoice->additional_fee)->toBe(2000.0)
        ->and((float) $newInvoice->discount)->toBe(440.0);

    expect((float) $vehicle2->fresh()->additional_fee)->toBe(500.0)
        ->and((float) $vehicle2->fresh()->final_total)->toBe(4980.0);

    // Siblings must be completely untouched.
    expect((float) $vehicle1->fresh()->final_total)->toBe(4480.0)
        ->and((float) $vehicle1->fresh()->additional_fee)->toBe(0.0)
        ->and((float) $vehicle3->fresh()->final_total)->toBe(4480.0)
        ->and((float) $vehicle3->fresh()->additional_fee)->toBe(0.0);
});

it('3: the accepted Quotation is byte-for-byte financially unchanged after the correction', function () {
    ['vehicle2' => $vehicle2, 'quotation' => $quotation, 'invoice' => $invoice] = givrScenario();
    $before = $quotation->fresh();

    test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Vehicle 2 surcharge', 'member_booking_id' => $vehicle2->id, 'additional_fee' => 500]
    )->assertOk();

    $after = $quotation->fresh();
    expect($after->version)->toBe($before->version)
        ->and($after->status)->toBe($before->status)
        ->and((float) $after->estimated_price)->toBe((float) $before->estimated_price)
        ->and((float) $after->additional_fee)->toBe((float) $before->additional_fee)
        ->and((float) $after->discount)->toBe((float) $before->discount);
});

it('4: a cancelled sibling is excluded from the recomputed total', function () {
    ['vehicle2' => $vehicle2, 'invoice' => $invoice] = givrScenario(withCancelledSibling: true);

    test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Vehicle 2 surcharge', 'member_booking_id' => $vehicle2->id, 'additional_fee' => 500]
    )->assertOk();

    $newInvoice = Invoice::where('is_current', true)->first();
    expect((float) $newInvoice->total)->toBe(15500.0);
});

it('5: a member_booking_id outside the active group (cancelled, or belonging to another group) is rejected', function () {
    ['invoice' => $invoiceWithCancelled, 'vehicle1' => $anchorA] = givrScenario(withCancelledSibling: true);
    $cancelledSibling = Booking::where('group_code', $anchorA->group_code)->where('status', 'cancelled')->first();

    $blockedCancelled = test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoiceWithCancelled),
        ['reason' => 'Trying to correct a cancelled vehicle', 'member_booking_id' => $cancelledSibling->id, 'additional_fee' => 500]
    );
    $blockedCancelled->assertStatus(422);
    expect($blockedCancelled->json('message'))->toContain('not part of this active group');
    expect(Invoice::where('is_current', true)->first()->id)->toBe($invoiceWithCancelled->id);

    ['invoice' => $invoiceB] = givrScenario();
    $otherGroup = givrScenario();

    $blockedOutside = test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoiceB),
        ['reason' => 'Trying to correct a vehicle from another group', 'member_booking_id' => $otherGroup['vehicle2']->id, 'additional_fee' => 500]
    );
    $blockedOutside->assertStatus(422);
    expect($blockedOutside->json('message'))->toContain('not part of this active group');
});

it('6: insufficient cash blocks the correction and rolls back both the member Booking write and the invoice (proves transactional rollback)', function () {
    ['vehicle1' => $vehicle1, 'vehicle2' => $vehicle2, 'vehicle3' => $vehicle3, 'invoice' => $invoice] = givrScenario();
    // cash_received (15,000) covers the original total exactly but not a
    // corrected 15,500 — the member write happens inside the transaction
    // BEFORE this guard is evaluated, so a real DB rollback is required.
    foreach ([$vehicle1, $vehicle2, $vehicle3] as $m) {
        $m->update(['cash_received' => 15000]);
    }

    $response = test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Vehicle 2 surcharge', 'member_booking_id' => $vehicle2->id, 'additional_fee' => 500]
    );

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Cash received');

    // The member write that happened mid-transaction must have been rolled back.
    expect((float) $vehicle2->fresh()->additional_fee)->toBe(0.0)
        ->and((float) $vehicle2->fresh()->final_total)->toBe(4480.0);

    // The old invoice must still be current — voidAndReplace() must never have run.
    $stillCurrent = Invoice::where('is_current', true)->first();
    expect($stillCurrent->id)->toBe($invoice->id)
        ->and($stillCurrent->status)->toBe('issued')
        ->and(Invoice::count())->toBe(1);
});

it('7: a non-cash payment with a changed total requires explicit verification, and succeeds once verified', function () {
    ['vehicle1' => $vehicle1, 'vehicle2' => $vehicle2, 'vehicle3' => $vehicle3, 'invoice' => $invoice] = givrScenario();
    foreach ([$vehicle1, $vehicle2, $vehicle3] as $m) {
        $m->update(['payment_method' => 'gcash', 'cash_received' => null]);
    }

    $blocked = test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Vehicle 2 surcharge', 'member_booking_id' => $vehicle2->id, 'additional_fee' => 500]
    );
    $blocked->assertStatus(422);
    expect($blocked->json('message'))->toContain('verified');
    expect((float) $vehicle2->fresh()->additional_fee)->toBe(0.0);
    expect(Invoice::where('is_current', true)->first()->id)->toBe($invoice->id);

    $allowed = test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Vehicle 2 surcharge', 'member_booking_id' => $vehicle2->id, 'additional_fee' => 500, 'payment_verified' => true]
    );
    $allowed->assertOk();
    expect((float) $vehicle2->fresh()->additional_fee)->toBe(500.0);
});

it('8: lineage is correct across the replacement', function () {
    ['vehicle2' => $vehicle2, 'invoice' => $invoice] = givrScenario();

    test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Vehicle 2 surcharge', 'member_booking_id' => $vehicle2->id, 'additional_fee' => 500]
    )->assertOk();

    $oldInvoice = $invoice->fresh();
    $newInvoice = Invoice::where('is_current', true)->first();

    expect($oldInvoice->status)->toBe('voided')
        ->and($oldInvoice->is_current)->toBeFalse()
        ->and($oldInvoice->void_reason)->toBe('Vehicle 2 surcharge')
        ->and($newInvoice->previous_invoice_id)->toBe($oldInvoice->id)
        ->and($newInvoice->original_invoice_id)->toBe($oldInvoice->id)
        ->and($newInvoice->invoice_number)->not->toBe($oldInvoice->invoice_number);
});

it('9: dispatcher discount/charge limits are enforced on the per-vehicle correction', function () {
    SystemSetting::setValue('max_additional_charge', 100);
    ['vehicle2' => $vehicle2, 'invoice' => $invoice] = givrScenario();

    $response = test()->actingAs(givrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Vehicle 2 surcharge', 'member_booking_id' => $vehicle2->id, 'additional_fee' => 999]
    );

    $response->assertStatus(422);
    expect((float) $vehicle2->fresh()->additional_fee)->toBe(0.0);
    expect(Invoice::where('is_current', true)->first()->id)->toBe($invoice->id);
});

it('10: an unauthorized role cannot use the grouped void-and-replace endpoint', function () {
    ['vehicle2' => $vehicle2, 'invoice' => $invoice] = givrScenario();

    $response = test()->actingAs(givrTeamLeader())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Should not be allowed', 'member_booking_id' => $vehicle2->id, 'additional_fee' => 500]
    );

    $response->assertStatus(403);
    expect(Invoice::where('is_current', true)->first()->id)->toBe($invoice->id);
});
