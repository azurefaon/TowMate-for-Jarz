<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\User;
use App\Services\DocumentGenerationService;
use Illuminate\Support\Facades\Storage;

function irUser(int $roleId, string $name): User
{
    if (! Role::find($roleId)) {
        $role = new Role(['name' => $name]);
        $role->id = $roleId;
        $role->save();
    }

    return User::factory()->create(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false]);
}

function irOwner(): User
{
    return irUser(1, 'Owner');
}

function irBooking(array $overrides = []): Booking
{
    $customer = Customer::create([
        'full_name' => 'Maria Santos',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'ir-' . uniqid() . '@example.com',
    ]);
    $truck = TruckType::firstOrCreate(['name' => 'IR Light'], ['base_rate' => 1500, 'per_km_rate' => 60]);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pickup ' . uniqid(),
        'dropoff_address' => 'Dropoff ' . uniqid(),
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 2100,
        'vat_exclusive_total' => 2100,
        'vat_amount' => 252,
        'final_total' => 2352,
        'service_type' => 'book_now',
        'status' => 'waiting_verification',
    ], $overrides));
}

/** Subtotal 2,100 + 12% VAT 252 = 2,352 (base 1,500 + 10 km x 60). */
function irInvoice(Booking $booking, array $overrides = []): Invoice
{
    return Invoice::create(array_merge([
        'booking_id' => $booking->id,
        'subtotal' => 2100,
        'additional_fee' => 0,
        'discount' => 0,
        'total' => 2352,
        'status' => 'issued',
        'is_current' => true,
        'pdf_path' => null,
    ], $overrides));
}

function irPaidBooking(string $receiptNumber = 'R-IR-0001'): array
{
    $booking = irBooking([
        'status' => 'completed', 'completed_at' => now(), 'payment_method' => 'cash',
        'payment_submitted_at' => now()->subHour(), 'cash_received' => 2500,
    ]);
    $invoice = irInvoice($booking);
    $dispatcher = irUser(2, 'Dispatcher');
    AuditLog::create(['user_id' => $dispatcher->id, 'action' => 'payment_confirmed', 'entity_type' => 'Booking', 'entity_id' => $booking->id, 'reference' => $booking->job_code, 'description' => 'Job completed']);
    $receipt = Receipt::create(['booking_id' => $booking->id, 'invoice_id' => $invoice->id, 'generated_by' => $dispatcher->id, 'receipt_number' => $receiptNumber, 'pdf_path' => 'documents/receipts/r-ir.pdf']);

    return [$booking, $invoice, $receipt, $dispatcher];
}

function irDocs($test, Booking $booking): array
{
    return $test->actingAs(irOwner())->getJson(route('superadmin.bookings.show', $booking->job_code))
        ->assertOk()->json('documents');
}

function irInvoiceUrl(Booking $booking, Invoice $invoice, string $name = 'show'): string
{
    return route("superadmin.bookings.invoices.{$name}", ['id' => $booking->job_code, 'invoice' => $invoice->id]);
}

// ---------------------------------------------------------------- booking details modal

it('shows an empty state when no invoice or receipt exists', function () {
    $booking = irBooking();
    $docs = irDocs($this, $booking);

    expect($docs['invoices'])->toBe([])
        ->and($docs['receipt'])->toBeNull()
        ->and($docs['payment']['value'])->toBe('Not submitted');

    $this->actingAs(irOwner())->get(route('superadmin.bookings.receipt.show', $booking->job_code))->assertNotFound();
});

it('gives the modal the current invoice summary and viewer links, not PDF links', function () {
    $booking = irBooking();
    $invoice = irInvoice($booking, ['pdf_path' => 'documents/invoices/x.pdf']);

    $docs = irDocs($this, $booking);

    expect($docs['invoices'])->toHaveCount(1)
        ->and($docs['invoices'][0]['number'])->toBe($invoice->invoice_number)
        ->and($docs['invoices'][0]['state'])->toBe('current')
        ->and($docs['invoices'][0]['amount'])->toContain('2,352')
        ->and($docs['invoices'][0]['url'])->toBe(irInvoiceUrl($booking, $invoice));
});

it('labels a superseded invoice Replaced and keeps both versions with lineage', function () {
    $booking = irBooking();
    $old = irInvoice($booking);
    $new = $old->voidAndReplace('Wrong additional fee', ['total' => 2500], 1);

    [$first, $second] = irDocs($this, $booking)['invoices'];

    expect($first['number'])->toBe($new->invoice_number)
        ->and($first['state'])->toBe('current')
        ->and($first['replaces'])->toBe($old->invoice_number)
        ->and($second['number'])->toBe($old->invoice_number)
        ->and($second['state'])->toBe('replaced')
        ->and($second['void_reason'])->toBe('Wrong additional fee')
        ->and(Invoice::where('booking_id', $booking->id)->count())->toBe(2);
});

// ---------------------------------------------------------------- invoice viewer

it('renders a current invoice with company details, amounts, VAT and history', function () {
    SystemSetting::setValue('company_name', 'JARZ Fixture Towing');
    SystemSetting::setValue('company_phone', '+63 900 111 2222');
    SystemSetting::setValue('company_email', 'billing@fixture.test');
    $booking = irBooking();
    $invoice = irInvoice($booking);

    $response = $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk();

    $response->assertSeeInOrder(['INVOICE', 'Current', 'BILLED TO', 'Maria Santos', $invoice->invoice_number, $booking->job_code, 'Towing service', 'Distance charge', 'Subtotal', 'VAT', 'TOTAL'], false)
        ->assertSee('₱2,100.00', false)->assertSee('₱252.00', false)->assertSee('₱2,352.00', false)
        ->assertSee('JARZ Fixture Towing', false)->assertSee('billing@fixture.test', false)
        ->assertSee('INVOICE HISTORY', false)->assertSee('Owner · Read-only', false)
        ->assertDontSee('LOGO', false);
    expect($response->getContent())->toContain('data:image/png;base64');
});

it('renders a replaced invoice with its note, a voided one without a successor, and the version history', function () {
    $booking = irBooking();
    $old = irInvoice($booking);
    $new = $old->voidAndReplace('Wrong additional fee', ['total' => 2500, 'additional_fee' => 148], 1);
    $owner = irOwner();

    $replaced = $this->actingAs($owner)->get(irInvoiceUrl($booking, $old))->assertOk();
    $replaced->assertSee('Replaced', false)->assertSee('Replaced by ' . $new->invoice_number, false)
        ->assertSee('Reason: Wrong additional fee', false);

    $current = $this->actingAs($owner)->get(irInvoiceUrl($booking, $new))->assertOk();
    $current->assertSee('Replaces', false)->assertSee($old->invoice_number, false)->assertSee('Additional fee', false)
        ->assertSee('Viewing', false);

    $lone = irBooking();
    $voided = irInvoice($lone, ['status' => 'voided', 'is_current' => false, 'voided_at' => now(), 'void_reason' => 'Duplicate']);
    $this->actingAs($owner)->get(irInvoiceUrl($lone, $voided))->assertOk()
        ->assertSee('Voided on', false)->assertSee('Reason: Duplicate', false)->assertDontSee('Replaced by', false);
});

it('shows group invoices as one line per vehicle with the adjustment once', function () {
    $customer = Customer::create(['full_name' => 'Group Customer', 'phone' => '0917' . fake()->unique()->numerify('#######')]);
    $code = 'GRP-' . fake()->unique()->numerify('######');
    $make = fn (float $total, string $truck) => irBooking([
        'customer_id' => $customer->id, 'group_code' => $code, 'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff',
        'final_total' => $total, 'truck_type_id' => TruckType::firstOrCreate(['name' => $truck], ['base_rate' => 1500, 'per_km_rate' => 60])->id,
    ]);
    $a = $make(4000, 'Light');
    $b = $make(5000, 'Medium');
    $lines = [['booking_id' => $a->id, 'final_total' => 4000], ['booking_id' => $b->id, 'final_total' => 5000]];
    $quotation = Quotation::create([
        'quotation_number' => 'Q-' . uniqid(), 'source_booking_id' => $a->id, 'customer_id' => $customer->id, 'truck_type_id' => $a->truck_type_id,
        'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff', 'distance_km' => 10, 'estimated_price' => 9200,
        'status' => 'accepted', 'extra_vehicles' => $lines,
    ]);
    $a->update(['quotation_id' => $quotation->id]);
    $b->update(['quotation_id' => $quotation->id]);
    $invoice = irInvoice($a, ['subtotal' => 8000, 'additional_fee' => 500, 'discount' => 300, 'total' => 9200, 'quotation_id' => $quotation->id]);

    $this->actingAs(irOwner())->get(irInvoiceUrl($b, $invoice))->assertOk()
        ->assertSee('Vehicle 1', false)->assertSee('Vehicle 2', false)
        ->assertSee('₱4,000.00', false)->assertSee('₱5,000.00', false)->assertSee('₱9,000.00', false)->assertSee('₱500.00', false)->assertSee('₱300.00', false)
        ->assertSee('₱9,200.00', false);
});

// ---------------------------------------------------------------- secure PDFs

it('streams invoice and receipt PDFs to the Owner only, inline or as a download', function () {
    Storage::fake('local');
    [$booking, $invoice, $receipt] = irPaidBooking();
    $invoice->update(['pdf_path' => 'documents/invoices/paid.pdf']);
    Storage::disk('local')->put('documents/invoices/paid.pdf', '%PDF-invoice');
    Storage::disk('local')->put('documents/receipts/r-ir.pdf', '%PDF-receipt');
    $owner = irOwner();

    $inline = $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice, 'pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($inline->headers->get('Content-Disposition'))->toContain('inline')
        ->and($inline->headers->get('Cache-Control'))->toContain('no-store');

    $download = $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice, 'pdf') . '?download=1')->assertOk();
    expect($download->headers->get('Content-Disposition'))->toContain('attachment');

    $receiptPdf = route('superadmin.bookings.receipt.pdf', $booking->job_code);
    $this->actingAs($owner)->get($receiptPdf . '?download=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');

    foreach ([[2, 'Dispatcher'], [6, 'System Admin'], [5, 'Customer']] as [$roleId, $name]) {
        $user = irUser($roleId, $name);
        $this->actingAs($user)->get(irInvoiceUrl($booking, $invoice, 'pdf'))->assertForbidden();
        $this->actingAs($user)->get($receiptPdf)->assertForbidden();
        $this->actingAs($user)->get(irInvoiceUrl($booking, $invoice))->assertForbidden();
    }

    auth()->logout();
    $this->flushSession();
    $this->get($receiptPdf)->assertRedirect();
});

it('404s for another booking\'s invoice or receipt, a missing PDF and unknown ids', function () {
    Storage::fake('local');
    $mine = irBooking();
    [$other, $foreign] = [irBooking(), null];
    $foreign = irInvoice($other, ['pdf_path' => 'documents/invoices/foreign.pdf']);
    Storage::disk('local')->put('documents/invoices/foreign.pdf', '%PDF-foreign');
    $noPdf = irInvoice($mine);
    $owner = irOwner();

    foreach (['show', 'pdf'] as $name) {
        $this->actingAs($owner)->get(irInvoiceUrl($mine, $foreign, $name))->assertNotFound();
    }
    $this->actingAs($owner)->get(irInvoiceUrl($mine, $noPdf, 'pdf'))->assertNotFound();
    $this->actingAs($owner)->get(route('superadmin.bookings.invoices.show', ['id' => $mine->job_code, 'invoice' => 999999]))->assertNotFound();

    [$paid] = irPaidBooking();
    $this->actingAs($owner)->get(route('superadmin.bookings.receipt.show', $mine->job_code))->assertNotFound();
    expect(irDocs($this, $paid)['receipt'])->not->toBeNull()
        ->and(irDocs($this, $mine)['receipt'])->toBeNull()
        ->and(collect(irDocs($this, $mine)['invoices'])->pluck('number')->all())->toBe([$noPdf->invoice_number]);
});


// ---------------------------------------------------------------- receipt viewer

it('renders a verified payment receipt with references and no invented reference', function () {
    [$booking, $invoice] = irPaidBooking();

    $this->actingAs(irOwner())->get(route('superadmin.bookings.receipt.show', $booking->job_code))->assertOk()
        ->assertSeeInOrder(['PAYMENT RECEIPT', 'RECEIVED FROM', 'Maria Santos', 'R-IR-0001', 'AMOUNT RECEIVED', '₱2,352.00', 'PAYMENT DETAILS', 'Payment method', 'Cash', 'Payment reference', 'Not available', 'Cash received', '₱2,500.00', 'Verification', 'Verified by', $booking->job_code, $invoice->invoice_number], false)
        ->assertSee('Owner · Read-only', false)->assertDontSee('PAYMENT NOT VERIFIED', false);
});

it('never presents an unverified payment as received', function () {
    [$booking] = irPaidBooking();
    $booking->update(['status' => 'waiting_verification', 'completed_at' => null]);

    $this->actingAs(irOwner())->get(route('superadmin.bookings.receipt.show', $booking->job_code))->assertOk()
        ->assertSee('PAYMENT NOT VERIFIED', false)->assertDontSee('AMOUNT RECEIVED', false)->assertSee('Not verified', false);
});

// ---------------------------------------------------------------- email status

it('shows an email status only when a real record supports it', function () {
    [$booking, $invoice, $receipt] = irPaidBooking();
    $owner = irOwner();

    $page = $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice))->assertOk();
    $page->assertSee('Email Not Available', false)->assertSee('No email send record', false)
        ->assertDontSee('Email Sent', false)->assertDontSee('Email Pending', false)->assertDontSee('Email Failed', false);

    $invoice->update(['email_sent' => true]);
    $receipt->update(['email_sent' => true]);

    $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice))->assertSee('Email Sent', false)->assertSee($booking->customer->email, false);
    $this->actingAs($owner)->get(route('superadmin.bookings.receipt.show', $booking->job_code))->assertSee('Email Sent', false);

    $booking->customer->update(['email' => null]);
    $this->actingAs($owner)->get(irInvoiceUrl($booking->fresh(), $invoice))->assertSee('Email Not Available', false);
});

// ---------------------------------------------------------------- generated PDFs

it('generates the approved A4 invoice and receipt PDFs without touching amounts or older PDFs', function () {
    Storage::fake('local');
    $service = app(DocumentGenerationService::class);
    [$booking, $invoice, $receipt, $dispatcher] = irPaidBooking();
    $booking->update(['created_by_admin_id' => $dispatcher->id]);
    $older = irInvoice(irBooking(), ['pdf_path' => 'documents/invoices/historical.pdf']);
    Storage::disk('local')->put('documents/invoices/historical.pdf', 'HISTORICAL-BYTES');
    $before = $invoice->only(['subtotal', 'additional_fee', 'discount', 'total', 'status', 'is_current']);

    $generatedInvoice = $service->generateInvoice($invoice);
    $generatedReceipt = $service->generateReceipt($booking->fresh());

    foreach ([$generatedInvoice->pdf_path, $generatedReceipt->pdf_path] as $path) {
        $pdf = Storage::disk('local')->get($path);
        expect($pdf)->toStartWith('%PDF')->and(preg_match_all('/\/Type\s*\/Page\b/', $pdf))->toBe(1);
    }

    expect($invoice->fresh()->only(['subtotal', 'additional_fee', 'discount', 'total', 'status', 'is_current']))->toEqual($before)
        ->and(Storage::disk('local')->get('documents/invoices/historical.pdf'))->toBe('HISTORICAL-BYTES')
        ->and($older->fresh()->pdf_path)->toBe('documents/invoices/historical.pdf');
});

it('keeps the Owner read-only: void and payment confirmation stay denied', function () {
    $booking = irBooking();
    $invoice = irInvoice($booking);
    $owner = irOwner();

    $this->actingAs($owner)->postJson(route('admin.invoices.void', $invoice), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($owner)->postJson(route('admin.jobs.confirm-payment', $booking))->assertForbidden();

    expect($invoice->fresh()->status)->toBe('issued')
        ->and($booking->fresh()->status)->toBe('waiting_verification');
});

it('adds no Invoice, Receipt or Customers module to the Owner sidebar', function () {
    $html = $this->actingAs(irOwner())->get(route('superadmin.dashboard'))->getContent();

    expect($html)->not->toContain('>Invoices<')->not->toContain('>Receipts<')->not->toContain('>Customers<');
});

// ---------------------------------------------------------------- historical integrity






it('hides payment instructions on an invoice whose payment is already confirmed', function () {
    SystemSetting::setValue('bank_account_number', '0011223344');
    SystemSetting::setValue('gcash_number', '09171234567');
    $unpaid = irBooking();
    $unpaidInvoice = irInvoice($unpaid);
    [$paid, $paidInvoice] = irPaidBooking();

    $this->actingAs(irOwner())->get(irInvoiceUrl($unpaid, $unpaidInvoice))->assertOk()->assertSee('PAYMENT INSTRUCTIONS', false);
    $this->actingAs(irOwner())->get(irInvoiceUrl($paid, $paidInvoice))->assertOk()
        ->assertDontSee('PAYMENT INSTRUCTIONS', false)->assertDontSee('Awaiting payment', false);
});




// ---------------------------------------------------------------- financial accuracy




// ---------------------------------------------------------------- document content


// ---------------------------------------------------------------- email wording

it('words Email Sent as a completed send, never as confirmed delivery', function () {
    [$booking, $invoice] = irPaidBooking();
    $invoice->update(['email_sent' => true]);

    $page = $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk();

    $page->assertSee('Email Sent', false)->assertSee('Delivery to, and receipt by, the customer is not confirmed', false)
        ->assertDontSee('Delivered', false)->assertDontSee('Email Pending', false)->assertDontSee('Email Failed', false);
});

// ---------------------------------------------------------------- amount received accuracy

function irReceiptPage($test, Booking $booking)
{
    return $test->actingAs(irOwner())->get(route('superadmin.bookings.receipt.show', $booking->job_code))->assertOk();
}

it('shows the invoice total as received, with cash received separately, when stored cash covers it', function () {
    [$booking] = irPaidBooking(); // invoice total 2,352; stored cash received 2,500

    irReceiptPage($this, $booking)->assertSee('AMOUNT RECEIVED', false)->assertSee('class="doc-amount" style="background: #FACC15"', false)
        ->assertSee('Cash received', false)->assertSee('₱2,500.00', false)
        ->assertSee('Invoice total settled; cash received is shown separately.', false)
        ->assertDontSee('BELOW INVOICE TOTAL', false);
});

it('never presents full payment when the stored cash received is below the invoice total', function () {
    [$booking] = irPaidBooking();
    $booking->update(['cash_received' => 2000]); // stored, verified (completed) but short of 2,352

    $page = irReceiptPage($this, $booking);
    $page->assertSee('AMOUNT RECEIVED — BELOW INVOICE TOTAL', false)->assertSee('class="doc-amount" style="background: #E5E5E5"', false)
        ->assertDontSee('class="doc-amount" style="background: #FACC15"', false)
        ->assertSee('₱2,000.00', false)->assertSee('Invoice total', false)->assertSee('₱2,352.00', false)
        ->assertSee('full payment is not recorded', false);
    // The headline amount is the stored cash, not the invoice total.
    $html = $page->getContent();
    $at = strpos($html, 'class="av">');
    expect($at)->not->toBeFalse()
        ->and(trim(substr($html, $at + 11, 14)))->toStartWith('₱2,000.00');
});

it('states plainly that GCash and bank confirmations record no separate received amount', function () {
    [$booking] = irPaidBooking();
    $booking->update(['payment_method' => 'gcash', 'cash_received' => null]);

    irReceiptPage($this, $booking)->assertSee('AMOUNT RECEIVED', false)->assertSee('₱2,352.00', false)
        ->assertSee('as confirmed by the Dispatcher; no separate amount is stored.', false)
        ->assertDontSee('Cash received', false);
});



it('shows no amount basis and a grey panel for a payment that is not verified', function () {
    [$booking] = irPaidBooking();
    $booking->update(['status' => 'waiting_verification', 'completed_at' => null]);

    irReceiptPage($this, $booking)->assertSee('PAYMENT NOT VERIFIED', false)->assertSee('class="doc-amount" style="background: #E5E5E5"', false)
        ->assertDontSee('Amount basis', false)->assertDontSee('AMOUNT RECEIVED', false);
});

// ---------------------------------------------------------------- modern documents only

/** Every phrase of the old PDF-versus-preview interface that must never reach the Owner viewers. */
const IR_FORBIDDEN = [
    'Original issued PDF', 'Original Issued PDF', 'View Original PDF', 'Download Original PDF', 'Original PDF',
    'Authoritative', 'authoritative', 'exactly as it was issued', 'Preview generated from records',
    'not the original issued PDF', 'Modern preview', 'class="dv-tab', 'dv-embed', '<iframe', '<object', 'source=original',
    'PREVIEW', 'Franchisee', 'Awaiting payment', 'PLEASE MAKE PAYMENT TO',
];

function irAssertNoLegacyUi(string $html): void
{
    foreach (IR_FORBIDDEN as $phrase) {
        expect($html)->not->toContain($phrase);
    }
}

/** A booking set covering every document state, including stored (legacy-style) PDFs. */
function irEveryState(): array
{
    Storage::fake('local');
    $pdf = fn (string $path) => Storage::disk('local')->put($path, '%PDF-legacy Awaiting payment PLEASE MAKE PAYMENT TO Franchisee');

    $current = irBooking(); $currentInv = irInvoice($current, ['pdf_path' => 'documents/invoices/c.pdf']); $pdf('documents/invoices/c.pdf');

    $replaced = irBooking(); $old = irInvoice($replaced, ['pdf_path' => 'documents/invoices/old.pdf']); $pdf('documents/invoices/old.pdf');
    $new = $old->voidAndReplace('Typo', ['total' => 2500, 'additional_fee' => 148], 1);

    $voided = irBooking();
    $voidedInv = irInvoice($voided, ['status' => 'voided', 'is_current' => false, 'voided_at' => now(), 'void_reason' => 'Duplicate', 'pdf_path' => 'documents/invoices/v.pdf']); $pdf('documents/invoices/v.pdf');

    $unreliable = irBooking(); $unreliableInv = irInvoice($unreliable, ['total' => 3000, 'pdf_path' => 'documents/invoices/u.pdf']); $pdf('documents/invoices/u.pdf');

    $noPdf = irBooking(); $noPdfInv = irInvoice($noPdf);

    [$paid, $paidInv] = irPaidBooking(); $pdf('documents/receipts/r-ir.pdf');

    return [
        [$current, $currentInv], [$replaced, $old], [$replaced, $new], [$voided, $voidedInv],
        [$unreliable, $unreliableInv], [$noPdf, $noPdfInv], [$paid, $paidInv],
    ];
}

it('opens the modern invoice directly, with no document tabs, embedded PDF or legacy PDF wording in any state', function () {
    $owner = irOwner();

    foreach (irEveryState() as [$booking, $invoice]) {
        $html = $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice))->assertOk()->getContent();
        irAssertNoLegacyUi($html);
        expect($html)->toContain('Owner · Read-only');
    }
});

it('opens the modern receipt directly, with no document tabs, embedded PDF or legacy PDF wording', function () {
    [$booking] = irPaidBooking();
    Storage::fake('local');
    Storage::disk('local')->put('documents/receipts/r-ir.pdf', '%PDF-legacy PLEASE MAKE PAYMENT TO Franchisee');

    $html = $this->actingAs(irOwner())->get(route('superadmin.bookings.receipt.show', $booking->job_code))->assertOk()->getContent();

    irAssertNoLegacyUi($html);
    expect($html)->toContain('class="doc-sheet doc-receipt"')->toContain('AMOUNT RECEIVED')->toContain('PAYMENT DETAILS')
        ->not->toContain('DESCRIPTION')->not->toContain('Subtotal');
});

it('renders a bookmarked ?source=original URL as the same modern document', function () {
    Storage::fake('local');
    [$booking, $invoice] = irPaidBooking();
    Storage::disk('local')->put('documents/receipts/r-ir.pdf', '%PDF-r');
    $owner = irOwner();

    $plain = $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice))->assertOk()->getContent();
    $legacy = $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice) . '?source=original')->assertOk()->getContent();
    irAssertNoLegacyUi($legacy);
    expect($legacy)->toContain('class="doc-sheet doc-invoice"')->and(strip_tags($legacy))->toBe(strip_tags($plain));

    $receiptUrl = route('superadmin.bookings.receipt.show', $booking->job_code);
    $this->actingAs($owner)->get($receiptUrl . '?source=original')->assertOk()->assertSee('class="doc-sheet doc-receipt"', false)->assertDontSee('<iframe', false);
});

it('shows a short reference-only note instead of any preview or original-document messaging', function () {
    $booking = irBooking();
    $invoice = irInvoice($booking);

    $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk()
        ->assertSee("For reference only. Shown from the booking's stored records.", false)
        ->assertSee('This view is for reference only and is not printed.', false);
});

it('keeps invoice history, status badges, related links and email status on the modern viewer', function () {
    $booking = irBooking();
    $old = irInvoice($booking);
    $new = $old->voidAndReplace('Typo', ['total' => 2500, 'additional_fee' => 148], 1);
    $owner = irOwner();

    $page = $this->actingAs($owner)->get(irInvoiceUrl($booking, $old))->assertOk();
    $page->assertSee('INVOICE HISTORY', false)->assertSee($new->invoice_number, false)->assertSee(irInvoiceUrl($booking, $new), false)
        ->assertSee('Replaced', false)->assertSee('Reason: Typo', false)->assertSee('Version 1 of 2', false)
        ->assertSee('EMAIL DELIVERY', false)->assertSee('Email Not Available', false);

    [$paid, $paidInvoice] = irPaidBooking();
    $this->actingAs($owner)->get(irInvoiceUrl($paid, $paidInvoice))->assertOk()
        ->assertSee(route('superadmin.bookings.receipt.show', $paid->job_code), false)->assertSee('View receipt', false);
    $this->actingAs($owner)->get(route('superadmin.bookings.receipt.show', $paid->job_code))->assertOk()
        ->assertSee(irInvoiceUrl($paid, $paidInvoice), false)->assertSee('View invoice', false);
});

it('shows a simple unavailable state, with stored facts only, when the invoice records do not reconcile', function () {
    $booking = irBooking();
    $invoice = irInvoice($booking, ['total' => 3000, 'pdf_path' => 'documents/invoices/u.pdf']);

    $page = $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk();
    $html = $page->getContent();

    $page->assertSee('Invoice details unavailable', false)->assertSee('do not reconcile into an itemised breakdown', false)
        ->assertSee($invoice->invoice_number, false)->assertSee('₱3,000.00', false);
    irAssertNoLegacyUi($html);
    expect($html)->not->toContain('class="doc-sheet')->not->toContain('Subtotal')->not->toContain('>VAT<')->not->toContain('Distance charge');
});

it('shows a simple unavailable state when a receipt has no related invoice to confirm the amount', function () {
    [$booking, $invoice] = irPaidBooking();
    Receipt::where('booking_id', $booking->id)->update(['invoice_id' => null]);
    $invoice->delete();

    $page = $this->actingAs(irOwner())->get(route('superadmin.bookings.receipt.show', $booking->job_code))->assertOk();
    $page->assertSee('Receipt details unavailable', false)->assertSee('cannot be confirmed', false)->assertSee('R-IR-0001', false);
    irAssertNoLegacyUi($page->getContent());
    expect($page->getContent())->not->toContain('AMOUNT RECEIVED')->not->toContain('class="doc-sheet');
});

it('lists charges in the phone summary only when they reconcile with the stored total', function () {
    $booking = irBooking();
    $invoice = irInvoice($booking);

    $html = $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk()->getContent();
    $summary = substr($html, strpos($html, '<div class="dv-summary'));
    $summary = substr($summary, 0, strpos($summary, '</dl>'));

    expect($summary)->toContain('Towing service')->toContain('Distance charge')->toContain('VAT')->toContain('₱2,352.00')
        ->not->toContain('<a ')->not->toContain('PDF');
});

it('keeps the signatory name rule and keeps the Franchisee label off the customer PDFs', function () {
    SystemSetting::setValue('gcash_name', 'Donald Cavalida');
    SystemSetting::setValue('bank_account_name', 'donald cavalida');
    SystemSetting::setValue('signature_image', '');
    $presenter = app(\App\Services\DocumentPresenter::class);
    $booking = irBooking();
    $invoice = irInvoice($booking);
    [, , $receipt] = irPaidBooking();

    foreach ([view('documents.invoice', ['doc' => $presenter->invoice($invoice)])->render(), view('documents.receipt', ['doc' => $presenter->receipt($receipt)])->render()] as $html) {
        expect($html)->not->toContain('Franchisee')->not->toContain('doc-signature-role');
    }

    // The name rule is unchanged: GCash account name first, bank account name as the fallback, a stray "Franchisee" stripped.
    SystemSetting::setValue('gcash_name', 'Donald Cavalida Franchisee');
    expect($presenter->company()['signatory'])->toBe('Donald Cavalida');
    SystemSetting::setValue('gcash_name', '');
    expect($presenter->company()['signatory'])->toBe('donald cavalida');

    SystemSetting::setValue('bank_account_name', '');
    $html = view('documents.invoice', ['doc' => $presenter->invoice($invoice)])->render();
    expect($html)->not->toContain('Franchisee');
});

it('keeps issued PDFs private, byte-for-byte unchanged and reachable only through the authenticated routes', function () {
    Storage::fake('local');
    $booking = irBooking();
    $invoice = irInvoice($booking, ['pdf_path' => 'documents/invoices/keep.pdf']);
    Storage::disk('local')->put('documents/invoices/keep.pdf', '%PDF-issued-bytes');
    $owner = irOwner();

    $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice))->assertOk();
    $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice) . '?source=original')->assertOk();
    $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice, 'pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(Storage::disk('local')->get('documents/invoices/keep.pdf'))->toBe('%PDF-issued-bytes')
        ->and($invoice->fresh()->pdf_path)->toBe('documents/invoices/keep.pdf');
    auth()->logout();
    $this->flushSession();
    $this->get(irInvoiceUrl($booking, $invoice, 'pdf'))->assertRedirect();
});

it('still generates distinct modern invoice and receipt PDFs without the Franchisee label', function () {
    Storage::fake('local');
    SystemSetting::setValue('gcash_name', 'Donald Cavalida');
    $service = app(\App\Services\DocumentGenerationService::class);
    [$booking, $invoice, , $dispatcher] = irPaidBooking();
    $booking->update(['created_by_admin_id' => $dispatcher->id]);

    $invoicePdf = Storage::disk('local')->get($service->generateInvoice($invoice)->pdf_path);
    $receiptPdf = Storage::disk('local')->get($service->generateReceipt($booking->fresh())->pdf_path);

    foreach ([$invoicePdf, $receiptPdf] as $pdf) {
        expect($pdf)->toStartWith('%PDF')->and(preg_match_all('/\/Type\s*\/Page\b/', $pdf))->toBe(1);
    }
    expect($invoicePdf)->not->toBe($receiptPdf);

    $presenter = app(\App\Services\DocumentPresenter::class);
    $invoiceHtml = view('documents.invoice', ['doc' => $presenter->invoice($invoice->fresh())])->render();
    $receiptHtml = view('documents.receipt', ['doc' => $presenter->receipt($booking->fresh()->receipt)])->render();
    expect($invoiceHtml)->toContain('DESCRIPTION')->not->toContain('AMOUNT RECEIVED')->not->toContain('Franchisee')
        ->and($receiptHtml)->toContain('AMOUNT RECEIVED')->not->toContain('DESCRIPTION')->not->toContain('Franchisee');
});

// ---------------------------------------------------------------- official modern PDFs: identification, actions, security

/** A minimal stored PDF whose embedded Title is as dompdf writes it (UTF-16BE with a byte-order mark). */
function irPdfTitled(?string $title, string $body = 'official-or-legacy'): string
{
    $info = $title === null ? '' : '/Title (' . "\xFE\xFF" . mb_convert_encoding($title, 'UTF-16BE', 'UTF-8') . ') ';

    return "%PDF-1.7\n1 0 obj\n<< {$info}/Producer (dompdf) >>\nendobj\n% {$body}\n%%EOF";
}

/** Stores a PDF for an invoice and returns its (unchanged) bytes. */
function irStoreInvoicePdf(Invoice $invoice, ?string $title): string
{
    $path = 'documents/invoices/invoice-' . $invoice->id . '.pdf'; // the invoice's own canonical path
    $bytes = irPdfTitled($title);
    Storage::disk('local')->put($path, $bytes);
    $invoice->update(['pdf_path' => $path]);

    return $bytes;
}

/** Stores a PDF for a receipt at the receipt's own canonical path and returns its bytes. */
function irStoreReceiptPdf(Receipt $receipt, ?string $title): string
{
    $path = 'documents/receipts/booking-' . $receipt->booking_id . '-receipt.pdf';
    $bytes = irPdfTitled($title);
    Storage::disk('local')->put($path, $bytes);
    $receipt->update(['pdf_path' => $path]);

    return $bytes;
}

it('identifies an official modern PDF from the file itself, not from its name, date or presence', function () {
    Storage::fake('local');
    $files = app(\App\Services\StoredDocuments::class);
    $booking = irBooking();
    $invoice = irInvoice($booking);

    // Modern: Title names exactly this invoice.
    irStoreInvoicePdf($invoice, 'Invoice ' . $invoice->invoice_number);
    expect($files->isOfficialModern($invoice->fresh()->pdf_path, 'invoice', $invoice->invoice_number, $invoice->id))->toBeTrue();

    // Legacy template titles are never official modern.
    foreach (['Service Invoice', 'Service Receipt'] as $legacy) {
        irStoreInvoicePdf($invoice, $legacy);
        expect($files->isOfficialModern($invoice->fresh()->pdf_path, 'invoice', $invoice->invoice_number, $invoice->id))->toBeFalse();
    }

    // A modern-looking Title for a different invoice, the wrong kind, no Title, and non-PDF bytes are all refused.
    irStoreInvoicePdf($invoice, 'Invoice IV-9999999');
    expect($files->isOfficialModern($invoice->fresh()->pdf_path, 'invoice', $invoice->invoice_number, $invoice->id))->toBeFalse();
    irStoreInvoicePdf($invoice, 'Payment Receipt ' . $invoice->invoice_number);
    expect($files->isOfficialModern($invoice->fresh()->pdf_path, 'invoice', $invoice->invoice_number, $invoice->id))->toBeFalse();
    irStoreInvoicePdf($invoice, null);
    expect($files->isOfficialModern($invoice->fresh()->pdf_path, 'invoice', $invoice->invoice_number, $invoice->id))->toBeFalse();
    Storage::disk('local')->put($invoice->pdf_path, 'Invoice ' . $invoice->invoice_number . ' (not a pdf)');
    expect($files->isOfficialModern($invoice->pdf_path, 'invoice', $invoice->invoice_number, $invoice->id))->toBeFalse();

    // Missing file, empty path and empty number.
    expect($files->isOfficialModern('documents/invoices/missing.pdf', 'invoice', $invoice->invoice_number, $invoice->id))->toBeFalse()
        ->and($files->isOfficialModern(null, 'invoice', $invoice->invoice_number, $invoice->id))->toBeFalse()
        ->and($files->isOfficialModern($invoice->pdf_path, 'invoice', null, $invoice->id))->toBeFalse();

    // The filename and age play no part: a legacy-titled file at the modern path stays unofficial.
    Storage::disk('local')->put('documents/invoices/invoice-' . $invoice->id . '.pdf', irPdfTitled('Service Invoice'));
    expect($files->isOfficialModern('documents/invoices/invoice-' . $invoice->id . '.pdf', 'invoice', $invoice->invoice_number, $invoice->id))->toBeFalse();
});

it('recognises the PDFs the real generator writes, so the rule cannot drift from the templates', function () {
    Storage::fake('local');
    $service = app(\App\Services\DocumentGenerationService::class);
    $files = app(\App\Services\StoredDocuments::class);
    [$booking, $invoice, $receipt, $dispatcher] = irPaidBooking();
    $booking->update(['created_by_admin_id' => $dispatcher->id]);

    $generatedInvoice = $service->generateInvoice($invoice);
    $generatedReceipt = $service->generateReceipt($booking->fresh());

    expect($files->isOfficialModern($generatedInvoice->pdf_path, 'invoice', $generatedInvoice->invoice_number, $generatedInvoice->id))->toBeTrue()
        ->and($files->isOfficialModern($generatedReceipt->pdf_path, 'receipt', $generatedReceipt->receipt_number, $generatedReceipt->booking_id))->toBeTrue()
        ->and($files->isOfficialModern($generatedInvoice->pdf_path, 'receipt', $generatedReceipt->receipt_number, $generatedReceipt->booking_id))->toBeFalse()
        ->and($files->title($generatedInvoice->pdf_path))->toBe('Invoice ' . $generatedInvoice->invoice_number)
        ->and($files->title($generatedReceipt->pdf_path))->toBe('Payment Receipt ' . $generatedReceipt->receipt_number);
});

it('offers View, Download and Print PDF only for a stored official modern invoice PDF', function () {
    Storage::fake('local');
    $owner = irOwner();

    $modernBooking = irBooking();
    $modern = irInvoice($modernBooking);
    irStoreInvoicePdf($modern, 'Invoice ' . $modern->invoice_number);

    $legacyBooking = irBooking();
    $legacy = irInvoice($legacyBooking);
    irStoreInvoicePdf($legacy, 'Service Invoice');

    $noneBooking = irBooking();
    $none = irInvoice($noneBooking);

    $modernPage = $this->actingAs($owner)->get(irInvoiceUrl($modernBooking, $modern))->assertOk();
    $pdfUrl = irInvoiceUrl($modernBooking, $modern, 'pdf');
    $modernPage->assertSee('>View PDF<', false)->assertSee('>Download PDF<', false)->assertSee('>Print PDF<', false)
        ->assertSee('href="' . $pdfUrl . '"', false)->assertSee('href="' . $pdfUrl . '?download=1"', false)
        ->assertSee('data-print-pdf="' . $pdfUrl . '"', false)->assertSee('superadmin/js/documents.js', false)
        ->assertDontSee('For reference only. Shown from', false)->assertSee('To print this document, use Print PDF.', false);
    irAssertNoLegacyUi($modernPage->getContent());
    // The private storage path is never exposed.
    expect($modernPage->getContent())->not->toContain('documents/invoices/')->not->toContain('storage/app');

    foreach ([[$legacyBooking, $legacy], [$noneBooking, $none]] as [$booking, $invoice]) {
        $page = $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice))->assertOk();
        $page->assertDontSee('>View PDF<', false)->assertDontSee('>Download PDF<', false)->assertDontSee('>Print PDF<', false)
            ->assertDontSee('data-print-pdf', false)->assertDontSee('documents.js', false)
            ->assertSee("For reference only. Shown from the booking's stored records.", false)
            ->assertSee('class="doc-sheet doc-invoice"', false);
        irAssertNoLegacyUi($page->getContent());
    }
});

it('offers View, Download and Print PDF only for a stored official modern receipt PDF', function () {
    Storage::fake('local');
    $owner = irOwner();
    [$booking, , $receipt] = irPaidBooking();

    // Legacy receipt PDF: reference view only, no PDF actions.
    irStoreReceiptPdf($receipt, 'Service Receipt');
    $url = route('superadmin.bookings.receipt.show', $booking->job_code);
    $this->actingAs($owner)->get($url)->assertOk()->assertDontSee('>Print PDF<', false)->assertDontSee('>Download PDF<', false)
        ->assertSee('class="doc-sheet doc-receipt"', false)->assertSee('AMOUNT RECEIVED', false);

    // Modern receipt PDF for this receipt number: actions appear and point at the authenticated route.
    irStoreReceiptPdf($receipt, 'Payment Receipt ' . $receipt->receipt_number);
    $pdfUrl = route('superadmin.bookings.receipt.pdf', $booking->job_code);
    $page = $this->actingAs($owner)->get($url)->assertOk();
    $page->assertSee('>View PDF<', false)->assertSee('>Download PDF<', false)->assertSee('>Print PDF<', false)
        ->assertSee('data-print-pdf="' . $pdfUrl . '"', false)->assertDontSee('PLEASE MAKE PAYMENT TO', false)
        ->assertDontSee('PAYMENT INSTRUCTIONS', false)->assertDontSee('DESCRIPTION', false);
    irAssertNoLegacyUi($page->getContent());

    // A modern Title naming a different receipt is not official.
    irStoreReceiptPdf($receipt, 'Payment Receipt R-OTHER-0001');
    $this->actingAs($owner)->get($url)->assertOk()->assertDontSee('>Print PDF<', false);
});

it('serves the official PDF inline, as a download and for printing, only through authorised booking routes', function () {
    Storage::fake('local');
    $booking = irBooking();
    $invoice = irInvoice($booking);
    $bytes = irStoreInvoicePdf($invoice, 'Invoice ' . $invoice->invoice_number);
    $other = irBooking();
    $otherInvoice = irInvoice($other);
    irStoreInvoicePdf($otherInvoice, 'Invoice ' . $otherInvoice->invoice_number);
    $owner = irOwner();
    $url = irInvoiceUrl($booking, $invoice, 'pdf');

    $inline = $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($inline->headers->get('Content-Disposition'))->toContain('inline')->and($inline->headers->get('Cache-Control'))->toContain('no-store');
    expect($this->actingAs($owner)->get($url . '?download=1')->assertOk()->headers->get('Content-Disposition'))->toContain('attachment');

    // Cross-booking access is refused; so are other roles and anonymous visitors.
    $this->actingAs($owner)->get(irInvoiceUrl($booking, $otherInvoice, 'pdf'))->assertNotFound();
    foreach ([[2, 'Dispatcher'], [6, 'System Admin'], [5, 'Customer']] as [$roleId, $name]) {
        $this->actingAs(irUser($roleId, $name))->get($url)->assertForbidden();
    }
    auth()->logout();
    $this->flushSession();
    $this->get($url)->assertRedirect();

    // Reading never alters the stored file, and no permanent public URL exists for it.
    expect(Storage::disk('local')->get($invoice->pdf_path))->toBe($bytes);
    expect(Storage::disk('public')->exists($invoice->pdf_path))->toBeFalse();
});

it('keeps a legacy-titled PDF retrievable through the authorised audit route without offering it in the Owner UI', function () {
    Storage::fake('local');
    $booking = irBooking();
    $invoice = irInvoice($booking);
    $bytes = irStoreInvoicePdf($invoice, 'Service Invoice');
    $owner = irOwner();

    $page = $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice))->assertOk();
    irAssertNoLegacyUi($page->getContent());
    expect($page->getContent())->not->toContain('data-print-pdf')->not->toContain('>View PDF<');

    // Record retention: the backend route still serves it, byte for byte, to the authorised Owner.
    $served = $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice, 'pdf'))->assertOk();
    expect($served->streamedContent())->toBe($bytes)->and(Storage::disk('local')->get($invoice->pdf_path))->toBe($bytes);
});

it('keeps an unverified payment and short cash safe on a receipt that has an official PDF', function () {
    Storage::fake('local');
    [$booking, , $receipt] = irPaidBooking();
    irStoreReceiptPdf($receipt, 'Payment Receipt ' . $receipt->receipt_number);
    $url = route('superadmin.bookings.receipt.show', $booking->job_code);
    $owner = irOwner();

    $booking->update(['cash_received' => 2000]);
    $this->actingAs($owner)->get($url)->assertOk()->assertSee('AMOUNT RECEIVED — BELOW INVOICE TOTAL', false)
        ->assertSee('class="doc-amount" style="background: #E5E5E5"', false)->assertSee('>Print PDF<', false);

    $booking->update(['cash_received' => 2500, 'status' => 'waiting_verification', 'completed_at' => null]);
    $this->actingAs($owner)->get($url)->assertOk()->assertSee('PAYMENT NOT VERIFIED', false)->assertDontSee('AMOUNT RECEIVED', false);
});

// ---------------------------------------------------------------- a matching Title alone is never enough

it('never treats a matching Title in an unrelated file as an official modern PDF', function () {
    Storage::fake('local');
    Storage::fake('public');
    $files = app(\App\Services\StoredDocuments::class);
    $owner = irOwner();

    $bookingA = irBooking(); $a = irInvoice($bookingA);
    $bookingB = irBooking(); $b = irInvoice($bookingB);
    $titleA = irPdfTitled('Invoice ' . $a->invoice_number);
    $aOwn = $files->canonicalPath('invoice', $a->id);
    $bOwn = $files->canonicalPath('invoice', $b->id);

    $cases = [
        'arbitrary private path with the right Title' => fn () => [Storage::disk('local')->put('documents/invoices/anything.pdf', $titleA), 'documents/invoices/anything.pdf'],
        "another record's own file, Title forged to this invoice" => fn () => [Storage::disk('local')->put($bOwn, $titleA), $bOwn],
        'traversal-style path' => fn () => [Storage::disk('local')->put($aOwn, $titleA), '../invoices/invoice-' . $a->id . '.pdf'],
        'legacy public-disk path' => fn () => [Storage::disk('public')->put('documents/invoices/invoice-' . $a->id . '.pdf', $titleA), 'storage/documents/invoices/invoice-' . $a->id . '.pdf'],
        'right path but only on the public disk' => function () use ($aOwn, $titleA) {
            Storage::disk('local')->delete($aOwn);

            return [Storage::disk('public')->put($aOwn, $titleA), $aOwn];
        },
    ];

    foreach ($cases as $name => $arrange) {
        [, $path] = $arrange();
        $a->update(['pdf_path' => $path]);

        expect($files->isOfficialModern($path, 'invoice', $a->invoice_number, $a->id))->toBeFalse("unrelated file accepted: {$name}");
        $this->actingAs($owner)->get(irInvoiceUrl($bookingA, $a))->assertOk()
            ->assertDontSee('>Print PDF<', false)->assertDontSee('>Download PDF<', false)->assertDontSee('data-print-pdf', false);
    }

    // The same Title in the invoice's own canonical private file is accepted.
    Storage::disk('local')->put($aOwn, $titleA);
    $a->update(['pdf_path' => $aOwn]);
    expect($files->isOfficialModern($aOwn, 'invoice', $a->invoice_number, $a->id))->toBeTrue();
    $this->actingAs($owner)->get(irInvoiceUrl($bookingA, $a))->assertOk()->assertSee('>Print PDF<', false);
});

it('binds a receipt PDF to its own booking: another booking\'s file with the right Title is refused', function () {
    Storage::fake('local');
    $files = app(\App\Services\StoredDocuments::class);
    [$bookingA, , $receiptA] = irPaidBooking();
    [$bookingB, , $receiptB] = irPaidBooking('R-IR-0002');
    $titleA = irPdfTitled('Payment Receipt ' . $receiptA->receipt_number);

    // Receipt A pointing at booking B's canonical receipt file, which carries A's Title.
    $bPath = $files->canonicalPath('receipt', $bookingB->id);
    Storage::disk('local')->put($bPath, $titleA);
    $receiptA->update(['pdf_path' => $bPath]);

    expect($files->isOfficialModern($bPath, 'receipt', $receiptA->receipt_number, $receiptA->booking_id))->toBeFalse();
    $this->actingAs(irOwner())->get(route('superadmin.bookings.receipt.show', $bookingA->job_code))->assertOk()->assertDontSee('>Print PDF<', false);

    // Its own canonical file is accepted.
    irStoreReceiptPdf($receiptA, 'Payment Receipt ' . $receiptA->receipt_number);
    expect($files->isOfficialModern($receiptA->fresh()->pdf_path, 'receipt', $receiptA->receipt_number, $receiptA->booking_id))->toBeTrue();
});

it('keeps PDF actions behind the booking-scoped Owner lookup: another booking\'s invoice id never yields a PDF', function () {
    Storage::fake('local');
    $bookingA = irBooking(); $a = irInvoice($bookingA);
    $bookingB = irBooking(); $b = irInvoice($bookingB);
    irStoreInvoicePdf($b, 'Invoice ' . $b->invoice_number);
    $owner = irOwner();

    // B's invoice requested under A's booking: viewer, inline, download and print all 404.
    foreach (['', '/pdf', '/pdf?download=1'] as $suffix) {
        $this->actingAs($owner)->get(route('superadmin.bookings.invoices.show', ['id' => $bookingA->job_code, 'invoice' => $b->id]) . $suffix)->assertNotFound();
    }
    // Only the Owner role reaches the routes at all.
    foreach ([[2, 'Dispatcher'], [6, 'System Admin'], [5, 'Customer']] as [$roleId, $name]) {
        $this->actingAs(irUser($roleId, $name))->get(irInvoiceUrl($bookingB, $b))->assertForbidden();
    }
});

// ---------------------------------------------------------------- Owner reads never generate, replace, modify or email

it('never regenerates, replaces, modifies or emails anything when the Owner views, downloads or prints', function () {
    Storage::fake('local');
    \Illuminate\Support\Facades\Mail::fake();
    $owner = irOwner(); // created first: user creation is itself audited, so the reads below are what is measured
    [$booking, $invoice, $receipt] = irPaidBooking();
    $invoiceBytes = irStoreInvoicePdf($invoice, 'Invoice ' . $invoice->invoice_number);
    $receiptBytes = irStoreReceiptPdf($receipt, 'Payment Receipt ' . $receipt->receipt_number);
    $invoice->update(['email_sent' => true]);
    $receipt->update(['email_sent' => true]);

    $snapshot = fn () => [
        'invoice' => $invoice->fresh()->toArray(), 'receipt' => $receipt->fresh()->toArray(), 'booking' => $booking->fresh()->toArray(),
        'files' => collect(Storage::disk('local')->allFiles())->mapWithKeys(fn ($f) => [$f => md5(Storage::disk('local')->get($f))])->all(),
        'counts' => [Invoice::count(), Receipt::count(), Booking::count(), AuditLog::count()],
    ];
    // Model-creation audits are written when the first request ends; flush those from the fixtures
    // above so the snapshot measures only what the Owner's reads do.
    $this->actingAs($owner)->get(route('superadmin.dashboard'))->assertOk();
    $before = $snapshot();

    // Any attempt to generate a document would blow up the request.
    $this->partialMock(\App\Services\DocumentGenerationService::class, function ($mock) {
        $mock->shouldNotReceive('generateInvoice');
        $mock->shouldNotReceive('generateReceipt');
        $mock->shouldNotReceive('generateQuotation');
    });

    $receiptUrl = route('superadmin.bookings.receipt.show', $booking->job_code);
    $receiptPdf = route('superadmin.bookings.receipt.pdf', $booking->job_code);
    foreach ([
        irInvoiceUrl($booking, $invoice), irInvoiceUrl($booking, $invoice) . '?source=original',
        irInvoiceUrl($booking, $invoice, 'pdf'), irInvoiceUrl($booking, $invoice, 'pdf') . '?download=1',
        $receiptUrl, $receiptUrl . '?source=original', $receiptPdf, $receiptPdf . '?download=1',
    ] as $url) {
        $this->actingAs($owner)->get($url)->assertOk();
    }

    $after = $snapshot();
    foreach ($before as $part => $value) {
        expect($after[$part])->toEqual($value, "the '{$part}' snapshot changed after Owner reads: " . '');
    }
    expect(Storage::disk('local')->get($invoice->pdf_path))->toBe($invoiceBytes)
        ->and(Storage::disk('local')->get($receipt->pdf_path))->toBe($receiptBytes);
    \Illuminate\Support\Facades\Mail::assertNothingSent();
    \Illuminate\Support\Facades\Mail::assertNothingQueued();
});

it('keeps the Owner viewer code free of any document generation, mail or write call', function () {
    foreach ([
        'app/Http/Controllers/SuperAdmin/BookingDocumentController.php', 'app/Services/DocumentPresenter.php',
        'app/Services/StoredDocuments.php', 'app/Services/OwnerBookingPresenter.php', 'public/superadmin/js/documents.js',
    ] as $file) {
        $source = file_get_contents(base_path($file));
        foreach (['generateInvoice', 'generateReceipt', 'generateQuotation', 'Mail::', '->send(', 'Storage::disk(\'local\')->put', '->delete(', 'unlink('] as $needle) {
            expect($source)->not->toContain($needle);
        }
    }
});
