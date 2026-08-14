<?php

namespace App\Mail;

use App\Models\Quotation;
use App\Services\BookingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotationUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Quotation $quotation;
    public array $priceBreakdown;

    public function __construct(Quotation $quotation)
    {
        $this->quotation = $quotation;

        $sourceBooking = $quotation->sourceBooking;
        $totalAmount   = (float) ($quotation->estimated_price ?? 0);
        $basePrice     = (float) ($quotation->truckType->base_rate ?? 0);
        $distanceKm    = (float) ($quotation->distance_km ?? 0);
        $additionalFee = (float) ($quotation->additional_fee ?? 0);

        $distanceFee = app(BookingService::class)->distanceFeeFor($distanceKm);

        $vatAmount = (float) ($sourceBooking?->vat_amount ?? 0);
        if ($vatAmount <= 0 && $totalAmount > 0) {
            $vatAmount = round(($totalAmount - $additionalFee) / 1.12 * 0.12, 2);
        }

        $additionalFeeNote = $sourceBooking?->dispatcher_note
            ?? collect($quotation->price_change_log ?? [])->last()['reason']
            ?? null;

        $this->priceBreakdown = [
            'base_price'          => $basePrice,
            'distance_km'         => $distanceKm,
            'distance_fee'        => $distanceFee,
            'vat_amount'          => $vatAmount,
            'other_fees'          => $additionalFee,
            'additional_fee_note' => $additionalFeeNote,
            'total_amount'        => $totalAmount,
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
