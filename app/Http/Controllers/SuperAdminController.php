<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Unit;
use App\Models\TruckType;
use App\Models\Booking;
use App\Models\AuditLog;

class SuperAdminController extends Controller
{
    public function index()
    {
        $totalUsers = User::count();

        $activeUnits = Unit::where('status', 'available')->count();

        $activeTruckTypes = TruckType::where('status', 'active')->count();

        $totalBookings = Booking::count();

        $totalRevenue = (float) Booking::where('status', 'completed')->sum('final_total');

        $todayBookings = Booking::whereDate('created_at', today())->count();

        $pendingBookings = Booking::where('status', 'requested')->count();

        $completedToday = Booking::where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        $cancelledToday = Booking::where('status', 'cancelled')
            ->whereDate('created_at', today())
            ->count();

        $recentActivities = AuditLog::latest()->take(5)->get();

        // PostgreSQL-safe weekly bookings (DOW: 0=Sun, 1=Mon, ..., 6=Sat)
        // Chart labels are Mon–Sun → indices 0–6
        $rawWeek = Booking::selectRaw('EXTRACT(DOW FROM created_at)::int as dow, count(*) as total')
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->groupBy('dow')
            ->get()
            ->keyBy('dow');

        $weekBookings = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) { // Mon=1 … Sat=6, Sun=0
            $weekBookings[] = (int) ($rawWeek->get($dow)?->total ?? 0);
        }

        return view('superadmin.dashboard', compact(
            'totalUsers',
            'totalBookings',
            'totalRevenue',
            'activeUnits',
            'todayBookings',
            'pendingBookings',
            'activeTruckTypes',
            'completedToday',
            'cancelledToday',
            'recentActivities',
            'weekBookings'
        ));
    }
}
