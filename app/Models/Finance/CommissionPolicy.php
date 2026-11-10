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
        'crew_mate_rate', 'interpretation_status', 'base_definition', 'allocation_rule', 'confirmed_rules', 'notes', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'activated_at' => 'datetime',
        'retired_at' => 'datetime',
        'confirmed_rules' => 'array',
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

    /**
     * Role interpretations the client must answer explicitly (C2, C4 and substitutes). Until a confirmed, active policy
     * records the accepted answer, any job that needs the rule stays blocked. rule => [accepted value => meaning].
     */
    public const CONFIRMABLE_RULES = [
        'rider_role' => ['confirmed' => 'The Rider / Substitute share goes to the person recorded in that role'],
        'substitutes' => ['paid_in_recorded_role' => 'A substitute or borrowed person is paid for the role they performed'],
        'shared_role' => ['split_equally' => 'People sharing one role split that role\'s share equally'],
        'combined_roles' => ['paid_per_role' => 'A person performing two roles on one job receives both shares'],
    ];

    /** True only when this policy is active, client-confirmed, and records the accepted answer for $rule. */
    public function confirmsRule(string $rule): bool
    {
        $accepted = array_keys(self::CONFIRMABLE_RULES[$rule] ?? []);

        return $this->status === self::STATUS_ACTIVE
            && $this->isConfirmed()
            && in_array(($this->confirmed_rules ?? [])[$rule] ?? null, $accepted, true);
    }
}
