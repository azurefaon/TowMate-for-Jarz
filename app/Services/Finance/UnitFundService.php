<?php

namespace App\Services\Finance;

use App\Models\Finance\Expense;
use App\Models\Finance\FundEntry;
use App\Models\Unit;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only: cash issued to a unit and cash returned by it.
 *
 * Unit fund balance = recorded issuances − recorded returns − approved expenses paid from the unit fund.
 * Customer collections are NOT part of this balance; they stay in bookings and payments.
 */
class UnitFundService extends FinanceSupport
{
    /** @param array{unit_id:int, amount:string|int, entry_date:string, team_leader_user_id?:int|null, counterparty_name?:string|null, notes?:string|null} $data */
    public function issue(User $actor, array $data, string $idempotencyKey): FundEntry
    {
        return $this->record($actor, FundEntry::TYPE_ISSUANCE, $data, $idempotencyKey);
    }

    /** @param array{unit_id:int, amount:string|int, entry_date:string, issuance_id?:int|null, team_leader_user_id?:int|null, counterparty_name?:string|null, notes?:string|null} $data */
    public function recordReturn(User $actor, array $data, string $idempotencyKey): FundEntry
    {
        return $this->record($actor, FundEntry::TYPE_RETURN, $data, $idempotencyKey);
    }

    public function void(User $actor, FundEntry $entry, string $reason): FundEntry
    {
        $this->authorize($actor, FinanceAccess::MANAGE_FUNDS);
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceException('A reason is required to void a unit fund entry.');
        }

        return DB::transaction(function () use ($actor, $entry, $reason) {
            Unit::whereKey($entry->unit_id)->lockForUpdate()->first();
            $locked = FundEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== FundEntry::STATUS_RECORDED) {
                throw new FinanceException('This unit fund entry is already voided.');
            }

            if ($locked->entry_type === FundEntry::TYPE_ISSUANCE) {
                $inUse = FundEntry::where('issuance_id', $locked->id)->recorded()->exists()
                    || Expense::where('fund_entry_id', $locked->id)->whereNotIn('status', [Expense::STATUS_REJECTED, Expense::STATUS_VOIDED])->exists();
                if ($inUse) {
                    throw new FinanceException('Void the returns and expenses linked to this issuance first.');
                }

                $after = $this->balance($locked->unit_id)->minus($locked->amount);
                if ($after->isNegative()) {
                    throw new FinanceException('Voiding this issuance would make the unit fund balance negative.');
                }
            }

            $locked->forceFill([
                'status' => FundEntry::STATUS_VOIDED,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            $this->audit($actor, 'finance_fund_voided', $locked, $locked->reference_code,
                "Voided unit fund {$locked->entry_type} of " . Money::of($locked->amount)->format() . ": {$reason}");

            return $locked;
        });
    }

    /** Exact unit fund balance (database NUMERIC sums, no floats). */
    public function balance(int $unitId): Money
    {
        $issued = FundEntry::recorded()->where('unit_id', $unitId)->where('entry_type', FundEntry::TYPE_ISSUANCE)->sum('amount');
        $returned = FundEntry::recorded()->where('unit_id', $unitId)->where('entry_type', FundEntry::TYPE_RETURN)->sum('amount');
        $spent = Expense::where('unit_id', $unitId)->where('funding_source', 'unit_fund')->where('status', Expense::STATUS_APPROVED)->sum('amount');

        return Money::of((string) $issued)->minus((string) $returned)->minus((string) $spent);
    }

    private function record(User $actor, string $type, array $data, string $idempotencyKey): FundEntry
    {
        $this->authorize($actor, FinanceAccess::MANAGE_FUNDS);

        $amount = Money::of($data['amount'] ?? throw new FinanceException('An amount is required.'));
        if (! $amount->isPositive()) {
            throw new FinanceException('The amount must be greater than zero.');
        }
        $unitId = (int) ($data['unit_id'] ?? 0);
        $entryDate = (string) ($data['entry_date'] ?? '');
        if ($entryDate === '' || ! strtotime($entryDate)) {
            throw new FinanceException('A valid entry date is required.');
        }
        $entryDate = date('Y-m-d', strtotime($entryDate));

        $fingerprint = ['entry_type' => $type, 'unit_id' => $unitId, 'amount' => $amount->toString(), 'entry_date' => $entryDate];

        return $this->createOnce(FundEntry::class, $idempotencyKey, $fingerprint, function () use ($actor, $type, $data, $amount, $unitId, $entryDate, $idempotencyKey) {
            // Lock the unit so concurrent returns cannot both pass the balance check.
            $unit = Unit::whereKey($unitId)->lockForUpdate()->first();
            if (! $unit) {
                throw new FinanceException('The selected unit does not exist.');
            }

            $issuanceId = null;
            if ($type === FundEntry::TYPE_RETURN) {
                if (! empty($data['issuance_id'])) {
                    $issuance = FundEntry::recorded()->whereKey($data['issuance_id'])->where('entry_type', FundEntry::TYPE_ISSUANCE)->first();
                    if (! $issuance || (int) $issuance->unit_id !== $unitId) {
                        throw new FinanceException('The linked issuance must be a recorded issuance for the same unit.');
                    }
                    $issuanceId = $issuance->id;
                }

                if ($this->balance($unitId)->compareTo($amount) < 0) {
                    throw new FinanceException('The returned amount is larger than the unit fund balance of ' . $this->balance($unitId)->format() . '.');
                }
            }

            $entry = FundEntry::create([
                'idempotency_key' => $idempotencyKey,
                'entry_type' => $type,
                'unit_id' => $unitId,
                'team_leader_user_id' => $data['team_leader_user_id'] ?? null,
                'counterparty_name' => $data['counterparty_name'] ?? null,
                'amount' => $amount,
                'entry_date' => $entryDate,
                'issuance_id' => $issuanceId,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            $verb = $type === FundEntry::TYPE_ISSUANCE ? 'Issued' : 'Recorded returned funds of';
            $this->audit($actor, $type === FundEntry::TYPE_ISSUANCE ? 'finance_fund_issued' : 'finance_fund_returned', $entry,
                $entry->reference_code, "{$verb} {$amount->format()} for unit {$unit->name}",
                ['amount' => $amount->toString(), 'unit_id' => $unitId, 'entry_date' => $entryDate]);

            return $entry;
        });
    }
}
