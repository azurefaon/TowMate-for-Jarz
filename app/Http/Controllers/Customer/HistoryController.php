<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;

class HistoryController extends Controller
{
    public function index(Request $request)
    {
        $customer = auth()->user()->customer;

        if (!$customer) {
            abort(403, 'No customer account found.');
        }


        $baseQuery = Booking::where('customer_id', $customer->id)
            ->whereIn('status', ['completed', 'Cancelled']);

        $query = clone $baseQuery;


        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('pickup_address', 'like', '%' . $request->search . '%')
                    ->orWhere('dropoff_address', 'like', '%' . $request->search . '%');
            });
        }


        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }


        $totalBookings = (clone $baseQuery)->count();

        $totalCompleted = (clone $baseQuery)
            ->where('status', 'completed')
            ->count();

        $totalSpent = (clone $baseQuery)->sum('final_total');


        $bookings = $query
            ->with(['truckType', 'unit.driver'])
            ->latest()
            ->paginate(5)
            ->appends($request->query());

        return view('customer.pages.history', compact(
            'bookings',
            'totalBookings',
            'totalCompleted',
            'totalSpent'
        ));
    }
}
