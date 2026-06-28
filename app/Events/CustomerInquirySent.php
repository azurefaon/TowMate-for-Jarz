<?php

namespace App\Events;

use App\Models\Quotation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerInquirySent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Quotation $quotation, public string $message) {}

    public function broadcastOn(): array
    {
        return [new Channel('dispatch')];
    }

    public function broadcastAs(): string
    {
        return 'customer.inquiry';
    }

    public function broadcastWith(): array
    {
        $this->quotation->loadMissing('sourceBooking');
        $booking = $this->quotation->sourceBooking;

        return [
            'quotation_id'   => $this->quotation->id,
            'booking_code'   => $booking?->booking_code ?? $booking?->job_code ?? null,
            'customer_name'  => $booking?->customer?->full_name ?? 'Customer',
            'message'        => $this->message,
            'sent_at'        => now()->toISOString(),
        ];
    }
}
