<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CompletionOtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string  $otp,
    ) {}

    public function build(): static
    {
        return $this->subject("TowMate — Your completion OTP for {$this->booking->booking_code}")
            ->view('emails.completion-otp');
    }
}
