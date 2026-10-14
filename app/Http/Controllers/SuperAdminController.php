<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Services\ReportMetricsService;
use App\Services\UnitAvailabilityService;
use Illuminate\Support\Str;

class SuperAdminController extends Controller
{
    protected array $activeJobStatuses = ['assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'];

    protected array $scheduledStatuses = ['scheduled', 'scheduled_confirmed'];

    protected array $verificationStatuses = ['waiting_verification', 'payment_pending', 'payment_submitted'];

    /**
     * Owner-facing wording for audit actions whose meaning has been verified
     * against the code that records them. Other actions keep the generic
     * wording in recentActivityGroups().
     */
    protected array $activityLabels = [
        'create_booking' => ['New booking request', 'clipboard-plus'],
        'quotation_sent' => ['Quotation sent', 'file-text'],
        'create_quotation' => ['Quotation created', 'file-text'],
        'payment_confirmed' => ['Payment confirmed', 'circle-check'],
        'booking_rescheduled' => ['Booking rescheduled', 'calendar'],
        'booking_assigned' => ['Booking assigned', 'user-check'],
    ];

    public function __construct(
        protected ReportMetricsService $metrics,
        protected UnitAvailabilityService $unitAvailability
    ) {
    }

    public function index()
    {
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $revenueMetrics = $this->metrics->averageRevenuePerJob($periodStart, $periodEnd);
        $revenueThisMonth = $revenueMetrics['revenue'];
        $completedJobsCount = $revenueMetrics['completed_jobs'];

        $activeJobsCount = Booking::whereIn('status', $this->activeJobStatuses)->count();

        // Same per-unit evaluation dispatch uses: "available" means ready for
        // dispatch right now; the total is every non-archived unit.
        $unitRows = $this->unitAvailability->evaluateAll();
        $totalUnitsCount = $unitRows->count();
        $availableUnitsCount = $unitRows->where('available', true)->count();

        $pendingBookingsCount = Booking::where('status', 'requested')->count();
        $scheduledBookingsCount = Booking::whereIn('status', $this->scheduledStatuses)->count();
        $verificationBookingsCount = Booking::whereIn('status', $this->verificationStatuses)->count();
        $returnedJobsCount = Booking::whereNotNull('returned_at')
            ->whereIn('status', ['confirmed', 'accepted', 'assigned'])
            ->count();

        // Same definition as Monthly Revenue (completed, paid, allocated), per day.
        $revenueTrend = $this->metrics->dailyRevenue(now()->subDays(6), 7);
        $revenueTrendTotal = round(array_sum(array_column($revenueTrend, 'revenue')), 2);

        return view('superadmin.dashboard', [
            'periodLabel' => $periodStart->format('F Y'),
            'revenueThisMonth' => $revenueThisMonth,
            'completedJobsCount' => $completedJobsCount,
            'activeJobsCount' => $activeJobsCount,
            'availableUnitsCount' => $availableUnitsCount,
            'totalUnitsCount' => $totalUnitsCount,
            'pendingBookingsCount' => $pendingBookingsCount,
            'scheduledBookingsCount' => $scheduledBookingsCount,
            'verificationBookingsCount' => $verificationBookingsCount,
            'returnedJobsCount' => $returnedJobsCount,
            'revenueTrendTotal' => $revenueTrendTotal,
            'revenueChart' => $this->revenueChart($revenueTrend),
            'activityGroups' => $this->recentActivityGroups(),
        ]);
    }

    /**
     * Bar heights as a share of a rounded axis maximum (1, 2, 2.5 or 5 x 10^n
     * steps, about three intervals) so grid lines land on readable values.
     */
    protected function revenueChart(array $series): array
    {
        $values = array_column($series, 'revenue');
        $max = $values ? max($values) : 0;
        $step = $this->niceStep($max / 3);
        $top = $max > 0 ? $step * (int) ceil($max / $step) : 0;

        $ticks = [];
        if ($top > 0) {
            $intervals = (int) round($top / $step);
            for ($i = $intervals; $i >= 0; $i--) {
                $ticks[] = ['label' => $this->compactPeso($i * $step), 'percent' => $i / $intervals * 100];
            }
        }

        $peakIndex = $max > 0 ? array_search($max, $values, true) : null;

        $bars = [];
        foreach ($series as $i => $day) {
            $bars[] = [
                'label' => $day['date']->format('M j'),
                'revenue' => $day['revenue'],
                'percent' => $top > 0 ? $day['revenue'] / $top * 100 : 0,
                'is_peak' => $i === $peakIndex,
            ];
        }

        return ['ticks' => $ticks, 'bars' => $bars];
    }

    protected function niceStep(float $rough): float
    {
        if ($rough <= 0) {
            return 1;
        }

        $magnitude = 10 ** floor(log10($rough));

        foreach ([1, 2, 2.5, 5] as $multiplier) {
            if ($rough <= $multiplier * $magnitude) {
                return $multiplier * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    protected function compactPeso(float $value): string
    {
        if ($value >= 1000) {
            return '₱' . rtrim(rtrim(number_format($value / 1000, 1), '0'), '.') . 'k';
        }

        return '₱' . number_format($value);
    }

    protected function recentActivityGroups(): array
    {
        $today = now()->startOfDay();

        return AuditLog::query()
            ->whereIn('entity_type', ['Booking', 'Quotation'])
            ->latest('created_at')
            ->take(6)
            ->get()
            ->map(function (AuditLog $log) {
                $status = $log->new_value['status'] ?? null;

                [$title, $icon] = $this->activityLabels[$log->action] ?? match (true) {
                    $log->entity_type === 'Quotation' => ['Quotation updated', 'file-text'],
                    $status === 'completed' => ['Job completed', 'circle-check'],
                    in_array($status, ['scheduled', 'scheduled_confirmed'], true) => ['Scheduled booking', 'calendar'],
                    default => [str($log->action)->replace('_', ' ')->title()->toString(), 'clipboard-list'],
                };

                return [
                    'title' => $title,
                    'icon' => $icon,
                    'reference' => $log->reference ? Str::after($log->reference, ' ') : ('#' . $log->entity_id),
                    'created_at' => $log->created_at,
                ];
            })
            ->groupBy(function (array $item) use ($today) {
                $day = $item['created_at']->copy()->startOfDay();

                return match (true) {
                    $day->equalTo($today) => 'Today',
                    $day->equalTo($today->copy()->subDay()) => 'Yesterday',
                    default => $item['created_at']->format('M j, Y'),
                };
            })
            ->map(fn ($items, $label) => ['label' => $label, 'items' => $items->all()])
            ->values()
            ->all();
    }
}
