<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\User;

function ivrRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ivrDispatcher(): User
{
    return User::factory()->create(['role_id' => ivrRole(2, 'Dispatcher')->id, 'status' => 'active', 'must_change_password' => false]);
}

function ivrTeamLeader(): User
{
    return User::factory()->create(['role_id' => ivrRole(3, 'Team Leader')->id, 'status' => 'active', 'must_change_password' => false]);
}

function ivrCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'IVR Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'ivr-' . uniqid() . '@example.com',
    ]);
}

function ivrTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'IVR Truck ' . fake()->unique()->word(),
        'base_rate' => 2500,
        'per_km_rate' => 120,
    ]);
}

// gross 3820, no discount/fee -> subtotal 3820, VAT 12% -> 458.4, final 4278.4
function ivrBooking(TruckType $truckType, Customer $customer, array $overrides = []): Booking
{
    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup Point',
        'dropoff_address' => 'Dropoff Point',
        'distance_km' => 15,
        'base_rate' => 2500,
        'per_km_rate' => 120,
        'computed_total' => 3820,
        'discount_percentage' => 0,
        'additional_fee' => 0,
        'vat_exclusive_total' => 3820,
        'vat_amount' => 458.4,
        'final_total' => 4278.4,
        'status' => 'waiting_verification',
        'service_type' => 'book_now',
        'payment_method' => 'cash',
        'cash_received' => 4278.4,
    ], $overrides));
    $booking->update(['booking_code' => 'TM-IVR' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

function ivrInvoice(Booking $booking, array $overrides = []): Invoice
{
    return Invoice::create(array_merge([
        'booking_id' => $booking->id,
        'subtotal' => (float) $booking->vat_exclusive_total,
        'additional_fee' => (float) $booking->additional_fee,
        'discount' => 0,
        'total' => (float) $booking->final_total,
        'status' => 'issued',
        'is_current' => true,
    ], $overrides));
}

it('1: dispatcher can void and replace a solo invoice with a corrected amount (cash, sufficient)', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer());
    $invoice = ivrInvoice($booking);

    $response = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Wrong discount applied', 'additional_fee' => 0, 'discount_percentage' => 10]
    );

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();
    // gross 3820, 10% discount -> subtotal 3438, VAT -> 412.56, final 3850.56
    expect((float) $booking->discount_percentage)->toBe(10.0)
        ->and((float) $booking->vat_exclusive_total)->toBe(3438.0)
        ->and((float) $booking->final_total)->toBe(3850.56);

    $oldInvoice = $invoice->fresh();
    expect($oldInvoice->status)->toBe('voided')
        ->and($oldInvoice->is_current)->toBeFalse()
        ->and($oldInvoice->void_reason)->toBe('Wrong discount applied');

    $newInvoice = Invoice::where('is_current', true)->where('booking_id', $booking->id)->first();
    expect($newInvoice)->not->toBeNull()
        ->and((float) $newInvoice->total)->toBe(3850.56)
        ->and($newInvoice->previous_invoice_id)->toBe($oldInvoice->id)
        ->and($newInvoice->original_invoice_id)->toBe($oldInvoice->id)
        ->and($newInvoice->invoice_number)->not->toBe($oldInvoice->invoice_number);
});

it('2: a reason is required', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer());
    $invoice = ivrInvoice($booking);

    $response = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['additional_fee' => 100]
    );

    $response->assertStatus(422);
    expect(Invoice::where('is_current', true)->first()->id)->toBe($invoice->id);
});

// Test 3 previously asserted that a grouped/consolidated invoice was
// blanket-rejected here. Grouped Void & Replace is now implemented (routed
// via isGroupedBooking() to InvoiceController::voidGroup()) — see the
// dedicated tests\Feature\Admin\GroupedInvoiceVoidReplaceTest.php for full
// coverage of that path. This solo test file only covers non-grouped
// invoices from here on, so that case was removed rather than repurposed.

it('4: a booking that never reached the invoice-issuance boundary is rejected', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer(), ['status' => 'arrived_dropoff']);
    $invoice = ivrInvoice($booking);

    $response = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Wrong total']
    );

    $response->assertStatus(422);
    expect(Invoice::where('is_current', true)->first()->id)->toBe($invoice->id);
});

it('5: an already-voided invoice cannot be voided again', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer());
    $invoice = ivrInvoice($booking, ['status' => 'voided', 'is_current' => false, 'voided_at' => now()]);

    $response = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Second attempt']
    );

    $response->assertStatus(422);
});

it('6: cash payment insufficient for the corrected (increased) total is blocked', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer(), ['cash_received' => 4278.4]);
    $invoice = ivrInvoice($booking);

    $response = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Add missed surcharge', 'additional_fee' => 2000]
    );

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Cash received');

    $booking->refresh();
    expect((float) $booking->additional_fee)->toBe(0.0);
    expect(Invoice::where('is_current', true)->first()->id)->toBe($invoice->id);
});

it('7: cash payment that still covers the corrected total succeeds', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer(), ['cash_received' => 5000]);
    $invoice = ivrInvoice($booking);

    $response = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Add missed surcharge', 'additional_fee' => 500]
    );

    $response->assertOk();
    $booking->refresh();
    expect((float) $booking->additional_fee)->toBe(500.0);
});

it('8: a non-cash payment with a changed total requires explicit dispatcher verification', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer(), [
        'payment_method' => 'gcash',
        'cash_received' => null,
    ]);
    $invoice = ivrInvoice($booking);

    $blocked = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Add missed surcharge', 'additional_fee' => 500]
    );
    $blocked->assertStatus(422);
    expect($blocked->json('message'))->toContain('verified');
    expect(Invoice::where('is_current', true)->first()->id)->toBe($invoice->id);

    $allowed = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Add missed surcharge', 'additional_fee' => 500, 'payment_verified' => true]
    );
    $allowed->assertOk();
    expect((float) $booking->fresh()->additional_fee)->toBe(500.0);
});

it('9: a non-cash payment with no amount change does not require verification', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer(), [
        'payment_method' => 'bank_transfer',
        'cash_received' => null,
    ]);
    $invoice = ivrInvoice($booking);

    $response = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Fix customer name on PDF only']
    );

    $response->assertOk();
});

it('10: dispatcher discount/charge limits are still enforced on the correction', function () {
    SystemSetting::setValue('max_additional_charge', 100);
    $booking = ivrBooking(ivrTruckType(), ivrCustomer());
    $invoice = ivrInvoice($booking);

    $response = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Add surcharge', 'additional_fee' => 999]
    );

    $response->assertStatus(422);
    expect(Invoice::where('is_current', true)->first()->id)->toBe($invoice->id);
});

it('11: an unauthorized role cannot use the void-and-replace endpoint', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer());
    $invoice = ivrInvoice($booking);

    $response = test()->actingAs(ivrTeamLeader())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Should not be allowed']
    );

    $response->assertStatus(403);
    expect(Invoice::where('is_current', true)->first()->id)->toBe($invoice->id);
});

it('12: a booking that already has a receipt cannot have its invoice corrected', function () {
    $dispatcher = ivrDispatcher();
    $booking = ivrBooking(ivrTruckType(), ivrCustomer(), ['status' => 'completed']);
    $invoice = ivrInvoice($booking);
    Receipt::create([
        'booking_id' => $booking->id,
        'invoice_id' => $invoice->id,
        'generated_by' => $dispatcher->id,
        'receipt_number' => 'R-IVR-12',
        'pdf_path' => 'documents/receipts/booking-' . $booking->id . '-receipt.pdf',
    ]);

    $response = test()->actingAs($dispatcher)->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Attempted correction after receipt']
    );

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('receipt');

    $invoice->refresh();
    expect($invoice->status)->toBe('issued')
        ->and($invoice->is_current)->toBeTrue();
    expect(Invoice::count())->toBe(1);
});

it('13: waiting_verification with no receipt succeeds and creates no Receipt as a side effect', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer(), ['status' => 'waiting_verification']);
    $invoice = ivrInvoice($booking);

    $response = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Waiting-verification correction, no receipt yet', 'discount_percentage' => 10]
    );

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();
    expect((float) $booking->discount_percentage)->toBe(10.0)
        ->and((float) $booking->final_total)->toBe(3850.56);

    $oldInvoice = $invoice->fresh();
    $newInvoice = Invoice::where('is_current', true)->where('booking_id', $booking->id)->first();
    expect($oldInvoice->status)->toBe('voided')
        ->and($oldInvoice->is_current)->toBeFalse();
    expect($newInvoice)->not->toBeNull()
        ->and($newInvoice->status)->toBe('issued')
        ->and($newInvoice->is_current)->toBeTrue()
        ->and($newInvoice->previous_invoice_id)->toBe($oldInvoice->id)
        ->and($newInvoice->original_invoice_id)->toBe($oldInvoice->id);

    expect(Receipt::where('booking_id', $booking->id)->count())->toBe(0);
});

it('14: completed status with no receipt still succeeds as an exceptional/recovery correction and creates no Receipt as a side effect', function () {
    $booking = ivrBooking(ivrTruckType(), ivrCustomer(), ['status' => 'completed']);
    $invoice = ivrInvoice($booking);

    $response = test()->actingAs(ivrDispatcher())->postJson(
        route('admin.invoices.void', $invoice),
        ['reason' => 'Post-completion correction before any receipt exists', 'discount_percentage' => 10]
    );

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();
    expect((float) $booking->discount_percentage)->toBe(10.0)
        ->and((float) $booking->final_total)->toBe(3850.56);

    $oldInvoice = $invoice->fresh();
    $newInvoice = Invoice::where('is_current', true)->where('booking_id', $booking->id)->first();
    expect($oldInvoice->status)->toBe('voided')
        ->and($oldInvoice->is_current)->toBeFalse();
    expect($newInvoice)->not->toBeNull()
        ->and($newInvoice->status)->toBe('issued')
        ->and($newInvoice->is_current)->toBeTrue()
        ->and($newInvoice->previous_invoice_id)->toBe($oldInvoice->id)
        ->and($newInvoice->original_invoice_id)->toBe($oldInvoice->id);

    expect(Receipt::where('booking_id', $booking->id)->count())->toBe(0);
});
