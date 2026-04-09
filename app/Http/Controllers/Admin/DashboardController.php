<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Booking;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $teamLeaders = User::where('role_id', 3)
            ->with(['unit', 'unit.driver'])
            ->get();

        $busyTeamLeaderIds = Booking::whereNotNull('assigned_unit_id')
            ->whereIn('bookings.status', ['assigned', 'on_job'])
            ->join('units', 'bookings.assigned_unit_id', '=', 'units.id')
            ->whereNotNull('units.team_leader_id')
            ->distinct()
            ->pluck('units.team_leader_id');

        $busyTeamLeadersCount = $busyTeamLeaderIds->count();
        $pendingRequests = Booking::where('status', 'requested')->count();
        $activeJobs = Booking::whereIn('status', ['assigned', 'on_job'])->count();
        $delayed = Booking::where('status', 'delayed')->count();
        $completedToday = Booking::where('status', 'completed')
            ->whereDate('updated_at', today())
            ->count();

        $available = max($teamLeaders->count() - $busyTeamLeadersCount, 0);

        $fleetHealth = $teamLeaders->count() > 0
            ? round(($available / $teamLeaders->count()) * 100)
            : 100;

        $incomingRequests = Booking::with(['customer', 'truckType'])
            ->where('status', 'requested')
            ->latest()
            ->take(6)
            ->get();

        $chartData = [
            'completed' => $completedToday,
            'assigned' => $activeJobs,
            'pending' => $pendingRequests,
        ];

        return view('admin-dashboard.pages.dashboard', compact(
            'teamLeaders',
            'available',
            'activeJobs',
            'delayed',
            'fleetHealth',
            'incomingRequests',
            'busyTeamLeadersCount',
            'pendingRequests',
            'completedToday',
            'chartData'
        ));
    }
}
