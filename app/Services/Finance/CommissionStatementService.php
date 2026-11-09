<?php

namespace App\Services\Finance;

use App\Models\Finance\CommissionLine;
use App\Models\Finance\CommissionPolicy;
use App\Models\Finance\CommissionStatement;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only: weekly (Monday–Saturday) commission statements.
 *
 * Phase 1 covers the lifecycle and approval integrity only. Computing the lines waits for the client's
 * commission interpretation (Phase 5). Approval re-checks every total exactly and freezes a sha256 of
 * the full statement, so any later tampering is detectable even outside the database triggers.
 */
class CommissionStatementService extends FinanceSupport
{
    public function prepareDraft(User $actor, string $weekStart, CommissionPolicy $policy): CommissionStatement
    {
        $this->authorize($actor, FinanceAccess::PREPARE_STATEMENTS);

        $start = CarbonImmutable::parse($weekStart, config('finance.timezone'))->startOfDay();
        if ($start->dayOfWeekIso !== (int) config('finance.week_start_iso_day')) {
            throw new FinanceException('A commission week must start on a Monday.');
        }

        return DB::transaction(function () use ($actor, $start, $policy) {
            $revision = (int) CommissionStatement::where('period_start', $start->toDateString())->max('revision') + 1;

            $statement = CommissionStatement::create([
                'period_start' => $start->toDateString(),
                'period_end' => $start->addDays((int) config('finance.week_length_days') - 1)->toDateString(),
                'policy_id' => $policy->id,
                'revision' => $revision,
                'prepared_by' => $actor->id,
                'prepared_at' => now(),
            ]);

            $this->audit($actor, 'finance_statement_prepared', $statement, $statement->reference_code,
                "Prepared commission statement for {$statement->period_start->toDateString()} to {$statement->period_end->toDateString()}");

            return $statement;
        });
    }

    public function approve(User $actor, CommissionStatement $statement): CommissionStatement
    {
        $this->authorize($actor, FinanceAccess::APPROVE_STATEMENTS);

        return DB::transaction(function () use ($actor, $statement) {
            $locked = CommissionStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CommissionStatement::STATUS_DRAFT) {
                throw new FinanceException("This statement is {$locked->status} and cannot be approved again.");
            }

            $policy = CommissionPolicy::findOrFail($locked->policy_id);
            if ($policy->status !== CommissionPolicy::STATUS_ACTIVE || ! $policy->isConfirmed()) {
                throw new FinanceException('Commission statements can only be approved under an active, client-confirmed commission policy.');
            }

            $lines = CommissionLine::where('statement_id', $locked->id)->orderBy('id')->get();
            if ($lines->isEmpty()) {
                throw new FinanceException('This statement has no commission lines.');
            }

            $this->assertExactTotals($locked, $lines);

            $snapshot = $this->snapshot($locked, $lines, $policy);
            $locked->forceFill([
                'status' => CommissionStatement::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_by_name' => $this->actorName($actor),
                'approved_at' => now(),
                'approval_snapshot' => $snapshot,
                'approval_hash' => self::hash($snapshot),
            ])->save();

            $this->audit($actor, 'finance_statement_approved', $locked, $locked->reference_code,
                'Approved commission statement totalling ' . Money::of($locked->total_base)->format(),
                ['approval_hash' => $locked->approval_hash]);

            return $locked;
        });
    }

    public function void(User $actor, CommissionStatement $statement, string $reason): CommissionStatement
    {
        $this->authorize($actor, FinanceAccess::APPROVE_STATEMENTS);
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceException('A reason is required to void a statement.');
        }

        return DB::transaction(function () use ($actor, $statement, $reason) {
            $locked = CommissionStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CommissionStatement::STATUS_APPROVED) {
                throw new FinanceException('Only an approved statement can be voided. Delete a draft instead.');
            }
            if ($locked->payouts()->where('status', 'recorded')->exists()) {
                throw new FinanceException('Void the recorded payouts before voiding this statement.');
            }

            $locked->forceFill([
                'status' => CommissionStatement::STATUS_VOIDED,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            $this->audit($actor, 'finance_statement_voided', $locked, $locked->reference_code, "Voided commission statement ({$reason})");

            return $locked;
        });
    }

    /** True while the stored lines and totals still match what was approved. */
    public function verifyIntegrity(CommissionStatement $statement): bool
    {
        if ($statement->approval_hash === null) {
            return false;
        }
        $lines = CommissionLine::where('statement_id', $statement->id)->orderBy('id')->get();
        $policy = CommissionPolicy::findOrFail($statement->policy_id);

        return hash_equals($statement->approval_hash, self::hash($this->snapshot($statement, $lines, $policy)));
    }

    public static function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function assertExactTotals(CommissionStatement $statement, $lines): void
    {
        $company = Money::sum($lines->where('commission_role', 'company')->pluck('amount'));
        $personnel = Money::sum($lines->where('commission_role', '!=', 'company')->pluck('amount'));
        $total = $company->plus($personnel);

        // Every job's lines must add up exactly to that job's base: no centavo created or lost.
        foreach ($lines->groupBy('revenue_allocation_id') as $allocationLines) {
            $bases = $allocationLines->pluck('base_amount')->unique();
            if ($bases->count() !== 1 || ! Money::sum($allocationLines->pluck('amount'))->equals($bases->first())) {
                throw new FinanceException('Commission lines for a job do not add up exactly to its base amount.');
            }
        }

        $expectedBase = Money::sum($lines->groupBy('revenue_allocation_id')->map(fn ($group) => $group->first()->base_amount));

        if (! $total->equals($statement->total_base) || ! $total->equals($expectedBase)
            || ! $company->equals($statement->company_amount) || ! $personnel->equals($statement->personnel_amount)
            || $lines->count() !== (int) $statement->line_count) {
            throw new FinanceException('Statement totals do not match its commission lines exactly.');
        }
    }

    private function snapshot(CommissionStatement $statement, $lines, CommissionPolicy $policy): array
    {
        return [
            'statement' => [
                'id' => $statement->id,
                'reference_code' => $statement->reference_code,
                'period_start' => $statement->period_start->toDateString(),
                'period_end' => $statement->period_end->toDateString(),
                'revision' => (int) $statement->revision,
                'total_base' => Money::of($statement->total_base)->toString(),
                'company_amount' => Money::of($statement->company_amount)->toString(),
                'personnel_amount' => Money::of($statement->personnel_amount)->toString(),
                'line_count' => (int) $statement->line_count,
            ],
            'policy' => ['id' => $policy->id, 'version' => (int) $policy->version, 'rates' => $policy->rates(),
                'base_definition' => $policy->base_definition, 'allocation_rule' => $policy->allocation_rule],
            'lines' => $lines->map(fn (CommissionLine $line) => [
                'id' => $line->id,
                'booking_id' => $line->booking_id,
                'revenue_allocation_id' => $line->revenue_allocation_id,
                'job_personnel_id' => $line->job_personnel_id,
                'commission_role' => $line->commission_role,
                'recipient_key' => $line->recipient_key,
                'recipient_name' => $line->recipient_name,
                'base_amount' => Money::of($line->base_amount)->toString(),
                'rate' => (string) $line->rate,
                'amount' => Money::of($line->amount)->toString(),
                'rounding_adjustment' => Money::of($line->rounding_adjustment)->toString(),
            ])->values()->all(),
        ];
    }
}
