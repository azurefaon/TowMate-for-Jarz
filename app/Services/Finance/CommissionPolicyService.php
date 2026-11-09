<?php

namespace App\Services\Finance;

use App\Models\Finance\CommissionPolicy;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only: versioned commission rates. A policy can only become active after its interpretation
 * (base amount and allocation rule) has been confirmed. The initial client rates are seeded as an
 * unconfirmed draft for exactly this reason.
 */
class CommissionPolicyService extends FinanceSupport
{
    /** @param array{company:string, driver:string, team_leader:string, rider:string, crew_mate:string} $rates */
    public function createDraft(User $actor, string $name, array $rates, ?string $notes = null): CommissionPolicy
    {
        $this->authorize($actor, FinanceAccess::MANAGE_POLICIES);
        $this->assertRates($rates);

        return DB::transaction(function () use ($actor, $name, $rates, $notes) {
            DB::table('finance_commission_policies')->lockForUpdate()->get(['id']); // serialise version numbers
            $version = (int) CommissionPolicy::max('version') + 1;

            $attributes = ['version' => $version, 'name' => $name, 'notes' => $notes, 'created_by' => $actor->id];
            foreach (CommissionPolicy::RATE_COLUMNS as $role => $column) {
                $attributes[$column] = $rates[$role];
            }

            $policy = CommissionPolicy::create($attributes);
            $this->audit($actor, 'finance_policy_drafted', $policy, "Policy v{$version}", "Drafted commission policy v{$version}", $rates);

            return $policy;
        });
    }

    /** Records the client's confirmed interpretation on a draft policy. */
    public function confirmInterpretation(User $actor, CommissionPolicy $policy, string $baseDefinition, string $allocationRule): CommissionPolicy
    {
        $this->authorize($actor, FinanceAccess::MANAGE_POLICIES);

        return DB::transaction(function () use ($actor, $policy, $baseDefinition, $allocationRule) {
            $locked = CommissionPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CommissionPolicy::STATUS_DRAFT) {
                throw new FinanceException('Only a draft policy can be confirmed.');
            }
            if (trim($baseDefinition) === '' || trim($allocationRule) === '') {
                throw new FinanceException('Both the commission base and the allocation rule are required.');
            }

            $locked->forceFill([
                'interpretation_status' => CommissionPolicy::INTERPRETATION_CONFIRMED,
                'base_definition' => $baseDefinition,
                'allocation_rule' => $allocationRule,
            ])->save();

            $this->audit($actor, 'finance_policy_confirmed', $locked, "Policy v{$locked->version}",
                "Confirmed commission interpretation for policy v{$locked->version}",
                ['base_definition' => $baseDefinition, 'allocation_rule' => $allocationRule]);

            return $locked;
        });
    }

    /** Activates a confirmed draft and retires the previously active version in the same transaction. */
    public function activate(User $actor, CommissionPolicy $policy, string $effectiveFrom): CommissionPolicy
    {
        $this->authorize($actor, FinanceAccess::MANAGE_POLICIES);

        return DB::transaction(function () use ($actor, $policy, $effectiveFrom) {
            $locked = CommissionPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CommissionPolicy::STATUS_DRAFT) {
                throw new FinanceException('Only a draft policy can be activated.');
            }
            if (! $locked->isConfirmed()) {
                throw new FinanceException('This policy cannot be activated until the commission interpretation is confirmed by the client.');
            }

            CommissionPolicy::where('status', CommissionPolicy::STATUS_ACTIVE)->lockForUpdate()->get()
                ->each(fn (CommissionPolicy $old) => $old->forceFill([
                    'status' => CommissionPolicy::STATUS_RETIRED,
                    'retired_by' => $actor->id,
                    'retired_at' => now(),
                ])->save());

            $locked->forceFill([
                'status' => CommissionPolicy::STATUS_ACTIVE,
                'effective_from' => $effectiveFrom,
                'activated_by' => $actor->id,
                'activated_at' => now(),
            ])->save();

            $this->audit($actor, 'finance_policy_activated', $locked, "Policy v{$locked->version}", "Activated commission policy v{$locked->version}");

            return $locked;
        });
    }

    private function assertRates(array $rates): void
    {
        $total = '0';
        foreach (array_keys(CommissionPolicy::RATE_COLUMNS) as $role) {
            $rate = (string) ($rates[$role] ?? '');
            if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $rate)) {
                throw new FinanceException("A valid {$role} rate is required.");
            }
            $total = bcadd($total, $rate, 2);
        }
        if (bccomp($total, '100', 2) !== 0) {
            throw new FinanceException("Commission rates must total exactly 100%, got {$total}%.");
        }
    }
}
