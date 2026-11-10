<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ExpenseRequest;
use App\Models\Booking;
use App\Models\Finance\Expense;
use App\Models\Unit;
use App\Services\Finance\ExpenseEvidenceStreamer;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceException;
use App\Services\Finance\UnitFundLedger;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner > Finance > Expenses: record, review, approve, reject, correct and void expenses, and view their evidence.
 * Every write goes through ExpenseService, which re-checks authorization, the funding total and the evidence rule.
 */
class ExpenseController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    use ResolvesFinancePeriod;

    public function __construct(private readonly ExpenseService $expenses, private readonly UnitFundLedger $ledger)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);

        [$from, $to] = $this->resolvePeriod($request);
        $filters = $this->filters($request);

        $base = Expense::query()->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyFilters($base, $filters, withStatus: false);

        $listing = (clone $base)
            ->when($filters['status'], fn ($q, $status) => $status === 'pending' ? $q->whereIn('status', Expense::PENDING_STATUSES) : $q->where('status', $status))
            ->with(['unit:id,name,plate_number', 'booking:id,booking_code', 'fundings', 'evidence', 'correctedExpense:id,reference_code', 'correction:id,reference_code,corrects_expense_id,status',
                'submitter:id,name,first_name,middle_name,last_name,role_id', 'verifier:id,name,first_name,middle_name,last_name',
                'approver:id,name,first_name,middle_name,last_name', 'rejecter:id,name,first_name,middle_name,last_name', 'voider:id,name,first_name,middle_name,last_name'])
            ->orderByRaw("CASE status WHEN 'verified' THEN 0 WHEN 'submitted' THEN 1 ELSE 2 END")
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $activeUnits = Unit::whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'plate_number']);

        return view('superadmin.finance.expenses.index', [
            'expenses' => $listing,
            'summary' => $this->expenses->summary($base),
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
            'periodLabel' => $this->periodLabel($from, $to),
            'activeUnits' => $activeUnits,
            'filterUnits' => Unit::orderBy('name')->get(['id', 'name', 'archived_at']),
            'availableByUnit' => $activeUnits->mapWithKeys(fn (Unit $u) => [$u->id => $this->ledger->available($u->id)->toString()])->all(),
            'recentBookings' => Booking::query()->whereNotNull('assigned_unit_id')->latest('id')->limit(200)->get(['id', 'booking_code', 'assigned_unit_id']),
            'today' => now(config('finance.timezone'))->toDateString(),
            'createKey' => old('form') === 'create' ? old('idempotency_key', (string) Str::uuid()) : (string) Str::uuid(),
        ]);
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        return $this->attempt($request, 'create', function () use ($request) {
            $expense = $this->expenses->submit($request->user(), $request->expenseData(), $request->validated('idempotency_key'), $request->file('receipts', []));
            $this->flashShortfall($expense);

            return "Recorded expense {$expense->reference_code} for " . Money::of($expense->amount)->format() . '.'
                . ($expense->evidence->isEmpty() ? ' Attach the receipt before it can be approved.' : '');
        });
    }

    public function approve(Request $request, int $expense): RedirectResponse
    {
        $model = Expense::findOrFail($expense);

        return $this->attempt($request, "expense-{$model->id}", fn () => tap("Approved {$model->reference_code}.", fn () => $this->expenses->approve($request->user(), $model)));
    }

    public function reject(Request $request, int $expense): RedirectResponse
    {
        $model = Expense::findOrFail($expense);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Enter the reason for rejecting this expense.']);

        return $this->attempt($request, "expense-{$model->id}", fn () => tap("Rejected {$model->reference_code}.", fn () => $this->expenses->reject($request->user(), $model, $data['reason'])));
    }

    public function void(Request $request, int $expense): RedirectResponse
    {
        $model = Expense::findOrFail($expense);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Enter the reason for voiding this expense.']);

        return $this->attempt($request, "expense-{$model->id}", fn () => tap("Voided {$model->reference_code}. It stays in the history with the reason.", fn () => $this->expenses->void($request->user(), $model, $data['reason'])));
    }

    public function correct(ExpenseRequest $request, int $expense): RedirectResponse
    {
        $model = Expense::findOrFail($expense);

        return $this->attempt($request, "expense-{$model->id}", function () use ($request, $model) {
            $replacement = $this->expenses->correct($request->user(), $model, $request->expenseData(), $request->validated('reason'), $request->validated('idempotency_key'));
            $this->flashShortfall($replacement);

            return "Corrected {$model->reference_code}. The replacement is {$replacement->reference_code} and needs approval.";
        });
    }

    public function uploadEvidence(Request $request, int $expense): RedirectResponse
    {
        $this->authorize(FinanceAccess::VIEW);
        $model = Expense::findOrFail($expense);
        $evidence = config('finance.evidence');
        $request->validate([
            'receipts' => ['required', 'array', 'max:' . $evidence['max_files_per_upload']],
            'receipts.*' => ['file', 'mimes:' . implode(',', $evidence['extensions']), 'mimetypes:' . implode(',', $evidence['mimes']), 'max:' . $evidence['max_kilobytes']],
        ], ['receipts.required' => 'Choose at least one receipt file.', 'receipts.*.mimes' => 'Receipts must be JPG, PNG, WEBP or PDF files.', 'receipts.*.mimetypes' => 'Receipts must be JPG, PNG, WEBP or PDF files.']);

        return $this->attempt($request, "expense-{$model->id}", function () use ($request, $model) {
            foreach ($request->file('receipts') as $file) {
                $this->expenses->attachEvidence($request->user(), $model, $file);
            }

            return "Attached evidence to {$model->reference_code}.";
        });
    }

    public function evidence(Request $request, ExpenseEvidenceStreamer $streamer, int $expense, int $evidence): StreamedResponse
    {
        $this->authorize(FinanceAccess::VIEW);

        return $streamer->stream($request->user(), $expense, $evidence);
    }

    // ------------------------------------------------------------------

    private function filters(Request $request): array
    {
        $int = fn (string $key) => $request->filled($key) && ctype_digit((string) $request->query($key)) ? (int) $request->query($key) : null;
        $in = fn (string $key, array $allowed) => in_array($request->query($key), $allowed, true) ? $request->query($key) : null;

        return [
            'unit_id' => $int('unit_id'),
            'booking' => mb_substr(trim((string) $request->query('booking', '')), 0, 40) ?: null,
            'category' => $in('category', array_keys(config('finance.expense_categories'))),
            'status' => $in('status', ['pending', 'submitted', 'verified', 'approved', 'rejected', 'voided']),
            'scope' => $in('scope', array_keys(config('finance.expense_scopes'))),
            'funding' => $in('funding', array_keys(config('finance.funding_sources'))),
        ];
    }

    private function applyFilters(Builder $query, array $f, bool $withStatus = true): void
    {
        $query
            ->when($f['unit_id'], fn ($q, $id) => $q->where('unit_id', $id))
            ->when($f['booking'], fn ($q, $ref) => $q->whereHas('booking', fn ($b) => $b->where('booking_code', 'ilike', '%' . addcslashes($ref, '%_\\') . '%')))
            ->when($f['category'], fn ($q, $c) => $q->where('category', $c))
            ->when($f['scope'], fn ($q, $s) => $q->where('expense_scope', $s))
            ->when($f['funding'], fn ($q, $s) => $q->whereHas('fundings', fn ($w) => $w->where('source', $s)));
    }

    private function flashShortfall(Expense $expense): void
    {
        $shortfall = Money::of($expense->unit_fund_shortfall);
        if ($shortfall->isPositive()) {
            session()->flash('warning', "The unit-fund part of {$expense->reference_code} is {$shortfall->format()} more than the cash the unit had available. "
                . 'It was recorded as entered. If the extra was paid from personal money or by the company, correct the funding split. No Team Leader debt was created.');
        }
    }

    private function attempt(Request $request, string $form, \Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()])->withInput($request->except(['_token', 'receipts']) + ['form' => $form]);
        }

        return back()->with('success', $message);
    }
}
