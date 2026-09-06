<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DispatcherNotification extends Model
{
    public const TYPE_NEW_BOOK_NOW = 'new_book_now';
    public const TYPE_NEW_SCHEDULED = 'new_scheduled';
    public const TYPE_VERIFICATION_REQUIRED = 'verification_required';

    protected $fillable = [
        'type',
        'booking_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Text is derived live from the current booking/customer/truck-type
     * relationships rather than frozen at creation time, so it never goes
     * stale if a customer name or booking detail changes later.
     */
    public function getTitleAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_NEW_BOOK_NOW => 'New booking request',
            self::TYPE_NEW_SCHEDULED => 'New scheduled booking',
            self::TYPE_VERIFICATION_REQUIRED => 'Verification required',
            default => 'Booking update',
        };
    }

    public function getSubtitleLinesAttribute(): array
    {
        $booking = $this->booking;

        if (! $booking) {
            return ['This booking is no longer available.'];
        }

        $customerName = $booking->customer->full_name ?? 'Customer';
        $truckClass = $booking->truckType->name ?? 'Tow request';

        return match ($this->type) {
            self::TYPE_NEW_BOOK_NOW => [
                "{$booking->booking_code} · {$customerName}",
                "Book Now · {$truckClass}",
            ],
            self::TYPE_NEW_SCHEDULED => [
                "{$booking->booking_code} · {$customerName}",
                optional($booking->scheduled_for)->format('M j \\· g:i A') ?? 'Schedule pending',
            ],
            self::TYPE_VERIFICATION_REQUIRED => [
                "{$booking->booking_code} · {$customerName}",
                'Waiting for verification',
            ],
            default => ["{$booking->booking_code} · {$customerName}"],
        };
    }

    /**
     * Where clicking this notification should navigate — reuses the
     * existing Dispatch Queue / Active Jobs pages rather than building a
     * separate booking-detail view just for notifications. For Book Now/
     * Scheduled, ?type=&booking= lets the Dispatch Queue page (see
     * applyNotificationDeepLink() in dispatcher/js/dispatch.js) activate the
     * right tab and open the existing booking drawer directly.
     */
    public function getTargetUrlAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_NEW_BOOK_NOW => route('admin.dispatch', ['type' => 'book-now', 'booking' => $this->booking?->booking_code]),
            self::TYPE_NEW_SCHEDULED => route('admin.dispatch', ['type' => 'scheduled', 'booking' => $this->booking?->booking_code]),
            self::TYPE_VERIFICATION_REQUIRED => route('admin.jobs'),
            default => route('admin.dashboard'),
        };
    }
}
