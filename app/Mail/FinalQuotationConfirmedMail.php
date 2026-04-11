<?php

namespace App\Mail;

use App\Models\Booking;
use App\Services\DocumentGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FinalQuotationConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public ?string $documentUrl;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking->loadMissing(['customer', 'truckType']);
        $this->documentUrl = app(DocumentGenerationService::class)->publicDocumentUrl($booking->final_quote_path);
    }

    public function build()
    {
        return $this->subject('Your Jarz final quotation is confirmed')
            ->view('emails.final-quotation-confirmed');
    }
}
