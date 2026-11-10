<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Services\Finance\ExpenseEvidenceStreamer;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceException;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dispatcher > Expense Review: the middle step of Team Leader -> Dispatcher -> Owner.
 * Dispatchers check the receipt against the expense and verify or reject it. They cannot approve.
 */
class ExpenseReviewController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function __construct(private readonly ExpenseService $expenses)
    {
    }

    public function index(): View
    {
        $this->authorize(FinanceAccess::VERIFY_EXPENSE);

        $with = ['unit:id,name,plate_number', 'booking:id,booking_code', 'fundings', 'evidence', 'submitter:id,name,first_name,middle_name,last_name'];

        return view('admin-dashboard.pages.finance.expense-review', [
            'pending' => Expense::where('status', Expense::STATUS_SUBMITTED)->with($with)->orderBy('submitted_at')->get(),
            'recent' => Expense::whereNotNull('verified_at')->with(array_merge($with, ['verifier:id,name,first_name,middle_name,last_name']))
                ->orderByDesc('verified_at')->limit(20)->get(),
        ]);
    }

    public function verify(Request $request, int $expense): RedirectResponse
    {
        $this->authorize(FinanceAccess::VERIFY_EXPENSE);
        $model = Expense::findOrFail($expense);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->expenses->verify($request->user(), $model, $data['note'] ?? null),
            "Verified {$model->reference_code}. It is now waiting for Owner approval.");
    }

    public function reject(Request $request, int $expense): RedirectResponse
    {
        $this->authorize(FinanceAccess::VERIFY_EXPENSE);
        $model = Expense::findOrFail($expense);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Enter the reason for rejecting this expense.']);

        return $this->attempt(fn () => $this->expenses->reject($request->user(), $model, $data['reason']), "Rejected {$model->reference_code}.");
    }

    public function evidence(Request $request, ExpenseEvidenceStreamer $streamer, int $expense, int $evidence): StreamedResponse
    {
        $this->authorize(FinanceAccess::VERIFY_EXPENSE);

        return $streamer->stream($request->user(), $expense, $evidence);
    }

    private function attempt(\Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return back()->with('success', $message);
    }
}
