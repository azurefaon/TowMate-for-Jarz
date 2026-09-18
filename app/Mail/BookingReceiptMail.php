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
    public array $groupVehicles;
    public float $groupAdjustment;

    public function __construct(Booking $booking, array $groupVehicles = [], float $groupAdjustment = 0.0)
    {
        $this->booking = $booking->loadMissing(['customer', 'truckType', 'receipt', 'unit.teamLeader', 'unit.driver']);
        $this->receiptUrl = app(DocumentGenerationService::class)->publicDocumentUrl($this->booking->receipt?->pdf_path);
        $this->groupVehicles = $groupVehicles;
        $this->groupAdjustment = $groupAdjustment;
    }

    public function build()
    {
        return $this->subject('Your Jarz service receipt')
            ->view('emails.booking-receipt');
    }
}
