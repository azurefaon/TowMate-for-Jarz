<?php

namespace App\Services\Finance;

use App\Models\Booking;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseEvidence;
use App\Models\Finance\ExpenseFunding;
use App\Models\Finance\FundEntry;
use App\Models\Unit;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Expenses: Team Leader submits -> Dispatcher verifies the receipt -> Owner approves.
 * The Owner may also record an expense and approve it directly (for example, a company-paid bill).
 *
 * - One expense may be split across funding sources (mixed funding). The splits must sum EXACTLY to the
 *   amount, so the cost is recorded once. Only the unit_fund split reduces a unit fund (see UnitFundLedger).
 * - Unit-fund spending above the cash available is allowed and recorded as stated. The shortfall is kept
 *   as a warning snapshot. No Team Leader debt is created.
 * - Evidence is required before verification and approval. Approved expenses are final: void or correct.
 */
class ExpenseService extends FinanceSupport
{
    public function __construct(private readonly UnitFundLedger $ledger)
    {
    }

    /**
     * @param array{unit_id:int, expense_scope?:string, booking_id?:?int, coverage_month?:?string, expense_date:string,
     *              category:string, description:string, amount:string|int, fundings?:array<string,string|int|null>,
     *              funding_source?:string, fund_entry_id?:?int} $data
     * @param  UploadedFile[]  $files  evidence to attach in the same submission
     */
    public function submit(User $actor, array $data, string $idempotencyKey, array $files = []): Expense
    {
        $this->authorize($actor, FinanceAccess::SUBMIT_EXPENSE);
        $prepared = $this->prepare($actor, $data);

        foreach ($files as $file) {
            $this->assertValidFile($file);
        }

        $fingerprint = [
            'unit_id' => $prepared['unit']->id,
            'amount' => $prepared['amount']->toString(),
            'expense_date' => $prepared['expense_date'],
            'category' => $prepared['category'],
        ];

        $expense = $this->createOnce(Expense::class, $idempotencyKey, $fingerprint, function () use ($actor, $prepared, $idempotencyKey) {
            return $this->createExpense($actor, $prepared, $idempotencyKey);
        });

        // Evidence is stored after the row exists. On an idempotent replay the files are not attached twice.
        if ($expense->wasRecentlyCreated) {
            foreach ($files as $file) {
                $this->attachEvidence($actor, $expense, $file);
            }
        }

        $fresh = $expense->fresh(['fundings', 'evidence']);
        $fresh->wasRecentlyCreated = $expense->wasRecentlyCreated; // callers tell a new submission from an idempotent replay

        return $fresh;
    }

    public function verify(User $actor, Expense $expense, ?string $note = null): Expense
    {
        $this->authorize($actor, FinanceAccess::VERIFY_EXPENSE);
        $this->assertHasEvidence($expense, 'verified');

        return $this->transition($actor, $expense, [Expense::STATUS_SUBMITTED], Expense::STATUS_VERIFIED, [
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'verification_note' => $this->clean($note, 1000),
        ], 'finance_expense_verified', 'Verified expense');
    }

    public function approve(User $actor, Expense $expense): Expense
    {
        $this->authorize($actor, FinanceAccess::APPROVE_EXPENSE);
        $this->assertHasEvidence($expense, 'approved');

        // Owner approval follows Dispatcher verification. The only shortcut is an expense the Owner entered personally.
        $allowedFrom = (int) $expense->submitted_by === (int) $actor->id
            ? Expense::PENDING_STATUSES
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
        $reason = $this->requireReason($reason, 'reject');

        return $this->transition($actor, $expense,
            $isOwner ? Expense::PENDING_STATUSES : [Expense::STATUS_SUBMITTED],
            Expense::STATUS_REJECTED,
            ['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason],
            'finance_expense_rejected', "Rejected expense ({$reason})");
    }

    public function void(User $actor, Expense $expense, string $reason): Expense
    {
        $this->authorize($actor, FinanceAccess::APPROVE_EXPENSE);
        $reason = $this->requireReason($reason, 'void');

        return $this->transition($actor, $expense, [Expense::STATUS_APPROVED], Expense::STATUS_VOIDED,
            ['voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason],
            'finance_expense_voided', "Voided expense ({$reason})");
    }

    /**
     * Owner correction. The original is closed (an approved expense is voided, a pending one is rejected) with the
     * reason, and a replacement linked by corrects_expense_id is recorded in the same transaction. Its evidence is
     * copied to new private files. The replacement is the Owner's own entry, so the Owner can approve it directly.
     */
    public function correct(User $actor, Expense $expense, array $data, string $reason, string $idempotencyKey): Expense
    {
        $this->authorize($actor, FinanceAccess::APPROVE_EXPENSE);
        $reason = $this->requireReason($reason, 'correct');
        $prepared = $this->prepare($actor, array_merge($data, ['unit_id' => $expense->unit_id])); // a correction never moves a cost to another unit

        $fingerprint = ['unit_id' => $prepared['unit']->id, 'amount' => $prepared['amount']->toString(), 'expense_date' => $prepared['expense_date'], 'category' => $prepared['category']];

        $copied = [];
        try {
            $replacement = $this->createOnce(Expense::class, $idempotencyKey, $fingerprint, function () use ($actor, $expense, $prepared, $reason, $idempotencyKey, &$copied) {
                $original = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

                if ($original->status === Expense::STATUS_APPROVED) {
                    $original->forceFill(['status' => Expense::STATUS_VOIDED, 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => "Corrected: {$reason}"])->save();
                } elseif ($original->isPending()) {
                    $original->forceFill(['status' => Expense::STATUS_REJECTED, 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => "Corrected: {$reason}"])->save();
                } else {
                    throw new FinanceException("A {$original->status} expense cannot be corrected.");
                }
                $this->audit($actor, $original->status === Expense::STATUS_VOIDED ? 'finance_expense_voided' : 'finance_expense_rejected',
                    $original, $original->reference_code, "Closed expense for correction ({$reason})");

                $replacement = $this->createExpense($actor, $prepared + ['corrects_expense_id' => $original->id], $idempotencyKey);

                foreach ($original->evidence as $evidence) {
                    $copied[] = $this->copyEvidence($actor, $evidence, $replacement);
                }

                return $replacement;
            });
        } catch (\Throwable $e) {
            foreach ($copied as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $e;
        }

        return $replacement->fresh(['fundings', 'evidence']);
    }

    /** Stores a receipt on the PRIVATE disk. The same file cannot be attached twice to one expense. */
    public function attachEvidence(User $actor, Expense $expense, UploadedFile $file): ExpenseEvidence
    {
        if (! $this->canAccess($actor, $expense)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        if (! in_array($expense->fresh()->status, Expense::PENDING_STATUSES, true)) {
            throw new FinanceException('Evidence cannot be added to a finalised expense.');
        }

        $this->assertValidFile($file);
        $config = config('finance.evidence');

        $sha = hash_file('sha256', $file->getRealPath());
        if (ExpenseEvidence::where('expense_id', $expense->id)->where('sha256', $sha)->exists()) {
            throw new FinanceException('This file is already attached to the expense.');
        }

        $mime = (string) $file->getMimeType();
        $extension = $this->extensionFor($mime);
        $path = $config['directory'] . '/' . $expense->id . '/' . Str::uuid() . '.' . $extension;
        Storage::disk($config['disk'])->putFileAs(dirname($path), $file, basename($path));

        try {
            return DB::transaction(function () use ($actor, $expense, $file, $config, $path, $mime, $sha) {
                $evidence = ExpenseEvidence::create([
                    'expense_id' => $expense->id,
                    'disk' => $config['disk'],
                    'path' => $path,
                    'original_name' => mb_substr(basename((string) $file->getClientOriginalName()), 0, 255),
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

    /**
     * Who may see an expense and its evidence: the Owner and Dispatchers (reviewers), and the Team Leader who
     * submitted it or leads its unit. Nobody else, including Drivers and Customers.
     */
    public function canAccess(User $actor, Expense $expense): bool
    {
        if (! FinanceAccess::isActive($actor)) {
            return false;
        }

        return match ((int) $actor->role_id) {
            FinanceAccess::ROLE_OWNER, FinanceAccess::ROLE_DISPATCHER => true,
            FinanceAccess::ROLE_TEAM_LEADER => (int) $expense->submitted_by === (int) $actor->id
                || (int) Unit::whereKey($expense->unit_id)->value('team_leader_id') === (int) $actor->id,
            default => false,
        };
    }

    /**
     * Unit-fund money still available for new spending: balance minus unit-fund expenses awaiting review.
     */
    public function availableUnitFunds(int $unitId): Money
    {
        return $this->ledger->available($unitId);
    }

    // ------------------------------------------------------------------

    private function prepare(User $actor, array $data): array
    {
        $amount = $this->parseAmount($data['amount'] ?? null, 'amount');

        $category = (string) ($data['category'] ?? '');
        if (! array_key_exists($category, config('finance.expense_categories'))) {
            throw new FinanceException('Choose a valid expense category.');
        }

        $description = $this->clean($data['description'] ?? null, 2000);
        if ($description === null) {
            throw new FinanceException('Describe what the expense was for.');
        }

        $expenseDate = $this->parseDate($data['expense_date'] ?? null);

        $unit = Unit::find((int) ($data['unit_id'] ?? 0)) ?? throw new FinanceException('The selected unit does not exist.');
        if ((int) $actor->role_id === FinanceAccess::ROLE_TEAM_LEADER && (int) $unit->team_leader_id !== (int) $actor->id) {
            throw new FinanceException('Team Leaders can only submit expenses for their own unit.');
        }

        $scope = (string) ($data['expense_scope'] ?? (empty($data['booking_id']) ? 'unit' : 'job'));
        if (! array_key_exists($scope, config('finance.expense_scopes'))) {
            throw new FinanceException('Choose whether this is a job expense or a unit / monthly expense.');
        }

        $bookingId = null;
        $coverageMonth = null;
        if ($scope === 'job') {
            $booking = $this->resolveBooking($data['booking_id'] ?? null);
            if (! $booking) {
                throw new FinanceException('A job expense needs a valid booking reference.');
            }
            $bookingId = $booking->id;
        } else {
            $month = (string) ($data['coverage_month'] ?? '');
            $coverageMonth = preg_match('/^\d{4}-\d{2}$/', $month)
                ? $month . '-01'
                : CarbonImmutable::parse($expenseDate)->startOfMonth()->toDateString();
        }

        $fundings = $this->parseFundings($data, $amount);

        $fundEntryId = null;
        if (! empty($data['fund_entry_id'])) {
            $fund = FundEntry::recorded()->whereKey($data['fund_entry_id'])->where('entry_type', FundEntry::TYPE_ISSUANCE)->first();
            if (! $fund || (int) $fund->unit_id !== $unit->id || ! isset($fundings[ExpenseFunding::SOURCE_UNIT_FUND])) {
                throw new FinanceException('Only unit-fund spending can be linked, and only to a recorded issuance for the same unit.');
            }
            $fundEntryId = $fund->id;
        }

        return [
            'unit' => $unit,
            'amount' => $amount,
            'category' => $category,
            'description' => $description,
            'expense_date' => $expenseDate,
            'expense_scope' => $scope,
            'booking_id' => $bookingId,
            'coverage_month' => $coverageMonth,
            'fundings' => $fundings,
            'fund_entry_id' => $fundEntryId,
        ];
    }

    /**
     * Accepts either fundings[source] = amount (mixed) or a single funding_source covering the full amount.
     *
     * @return array<string, Money>
     */
    private function parseFundings(array $data, Money $amount): array
    {
        $sources = config('finance.funding_sources');
        $fundings = [];

        if (! empty($data['fundings']) && is_array($data['fundings'])) {
            foreach ($data['fundings'] as $source => $value) {
                if ($value === null || $value === '' || $value === '0' || $value === '0.00') {
                    continue;
                }
                if (! array_key_exists($source, $sources)) {
                    throw new FinanceException('Choose a valid funding source.');
                }
                $fundings[$source] = $this->parseAmount($value, 'funding');
            }
        } elseif (! empty($data['funding_source'])) {
            if (! array_key_exists($data['funding_source'], $sources)) {
                throw new FinanceException('Choose a valid funding source.');
            }
            $fundings[$data['funding_source']] = $amount;
        }

        if ($fundings === []) {
            throw new FinanceException('Say how the expense was paid: issued unit funds, direct company payment or personal advance.');
        }

        $funded = Money::sum($fundings);
        if (! $funded->equals($amount)) {
            throw new FinanceException("The funding amounts ({$funded->format()}) must add up exactly to the expense amount ({$amount->format()}).");
        }

        return $fundings;
    }

    private function createExpense(User $actor, array $p, string $idempotencyKey): Expense
    {
        // Lock the unit so the "available funds" snapshot is consistent with concurrent submissions.
        Unit::whereKey($p['unit']->id)->lockForUpdate()->first();

        $unitPortion = $p['fundings'][ExpenseFunding::SOURCE_UNIT_FUND] ?? Money::zero();
        $shortfall = Money::zero();
        if ($unitPortion->isPositive()) {
            $available = $this->ledger->available($p['unit']->id);
            $covered = $available->isPositive() ? $available : Money::zero();
            if ($unitPortion->compareTo($covered) > 0) {
                $shortfall = $unitPortion->minus($covered);
            }
        }

        $expense = Expense::create([
            'idempotency_key' => $idempotencyKey,
            'unit_id' => $p['unit']->id,
            'booking_id' => $p['booking_id'],
            'expense_scope' => $p['expense_scope'],
            'fund_entry_id' => $p['fund_entry_id'],
            'corrects_expense_id' => $p['corrects_expense_id'] ?? null,
            'category' => $p['category'],
            'description' => $p['description'],
            'amount' => $p['amount']->toString(),
            'expense_date' => $p['expense_date'],
            'coverage_month' => $p['coverage_month'],
            'funding_source' => count($p['fundings']) > 1 ? 'mixed' : array_key_first($p['fundings']),
            'unit_fund_shortfall' => $shortfall->toString(),
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
        ]);

        foreach ($p['fundings'] as $source => $value) {
            ExpenseFunding::create([
                'expense_id' => $expense->id,
                'source' => $source,
                'amount' => $value->toString(),
                'fund_entry_id' => $source === ExpenseFunding::SOURCE_UNIT_FUND ? $p['fund_entry_id'] : null,
            ]);
        }

        $this->audit($actor, 'finance_expense_submitted', $expense, $expense->reference_code,
            "Recorded {$expense->categoryLabel()} expense of {$p['amount']->format()} for unit {$p['unit']->name}"
                . ($shortfall->isPositive() ? " (exceeds available unit funds by {$shortfall->format()})" : ''),
            [
                'amount' => $p['amount']->toString(),
                'category' => $p['category'],
                'scope' => $p['expense_scope'],
                'fundings' => array_map(fn (Money $m) => $m->toString(), $p['fundings']),
                'unit_fund_shortfall' => $shortfall->toString(),
                'corrects' => $p['corrects_expense_id'] ?? null,
            ]);

        return $expense;
    }

    private function copyEvidence(User $actor, ExpenseEvidence $evidence, Expense $target): array
    {
        $extension = pathinfo($evidence->path, PATHINFO_EXTENSION) ?: 'bin';
        $path = config('finance.evidence.directory') . '/' . $target->id . '/' . Str::uuid() . '.' . $extension;
        Storage::disk($evidence->disk)->copy($evidence->path, $path);

        ExpenseEvidence::create([
            'expense_id' => $target->id,
            'disk' => $evidence->disk,
            'path' => $path,
            'original_name' => $evidence->original_name,
            'mime_type' => $evidence->mime_type,
            'size_bytes' => $evidence->size_bytes,
            'sha256' => $evidence->sha256,
            'uploaded_by' => $actor->id,
        ]);

        return [$evidence->disk, $path];
    }

    private function transition(User $actor, Expense $expense, array $from, string $to, array $changes, string $action, string $description, ?string $wrongStatusMessage = null): Expense
    {
        return DB::transaction(function () use ($actor, $expense, $from, $to, $changes, $action, $description, $wrongStatusMessage) {
            $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw new FinanceException($wrongStatusMessage && $locked->status === Expense::STATUS_SUBMITTED
                    ? $wrongStatusMessage
                    : "This expense is {$locked->statusLabel()} and cannot be changed to {$to}.");
            }

            $locked->forceFill(['status' => $to] + $changes)->save();

            $this->audit($actor, $action, $locked, $locked->reference_code,
                $description . ' of ' . Money::of($locked->amount)->format());

            return $locked;
        });
    }

    private function assertHasEvidence(Expense $expense, string $step): void
    {
        if (! ExpenseEvidence::where('expense_id', $expense->id)->exists()) {
            throw new FinanceException("Attach the receipt or payment evidence before this expense can be {$step}.");
        }
    }

    private function assertValidFile(UploadedFile $file): void
    {
        $config = config('finance.evidence');
        if (! $file->isValid()) {
            throw new FinanceException('The file did not upload correctly. Try again.');
        }
        $mime = (string) $file->getMimeType();   // detected from the file content, not the name
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($mime, $config['mimes'], true) || ! in_array($extension, $config['extensions'], true)) {
            throw new FinanceException('Evidence must be a JPG, PNG, WEBP or PDF file.');
        }
        if ($file->getSize() <= 0 || $file->getSize() > $config['max_kilobytes'] * 1024) {
            throw new FinanceException('Evidence files must be smaller than ' . intdiv($config['max_kilobytes'], 1024) . ' MB.');
        }
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function resolveBooking(mixed $reference): ?Booking
    {
        if ($reference === null || $reference === '') {
            return null;
        }
        if ($reference instanceof Booking) {
            return $reference;
        }

        // Matched by the public booking code only (never the internal id). "123" is read as "0000123".
        $reference = trim((string) $reference);
        if (ctype_digit($reference) && strlen($reference) < 7) {
            $reference = str_pad($reference, 7, '0', STR_PAD_LEFT);
        }

        return Booking::where('booking_code', $reference)->first();
    }

    private function parseAmount(mixed $value, string $field): Money
    {
        if ($value === null || $value === '') {
            throw new FinanceException($field === 'amount' ? 'An amount is required.' : 'Enter each funding amount.');
        }
        try {
            $amount = Money::of(is_string($value) ? str_replace([',', '₱', ' '], '', $value) : $value);
        } catch (\InvalidArgumentException) {
            throw new FinanceException('Enter a valid amount with at most two decimal places.');
        }
        if (! $amount->isPositive()) {
            throw new FinanceException('Amounts must be greater than zero.');
        }
        if ($amount->compareTo('9999999999.99') > 0) {
            throw new FinanceException('The amount is too large.');
        }

        return $amount;
    }

    private function parseDate(mixed $value): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! strtotime($value)) {
            throw new FinanceException('A valid expense date is required.');
        }
        $date = CarbonImmutable::parse($value, config('finance.timezone'));
        if ($date->greaterThan(now(config('finance.timezone'))->endOfDay())) {
            throw new FinanceException('Expenses cannot be dated in the future.');
        }

        return $date->toDateString();
    }

    private function requireReason(string $reason, string $verb): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new FinanceException("A reason is required to {$verb} an expense.");
        }

        return mb_substr($reason, 0, 500);
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Expense summary over a filtered query, from NUMERIC aggregates (all statuses). Used by the Expenses page and the Overview. */
    public function summary(\Illuminate\Database\Eloquent\Builder $base): array
    {
        $ids = (clone $base)->select('id');

        $byStatus = \Illuminate\Support\Facades\DB::table('finance_expenses')->whereIn('id', $ids)->groupBy('status')
            ->selectRaw('status, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total')->get()->keyBy('status');

        $approvedBySource = \Illuminate\Support\Facades\DB::table('finance_expense_fundings as f')
            ->join('finance_expenses as e', 'e.id', '=', 'f.expense_id')
            ->whereIn('e.id', $ids)->where('e.status', Expense::STATUS_APPROVED)
            ->groupBy('f.source')->selectRaw('f.source, COALESCE(SUM(f.amount), 0) AS total')->get()->keyBy('source');

        $row = fn (string $status) => [
            'count' => (int) ($byStatus->get($status)->n ?? 0),
            'total' => Money::of((string) ($byStatus->get($status)->total ?? '0')),
        ];
        $source = fn (string $s) => Money::of((string) ($approvedBySource->get($s)->total ?? '0'));

        return [
            'submitted' => $row(Expense::STATUS_SUBMITTED),
            'verified' => $row(Expense::STATUS_VERIFIED),
            'approved' => $row(Expense::STATUS_APPROVED),
            'rejected' => $row(Expense::STATUS_REJECTED),
            'voided' => $row(Expense::STATUS_VOIDED),
            'approved_unit_fund' => $source('unit_fund'),
            'approved_company' => $source('company_direct'),
            'approved_personal' => $source('personal_advance'),
            'missing_evidence' => (clone $base)->whereIn('status', Expense::PENDING_STATUSES)->doesntHave('evidence')->count(),
        ];
    }

}
