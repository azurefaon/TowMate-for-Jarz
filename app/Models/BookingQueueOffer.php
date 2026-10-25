<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BookingQueueOffer extends Model
{
    public const OFFERED = 'offered';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';
    public const PROMOTED = 'promoted';

    /** An offer that still holds its reservation. */
    public const ACTIVE = [self::OFFERED, self::ACCEPTED];

    protected $fillable = [
        'booking_id', 'behind_booking_id', 'team_leader_id', 'unit_id', 'status',
        'offered_at', 'expires_at', 'responded_at', 'accepted_at', 'promoted_at',
        'cancelled_at', 'cancel_reason', 'created_by', 'cancelled_by',
    ];

    protected $casts = [
        'offered_at' => 'datetime',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
        'accepted_at' => 'datetime',
        'promoted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function behindBooking()
    {
        return $this->belongsTo(Booking::class, 'behind_booking_id');
    }

    public function teamLeader()
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
