<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\TruckType;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\DocumentGenerationService;
use App\Services\ReportMetricsService;
use App\Services\ReportWorkbookBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportsController extends Controller
{
    protected const PERIODS = ['today', 'week', 'month', 'quarter'];

    protected const ACTIVITY_PDF_ROW_CAP = 1000;

    protected const BUCKETS = [
        'requested'            => ['requested'],
        'quoted'                => ['reviewed', 'quotation_sent'],
        'assigned'              => ['confirmed', 'accepted', 'assigned', 'scheduled_confirmed'],
        'in_progress'           => ['on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'],
        'waiting_verification'  => ['waiting_verification', 'payment_pending', 'payment_submitted'],
        'completed'             => ['completed'],
        'cancelled'             => ['cancelled'],
        'returned'              => ['returned'],
        'scheduled'             => ['scheduled'],
        'not_responding'        => ['not_responding'],
    ];

    protected const ACTIVITY_CATEGORIES = [
        'create' => 'Create',
        'update' => 'Update',
        'archive' => 'Archive',
        'restore' => 'Restore',
        'delete' => 'Delete',
        'dispatch' => 'Dispatch',
        'assignment_change' => 'Assignment Change',
        'quotation_change' => 'Quotation Change',
        'status_change' => 'Status Change',
        'system' => 'System',
    ];

    public function __construct(protected DocumentGenerationService $documents, protected ReportMetricsService $metrics)
    {
    }

    public function index(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);
        $filters = $this->resolveFilters($request);

        return view('superadmin.reports.index', array_merge(
            $this->buildSummary($start, $end, $filters),
            [
                'period' => $period,
                'customRange' => $customRange,
                'fromInput' => $request->query('from'),
                'toInput' => $request->query('to'),
                'filters' => $filters,
                'truckTypes' => TruckType::orderBy('name')->get(['id', 'name']),
                'bookingsTrend' => $this->buildBookingsTrend($start, $end, $filters),
                'truckTypePerformance' => $this->buildTruckTypePerformance($start, $end, $filters),
                'fleetPerformance' => $this->buildFleetPerformance($start, $end, $filters),
            ],
        ));
    }

    protected function buildBookingsTrend($start, $end, array $filters = []): array
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->endOfDay();

        $rows = Booking::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($filters['truck_type_id'] ?? null, fn ($q, $id) => $q->where('truck_type_id', $id))
            ->when(($filters['min_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '>=', $filters['min_amount']))
            ->when(($filters['max_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '<=', $filters['max_amount']))
            ->selectRaw('DATE(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $trend = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $trend[] = [
                'label' => $cursor->format('M j'),
                'total' => (int) ($rows[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $trend;
    }

    /**
     * Job counts are by creation date (bookings created in the period); revenue is
     * the service's completed-by-completion-date figure, so a truck type that only
     * has revenue from earlier bookings still appears and the column adds up to
     * the Revenue page.
     */
    protected function buildTruckTypePerformance($start, $end, array $filters = []): \Illuminate\Support\Collection
    {
        $base = fn () => Booking::whereBetween('created_at', [$start, $end])
            ->when($filters['truck_type_id'] ?? null, fn ($q, $id) => $q->where('truck_type_id', $id))
            ->when(($filters['min_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '>=', $filters['min_amount']))
            ->when(($filters['max_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '<=', $filters['max_amount']));

        $counts = $base()
            ->whereNotNull('truck_type_id')
            ->with('truckType')
            ->selectRaw(
                'truck_type_id, count(*) as total_jobs, '
                . 'sum(case when status = ? then 1 else 0 end) as completed_jobs, '
                . 'sum(case when status = ? then 1 else 0 end) as cancelled_jobs',
                ['completed', 'cancelled']
            )
            ->groupBy('truck_type_id')
            ->get()
            ->keyBy('truck_type_id');

        $revenue = $this->metrics->revenueByTruckType($start, $end, $filters)->keyBy('truck_type_id');

        return $counts->keys()->merge($revenue->keys())->unique()->values()
            ->map(function ($id) use ($counts, $revenue) {
                $row = $counts->get($id);

                return [
                    'truck_type_name' => $row?->truckType->name ?? ($revenue->get($id)['truck_type_name'] ?? 'Truck Type #' . $id),
                    'total_jobs' => (int) ($row->total_jobs ?? 0),
                    'completed_jobs' => (int) ($row->completed_jobs ?? 0),
                    'cancelled_jobs' => (int) ($row->cancelled_jobs ?? 0),
                    'revenue' => (float) ($revenue->get($id)['revenue'] ?? 0.0),
                ];
            })
            ->sortByDesc('total_jobs')
            ->values();
    }

    /** Same split as the truck-type table: job counts by creation date, revenue from the service. */
    protected function buildFleetPerformance($start, $end, array $filters = []): \Illuminate\Support\Collection
    {
        $base = fn () => Booking::whereBetween('created_at', [$start, $end])
            ->when($filters['truck_type_id'] ?? null, fn ($q, $id) => $q->where('truck_type_id', $id))
            ->when(($filters['min_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '>=', $filters['min_amount']))
            ->when(($filters['max_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '<=', $filters['max_amount']));

        $counts = $base()
            ->whereNotNull('assigned_unit_id')
            ->with('unit.truckType')
            ->selectRaw(
                'assigned_unit_id, count(*) as total_jobs, '
                . 'sum(case when status = ? then 1 else 0 end) as completed_jobs',
                ['completed']
            )
            ->groupBy('assigned_unit_id')
            ->get()
            ->keyBy('assigned_unit_id');

        $revenue = $this->metrics->unitPerformance($start, $end, $filters)->keyBy('unit_id');

        return $counts->keys()->merge($revenue->keys())->unique()->values()
            ->map(function ($id) use ($counts, $revenue) {
                $row = $counts->get($id);
                $perf = $revenue->get($id);

                return [
                    'unit_name' => $row?->display_unit_name ?? ($perf['unit_name'] ?? 'Unit #' . $id),
                    'truck_type_name' => $row?->unit->truckType->name ?? ($perf['truck_type_name'] ?? '—'),
                    'total_jobs' => (int) ($row->total_jobs ?? 0),
                    'completed_jobs' => (int) ($row->completed_jobs ?? 0),
                    'revenue' => (float) ($perf['revenue'] ?? 0.0),
                ];
            })
            ->sortByDesc('total_jobs')
            ->values();
    }

    public function revenue(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);
        $filters = $this->resolveFilters($request);

        $revenueSummary = $this->metrics->revenueSummary($start, $end, $filters);

        $revenueByTruckType = $this->metrics->revenueByTruckType($start, $end, $filters)
            ->map(fn (array $row) => ['truck_type_name' => $row['truck_type_name'], 'trips' => $row['jobs'], 'revenue' => $row['revenue']]);

        $topUnits = $this->metrics->unitPerformance($start, $end, $filters, 5)
            ->map(fn (array $row) => ['unit_name' => $row['unit_name'], 'trips' => $row['completed_jobs'], 'revenue' => $row['revenue']]);

        $revenueTrend = $this->buildRevenueTrend($start, $end, $filters);

        return view('superadmin.revenue.index', [
            'start' => Carbon::parse($start),
            'end' => Carbon::parse($end),
            'completedCount' => $revenueSummary['completedCount'],
            'financial' => [
                'totalRevenue' => $revenueSummary['totalRevenue'],
                'averagePerBooking' => $revenueSummary['averagePerBooking'],
                'vatCollected' => $revenueSummary['vatCollected'],
                'additionalFees' => $revenueSummary['additionalFees'],
                'cashReceived' => $revenueSummary['cashReceived'],
            ],
            'period' => $period,
            'customRange' => $customRange,
            'fromInput' => $request->query('from'),
            'toInput' => $request->query('to'),
            'filters' => $filters,
            'truckTypes' => TruckType::orderBy('name')->get(['id', 'name']),
            'revenueByTruckType' => $revenueByTruckType,
            'topUnits' => $topUnits,
            'revenueTrend' => $revenueTrend,
        ]);
    }

    /**
     * Daily completed revenue, by completion date: the service's dailyRevenue(), so
     * the trend adds up to the period total (same query, group allocation, filters
     * and app timezone).
     */
    protected function buildRevenueTrend($start, $end, array $filters = []): array
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->endOfDay();
        $days = (int) $start->copy()->diffInDays($end->copy()->startOfDay()) + 1;

        return collect($this->metrics->dailyRevenue($start, $days, $filters))
            ->map(fn (array $row) => ['label' => $row['date']->format('M j'), 'revenue' => $row['revenue']])
            ->all();
    }

    public function bookings(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);
        $filters = $this->resolveFilters($request);

        $bucket = (string) $request->query('bucket', '');
        $statuses = self::BUCKETS[$bucket] ?? null;

        $bookings = $this->bookingDetailQuery($start, $end, $filters, $statuses)->paginate(20)->withQueryString();

        // Status wording and colour come from the same presenter as Owner → Bookings.
        $presenter = app(\App\Services\OwnerBookingPresenter::class);
        $rows = $bookings->getCollection()->map(fn (Booking $booking) => [
            'booking' => $booking,
            'status' => ['label' => $presenter->statusLabel($booking->status), 'tone' => $presenter->statusTone($booking->status)],
        ]);

        return view('superadmin.reports.bookings', [
            'bookings'   => $bookings,
            'rows'       => $rows,
            'bucket'     => $bucket,
            'bucketLabel'=> self::bucketLabel($bucket),
            'start'      => Carbon::parse($start),
            'end'        => Carbon::parse($end),
            'period'     => $period,
        ]);
    }

    protected function bookingDetailQuery($start, $end, array $filters, ?array $statuses = null)
    {
        return Booking::with(['customer', 'truckType', 'unit'])
            ->whereBetween('created_at', [$start, $end])
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($filters['truck_type_id'], fn ($q, $id) => $q->where('truck_type_id', $id))
            ->when($filters['min_amount'] !== null, fn ($q) => $q->where('final_total', '>=', $filters['min_amount']))
            ->when($filters['max_amount'] !== null, fn ($q) => $q->where('final_total', '<=', $filters['max_amount']))
            ->orderByDesc('created_at');
    }

    public function activity(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);

        $category = (string) $request->query('category', '');
        $entityType = (string) $request->query('entity_type', '');
        $actorId = $request->query('user_id', '');
        $search = trim((string) $request->query('search', ''));

        $logs = AuditLog::with(['user.role'])
            ->whereIn('action', AuditLogService::BUSINESS_ACTIONS)
            ->whereBetween('created_at', [$start, $end])
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when($entityType !== '', fn ($q) => $q->where('entity_type', $entityType))
            ->when(filled($actorId), fn ($q) => $q->where('user_id', $actorId))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('description', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $logs->getCollection()->transform(function (AuditLog $log) {
            $log->activity_label = ucwords(str_replace('_', ' ', (string) $log->action));
            $log->entity_label = $log->entity_type ? trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $log->entity_type)) : null;
            $log->activity_changes = $this->explodeAuditLogRows($log);

            return $log;
        });

        return view('superadmin.reports.activity', [
            'logs' => $logs,
            // The range actually applied (the current month unless a custom range was chosen).
            'rangeStart' => Carbon::parse($start),
            'rangeEnd' => Carbon::parse($end),
            'period' => $period,
            'customRange' => $customRange,
            'fromInput' => $request->query('from'),
            'toInput' => $request->query('to'),
            'category' => $category,
            'entityType' => $entityType,
            'actorId' => $actorId,
            'search' => $search,
            'categories' => self::ACTIVITY_CATEGORIES,
            'entityTypes' => AuditLog::query()->select('entity_type')->distinct()->whereNotNull('entity_type')->whereIn('action', AuditLogService::BUSINESS_ACTIONS)->orderBy('entity_type')->pluck('entity_type'),
            'actors' => User::whereIn('role_id', [1, 2, 3])->orderBy('name')->get(['id', 'name', 'first_name', 'middle_name', 'last_name']),
        ]);
    }

    protected function formatAuditValue($value): string
    {
        if ($value === null || $value === '') {
            return '(none)';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                $value = $value->jsonSerialize();
            } elseif (method_exists($value, '__toString')) {
                return (string) $value;
            } else {
                $value = (array) $value;
            }
        }

        if (is_array($value)) {
            if (empty($value)) {
                return '(none)';
            }

            $isList = array_is_list($value);
            $hasNestedValues = collect($value)->contains(fn ($item) => is_array($item) || is_object($item));

            $parts = [];
            foreach ($value as $key => $item) {
                $formatted = $this->formatAuditValue($item);
                $parts[] = $isList ? $formatted : ucwords(str_replace('_', ' ', (string) $key)) . ': ' . $formatted;
            }

            $parts = array_slice($parts, 0, 12);
            $result = implode($hasNestedValues ? '; ' : ', ', $parts);

            return mb_strlen($result) > 300 ? mb_substr($result, 0, 297) . '…' : $result;
        }

        return (string) $value;
    }

    protected function explodeAuditLogRows(AuditLog $log): array
    {
        $old = $log->old_value ?? [];
        $new = $log->new_value ?? [];

        $humanize = fn ($key) => ucwords(str_replace('_', ' ', $key));

        $rows = [];

        if (empty($old) && ! empty($new)) {
            foreach ($new as $field => $value) {
                if ($value !== null && $value !== '') {
                    $rows[] = ['label' => $humanize($field), 'value' => $this->formatAuditValue($value)];
                }
            }
        } elseif (empty($new) && ! empty($old)) {
            foreach ($old as $field => $value) {
                if ($value !== null && $value !== '') {
                    $rows[] = ['label' => $humanize($field), 'value' => $this->formatAuditValue($value)];
                }
            }
        } elseif (! empty($old) || ! empty($new)) {
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
                if (array_key_exists($field, $old) && array_key_exists($field, $new)) {
                    $rows[] = [
                        'label' => $humanize($field),
                        'old' => $this->formatAuditValue($old[$field]),
                        'new' => $this->formatAuditValue($new[$field]),
                    ];
                } else {
                    $rows[] = [
                        'label' => $humanize($field),
                        'value' => $this->formatAuditValue($new[$field] ?? $old[$field] ?? null),
                    ];
                }
            }
        }

        return $rows ?: [['label' => null, 'value' => $log->description]];
    }

    protected static function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'requested'           => 'Requested',
            'quoted'               => 'Quoted',
            'assigned'             => 'Assigned',
            'in_progress'          => 'In Progress',
            'waiting_verification' => 'Waiting Verification',
            'completed'            => 'Completed',
            'cancelled'            => 'Cancelled',
            'returned'             => 'Returned',
            'scheduled'            => 'Scheduled',
            'not_responding'       => 'Not Responding',
            default                => 'All',
        };
    }

    protected static function activityCategoryLabel(?string $category): string
    {
        return self::ACTIVITY_CATEGORIES[$category] ?? ucfirst(str_replace('_', ' ', (string) $category));
    }

    protected function resolveRange(Request $request): array
    {
        $from = $request->query('from');
        $to   = $request->query('to');

        if (filled($from) && filled($to)) {
            try {
                $start = Carbon::parse($from)->startOfDay();
                $end   = Carbon::parse($to)->endOfDay();

                if ($start->lte($end) && $start->diffInDays($end) <= 366) {
                    return [$start, $end, 'custom', true];
                }
            } catch (\Throwable) {
            }
        }

        $period = $this->resolvePeriod($request);
        [$start, $end] = $this->dateRange($period);

        return [$start, $end, $period, false];
    }

    protected function resolveFilters(Request $request): array
    {
        $minAmount = $request->query('min_amount');
        $maxAmount = $request->query('max_amount');

        return [
            'truck_type_id' => $request->filled('truck_type_id') ? (int) $request->query('truck_type_id') : null,
            'min_amount' => is_numeric($minAmount) ? (float) $minAmount : null,
            'max_amount' => is_numeric($maxAmount) ? (float) $maxAmount : null,
        ];
    }

    public function export(Request $request)
    {
        [$start, $end, $period] = $this->resolveRange($request);
        $filters = $this->resolveFilters($request);
        $summary = $this->buildSummary($start, $end, $filters);
        $filename = 'towmate-report-' . $period . '-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($summary, $period) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['TowMate Reports Summary']);
            fputcsv($handle, ['Period', ucfirst($period)]);
            fputcsv($handle, ['Date range', $summary['start']->toDateString() . ' to ' . $summary['end']->toDateString()]);
            fputcsv($handle, []);
            fputcsv($handle, ['Metric', 'Value']);
            fputcsv($handle, ['Total Bookings (created in period)', $summary['totalBookings']]);
            fputcsv($handle, ['Completed revenue (by completion date)', number_format($summary['totalRevenue'], 2)]);
            fputcsv($handle, ['Completed jobs (by completion date)', $summary['completedJobsCount']]);
            fputcsv($handle, ['Completed (created in period)', $summary['completedCount']]);
            fputcsv($handle, ['Pending (created in period)', $summary['pendingCount']]);
            fputcsv($handle, ['Cancelled (created in period)', $summary['cancelledCount']]);
            fputcsv($handle, []);
            fputcsv($handle, ['Status', 'Count']);
            foreach ($summary['pipeline'] as $row) {
                fputcsv($handle, [$row['label'], $row['count']]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    protected const REPORT_SECTIONS = [
        'all' => 'Reports Summary',
        'booking' => 'Booking Report',
        'vehicle' => 'Vehicle Report',
        'financial' => 'Financial Report',
    ];

    public function exportSummaryPdf(Request $request)
    {
        [$start, $end, $period] = $this->resolveRange($request);
        $filters = $this->resolveFilters($request);
        $summary = $this->buildSummary($start, $end, $filters);

        $section = (string) $request->query('section', 'all');
        if (! array_key_exists($section, self::REPORT_SECTIONS)) {
            $section = 'all';
        }

        $html = $this->documents->renderReportPdf('documents.reports.summary-pdf', [
            'summary' => $summary,
            'period' => $period,
            'filters' => $filters,
            'section' => $section,
            'sectionTitle' => self::REPORT_SECTIONS[$section],
            'truckTypeName' => $filters['truck_type_id'] ? TruckType::find($filters['truck_type_id'])?->name : null,
        ]);

        $filename = 'towmate-' . $section . '-report-' . $period . '-' . now()->format('Y-m-d') . '.pdf';

        return response($html, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function exportSummaryXlsx(Request $request, ReportWorkbookBuilder $workbooks)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'period' => ['nullable', 'in:' . implode(',', self::PERIODS)],
            'section' => ['nullable', 'in:' . implode(',', array_keys(self::REPORT_SECTIONS))],
            'truck_type_id' => ['nullable', 'integer'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        [$start, $end, $period] = $this->resolveRange($request);
        $filters = $this->resolveFilters($request);
        $summary = $this->buildSummary($start, $end, $filters);
        $section = (string) $request->query('section', 'all');

        $truckTypeName = $filters['truck_type_id'] ? TruckType::find($filters['truck_type_id'])?->name : null;

        $detailQuery = in_array($section, ['all', 'booking'], true)
            ? $this->bookingDetailQuery($start, $end, $filters)
            : null;

        $spreadsheet = $workbooks->build($summary, $section, $period, $filters, $truckTypeName, $detailQuery);
        $filename = 'towmate-' . $section . '-report-' . $period . '-' . now()->format('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($workbooks, $spreadsheet) {
            $workbooks->write($spreadsheet, 'php://output');
        }, $filename, ['Content-Type' => ReportWorkbookBuilder::MIME]);
    }

    public function exportActivityPdf(Request $request)
    {
        [$start, $end, $period] = $this->resolveRange($request);

        $category = (string) $request->query('category', '');
        $entityType = (string) $request->query('entity_type', '');
        $actorId = $request->query('user_id', '');
        $search = trim((string) $request->query('search', ''));

        $query = AuditLog::with('user')
            ->whereIn('action', AuditLogService::BUSINESS_ACTIONS)
            ->whereBetween('created_at', [$start, $end])
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when($entityType !== '', fn ($q) => $q->where('entity_type', $entityType))
            ->when(filled($actorId), fn ($q) => $q->where('user_id', $actorId))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('description', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%");
                });
            })
            ->latest();

        $total = $query->count();
        $logs = $query->limit(self::ACTIVITY_PDF_ROW_CAP)->get();

        $html = $this->documents->renderReportPdf('documents.reports.activity-pdf', [
            'logs' => $logs,
            'total' => $total,
            'truncated' => $total > self::ACTIVITY_PDF_ROW_CAP,
            'rowCap' => self::ACTIVITY_PDF_ROW_CAP,
            'start' => Carbon::parse($start),
            'end' => Carbon::parse($end),
            'period' => $period,
            'categoryLabel' => $category !== '' ? self::activityCategoryLabel($category) : null,
        ]);

        $filename = 'towmate-activity-log-' . $period . '-' . now()->format('Y-m-d') . '.pdf';

        return response($html, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    protected function resolvePeriod(Request $request): string
    {
        $period = (string) $request->input('period', 'month');

        return in_array($period, self::PERIODS, true) ? $period : 'month';
    }

    protected function dateRange(string $period): array
    {
        return match ($period) {
            'today' => [today(), now()->endOfDay()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'quarter' => [now()->copy()->subMonths(3)->startOfDay(), now()->endOfDay()],
            default => [now()->startOfWeek(), now()->endOfWeek()],
        };
    }

    protected function buildSummary($start, $end, array $filters = []): array
    {
        $base = fn () => Booking::whereBetween('created_at', [$start, $end])
            ->when($filters['truck_type_id'] ?? null, fn ($q, $id) => $q->where('truck_type_id', $id))
            ->when(($filters['min_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '>=', $filters['min_amount']))
            ->when(($filters['max_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '<=', $filters['max_amount']));

        $totalBookings = $base()->count();
        // Revenue is never summed here: it comes from the shared service (completed jobs, by
        // completion date, with the group fee/discount allocation), like Revenue and the Dashboard.
        $revenue = $this->metrics->revenueSummary($start, $end, $filters);
        $totalRevenue = $revenue['totalRevenue'];
        $completedCount = $base()->where('status', 'completed')->count();
        $pendingCount = $base()->where('status', 'requested')->count();
        $cancelledCount = $base()->where('status', 'cancelled')->count();

        $byStatus = $base()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pipeline = collect(self::BUCKETS)->map(function (array $statuses, string $key) use ($byStatus) {
            return [
                'key'   => $key,
                'label' => self::bucketLabel($key),
                'count' => (int) collect($statuses)->sum(fn ($status) => $byStatus->get($status, 0)),
            ];
        })->values();

        $accountedFor = $pipeline->sum('count');
        $otherCount = max(0, $totalBookings - $accountedFor);
        if ($otherCount > 0) {
            $pipeline->push(['key' => '', 'label' => 'Other', 'count' => $otherCount]);
        }

        $pipeline = $pipeline->map(function (array $row) use ($totalBookings) {
            $row['share'] = $totalBookings > 0 ? ($row['count'] / $totalBookings) * 100 : 0.0;

            return $row;
        });

        $vehicleReport = $this->metrics->unitPerformance($start, $end, $filters)
            ->map(fn (array $row) => ['unit_name' => $row['unit_name'], 'trips' => $row['completed_jobs'], 'revenue' => $row['revenue']])
            ->sortByDesc('trips')
            ->values();

        $financial = [
            'totalRevenue' => $revenue['totalRevenue'],
            'additionalFees' => $revenue['additionalFees'],
            'vatCollected' => $revenue['vatCollected'],
            'cashReceived' => $revenue['cashReceived'],
            'averagePerBooking' => $revenue['averagePerBooking'],
        ];

        return [
            'start' => Carbon::parse($start),
            'end' => Carbon::parse($end),
            'totalBookings' => $totalBookings,
            'totalRevenue' => $totalRevenue,
            'completedCount' => $completedCount,
            'completedJobsCount' => $revenue['completedCount'],
            'pendingCount' => $pendingCount,
            'cancelledCount' => $cancelledCount,
            'completionRate' => $totalBookings > 0 ? ($completedCount / $totalBookings) * 100 : 0.0,
            'pipeline' => $pipeline,
            'vehicleReport' => $vehicleReport,
            'totalVehiclesUsed' => $vehicleReport->count(),
            'totalTrips' => $vehicleReport->sum('trips'),
            'financial' => $financial,
        ];
    }
}
