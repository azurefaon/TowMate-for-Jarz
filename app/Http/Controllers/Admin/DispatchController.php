<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;

class DispatchController extends Controller
{
    public function index()
    {
        $incomingRequests = Booking::where('status', 'requested')
            ->latest()
            ->get();

        return view('admin-dashboard.pages.dispatch', compact('incomingRequests'));
    }
}
