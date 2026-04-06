<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $query = Booking::with([
            'customer',
            'truckType',
            'unit',
            'receipt'
        ]);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('customer', function ($q2) use ($request) {
                    $q2->where('full_name', 'like', '%' . $request->search . '%');
                })
                    ->orWhere('pickup_address', 'like', '%' . $request->search . '%')
                    ->orWhere('dropoff_address', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->status) {
            if ($request->status === 'active') {
                $query->whereIn('status', ['assigned', 'on_job']);
            } else {
                $query->where('status', $request->status);
            }
        }

        $bookings = $query->latest()->paginate(10)->withQueryString();

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

        return response()->json($booking);
    }

    public function store(Request $request)
    {
        $customer = Auth::user()->customer;
        $admin = \App\Models\User::where('role_id', 1)->first();

        $truck = \App\Models\TruckType::findOrFail($request->truck_type_id);

        $distanceKm = $request->distance_km ?? 0;

        $base = $truck->base_rate;
        $perKm = $truck->per_km_rate;

        $extraKm = max(0, $distanceKm - 4);
        $distanceCost = $extraKm * $perKm;

        $total = $base + $distanceCost;

        Booking::create([
            'customer_id' => $customer->id,
            'truck_type_id' => $truck->id,
            'pickup_address' => $request->pickup_address,
            'dropoff_address' => $request->dropoff_address,
            'distance_km' => $distanceKm,
            'base_rate' => $base,
            'per_km_rate' => $perKm,
            'final_total' => $total,
            'status' => 'requested',
            'created_by_admin_id' => $admin->id,
        ]);

        return back();
    }
}
