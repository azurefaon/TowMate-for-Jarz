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

        $availableUnitsCount = $this->unitAvailability->evaluateAll()
            ->filter(fn (array $row) => $row['operational_state'] !== 'maintenance' && $row['active_booking'] === null)
            ->count();

        $pendingBookingsCount = Booking::where('status', 'requested')->count();
        $scheduledBookingsCount = Booking::whereIn('status', $this->scheduledStatuses)->count();
        $verificationBookingsCount = Booking::whereIn('status', $this->verificationStatuses)->count();
        $returnedJobsCount = Booking::whereNotNull('returned_at')
            ->whereIn('status', ['confirmed', 'accepted', 'assigned'])
            ->count();

        $revenueTrend = [];
        $revenueTrendLabels = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $revenueTrend[] = (float) Booking::where('status', 'completed')
                ->whereDate('completed_at', $day)
                ->sum('final_total');
            $revenueTrendLabels[] = $day->format('M j');
        }

        $recentActivity = AuditLog::query()
            ->whereIn('entity_type', ['Booking', 'Quotation'])
            ->latest('created_at')
            ->take(6)
            ->get()
            ->map(function (AuditLog $log) {
                $status = $log->new_value['status'] ?? null;

                $title = match (true) {
                    $log->entity_type === 'Quotation' => 'Quotation updated',
                    $log->action === 'create_booking' => 'New booking submitted',
                    $status === 'completed' => 'Job completed',
                    in_array($status, ['scheduled', 'scheduled_confirmed'], true) => 'Scheduled booking',
                    default => str($log->action)->replace('_', ' ')->title()->toString(),
                };

                return [
                    'title' => $title,
                    'reference' => $log->reference ? Str::after($log->reference, ' ') : ('#' . $log->entity_id),
                    'created_at' => $log->created_at,
                ];
            });

        return view('superadmin.dashboard', [
            'periodLabel' => $periodStart->format('F Y'),
            'revenueThisMonth' => $revenueThisMonth,
            'completedJobsCount' => $completedJobsCount,
            'activeJobsCount' => $activeJobsCount,
            'availableUnitsCount' => $availableUnitsCount,
            'pendingBookingsCount' => $pendingBookingsCount,
            'scheduledBookingsCount' => $scheduledBookingsCount,
            'verificationBookingsCount' => $verificationBookingsCount,
            'returnedJobsCount' => $returnedJobsCount,
            'revenueTrend' => $revenueTrend,
            'revenueTrendLabels' => $revenueTrendLabels,
            'recentActivity' => $recentActivity,
        ]);
    }
}
