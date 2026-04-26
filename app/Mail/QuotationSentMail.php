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

        $totalAmount   = (float) ($quotation->estimated_price ?? 0);
        $basePrice     = (float) ($quotation->truckType->base_rate ?? 0);
        $distanceKm    = (float) ($quotation->distance_km ?? 0);
        $additionalFee = (float) ($quotation->additional_fee ?? 0);

        // Per-4km: ₱200 per complete 4km increment (matches dispatcher formula)
        $kmIncrements = (int) floor($distanceKm / 4);
        $distanceFee  = round($kmIncrements * 200.0, 2);

        $this->priceBreakdown = [
            'base_price'    => $basePrice,
            'distance_km'   => $distanceKm,
            'distance_fee'  => $distanceFee,
            'km_increments' => $kmIncrements,
            'additional_fee' => $additionalFee,
            'has_excess'    => false,
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
