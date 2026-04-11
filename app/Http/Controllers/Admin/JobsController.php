<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class JobsController extends Controller
{
    public function index()
    {
        $activeStatuses = ['assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job', 'delayed'];

        $jobs = Booking::with(['customer', 'truckType', 'unit.driver', 'unit.teamLeader', 'assignedTeamLeader'])
            ->whereIn('status', $activeStatuses)
            ->latest()
            ->paginate(12);

        $stats = [
            'total' => Booking::whereIn('status', $activeStatuses)->count(),
            'on_job' => Booking::whereIn('status', ['on_the_way', 'in_progress', 'on_job'])->count(),
            'assigned' => Booking::where('status', 'assigned')->count(),
            'delayed' => Booking::where('status', 'delayed')->count(),
        ];

        return view('admin-dashboard.pages.jobs', compact('jobs', 'stats'));
    }
}
