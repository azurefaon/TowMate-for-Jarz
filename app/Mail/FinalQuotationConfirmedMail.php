<?php

namespace App\Mail;

use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FinalQuotationConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public array $priceBreakdown;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking->loadMissing(['customer', 'truckType']);

        $baseRate      = (float) ($booking->truckType->base_rate ?? 0);
        $distanceKm    = (float) ($booking->distance_km ?? 0);
        $additionalFee = (float) ($booking->additional_fee ?? 0);
        $total         = (float) ($booking->final_total ?? 0);
        $distanceFee   = app(BookingService::class)->distanceFeeFor($distanceKm, (float) ($booking->truckType->per_km_rate ?? 0));

        $this->priceBreakdown = [
            'base_rate'      => $baseRate,
            'distance_km'    => $distanceKm,
            'distance_fee'   => $distanceFee,
            'additional_fee' => $additionalFee,
            'total'          => $total,
        ];
    }

    public function build()
    {
        return $this->subject('Your TowMate booking is confirmed — ' . ($this->booking->quotation_number ?? ''))
            ->view('emails.final-quotation-confirmed')
            ->with([
                'booking'        => $this->booking,
                'priceBreakdown' => $this->priceBreakdown,
            ]);
    }
}
