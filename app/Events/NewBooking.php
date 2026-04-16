<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Booking;

class NewBooking implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;

    /**
     * Create a new event instance.
     */
    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('dispatch'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'booking.created';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $this->booking->loadMissing(['customer', 'truckType']);

        return [
            'id' => $this->booking->booking_code ?: $this->booking->id,
            'booking_code' => $this->booking->job_code,
            'pickup_address' => $this->booking->pickup_address,
            'dropoff_address' => $this->booking->dropoff_address,
            'created_at' => optional($this->booking->created_at)->toISOString(),
            'created_at_human' => optional($this->booking->created_at)->diffForHumans() ?? 'Just now',
            'truck_type_name' => $this->booking->truckType->name ?? 'Unknown',
            'customer_name' => $this->booking->customer->full_name ?? 'Unknown',
            'customer_phone' => $this->booking->customer->phone ?? 'N/A',
            'service_type' => $this->booking->service_mode,
            'service_mode_label' => $this->booking->service_mode_label,
            'scheduled_for' => optional($this->booking->scheduled_for)->toISOString(),
            'schedule_window_label' => $this->booking->schedule_window_label,
        ];
    }
}
