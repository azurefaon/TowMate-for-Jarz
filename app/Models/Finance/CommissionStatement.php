<?php

namespace App\Models\Finance;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One week (Monday–Saturday) of commission. Approval freezes it, including a sha256 of its content. */
class CommissionStatement extends Model
{
    use HasFinanceReference;

    public const REFERENCE_PREFIX = 'COMM';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_VOIDED = 'voided';

    protected $table = 'finance_commission_statements';

    protected $attributes = ['status' => self::STATUS_DRAFT, 'revision' => 1];

    protected $fillable = [
        'period_start', 'period_end', 'policy_id', 'revision', 'supersedes_statement_id', 'total_base',
        'company_amount', 'personnel_amount', 'line_count', 'prepared_by', 'prepared_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_base' => MoneyCast::class,
        'company_amount' => MoneyCast::class,
        'personnel_amount' => MoneyCast::class,
        'line_count' => 'integer',
        'revision' => 'integer',
        'prepared_at' => 'datetime',
        'approved_at' => 'datetime',
        'voided_at' => 'datetime',
        'approval_snapshot' => 'array',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CommissionPolicy::class, 'policy_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommissionLine::class, 'statement_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'statement_id');
    }
}
