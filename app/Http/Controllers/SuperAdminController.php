<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\ReportMetricsService;

class SuperAdminController extends Controller
{
    public function __construct(protected ReportMetricsService $metrics)
    {
    }

    public function index()
    {
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $revenueMetrics = $this->metrics->averageRevenuePerJob($periodStart, $periodEnd);
        $revenueThisMonth = $revenueMetrics['revenue'];
        $completedJobsCount = $revenueMetrics['completed_jobs'];
        $averageRevenuePerJob = $revenueMetrics['average'];

        $cancellationMetrics = $this->metrics->cancellationRate($periodStart, $periodEnd);
        $cancellationRate = $cancellationMetrics['rate'];

        $fleetMetrics = $this->metrics->currentFleetUtilization();
        $fleetUtilization = $fleetMetrics['rate'];

        $todayBookings = Booking::whereDate('created_at', today())->count();
        $pendingBookings = Booking::where('status', 'requested')->count();
        $completedToday = Booking::where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        $rawWeek = Booking::selectRaw('EXTRACT(DOW FROM created_at)::int as dow, count(*) as total')
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->groupBy('dow')
            ->get()
            ->keyBy('dow');

        $weekBookings = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) {
            $weekBookings[] = (int) ($rawWeek->get($dow)?->total ?? 0);
        }

        $revenueTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $revenueTrend[] = (float) Booking::where('status', 'completed')
                ->whereDate('completed_at', $day)
                ->sum('final_total');
        }

        $revenueByTruckType = $this->metrics->revenueByTruckType($periodStart, $periodEnd, [], 5)
            ->map(fn (array $row) => ['name' => $row['truck_type_name'], 'revenue' => $row['revenue']]);

        $topUnits = $this->metrics->unitPerformance($periodStart, $periodEnd, [], 5)
            ->map(fn (array $row) => ['name' => $row['unit_name'], 'trips' => $row['completed_jobs'], 'revenue' => $row['revenue']]);

        return view('superadmin.dashboard', [
            'periodLabel' => $periodStart->format('F Y'),
            'revenueThisMonth' => $revenueThisMonth,
            'completedJobsCount' => $completedJobsCount,
            'averageRevenuePerJob' => $averageRevenuePerJob,
            'cancellationRate' => $cancellationRate,
            'fleetUtilization' => $fleetUtilization,
            'todayBookings' => $todayBookings,
            'pendingBookings' => $pendingBookings,
            'completedToday' => $completedToday,
            'weekBookings' => $weekBookings,
            'revenueTrend' => $revenueTrend,
            'revenueByTruckType' => $revenueByTruckType,
            'topUnits' => $topUnits,
        ]);
    }
}
