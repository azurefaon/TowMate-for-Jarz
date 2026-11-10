<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Finance\CommissionStatement;
use App\Models\Unit;
use App\Services\Finance\CommissionStatementService;
use App\Services\DocumentGenerationService;
use App\Services\Finance\FinanceException;
use App\Services\Finance\PayoutService;
use App\Support\Finance\FinanceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Owner > Finance > Commission Statements: list, prepare (select jobs), review, submit, approve, void and correct.
 * Every figure comes from CommissionCalculator through CommissionStatementService. Payouts are Phase 7.
 */
class CommissionStatementController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function __construct(
        private readonly CommissionStatementService $statements,
        private readonly PayoutService $payouts,
        private readonly DocumentGenerationService $documents,
    )
    {
    }

    public function index(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);

        $status = in_array($request->query('status'), array_keys(CommissionStatement::STATUS_LABELS), true) ? $request->query('status') : null;
        $unitId = $request->filled('unit_id') && ctype_digit((string) $request->query('unit_id')) ? (int) $request->query('unit_id') : null;

        $list = CommissionStatement::with(['unit:id,name', 'policy:id,version'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->orderByDesc('period_start')->orderBy('unit_id')->orderByDesc('revision')
            ->paginate(25)->withQueryString();

        return view('superadmin.finance.statements.index', [
            'statements' => $list,
            'status' => $status,
            'unitId' => $unitId,
            'units' => Unit::orderBy('name')->get(['id', 'name']),
            'defaultWeek' => CarbonImmutable::now(config('finance.timezone'))->startOfWeek(CarbonImmutable::MONDAY)->subWeek(),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize(FinanceAccess::VIEW);
        $data = $request->validate([
            'week' => ['required', 'date_format:Y-m-d'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
        ]);
        $week = CarbonImmutable::createFromFormat('Y-m-d', $data['week'], config('finance.timezone'))->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();

        $open = CommissionStatement::where('period_start', $week->toDateString())->where('unit_id', $data['unit_id'])
            ->whereIn('status', CommissionStatement::OPEN_STATUSES)->first();

        return view('superadmin.finance.statements.create', [
            'week' => $week,
            'unit' => Unit::findOrFail($data['unit_id']),
            'candidates' => $this->statements->candidates($week, (int) $data['unit_id']),
            'open' => $open,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(FinanceAccess::PREPARE_STATEMENTS);
        $data = $request->validate([
            'week' => ['required', 'date_format:Y-m-d'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'jobs' => ['required', 'array', 'min:1'],
            'jobs.*' => ['string', 'max:60'],
        ]);

        try {
            $statement = $this->statements->generate($request->user(), $data['week'], (int) $data['unit_id'], $data['jobs']);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()])->withInput();
        }

        return redirect()->route('superadmin.finance.statements.show', $statement->id)
            ->with('success', "Draft statement {$statement->reference_code} calculated for {$statement->job_count} job(s).");
    }

    public function show(int $statement): View
    {
        $this->authorize(FinanceAccess::VIEW);
        $model = CommissionStatement::with(['unit:id,name', 'policy', 'supersedes:id,reference_code'])->findOrFail($statement);

        return view('superadmin.finance.statements.show', [
            'statement' => $model,
            'review' => $model->isOpen() ? $this->statements->review($model) : null,
            'calc' => $model->isOpen() ? ($model->calculation['content'] ?? []) : ($model->approval_snapshot['calculation'] ?? $model->calculation['content'] ?? []),
            'integrity' => $model->approval_hash ? $this->statements->verifyIntegrity($model) : null,
            'corrections' => CommissionStatement::where('supersedes_statement_id', $model->id)->get(['id', 'reference_code', 'status']),
            'history' => AuditLog::where('entity_type', 'CommissionStatement')->where('entity_id', $model->id)->orderBy('id')->get(),
            'payouts' => $model->approval_snapshot ? $this->payouts->reconciliation($model) : null,
            'payoutKey' => (string) Str::uuid(),
        ]);
    }

    /** Printable statement for the browser (Print / Save as PDF). */
    public function print(int $statement): View
    {
        $this->authorize(FinanceAccess::VIEW);

        return view('documents.commission-statement', $this->documentData(CommissionStatement::findOrFail($statement)) + ['forPdf' => false]);
    }

    /** The same statement rendered to PDF by the existing TowMate document generator (Dompdf). */
    public function pdf(int $statement): Response
    {
        $this->authorize(FinanceAccess::VIEW);
        $model = CommissionStatement::findOrFail($statement);
        $bytes = $this->documents->renderReportPdf('documents.commission-statement', $this->documentData($model) + ['forPdf' => true]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $model->reference_code . '.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function documentData(CommissionStatement $model): array
    {
        $model->loadMissing(['unit:id,name', 'policy', 'supersedes:id,reference_code']);
        $final = in_array($model->status, [CommissionStatement::STATUS_APPROVED, CommissionStatement::STATUS_PAID, CommissionStatement::STATUS_VOIDED], true);

        return [
            'statement' => $model,
            'final' => $final,
            'calc' => $final ? ($model->approval_snapshot['calculation'] ?? []) : ($model->calculation['content'] ?? []),
            'policy' => $final ? ($model->approval_snapshot['policy'] ?? null) : ($model->calculation['content']['policy'] ?? null),
            'review' => $model->isOpen() ? $this->statements->review($model) : null,
            'payouts' => $model->approval_snapshot ? $this->payouts->reconciliation($model) : null,
            'settings' => $this->documents->documentSettings(),
            'generatedAt' => now(),
            'generatedBy' => auth()->user()?->full_name ?: auth()->user()?->name,
        ];
    }

    public function recalculate(Request $request, int $statement): RedirectResponse
    {
        return $this->act(fn () => $this->statements->recalculate($request->user(), CommissionStatement::findOrFail($statement)), 'Recalculated the draft from the current records.');
    }

    public function submit(Request $request, int $statement): RedirectResponse
    {
        return $this->act(fn () => $this->statements->submitForReview($request->user(), CommissionStatement::findOrFail($statement)), 'Submitted for review.');
    }

    public function returnToDraft(Request $request, int $statement): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->act(fn () => $this->statements->returnToDraft($request->user(), CommissionStatement::findOrFail($statement), $data['reason']), 'Returned to draft.');
    }

    public function approve(Request $request, int $statement): RedirectResponse
    {
        return $this->act(fn () => $this->statements->approve($request->user(), CommissionStatement::findOrFail($statement)), 'Approved. The statement is now locked.');
    }

    public function void(Request $request, int $statement): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->act(fn () => $this->statements->void($request->user(), CommissionStatement::findOrFail($statement), $data['reason']), 'Voided. The original statement and its approval record remain available.');
    }

    public function correct(Request $request, int $statement): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $new = $this->statements->correct($request->user(), CommissionStatement::findOrFail($statement), $data['reason']);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return redirect()->route('superadmin.finance.statements.show', $new->id)
            ->with('success', "The approved statement was voided and kept. Correction draft {$new->reference_code} (revision {$new->revision}) was created.");
    }

    private function act(\Closure $action, string $message): RedirectResponse
    {
        try {
            $statement = $action();
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return redirect()->route('superadmin.finance.statements.show', $statement->id)->with('success', $message);
    }
}
