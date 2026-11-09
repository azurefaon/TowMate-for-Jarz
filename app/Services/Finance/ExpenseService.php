<?php

namespace App\Services\Finance;

use App\Models\Booking;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseEvidence;
use App\Models\Finance\FundEntry;
use App\Models\Unit;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Expense flow: Team Leader submits -> Dispatcher verifies -> Owner approves.
 * The Owner may also enter and approve a company expense directly. Approved expenses are final and can only be voided.
 */
class ExpenseService extends FinanceSupport
{
    /** @param array{unit_id:int, amount:string|int, expense_date:string, category:string, funding_source:string, booking_id?:int|null, fund_entry_id?:int|null, description?:string|null} $data */
    public function submit(User $actor, array $data, string $idempotencyKey): Expense
    {
        $this->authorize($actor, FinanceAccess::SUBMIT_EXPENSE);

        $amount = Money::of($data['amount'] ?? throw new FinanceException('An amount is required.'));
        if (! $amount->isPositive()) {
            throw new FinanceException('The amount must be greater than zero.');
        }
        $category = (string) ($data['category'] ?? '');
        if (! array_key_exists($category, config('finance.expense_categories'))) {
            throw new FinanceException('Choose a valid expense category.');
        }
        $funding = (string) ($data['funding_source'] ?? '');
        if (! array_key_exists($funding, config('finance.funding_sources'))) {
            throw new FinanceException('Choose a valid funding source.');
        }
        $date = (string) ($data['expense_date'] ?? '');
        if ($date === '' || ! strtotime($date)) {
            throw new FinanceException('A valid expense date is required.');
        }
        $date = date('Y-m-d', strtotime($date));
        $unitId = (int) ($data['unit_id'] ?? 0);

        $unit = Unit::find($unitId) ?? throw new FinanceException('The selected unit does not exist.');
        if ((int) $actor->role_id === FinanceAccess::ROLE_TEAM_LEADER && (int) $unit->team_leader_id !== (int) $actor->id) {
            throw new FinanceException('Team Leaders can only submit expenses for their own unit.');
        }

        if (! empty($data['booking_id']) && ! Booking::whereKey($data['booking_id'])->exists()) {
            throw new FinanceException('The linked job does not exist.');
        }

        if (! empty($data['fund_entry_id'])) {
            $fund = FundEntry::recorded()->whereKey($data['fund_entry_id'])->where('entry_type', FundEntry::TYPE_ISSUANCE)->first();
            if (! $fund || (int) $fund->unit_id !== $unitId || $funding !== 'unit_fund') {
                throw new FinanceException('Only a unit-fund expense can be linked, and only to a recorded issuance for the same unit.');
            }
        }

        $fingerprint = ['unit_id' => $unitId, 'amount' => $amount->toString(), 'expense_date' => $date, 'category' => $category];

        return $this->createOnce(Expense::class, $idempotencyKey, $fingerprint, function () use ($actor, $data, $amount, $unit, $date, $category, $funding, $idempotencyKey) {
            $expense = Expense::create([
                'idempotency_key' => $idempotencyKey,
                'unit_id' => $unit->id,
                'booking_id' => $data['booking_id'] ?? null,
                'fund_entry_id' => $data['fund_entry_id'] ?? null,
                'category' => $category,
                'description' => $data['description'] ?? null,
                'amount' => $amount,
                'expense_date' => $date,
                'funding_source' => $funding,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
            ]);

            $this->audit($actor, 'finance_expense_submitted', $expense, $expense->reference_code,
                "Submitted {$category} expense of {$amount->format()} for unit {$unit->name}",
                ['amount' => $amount->toString(), 'category' => $category, 'funding_source' => $funding]);

            return $expense;
        });
    }

    public function verify(User $actor, Expense $expense, ?string $note = null): Expense
    {
        $this->authorize($actor, FinanceAccess::VERIFY_EXPENSE);

        return $this->transition($actor, $expense, [Expense::STATUS_SUBMITTED], Expense::STATUS_VERIFIED, [
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'verification_note' => $note,
        ], 'finance_expense_verified', 'Verified expense');
    }

    public function approve(User $actor, Expense $expense): Expense
    {
        $this->authorize($actor, FinanceAccess::APPROVE_EXPENSE);

        // Owner approval follows Dispatcher verification. The only shortcut is an expense the Owner entered personally.
        $allowedFrom = (int) $expense->submitted_by === (int) $actor->id
            ? [Expense::STATUS_SUBMITTED, Expense::STATUS_VERIFIED]
            : [Expense::STATUS_VERIFIED];

        return $this->transition($actor, $expense, $allowedFrom, Expense::STATUS_APPROVED, [
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ], 'finance_expense_approved', 'Approved expense', 'A Dispatcher must verify this expense before the Owner approves it.');
    }

    public function reject(User $actor, Expense $expense, string $reason): Expense
    {
        $isOwner = FinanceAccess::isOwner($actor);
        if (! $isOwner) {
            $this->authorize($actor, FinanceAccess::VERIFY_EXPENSE); // a Dispatcher may reject at the verification step
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceException('A reason is required to reject an expense.');
        }

        return $this->transition($actor, $expense,
            $isOwner ? [Expense::STATUS_SUBMITTED, Expense::STATUS_VERIFIED] : [Expense::STATUS_SUBMITTED],
            Expense::STATUS_REJECTED,
            ['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason],
            'finance_expense_rejected', "Rejected expense ({$reason})");
    }

    public function void(User $actor, Expense $expense, string $reason): Expense
    {
        $this->authorize($actor, FinanceAccess::APPROVE_EXPENSE);
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceException('A reason is required to void an expense.');
        }

        return $this->transition($actor, $expense, [Expense::STATUS_APPROVED], Expense::STATUS_VOIDED,
            ['voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason],
            'finance_expense_voided', "Voided expense ({$reason})");
    }

    /** Stores a receipt on the PRIVATE disk. The same file cannot be attached twice to one expense. */
    public function attachEvidence(User $actor, Expense $expense, UploadedFile $file): ExpenseEvidence
    {
        $canAttach = FinanceAccess::isOwner($actor)
            || $actor->can(FinanceAccess::VERIFY_EXPENSE)
            || ($actor->can(FinanceAccess::SUBMIT_EXPENSE) && (int) $expense->submitted_by === (int) $actor->id);
        if (! $canAttach) {
            throw new \Illuminate\Auth\Access\AuthorizationException('This action is unauthorized.');
        }

        if (! in_array($expense->fresh()->status, [Expense::STATUS_SUBMITTED, Expense::STATUS_VERIFIED], true)) {
            throw new FinanceException('Evidence cannot be added to a finalised expense.');
        }

        $config = config('finance.evidence');
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, $config['mimes'], true)) {
            throw new FinanceException('Evidence must be a JPG, PNG, WEBP or PDF file.');
        }
        if ($file->getSize() <= 0 || $file->getSize() > $config['max_kilobytes'] * 1024) {
            throw new FinanceException('Evidence files must be smaller than ' . intdiv($config['max_kilobytes'], 1024) . ' MB.');
        }

        $sha = hash_file('sha256', $file->getRealPath());
        if (ExpenseEvidence::where('expense_id', $expense->id)->where('sha256', $sha)->exists()) {
            throw new FinanceException('This file is already attached to the expense.');
        }

        $extension = $file->guessExtension() ?: 'bin';
        $path = $config['directory'] . '/' . $expense->id . '/' . Str::uuid() . '.' . $extension;
        Storage::disk($config['disk'])->putFileAs(dirname($path), $file, basename($path));

        try {
            return DB::transaction(function () use ($actor, $expense, $file, $config, $path, $mime, $sha) {
                $evidence = ExpenseEvidence::create([
                    'expense_id' => $expense->id,
                    'disk' => $config['disk'],
                    'path' => $path,
                    'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                    'mime_type' => $mime,
                    'size_bytes' => $file->getSize(),
                    'sha256' => $sha,
                    'uploaded_by' => $actor->id,
                ]);

                $this->audit($actor, 'finance_expense_evidence_added', $expense, $expense->reference_code, 'Added receipt evidence to expense');

                return $evidence;
            });
        } catch (\Throwable $e) {
            Storage::disk($config['disk'])->delete($path); // no orphaned file when the row is refused
            throw $e;
        }
    }

    private function transition(User $actor, Expense $expense, array $from, string $to, array $changes, string $action, string $description, ?string $wrongStatusMessage = null): Expense
    {
        return DB::transaction(function () use ($actor, $expense, $from, $to, $changes, $action, $description, $wrongStatusMessage) {
            $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw new FinanceException($wrongStatusMessage && in_array($locked->status, [Expense::STATUS_SUBMITTED], true)
                    ? $wrongStatusMessage
                    : "This expense is {$locked->status} and cannot be changed to {$to}.");
            }

            $locked->forceFill(['status' => $to] + $changes)->save();

            $this->audit($actor, $action, $locked, $locked->reference_code,
                $description . ' of ' . Money::of($locked->amount)->format());

            return $locked;
        });
    }
}
