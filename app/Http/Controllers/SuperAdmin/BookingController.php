<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index()
    {
        $bookings = \App\Models\Booking::with([
            'customer',
            'truckType',
            'unit',
            'receipt'
        ])->latest()->paginate(7);

        return view('superadmin.bookings.index', compact('bookings'));
    }


    public function show($id)
    {
        $booking = Booking::with([
            'customer',
            'truckType',
            'unit',
            'receipt'
        ])->findOrFail($id);

        return view('superadmin.bookings.show', compact('booking'));
    }

    public function store(Request $request)
    {
        $customer = Auth::user()->customer;
        $admin = \App\Models\User::where('role_id', 1)->first();

        Booking::create([
            'customer_id' => $customer->id,

            'truck_type_id' => 1,

            'pickup_address' => $request->pickup_address,
            'dropoff_address' => $request->dropoff_address,

            'distance_km' => 0,
            'base_rate' => 100,
            'per_km_rate' => 50,
            'final_total' => 0,

            'status' => 'requested',

            'created_by_admin_id' => $admin->id,
        ]);

        return redirect()->back()->with('success', 'Booking Created');
    }
}
