<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;

class DashboardController extends Controller
{
    public function index()
    {
        $userId = auth()->id();

        $totalBookings = Booking::where('customer_id', $userId)->count();

        $activeBookings = Booking::where('customer_id', $userId)
            ->whereIn('status', ['requested', 'assigned', 'on_job'])
            ->count();

        $totalSpent = Booking::where('customer_id', $userId)
            ->sum('final_total');

        $activeBooking = Booking::where('customer_id', $userId)
            ->whereIn('status', ['requested', 'assigned', 'on_job'])
            ->latest()
            ->first();

        return view('customer.pages.dashboard', compact(
            'totalBookings',
            'activeBookings',
            'totalSpent',
            'activeBooking'
        ));
    }
}
