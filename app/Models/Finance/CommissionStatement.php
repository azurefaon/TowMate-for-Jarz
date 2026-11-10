<?php

namespace App\Models\Finance;

use App\Casts\MoneyCast;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One towing unit's commission for one Monday–Saturday week: Draft → For Review → Approved (→ Paid in Phase 7).
 * Approval freezes it, including a sha256 of its content. A correction voids it and starts a new revision.
 */
class CommissionStatement extends Model
{
    use HasFinanceReference;

    public const REFERENCE_PREFIX = 'COMM';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FOR_REVIEW = 'for_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_VOIDED = 'voided';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_FOR_REVIEW => 'For Review',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_VOIDED => 'Voided',
    ];

    public const OPEN_STATUSES = [self::STATUS_DRAFT, self::STATUS_FOR_REVIEW];

    protected $table = 'finance_commission_statements';

    protected $attributes = ['status' => self::STATUS_DRAFT, 'revision' => 1];

    protected $fillable = [
        'period_start', 'period_end', 'unit_id', 'policy_id', 'revision', 'supersedes_statement_id', 'total_base',
        'total_collection', 'total_expenses', 'total_deductions', 'company_amount', 'personnel_amount',
        'unallocated_amount', 'line_count', 'job_count', 'included_jobs', 'calculation', 'calculation_hash',
        'correction_reason', 'prepared_by', 'prepared_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_base' => MoneyCast::class,
        'total_collection' => MoneyCast::class,
        'total_expenses' => MoneyCast::class,
        'total_deductions' => MoneyCast::class,
        'company_amount' => MoneyCast::class,
        'personnel_amount' => MoneyCast::class,
        'unallocated_amount' => MoneyCast::class,
        'line_count' => 'integer',
        'job_count' => 'integer',
        'revision' => 'integer',
        'included_jobs' => 'array',
        'calculation' => 'array',
        'prepared_at' => 'datetime',
        'submitted_at' => 'datetime',
        'returned_at' => 'datetime',
        'approved_at' => 'datetime',
        'voided_at' => 'datetime',
        'approval_snapshot' => 'array',
    ];

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CommissionPolicy::class, 'policy_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_statement_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommissionLine::class, 'statement_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(CommissionSettlement::class, 'statement_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'statement_id');
    }
}
