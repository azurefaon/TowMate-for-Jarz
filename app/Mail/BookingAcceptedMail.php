<?php

namespace App\Mail;

use App\Models\Booking;
use App\Services\DocumentGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class BookingAcceptedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $booking;
    public $reviewUrl;
    public ?string $documentUrl;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking->loadMissing(['customer', 'truckType']);
        $this->reviewUrl = URL::temporarySignedRoute(
            'quotation.review',
            now()->addDays(7),
            ['booking' => $this->booking]
        );
        $this->documentUrl = app(DocumentGenerationService::class)->publicDocumentUrl($booking->initial_quote_path);
    }

    public function build()
    {
        return $this->subject('Your Jarz quotation is ready')
            ->view('emails.booking-accepted');
    }
}
