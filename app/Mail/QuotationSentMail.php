<?php

namespace App\Mail;

use App\Models\Quotation;
use App\Services\DocumentGenerationService;
use App\Services\DocumentPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotationSentMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $doc;

    public string $attachmentName;

    public function __construct(public Quotation $quotation)
    {
        $quotation->loadMissing(['customer', 'truckType', 'sourceBooking']);

        $this->doc = app(DocumentPresenter::class)->quotation($quotation, $quotation->sourceBooking);
        $this->attachmentName = DocumentFilename::for('Quotation', $quotation->quotation_number);
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
            with: [
                'doc' => $this->doc,
                'attachmentName' => $this->attachmentName,
                'expiresAt' => $this->quotation->expires_at?->format('M d, Y g:i A'),
            ],
        );
    }

    public function attachments(): array
    {
        $quotation = $this->quotation;

        return [
            Attachment::fromData(
                fn () => app(DocumentGenerationService::class)->renderQuotationPdf($quotation),
                $this->attachmentName
            )->withMime('application/pdf'),
        ];
    }
}
