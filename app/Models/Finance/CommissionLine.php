<?php

namespace App\Models\Finance;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One share of one job's commission base. Writable only while the statement is a draft. */
class CommissionLine extends Model
{
    public const ROLES = ['company', 'driver', 'team_leader', 'rider', 'crew_mate'];

    protected $table = 'finance_commission_lines';

    protected $fillable = [
        'statement_id', 'booking_id', 'revenue_allocation_id', 'job_personnel_id', 'commission_role',
        'recipient_personnel_id', 'recipient_user_id', 'recipient_key', 'recipient_name', 'base_amount', 'rate',
        'amount', 'rounding_adjustment',
    ];

    protected $casts = [
        'base_amount' => MoneyCast::class,
        'amount' => MoneyCast::class,
        'rounding_adjustment' => MoneyCast::class,
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CommissionStatement::class, 'statement_id');
    }
}
