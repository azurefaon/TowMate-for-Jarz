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
    public bool $isReminder;
    public ?string $validUntilLabel;

    public function __construct(Booking $booking, bool $isReminder = false)
    {
        $this->booking = $booking->loadMissing(['customer', 'truckType']);
        $this->isReminder = $isReminder;
        $this->validUntilLabel = $this->booking->quotation_validity_label;
        $this->reviewUrl = URL::temporarySignedRoute(
            'quotation.review',
            $this->booking->quotation_expires_at ?? now()->addDays(7),
            ['booking' => $this->booking]
        );
        $this->documentUrl = app(DocumentGenerationService::class)->publicDocumentUrl($booking->initial_quote_path);
    }

    public function build()
    {
        return $this->subject($this->isReminder ? 'Reminder: your Jarz quotation is still active' : 'Your Jarz quotation is ready')
            ->view('emails.booking-accepted');
    }
}
