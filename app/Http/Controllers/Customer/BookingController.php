<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use Illuminate\Support\Facades\Auth;

class BookingController extends Controller
{
    public function store(Request $request)
    {
        $customer = Auth::user()->customer;

        $distance = 0;
        $price = 0;

        $booking = Booking::create([
            'customer_id' => $customer->id,

            'truck_type_id' => 1,

            'pickup_address' => $request->pickup_address,
            'pickup_lat' => $request->pickup_lat,
            'pickup_lng' => $request->pickup_lng,

            'dropoff_address' => $request->dropoff_address,
            'dropoff_lat' => $request->drop_lat,
            'dropoff_lng' => $request->drop_lng,

            'distance_km' => $distance,
            'base_rate' => 100,
            'per_km_rate' => 50,
            'final_total' => $price,

            'status' => 'requested',
        ]);

        // return response()->json([
        //     'success' => true,
        //     'booking_id' => $booking->id
        // ]);
        return redirect()->route('customer.book', $booking->id);
    }
}
