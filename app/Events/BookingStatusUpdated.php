<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Booking $booking)
    {
        $this->booking->loadMissing(['customer', 'truckType', 'unit.driver', 'unit.teamLeader', 'assignedTeamLeader']);
    }

    public function broadcastOn(): array
    {
        return [new Channel('dispatch')];
    }

    public function broadcastAs(): string
    {
        return 'booking.updated';
    }

    public function broadcastWith(): array
    {
        $teamLeaderName = optional(optional($this->booking->unit)->teamLeader)->name
            ?? optional($this->booking->assignedTeamLeader)->name
            ?? 'Awaiting crew';

        $driverName = $this->booking->driver_name
            ?? optional(optional($this->booking->unit)->driver)->name
            ?? 'Driver pending';

        return [
            'id' => $this->booking->booking_code,
            'booking_code' => $this->booking->booking_code,
            'status' => $this->booking->status,
            'status_label' => str($this->booking->status)->replace('_', ' ')->title()->toString(),
            'customer_name' => $this->booking->customer->full_name ?? 'Customer',
            'truck_type_name' => $this->booking->truckType->name ?? 'Tow service',
            'unit_name' => $this->booking->unit->name ?? 'Unit pending',
            'driver_name' => $driverName,
            'team_leader_name' => $teamLeaderName,
            'pickup_address' => $this->booking->pickup_address,
            'dropoff_address' => $this->booking->dropoff_address,
            'updated_at_human' => optional($this->booking->updated_at)->diffForHumans() ?? 'Just now',
        ];
    }
}
