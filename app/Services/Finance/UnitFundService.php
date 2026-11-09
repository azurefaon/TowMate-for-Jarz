<?php

namespace App\Services\Finance;

use App\Models\Finance\Expense;
use App\Models\Finance\FundEntry;
use App\Models\Unit;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only: cash issued to a unit (initial or additional) and unused cash returned by it.
 *
 * Balances come from UnitFundLedger. Customer collections and payouts are never part of a unit fund.
 * Recorded entries are permanent: a mistake is voided with a reason, or corrected (void + linked replacement).
 */
class UnitFundService extends FinanceSupport
{
    public function __construct(private readonly UnitFundLedger $ledger)
    {
    }

    /**
     * @param array{unit_id:int, amount:string|int, entry_date:string, issue_kind:string, counterparty_name?:?string,
     *              external_reference?:?string, notes?:?string, team_leader_user_id?:?int} $data
     */
    public function issue(User $actor, array $data, string $idempotencyKey): FundEntry
    {
        return $this->record($actor, FundEntry::TYPE_ISSUANCE, $data, $idempotencyKey);
    }

    /**
     * @param array{unit_id:int, amount:string|int, entry_date:string, counterparty_name?:?string, external_reference?:?string,
     *              notes?:?string, issuance_id?:?int, team_leader_user_id?:?int} $data
     */
    public function recordReturn(User $actor, array $data, string $idempotencyKey): FundEntry
    {
        return $this->record($actor, FundEntry::TYPE_RETURN, $data, $idempotencyKey);
    }

    public function void(User $actor, FundEntry $entry, string $reason): FundEntry
    {
        $this->authorize($actor, FinanceAccess::MANAGE_FUNDS);
        $reason = $this->requireReason($reason, 'void');

        return DB::transaction(function () use ($actor, $entry, $reason) {
            $locked = $this->lockForChange($entry);
            $this->assertVoidable($locked);
            $this->markVoided($actor, $locked, $reason);

            return $locked;
        });
    }

    /**
     * Corrects a recorded entry: the original is voided with the reason, and a replacement that points back
     * at it (corrects_entry_id) is recorded in the same transaction. Both stay in the history.
     *
     * @param array{amount:string|int, entry_date:string, counterparty_name?:?string, external_reference?:?string, notes?:?string} $data
     */
    public function correct(User $actor, FundEntry $entry, array $data, string $reason, string $idempotencyKey): FundEntry
    {
        $this->authorize($actor, FinanceAccess::MANAGE_FUNDS);
        $reason = $this->requireReason($reason, 'correct');

        $replacement = $data + [
            'unit_id' => $entry->unit_id,
            'issue_kind' => $entry->issue_kind,
            'issuance_id' => $entry->issuance_id,
            'team_leader_user_id' => $entry->team_leader_user_id,
        ];
        $replacement['unit_id'] = $entry->unit_id; // a correction never moves money to another unit

        return $this->record($actor, $entry->entry_type, $replacement, $idempotencyKey, function () use ($actor, $entry, $reason) {
            $locked = $this->lockForChange($entry);
            $this->assertVoidable($locked, forCorrection: true);
            $this->markVoided($actor, $locked, "Corrected: {$reason}");

            return $locked;
        });
    }

    /** Remaining unit fund balance (today, or as of a date). */
    public function balance(int $unitId, ?string $asOf = null): Money
    {
        return $this->ledger->balance($unitId, $asOf);
    }

    /**
     * @param  (callable(): FundEntry)|null  $beforeCreate  runs inside the transaction (used by corrections to void the original first)
     */
    private function record(User $actor, string $type, array $data, string $idempotencyKey, ?callable $beforeCreate = null): FundEntry
    {
        $this->authorize($actor, FinanceAccess::MANAGE_FUNDS);

        $amount = $this->parseAmount($data['amount'] ?? null);
        $unitId = (int) ($data['unit_id'] ?? 0);
        $entryDate = $this->parseDate($data['entry_date'] ?? null);
        $issueKind = $type === FundEntry::TYPE_ISSUANCE ? (string) ($data['issue_kind'] ?? '') : null;
        if ($type === FundEntry::TYPE_ISSUANCE && ! array_key_exists($issueKind, config('finance.issue_kinds'))) {
            throw new FinanceException('Choose whether these are initial or additional funds.');
        }

        $fingerprint = ['entry_type' => $type, 'unit_id' => $unitId, 'amount' => $amount->toString(), 'entry_date' => $entryDate];

        return $this->createOnce(FundEntry::class, $idempotencyKey, $fingerprint,
            function () use ($actor, $type, $data, $amount, $unitId, $entryDate, $issueKind, $idempotencyKey, $beforeCreate) {
                // Lock the unit so concurrent entries for it are checked one at a time.
                $unit = Unit::whereKey($unitId)->lockForUpdate()->first();
                if (! $unit) {
                    throw new FinanceException('The selected unit does not exist.');
                }
                if ($unit->archived_at !== null && $beforeCreate === null && $type === FundEntry::TYPE_ISSUANCE) {
                    throw new FinanceException('Funds cannot be issued to an archived unit.');
                }

                $corrected = $beforeCreate ? $beforeCreate() : null;

                if ($type === FundEntry::TYPE_ISSUANCE) {
                    $hasInitial = FundEntry::recorded()->where('unit_id', $unitId)->where('issue_kind', 'initial')->exists();
                    if ($issueKind === 'initial' && $hasInitial) {
                        throw new FinanceException('This unit already has initial funds recorded. Record additional funds instead.');
                    }
                    if ($issueKind === 'additional' && ! $hasInitial) {
                        throw new FinanceException('Record the initial funds for this unit before additional funds.');
                    }
                }

                $issuanceId = null;
                if ($type === FundEntry::TYPE_RETURN) {
                    $issuanceId = $this->resolveIssuance($data['issuance_id'] ?? null, $unitId);
                    $this->assertReturnSupported($unitId, $amount, $entryDate);
                }

                $entry = FundEntry::create([
                    'idempotency_key' => $idempotencyKey,
                    'entry_type' => $type,
                    'issue_kind' => $issueKind,
                    'unit_id' => $unitId,
                    'team_leader_user_id' => $data['team_leader_user_id'] ?? null,
                    'counterparty_name' => $this->clean($data['counterparty_name'] ?? null, 150),
                    'external_reference' => $this->clean($data['external_reference'] ?? null, 100),
                    'amount' => $amount->toString(),
                    'entry_date' => $entryDate,
                    'issuance_id' => $issuanceId,
                    'corrects_entry_id' => $corrected?->id,
                    'notes' => $this->clean($data['notes'] ?? null, 2000),
                    'recorded_by' => $actor->id,
                ]);

                // A correction that lowers an issuance must still leave the unit with non-negative cash.
                if ($corrected && $this->ledger->balance($unitId)->isNegative()) {
                    throw new FinanceException('This correction would make the unit fund balance negative. Record the related returns or expenses first.');
                }

                $label = $type === FundEntry::TYPE_ISSUANCE
                    ? 'Issued ' . strtolower(config('finance.issue_kinds')[$issueKind])
                    : 'Recorded returned funds';
                $action = $type === FundEntry::TYPE_ISSUANCE ? 'finance_fund_issued' : 'finance_fund_returned';

                $this->audit($actor, $action, $entry, $entry->reference_code,
                    "{$label} of {$amount->format()} for unit {$unit->name}" . ($corrected ? " (correction of {$corrected->reference_code})" : ''),
                    array_filter([
                        'amount' => $amount->toString(),
                        'unit_id' => $unitId,
                        'entry_date' => $entryDate,
                        'issue_kind' => $issueKind,
                        'corrects' => $corrected?->reference_code,
                    ], fn ($v) => $v !== null));

                return $entry;
            });
    }

    /**
     * A return can never exceed the cash the unit still holds: not today, not on the return date, and not after
     * the unit-fund expenses still awaiting review are taken into account (those were spent from the same cash).
     */
    private function assertReturnSupported(int $unitId, Money $amount, string $entryDate): void
    {
        $pending = $this->ledger->pendingAmount($unitId);
        $returnable = $this->ledger->balance($unitId)->minus($pending);
        $onDate = $this->ledger->balance($unitId, $entryDate);

        if ($onDate->compareTo($amount) < 0) {
            throw new FinanceException("The returned amount is larger than the unit's balance on that date ({$onDate->format()}).");
        }
        if ($returnable->compareTo($amount) < 0) {
            $message = "The returned amount is larger than the unit's returnable balance ({$returnable->format()})";
            throw new FinanceException($pending->isPositive()
                ? $message . ", which already sets aside {$pending->format()} of expenses awaiting review."
                : $message . '.');
        }
    }

    private function assertVoidable(FundEntry $entry, bool $forCorrection = false): void
    {
        if ($entry->status !== FundEntry::STATUS_RECORDED) {
            throw new FinanceException('This unit fund entry is already voided.');
        }

        if ($entry->entry_type !== FundEntry::TYPE_ISSUANCE) {
            return;
        }

        $linked = FundEntry::where('issuance_id', $entry->id)->recorded()->exists()
            || Expense::where('fund_entry_id', $entry->id)->whereNotIn('status', [Expense::STATUS_REJECTED, Expense::STATUS_VOIDED])->exists();
        if ($linked) {
            throw new FinanceException('Void the returns and expenses linked to this issuance first.');
        }

        if ($entry->issue_kind === 'initial' && ! $forCorrection
            && FundEntry::recorded()->where('unit_id', $entry->unit_id)->where('issue_kind', 'additional')->exists()) {
            throw new FinanceException('This unit has additional funds recorded after the initial funds. Correct the initial entry instead of voiding it.');
        }

        // A plain void must not leave the unit holding negative cash. A correction is checked when its replacement is recorded.
        if (! $forCorrection && $this->ledger->balance($entry->unit_id)->minus($entry->amount)->isNegative()) {
            throw new FinanceException('Voiding this issuance would make the unit fund balance negative.');
        }
    }

    private function markVoided(User $actor, FundEntry $entry, string $reason): void
    {
        $entry->forceFill([
            'status' => FundEntry::STATUS_VOIDED,
            'voided_by' => $actor->id,
            'voided_at' => now(),
            'void_reason' => $reason,
        ])->save();

        $this->audit($actor, 'finance_fund_voided', $entry, $entry->reference_code,
            "Voided unit fund {$entry->entry_type} of " . Money::of($entry->amount)->format() . ": {$reason}");
    }

    private function lockForChange(FundEntry $entry): FundEntry
    {
        Unit::whereKey($entry->unit_id)->lockForUpdate()->first();

        return FundEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
    }

    private function resolveIssuance(mixed $issuanceId, int $unitId): ?int
    {
        if (empty($issuanceId)) {
            return null;
        }

        $issuance = FundEntry::recorded()->whereKey($issuanceId)->where('entry_type', FundEntry::TYPE_ISSUANCE)->first();
        if (! $issuance || (int) $issuance->unit_id !== $unitId) {
            throw new FinanceException('The linked issuance must be a recorded issuance for the same unit.');
        }

        return $issuance->id;
    }

    private function parseAmount(mixed $value): Money
    {
        if ($value === null || $value === '') {
            throw new FinanceException('An amount is required.');
        }

        try {
            $amount = Money::of(is_string($value) ? str_replace(',', '', $value) : $value);
        } catch (\InvalidArgumentException) {
            throw new FinanceException('Enter a valid amount with at most two decimal places.');
        }

        if (! $amount->isPositive()) {
            throw new FinanceException('The amount must be greater than zero.');
        }
        if ($amount->compareTo('9999999999.99') > 0) {
            throw new FinanceException('The amount is too large.');
        }

        return $amount;
    }

    private function parseDate(mixed $value): string
    {
        $value = (string) $value;
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! strtotime($value)) {
            throw new FinanceException('A valid date is required.');
        }

        $date = CarbonImmutable::parse($value, config('finance.timezone'));
        if ($date->greaterThan(now(config('finance.timezone'))->endOfDay())) {
            throw new FinanceException('Fund movements cannot be dated in the future.');
        }

        return $date->toDateString();
    }

    private function requireReason(string $reason, string $verb): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceException("A reason is required to {$verb} a unit fund entry.");
        }

        return mb_substr($reason, 0, 500);
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
