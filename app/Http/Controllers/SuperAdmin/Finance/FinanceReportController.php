<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\DocumentGenerationService;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\FinanceReportWorkbook;
use App\Services\Finance\JobCollectionService;
use App\Support\Finance\FinanceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner > Finance > Finance Reports: page, printable report (Print / Save as PDF), PDF and Excel. All four use the same
 * filters and the same FinanceReportService data, so their totals are identical.
 */
class FinanceReportController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    use ResolvesFinancePeriod;

    public function __construct(private readonly FinanceReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);
        [$from, $to, $filters] = $this->inputs($request);

        return view('superadmin.finance.reports.index', [
            'r' => $this->reports->build($from, $to, $filters),
            'from' => $from,
            'to' => $to,
            'periodLabel' => $this->periodLabel($from, $to),
            'filters' => $filters,
            'query' => $this->query($from, $to, $filters),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'archived_at']),
            'people' => $this->reports->personnelOptions(),
            'paymentStatuses' => ['outstanding' => 'With an outstanding balance'] + JobCollectionService::COLLECTION_STATUSES,
        ]);
    }

    public function print(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);
        [$from, $to, $filters] = $this->inputs($request);

        return view('documents.finance-report', [
            'r' => $this->reports->build($from, $to, $filters),
            'forPdf' => false,
            'query' => $this->query($from, $to, $filters),
            'settings' => app(DocumentGenerationService::class)->documentSettings(),
            'generatedAt' => now(),
            'generatedBy' => $request->user()?->full_name ?: $request->user()?->name,
        ]);
    }

    public function pdf(Request $request, DocumentGenerationService $documents): Response
    {
        $this->authorize(FinanceAccess::VIEW);
        [$from, $to, $filters] = $this->inputs($request);
        $bytes = $documents->renderReportPdf('documents.finance-report', [
            'r' => $this->reports->build($from, $to, $filters),
            'forPdf' => true,
            'query' => $this->query($from, $to, $filters),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $this->filename($from, $to, 'pdf') . '"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function xlsx(Request $request, FinanceReportWorkbook $workbook): StreamedResponse
    {
        $this->authorize(FinanceAccess::VIEW);
        [$from, $to, $filters] = $this->inputs($request);
        $book = $workbook->build($this->reports->build($from, $to, $filters));

        return response()->streamDownload(fn () => $workbook->write($book, 'php://output'), $this->filename($from, $to, 'xlsx'), [
            'Content-Type' => FinanceReportWorkbook::MIME,
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: array} */
    private function inputs(Request $request): array
    {
        [$from, $to] = $this->resolvePeriod($request);
        if ($from->diffInDays($to) > 366) {
            $to = $from->addDays(366);
        }
        $pick = fn (string $key, int $max = 40) => is_string($request->query($key)) && $request->query($key) !== '' ? mb_substr($request->query($key), 0, $max) : null;

        return [$from, $to, [
            'unit_id' => ctype_digit((string) $request->query('unit_id')) ? (int) $request->query('unit_id') : null,
            'personnel' => $pick('personnel'),
            'payment_status' => $pick('payment_status'),
            'expense_status' => $pick('expense_status'),
            'statement_status' => $pick('statement_status'),
        ]];
    }

    private function query(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        return array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString()] + $filters, fn ($v) => $v !== null && $v !== '');
    }

    private function filename(CarbonImmutable $from, CarbonImmutable $to, string $ext): string
    {
        return "towmate-finance-report-{$from->toDateString()}-to-{$to->toDateString()}.{$ext}";
    }
}
