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
        'recipient_name', 'recipient_roles', 'amount', 'payout_method', 'external_reference', 'paid_at', 'recorded_by',
        'recorded_by_name', 'evidence_path', 'evidence_original_name', 'evidence_mime', 'evidence_sha256', 'notes', 'corrects_payout_id',
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

    protected $hidden = ['evidence_path'];

    public function methodLabel(): string
    {
        return config('finance.payout_methods')[$this->payout_method] ?? $this->payout_method;
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_payout_id');
    }

    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class);
    }
}
