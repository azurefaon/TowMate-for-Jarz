<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\Request;

class DispatchController extends Controller
{
    protected BookingService $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    public function index()
    {
        $incomingRequests = Booking::with(['customer', 'truckType'])
            ->where('status', 'requested')
            ->oldest()
            ->get();

        return view('admin-dashboard.pages.dispatch', compact('incomingRequests'));
    }

    public function assignBooking(Request $request, Booking $booking)
    {
        $request->validate([
            'action' => 'required|in:accept,reject',
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        if ($request->action === 'accept') {
            $quotationNumber = $this->bookingService->generateQuotationNumber($booking);

            $booking->update([
                'status' => 'accepted',
                'quotation_number' => $quotationNumber,
                'quotation_generated' => true,
                'assigned_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Booking accepted and quotation generated.',
                'quotation_number' => $quotationNumber,
            ]);
        }

        if ($request->action === 'reject') {

            $booking->update([
                'status' => 'cancelled',
                'rejection_reason' => $request->rejection_reason,
            ]);

            event(new \App\Events\BookingCancelled($booking));

            return response()->json([
                'success' => true,
                'message' => 'Booking cancelled and user notified.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking rejected and cancelled.',
        ]);
    }
}
