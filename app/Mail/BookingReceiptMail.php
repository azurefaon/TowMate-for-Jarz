<?php

namespace App\Mail;

use App\Models\Booking;
use App\Services\DocumentGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public ?string $receiptUrl;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking->loadMissing(['customer', 'truckType', 'receipt']);
        $this->receiptUrl = app(DocumentGenerationService::class)->publicDocumentUrl($this->booking->receipt?->pdf_path);
    }

    public function build()
    {
        return $this->subject('Your Jarz service receipt')
            ->view('emails.booking-receipt');
    }
}
