<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\Finance\CommissionLine;
use App\Models\Finance\CommissionPolicy;
use App\Models\Finance\CommissionSettlement;
use App\Models\Finance\CommissionStatement;
use App\Models\Unit;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only: weekly commission statements, one per towing unit and Monday–Saturday week.
 *
 *   Draft ──submit──▶ For Review ──approve──▶ Approved ──void / correct──▶ Voided (+ new revision)
 *     ▲                   │
 *     └──return to draft──┘                         (Paid is recorded in Phase 7)
 *
 * CommissionCalculator is the only calculation. A statement stores the calculator's output for the jobs the Owner
 * selected (`calculation`) and its hash. Approval recalculates: it succeeds only when the result is identical to what
 * was reviewed AND the calculator reports zero blockers, which includes an active, client-confirmed policy. Pending
 * client decisions therefore can never be approved by accident.
 *
 * On approval the statement freezes (database triggers) with a hashed snapshot of jobs, units, personnel, policy,
 * source expenses and deductions, amounts, approver and audit references. Settlement rows (one active row per
 * booking × unit, unique index) stop the same earnings from being approved twice. A correction voids the approved
 * statement, releases its settlements and opens a new revision; the original stays readable.
 */
class CommissionStatementService extends FinanceSupport
{
    public function __construct(private readonly CommissionCalculator $calculator)
    {
    }

    // ------------------------------------------------------------------ read side

    /**
     * Every job in the week for the unit, with the statement that already settled it (if any).
     *
     * @return array{draft:array, jobs:array<int,array{key:string,reference:string,service_date:?CarbonImmutable,has_line:bool,settled_by:?string,blockers:array,base:?Money}>}
     */
    public function candidates(CarbonImmutable $weekStart, int $unitId): array
    {
        $draft = $this->calculator->draft($weekStart, $unitId);
        $settled = $this->settledKeys($draft, $unitId);

        $jobs = [];
        foreach ($draft['lines'] as $line) {
            $jobs[$line['job_key']] = ['key' => $line['job_key'], 'reference' => $line['reference'], 'service_date' => $line['service_date'],
                'has_line' => true, 'settled_by' => $settled[$line['job_key']] ?? null, 'blockers' => $line['blockers'], 'base' => $line['base']];
        }
        foreach ($draft['blocked_jobs'] as $bj) {
            $jobs[$bj['job']['key']] ??= ['key' => $bj['job']['key'], 'reference' => $bj['job']['reference'], 'service_date' => $bj['job']['service_date'],
                'has_line' => false, 'settled_by' => $settled[$bj['job']['key']] ?? null, 'blockers' => $bj['blockers'], 'base' => null];
        }

        return ['draft' => $draft, 'jobs' => array_values($jobs)];
    }

    /**
     * Live review of an open statement: recalculates its selected jobs and reports whether the stored (reviewed)
     * calculation is still current, what blocks approval, and whether any job was settled elsewhere meanwhile.
     */
    public function review(CommissionStatement $statement): array
    {
        $draft = $this->calculator->draft(CarbonImmutable::parse($statement->period_start->toDateString(), config('finance.timezone')),
            $statement->unit_id, null, $statement->included_jobs ?? []);
        $content = $this->content($draft);
        $blockers = $draft['blockers'];

        $found = collect($draft['lines'])->pluck('job_key')->merge(collect($draft['blocked_jobs'])->pluck('job.key'))->unique()->all();
        foreach (array_diff($statement->included_jobs ?? [], $found) as $missing) {
            $blockers[] = ['scope' => 'job', 'code' => 'job_no_longer_in_period', 'message' => "A selected job ({$missing}) is no longer a completed job of this week and unit. Recalculate the draft."];
        }
        foreach ($this->settledKeys($draft, (int) $statement->unit_id, $statement->id) as $key => $ref) {
            $blockers[] = ['scope' => 'job', 'code' => 'already_settled', 'message' => "A selected job ({$key}) is already settled by approved statement {$ref}."];
        }

        $stale = ! hash_equals((string) $statement->calculation_hash, self::hash($content));
        if ($stale) {
            $blockers[] = ['scope' => 'statement', 'code' => 'calculation_changed', 'message' => 'Source records changed after this statement was calculated (payments, expenses, deductions, crew or policy). Return it to draft and recalculate before approval.'];
        }

        return ['draft' => $draft, 'blockers' => $blockers, 'stale' => $stale, 'can_approve' => $blockers === [] && $draft['lines'] !== []];
    }

    // ------------------------------------------------------------------ workflow

    /**
     * Creates the draft statement for a unit and week, or recalculates the existing draft. $jobKeys selects jobs;
     * null selects every job of the week not already settled.
     */
    public function generate(User $actor, string $weekStart, int $unitId, ?array $jobKeys = null, ?CommissionStatement $supersedes = null, ?string $correctionReason = null): CommissionStatement
    {
        $this->authorize($actor, FinanceAccess::PREPARE_STATEMENTS);

        $start = CarbonImmutable::parse($weekStart, config('finance.timezone'))->startOfDay();
        if ($start->dayOfWeekIso !== (int) config('finance.week_start_iso_day')) {
            throw new FinanceException('A commission week must start on a Monday.');
        }
        if (! Unit::whereKey($unitId)->exists()) {
            throw new FinanceException('Choose a valid towing unit.');
        }

        return $this->transaction(function () use ($actor, $start, $unitId, $jobKeys, $supersedes, $correctionReason) {
            $this->lockWeekUnit($start, $unitId);

            $open = CommissionStatement::where('period_start', $start->toDateString())->where('unit_id', $unitId)
                ->whereIn('status', CommissionStatement::OPEN_STATUSES)->lockForUpdate()->first();
            if ($open && $open->status === CommissionStatement::STATUS_FOR_REVIEW) {
                throw new FinanceException("Statement {$open->reference_code} is For Review. Return it to draft before recalculating.");
            }
            if ($open && $supersedes) {
                throw new FinanceException("Statement {$open->reference_code} is still open for this week and unit. Finish or recalculate it first.");
            }

            ['jobs' => $candidates] = $this->candidates($start, $unitId);
            $available = collect($candidates)->whereNull('settled_by')->pluck('key')->all();
            if ($jobKeys === null) {
                $selected = $available;
            } else {
                $selected = array_values(array_unique(array_map('strval', $jobKeys)));
                foreach ($selected as $key) {
                    $candidate = collect($candidates)->firstWhere('key', $key);
                    if (! $candidate) {
                        throw new FinanceException('A selected job is not a completed job of this unit in this week.');
                    }
                    if ($candidate['settled_by']) {
                        throw new FinanceException("Job {$candidate['reference']} is already settled by approved statement {$candidate['settled_by']}.");
                    }
                }
            }
            if ($selected === []) {
                throw new FinanceException('There are no unsettled jobs for this unit in this week.');
            }
            sort($selected);

            $draft = $this->calculator->draft($start, $unitId, null, $selected);
            if (! $draft['policy']) {
                throw new FinanceException('No commission policy exists.');
            }

            $content = $this->content($draft);
            $totals = $this->totals($content['lines']);
            $attributes = $totals + [
                'policy_id' => $draft['policy']['id'],
                'job_count' => count($selected),
                'included_jobs' => $selected,
                'calculation' => ['content' => $content, 'blockers' => $draft['blockers'], 'notices' => $draft['notices'],
                    'blocked_jobs' => collect($draft['blocked_jobs'])->map(fn ($bj) => ['job_key' => $bj['job']['key'], 'reference' => $bj['job']['reference'], 'blockers' => $bj['blockers']])->all(),
                    'calculated_at' => now()->toDateTimeString()],
                'calculation_hash' => self::hash($content),
                'prepared_by' => $actor->id,
                'prepared_at' => now(),
            ];

            if ($open) {
                CommissionLine::where('statement_id', $open->id)->delete();
                $open->forceFill($attributes)->save();
                $statement = $open;
                $action = 'finance_statement_recalculated';
                $verb = 'Recalculated';
            } else {
                $revision = (int) CommissionStatement::where('period_start', $start->toDateString())->where('unit_id', $unitId)->max('revision') + 1;
                $statement = new CommissionStatement();
                $statement->forceFill($attributes + [
                    'period_start' => $start->toDateString(),
                    'period_end' => $start->addDays((int) config('finance.week_length_days') - 1)->toDateString(),
                    'unit_id' => $unitId,
                    'revision' => $revision,
                    'supersedes_statement_id' => $supersedes?->id,
                    'correction_reason' => $correctionReason,
                ])->save();
                $action = 'finance_statement_prepared';
                $verb = 'Prepared';
            }

            $this->writeLines($statement, $content['lines']);

            $this->audit($actor, $action, $statement, $statement->reference_code,
                "{$verb} commission statement for " . $this->periodLabel($statement) . ' (' . count($selected) . ' job(s), base ' . Money::of($statement->total_base)->format() . ')',
                ['calculation_hash' => $statement->calculation_hash, 'included_jobs' => $selected]);

            return $statement->fresh();
        });
    }

    /** Recalculates an existing draft with its current selection (or a new one). */
    public function recalculate(User $actor, CommissionStatement $statement, ?array $jobKeys = null): CommissionStatement
    {
        if ($statement->status !== CommissionStatement::STATUS_DRAFT) {
            throw new FinanceException('Only a draft statement can be recalculated.');
        }

        return $this->generate($actor, $statement->period_start->toDateString(), (int) $statement->unit_id, $jobKeys ?? $statement->included_jobs);
    }

    /** Draft → For Review. Allowed with open issues (they are what the review is for); approval is not. */
    public function submitForReview(User $actor, CommissionStatement $statement): CommissionStatement
    {
        $this->authorize($actor, FinanceAccess::PREPARE_STATEMENTS);

        return $this->transaction(function () use ($actor, $statement) {
            $locked = CommissionStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CommissionStatement::STATUS_DRAFT) {
                throw new FinanceException("This statement is {$locked->statusLabel()} and cannot be submitted for review.");
            }
            $review = $this->review($locked);
            if ($review['stale']) {
                throw new FinanceException('Source records changed after this draft was calculated. Recalculate it, then submit for review.');
            }

            $locked->forceFill(['status' => CommissionStatement::STATUS_FOR_REVIEW, 'submitted_by' => $actor->id, 'submitted_at' => now()])->save();

            $issues = count($review['blockers']);
            $this->audit($actor, 'finance_statement_submitted', $locked, $locked->reference_code,
                "Submitted commission statement for review ({$this->periodLabel($locked)}); {$issues} outstanding issue(s)",
                ['calculation_hash' => $locked->calculation_hash, 'blocker_codes' => collect($review['blockers'])->pluck('code')->unique()->values()->all()]);

            return $locked;
        });
    }

    /** For Review → Draft, with a reason (for example to recalculate after crew or expenses change). */
    public function returnToDraft(User $actor, CommissionStatement $statement, string $reason): CommissionStatement
    {
        $this->authorize($actor, FinanceAccess::PREPARE_STATEMENTS);
        $reason = $this->reason($reason, 'return a statement to draft');

        return $this->transaction(function () use ($actor, $statement, $reason) {
            $locked = CommissionStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CommissionStatement::STATUS_FOR_REVIEW) {
                throw new FinanceException('Only a statement that is For Review can be returned to draft.');
            }

            $locked->forceFill(['status' => CommissionStatement::STATUS_DRAFT, 'returned_by' => $actor->id, 'returned_at' => now(), 'return_reason' => $reason])->save();
            $this->audit($actor, 'finance_statement_returned', $locked, $locked->reference_code, "Returned commission statement to draft ({$reason})");

            return $locked;
        });
    }

    /** For Review → Approved, only with zero blockers and an unchanged calculation. Freezes the statement. */
    public function approve(User $actor, CommissionStatement $statement): CommissionStatement
    {
        $this->authorize($actor, FinanceAccess::APPROVE_STATEMENTS);

        return $this->transaction(function () use ($actor, $statement) {
            $locked = CommissionStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === CommissionStatement::STATUS_APPROVED) {
                throw new FinanceException('This statement is already approved.');
            }
            if ($locked->status !== CommissionStatement::STATUS_FOR_REVIEW) {
                throw new FinanceException("This statement is {$locked->statusLabel()}. Submit it for review before approval.");
            }
            $this->lockWeekUnit(CarbonImmutable::parse($locked->period_start->toDateString()), (int) $locked->unit_id);

            $policy = CommissionPolicy::findOrFail($locked->policy_id);
            if ($policy->status !== CommissionPolicy::STATUS_ACTIVE || ! $policy->isConfirmed()) {
                throw new FinanceException('Commission statements can only be approved under an active, client-confirmed commission policy.');
            }

            $review = $this->review($locked);
            if (! $review['can_approve']) {
                $first = $review['blockers'][0]['message'] ?? 'The statement has no commission lines.';
                throw new FinanceException('This statement cannot be approved yet: ' . $first . (count($review['blockers']) > 1 ? ' (' . (count($review['blockers']) - 1) . ' more issue(s))' : ''));
            }

            $lines = CommissionLine::where('statement_id', $locked->id)->orderBy('id')->get();
            if ($lines->isEmpty()) {
                throw new FinanceException('This statement has no commission lines.');
            }
            $this->assertExactTotals($locked, $lines);

            $approvedAt = now()->startOfSecond();
            $snapshot = $this->snapshot($locked, $lines, $policy) + [
                'approval' => ['approved_by' => $actor->id, 'approved_by_name' => $this->actorName($actor), 'approved_at' => $approvedAt->format('Y-m-d H:i:s')],
                'audit' => $this->auditReferences($locked),
            ];

            $locked->forceFill([
                'status' => CommissionStatement::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_by_name' => $this->actorName($actor),
                'approved_at' => $approvedAt,
                'approval_snapshot' => $snapshot,
                'approval_hash' => self::hash($snapshot),
            ])->save();

            foreach ($locked->calculation['content']['lines'] as $line) {
                foreach ($line['member_booking_ids'] as $bookingId) {
                    CommissionSettlement::create([
                        'statement_id' => $locked->id, 'booking_id' => $bookingId, 'unit_id' => $line['unit_id'],
                        'job_key' => $line['job_key'], 'settled_at' => $approvedAt,
                    ]);
                }
            }

            $this->audit($actor, 'finance_statement_approved', $locked, $locked->reference_code,
                'Approved commission statement for ' . $this->periodLabel($locked) . ', base ' . Money::of($locked->total_base)->format(),
                ['approval_hash' => $locked->approval_hash, 'calculation_hash' => $locked->calculation_hash]);

            return $locked;
        }, 'These earnings were settled by another approval at the same moment. Refresh the page.');
    }

    /** Approved → Voided (with a reason). Releases its settlements; the statement and snapshot remain readable. */
    public function void(User $actor, CommissionStatement $statement, string $reason): CommissionStatement
    {
        $this->authorize($actor, FinanceAccess::APPROVE_STATEMENTS);
        $reason = $this->reason($reason, 'void a statement');

        return $this->transaction(fn () => $this->voidLocked($actor, $statement, $reason));
    }

    /**
     * Correction of an approved statement: voids it (original and audit trail preserved) and opens a new draft revision
     * for the same jobs, linked through supersedes_statement_id.
     */
    public function correct(User $actor, CommissionStatement $statement, string $reason): CommissionStatement
    {
        $this->authorize($actor, FinanceAccess::APPROVE_STATEMENTS);
        $this->authorize($actor, FinanceAccess::PREPARE_STATEMENTS);
        $reason = $this->reason($reason, 'correct a statement');

        return $this->transaction(function () use ($actor, $statement, $reason) {
            $voided = $this->voidLocked($actor, $statement, "Correction: {$reason}");

            return $this->generate($actor, $voided->period_start->toDateString(), (int) $voided->unit_id, $voided->included_jobs, $voided, $reason);
        });
    }

    /** True while the stored statement and lines still match the approved snapshot and its hash. */
    public function verifyIntegrity(CommissionStatement $statement): bool
    {
        $stored = $statement->approval_snapshot;
        if ($statement->approval_hash === null || ! is_array($stored) || ! hash_equals($statement->approval_hash, self::hash($stored))) {
            return false;
        }
        $lines = CommissionLine::where('statement_id', $statement->id)->orderBy('id')->get();
        $policy = CommissionPolicy::findOrFail($statement->policy_id);
        $rebuilt = $this->snapshot($statement, $lines, $policy);

        foreach (['statement', 'lines', 'calculation'] as $part) {
            if (self::canonical($rebuilt[$part]) !== self::canonical($stored[$part] ?? null)) {
                return false;
            }
        }

        return ($stored['policy']['id'] ?? null) === $policy->id && ($stored['policy']['rates'] ?? null) == $policy->rates();
    }

    public static function hash(array $snapshot): string
    {
        return hash('sha256', json_encode(self::canonical($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Recursively sorts associative keys so a jsonb round trip (which reorders keys) hashes identically. */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map([self::class, 'canonical'], $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    // ------------------------------------------------------------------ internals

    private function voidLocked(User $actor, CommissionStatement $statement, string $reason): CommissionStatement
    {
        $locked = CommissionStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== CommissionStatement::STATUS_APPROVED) {
            throw new FinanceException('Only an approved statement can be voided or corrected. Return a statement under review to draft instead.');
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

        CommissionSettlement::where('statement_id', $locked->id)->active()->get()
            ->each(fn (CommissionSettlement $s) => $s->forceFill(['released_at' => now(), 'released_by' => $actor->id, 'release_reason' => $reason])->save());

        $this->audit($actor, 'finance_statement_voided', $locked, $locked->reference_code, "Voided commission statement ({$reason})");

        return $locked;
    }

    /** The hashed part of a calculation: money as strings, dates as Y-m-d, no blocker wording. */
    private function content(array $draft): array
    {
        $s = fn ($m) => Money::of($m)->toString();

        return [
            'week_start' => $draft['week_start']->toDateString(),
            'week_end' => $draft['week_end']->toDateString(),
            'unit_id' => $draft['unit_id'],
            'policy' => $draft['policy'],
            'lines' => collect($draft['lines'])->map(fn (array $l) => [
                'job_key' => $l['job_key'],
                'reference' => $l['reference'],
                'group_code' => $l['group_code'],
                'booking_id' => $l['booking_id'],
                'member_booking_ids' => array_values(array_map('intval', $l['member_booking_ids'])),
                'allocation_status' => $l['allocation_status'],
                'service_date' => $l['service_date']?->toDateString(),
                'unit_id' => $l['unit_id'],
                'unit_name' => $l['unit_name'],
                'vehicles' => (int) $l['vehicles'],
                'collection' => $s($l['collection']),
                'expenses' => array_map(fn ($e) => ['id' => $e['id'], 'reference' => $e['reference'], 'category' => $e['category'], 'amount' => $s($e['amount'])], $l['expenses']),
                'expense_total' => $s($l['expense_total']),
                'deductions' => array_map(fn ($d) => ['id' => $d['id'], 'reference' => $d['reference'], 'type' => $d['type'], 'amount' => $s($d['amount'])], $l['deductions']),
                'deduction_total' => $s($l['deduction_total']),
                'base' => $s($l['base']),
                'company' => $s($l['company']),
                'company_rounding' => $s($l['company_rounding']),
                'roles' => collect($l['roles'])->map(fn ($r) => [
                    'label' => $r['label'],
                    'rate' => (string) $r['rate'],
                    'share' => $s($r['share']),
                    'allocated' => $s($r['allocated']),
                    'unallocated' => $s($r['unallocated']),
                    'recipients' => array_map(fn ($p) => [
                        'name' => $p['name'], 'slot' => $p['slot'], 'personnel_id' => $p['personnel_id'], 'user_id' => $p['user_id'],
                        'job_personnel_id' => $p['job_personnel_id'], 'recipient_key' => $p['recipient_key'],
                        'role_confirmed' => $p['role_confirmed'], 'is_substitute' => $p['is_substitute'], 'amount' => $s($p['amount']),
                    ], $r['recipients']),
                ])->all(),
                'crew_allocated' => $s($l['crew_allocated']),
                'unallocated' => $s($l['unallocated']),
            ])->values()->all(),
        ];
    }

    /** Statement totals from calculation lines. Lines whose base is not positive carry no shares (and block approval). */
    private function totals(array $lines): array
    {
        $c = collect($lines);
        $positive = $c->filter(fn ($l) => Money::of($l['base'])->isPositive());

        return [
            'total_collection' => Money::sum($c->pluck('collection'))->toString(),
            'total_expenses' => Money::sum($c->pluck('expense_total'))->toString(),
            'total_deductions' => Money::sum($c->pluck('deduction_total'))->toString(),
            'total_base' => Money::sum($positive->pluck('base'))->toString(),
            'company_amount' => Money::sum($positive->pluck('company'))->toString(),
            'personnel_amount' => Money::sum($positive->pluck('crew_allocated'))->toString(),
            'unallocated_amount' => Money::sum($positive->pluck('unallocated'))->toString(),
        ];
    }

    private function writeLines(CommissionStatement $statement, array $lines): void
    {
        $rows = 0;
        $rates = $statement->calculation['content']['policy']['rates'] ?? [];
        foreach ($lines as $l) {
            if (! Money::of($l['base'])->isPositive()) {
                continue;
            }
            $common = ['statement_id' => $statement->id, 'booking_id' => $l['booking_id'], 'unit_id' => $l['unit_id'], 'base_amount' => $l['base']];

            CommissionLine::create($common + ['commission_role' => 'company', 'recipient_key' => 'company', 'recipient_name' => 'Company',
                'rate' => (string) ($rates['company'] ?? '0'), 'amount' => $l['company'], 'rounding_adjustment' => $l['company_rounding']]);
            $rows++;

            foreach ($l['roles'] as $role => $r) {
                foreach ($r['recipients'] as $p) {
                    CommissionLine::create($common + ['commission_role' => $role, 'job_personnel_id' => $p['job_personnel_id'],
                        'recipient_personnel_id' => $p['personnel_id'], 'recipient_user_id' => $p['user_id'], 'recipient_key' => $p['recipient_key'],
                        'recipient_name' => mb_substr($p['name'], 0, 150), 'rate' => $r['rate'], 'amount' => $p['amount']]);
                    $rows++;
                }
                if (Money::of($r['unallocated'])->isPositive()) {
                    CommissionLine::create($common + ['commission_role' => $role, 'recipient_key' => 'unallocated', 'recipient_name' => 'Unallocated',
                        'rate' => $r['rate'], 'amount' => $r['unallocated']]);
                    $rows++;
                }
            }
        }
        $statement->forceFill(['line_count' => $rows])->save();
    }

    private function assertExactTotals(CommissionStatement $statement, $lines): void
    {
        $company = Money::sum($lines->where('recipient_key', 'company')->pluck('amount'));
        $unallocated = Money::sum($lines->where('recipient_key', 'unallocated')->pluck('amount'));
        $personnel = Money::sum($lines->whereNotIn('recipient_key', ['company', 'unallocated'])->pluck('amount'));
        $total = $company->plus($personnel)->plus($unallocated);

        // Every job × unit's lines must add up exactly to its base: no centavo created or lost.
        foreach ($lines->groupBy(fn ($l) => $l->booking_id . '|' . $l->unit_id) as $group) {
            $bases = $group->map(fn ($l) => Money::of($l->base_amount)->toString())->unique();
            if ($bases->count() !== 1 || ! Money::sum($group->pluck('amount'))->equals($bases->first())) {
                throw new FinanceException('Commission lines for a job do not add up exactly to its base amount.');
            }
        }
        $expectedBase = Money::sum($lines->groupBy(fn ($l) => $l->booking_id . '|' . $l->unit_id)->map(fn ($g) => $g->first()->base_amount));

        if (! $total->equals($statement->total_base) || ! $total->equals($expectedBase)
            || ! $company->equals($statement->company_amount) || ! $personnel->equals($statement->personnel_amount)
            || ! $unallocated->equals($statement->unallocated_amount) || $lines->count() !== (int) $statement->line_count) {
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
                'unit_id' => $statement->unit_id,
                'unit_name' => $statement->unit?->name,
                'revision' => (int) $statement->revision,
                'supersedes_statement_id' => $statement->supersedes_statement_id,
                'included_jobs' => $statement->included_jobs,
                'total_collection' => Money::of($statement->total_collection)->toString(),
                'total_expenses' => Money::of($statement->total_expenses)->toString(),
                'total_deductions' => Money::of($statement->total_deductions)->toString(),
                'total_base' => Money::of($statement->total_base)->toString(),
                'company_amount' => Money::of($statement->company_amount)->toString(),
                'personnel_amount' => Money::of($statement->personnel_amount)->toString(),
                'unallocated_amount' => Money::of($statement->unallocated_amount)->toString(),
                'line_count' => (int) $statement->line_count,
                'calculation_hash' => $statement->calculation_hash,
            ],
            'policy' => ['id' => $policy->id, 'version' => (int) $policy->version, 'name' => $policy->name, 'rates' => $policy->rates(),
                'interpretation_status' => $policy->interpretation_status, 'base_definition' => $policy->base_definition,
                'allocation_rule' => $policy->allocation_rule, 'confirmed_rules' => $policy->confirmed_rules ?? []],
            'calculation' => $statement->calculation['content'] ?? null,
            'lines' => $lines->map(fn (CommissionLine $line) => [
                'id' => $line->id,
                'booking_id' => $line->booking_id,
                'unit_id' => $line->unit_id,
                'job_personnel_id' => $line->job_personnel_id,
                'commission_role' => $line->commission_role,
                'recipient_key' => $line->recipient_key,
                'recipient_name' => $line->recipient_name,
                'recipient_personnel_id' => $line->recipient_personnel_id,
                'recipient_user_id' => $line->recipient_user_id,
                'base_amount' => Money::of($line->base_amount)->toString(),
                'rate' => (string) $line->rate,
                'amount' => Money::of($line->amount)->toString(),
                'rounding_adjustment' => Money::of($line->rounding_adjustment)->toString(),
            ])->values()->all(),
        ];
    }

    private function auditReferences(CommissionStatement $statement): array
    {
        return AuditLog::where('entity_type', class_basename(CommissionStatement::class))->where('entity_id', $statement->id)
            ->orderBy('id')->get(['id', 'action'])->map(fn ($a) => ['id' => $a->id, 'action' => $a->action])->all();
    }

    /** job key => reference of the approved statement that already settled it for this unit. */
    private function settledKeys(array $draft, int $unitId, ?int $exceptStatementId = null): array
    {
        $members = [];
        foreach ($draft['lines'] as $l) {
            $members[$l['job_key']] = $l['member_booking_ids'];
        }
        foreach ($draft['blocked_jobs'] as $bj) {
            $members[$bj['job']['key']] ??= collect($bj['job']['members'])->pluck('id')->all();
        }
        if ($members === []) {
            return [];
        }

        $active = CommissionSettlement::active()->where('unit_id', $unitId)
            ->whereIn('booking_id', collect($members)->flatten()->all())
            ->when($exceptStatementId, fn ($q) => $q->where('statement_id', '!=', $exceptStatementId))
            ->with('statement:id,reference_code')->get();

        $result = [];
        foreach ($members as $key => $ids) {
            $hit = $active->first(fn ($s) => in_array($s->booking_id, $ids));
            if ($hit) {
                $result[$key] = $hit->statement?->reference_code ?? "#{$hit->statement_id}";
            }
        }

        return $result;
    }

    /** Serialises statement work for one week × unit across concurrent requests (PostgreSQL advisory lock). */
    private function lockWeekUnit(CarbonImmutable $start, int $unitId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [(int) $start->format('Ymd'), $unitId]);
        }
    }

    /** Runs $fn in a transaction and turns a lost uniqueness race into a clear message. */
    private function transaction(\Closure $fn, string $conflictMessage = 'Another change to this statement was saved at the same moment. Refresh the page.'): mixed
    {
        try {
            return DB::transaction($fn);
        } catch (UniqueConstraintViolationException) {
            throw new FinanceException($conflictMessage);
        }
    }

    private function reason(string $reason, string $what): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new FinanceException("A reason (at least 5 characters) is required to {$what}.");
        }

        return mb_substr($reason, 0, 500);
    }

    private function periodLabel(CommissionStatement $statement): string
    {
        return $statement->period_start->format('M j') . '–' . $statement->period_end->format('M j, Y') . ($statement->unit?->name ? ", {$statement->unit->name}" : '');
    }
}
