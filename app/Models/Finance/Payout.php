<?php

namespace App\Models\Finance;

use App\Casts\MoneyCast;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money actually paid to a crew member for an approved statement. Separate from what was earned. */
class Payout extends Model
{
    use HasFinanceReference;

    public const REFERENCE_PREFIX = 'PAY';
    public const STATUS_RECORDED = 'recorded';
    public const STATUS_VOIDED = 'voided';

    protected $table = 'finance_payouts';

    protected $attributes = ['status' => self::STATUS_RECORDED];

    protected $fillable = [
        'idempotency_key', 'statement_id', 'recipient_key', 'recipient_personnel_id', 'recipient_user_id',
        'recipient_name', 'amount', 'payout_method', 'external_reference', 'paid_at', 'recorded_by',
    ];

    protected $casts = [
        'amount' => MoneyCast::class,
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CommissionStatement::class, 'statement_id');
    }

    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class);
    }
}
