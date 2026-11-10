<?php

namespace App\Services\Finance;

use App\Models\Finance\CommissionLine;
use App\Models\Finance\CommissionStatement;
use App\Models\Finance\Payout;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only: records crew payouts against an APPROVED statement. The amount is never typed in. It is
 * the recipient's exact approved earnings, so what was paid can never drift from what was earned.
 * One recorded payout per recipient per statement; a mistake is voided and re-recorded.
 */
class PayoutService extends FinanceSupport
{
    /** @return array<string, array{recipient_key:string, recipient_name:string, recipient_personnel_id:?int, recipient_user_id:?int, earned:Money}> */
    public function earningsByRecipient(CommissionStatement $statement): array
    {
        $result = [];
        CommissionLine::where('statement_id', $statement->id)->whereNotIn('recipient_key', ['company', 'unallocated'])->orderBy('id')->get()
            ->groupBy('recipient_key')
            ->each(function ($lines, $key) use (&$result) {
                $first = $lines->first();
                $result[$key] = [
                    'recipient_key' => $key,
                    'recipient_name' => $first->recipient_name,
                    'recipient_personnel_id' => $first->recipient_personnel_id,
                    'recipient_user_id' => $first->recipient_user_id,
                    'earned' => Money::sum($lines->pluck('amount')),
                ];
            });

        return $result;
    }

    public function record(User $actor, CommissionStatement $statement, string $recipientKey, string $method, string $paidAt, string $idempotencyKey, ?string $externalReference = null): Payout
    {
        $this->authorize($actor, FinanceAccess::RECORD_PAYOUTS);

        if (! array_key_exists($method, config('finance.payout_methods'))) {
            throw new FinanceException('Choose a valid payout method.');
        }

        $fingerprint = ['statement_id' => $statement->id, 'recipient_key' => $recipientKey, 'payout_method' => $method];

        return $this->createOnce(Payout::class, $idempotencyKey, $fingerprint, function () use ($actor, $statement, $recipientKey, $method, $paidAt, $idempotencyKey, $externalReference) {
            $locked = CommissionStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CommissionStatement::STATUS_APPROVED) {
                throw new FinanceException('Payouts can only be recorded for an approved commission statement.');
            }

            $earnings = $this->earningsByRecipient($locked)[$recipientKey] ?? null;
            if ($earnings === null) {
                throw new FinanceException('This person has no approved earnings on this statement.');
            }
            if (! $earnings['earned']->isPositive()) {
                throw new FinanceException('There is nothing to pay this person on this statement.');
            }
            if (Payout::where('statement_id', $locked->id)->where('recipient_key', $recipientKey)->where('status', Payout::STATUS_RECORDED)->exists()) {
                throw new FinanceException('A payout is already recorded for this person on this statement.');
            }

            $payout = Payout::create([
                'idempotency_key' => $idempotencyKey,
                'statement_id' => $locked->id,
                'recipient_key' => $recipientKey,
                'recipient_personnel_id' => $earnings['recipient_personnel_id'],
                'recipient_user_id' => $earnings['recipient_user_id'],
                'recipient_name' => $earnings['recipient_name'],
                'amount' => $earnings['earned'],
                'payout_method' => $method,
                'external_reference' => $externalReference,
                'paid_at' => $paidAt,
                'recorded_by' => $actor->id,
            ]);

            $log = $this->audit($actor, 'finance_payout_recorded', $payout, $payout->reference_code,
                "Recorded payout of {$earnings['earned']->format()} to {$earnings['recipient_name']} for statement {$locked->reference_code}",
                ['amount' => $earnings['earned']->toString(), 'method' => $method, 'statement_id' => $locked->id]);

            $payout->forceFill(['audit_log_id' => $log->id])->save();

            return $payout;
        });
    }

    public function void(User $actor, Payout $payout, string $reason): Payout
    {
        $this->authorize($actor, FinanceAccess::RECORD_PAYOUTS);
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceException('A reason is required to void a payout.');
        }

        return DB::transaction(function () use ($actor, $payout, $reason) {
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Payout::STATUS_RECORDED) {
                throw new FinanceException('This payout is already voided.');
            }

            $locked->forceFill([
                'status' => Payout::STATUS_VOIDED,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            $this->audit($actor, 'finance_payout_voided', $locked, $locked->reference_code, "Voided payout ({$reason})");

            return $locked;
        });
    }
}
