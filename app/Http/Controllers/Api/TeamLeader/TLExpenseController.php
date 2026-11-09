<?php

namespace App\Http\Controllers\Api\TeamLeader;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ExpenseRequest;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseFunding;
use App\Services\Finance\ExpenseEvidenceStreamer;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceException;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Team Leader app: submit expenses for the Team Leader's own unit, with receipt photos. This is the first step
 * of Team Leader -> Dispatcher -> Owner. Team Leaders never verify or approve.
 */
class TLExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses)
    {
    }

    public function options(Request $request): JsonResponse
    {
        $unit = $request->user()->unit;

        return response()->json([
            'success' => true,
            'data' => [
                'unit' => $unit ? ['id' => $unit->id, 'name' => $unit->name, 'available_unit_funds' => $this->expenses->availableUnitFunds($unit->id)->toString()] : null,
                'categories' => collect(config('finance.expense_categories'))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'funding_sources' => collect(config('finance.funding_sources'))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'expense_scopes' => collect(config('finance.expense_scopes'))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'evidence' => ['max_kilobytes' => config('finance.evidence.max_kilobytes'), 'types' => config('finance.evidence.extensions')],
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $unitId = optional($user->unit)->id;

        $expenses = Expense::query()
            ->where(fn ($q) => $q->where('submitted_by', $user->id)->when($unitId, fn ($w) => $w->orWhere('unit_id', $unitId)))
            ->with(['fundings', 'evidence', 'booking:id,booking_code'])
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'data' => $expenses->map(fn (Expense $e) => $this->present($e))->values()]);
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        if (! $request->user()->unit) {
            return response()->json(['success' => false, 'message' => 'You are not assigned to a unit.'], 422);
        }

        try {
            $expense = $this->expenses->submit($request->user(), $request->expenseData(), $request->validated('idempotency_key'), $request->file('receipts', []));
        } catch (FinanceException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $shortfall = Money::of($expense->unit_fund_shortfall);

        return response()->json([
            'success' => true,
            'message' => 'Expense submitted for Dispatcher review.',
            'warning' => $shortfall->isPositive()
                ? "The unit-fund amount is {$shortfall->format()} more than the cash available. It was recorded as entered."
                : null,
            'data' => $this->present($expense),
        ], $expense->wasRecentlyCreated ? 201 : 200);
    }

    public function uploadEvidence(Request $request, int $expense): JsonResponse
    {
        $evidence = config('finance.evidence');
        $request->validate([
            'receipt' => ['required', 'file', 'mimes:' . implode(',', $evidence['extensions']), 'mimetypes:' . implode(',', $evidence['mimes']), 'max:' . $evidence['max_kilobytes']],
        ]);
        $model = Expense::findOrFail($expense);

        try {
            $this->expenses->attachEvidence($request->user(), $model, $request->file('receipt'));
        } catch (FinanceException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return response()->json(['success' => false, 'message' => 'You cannot add evidence to this expense.'], 403);
        }

        return response()->json(['success' => true, 'data' => $this->present($model->fresh(['fundings', 'evidence', 'booking']))]);
    }

    public function evidence(Request $request, ExpenseEvidenceStreamer $streamer, int $expense, int $evidence): StreamedResponse
    {
        return $streamer->stream($request->user(), $expense, $evidence);
    }

    private function present(Expense $e): array
    {
        return [
            'id' => $e->id,
            'reference' => $e->reference_code,
            'status' => $e->status,
            'status_label' => $e->statusLabel(),
            'expense_scope' => $e->expense_scope,
            'booking_reference' => $e->booking?->booking_code,
            'coverage_month' => $e->coverage_month?->format('Y-m'),
            'expense_date' => $e->expense_date?->toDateString(),
            'category' => $e->category,
            'category_label' => $e->categoryLabel(),
            'description' => $e->description,
            'amount' => Money::of($e->amount)->toString(),
            'fundings' => $e->fundings->map(fn (ExpenseFunding $f) => ['source' => $f->source, 'label' => $f->label(), 'amount' => Money::of($f->amount)->toString()])->values(),
            'unit_fund_shortfall' => Money::of($e->unit_fund_shortfall)->toString(),
            'evidence_count' => $e->evidence->count(),
            'rejection_reason' => $e->rejection_reason,
            'submitted_at' => $e->submitted_at?->toIso8601String(),
        ];
    }
}
