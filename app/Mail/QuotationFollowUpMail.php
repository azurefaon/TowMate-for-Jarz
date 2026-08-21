<?php

namespace App\Mail;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotationFollowUpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Quotation $quotation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reminder: Your Quotation Is Still Waiting — ' . $this->quotation->quotation_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-followup',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
