<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffPasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $otp,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'TowMate Password Verification Code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.staff-password-reset-otp');
    }
}
