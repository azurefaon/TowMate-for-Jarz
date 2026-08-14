<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportsController extends Controller
{
    protected const PERIODS = ['today', 'week', 'month'];

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

    public function index(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);

        return view('superadmin.reports.index', array_merge(
            $this->buildSummary($start, $end),
            ['period' => $period, 'customRange' => $customRange, 'fromInput' => $request->query('from'), 'toInput' => $request->query('to')],
        ));
    }

    public function bookings(Request $request)
    {
        [$start, $end, $period, $customRange] = $this->resolveRange($request);

        $bucket = (string) $request->query('bucket', '');
        $statuses = self::BUCKETS[$bucket] ?? null;

        $query = Booking::with(['customer', 'truckType'])
            ->whereBetween('created_at', [$start, $end])
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
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

    public function export(Request $request)
    {
        [$start, $end, $period] = $this->resolveRange($request);
        $summary = $this->buildSummary($start, $end);
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

    protected function resolvePeriod(Request $request): string
    {
        $period = (string) $request->input('period', 'week');

        return in_array($period, self::PERIODS, true) ? $period : 'week';
    }

    protected function dateRange(string $period): array
    {
        return match ($period) {
            'today' => [today(), now()->endOfDay()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [now()->startOfWeek(), now()->endOfWeek()],
        };
    }

    protected function buildSummary($start, $end): array
    {
        $base = fn () => Booking::whereBetween('created_at', [$start, $end]);

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

        return [
            'start' => Carbon::parse($start),
            'end' => Carbon::parse($end),
            'totalBookings' => $totalBookings,
            'totalRevenue' => $totalRevenue,
            'completedCount' => $completedCount,
            'pendingCount' => $pendingCount,
            'cancelledCount' => $cancelledCount,
            'pipeline' => $pipeline,
        ];
    }
}
