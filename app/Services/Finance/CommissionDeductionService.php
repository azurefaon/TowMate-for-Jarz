<?php

namespace App\Services\Finance;

use App\Models\Booking;
use App\Models\Finance\CommissionDeduction;
use App\Models\Unit;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only: deductions from a job's sharing base that are not expenses (provider fee, refund, damage).
 * Recorded as pending review; only approved deductions reduce the base. Pending ones block commission approval.
 * Duplicates are refused (same job, unit, type and reference, or the same amount without a reference).
 */
class CommissionDeductionService extends FinanceSupport
{
    /** @param array{booking_id:int, unit_id:int, deduction_type:string, amount:string|int, description:string, external_reference?:?string} $data */
    public function record(User $actor, array $data, string $idempotencyKey): CommissionDeduction
    {
        $this->authorize($actor, FinanceAccess::MANAGE_DEDUCTIONS);

        $type = (string) ($data['deduction_type'] ?? '');
        if (! array_key_exists($type, CommissionDeduction::TYPES)) {
            throw new FinanceException('Choose a valid deduction type.');
        }
        try {
            $amount = Money::of(str_replace([',', '₱', ' '], '', (string) ($data['amount'] ?? '')));
        } catch (\InvalidArgumentException) {
            throw new FinanceException('Enter a valid amount with at most two decimal places.');
        }
        if (! $amount->isPositive()) {
            throw new FinanceException('A deduction must be greater than zero.');
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw new FinanceException('Describe the deduction.');
        }
        $booking = Booking::find((int) ($data['booking_id'] ?? 0)) ?? throw new FinanceException('The job does not exist.');
        $unit = Unit::find((int) ($data['unit_id'] ?? 0)) ?? throw new FinanceException('The unit does not exist.');
        $reference = trim((string) ($data['external_reference'] ?? '')) ?: null;

        $fingerprint = ['booking_id' => $booking->id, 'unit_id' => $unit->id, 'deduction_type' => $type, 'amount' => $amount->toString()];

        return $this->createOnce(CommissionDeduction::class, $idempotencyKey, $fingerprint, function () use ($actor, $booking, $unit, $type, $amount, $description, $reference, $idempotencyKey) {
            Booking::whereKey($booking->id)->lockForUpdate()->first();

            $duplicate = CommissionDeduction::where('booking_id', $booking->id)
                ->where('unit_id', $unit->id)
                ->where('deduction_type', $type)
                ->where('status', '!=', CommissionDeduction::STATUS_VOIDED)
                ->where(fn ($q) => $reference !== null
                    ? $q->where('external_reference', $reference)
                    : $q->whereNull('external_reference')->where('amount', $amount->toString()))
                ->exists();
            if ($duplicate) {
                throw new FinanceException('This deduction is already recorded for this job and unit. Void the existing one first if it was wrong.');
            }

            $deduction = CommissionDeduction::create([
                'idempotency_key' => $idempotencyKey,
                'booking_id' => $booking->id,
                'unit_id' => $unit->id,
                'deduction_type' => $type,
                'amount' => $amount->toString(),
                'description' => mb_substr($description, 0, 2000),
                'external_reference' => $reference ? mb_substr($reference, 0, 100) : null,
                'recorded_by' => $actor->id,
            ]);

            $this->audit($actor, 'finance_deduction_recorded', $deduction, $deduction->reference_code,
                "Recorded {$deduction->typeLabel()} of {$amount->format()} for job {$booking->booking_code} (pending review)");

            return $deduction;
        });
    }

    public function approve(User $actor, CommissionDeduction $deduction): CommissionDeduction
    {
        $this->authorize($actor, FinanceAccess::MANAGE_DEDUCTIONS);

        return $this->transition($actor, $deduction, CommissionDeduction::STATUS_PENDING, CommissionDeduction::STATUS_APPROVED,
            ['approved_by' => $actor->id, 'approved_at' => now()], 'finance_deduction_approved', 'Approved');
    }

    public function void(User $actor, CommissionDeduction $deduction, string $reason): CommissionDeduction
    {
        $this->authorize($actor, FinanceAccess::MANAGE_DEDUCTIONS);
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceException('A reason is required to void a deduction.');
        }

        return DB::transaction(function () use ($actor, $deduction, $reason) {
            $locked = CommissionDeduction::whereKey($deduction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === CommissionDeduction::STATUS_VOIDED) {
                throw new FinanceException('This deduction is already voided.');
            }
            $locked->forceFill(['status' => CommissionDeduction::STATUS_VOIDED, 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 500)])->save();
            $this->audit($actor, 'finance_deduction_voided', $locked, $locked->reference_code, "Voided deduction ({$reason})");

            return $locked;
        });
    }

    private function transition(User $actor, CommissionDeduction $deduction, string $from, string $to, array $changes, string $action, string $verb): CommissionDeduction
    {
        return DB::transaction(function () use ($actor, $deduction, $from, $to, $changes, $action, $verb) {
            $locked = CommissionDeduction::whereKey($deduction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                throw new FinanceException("This deduction is {$locked->status} and cannot be {$to}.");
            }
            $locked->forceFill(['status' => $to] + $changes)->save();
            $this->audit($actor, $action, $locked, $locked->reference_code, "{$verb} deduction of " . Money::of($locked->amount)->format());

            return $locked;
        });
    }
}
