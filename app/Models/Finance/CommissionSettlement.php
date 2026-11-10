<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks one booking × unit's earnings as settled by an approved statement. At most one ACTIVE settlement exists per
 * booking × unit (database unique index), so the same earnings can never be approved twice. Voiding the statement
 * releases it; the row itself is permanent.
 */
class CommissionSettlement extends Model
{
    protected $table = 'finance_commission_settlements';

    protected $fillable = ['statement_id', 'booking_id', 'unit_id', 'job_key', 'settled_at'];

    protected $casts = [
        'settled_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CommissionStatement::class, 'statement_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('released_at');
    }
}
