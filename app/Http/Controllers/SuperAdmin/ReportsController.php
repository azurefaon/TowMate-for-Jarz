<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\TruckType;
use App\Models\User;
use App\Services\DocumentGenerationService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        'security' => 'Security',
    ];

    protected const SECURITY_CATEGORIES = ['login', 'logout', 'login_failed', 'security'];

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

    protected function buildTruckTypePerformance($start, $end, array $filters = []): \Illuminate\Support\Collection
    {
        $base = fn () => Booking::whereBetween('created_at', [$start, $end])
            ->when($filters['truck_type_id'] ?? null, fn ($q, $id) => $q->where('truck_type_id', $id))
            ->when(($filters['min_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '>=', $filters['min_amount']))
            ->when(($filters['max_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '<=', $filters['max_amount']));

        return $base()
            ->whereNotNull('truck_type_id')
            ->with('truckType')
            ->selectRaw(
                'truck_type_id, count(*) as total_jobs, '
                . 'sum(case when status = ? then 1 else 0 end) as completed_jobs, '
                . 'sum(case when status = ? then 1 else 0 end) as cancelled_jobs, '
                . 'sum(case when status = ? then final_total else 0 end) as revenue',
                ['completed', 'cancelled', 'completed']
            )
            ->groupBy('truck_type_id')
            ->orderByDesc('total_jobs')
            ->get()
            ->map(fn ($row) => [
                'truck_type_name' => $row->truckType->name ?? 'Truck Type #' . $row->truck_type_id,
                'total_jobs' => (int) $row->total_jobs,
                'completed_jobs' => (int) $row->completed_jobs,
                'cancelled_jobs' => (int) $row->cancelled_jobs,
                'revenue' => (float) $row->revenue,
            ]);
    }

    protected function buildFleetPerformance($start, $end, array $filters = []): \Illuminate\Support\Collection
    {
        $base = fn () => Booking::whereBetween('created_at', [$start, $end])
            ->when($filters['truck_type_id'] ?? null, fn ($q, $id) => $q->where('truck_type_id', $id))
            ->when(($filters['min_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '>=', $filters['min_amount']))
            ->when(($filters['max_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '<=', $filters['max_amount']));

        return $base()
            ->whereNotNull('assigned_unit_id')
            ->with('unit.truckType')
            ->selectRaw(
                'assigned_unit_id, count(*) as total_jobs, '
                . 'sum(case when status = ? then 1 else 0 end) as completed_jobs, '
                . 'sum(case when status = ? then final_total else 0 end) as revenue',
                ['completed', 'completed']
            )
            ->groupBy('assigned_unit_id')
            ->orderByDesc('total_jobs')
            ->get()
            ->map(fn ($row) => [
                'unit_name' => $row->unit->name ?? 'Unit #' . $row->assigned_unit_id,
                'truck_type_name' => $row->unit->truckType->name ?? '—',
                'total_jobs' => (int) $row->total_jobs,
                'completed_jobs' => (int) $row->completed_jobs,
                'revenue' => (float) $row->revenue,
            ]);
    }

    public function revenue(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);
        $filters = $this->resolveFilters($request);

        $summary = $this->buildSummary($start, $end, $filters);

        $revenueByTruckType = Booking::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->whereNotNull('truck_type_id')
            ->with('truckType')
            ->selectRaw('truck_type_id, count(*) as trips, sum(final_total) as revenue')
            ->groupBy('truck_type_id')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'truck_type_name' => $row->truckType->name ?? 'Truck Type #' . $row->truck_type_id,
                'trips' => (int) $row->trips,
                'revenue' => (float) $row->revenue,
            ]);

        $revenueTrend = $this->buildRevenueTrend($start, $end, $filters);

        return view('superadmin.revenue.index', array_merge($summary, [
            'period' => $period,
            'customRange' => $customRange,
            'fromInput' => $request->query('from'),
            'toInput' => $request->query('to'),
            'filters' => $filters,
            'truckTypes' => TruckType::orderBy('name')->get(['id', 'name']),
            'revenueByTruckType' => $revenueByTruckType,
            'topUnits' => $summary['vehicleReport']->take(5)->values(),
            'revenueTrend' => $revenueTrend,
        ]));
    }

    protected function buildRevenueTrend($start, $end, array $filters = []): array
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->endOfDay();

        $rows = Booking::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->when($filters['truck_type_id'] ?? null, fn ($q, $id) => $q->where('truck_type_id', $id))
            ->when(($filters['min_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '>=', $filters['min_amount']))
            ->when(($filters['max_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '<=', $filters['max_amount']))
            ->selectRaw('DATE(created_at) as day, sum(final_total) as revenue')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $trend = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $trend[] = [
                'label' => $cursor->format('M j'),
                'revenue' => (float) ($rows[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $trend;
    }

    public function bookings(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);
        $filters = $this->resolveFilters($request);

        $bucket = (string) $request->query('bucket', '');
        $statuses = self::BUCKETS[$bucket] ?? null;

        $query = Booking::with(['customer', 'truckType', 'unit'])
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

    public function activity(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);

        $category = (string) $request->query('category', '');
        $entityType = (string) $request->query('entity_type', '');
        $actorId = $request->query('user_id', '');
        $search = trim((string) $request->query('search', ''));

        $allLogs = AuditLog::with('user')
            ->whereBetween('created_at', [$start, $end])
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when($category === '', fn ($q) => $q->whereNotIn('category', self::SECURITY_CATEGORIES))
            ->when($entityType !== '', fn ($q) => $q->where('entity_type', $entityType))
            ->when(filled($actorId), fn ($q) => $q->where('user_id', $actorId))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('description', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->get();

        $rows = $allLogs->flatMap(fn ($log) => collect($this->explodeAuditLogRows($log))
            ->map(fn ($description) => (object) ['log' => $log, 'description' => $description]));

        $perPage = 10;
        $page = (int) $request->query('page', 1);
        $logs = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

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

    protected function explodeAuditLogRows(AuditLog $log): array
    {
        $old = $log->old_value ?? [];
        $new = $log->new_value ?? [];

        $humanize = fn ($key) => ucwords(str_replace('_', ' ', $key));
        $formatValue = function ($value) {
            if ($value === null || $value === '') {
                return '(none)';
            }
            if (is_bool($value)) {
                return $value ? 'Yes' : 'No';
            }
            return (string) $value;
        };

        $rows = [];

        if (empty($old) && ! empty($new)) {
            foreach ($new as $field => $value) {
                if ($value !== null && $value !== '') {
                    $rows[] = $humanize($field) . ': ' . $formatValue($value);
                }
            }
        } elseif (empty($new) && ! empty($old)) {
            foreach ($old as $field => $value) {
                if ($value !== null && $value !== '') {
                    $rows[] = $humanize($field) . ': ' . $formatValue($value);
                }
            }
        } elseif (! empty($old) || ! empty($new)) {
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
                $rows[] = $humanize($field) . ': ' . $formatValue($old[$field] ?? null) . ' - ' . $formatValue($new[$field] ?? null);
            }
        }

        return $rows ?: [$log->description];
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

        $pipeline = $pipeline->map(function (array $row) use ($totalBookings) {
            $row['share'] = $totalBookings > 0 ? ($row['count'] / $totalBookings) * 100 : 0.0;

            return $row;
        });

        $vehicleReport = $base()
            ->where('status', 'completed')
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
            'additionalFees' => (float) $base()->where('status', 'completed')->sum('additional_fee'),
            'vatCollected' => (float) $base()->where('status', 'completed')->sum('vat_amount'),
            'cashReceived' => (float) $base()->where('status', 'completed')->sum('cash_received'),
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
            'completionRate' => $totalBookings > 0 ? ($completedCount / $totalBookings) * 100 : 0.0,
            'pipeline' => $pipeline,
            'vehicleReport' => $vehicleReport,
            'totalVehiclesUsed' => $vehicleReport->count(),
            'totalTrips' => $vehicleReport->sum('trips'),
            'financial' => $financial,
        ];
    }
}
