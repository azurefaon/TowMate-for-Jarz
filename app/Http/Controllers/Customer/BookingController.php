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
        $request->validate([
            'pickup_address' => 'required|string',
            'dropoff_address' => 'required|string',
            'pickup_lat' => 'required',
            'pickup_lng' => 'required',
            'drop_lat' => 'required',
            'drop_lng' => 'required',
            'truck_type_id' => 'required',
            'distance' => 'required',
            'price' => 'required',
        ]);

        $customer = Auth::user()->customer;

        $distance = floatval(str_replace(' km', '', $request->distance));
        $price = floatval(str_replace(['₱', ','], '', $request->price));

        $booking = Booking::create([
            'customer_id' => $customer->id,

            'truck_type_id' => $request->truck_type_id,

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

        return redirect()->route('customer.track', $booking->id);
    }
}
