<?php

namespace App\Models;

use App\Models\Concerns\GeneratesPublicCode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory, GeneratesPublicCode;

    protected $fillable = [
        'booking_code',
        'quotation_id',
        'group_code',
        'customer_id',
        'truck_type_id',
        'assigned_unit_id',
        'assigned_team_leader_id',
        'created_by_admin_id',
        'age',

        'pickup_address',
        'pickup_notes',
        'pickup_lat',
        'pickup_lng',

        'dropoff_address',
        'dropoff_lat',
        'dropoff_lng',

        'distance_km',
        'eta_minutes',
        'base_rate',
        'per_km_rate',
        'computed_total',
        'discount_percentage',
        'discount_reason',
        'additional_fee',
        'final_total',
        'customer_type',
        'service_type',
        'scheduled_date',
        'scheduled_time',
        'scheduled_for',
        'confirmation_type',
        'vehicle_image_path',
        'notes',
        'remarks',
        'quotation_number',
        'initial_quote_path',
        'final_quote_path',
        'quotation_generated',
        'quotation_status',
        'dispatcher_note',
        'driver_name',
        'assigned_at',
        'completed_at',
        'rejection_reason',
        'reviewed_at',
        'quoted_at',
        'quotation_sent_at',
        'quotation_expires_at',
        'quotation_follow_up_sent_at',
        'negotiation_requested_at',
        'counter_offer_amount',
        'customer_approved_at',
        'price_locked_at',
        'customer_response_note',
        'completion_requested_at',
        'customer_verified_at',
        'customer_verification_status',
        'customer_verification_note',
        'returned_at',
        'return_reason',
        'returned_by_team_leader_id',

        'completion_otp',
        'completion_otp_expires_at',
        'arrival_photo_path',
        'dropoff_photo_path',
        'customer_signature_path',

        'payment_method',
        'payment_proof_path',
        'payment_submitted_at',
        'paymongo_link_id',
        'paymongo_checkout_url',
        'paymongo_intent_id',
        'paymongo_client_key',

        'scheduled_expires_at',
        'extra_vehicles',

        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (blank($booking->booking_code)) {
                $booking->booking_code = static::nextPublicCode('booking_code');
            }
        });

        static::saving(function (Booking $booking) {
            $booking->quotation_status = $booking->resolveQuotationStatus($booking->quotation_status ?? null);
        });

        static::updated(function (Booking $booking) {
            if ($booking->isDirty(['pickup_address', 'dropoff_address', 'truck_type_id', 'distance_km', 'final_total'])) {
                if (in_array($booking->status, ['quoted', 'pending', 'reviewed'])) {
                    app(\App\Services\QuotationService::class)->updateQuotation($booking);
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quotation_generated' => 'boolean',
            'scheduled_date' => 'date',
            'scheduled_for' => 'datetime',
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'quoted_at' => 'datetime',
            'quotation_sent_at' => 'datetime',
            'quotation_expires_at' => 'datetime',
            'quotation_follow_up_sent_at' => 'datetime',
            'negotiation_requested_at' => 'datetime',
            'customer_approved_at' => 'datetime',
            'price_locked_at' => 'datetime',
            'counter_offer_amount' => 'decimal:2',
            'completion_requested_at' => 'datetime',
            'customer_verified_at' => 'datetime',
            'returned_at' => 'datetime',
            'payment_submitted_at' => 'datetime',
            'scheduled_expires_at' => 'datetime',
            'extra_vehicles' => 'array',
            'base_rate' => 'decimal:2',
            'per_km_rate' => 'decimal:2',
            'computed_total' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'additional_fee' => 'decimal:2',
            'final_total' => 'decimal:2',
            'eta_minutes' => 'decimal:2',
            'payment_proof_path' => 'array',
        ];
    }

    public function getVehicleImagePathsAttribute(): array
    {
        $raw = $this->attributes['vehicle_image_path'] ?? null;
        if (! $raw) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [$raw];
    }

    public function getRouteKeyName(): string
    {
        return 'booking_code';
    }

    public function getJobCodeAttribute(): string
    {
        return $this->booking_code ?: str_pad((string) $this->getKey(), 7, '0', STR_PAD_LEFT);
    }

    public function resolveQuotationStatus(?string $currentStatus = null): string
    {
        $resolvedStatus = strtolower(trim((string) $currentStatus));
        $allowedStatuses = ['active', 'expired', 'cancelled', 'accepted'];
        $bookingStatus = strtolower(trim((string) ($this->status ?? 'requested')));

        if (in_array($bookingStatus, ['cancelled', 'rejected'], true)) {
            return 'cancelled';
        }

        if (in_array($bookingStatus, ['confirmed', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'payment_pending', 'payment_submitted', 'completed', 'on_job'], true)) {
            return 'accepted';
        }

        if ($this->shouldExpireQuotation()) {
            return 'expired';
        }

        return in_array($resolvedStatus, $allowedStatuses, true) ? $resolvedStatus : 'active';
    }

    public function shouldExpireQuotation(): bool
    {
        return in_array((string) $this->status, ['quoted', 'quotation_sent'], true)
            && ! is_null($this->quotation_expires_at)
            && $this->quotation_expires_at->isPast();
    }

    public function needsQuotationFollowUp(): bool
    {
        return in_array((string) $this->status, ['quoted', 'quotation_sent'], true)
            && (string) $this->quotation_status === 'active'
            && ! $this->shouldExpireQuotation()
            && ! is_null($this->quotation_sent_at)
            && $this->quotation_sent_at->lte(now()->subDays(5))
            && is_null($this->quotation_follow_up_sent_at);
    }

    public function syncQuotationLifecycle(): bool
    {
        $resolvedStatus = $this->resolveQuotationStatus($this->quotation_status ?? null);

        if ($resolvedStatus === (string) ($this->quotation_status ?? '')) {
            return false;
        }

        $this->forceFill([
            'quotation_status' => $resolvedStatus,
        ])->save();

        return $resolvedStatus === 'expired';
    }

    public function getQuotationValidityLabelAttribute(): ?string
    {
        return $this->quotation_expires_at?->format('M d, Y g:i A');
    }

    public function getDistanceFeeAmountAttribute(): float
    {
        $distanceKm = (float) ($this->distance_km ?? 0);
        $kmIncrements = (int) floor($distanceKm / 4);
        return round($kmIncrements * 200.0, 2);
    }

    public function getExcessKmAttribute(): float
    {
        $threshold = (float) SystemSetting::getValue('excess_km_threshold', setting('excess_km_threshold', 10));

        if ($threshold <= 0) {
            $threshold = (float) setting('excess_km_threshold', 10);
        }

        return max(round((float) ($this->distance_km ?? 0) - $threshold, 2), 0);
    }

    public function getExcessFeeAmountAttribute(): float
    {
        $rate = (float) SystemSetting::getValue('excess_km_rate', setting('excess_km_rate', 20));

        if ($rate <= 0) {
            $rate = (float) setting('excess_km_rate', 20);
        }

        return round($this->excess_km * $rate, 2);
    }

    public function getDiscountAmountAttribute(): float
    {
        $computedTotal = (float) ($this->computed_total ?? 0);
        $discountPercentage = (float) ($this->discount_percentage ?? 0);

        return round($computedTotal * ($discountPercentage / 100), 2);
    }

    public function getQuotationBreakdownAttribute(): array
    {
        return [
            'base_rate' => (float) ($this->base_rate ?? 0),
            'distance_fee' => $this->distance_fee_amount,
            'excess_km' => $this->excess_km,
            'excess_fee' => $this->excess_fee_amount,
            'additional_fee' => (float) ($this->additional_fee ?? 0),
            'discount' => 0,
            'final_total' => (float) ($this->final_total ?? 0),
        ];
    }

    public function getServiceModeAttribute(): string
    {
        $serviceType = strtolower(trim((string) ($this->attributes['service_type'] ?? '')));

        if (in_array($serviceType, ['book_now', 'schedule'], true)) {
            return $serviceType;
        }

        return $this->getScheduledForAttribute(null) ? 'schedule' : 'book_now';
    }

    public function getServiceModeLabelAttribute(): string
    {
        return $this->service_mode === 'schedule' ? 'Schedule Later' : 'Book Now';
    }

    public function getScheduledForAttribute($value): ?Carbon
    {
        if (! empty($value)) {
            return Carbon::parse($value);
        }

        $scheduledDate = $this->attributes['scheduled_date'] ?? null;
        $scheduledTime = $this->attributes['scheduled_time'] ?? null;

        if (! empty($scheduledDate)) {
            return Carbon::parse(trim($scheduledDate . ' ' . ($scheduledTime ?: '00:00')));
        }

        $notes = (string) ($this->attributes['notes'] ?? '');

        if (preg_match('/Requested schedule:\s*(\d{4}-\d{2}-\d{2})(?:\s+(\d{2}:\d{2}))?/i', $notes, $matches)) {
            return Carbon::parse(trim(($matches[1] ?? '') . ' ' . ($matches[2] ?? '00:00')));
        }

        return null;
    }

    public function getIsScheduledAttribute(): bool
    {
        return $this->service_mode === 'schedule';
    }

    public function getIsDueForDispatchAttribute(): bool
    {
        return $this->is_scheduled && $this->scheduled_for?->lte(now());
    }

    public function getIsDispatchDelayedAttribute(): bool
    {
        if ((string) $this->status === 'delayed') {
            return true;
        }

        return $this->is_scheduled
            && ! is_null($this->scheduled_for)
            && in_array($this->status, ['requested', 'reviewed'], true)
            && $this->scheduled_for->copy()->addHour()->lte(now());
    }

    public function getNeedsReassignmentAttribute(): bool
    {
        if ($this->status === 'confirmed') {
            return false;
        }

        return !is_null($this->returned_at)
            && !empty($this->return_reason)
            && in_array($this->status, ['accepted', 'assigned']);
    }

    public function getScheduleWindowLabelAttribute(): string
    {
        if (! $this->is_scheduled) {
            return 'Immediate dispatch';
        }

        if (! $this->scheduled_for) {
            return 'Scheduled time pending';
        }

        if ($this->is_dispatch_delayed) {
            return 'Delayed · ' . $this->scheduled_for->format('M d, Y g:i A');
        }

        return $this->is_due_for_dispatch
            ? 'Due now · ' . $this->scheduled_for->format('M d, Y g:i A')
            : 'Scheduled for ' . $this->scheduled_for->format('M d, Y g:i A');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function truckType()
    {
        return $this->belongsTo(TruckType::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'assigned_unit_id');
    }

    public function assignedTeamLeader()
    {
        return $this->belongsTo(User::class, 'assigned_team_leader_id');
    }

    public function returnedByTeamLeader()
    {
        return $this->belongsTo(User::class, 'returned_by_team_leader_id');
    }

    public function receipt()
    {
        return $this->hasOne(Receipt::class);
    }

    public function getIsScheduledQueueAttribute(): bool
    {
        return in_array($this->status, ['scheduled', 'scheduled_confirmed'], true);
    }

    public function getIsScheduledExpiredAttribute(): bool
    {
        return ! is_null($this->scheduled_expires_at)
            && $this->scheduled_expires_at->isPast();
    }

    public function scopeScheduledQueue($query)
    {
        return $query->whereIn('status', ['scheduled', 'scheduled_confirmed']);
    }

    public function scopeBookNowQueue($query)
    {
        return $query->whereIn('status', ['requested', 'reviewed'])
            ->where(function ($q) {
                $q->whereNull('service_type')
                    ->orWhere('service_type', 'book_now');
            });
    }
}
