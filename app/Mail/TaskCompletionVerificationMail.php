<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TaskCompletionVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public string $approveUrl;
    public ?string $rejectUrl;

    public function __construct(Booking $booking, string $approveUrl, ?string $rejectUrl = null)
    {
        $this->booking = $booking;
        $this->approveUrl = $approveUrl;
        $this->rejectUrl = $rejectUrl;
    }

    public function build()
    {
        return $this->subject('Jarz Task Completion Verification')
            ->view('emails.task-completion-verification');
    }
}
