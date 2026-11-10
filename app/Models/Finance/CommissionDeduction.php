<?php

namespace App\Models\Finance;

use App\Casts\MoneyCast;
use App\Models\Booking;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A non-expense reduction of a job's sharing base (provider fee, refund, damage). Only APPROVED deductions count. */
class CommissionDeduction extends Model
{
    use HasFinanceReference;

    public const REFERENCE_PREFIX = 'DED';
    public const STATUS_PENDING = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_VOIDED = 'voided';

    public const TYPES = [
        'payment_fee' => 'Payment provider fee',
        'refund' => 'Refund to customer',
        'damage' => 'Damage charge',
        'other' => 'Other deduction',
    ];

    protected $table = 'finance_commission_deductions';

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected $fillable = ['idempotency_key', 'booking_id', 'unit_id', 'deduction_type', 'amount', 'description', 'external_reference', 'recorded_by'];

    protected $casts = [
        'amount' => MoneyCast::class,
        'approved_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->deduction_type] ?? $this->deduction_type;
    }
}
