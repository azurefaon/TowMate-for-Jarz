<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;

/** A versioned set of commission rates. Frozen once it leaves draft. */
class CommissionPolicy extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RETIRED = 'retired';
    public const INTERPRETATION_PENDING = 'pending_client_confirmation';
    public const INTERPRETATION_CONFIRMED = 'confirmed';

    /** commission role => rate column */
    public const RATE_COLUMNS = [
        'company' => 'company_rate',
        'driver' => 'driver_rate',
        'team_leader' => 'team_leader_rate',
        'rider' => 'rider_rate',
        'crew_mate' => 'crew_mate_rate',
    ];

    protected $table = 'finance_commission_policies';

    protected $attributes = ['status' => self::STATUS_DRAFT, 'interpretation_status' => self::INTERPRETATION_PENDING];

    protected $fillable = [
        'version', 'name', 'effective_from', 'company_rate', 'driver_rate', 'team_leader_rate', 'rider_rate',
        'crew_mate_rate', 'interpretation_status', 'base_definition', 'allocation_rule', 'notes', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'activated_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    /** @return array<string, string> commission role => percent, as exact decimal strings */
    public function rates(): array
    {
        return array_map(fn (string $column) => (string) $this->getAttribute($column), self::RATE_COLUMNS);
    }

    public function isConfirmed(): bool
    {
        return $this->interpretation_status === self::INTERPRETATION_CONFIRMED;
    }
}
