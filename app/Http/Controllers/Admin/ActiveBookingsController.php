<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActiveBookingsController extends Controller
{
    /**
     * Display all active bookings with live tracking capabilities
     */
    public function index()
    {
        $activeBookings = Booking::with([
            'customer',
            'truckType',
            'unit.teamLeader',
            'unit.zone',
        ])
            ->whereIn('status', [
                'accepted',
                'assigned',
                'confirmed',
                'in_progress',
                'on_way',
                'on_job',
                'on_tow',
            ])
            ->orderByDesc('updated_at')
            ->paginate(20);

        // Get all zones for dropdown/filtering
        $zones = Zone::orderBy('name')->get();

        return view('admin-dashboard.pages.active-bookings.index', compact(
            'activeBookings',
            'zones'
        ));
    }

    /**
     * Update booking status in real-time
     */
    public function updateStatus(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'accepted',
                    'assigned',
                    'confirmed',
                    'in_progress',
                    'on_way',
                    'on_job',
                    'on_tow',
                    'completed',
                ]),
            ],
        ]);

        $booking->update(['status' => $validated['status']]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Booking #{$booking->job_code} status updated to {$validated['status']}",
                'status' => $booking->status,
                'updated_at' => $booking->updated_at->toIso8601String(),
            ]);
        }

        return back()->with('success', "Booking status updated to {$validated['status']}");
    }

    /**
     * Update booking route/location info
     */
    public function updateRoute(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'pickup_address' => 'nullable|string|max:500',
            'dropoff_address' => 'nullable|string|max:500',
            'dispatcher_note' => 'nullable|string|max:1000',
        ]);

        $booking->update($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Booking #{$booking->job_code} route updated",
                'booking' => [
                    'id' => $booking->id,
                    'job_code' => $booking->job_code,
                    'pickup_address' => $booking->pickup_address,
                    'dropoff_address' => $booking->dropoff_address,
                    'dispatcher_note' => $booking->dispatcher_note,
                ],
                'updated_at' => $booking->updated_at->toIso8601String(),
            ]);
        }

        return back()->with('success', 'Booking route updated');
    }

    /**
     * Update booking pricing/details
     */
    public function updatePricing(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'additional_fee' => 'nullable|numeric|min:0',
            'discount_percentage' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'remarks' => 'nullable|string|max:1000',
        ]);

        $booking->update($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Booking #{$booking->job_code} pricing updated",
                'booking' => [
                    'id' => $booking->id,
                    'job_code' => $booking->job_code,
                    'additional_fee' => $booking->additional_fee,
                    'discount_percentage' => $booking->discount_percentage,
                    'final_total' => $booking->final_total,
                    'remarks' => $booking->remarks,
                ],
                'updated_at' => $booking->updated_at->toIso8601String(),
            ]);
        }

        return back()->with('success', 'Booking pricing updated');
    }

    /**
     * Get booking details for AJAX modal
     */
    public function show(Request $request, Booking $booking)
    {
        $booking->load(['customer', 'truckType', 'unit.teamLeader', 'unit.zone']);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'booking' => [
                    'id' => $booking->id,
                    'job_code' => $booking->job_code,
                    'status' => $booking->status,
                    'customer' => [
                        'name' => $booking->customer?->full_name,
                        'phone' => $booking->customer?->phone,
                        'email' => $booking->customer?->email,
                    ],
                    'truck_type' => $booking->truckType?->name,
                    'pickup_address' => $booking->pickup_address,
                    'dropoff_address' => $booking->dropoff_address,
                    'assigned_team_leader' => $booking->unit?->teamLeader?->full_name ?? $booking->unit?->teamLeader?->name,
                    'assigned_unit' => $booking->unit?->name,
                    'zone' => $booking->unit?->zone?->name,
                    'distance_km' => $booking->distance_km,
                    'base_rate' => $booking->base_rate,
                    'distance_fee' => $booking->distance_fee_amount,
                    'additional_fee' => $booking->additional_fee,
                    'discount_percentage' => $booking->discount_percentage,
                    'final_total' => $booking->final_total,
                    'dispatcher_note' => $booking->dispatcher_note,
                    'remarks' => $booking->remarks,
                    'created_at' => $booking->created_at?->toIso8601String(),
                    'updated_at' => $booking->updated_at?->toIso8601String(),
                ],
            ]);
        }

        return view('admin-dashboard.pages.active-bookings.show', compact('booking'));
    }
}
