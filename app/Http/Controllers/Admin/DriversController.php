<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Booking;
use Illuminate\Http\Request;

class DriversController extends Controller
{
    public function index()
    {
        $teamLeaders = User::where('role_id', 3)
            ->with(['unit', 'unit.driver'])
            ->get();

        $busyTeamLeaders = Booking::where('assigned_unit_id', '!=', null)
            ->whereIn('bookings.status', ['assigned', 'on_job'])
            ->join('units', 'bookings.assigned_unit_id', '=', 'units.id')
            ->whereNotNull('units.team_leader_id')
            ->distinct()
            ->pluck('units.team_leader_id');

        return view('admin-dashboard.pages.drivers', compact('teamLeaders', 'busyTeamLeaders'));
    }
}
