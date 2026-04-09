<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class JobsController extends Controller
{
    public function index()
    {
        $jobs = Booking::with(['customer', 'truckType', 'unit.driver', 'unit.teamLeader'])
            ->whereIn('status', ['assigned', 'on_job', 'delayed'])
            ->latest()
            ->paginate(12);

        $stats = [
            'total' => Booking::whereIn('status', ['assigned', 'on_job', 'delayed'])->count(),
            'on_job' => Booking::where('status', 'on_job')->count(),
            'assigned' => Booking::where('status', 'assigned')->count(),
            'delayed' => Booking::where('status', 'delayed')->count(),
        ];

        return view('admin-dashboard.pages.jobs', compact('jobs', 'stats'));
    }
}
