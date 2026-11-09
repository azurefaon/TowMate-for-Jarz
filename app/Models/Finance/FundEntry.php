<?php

namespace App\Models\Finance;

use App\Casts\MoneyCast;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash issued by the Owner to a unit, or returned by the unit. Permanent: void, never edit. */
class FundEntry extends Model
{
    use HasFinanceReference;

    public const REFERENCE_PREFIX = 'FUND';
    public const TYPE_ISSUANCE = 'issuance';
    public const TYPE_RETURN = 'return';
    public const STATUS_RECORDED = 'recorded';
    public const STATUS_VOIDED = 'voided';

    protected $table = 'finance_fund_entries';

    protected $attributes = ['status' => self::STATUS_RECORDED];

    protected $fillable = [
        'idempotency_key', 'entry_type', 'unit_id', 'team_leader_user_id', 'counterparty_name', 'amount',
        'entry_date', 'issuance_id', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'amount' => MoneyCast::class,
        'entry_date' => 'date',
        'voided_at' => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(self::class, 'issuance_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeRecorded($query)
    {
        return $query->where('status', self::STATUS_RECORDED);
    }
}
