<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

/*
 * Canonical receipt-exists guard for Invoice Void & Replace.
 *
 * Business rule under test: a correction is refused once ANY canonical Receipt
 * exists for the transaction (solo booking, or any member/receipt of the
 * group's same-trip set, or a receipt attached to the invoice being
 * corrected) - based on the Receipt relationship, never on booking status.
 * waiting_verification and completed WITHOUT a receipt stay correctable.
 *
 * Self-contained (irg* helpers) so it runs alone or with the suite.
 */

function irgDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'status' => 'active', 'must_change_password' => false]);
}

function irgCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'IRG Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'irg-' . uniqid() . '@example.com',
    ]);
}

function irgTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'IRG Truck ' . fake()->unique()->word(),
        'base_rate' => 4000,
        'per_km_rate' => 0,
    ]);
}

function irgBooking(Customer $customer, TruckType $truckType, array $overrides = []): Booking
{
    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'IRG Pickup',
        'dropoff_address' => 'IRG Dropoff',
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
    $booking->update(['booking_code' => 'TM-IRG' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

/** Solo booking + its issued, current invoice. */
function irgSolo(string $status): array
{
    $booking = irgBooking(irgCustomer(), irgTruckType(), ['status' => $status]);
    $invoice = Invoice::create([
        'booking_id' => $booking->id,
        'subtotal' => 4000,
        'additional_fee' => 0,
        'discount' => 0,
        'total' => 4480,
        'status' => 'issued',
        'is_current' => true,
    ]);

    return compact('booking', 'invoice');
}

/** 3-vehicle normalized group (anchor = vehicle1 = quotation.source_booking_id) with a consolidated invoice. */
function irgGroup(string $status): array
{
    $customer = irgCustomer();
    $truckType = irgTruckType();
    $groupCode = 'GRP-IRG-' . uniqid();

    $v1 = irgBooking($customer, $truckType, ['group_code' => $groupCode, 'status' => $status]);
    $v2 = irgBooking($customer, $truckType, ['group_code' => $groupCode, 'status' => $status]);
    $v3 = irgBooking($customer, $truckType, ['group_code' => $groupCode, 'status' => $status]);

    $quotation = Quotation::create([
        'quotation_number' => 'QT-IRG-' . uniqid(),
        'source_booking_id' => $v1->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $v1->pickup_address,
        'dropoff_address' => $v1->dropoff_address,
        'distance_km' => 10,
        'estimated_price' => 15000,
        'additional_fee' => 2000,
        'discount' => 440,
        'status' => 'accepted',
        'is_current' => true,
        'extra_vehicles' => [
            ['booking_id' => $v2->id, 'truck_type_id' => $truckType->id],
            ['booking_id' => $v3->id, 'truck_type_id' => $truckType->id],
        ],
    ]);
    foreach ([$v1, $v2, $v3] as $member) {
        $member->update(['quotation_id' => $quotation->id]);
    }

    $invoice = Invoice::create([
        'booking_id' => $v1->id,
        'quotation_id' => $quotation->id,
        'subtotal' => 12000,
        'additional_fee' => 2000,
        'discount' => 440,
        'total' => 15000,
        'status' => 'issued',
        'is_current' => true,
    ]);

    return ['bookings' => [$v1, $v2, $v3], 'quotation' => $quotation, 'invoice' => $invoice];
}

function irgIssueReceipt(Booking $booking, ?Invoice $invoice, string $number): Receipt
{
    return Receipt::create([
        'booking_id' => $booking->id,
        'invoice_id' => $invoice?->id,
        'generated_by' => irgDispatcher()->id,
        'receipt_number' => $number,
        'pdf_path' => 'documents/receipts/booking-' . $booking->id . '-receipt.pdf',
        'email_sent' => true,
    ]);
}

/** Everything a blocked correction must leave byte-for-byte unchanged. */
function irgSnapshot(array $bookings, ?Quotation $quotation = null): array
{
    $ids = array_map(fn ($b) => $b->id, $bookings);

    return [
        'bookings' => Booking::whereIn('id', $ids)->orderBy('id')->get()->map(fn ($b) => $b->only([
            'status', 'computed_total', 'final_total', 'additional_fee', 'discount_percentage',
            'vat_exclusive_total', 'vat_amount', 'payment_method', 'cash_received',
            'payment_submitted_at', 'completed_at',
        ]))->all(),
        'invoices' => Invoice::orderBy('id')->get()->map(fn ($i) => $i->only([
            'id', 'booking_id', 'invoice_number', 'status', 'is_current', 'subtotal', 'additional_fee',
            'discount', 'total', 'previous_invoice_id', 'original_invoice_id', 'voided_at', 'void_reason',
        ]))->all(),
        'receipts' => Receipt::orderBy('id')->get()->map(fn ($r) => $r->only([
            'id', 'booking_id', 'invoice_id', 'receipt_number', 'pdf_path', 'email_sent',
        ]))->all(),
        'quotation' => $quotation ? Quotation::find($quotation->id)->only([
            'status', 'estimated_price', 'additional_fee', 'discount', 'is_current', 'version', 'extra_vehicles',
        ]) : null,
        'corrections_audited' => AuditLog::where('action', 'invoice_voided_replaced')->count(),
    ];
}

// ------------------------------------------- UI / backend agreement
//
// The dispatcher booking-detail bundle must expose the SAME lock decision the
// Void & Replace endpoint enforces (correction_locked_by_receipt), so the UI
// never offers a correction the backend rejects, nor hides one it allows.
// Notably it is NOT "data.receipt exists": a legacy sibling that only shares a
// group_code is not part of this booking's transaction.

/**
 * Builds one scenario and returns [viewFrom booking, invoice, expectedLocked].
 * $viewFrom is the booking whose detail modal the dispatcher opens.
 */
function irgAgreementScenario(string $name, string $status): array
{
    switch ($name) {
        case 'solo, no receipt':
            ['booking' => $b, 'invoice' => $i] = irgSolo($status);
            return [$b, $i, false];

        case 'solo, receipt':
            ['booking' => $b, 'invoice' => $i] = irgSolo($status);
            irgIssueReceipt($b, $i, 'R-AGREE-SOLO');
            return [$b, $i, true];

        case 'solo, receipt attached only via invoice_id':
            ['booking' => $b, 'invoice' => $i] = irgSolo($status);
            $other = irgBooking(irgCustomer(), irgTruckType(), ['status' => 'completed']);
            irgIssueReceipt($other, $i, 'R-AGREE-BYINVOICE');
            return [$b, $i, true];

        case 'legacy group, no receipt':
        case 'legacy group, SIBLING has a receipt':
            // Legacy / non-normalized shape: same group_code, no booking_id extras on
            // any quotation, so each booking is its own transaction with its own invoice.
            $customer = irgCustomer();
            $truck = irgTruckType();
            $group = 'GRP-LEG-' . uniqid();
            $a = irgBooking($customer, $truck, ['group_code' => $group, 'status' => $status]);
            $sibling = irgBooking($customer, $truck, ['group_code' => $group, 'status' => 'completed']);
            $invA = Invoice::create(['booking_id' => $a->id, 'subtotal' => 4000, 'additional_fee' => 0, 'discount' => 0, 'total' => 4480, 'status' => 'issued', 'is_current' => true]);
            $invSibling = Invoice::create(['booking_id' => $sibling->id, 'subtotal' => 4000, 'additional_fee' => 0, 'discount' => 0, 'total' => 4480, 'status' => 'issued', 'is_current' => true]);
            if ($name === 'legacy group, SIBLING has a receipt') {
                irgIssueReceipt($sibling, $invSibling, 'R-AGREE-LEGACY-SIBLING');
            }
            return [$a, $invA, false];
    }

    // normalized groups: "normalized group, receipt on <anchor|sibling|third>" / "normalized group, no receipt"
    ['bookings' => $members, 'invoice' => $invoice] = irgGroup($status);
    $owners = ['anchor' => 0, 'sibling' => 1, 'third' => 2];
    foreach ($owners as $label => $index) {
        if ($name === "normalized group, receipt on {$label}") {
            // Real generateReceipt() sets invoice_id to the receipt booking's OWN current
            // invoice: only the anchor owns the consolidated invoice; a sibling/third
            // member's receipt therefore has invoice_id NULL and can lock ONLY through
            // booking-membership in the transaction.
            irgIssueReceipt($members[$index], $index === 0 ? $invoice : null, "R-AGREE-GRP-{$label}");
            // Open the modal from a NON-anchor vehicle to prove group-wide semantics.
            return [$members[1], $invoice, true];
        }
    }

    return [$members[1], $invoice, false]; // 'normalized group, no receipt'
}

it('the bundle flag agrees with the backend: locked <=> the correction is rejected, unlocked <=> it is allowed', function (string $scenario, string $status) {
    [$viewFrom, $invoice, $expectLocked] = irgAgreementScenario($scenario, $status);
    $dispatcher = irgDispatcher();

    // What the UI sees...
    $bundle = test()->actingAs($dispatcher)->getJson(route('admin.booking.detail-bundle', $viewFrom))->assertOk()->json();
    expect($bundle['invoice']['id'])->toBe($invoice->id, 'the modal must be offering THIS invoice');
    expect($bundle['correction_locked_by_receipt'])->toBe($expectLocked);

    // ...must match what the backend actually does with the same invoice.
    $response = test()->actingAs($dispatcher)->postJson(route('admin.invoices.void', $invoice), ['reason' => 'Agreement probe']);

    if ($expectLocked) {
        $response->assertStatus(422);
        expect($response->json('message'))->toContain('receipt');
    } else {
        $response->assertOk()->assertJsonPath('success', true);
    }
})->with(function () {
    $scenarios = [
        'solo, no receipt',
        'solo, receipt',
        'solo, receipt attached only via invoice_id',
        'legacy group, no receipt',
        'legacy group, SIBLING has a receipt',
        'normalized group, no receipt',
        'normalized group, receipt on anchor',
        'normalized group, receipt on sibling',
        'normalized group, receipt on third',
    ];
    foreach ($scenarios as $scenario) {
        foreach (['waiting_verification', 'completed'] as $status) {
            yield "{$scenario} / {$status}" => [$scenario, $status];
        }
    }
});

it('legacy: the bundle may still DISPLAY the sibling receipt, but that must not lock this booking', function () {
    [$a, $invA] = irgAgreementScenario('legacy group, SIBLING has a receipt', 'waiting_verification');

    $bundle = test()->actingAs(irgDispatcher())->getJson(route('admin.booking.detail-bundle', $a))->assertOk()->json();

    // Existing display lookup (group_code-wide) still surfaces the sibling's receipt...
    expect($bundle['receipt'])->not->toBeNull();
    expect($bundle['receipt']['receipt_number'])->toBe('R-AGREE-LEGACY-SIBLING');
    // ...which is exactly why data.receipt is NOT a valid lock signal.
    expect($bundle['correction_locked_by_receipt'])->toBeFalse();
});

// ---------------------------------------------------------------- solo

it('solo: an issued receipt blocks correction whatever the booking status, and nothing changes', function (string $status) {
    ['booking' => $booking, 'invoice' => $invoice] = irgSolo($status);
    irgIssueReceipt($booking, $invoice, 'R-IRG-SOLO-' . $status);
    $before = irgSnapshot([$booking]);

    // Direct API request - proves the guard does not depend on the UI hiding the button.
    $response = test()->actingAs(irgDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Attempt after receipt issuance', 'discount_percentage' => 10]
    );

    $response->assertStatus(422)->assertJsonPath('success', false);
    expect($response->json('message'))->toContain('receipt');
    expect(irgSnapshot([$booking]))->toBe($before);
})->with(['waiting_verification', 'completed']);

it('solo: with no receipt, both waiting_verification and completed remain correctable, with correct lineage', function (string $status) {
    ['booking' => $booking, 'invoice' => $invoice] = irgSolo($status);

    test()->actingAs(irgDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Correction with no receipt', 'discount_percentage' => 10]
    )->assertOk()->assertJsonPath('success', true);

    $old = $invoice->fresh();
    $new = Invoice::where('booking_id', $booking->id)->where('is_current', true)->first();

    expect($old->status)->toBe('voided')->and($old->is_current)->toBeFalse()
        ->and($new)->not->toBeNull()
        ->and($new->previous_invoice_id)->toBe($old->id)
        ->and($new->original_invoice_id)->toBe($old->id)
        ->and(Invoice::where('booking_id', $booking->id)->where('is_current', true)->count())->toBe(1)
        ->and(Receipt::count())->toBe(0);
})->with(['waiting_verification', 'completed']);

it('solo: a receipt attached to the invoice being corrected blocks it even if its booking_id points elsewhere', function () {
    ['booking' => $booking, 'invoice' => $invoice] = irgSolo('waiting_verification');
    $other = irgBooking(irgCustomer(), irgTruckType(), ['status' => 'completed']);
    irgIssueReceipt($other, $invoice, 'R-IRG-BYINVOICE');
    $before = irgSnapshot([$booking, $other]);

    $response = test()->actingAs(irgDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Attempt: receipt exists via the invoice relationship']
    );

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('receipt');
    expect(irgSnapshot([$booking, $other]))->toBe($before);
});

// ------------------------------------------------------------- grouped

it('grouped: a receipt on ANY member blocks correction, wherever it is attached (anchor, sibling or third vehicle)', function (string $status, int $ownerIndex) {
    ['bookings' => $members, 'quotation' => $quotation, 'invoice' => $invoice] = irgGroup($status);
    // Realistic ownership (see irgAgreementScenario): only the anchor's receipt references
    // the consolidated invoice; a sibling/third receipt has invoice_id NULL.
    irgIssueReceipt($members[$ownerIndex], $ownerIndex === 0 ? $invoice : null, "R-IRG-GRP-{$status}-{$ownerIndex}");
    $before = irgSnapshot($members, $quotation);
    $dispatcher = irgDispatcher();

    // 1) reason-only replacement
    $a = test()->actingAs($dispatcher)->postJson(route('admin.invoices.void', $invoice), ['reason' => 'Reason-only attempt']);
    // 2) per-vehicle correction initiated from a non-anchor member
    $b = test()->actingAs($dispatcher)->postJson(route('admin.invoices.void', $invoice), [
        'reason' => 'Per-vehicle attempt',
        'member_booking_id' => $members[1]->id,
        'additional_fee' => 500,
    ]);

    $a->assertStatus(422);
    $b->assertStatus(422);
    expect($a->json('message'))->toContain('receipt')->and($b->json('message'))->toContain('receipt');
    expect(irgSnapshot($members, $quotation))->toBe($before);
})->with([
    'waiting_verification / anchor' => ['waiting_verification', 0],
    'waiting_verification / sibling' => ['waiting_verification', 1],
    'waiting_verification / third vehicle' => ['waiting_verification', 2],
    'completed / anchor' => ['completed', 0],
    'completed / sibling' => ['completed', 1],
    'completed / third vehicle' => ['completed', 2],
]);

it('grouped: with no receipt, both waiting_verification and completed stay correctable, with correct lineage and an untouched quotation', function (string $status) {
    ['bookings' => $members, 'quotation' => $quotation, 'invoice' => $invoice] = irgGroup($status);
    $quotationBefore = irgSnapshot($members, $quotation)['quotation'];

    test()->actingAs(irgDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Group correction with no receipt']
    )->assertOk()->assertJsonPath('success', true);

    $old = $invoice->fresh();
    $new = Invoice::where('is_current', true)->first();

    expect($old->status)->toBe('voided')->and($old->is_current)->toBeFalse()
        ->and($new->id)->not->toBe($old->id)
        ->and($new->previous_invoice_id)->toBe($old->id)
        ->and($new->original_invoice_id)->toBe($old->id)
        ->and((float) $new->total)->toBe(15000.0)
        ->and(Invoice::where('is_current', true)->count())->toBe(1)
        ->and(Receipt::count())->toBe(0)
        ->and(irgSnapshot($members, $quotation)['quotation'])->toBe($quotationBefore);
})->with(['waiting_verification', 'completed']);
