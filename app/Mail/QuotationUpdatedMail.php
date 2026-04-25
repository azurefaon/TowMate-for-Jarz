<?php

namespace App\Mail;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class QuotationUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Quotation $quotation;
    public string $quotationUrl;
    public array $priceBreakdown;

    public function __construct(Quotation $quotation)
    {
        $this->quotation = $quotation;
        
        $expiresAt  = $quotation->expires_at ?? now()->addDays(7);
        $version    = $quotation->link_version ?? 1;

        $this->quotationUrl = URL::temporarySignedRoute(
            'quotation.show',
            $expiresAt,
            ['quotation' => $quotation->id, 'v' => $version]
        );

        $totalAmount = $quotation->estimated_price ?? 0;
        $basePrice   = $quotation->truckType->base_rate ?? 0;
        $perKmRate = $quotation->truckType->per_km_rate ?? 0;
        $distanceKm = $quotation->distance_km ?? 0;
        
        // Calculate distance fee with 4km rule
        $distanceFee = 0;
        $hasExcess = false;
        $excessKm = 0;
        $first4KmFee = 0;
        $excessFee = 0;
        
        if ($distanceKm > 4) {
            $hasExcess = true;
            $excessKm = $distanceKm - 4;
            $first4KmFee = 4 * $perKmRate;
            $excessFee = $excessKm * 200;
            $distanceFee = $first4KmFee + $excessFee;
        } else {
            $distanceFee = $distanceKm * $perKmRate;
        }
        
        $customerPrice = $basePrice + $distanceFee;
        $otherFees = $totalAmount - $customerPrice; // Calculate other fees from difference
        
        $this->priceBreakdown = [
            'base_price' => $basePrice,
            'per_km_rate' => $perKmRate,
            'distance_km' => $distanceKm,
            'distance_fee' => $distanceFee,
            'has_excess' => $hasExcess,
            'excess_km' => $excessKm,
            'first_4km_fee' => $first4KmFee,
            'excess_fee' => $excessFee,
            'customer_price' => $customerPrice,
            'other_fees' => $otherFees,
            'total_amount' => $totalAmount, // Use stored estimated_price as final total
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Updated Quotation - ' . $this->quotation->quotation_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-updated',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
