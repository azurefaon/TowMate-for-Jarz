<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Quotation;
use Illuminate\Http\Request;

class PublicTrackController extends Controller
{
    public function index(Request $request)
    {
        $ref     = trim((string) $request->query('ref', ''));
        $booking = null;
        $error   = null;

        if ($ref !== '') {
            // Search by booking_code, quotation_number, or reference_number
            $booking = Booking::with(['customer', 'truckType', 'unit.teamLeader'])
                ->where(function ($q) use ($ref) {
                    $q->where('booking_code', $ref)
                        ->orWhere('quotation_number', $ref);
                })
                ->whereNotIn('status', ['cancelled'])
                ->first();

            if (! $booking) {
                $error = 'No active booking found for reference <strong>' . e($ref) . '</strong>. Please double-check your reference number.';
            }
        }

        return view('public.track', compact('booking', 'error', 'ref'));
    }
}
