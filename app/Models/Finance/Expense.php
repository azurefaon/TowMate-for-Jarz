<?php

namespace App\Models\Finance;

use App\Casts\MoneyCast;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Team Leader submits -> Dispatcher verifies -> Owner approves. Approved expenses can only be voided. */
class Expense extends Model
{
    use HasFinanceReference;

    public const REFERENCE_PREFIX = 'EXP';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_VOIDED = 'voided';

    protected $table = 'finance_expenses';

    protected $attributes = ['status' => self::STATUS_SUBMITTED];

    protected $fillable = [
        'idempotency_key', 'unit_id', 'booking_id', 'fund_entry_id', 'category', 'description', 'amount',
        'expense_date', 'funding_source', 'submitted_by', 'submitted_at',
    ];

    protected $casts = [
        'amount' => MoneyCast::class,
        'expense_date' => 'date',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function fundEntry(): BelongsTo
    {
        return $this->belongsTo(FundEntry::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ExpenseEvidence::class);
    }
}
