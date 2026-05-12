<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;

class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_number',
        'source_booking_id',
        'customer_id',
        'truck_type_id',
        'pickup_address',
        'dropoff_address',
        'pickup_notes',
        'distance_km',
        'eta_minutes',
        'vehicle_make',
        'vehicle_model',
        'vehicle_year',
        'vehicle_color',
        'vehicle_plate_number',
        'vehicle_image_path',
        'extra_vehicles',
        'estimated_price',
        'additional_fee',
        'discount',
        'counter_offer_amount',
        'service_type',
        'status',
        'sent_at',
        'expires_at',
        'expiry_hours',
        'viewed_at',
        'responded_at',
        'follow_up_sent_at',
        'response_note',
        'link_version',
        'scheduled_date',
        'scheduled_time',
    ];

    protected $casts = [
        'distance_km' => 'decimal:2',
        'eta_minutes' => 'decimal:2',
        'estimated_price' => 'decimal:2',
        'additional_fee' => 'decimal:2',
        'discount' => 'decimal:2',
        'counter_offer_amount' => 'decimal:2',
        'expiry_hours' => 'integer',
        'link_version' => 'integer',
        'scheduled_date' => 'date',
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'viewed_at' => 'datetime',
        'responded_at' => 'datetime',
        'follow_up_sent_at' => 'datetime',
        'extra_vehicles' => 'array',
    ];

    public function getVehicleImagePathsAttribute(): array
    {
        $raw = $this->attributes['vehicle_image_path'] ?? null;
        if (! $raw) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [$raw];
    }

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function truckType(): BelongsTo
    {
        return $this->belongsTo(TruckType::class);
    }

    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class);
    }

    public function sourceBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'source_booking_id');
    }

    // Helper methods
    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'sent']) &&
            ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function needsFollowUp(): bool
    {
        return $this->status === 'sent' &&
            $this->sent_at &&
            $this->sent_at->diffInDays(now()) >= 5 &&
            !$this->follow_up_sent_at &&
            !$this->responded_at &&
            !$this->isExpired();
    }

    public function getTimeRemaining(): ?array
    {
        if (!$this->expires_at) {
            return null;
        }

        if ($this->isExpired()) {
            return [
                'expired' => true,
                'message' => 'Expired',
                'urgency' => 'expired'
            ];
        }

        $now = now();
        $hoursRemaining = $now->diffInHours($this->expires_at);
        $minutesRemaining = $now->copy()->addHours($hoursRemaining)->diffInMinutes($this->expires_at);

        // Urgency levels
        $urgency = 'normal';
        if ($hoursRemaining < 48) {
            $urgency = 'urgent'; // Red
        } elseif ($hoursRemaining < 120) {
            $urgency = 'warning'; // Yellow
        }

        $message = '';
        if ($hoursRemaining > 24) {
            $days = floor($hoursRemaining / 24);
            $hours = $hoursRemaining % 24;
            $message = "{$days}d {$hours}h remaining";
        } elseif ($hoursRemaining > 0) {
            $message = "{$hoursRemaining}h {$minutesRemaining}m remaining";
        } else {
            $message = "{$minutesRemaining}m remaining";
        }

        return [
            'expired' => false,
            'hours' => $hoursRemaining,
            'minutes' => $minutesRemaining,
            'message' => $message,
            'urgency' => $urgency
        ];
    }

    public function getUrgencyLevel(): string
    {
        if ($this->status === 'expired' || $this->status === 'disregarded') {
            return 'expired';
        }

        if ($this->status === 'accepted') {
            return 'accepted';
        }

        if ($this->status === 'rejected') {
            return 'rejected';
        }

        $timeRemaining = $this->getTimeRemaining();
        return $timeRemaining['urgency'] ?? 'normal';
    }

    public function markAsViewed(): void
    {
        if (!$this->viewed_at) {
            $this->update(['viewed_at' => now()]);
        }
    }

    public function markAsResponded(): void
    {
        if (!$this->responded_at) {
            $this->update(['responded_at' => now()]);
        }
    }
}
