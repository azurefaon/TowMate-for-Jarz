<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;

class TrackController extends Controller
{

    public function index()
    {
        $customer = auth()->user()->customer;

        if (!$customer) {
            abort(403, 'No customer account found.');
        }

        $bookings = Booking::where('customer_id', $customer->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['truckType', 'unit.driver'])
            ->latest()
            ->get();

        return view('customer.pages.track', compact('bookings'));
    }


    public function show($id)
    {
        $customer = auth()->user()->customer;

        $booking = Booking::where('id', $id)
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['truckType', 'unit.driver'])
            ->first();

        if (!$booking) {
            return redirect()->route('customer.track.index')
                ->with('error', 'Booking not found or already completed.');
        }

        return view('customer.pages.track-show', compact('booking'));
    }
}
