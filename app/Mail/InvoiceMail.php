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
    public array $groupVehicles;
    public float $groupAdjustment;

    public function __construct(Invoice $invoice, array $groupVehicles = [], float $groupAdjustment = 0.0)
    {
        $this->invoice = $invoice->loadMissing(['booking.customer', 'booking.truckType', 'previousInvoice']);
        $this->invoiceUrl = app(DocumentGenerationService::class)->publicDocumentUrl($this->invoice->pdf_path);
        $this->groupVehicles = $groupVehicles;
        $this->groupAdjustment = $groupAdjustment;
    }

    public function build()
    {
        return $this->subject('Your invoice for Job ' . ($this->invoice->booking->job_code ?? $this->invoice->booking->booking_code) . ' — ₱' . number_format((float) $this->invoice->total, 2))
            ->view('emails.invoice');
    }
}
