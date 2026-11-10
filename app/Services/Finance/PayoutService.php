<?php

namespace App\Services\Finance;

use App\Models\Finance\CommissionStatement;
use App\Models\Finance\Payout;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Owner-only payout RECORDS for approved commission statements. Nothing here moves money: the Owner pays the person
 * by cash, GCash or bank transfer outside TowMate and then records that payment here.
 *
 * - The payable amount comes from the statement's frozen approval snapshot, never from current crew or rates, and is
 *   never typed in. Company share and unallocated amounts are not payable.
 * - Supported workflow: ONE full payout per recipient per statement. Partial disbursements and one combined (group)
 *   payout for several people are pending client confirmation and are not supported.
 * - A mistake is corrected by voiding the payout (reason required) and recording a linked replacement; the original
 *   stays. Once every payable person has exactly their approved amount recorded, the Owner marks the statement Paid,
 *   which freezes its payouts. Recording a payout never marks a statement Paid by itself.
 */
class PayoutService extends FinanceSupport
{
    public function __construct(private readonly CommissionStatementService $statements)
    {
    }

    /**
     * Payable earnings per recipient, read from the approval snapshot (company and unallocated excluded).
     *
     * @return array<string, array{recipient_key:string, recipient_name:string, recipient_personnel_id:?int, recipient_user_id:?int, roles:string[], earned:Money}>
     */
    public function earningsByRecipient(CommissionStatement $statement): array
    {
        $result = [];
        foreach ($statement->approval_snapshot['lines'] ?? [] as $line) {
            $key = $line['recipient_key'];
            if (in_array($key, ['company', 'unallocated'], true)) {
                continue;
            }
            $result[$key] ??= [
                'recipient_key' => $key,
                'recipient_name' => $line['recipient_name'],
                'recipient_personnel_id' => $line['recipient_personnel_id'] ?? null,
                'recipient_user_id' => $line['recipient_user_id'] ?? null,
                'roles' => [],
                'earned' => Money::zero(),
            ];
            $result[$key]['earned'] = $result[$key]['earned']->plus($line['amount']);
            $label = CommissionCalculator::ROLE_LABELS[$line['commission_role']] ?? $line['commission_role'];
            if (! in_array($label, $result[$key]['roles'], true)) {
                $result[$key]['roles'][] = $label;
            }
        }
        ksort($result);

        return $result;
    }

    /** Who is owed what, what has been recorded, and whether the statement can be marked Paid. */
    public function reconciliation(CommissionStatement $statement): array
    {
        $earnings = $this->earningsByRecipient($statement);
        $payouts = Payout::where('statement_id', $statement->id)->orderBy('id')->get();
        $recorded = $payouts->where('status', Payout::STATUS_RECORDED)->keyBy('recipient_key');

        $rows = [];
        $issues = [];
        foreach ($earnings as $key => $e) {
            $payout = $recorded->get($key);
            $matches = $payout && Money::of($payout->amount)->equals($e['earned']);
            if ($e['earned']->isPositive() && ! $payout) {
                $issues[] = "No payout recorded for {$e['recipient_name']} ({$e['earned']->format()}).";
            } elseif ($payout && ! $matches) {
                $issues[] = "The payout to {$e['recipient_name']} does not equal the approved amount.";
            }
            $rows[] = $e + ['payout' => $payout, 'reconciled' => ! $e['earned']->isPositive() || $matches];
        }
        foreach ($recorded as $key => $payout) {
            if (! isset($earnings[$key])) {
                $issues[] = "Payout {$payout->reference_code} has no approved earnings on this statement.";
            }
        }

        $payable = Money::sum(collect($earnings)->pluck('earned'));
        $paid = Money::sum($recorded->pluck('amount'));
        if ($statement->status === CommissionStatement::STATUS_APPROVED && ! $payable->equals($statement->personnel_amount)) {
            $issues[] = 'The approved snapshot does not match the statement personnel amount.';
        }
        if (! Money::of($statement->unallocated_amount)->isZero()) {
            $issues[] = 'The statement has unallocated amounts.';
        }

        return [
            'rows' => $rows,
            'company' => Money::of($statement->company_amount),
            'base' => Money::of($statement->total_base),
            'payable' => $payable,
            'paid' => $paid,
            'remaining' => $payable->minus($paid),
            'issues' => $issues,
            'can_mark_paid' => $statement->status === CommissionStatement::STATUS_APPROVED && $issues === [] && $paid->equals($payable),
            'history' => $payouts,
        ];
    }

    public function record(User $actor, CommissionStatement $statement, string $recipientKey, string $method, string $paidAt, string $idempotencyKey,
        ?string $externalReference = null, ?UploadedFile $evidence = null, ?string $notes = null, ?int $correctsPayoutId = null): Payout
    {
        $this->authorize($actor, FinanceAccess::RECORD_PAYOUTS);

        if (! array_key_exists($method, config('finance.payout_methods'))) {
            throw new FinanceException('Choose a valid payout method: Cash, GCash or Bank Transfer.');
        }
        $externalReference = filled($externalReference) ? mb_substr(trim($externalReference), 0, 100) : null;
        if ($externalReference === null && $evidence === null) {
            throw new FinanceException('Enter the transaction reference (for example the GCash or bank reference, or the cash acknowledgement number) or attach supporting evidence.');
        }
        if ($evidence) {
            $this->assertEvidence($evidence);
        }
        $paid = $this->paidAt($paidAt, $statement);

        $fingerprint = ['statement_id' => $statement->id, 'recipient_key' => $recipientKey, 'payout_method' => $method];

        return $this->createOnce(Payout::class, $idempotencyKey, $fingerprint,
            function () use ($actor, $statement, $recipientKey, $method, $paid, $idempotencyKey, $externalReference, $evidence, $notes, $correctsPayoutId) {
                $locked = CommissionStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === CommissionStatement::STATUS_PAID) {
                    throw new FinanceException('This statement is already marked Paid. Its payouts are final.');
                }
                if ($locked->status !== CommissionStatement::STATUS_APPROVED) {
                    throw new FinanceException('Payouts can only be recorded for an approved commission statement.');
                }
                if (! $this->statements->verifyIntegrity($locked)) {
                    throw new FinanceException('The approved statement failed its integrity check. Payouts cannot be recorded against it.');
                }

                $earnings = $this->earningsByRecipient($locked)[$recipientKey] ?? null;
                if ($earnings === null) {
                    throw new FinanceException('This person has no approved earnings on this statement.');
                }
                if (! $earnings['earned']->isPositive()) {
                    throw new FinanceException('There is nothing to pay this person on this statement.');
                }
                if (Payout::where('statement_id', $locked->id)->where('recipient_key', $recipientKey)->where('status', Payout::STATUS_RECORDED)->lockForUpdate()->exists()) {
                    throw new FinanceException('A payout is already recorded for this person on this statement.');
                }

                $stored = $evidence ? $this->storeEvidence($evidence, $locked) : null;
                try {
                    $payout = Payout::create([
                        'idempotency_key' => $idempotencyKey,
                        'statement_id' => $locked->id,
                        'recipient_key' => $recipientKey,
                        'recipient_personnel_id' => $earnings['recipient_personnel_id'],
                        'recipient_user_id' => $earnings['recipient_user_id'],
                        'recipient_name' => $earnings['recipient_name'],
                        'recipient_roles' => mb_substr(implode(', ', $earnings['roles']), 0, 150),
                        'amount' => $earnings['earned'],
                        'payout_method' => $method,
                        'external_reference' => $externalReference,
                        'paid_at' => $paid,
                        'recorded_by' => $actor->id,
                        'recorded_by_name' => $this->actorName($actor),
                        'evidence_path' => $stored['path'] ?? null,
                        'evidence_original_name' => $stored['name'] ?? null,
                        'evidence_mime' => $stored['mime'] ?? null,
                        'evidence_sha256' => $stored['sha256'] ?? null,
                        'notes' => filled($notes) ? mb_substr(trim($notes), 0, 500) : null,
                        'corrects_payout_id' => $correctsPayoutId,
                    ]);
                } catch (\Throwable $e) {
                    if ($stored) {
                        Storage::disk('local')->delete($stored['path']);
                    }
                    throw $e;
                }

                $log = $this->audit($actor, 'finance_payout_recorded', $payout, $payout->reference_code,
                    "Recorded payout of {$earnings['earned']->format()} to {$earnings['recipient_name']} (" . config('finance.payout_methods')[$method] . ") for statement {$locked->reference_code}",
                    ['amount' => $earnings['earned']->toString(), 'method' => $method, 'statement_id' => $locked->id, 'reference' => $externalReference, 'corrects_payout_id' => $correctsPayoutId]);

                $payout->forceFill(['audit_log_id' => $log->id])->save();

                return $payout;
            });
    }

    public function void(User $actor, Payout $payout, string $reason): Payout
    {
        $this->authorize($actor, FinanceAccess::RECORD_PAYOUTS);
        $reason = $this->reason($reason, 'void a payout');

        return DB::transaction(fn () => $this->voidLocked($actor, $payout, $reason));
    }

    /** Voids a wrong payout and records the corrected one, linked to it, in one transaction. */
    public function correct(User $actor, Payout $payout, string $reason, string $method, string $paidAt, string $idempotencyKey,
        ?string $externalReference = null, ?UploadedFile $evidence = null, ?string $notes = null): Payout
    {
        $this->authorize($actor, FinanceAccess::RECORD_PAYOUTS);
        $reason = $this->reason($reason, 'correct a payout');

        return DB::transaction(function () use ($actor, $payout, $reason, $method, $paidAt, $idempotencyKey, $externalReference, $evidence, $notes) {
            $old = $this->voidLocked($actor, $payout, "Corrected: {$reason}");
            $new = $this->record($actor, CommissionStatement::findOrFail($old->statement_id), $old->recipient_key, $method, $paidAt, $idempotencyKey,
                $externalReference, $evidence, $notes, $old->id);

            $this->audit($actor, 'finance_payout_corrected', $new, $new->reference_code, "Payout {$old->reference_code} corrected by {$new->reference_code} ({$reason})");

            return $new;
        });
    }

    /** Approved → Paid, only when every payable person has exactly their approved amount recorded. */
    public function markPaid(User $actor, CommissionStatement $statement): CommissionStatement
    {
        $this->authorize($actor, FinanceAccess::RECORD_PAYOUTS);

        return DB::transaction(function () use ($actor, $statement) {
            $locked = CommissionStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === CommissionStatement::STATUS_PAID) {
                throw new FinanceException('This statement is already marked Paid.');
            }
            if ($locked->status !== CommissionStatement::STATUS_APPROVED) {
                throw new FinanceException('Only an approved statement can be marked Paid.');
            }
            if (! $this->statements->verifyIntegrity($locked)) {
                throw new FinanceException('The approved statement failed its integrity check.');
            }
            Payout::where('statement_id', $locked->id)->lockForUpdate()->get(['id']);

            $rec = $this->reconciliation($locked);
            if (! $rec['can_mark_paid']) {
                throw new FinanceException('This statement cannot be marked Paid yet: ' . ($rec['issues'][0] ?? "recorded payouts total {$rec['paid']->format()} of {$rec['payable']->format()}."));
            }

            $locked->forceFill(['status' => CommissionStatement::STATUS_PAID, 'paid_by' => $actor->id, 'paid_by_name' => $this->actorName($actor), 'paid_at' => now()])->save();

            $this->audit($actor, 'finance_statement_paid', $locked, $locked->reference_code,
                "Marked commission statement Paid: {$rec['paid']->format()} paid to " . count(array_filter($rec['rows'], fn ($r) => $r['payout'])) . ' person(s); company share ' . $rec['company']->format() . ' is not a payout',
                ['paid' => $rec['paid']->toString(), 'payout_ids' => collect($rec['rows'])->pluck('payout.id')->filter()->values()->all()]);

            return $locked;
        });
    }

    // ------------------------------------------------------------------ internals

    private function voidLocked(User $actor, Payout $payout, string $reason): Payout
    {
        $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== Payout::STATUS_RECORDED) {
            throw new FinanceException('This payout is already voided.');
        }
        $status = CommissionStatement::whereKey($locked->statement_id)->lockForUpdate()->value('status');
        if ($status === CommissionStatement::STATUS_PAID) {
            throw new FinanceException('This statement is marked Paid. Its payouts are final and cannot be voided.');
        }

        $locked->forceFill(['status' => Payout::STATUS_VOIDED, 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason])->save();
        $this->audit($actor, 'finance_payout_voided', $locked, $locked->reference_code, "Voided payout to {$locked->recipient_name} ({$reason})");

        return $locked;
    }

    private function paidAt(string $value, CommissionStatement $statement): CarbonImmutable
    {
        try {
            $at = CarbonImmutable::parse($value, config('finance.timezone'));
        } catch (\Throwable) {
            throw new FinanceException('Enter the actual payout date.');
        }
        if ($at->greaterThan(CarbonImmutable::now(config('finance.timezone'))->endOfDay())) {
            throw new FinanceException('The payout date cannot be in the future.');
        }
        if ($at->lessThan(CarbonImmutable::parse($statement->period_start->toDateString(), config('finance.timezone'))->startOfDay())) {
            throw new FinanceException('The payout date cannot be before the statement period.');
        }

        return $at;
    }

    private function assertEvidence(UploadedFile $file): void
    {
        $config = config('finance.evidence');
        if (! $file->isValid() || ! in_array((string) $file->getMimeType(), $config['mimes'], true) || $file->getSize() > $config['max_kilobytes'] * 1024) {
            throw new FinanceException('Supporting evidence must be a JPG, PNG, WEBP or PDF file up to ' . ($config['max_kilobytes'] / 1024) . ' MB.');
        }
    }

    private function storeEvidence(UploadedFile $file, CommissionStatement $statement): array
    {
        $mime = (string) $file->getMimeType();
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? 'bin';
        $path = "finance/payouts/{$statement->id}/" . Str::uuid() . ".{$ext}";
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return ['path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => $mime, 'sha256' => hash_file('sha256', $file->getRealPath())];
    }

    private function reason(string $reason, string $what): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new FinanceException("A reason (at least 5 characters) is required to {$what}.");
        }

        return mb_substr($reason, 0, 500);
    }
}
