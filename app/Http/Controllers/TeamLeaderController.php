<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Booking;

class TeamLeaderController extends Controller
{
    public function index()
    {
        $bookings = Booking::with(['customer', 'truckType'])
            ->where('status', 'accepted')
            ->latest()
            ->paginate(15);

        return view('teamleader.bookings', compact('bookings'));
    }
}
