<?php

namespace App\Mail;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;


class QuotationSentMail extends Mailable
{
    use Queueable, SerializesModels;

    public Quotation $quotation;
    public array $priceBreakdown;

    public function __construct(Quotation $quotation)
    {
        $this->quotation = $quotation;

        $totalAmount = $quotation->estimated_price ?? 0;
        $basePrice   = $quotation->truckType->base_rate ?? 0;
        $perKmRate   = $quotation->truckType->per_km_rate ?? 0;
        $distanceKm  = $quotation->distance_km ?? 0;

        $distanceFee  = 0;
        $hasExcess    = false;
        $excessKm     = 0;
        $first4KmFee  = 0;
        $excessFee    = 0;

        if ($distanceKm > 4) {
            $hasExcess   = true;
            $excessKm    = $distanceKm - 4;
            $first4KmFee = 4 * $perKmRate;
            $excessFee   = $excessKm * 200;
            $distanceFee = $first4KmFee + $excessFee;
        } else {
            $distanceFee = $distanceKm * $perKmRate;
        }

        $customerPrice = $basePrice + $distanceFee;
        $otherFees     = $totalAmount - $customerPrice;

        $this->priceBreakdown = [
            'base_price'    => $basePrice,
            'per_km_rate'   => $perKmRate,
            'distance_km'   => $distanceKm,
            'distance_fee'  => $distanceFee,
            'has_excess'    => $hasExcess,
            'excess_km'     => $excessKm,
            'first_4km_fee' => $first4KmFee,
            'excess_fee'    => $excessFee,
            'customer_price' => $customerPrice,
            'other_fees'    => $otherFees,
            'total_amount'  => $totalAmount,
        ];
    }

    public function build()
    {
        $expiresAt = $this->quotation->expires_at ?? now()->addDays(7);

        $version = $this->quotation->link_version ?? 1;

        $signedAcceptUrl = URL::temporarySignedRoute(
            'quotation.accept',
            $expiresAt,
            ['quotation' => $this->quotation->id, 'v' => $version]
        );

        $signedShowUrl = URL::temporarySignedRoute(
            'quotation.show',
            $expiresAt,
            ['quotation' => $this->quotation->id, 'v' => $version]
        );

        return $this->view('emails.quotation-sent')
            ->with([
                'quotation' => $this->quotation,
                'priceBreakdown' => $this->priceBreakdown,
                'signedAcceptUrl' => $signedAcceptUrl,
                'quotationUrl' => $signedShowUrl,
            ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Towing Service Quotation - ' . $this->quotation->quotation_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-sent',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
