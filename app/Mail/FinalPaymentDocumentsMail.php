<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Services\DocumentGenerationService;
use App\Services\DocumentPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The single email sent once a payment is confirmed: the invoice and the receipt as two
 * separate PDFs. Callers must pass a Receipt and Invoice that belong to this booking's
 * transaction (see FinalPaymentDocumentsService), whose stored PDFs are attached as-is.
 */
class FinalPaymentDocumentsMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $invoiceName;

    public string $receiptName;

    public string $amountLabel;

    public string $amount;

    public array $company;

    public function __construct(public Booking $booking, public Invoice $invoice, public Receipt $receipt)
    {
        $presenter = app(DocumentPresenter::class);
        $receiptDoc = $presenter->receipt($receipt);

        $this->invoiceName = DocumentFilename::for('Invoice', $invoice->invoice_number);
        $this->receiptName = DocumentFilename::for('Receipt', $receipt->receipt_number);
        $this->amountLabel = $receiptDoc['amount_label'] === 'AMOUNT RECEIVED' ? 'AMOUNT PAID' : $receiptDoc['amount_label'];
        $this->amount = $receiptDoc['amount'];
        $this->company = $presenter->company();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment confirmed — ' . $this->booking->job_code);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.final-payment-documents',
            with: [
                'customerName' => $this->booking->customer->full_name ?? 'Customer',
                'bookingRef' => $this->booking->job_code,
                'attachmentNames' => [$this->invoiceName, $this->receiptName],
            ],
        );
    }

    public function attachments(): array
    {
        return [
            $this->storedPdf($this->invoice->pdf_path, $this->invoiceName),
            $this->storedPdf($this->receipt->pdf_path, $this->receiptName),
        ];
    }

    private function storedPdf(?string $path, string $name): Attachment
    {
        if (blank($path) || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Cannot attach ' . $name . ': the stored PDF is missing.');
        }

        return Attachment::fromStorageDisk('local', $path)->as($name)->withMime('application/pdf');
    }
}
