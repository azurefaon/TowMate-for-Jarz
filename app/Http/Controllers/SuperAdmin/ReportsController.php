<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\TruckType;
use App\Models\User;
use App\Services\DocumentGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportsController extends Controller
{
    protected const PERIODS = ['today', 'week', 'month', 'quarter'];

    protected const ACTIVITY_PDF_ROW_CAP = 1000;

    // Status buckets shared between the pipeline breakdown and the drill-down
    // list, so a clicked count always resolves to exactly the same bookings.
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
        'login' => 'Login',
        'logout' => 'Logout',
        'login_failed' => 'Failed Login',
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

    public function __construct(protected DocumentGenerationService $documents)
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
            ],
        ));
    }

    public function bookings(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);
        $filters = $this->resolveFilters($request);

        $bucket = (string) $request->query('bucket', '');
        $statuses = self::BUCKETS[$bucket] ?? null;

        $query = Booking::with(['customer', 'truckType'])
            ->whereBetween('created_at', [$start, $end])
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($filters['truck_type_id'], fn ($q, $id) => $q->where('truck_type_id', $id))
            ->when($filters['min_amount'] !== null, fn ($q) => $q->where('final_total', '>=', $filters['min_amount']))
            ->when($filters['max_amount'] !== null, fn ($q) => $q->where('final_total', '<=', $filters['max_amount']))
            ->orderByDesc('created_at');

        $bookings = $query->paginate(20)->withQueryString();

        return view('superadmin.reports.bookings', [
            'bookings'   => $bookings,
            'bucket'     => $bucket,
            'bucketLabel'=> self::bucketLabel($bucket),
            'start'      => Carbon::parse($start),
            'end'        => Carbon::parse($end),
            'period'     => $period,
        ]);
    }

    /**
     * Detailed, filterable activity log — replaces the old standalone Audit
     * Logs page. Lives as a tab inside Reports.
     */
    public function activity(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);

        $category = (string) $request->query('category', '');
        $entityType = (string) $request->query('entity_type', '');
        $actorId = $request->query('user_id', '');
        $search = trim((string) $request->query('search', ''));

        $logs = AuditLog::with('user')
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

        return view('superadmin.reports.activity', [
            'logs' => $logs,
            'period' => $period,
            'customRange' => $customRange,
            'fromInput' => $request->query('from'),
            'toInput' => $request->query('to'),
            'category' => $category,
            'entityType' => $entityType,
            'actorId' => $actorId,
            'search' => $search,
            'categories' => self::ACTIVITY_CATEGORIES,
            'entityTypes' => AuditLog::query()->select('entity_type')->distinct()->whereNotNull('entity_type')->orderBy('entity_type')->pluck('entity_type'),
            'actors' => User::whereIn('role_id', [1, 2, 3])->orderBy('name')->get(['id', 'name', 'first_name', 'middle_name', 'last_name']),
        ]);
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

    /**
     * Resolves the active date range from either a validated custom from/to
     * pair or the fixed period buckets, returning [start, end, period, isCustom].
     */
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
                // fall through to period-based range
            }
        }

        $period = $this->resolvePeriod($request);
        [$start, $end] = $this->dateRange($period);

        return [$start, $end, $period, false];
    }

    /**
     * Vehicle-type and amount filters shared by the summary and bookings
     * drill-down views.
     */
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
            fputcsv($handle, ['Total Bookings', $summary['totalBookings']]);
            fputcsv($handle, ['Total Revenue', number_format($summary['totalRevenue'], 2)]);
            fputcsv($handle, ['Completed', $summary['completedCount']]);
            fputcsv($handle, ['Pending', $summary['pendingCount']]);
            fputcsv($handle, ['Cancelled', $summary['cancelledCount']]);
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

    public function exportActivityPdf(Request $request)
    {
        [$start, $end, $period] = $this->resolveRange($request);

        $category = (string) $request->query('category', '');
        $entityType = (string) $request->query('entity_type', '');
        $actorId = $request->query('user_id', '');
        $search = trim((string) $request->query('search', ''));

        $query = AuditLog::with('user')
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
        $totalRevenue = (float) $base()->where('status', 'completed')->sum('final_total');
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

        $vehicleReport = $base()
            ->whereNotNull('assigned_unit_id')
            ->with('unit')
            ->selectRaw('assigned_unit_id, count(*) as trips, sum(final_total) as revenue')
            ->groupBy('assigned_unit_id')
            ->orderByDesc('trips')
            ->get()
            ->map(fn ($row) => [
                'unit_name' => $row->unit->name ?? 'Unit #' . $row->assigned_unit_id,
                'trips' => (int) $row->trips,
                'revenue' => (float) $row->revenue,
            ]);

        $financial = [
            'totalRevenue' => $totalRevenue,
            'additionalFees' => (float) $base()->sum('additional_fee'),
            'vatCollected' => (float) $base()->sum('vat_amount'),
            'cashReceived' => (float) $base()->sum('cash_received'),
            'averagePerBooking' => $completedCount > 0 ? $totalRevenue / $completedCount : 0.0,
        ];

        return [
            'start' => Carbon::parse($start),
            'end' => Carbon::parse($end),
            'totalBookings' => $totalBookings,
            'totalRevenue' => $totalRevenue,
            'completedCount' => $completedCount,
            'pendingCount' => $pendingCount,
            'cancelledCount' => $cancelledCount,
            'pipeline' => $pipeline,
            'vehicleReport' => $vehicleReport,
            'totalVehiclesUsed' => $vehicleReport->count(),
            'totalTrips' => $vehicleReport->sum('trips'),
            'financial' => $financial,
        ];
    }
}
