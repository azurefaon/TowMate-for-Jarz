<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\DocumentGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public Invoice $invoice;
    public ?string $invoiceUrl;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice->loadMissing(['booking.customer', 'booking.truckType', 'previousInvoice']);
        $this->invoiceUrl = app(DocumentGenerationService::class)->publicDocumentUrl($this->invoice->pdf_path);
    }

    public function build()
    {
        return $this->subject('Your invoice for Job ' . ($this->invoice->booking->job_code ?? $this->invoice->booking->booking_code) . ' — ₱' . number_format((float) $this->invoice->total, 2))
            ->view('emails.invoice');
    }
}
