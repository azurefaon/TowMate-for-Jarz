<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Finance\ClientReviewItem;
use App\Models\Finance\CommissionPolicy;
use App\Services\DocumentGenerationService;
use App\Services\Finance\ClientReviewService;
use App\Services\Finance\FinanceException;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Owner > Finance > Client Review: pending client decisions, requested revisions and remarks, plus a summary download. */
class ClientReviewController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function __construct(private readonly ClientReviewService $review)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);
        $status = array_key_exists((string) $request->query('status'), ClientReviewItem::STATUSES) ? $request->query('status') : null;

        return view('superadmin.finance.client-review.index', [
            'items' => ClientReviewItem::with('updater')->when($status, fn ($q) => $q->where('status', $status))->orderByRaw("CASE status WHEN 'closed' THEN 1 ELSE 0 END")->orderBy('id')->get(),
            'counts' => ClientReviewItem::selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status'),
            'status' => $status,
            'policy' => CommissionPolicy::where('status', 'active')->first() ?? CommissionPolicy::orderByDesc('version')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_POLICIES);
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ClientReviewItem::KINDS))],
            'title' => ['required', 'string', 'max:200'],
            'question' => ['nullable', 'string', 'max:4000'],
            'blocks' => ['nullable', 'string', 'max:2000'],
            'requested_revision' => ['nullable', 'string', 'max:4000'],
            'owner_remarks' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $item = $this->review->create($request->user(), $data);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "Added {$item->reference_code}.");
    }

    public function update(Request $request, int $item): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_POLICIES);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(ClientReviewItem::STATUSES))],
            'client_response' => ['nullable', 'string', 'max:4000'],
            'requested_revision' => ['nullable', 'string', 'max:4000'],
            'owner_remarks' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $model = $this->review->update($request->user(), ClientReviewItem::findOrFail($item), $data);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return back()->with('success', "Saved {$model->reference_code}. Recording an answer does not change any commission rule or financial record.");
    }

    public function print(Request $request, DocumentGenerationService $documents): View
    {
        $this->authorize(FinanceAccess::VIEW);

        return view('documents.client-review', $this->documentData() + ['forPdf' => false, 'settings' => $documents->documentSettings(),
            'generatedAt' => now(), 'generatedBy' => $request->user()?->full_name ?: $request->user()?->name]);
    }

    public function pdf(DocumentGenerationService $documents): Response
    {
        $this->authorize(FinanceAccess::VIEW);
        $bytes = $documents->renderReportPdf('documents.client-review', $this->documentData() + ['forPdf' => true]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="towmate-finance-client-review-' . now()->format('Y-m-d') . '.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function documentData(): array
    {
        return [
            'items' => ClientReviewItem::orderByRaw("CASE status WHEN 'closed' THEN 1 ELSE 0 END")->orderBy('id')->get(),
            'policy' => CommissionPolicy::where('status', 'active')->first() ?? CommissionPolicy::orderByDesc('version')->first(),
            'history' => AuditLog::whereIn('action', ['finance_client_review_created', 'finance_client_review_updated'])->latest('id')->limit(30)->get(),
        ];
    }
}
