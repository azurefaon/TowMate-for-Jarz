<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\Booking;

class SuperAdminController extends Controller
{
    public function index()
    {
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $completedThisMonth = Booking::where('status', 'completed')
            ->whereBetween('completed_at', [$periodStart, $periodEnd]);

        $revenueThisMonth = (float) (clone $completedThisMonth)->sum('final_total');
        $completedJobsCount = (clone $completedThisMonth)->count();
        $averageRevenuePerJob = $completedJobsCount > 0 ? $revenueThisMonth / $completedJobsCount : 0.0;

        $totalBookingsThisMonth = Booking::whereBetween('created_at', [$periodStart, $periodEnd])->count();
        $cancelledThisMonth = Booking::where('status', 'cancelled')
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->count();
        $cancellationRate = $totalBookingsThisMonth > 0 ? ($cancelledThisMonth / $totalBookingsThisMonth) * 100 : 0.0;

        $totalUnits = Unit::whereNull('archived_at')->count();
        $unitsInUse = Unit::where('status', 'on_job')->count();
        $fleetUtilization = $totalUnits > 0 ? ($unitsInUse / $totalUnits) * 100 : 0.0;

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

        $revenueByTruckType = Booking::query()
            ->whereBetween('completed_at', [$periodStart, $periodEnd])
            ->where('status', 'completed')
            ->whereNotNull('truck_type_id')
            ->with('truckType')
            ->selectRaw('truck_type_id, sum(final_total) as revenue')
            ->groupBy('truck_type_id')
            ->orderByDesc('revenue')
            ->take(5)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->truckType->name ?? 'Truck Type #' . $row->truck_type_id,
                'revenue' => (float) $row->revenue,
            ]);

        $topUnits = Booking::query()
            ->whereBetween('completed_at', [$periodStart, $periodEnd])
            ->where('status', 'completed')
            ->whereNotNull('assigned_unit_id')
            ->with('unit')
            ->selectRaw('assigned_unit_id, count(*) as trips, sum(final_total) as revenue')
            ->groupBy('assigned_unit_id')
            ->orderByDesc('revenue')
            ->take(5)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->unit->name ?? 'Unit #' . $row->assigned_unit_id,
                'trips' => (int) $row->trips,
                'revenue' => (float) $row->revenue,
            ]);

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
