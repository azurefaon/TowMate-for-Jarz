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
        $drivers = User::where('role_id', 4)->get();

        $available = $drivers->count();

        $activeJobs = Booking::whereIn('status', ['assigned', 'on_job'])->count();

        $delayed = Booking::where('status', 'delayed')->count();

        $fleetHealth = 98;

        $incomingRequests = Booking::where('status', 'requested')
            ->latest()
            ->take(5)
            ->get();

        return view('admin-dashboard.pages.dashboard', compact(
            'drivers',
            'available',
            'activeJobs',
            'delayed',
            'fleetHealth'
        ));
    }

    // public function index()
    // {
    //     $drivers = User::where('role_id', 4)->get();

    //     $available = $drivers->count();

    //     // $available = $drivers->filter(function ($driver) {
    //     //     return !Booking::where('driver_id', $driver->id)
    //     //         ->whereIn('status', ['assigned', 'on_job'])
    //     //         ->exists();
    //     // })->count();

    //     $activeJobs = Booking::whereIn('status', ['assigned', 'on_job'])->count();

    //     $delayed = Booking::where('status', 'delayed')->count();

    //     $totalDrivers = $drivers->count();
    //     $busyDrivers = Booking::whereIn('status', ['assigned', 'on_job'])
    //         ->distinct('driver_id')
    //         ->count('driver_id');

    //     $fleetHealth = $totalDrivers > 0
    //         ? round((($totalDrivers - $busyDrivers) / $totalDrivers) * 100)
    //         : 100;

    //     return view('admin-dashboard.pages.dashboard', compact(
    //         'drivers',
    //         'available',
    //         'activeJobs',
    //         'delayed',
    //         'fleetHealth'
    //     ));
    // }
}
