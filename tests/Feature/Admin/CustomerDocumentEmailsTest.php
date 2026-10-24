<?php

use App\Mail\DocumentFilename;
use App\Mail\FinalPaymentDocumentsMail;
use App\Mail\QuotationSentMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Services\DocumentGenerationService;
use App\Services\DocumentPresenter;
use App\Services\FinalPaymentDocumentsService;
use App\Services\QuotationService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

function cdeRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cdeCustomer(string $name = 'Renata Cruz', ?string $email = 'renata@example.test'): Customer
{
    cdeRole(5, 'Customer');
    $user = User::factory()->create(['role_id' => 5]);

    return Customer::create([
        'user_id' => $user->id,
        'full_name' => $name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $email,
    ]);
}

function cdeBooking(Customer $customer, array $overrides = []): Booking
{
    cdeRole(2, 'Dispatcher');
    $admin = User::factory()->create(['role_id' => 2]);
    $truck = TruckType::firstOrCreate(['name' => 'Flatbed Tow Truck'], ['base_rate' => 1800, 'per_km_rate' => 150]);

    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'created_by_admin_id' => $admin->id,
        'pickup_address' => 'Pasig City, Metro Manila',
        'dropoff_address' => 'Taguig City, Metro Manila',
        'distance_km' => 9.4,
        'base_rate' => 1800,
        'per_km_rate' => 150,
        'computed_total' => 2923.20,
        'final_total' => 2923.20,
        'status' => 'completed',
        'payment_method' => 'gcash',
        'service_type' => 'book_now',
    ], $overrides));

    $booking->forceFill([
        'booking_code' => 'TM-CDE' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT),
        'vat_exclusive_total' => 2610.00,
        'vat_amount' => 313.20,
        'vat_rate' => 0.12,
        'completed_at' => ($overrides['status'] ?? 'completed') === 'completed' ? now() : null,
    ])->save();

    return $booking->fresh(['customer', 'truckType']);
}

function cdeInvoice(Booking $booking, float $total = 2923.20, float $subtotal = 2610.00): Invoice
{
    return Invoice::create([
        'booking_id' => $booking->id,
        'subtotal' => $subtotal,
        'additional_fee' => 0,
        'discount' => 0,
        'total' => $total,
        'status' => 'issued',
        'is_current' => true,
    ]);
}

function cdeQuotation(Booking $booking, float $fee = 0): Quotation
{
    return Quotation::create([
        'quotation_number' => 'Q-CDE-' . $booking->id,
        'source_booking_id' => $booking->id,
        'customer_id' => $booking->customer_id,
        'truck_type_id' => $booking->truck_type_id,
        'pickup_address' => $booking->pickup_address,
        'dropoff_address' => $booking->dropoff_address,
        'distance_km' => $booking->distance_km,
        'estimated_price' => 2923.20 + $fee,
        'additional_fee' => $fee,
        'vat_rate' => 0.12,
        'status' => 'pending',
        'is_current' => true,
    ])->fresh(['customer', 'truckType', 'sourceBooking']);
}

/** Every message the array mailer accepted, as Symfony message objects. */
function cdeSent(): array
{
    $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();

    return collect($transport->messages())->map(fn ($m) => $m->getOriginalMessage())->all();
}

function cdeAttachmentNames($message): array
{
    return collect($message->getAttachments())
        ->map(fn ($a) => $a->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename'))
        ->all();
}

function cdePageCount(string $pdf): int
{
    return preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf);
}

beforeEach(function () {
    config(['mail.default' => 'array']);
    Storage::fake('local');
});

it('sends the quotation email with exactly one Quotation PDF and nothing else attached', function () {
    $booking = cdeBooking(cdeCustomer(), ['status' => 'quotation_sent']);
    $quotation = cdeQuotation($booking);

    app(QuotationService::class)->sendQuotation($quotation);

    $messages = cdeSent();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getTo()[0]->getAddress())->toBe('renata@example.test')
        ->and($messages[0]->getSubject())->toBe('Your Towing Service Quotation — Q-CDE-' . $booking->id)
        ->and(cdeAttachmentNames($messages[0]))->toBe(['Quotation Q-CDE-' . $booking->id . '.pdf'])
        ->and($messages[0]->getAttachments()[0]->getBody())->toStartWith('%PDF')
        ->and($messages[0]->getAttachments()[0]->getMediaType() . '/' . $messages[0]->getAttachments()[0]->getMediaSubtype())->toBe('application/pdf');
});

it('renders the quotation PDF from the stored quotation, reconciled, on a single white A4 page', function () {
    $booking = cdeBooking(cdeCustomer(), ['status' => 'quotation_sent']);
    $quotation = cdeQuotation($booking);

    $doc = app(DocumentPresenter::class)->quotation($quotation);
    $pdf = app(DocumentGenerationService::class)->renderQuotationPdf($quotation);

    expect($doc['reliable'])->toBeTrue()
        ->and($doc['total'])->toBe('₱2,923.20')
        ->and(collect($doc['summary'])->pluck('value')->all())->toBe(['₱2,610.00', '₱313.20'])
        ->and($pdf)->toStartWith('%PDF')
        ->and(cdePageCount($pdf))->toBe(1)
        ->and($pdf)->toMatch('#/MediaBox \[0\.000 0\.000 595\.280 841\.890\]#');
});

it('falls back to the stored total alone when the quotation breakdown does not reconcile', function () {
    $booking = cdeBooking(cdeCustomer(), ['status' => 'quotation_sent']);
    $quotation = cdeQuotation($booking);
    $quotation->update(['estimated_price' => 3000.00]);

    $doc = app(DocumentPresenter::class)->quotation($quotation->fresh());

    expect($doc['reliable'])->toBeFalse()
        ->and($doc['summary'])->toBe([])
        ->and($doc['total'])->toBe('₱3,000.00')
        ->and($doc['breakdown_note'])->not->toBeNull();
});

it('emails the invoice and the receipt together, as two separate PDFs, only after payment is confirmed', function () {
    $booking = cdeBooking(cdeCustomer());
    $invoice = cdeInvoice($booking);

    expect(app(FinalPaymentDocumentsService::class)->send($booking->id))->toBe(FinalPaymentDocumentsService::SENT);

    $messages = cdeSent();
    $receipt = Receipt::where('booking_id', $booking->id)->firstOrFail();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getTo()[0]->getAddress())->toBe('renata@example.test')
        ->and(cdeAttachmentNames($messages[0]))->toBe([
            DocumentFilename::for('Invoice', $invoice->fresh()->invoice_number),
            DocumentFilename::for('Receipt', $receipt->receipt_number),
        ]);

    foreach ($messages[0]->getAttachments() as $attachment) {
        expect($attachment->getBody())->toStartWith('%PDF');
    }

    expect($messages[0]->getAttachments()[0]->getBody())->toBe(Storage::disk('local')->get($invoice->fresh()->pdf_path))
        ->and($messages[0]->getAttachments()[1]->getBody())->toBe(Storage::disk('local')->get($receipt->fresh()->pdf_path))
        ->and($receipt->fresh()->email_sent)->toBeTrue()
        ->and($invoice->fresh()->email_sent)->toBeTrue()
        ->and($receipt->invoice_id)->toBe($invoice->id);
});

it('sends nothing before payment is confirmed', function (string $status) {
    $booking = cdeBooking(cdeCustomer(), ['status' => $status]);
    cdeInvoice($booking);

    expect(app(FinalPaymentDocumentsService::class)->send($booking->id))->toBe(FinalPaymentDocumentsService::NOT_COMPLETED)
        ->and(cdeSent())->toHaveCount(0)
        ->and(Receipt::where('booking_id', $booking->id)->exists())->toBeFalse();
})->with(['waiting_verification', 'payment_pending', 'arrived_dropoff']);

it('never sends the final payment email twice, even when called repeatedly or concurrently', function () {
    $booking = cdeBooking(cdeCustomer());
    cdeInvoice($booking);
    $service = app(FinalPaymentDocumentsService::class);

    expect($service->send($booking->id))->toBe(FinalPaymentDocumentsService::SENT)
        ->and($service->send($booking->id))->toBe(FinalPaymentDocumentsService::ALREADY_SENT)
        ->and(cdeSent())->toHaveCount(1);

    // A second request that arrives while the first still holds the booking lock does nothing.
    $other = cdeBooking(cdeCustomer('Other Person', 'other@example.test'));
    cdeInvoice($other);
    $held = \Illuminate\Support\Facades\Cache::lock('final-payment-documents:' . $other->id, 60);
    $held->get();

    expect($service->send($other->id))->toBe(FinalPaymentDocumentsService::BUSY)
        ->and(cdeSent())->toHaveCount(1);

    $held->release();
});

it('does not mark the email sent when the mail transport fails, and a retry then succeeds exactly once', function () {
    $booking = cdeBooking(cdeCustomer());
    $invoice = cdeInvoice($booking);

    Mail::extend('cde-failing', fn () => new class extends AbstractTransport {
        protected function doSend(SentMessage $message): void
        {
            throw new \RuntimeException('smtp unavailable');
        }

        public function __toString(): string
        {
            return 'cde-failing';
        }
    });
    config(['mail.default' => 'cde-failing', 'mail.mailers.cde-failing' => ['transport' => 'cde-failing']]);

    expect(fn () => app(FinalPaymentDocumentsService::class)->send($booking->id))->toThrow(\RuntimeException::class);

    $receipt = Receipt::where('booking_id', $booking->id)->firstOrFail();
    expect($receipt->email_sent)->toBeFalse()
        ->and($invoice->fresh()->email_sent)->toBeFalse();

    config(['mail.default' => 'array']);
    Mail::forgetMailers();

    expect(app(FinalPaymentDocumentsService::class)->send($booking->id))->toBe(FinalPaymentDocumentsService::SENT)
        ->and(cdeSent())->toHaveCount(1)
        ->and($receipt->fresh()->email_sent)->toBeTrue()
        ->and(Receipt::where('booking_id', $booking->id)->count())->toBe(1);
});

it('does not email a customer without an address, and leaves the send retryable', function () {
    $booking = cdeBooking(cdeCustomer('No Email', null));
    cdeInvoice($booking);

    expect(app(FinalPaymentDocumentsService::class)->send($booking->id))->toBe(FinalPaymentDocumentsService::NO_RECIPIENT)
        ->and(cdeSent())->toHaveCount(0)
        ->and(Receipt::where('booking_id', $booking->id)->first()->email_sent)->toBeFalse();
});

it('attaches only the invoice of this booking, never another customer\'s', function () {
    $mine = cdeBooking(cdeCustomer('Mine', 'mine@example.test'));
    $theirs = cdeBooking(cdeCustomer('Theirs', 'theirs@example.test'));
    $theirInvoice = cdeInvoice($theirs, 9999.00, 9999.00);
    $myInvoice = cdeInvoice($mine);

    app(FinalPaymentDocumentsService::class)->send($mine->id);

    $message = cdeSent()[0];
    expect($message->getTo()[0]->getAddress())->toBe('mine@example.test')
        ->and(cdeAttachmentNames($message)[0])->toBe(DocumentFilename::for('Invoice', $myInvoice->fresh()->invoice_number))
        ->and($theirInvoice->fresh()->email_sent)->toBeFalse()
        ->and(Receipt::where('booking_id', $theirs->id)->exists())->toBeFalse()
        ->and(Receipt::where('booking_id', $mine->id)->first()->invoice_id)->toBe($myInvoice->id);
});

it('refuses to send when the booking has no current invoice of its own', function () {
    $booking = cdeBooking(cdeCustomer());
    $other = cdeBooking(cdeCustomer('Other', 'other@example.test'));
    cdeInvoice($other);

    expect(fn () => app(FinalPaymentDocumentsService::class)->send($booking->id))->toThrow(\RuntimeException::class)
        ->and(cdeSent())->toHaveCount(0);
});

it('attaches the replacement invoice after a void and replace, marked Paid, and never the voided one', function () {
    $booking = cdeBooking(cdeCustomer());
    $original = cdeInvoice($booking, 2000.00, 1785.71);
    $replacement = $original->voidAndReplace('Corrected total', ['subtotal' => 2610.00, 'total' => 2923.20], null);

    app(FinalPaymentDocumentsService::class)->send($booking->id);

    $invoiceDoc = app(DocumentPresenter::class)->invoice($replacement->fresh());
    $names = cdeAttachmentNames(cdeSent()[0]);

    expect($names[0])->toBe(DocumentFilename::for('Invoice', $replacement->fresh()->invoice_number))
        ->and($names[0])->not->toBe(DocumentFilename::for('Invoice', $original->fresh()->invoice_number))
        ->and($invoiceDoc['pdf_status'])->toBe('Paid')
        ->and($invoiceDoc['replaces'])->toBe($original->fresh()->invoice_number)
        ->and($original->fresh()->email_sent)->toBeFalse();
});

it('labels the invoice only with a status the stored state supports', function () {
    $presenter = app(DocumentPresenter::class);

    $unpaid = cdeBooking(cdeCustomer(), ['status' => 'waiting_verification']);
    $unpaidInvoice = cdeInvoice($unpaid);
    expect($presenter->invoice($unpaidInvoice)['pdf_status'])->toBe('Unpaid');

    $paid = cdeBooking(cdeCustomer());
    $paidInvoice = cdeInvoice($paid);
    expect($presenter->invoice($paidInvoice)['pdf_status'])->toBe('Paid');

    $old = cdeBooking(cdeCustomer(), ['status' => 'waiting_verification']);
    $oldInvoice = cdeInvoice($old);
    $new = $oldInvoice->voidAndReplace('Fix', ['total' => 2923.20], null);
    expect($presenter->invoice($oldInvoice->fresh())['pdf_status'])->toBe('Replaced')
        ->and($presenter->invoice($new->fresh())['pdf_status'])->toBe('Unpaid');

    $voided = cdeBooking(cdeCustomer(), ['status' => 'waiting_verification']);
    $voidedInvoice = cdeInvoice($voided);
    $voidedInvoice->update(['status' => 'voided', 'is_current' => false, 'voided_at' => now()]);
    expect($presenter->invoice($voidedInvoice->fresh())['pdf_status'])->toBe('Voided');
});

it('keeps the invoice, receipt and email amounts identical', function () {
    $booking = cdeBooking(cdeCustomer());
    $invoice = cdeInvoice($booking);

    app(FinalPaymentDocumentsService::class)->send($booking->id);

    $receipt = Receipt::where('booking_id', $booking->id)->first();
    $presenter = app(DocumentPresenter::class);
    $mail = new FinalPaymentDocumentsMail($booking->fresh(['customer']), $invoice->fresh(), $receipt->fresh());
    $html = $mail->render();

    expect($presenter->invoice($invoice->fresh())['total'])->toBe('₱2,923.20')
        ->and($presenter->receipt($receipt->fresh())['amount'])->toBe('₱2,923.20')
        ->and($mail->amount)->toBe('₱2,923.20')
        ->and($html)->toContain('₱2,923.20')->toContain('AMOUNT PAID')->toContain('TM-CDE');
});

it('falls back to the stored total when an invoice breakdown does not reconcile', function () {
    $booking = cdeBooking(cdeCustomer());
    $invoice = cdeInvoice($booking, 2999.99, 2610.00);

    $doc = app(DocumentPresenter::class)->invoice($invoice);

    expect($doc['reliable'])->toBeFalse()
        ->and($doc['rows'])->toBe([])
        ->and($doc['total'])->toBe('₱2,999.99')
        ->and($doc['breakdown_note'])->not->toBeNull();
});

it('keeps every vehicle on a group invoice and gives the group receipt a concise service summary', function () {
    $booking = cdeBooking(cdeCustomer('Fleet Co'));
    $invoice = cdeInvoice($booking, 5846.40, 5846.40);
    $vehicles = [
        ['truck_type_name' => 'Flatbed Tow Truck', 'final_total' => 2923.20],
        ['truck_type_name' => 'Wheel-lift Tow Truck', 'final_total' => 2923.20],
    ];

    $doc = app(DocumentPresenter::class)->invoice($invoice, $vehicles);

    expect($doc['is_group'])->toBeTrue()
        ->and($doc['reliable'])->toBeTrue()
        ->and(collect($doc['lines'])->pluck('title')->all())->toBe(['Vehicle 1 — Flatbed Tow Truck', 'Vehicle 2 — Wheel-lift Tow Truck'])
        ->and(collect($doc['lines'])->pluck('amount')->all())->toBe(['₱2,923.20', '₱2,923.20'])
        ->and($doc['total'])->toBe('₱5,846.40');
});

it('lays out a long group invoice over two A4 pages with the table header repeated and the total on the last page', function () {
    $booking = cdeBooking(cdeCustomer('Metro Fleet Logistics Inc.'));
    $invoice = cdeInvoice($booking, 22 * 2923.20, 22 * 2923.20);
    $vehicles = [];
    for ($i = 1; $i <= 22; $i++) {
        $vehicles[] = ['truck_type_name' => $i % 2 ? 'Flatbed Tow Truck' : 'Wheel-lift Tow Truck', 'final_total' => 2923.20];
    }

    $invoice = app(DocumentGenerationService::class)->generateInvoice($invoice, $vehicles);
    $pdf = Storage::disk('local')->get($invoice->pdf_path);

    expect(cdePageCount($pdf))->toBe(2)
        ->and($pdf)->toMatch('#/MediaBox \[0\.000 0\.000 595\.280 841\.890\]#');
});

it('renders long customer names and addresses without losing a page or text', function () {
    $name = 'Maria Cristina Fernandez-Villanueva de los Santos-Reyes y Dela Cruz';
    $address = 'Unit 1204 Tower 2, The Very Long Residential Condominium Name, Corner of General Emilio Aguinaldo Highway and Santiago Boulevard, Barangay San Antonio Poblacion, Imus City, Cavite, Calabarzon 4103, Philippines';
    $booking = cdeBooking(cdeCustomer($name, 'maria@example.test'), ['pickup_address' => $address, 'dropoff_address' => $address]);
    $invoice = cdeInvoice($booking);

    app(FinalPaymentDocumentsService::class)->send($booking->id);

    $invoiceHtml = view('documents.invoice', ['doc' => app(DocumentPresenter::class)->invoice($invoice->fresh())])->render();
    $receiptHtml = view('documents.receipt', ['doc' => app(DocumentPresenter::class)->receipt(Receipt::first())])->render();

    expect($invoiceHtml)->toContain($name)->toContain($address)
        ->and($receiptHtml)->toContain($name);

    foreach (cdeSent()[0]->getAttachments() as $attachment) {
        expect(cdePageCount($attachment->getBody()))->toBeLessThanOrEqual(2);
    }
});

it('uses a white A4 document with the logo on the quotation only', function () {
    $booking = cdeBooking(cdeCustomer());
    $invoice = cdeInvoice($booking);
    $quotation = cdeQuotation($booking);
    $presenter = app(DocumentPresenter::class);

    $quotationHtml = view('documents.quotation', ['doc' => $presenter->quotation($quotation)])->render();
    $invoiceHtml = view('documents.invoice', ['doc' => $presenter->invoice($invoice)])->render();
    $receiptHtml = view('documents.receipt', ['doc' => $presenter->receipt(app(DocumentGenerationService::class)->generateReceipt($booking))])->render();

    expect($quotationHtml)->toContain('class="pdf-logo"')->toContain('>QUOTATION<')
        ->and($invoiceHtml)->not->toContain('class="pdf-logo"')->not->toContain('<img')->toContain('>INVOICE<')
        ->and($receiptHtml)->not->toContain('class="pdf-logo"')->not->toContain('<img')->toContain('>RECEIPT<');

    foreach ([$quotationHtml, $invoiceHtml, $receiptHtml] as $html) {
        expect($html)->toContain('background: #FFFFFF')->not->toContain('#efefef')->not->toContain('Franchisee');
    }
});

it('builds responsive emails that fit 600, 390 and 360 pixel screens', function () {
    $booking = cdeBooking(cdeCustomer('Maria Cristina Fernandez-Villanueva de los Santos-Reyes y Dela Cruz'));
    $invoice = cdeInvoice($booking);
    app(FinalPaymentDocumentsService::class)->send($booking->id);
    $receipt = Receipt::first();

    $htmls = [
        (new QuotationSentMail(cdeQuotation($booking)))->render(),
        (new FinalPaymentDocumentsMail($booking->fresh(['customer']), $invoice->fresh(), $receipt->fresh()))->render(),
    ];

    foreach ($htmls as $html) {
        expect($html)->toContain('max-width:600px')->toContain('width="100%"')
            ->toContain('@media only screen and (max-width: 480px)')
            ->toContain('word-break:break-word');

        preg_match_all('/(?<![-\w])width:\s*(\d+)px/', $html, $fixed);
        expect(collect($fixed[1])->map(fn ($w) => (int) $w)->max() ?? 0)->toBeLessThanOrEqual(600);
        expect($html)->not->toContain('<img');
    }
});

it('keeps invoice generation at payment submission silent and does not email the customer', function () {
    $booking = cdeBooking(cdeCustomer(), ['status' => 'waiting_verification']);
    $invoice = cdeInvoice($booking);

    app(DocumentGenerationService::class)->generateInvoice($invoice);

    expect(cdeSent())->toHaveCount(0)
        ->and($invoice->fresh()->email_sent)->toBeFalse()
        ->and(Storage::disk('local')->exists($invoice->fresh()->pdf_path))->toBeTrue();
});

it('does not reset the sent flag when the receipt is generated again', function () {
    $booking = cdeBooking(cdeCustomer());
    cdeInvoice($booking);
    app(FinalPaymentDocumentsService::class)->send($booking->id);

    app(DocumentGenerationService::class)->generateReceipt($booking->fresh());

    expect(Receipt::where('booking_id', $booking->id)->first()->email_sent)->toBeTrue();
});

/** Two completed members of one normalized group, one current invoice on the first. */
function cdeGroup(): array
{
    $customer = cdeCustomer('Group Customer', 'group@example.test');
    $a = cdeBooking($customer, ['group_code' => 'GRP-CDE-' . uniqid()]);
    $b = cdeBooking($customer, ['group_code' => $a->group_code]);
    $quotation = Quotation::create([
        'quotation_number' => 'Q-GRP-' . $a->id,
        'source_booking_id' => $a->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $a->truck_type_id,
        'pickup_address' => $a->pickup_address,
        'dropoff_address' => $a->dropoff_address,
        'distance_km' => 9.4,
        'estimated_price' => 5846.40,
        'status' => 'accepted',
        'is_current' => true,
        'extra_vehicles' => [
            ['booking_id' => $a->id, 'truck_type_name' => 'Flatbed Tow Truck', 'final_total' => 2923.20],
            ['booking_id' => $b->id, 'truck_type_name' => 'Flatbed Tow Truck', 'final_total' => 2923.20],
        ],
    ]);
    $a->update(['quotation_id' => $quotation->id]);
    $b->update(['quotation_id' => $quotation->id]);
    $invoice = cdeInvoice($a, 5846.40, 5846.40);

    return [$a->fresh(), $b->fresh(), $invoice];
}

function cdeTransactionReceipts(Booking $a, Booking $b): \Illuminate\Support\Collection
{
    return Receipt::whereIn('booking_id', [$a->id, $b->id])->get();
}

it('sends one email and keeps one receipt when member A is confirmed and then member B', function () {
    [$a, $b, $invoice] = cdeGroup();
    $service = app(FinalPaymentDocumentsService::class);

    expect($service->send($a->id))->toBe(FinalPaymentDocumentsService::SENT)
        ->and($service->send($b->id))->toBe(FinalPaymentDocumentsService::ALREADY_SENT)
        ->and($service->send($a->id))->toBe(FinalPaymentDocumentsService::ALREADY_SENT);

    $receipts = cdeTransactionReceipts($a, $b);
    expect($receipts)->toHaveCount(1)
        ->and($receipts->first()->booking_id)->toBe($a->id)
        ->and($receipts->first()->invoice_id)->toBe($invoice->id)
        ->and(cdeSent())->toHaveCount(1)
        ->and(Invoice::whereIn('booking_id', [$a->id, $b->id])->count())->toBe(1);
});

it('sends one email and keeps one receipt when member B is confirmed and then member A', function () {
    [$a, $b, $invoice] = cdeGroup();
    $service = app(FinalPaymentDocumentsService::class);

    expect($service->send($b->id))->toBe(FinalPaymentDocumentsService::SENT)
        ->and($service->send($a->id))->toBe(FinalPaymentDocumentsService::ALREADY_SENT)
        ->and($service->send($b->id))->toBe(FinalPaymentDocumentsService::ALREADY_SENT);

    $receipts = cdeTransactionReceipts($a, $b);
    expect($receipts)->toHaveCount(1)
        ->and($receipts->first()->booking_id)->toBe($b->id)
        ->and($receipts->first()->invoice_id)->toBe($invoice->id)
        ->and(cdeSent())->toHaveCount(1);
});

it('treats every member of the group as the same transaction for locking', function () {
    [$a, $b] = cdeGroup();
    $service = app(FinalPaymentDocumentsService::class);

    // The transaction key is the lowest member id, whichever member is confirmed.
    $held = \Illuminate\Support\Facades\Cache::lock('final-payment-documents:' . min($a->id, $b->id), 60);
    expect($held->get())->toBeTrue()
        ->and($service->send($a->id))->toBe(FinalPaymentDocumentsService::BUSY)
        ->and($service->send($b->id))->toBe(FinalPaymentDocumentsService::BUSY)
        ->and(cdeTransactionReceipts($a, $b))->toHaveCount(0)
        ->and(cdeSent())->toHaveCount(0);

    $held->release();

    expect($service->send($b->id))->toBe(FinalPaymentDocumentsService::SENT)
        ->and($service->send($a->id))->toBe(FinalPaymentDocumentsService::ALREADY_SENT)
        ->and(cdeSent())->toHaveCount(1);
});

it('retries a failed group email from either member without a second receipt, then sends nothing more', function () {
    [$a, $b, $invoice] = cdeGroup();

    Mail::extend('cde-failing-group', fn () => new class extends AbstractTransport {
        protected function doSend(SentMessage $message): void
        {
            throw new \RuntimeException('smtp unavailable');
        }

        public function __toString(): string
        {
            return 'cde-failing-group';
        }
    });
    config(['mail.default' => 'cde-failing-group', 'mail.mailers.cde-failing-group' => ['transport' => 'cde-failing-group']]);

    expect(fn () => app(FinalPaymentDocumentsService::class)->send($a->id))->toThrow(\RuntimeException::class);

    $first = cdeTransactionReceipts($a, $b);
    expect($first)->toHaveCount(1)
        ->and($first->first()->email_sent)->toBeFalse()
        ->and($invoice->fresh()->email_sent)->toBeFalse();

    config(['mail.default' => 'array']);
    Mail::forgetMailers();

    // The retry arrives through the other member, and still reuses the one receipt.
    expect(app(FinalPaymentDocumentsService::class)->send($b->id))->toBe(FinalPaymentDocumentsService::SENT);

    $receipts = cdeTransactionReceipts($a, $b);
    $message = cdeSent()[0];

    expect($receipts)->toHaveCount(1)
        ->and($receipts->first()->id)->toBe($first->first()->id)
        ->and($receipts->first()->email_sent)->toBeTrue()
        ->and(cdeSent())->toHaveCount(1)
        ->and(cdeAttachmentNames($message))->toBe([
            DocumentFilename::for('Invoice', $invoice->fresh()->invoice_number),
            DocumentFilename::for('Receipt', $receipts->first()->receipt_number),
        ]);

    expect(app(FinalPaymentDocumentsService::class)->send($a->id))->toBe(FinalPaymentDocumentsService::ALREADY_SENT)
        ->and(app(FinalPaymentDocumentsService::class)->send($b->id))->toBe(FinalPaymentDocumentsService::ALREADY_SENT)
        ->and(cdeSent())->toHaveCount(1)
        ->and(cdeTransactionReceipts($a, $b))->toHaveCount(1);
});

it('keeps single-booking behaviour: one receipt, one email, repeated confirmation sends nothing', function () {
    $booking = cdeBooking(cdeCustomer());
    cdeInvoice($booking);
    $service = app(FinalPaymentDocumentsService::class);

    expect($service->send($booking->id))->toBe(FinalPaymentDocumentsService::SENT)
        ->and($service->send($booking->id))->toBe(FinalPaymentDocumentsService::ALREADY_SENT)
        ->and(Receipt::where('booking_id', $booking->id)->count())->toBe(1)
        ->and(cdeSent())->toHaveCount(1);
});

function cdeFailingTransport(string $name): void
{
    Mail::extend($name, fn () => new class($name) extends AbstractTransport {
        public function __construct(private string $label)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            throw new \RuntimeException('smtp unavailable');
        }

        public function __toString(): string
        {
            return $this->label;
        }
    });
    config(['mail.default' => $name, 'mail.mailers.' . $name => ['transport' => $name]]);
}

it('keeps the quotation available but records and reports a failed email', function () {
    $booking = cdeBooking(cdeCustomer(), ['status' => 'quotation_sent']);
    $quotation = cdeQuotation($booking);
    cdeFailingTransport('cde-quote-failing');

    $service = app(QuotationService::class);
    $sent = $service->sendQuotation($quotation);

    expect($sent->status)->toBe('sent')
        ->and($sent->expires_at)->not->toBeNull()
        ->and($service->emailFailed($quotation))->toBeTrue()
        ->and(cdeSent())->toHaveCount(0);

    $audit = \App\Models\AuditLog::where('action', 'quotation_email_failed')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->reference)->toBe($quotation->quotation_number)
        ->and($audit->entity_id)->toBe($quotation->id)
        ->and($audit->description)->toContain('could not be delivered')
        ->and($audit->description)->not->toContain('smtp unavailable');
});

it('records a failed email when the quotation PDF cannot be generated, and sends no PDF-less email', function () {
    $booking = cdeBooking(cdeCustomer(), ['status' => 'quotation_sent']);
    $quotation = cdeQuotation($booking);

    app()->bind(DocumentGenerationService::class, fn () => new class extends DocumentGenerationService {
        public function renderQuotationPdf(Quotation $quotation): string
        {
            throw new \RuntimeException('render failed');
        }
    });

    $service = app(QuotationService::class);
    $service->sendQuotation($quotation);

    expect($quotation->fresh()->status)->toBe('sent')
        ->and($service->emailFailed($quotation))->toBeTrue()
        ->and(cdeSent())->toHaveCount(0)
        ->and(\App\Models\AuditLog::where('action', 'quotation_email_failed')->count())->toBe(1);
});

it('records nothing and reports no failure when the quotation email is delivered once with one PDF', function () {
    $booking = cdeBooking(cdeCustomer(), ['status' => 'quotation_sent']);
    $quotation = cdeQuotation($booking);

    $service = app(QuotationService::class);
    $service->sendQuotation($quotation);

    expect($service->emailFailed($quotation))->toBeFalse()
        ->and(\App\Models\AuditLog::where('action', 'quotation_email_failed')->count())->toBe(0)
        ->and(cdeSent())->toHaveCount(1)
        ->and(cdeSent()[0]->getAttachments())->toHaveCount(1);
});

it('tells the dispatcher when the quotation email failed instead of claiming it was sent', function () {
    $booking = cdeBooking(cdeCustomer(), ['status' => 'quotation_sent']);
    $quotation = cdeQuotation($booking);
    $quotation->update(['status' => 'draft']);
    $dispatcher = User::factory()->create(['role_id' => cdeRole(2, 'Dispatcher')->id]);
    cdeFailingTransport('cde-quote-failing-http');

    $response = $this->actingAs($dispatcher)->postJson(route('admin.quotations.send', $quotation->fresh()));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('email_failed', true);
    expect($response->json('message'))->toContain('could not be delivered')->not->toContain('sent to customer successfully');
    expect($quotation->fresh()->status)->toBe('sent');
});

it('refreshes the invoice PDF to Paid before anything else, so a failed receipt cannot leave an Unpaid PDF', function () {
    $booking = cdeBooking(cdeCustomer(), ['status' => 'waiting_verification']);
    $invoice = cdeInvoice($booking);
    app(DocumentGenerationService::class)->generateInvoice($invoice);
    $unpaid = Storage::disk('local')->get($invoice->fresh()->pdf_path);

    $booking->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

    $generator = new class extends DocumentGenerationService {
        public array $statuses = [];

        public function generateInvoice(Invoice $invoice, array $groupVehicles = [], float $groupAdjustment = 0.0): Invoice
        {
            $this->statuses[] = app(DocumentPresenter::class)->invoice($invoice)['pdf_status'];

            return parent::generateInvoice($invoice, $groupVehicles, $groupAdjustment);
        }

        public function generateReceipt(Booking $booking, array $groupVehicles = [], float $groupAdjustment = 0.0, ?Invoice $invoice = null): Receipt
        {
            throw new \RuntimeException('receipt render failed');
        }
    };
    app()->bind(DocumentGenerationService::class, fn () => $generator);

    expect(fn () => app(FinalPaymentDocumentsService::class)->send($booking->id))->toThrow(\RuntimeException::class);

    expect($generator->statuses)->toBe(['Paid'])
        ->and(Storage::disk('local')->get($invoice->fresh()->pdf_path))->not->toBe($unpaid)
        ->and(cdeSent())->toHaveCount(0)
        ->and($invoice->fresh()->email_sent)->toBeFalse()
        ->and(Receipt::where('booking_id', $booking->id)->count())->toBe(0);

    // The retry, once generation works again, delivers one email with the receipt created once.
    app()->bind(DocumentGenerationService::class, fn () => new DocumentGenerationService());

    expect(app(FinalPaymentDocumentsService::class)->send($booking->id))->toBe(FinalPaymentDocumentsService::SENT)
        ->and(cdeSent())->toHaveCount(1)
        ->and(Receipt::where('booking_id', $booking->id)->count())->toBe(1)
        ->and(Invoice::where('booking_id', $booking->id)->count())->toBe(1);
});

it('never replaces a valid stored invoice PDF with failed or partial output', function (string $output) {
    $booking = cdeBooking(cdeCustomer());
    $invoice = cdeInvoice($booking);
    app(DocumentGenerationService::class)->generateInvoice($invoice);
    $path = $invoice->fresh()->pdf_path;
    $valid = Storage::disk('local')->get($path);

    app()->bind(DocumentGenerationService::class, fn () => new class($output) extends DocumentGenerationService {
        public function __construct(private string $output)
        {
        }

        protected function renderPdf(string $html, ?string $footerText = null, bool $rightAlignedPageNumber = false): string
        {
            return $this->output;
        }
    });

    expect(fn () => app(DocumentGenerationService::class)->generateInvoice($invoice->fresh()))->toThrow(\RuntimeException::class);

    expect(Storage::disk('local')->get($path))->toBe($valid)
        ->and($invoice->fresh()->pdf_path)->toBe($path)
        ->and(Storage::disk('local')->exists($path . '.tmp'))->toBeFalse();
})->with([
    'empty output' => [''],
    'not a PDF' => ['<html>error</html>'],
    'truncated PDF' => ["%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>"],
]);

it('stores complete PDFs without leaving temporary files and keeps invoice numbers and amounts', function () {
    $booking = cdeBooking(cdeCustomer());
    $invoice = cdeInvoice($booking);
    $number = $invoice->fresh()->invoice_number;

    $generated = app(DocumentGenerationService::class)->generateInvoice($invoice);

    expect(Storage::disk('local')->get($generated->pdf_path))->toStartWith('%PDF')
        ->and(collect(Storage::disk('local')->allFiles('documents'))->filter(fn ($f) => str_ends_with($f, '.tmp')))->toHaveCount(0)
        ->and($generated->invoice_number)->toBe($number)
        ->and((float) $generated->total)->toBe(2923.20);
});
