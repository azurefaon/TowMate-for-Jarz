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

    public array $priceBreakdown;
    public string $signedAcceptUrl;
    public string $quotationUrl;

    public function __construct(public Quotation $quotation)
    {
        $totalAmount   = (float) ($quotation->estimated_price ?? 0);
        $basePrice     = (float) ($quotation->truckType->base_rate ?? 0);
        $distanceKm    = (float) ($quotation->distance_km ?? 0);
        $additionalFee = (float) ($quotation->additional_fee ?? 0);

        $kmIncrements = (int) floor($distanceKm / 4);
        $distanceFee  = round($kmIncrements * 200.0, 2);

        $this->priceBreakdown = [
            'base_price'     => $basePrice,
            'distance_km'    => $distanceKm,
            'distance_fee'   => $distanceFee,
            'km_increments'  => $kmIncrements,
            'additional_fee' => $additionalFee,
            'has_excess'     => false,
            'total_amount'   => $totalAmount,
        ];

        $expiresAt = $quotation->expires_at ?? now()->addDays(7);
        $version   = $quotation->link_version ?? 1;

        $this->signedAcceptUrl = URL::temporarySignedRoute(
            'quotation.accept',
            $expiresAt,
            ['quotation' => $quotation->id, 'v' => $version]
        );

        $this->quotationUrl = URL::temporarySignedRoute(
            'quotation.show',
            $expiresAt,
            ['quotation' => $quotation->id, 'v' => $version]
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Towing Service Quotation — ' . $this->quotation->quotation_number,
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
