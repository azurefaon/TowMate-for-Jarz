<?php

namespace App\Models;

use App\Models\Concerns\GeneratesPublicCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory, GeneratesPublicCode;

    protected $fillable = [
        'booking_code',
        'customer_id',
        'truck_type_id',
        'assigned_unit_id',
        'assigned_team_leader_id',
        'created_by_admin_id',
        'age',

        'pickup_address',
        'pickup_lat',
        'pickup_lng',

        'dropoff_address',
        'dropoff_lat',
        'dropoff_lng',

        'distance_km',
        'base_rate',
        'per_km_rate',
        'computed_total',
        'discount_percentage',
        'discount_reason',
        'additional_fee',
        'final_total',
        'customer_type',
        'confirmation_type',
        'vehicle_image_path',
        'notes',
        'remarks',
        'quotation_number',
        'initial_quote_path',
        'final_quote_path',
        'quotation_generated',
        'dispatcher_note',
        'driver_name',
        'assigned_at',
        'completed_at',
        'rejection_reason',
        'reviewed_at',
        'quoted_at',
        'quotation_sent_at',
        'negotiation_requested_at',
        'counter_offer_amount',
        'customer_approved_at',
        'price_locked_at',
        'customer_response_note',
        'completion_requested_at',
        'customer_verified_at',
        'customer_verification_status',
        'customer_verification_note',

        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (blank($booking->booking_code)) {
                $booking->booking_code = static::nextPublicCode('booking_code');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quotation_generated' => 'boolean',
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'quoted_at' => 'datetime',
            'quotation_sent_at' => 'datetime',
            'negotiation_requested_at' => 'datetime',
            'customer_approved_at' => 'datetime',
            'price_locked_at' => 'datetime',
            'counter_offer_amount' => 'decimal:2',
            'completion_requested_at' => 'datetime',
            'customer_verified_at' => 'datetime',
            'base_rate' => 'decimal:2',
            'per_km_rate' => 'decimal:2',
            'computed_total' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'additional_fee' => 'decimal:2',
            'final_total' => 'decimal:2',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'booking_code';
    }

    public function getJobCodeAttribute(): string
    {
        return $this->booking_code ?: str_pad((string) $this->getKey(), 7, '0', STR_PAD_LEFT);
    }

    public function getDistanceFeeAmountAttribute(): float
    {
        $baseRate = (float) ($this->base_rate ?? 0);
        $computedTotal = (float) ($this->computed_total ?? ($baseRate + ((float) ($this->distance_km ?? 0) * (float) ($this->per_km_rate ?? 0))));

        return max(round($computedTotal - $baseRate, 2), 0);
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
            'additional_fee' => (float) ($this->additional_fee ?? 0),
            'discount' => $this->discount_amount,
            'final_total' => (float) ($this->final_total ?? 0),
        ];
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

    public function receipt()
    {
        return $this->hasOne(Receipt::class);
    }
}
