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

function irPaidBooking(): array
{
    $booking = irBooking([
        'status' => 'completed', 'completed_at' => now(), 'payment_method' => 'cash',
        'payment_submitted_at' => now()->subHour(), 'cash_received' => 2500,
    ]);
    $invoice = irInvoice($booking);
    $dispatcher = irUser(2, 'Dispatcher');
    AuditLog::create(['user_id' => $dispatcher->id, 'action' => 'payment_confirmed', 'entity_type' => 'Booking', 'entity_id' => $booking->id, 'reference' => $booking->job_code, 'description' => 'Job completed']);
    $receipt = Receipt::create(['booking_id' => $booking->id, 'invoice_id' => $invoice->id, 'generated_by' => $dispatcher->id, 'receipt_number' => 'R-IR-0001', 'pdf_path' => 'documents/receipts/r-ir.pdf']);

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

it('disables the PDF buttons when the file is missing', function () {
    $booking = irBooking();
    $invoice = irInvoice($booking);

    $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk()->assertSee('PDF not available', false);
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

const IR_PREVIEW_LABEL = 'Preview generated from records — not the original issued PDF.';

it('shows a historical invoice as a labelled modern preview with prominent original-PDF actions', function () {
    Storage::fake('local');
    $booking = irBooking();
    $old = irInvoice($booking, ['pdf_path' => 'documents/invoices/legacy.pdf']);
    // A legacy-template PDF: old wording that the preview must never repeat.
    Storage::disk('local')->put('documents/invoices/legacy.pdf', '%PDF-legacy Status Awaiting payment PLEASE MAKE PAYMENT TO');
    $new = $old->voidAndReplace('Typo', ['total' => 2500, 'additional_fee' => 148], 1);

    $page = $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $old))->assertOk();

    $page->assertSee(IR_PREVIEW_LABEL, false)
        ->assertSee('class="dv-paper is-preview"', false)->assertSee('class="doc-sheet doc-invoice"', false)
        ->assertSee('View Original PDF', false)->assertSee('Download Original PDF', false)
        ->assertSee(irInvoiceUrl($booking, $old, 'pdf'), false)
        ->assertSee('Original issued PDF', false)->assertSee('1 of 2', false)
        ->assertSee('Replaced by ' . $new->invoice_number, false)
        ->assertDontSee('Awaiting payment', false)->assertDontSee('PLEASE MAKE PAYMENT TO', false)
        ->assertDontSee('<iframe class="dv-embed"', false);
    // The preview itself offers no download or PDF link.
    $html = $page->getContent();
    $start = strpos($html, '<div class="dv-paper is-preview"');
    $end = strpos($html, '<p class="dv-print-note"', $start);
    $preview = substr($html, $start, $end - $start);
    expect($start)->not->toBeFalse()->and($end)->toBeGreaterThan($start)
        ->and($preview)->not->toContain('download')->not->toContain('.pdf')->not->toContain('href=');
});

it('lets the Owner switch to the original issued PDF, untouched, on screen', function () {
    Storage::fake('local');
    $booking = irBooking();
    $old = irInvoice($booking, ['pdf_path' => 'documents/invoices/original.pdf']);
    Storage::disk('local')->put('documents/invoices/original.pdf', '%PDF-original-issued');

    $page = $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $old) . '?source=original')->assertOk();

    $page->assertSee('<iframe class="dv-embed"', false)->assertSee('exactly as it was issued', false)
        ->assertSee('This is the authoritative invoice', false)->assertDontSee(IR_PREVIEW_LABEL, false);
    expect($page->getContent())->not->toContain('class="doc-sheet');
    // Viewing, on either tab, never writes to the stored PDF.
    $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $old))->assertOk();
    expect(Storage::disk('local')->get('documents/invoices/original.pdf'))->toBe('%PDF-original-issued');
});

it('shows only the original PDF with an explanation when the records cannot support an accurate preview', function () {
    Storage::fake('local');
    $booking = irBooking();
    $invoice = irInvoice($booking, ['total' => 3000, 'pdf_path' => 'documents/invoices/odd.pdf']);
    Storage::disk('local')->put('documents/invoices/odd.pdf', '%PDF-odd');

    $page = $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk();

    $page->assertSee('<iframe class="dv-embed"', false)->assertSee('A modern preview is not shown', false)
        ->assertSee('do not reconcile', false)->assertDontSee(IR_PREVIEW_LABEL, false);
    expect($page->getContent())->not->toContain('class="doc-sheet');
});

it('labels the rendered preview as not the issued document when no original PDF is on file', function () {
    $booking = irBooking();
    $invoice = irInvoice($booking);

    $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk()
        ->assertSee(IR_PREVIEW_LABEL, false)->assertSee('No original PDF is on file', false)
        ->assertSee('class="doc-sheet', false)->assertDontSee('<iframe class="dv-embed"', false);
});

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

it('renders the receipt preview as a distinct payment receipt, not a second invoice', function () {
    Storage::fake('local');
    [$booking, $invoice, $receipt] = irPaidBooking();
    Storage::disk('local')->put('documents/receipts/r-ir.pdf', '%PDF-legacy PLEASE MAKE PAYMENT TO');
    $owner = irOwner();

    $receiptPage = $this->actingAs($owner)->get(route('superadmin.bookings.receipt.show', $booking->job_code))->assertOk();
    $receiptPage->assertSee(IR_PREVIEW_LABEL, false)->assertSee('class="doc-sheet doc-receipt"', false)
        ->assertSee('AMOUNT RECEIVED', false)->assertSee('class="doc-amount" style="background: #FACC15"', false)
        ->assertSee('PAYMENT DETAILS', false)->assertSee('R-IR-0001', false)->assertSee($invoice->invoice_number, false)
        ->assertSee('View Original PDF', false)->assertSee('Download Original PDF', false)
        ->assertDontSee('PLEASE MAKE PAYMENT TO', false)->assertDontSee('PAYMENT INSTRUCTIONS', false)
        ->assertDontSee('DESCRIPTION', false)->assertDontSee('Subtotal', false)->assertDontSee('Distance charge', false)
        ->assertDontSee('<iframe class="dv-embed"', false);

    $invoicePage = $this->actingAs($owner)->get(irInvoiceUrl($booking, $invoice))->assertOk();
    $invoicePage->assertSee('class="doc-sheet doc-invoice"', false)->assertSee('DESCRIPTION', false)->assertSee('TOTAL', false)
        ->assertDontSee('AMOUNT RECEIVED', false)->assertDontSee('PAYMENT DETAILS', false);
});

it('shows the receipt PDF only, with an explanation, when there is no related invoice to confirm the amount', function () {
    Storage::fake('local');
    [$booking, $invoice] = irPaidBooking();
    Receipt::where('booking_id', $booking->id)->update(['invoice_id' => null]);
    $invoice->delete();
    Storage::disk('local')->put('documents/receipts/r-ir.pdf', '%PDF-receipt');

    $this->actingAs(irOwner())->get(route('superadmin.bookings.receipt.show', $booking->job_code))->assertOk()
        ->assertSee('<iframe class="dv-embed"', false)->assertSee('A modern preview is not shown', false)
        ->assertDontSee('AMOUNT RECEIVED', false);
});

it('shows the receipt preview alone when no original receipt PDF is on file', function () {
    [$booking] = irPaidBooking();

    $this->actingAs(irOwner())->get(route('superadmin.bookings.receipt.show', $booking->job_code))->assertOk()
        ->assertSee(IR_PREVIEW_LABEL, false)->assertSee('No original PDF is on file', false)
        ->assertSee('Original PDF not available', false)->assertDontSee('<iframe class="dv-embed"', false);
});

// ---------------------------------------------------------------- financial accuracy

it('presents no VAT, subtotal or adjustment when the stored values do not reconcile', function () {
    $booking = irBooking();
    $invoice = irInvoice($booking, ['total' => 3000]);

    $page = $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk();

    $page->assertSee('₱3,000.00', false)->assertSee('An itemised VAT and adjustment breakdown is not recorded', false)
        ->assertDontSee('Subtotal', false)->assertDontSee('>VAT<', false)->assertDontSee('Adjustment', false)
        ->assertDontSee('Distance charge', false);
});

it('never invents VAT when the booking holds no stored VAT', function () {
    $booking = irBooking(['vat_amount' => null, 'vat_exclusive_total' => null]);
    $invoice = irInvoice($booking);

    $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk()
        ->assertDontSee('>VAT<', false)->assertSee('An itemised VAT and adjustment breakdown is not recorded', false);
});

it('shows a group breakdown only when vehicle totals plus the stored fee and discount equal the invoice total', function () {
    $customer = Customer::create(['full_name' => 'Group Customer', 'phone' => '0917' . fake()->unique()->numerify('#######')]);
    $code = 'GRP-' . fake()->unique()->numerify('######');
    $make = fn (float $total) => irBooking(['customer_id' => $customer->id, 'group_code' => $code, 'pickup_address' => 'G pick', 'dropoff_address' => 'G drop', 'final_total' => $total]);
    $a = $make(4000);
    $b = $make(5000);
    $quotation = Quotation::create([
        'quotation_number' => 'Q-' . uniqid(), 'source_booking_id' => $a->id, 'customer_id' => $customer->id, 'truck_type_id' => $a->truck_type_id,
        'pickup_address' => 'G pick', 'dropoff_address' => 'G drop', 'distance_km' => 10, 'estimated_price' => 9200, 'status' => 'accepted',
        'extra_vehicles' => [['booking_id' => $a->id, 'final_total' => 4000], ['booking_id' => $b->id, 'final_total' => 5000]],
    ]);
    $a->update(['quotation_id' => $quotation->id]);
    $b->update(['quotation_id' => $quotation->id]);
    $mismatch = irInvoice($a, ['additional_fee' => 500, 'discount' => 300, 'total' => 9999, 'quotation_id' => $quotation->id]);

    $this->actingAs(irOwner())->get(irInvoiceUrl($a, $mismatch))->assertOk()
        ->assertSee('₱9,999.00', false)->assertSee('An itemised VAT and adjustment breakdown is not recorded', false)
        ->assertDontSee('Vehicle 1', false);
});

// ---------------------------------------------------------------- document content

it('keeps payment instructions, the signatory block and the accreditation logo on generated documents', function () {
    SystemSetting::setValue('bank_name', 'BDO');
    SystemSetting::setValue('bank_account_name', 'Juan Dela Cruz');
    SystemSetting::setValue('bank_account_number', '0011223344');
    SystemSetting::setValue('gcash_number', '09171234567');
    SystemSetting::setValue('gcash_name', 'Juan Dela Cruz Franchisee');
    SystemSetting::setValue('signature_image', '');
    $booking = irBooking();
    $current = irInvoice($booking);
    $presenter = app(\App\Services\DocumentPresenter::class);

    $html = view('documents.invoice', ['doc' => $presenter->invoice($current)])->render();

    expect($html)->toContain('PAYMENT INSTRUCTIONS')->toContain('0011223344')->toContain('09171234567')
        ->toContain('Juan Dela Cruz')->toContain('Franchisee')->toContain('alt="Accreditation"')
        ->toContain('doc-signature-blank')->not->toContain('alt="Signature"');

    $voided = irInvoice(irBooking(), ['status' => 'voided', 'is_current' => false, 'voided_at' => now()]);
    expect(view('documents.invoice', ['doc' => $presenter->invoice($voided)])->render())->not->toContain('PAYMENT INSTRUCTIONS');

    [, , $receipt] = irPaidBooking();
    $receiptHtml = view('documents.receipt', ['doc' => $presenter->receipt($receipt)])->render();
    expect($receiptHtml)->toContain('Franchisee')->toContain('Pick-up')->toContain('Drop-off')->not->toContain('PAYMENT INSTRUCTIONS');
});

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

it('offers View and Download Original PDF exactly once, with an accurate phone source label', function () {
    Storage::fake('local');
    [$booking, $invoice] = irPaidBooking();
    $invoice->update(['pdf_path' => 'documents/invoices/o.pdf']);
    Storage::disk('local')->put('documents/invoices/o.pdf', '%PDF-o');
    Storage::disk('local')->put('documents/receipts/r-ir.pdf', '%PDF-r');
    $owner = irOwner();

    foreach ([irInvoiceUrl($booking, $invoice), route('superadmin.bookings.receipt.show', $booking->job_code)] as $url) {
        $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();

        expect(substr_count($html, '>View Original PDF<'))->toBe(1)
            ->and(substr_count($html, '>Download Original PDF<'))->toBe(1)
            ->and($html)->toContain('<span class="dv-only-desktop">Preview generated from records</span>')
            ->and($html)->toContain('<span class="dv-only-mobile">Summary of stored records; the original issued PDF is on file</span>');
        // The phone summary no longer carries its own PDF button.
        $summary = substr($html, strpos($html, '<div class="dv-summary">'));
        $summary = substr($summary, 0, strpos($summary, '</div>'));
        expect($summary)->not->toContain('<a ')->not->toContain('View Original PDF');
    }
});

it('describes the phone source honestly when no original PDF is on file', function () {
    $booking = irBooking();
    $invoice = irInvoice($booking);

    $this->actingAs(irOwner())->get(irInvoiceUrl($booking, $invoice))->assertOk()
        ->assertSee('<span class="dv-only-mobile">Summary of stored records; no original PDF is on file</span>', false)
        ->assertSee('Original PDF not available', false)->assertDontSee('>View Original PDF<', false);
});

it('shows no amount basis and a grey panel for a payment that is not verified', function () {
    [$booking] = irPaidBooking();
    $booking->update(['status' => 'waiting_verification', 'completed_at' => null]);

    irReceiptPage($this, $booking)->assertSee('PAYMENT NOT VERIFIED', false)->assertSee('class="doc-amount" style="background: #E5E5E5"', false)
        ->assertDontSee('Amount basis', false)->assertDontSee('AMOUNT RECEIVED', false);
});
