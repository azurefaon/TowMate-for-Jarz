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

        $todayBookings = Booking::whereDate('created_at', today())->count();

        $pendingBookings = Booking::where('status', 'requested')->count();

        $completedToday = Booking::where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        $cancelledToday = Booking::where('status', 'cancelled')
            ->whereDate('created_at', today())
            ->count();

        $recentActivities = AuditLog::latest()->take(5)->get();

        return view('superadmin.dashboard', compact(
            'totalUsers',
            'activeUnits',
            'todayBookings',
            'pendingBookings',
            'activeTruckTypes',
            'completedToday',
            'cancelledToday',
            'recentActivities'
        ));
    }
}
