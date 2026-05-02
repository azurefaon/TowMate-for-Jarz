<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Quotation;
use Illuminate\Http\Request;

class PublicTrackController extends Controller
{
    public function index(Request $request)
    {
        $ref      = trim((string) $request->query('ref', ''));
        $booking  = null;
        $quotation = null;
        $error    = null;

        $groupBookings = collect();

        if ($ref !== '') {
            // 1) Search existing booking by booking_code, quotation_number, or group_code
            $booking = Booking::with(['customer', 'truckType', 'unit.teamLeader'])
                ->where(function ($q) use ($ref) {
                    $q->where('booking_code', $ref)
                        ->orWhere('quotation_number', $ref)
                        ->orWhere('group_code', $ref);
                })
                ->whereNotIn('status', ['cancelled'])
                ->first();

            // 2) If found via group_code, load all siblings for the group view
            if ($booking && $booking->group_code) {
                $groupBookings = Booking::with(['customer', 'truckType', 'unit.teamLeader'])
                    ->where('group_code', $booking->group_code)
                    ->where('id', '!=', $booking->id)
                    ->whereNotIn('status', ['cancelled'])
                    ->get();
            }

            // 3) If no booking yet, look for a Quotation (pre-booking state)
            if (! $booking) {
                $quotation = Quotation::with(['customer', 'truckType'])
                    ->where('quotation_number', $ref)
                    ->whereNotIn('status', ['rejected'])
                    ->first();
            }

            if (! $booking && ! $quotation) {
                $error = 'No active booking or quotation found for reference <strong>' . e($ref) . '</strong>. Please double-check your reference number.';
            }
        }

        return view('public.track', compact('booking', 'quotation', 'error', 'ref', 'groupBookings'));
    }
}
