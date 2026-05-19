<?php

namespace App\Http\Controllers\Api\TeamLeader;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TLTaskController extends Controller
{
    private const TERMINAL_STATUSES = ['completed', 'cancelled', 'rejected', 'returned'];

    // Statuses that belong to the TL workflow â€” excludes pre-dispatch states
    // like 'confirmed' (awaiting dispatcher "Start Job") or 'quotation_sent'.
    private const TL_TASK_STATUSES = [
        'assigned', 'accepted', 'on_the_way', 'arrived_pickup',
        'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff',
        'waiting_verification', 'completed', 'returned',
    ];

    private const VALID_TRANSITIONS = [
        'assigned'          => ['accepted'],
        'accepted'          => ['on_the_way', 'returned'],
        'on_the_way'        => ['arrived_pickup', 'returned'],
        'arrived_pickup'    => ['in_progress', 'returned'],
        'in_progress'       => ['loading_vehicle', 'returned'],
        'loading_vehicle'   => ['on_job', 'returned'],
        'on_job'            => ['arrived_dropoff', 'returned'],
        'arrived_dropoff'   => ['waiting_verification', 'returned'],
        'waiting_verification' => ['completed', 'returned'],
    ];

    public function current(Request $request): JsonResponse
    {
        // Active tasks first; fall back to completed/returned only if nothing active
        $booking = Booking::where('assigned_team_leader_id', $request->user()->id)
            ->whereIn('status', self::TL_TASK_STATUSES)
            ->with(['customer', 'truckType', 'unit'])
            ->orderByRaw("CASE WHEN status IN ('completed','returned') THEN 1 ELSE 0 END")
            ->latest()
            ->first();

        if (! $booking) {
            return response()->json(['success' => true, 'data' => null]);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatTask($booking),
        ]);
    }

    public function accept(Booking $booking, Request $request): JsonResponse
    {
        if ((int) $booking->assigned_team_leader_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'This task is not assigned to you.'], 403);
        }

        if ($booking->status !== 'assigned') {
            return response()->json(['success' => false, 'message' => 'Task is no longer available.'], 409);
        }

        $booking->update([
            'status'      => 'accepted',
            'assigned_at' => now(),
        ]);

        $booking->load(['customer', 'truckType', 'unit']);

        try {
            BookingStatusUpdated::safeFire($booking);
        } catch (\Throwable) {}

        return response()->json([
            'success' => true,
            'data'    => $this->formatTask($booking),
            'message' => 'Task accepted.',
        ]);
    }

    public function updateStatus(Booking $booking, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string',
        ]);

        if ((int) $booking->assigned_team_leader_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'This task is not assigned to you.'], 403);
        }

        $newStatus = $validated['status'];
        $allowed   = self::VALID_TRANSITIONS[$booking->status] ?? [];

        if (! in_array($newStatus, $allowed)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from '{$booking->status}' to '{$newStatus}'.",
            ], 422);
        }

        $updates = ['status' => $newStatus];

        if ($newStatus === 'completed') {
            $updates['completed_at'] = now();
        }

        $booking->update($updates);
        $booking->load(['customer', 'truckType', 'unit']);

        try { BookingStatusUpdated::safeFire($booking); } catch (\Throwable) {}

        return response()->json([
            'success' => true,
            'data'    => $this->formatTask($booking),
        ]);
    }

    public function returnTask(Booking $booking, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'notes'  => 'nullable|string|max:1000',
        ]);

        if ((int) $booking->assigned_team_leader_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'This task is not assigned to you.'], 403);
        }

        if (in_array($booking->status, self::TERMINAL_STATUSES)) {
            return response()->json(['success' => false, 'message' => 'Task is already in a terminal state.'], 409);
        }

        $booking->update([
            'status'                    => 'returned',
            'returned_at'               => now(),
            'return_reason'             => $validated['reason'],
            'returned_by_team_leader_id' => $request->user()->id,
            'pickup_notes'              => $booking->pickup_notes
                ? $booking->pickup_notes . "\nReturn note: " . ($validated['notes'] ?? '')
                : ($validated['notes'] ?? null),
        ]);

        // Free up the unit scoped to the assigned unit
        if ($booking->assigned_unit_id) {
            Unit::where('id', $booking->assigned_unit_id)
                ->where('status', 'on_job')
                ->update(['status' => 'available']);
        } else {
            Unit::where('team_leader_id', $request->user()->id)
                ->where('status', 'on_job')
                ->update(['status' => 'available']);
        }

        $booking->load(['customer', 'truckType', 'unit']);

        try { BookingStatusUpdated::safeFire($booking); } catch (\Throwable) {}

        return response()->json(['success' => true, 'message' => 'Task returned successfully.']);
    }

    public function uploadPhoto(Booking $booking, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png|max:5120',
            'type'  => 'required|in:arrival,dropoff,payment_proof,inspection_damage',
        ]);

        if ((int) $booking->assigned_team_leader_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'This task is not assigned to you.'], 403);
        }

        $path = $request->file('photo')->store('task-photos', 'public');

        $column = match ($validated['type']) {
            'arrival'            => 'arrival_photo_path',
            'dropoff'            => 'dropoff_photo_path',
            'payment_proof'      => 'payment_proof_path',
            'inspection_damage'  => 'inspection_damage_photo_path',
        };
        $booking->update([$column => $path]);

        return response()->json([
            'success' => true,
            'path'    => $path,
            'url'     => Storage::url($path),
        ]);
    }

    public function complete(Booking $booking, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'signature'      => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'payment_method' => 'required|string|in:cash,gcash,bank_transfer',
        ]);

        if ((int) $booking->assigned_team_leader_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'This task is not assigned to you.'], 403);
        }

        if ($booking->status !== 'waiting_verification') {
            return response()->json(['success' => false, 'message' => 'Task is not awaiting verification.'], 422);
        }

        $updates = [
            'status'                       => 'completed',
            'completed_at'                 => now(),
            'customer_verified_at'         => now(),
            'customer_verification_status' => 'verified',
            'payment_method'               => $validated['payment_method'],
        ];

        if ($request->hasFile('signature')) {
            $updates['customer_signature_path'] = $request->file('signature')->store('signatures', 'public');
        }

        $booking->update($updates);

        // Auto-assign TL to sibling scheduled booking (group booking support)
        $nextTask = null;
        $sibling  = null;
        if ($booking->group_code) {
            $sibling = Booking::where('group_code', $booking->group_code)
                ->where('id', '!=', $booking->id)
                ->whereIn('status', ['requested', 'scheduled', 'scheduled_confirmed', 'confirmed'])
                ->first();
            if ($sibling) {
                $sibling->update([
                    'assigned_team_leader_id' => $booking->assigned_team_leader_id,
                    'assigned_unit_id'        => $booking->assigned_unit_id,
                    'status'                  => 'accepted',
                    'assigned_at'             => now(),
                ]);
                $sibling->load(['customer', 'truckType', 'unit']);
                $nextTask = $this->formatTask($sibling);
            }
        }

        // Free the unit only when there is no sibling task for this TL
        if (!$nextTask) {
            if ($booking->assigned_unit_id) {
                Unit::where('id', $booking->assigned_unit_id)
                    ->update(['status' => 'available']);
            } else {
                Unit::where('team_leader_id', $request->user()->id)
                    ->update(['status' => 'available']);
            }
        }

        $booking->load(['customer', 'truckType', 'unit']);

        try { BookingStatusUpdated::safeFire($booking); } catch (\Throwable) {}
        if ($sibling) {
            try { BookingStatusUpdated::safeFire($sibling); } catch (\Throwable) {}
        }

        return response()->json([
            'success'   => true,
            'message'   => 'Task completed successfully.',
            'data'      => $this->formatTask($booking),
            'next_task' => $nextTask,
        ]);
    }

    private function formatTask(Booking $booking): array
    {
        $customer = $booking->customer;

        $groupCode         = $booking->group_code;
        $groupVehicleCount = 1;
        $groupPosition     = 1;
        if ($groupCode) {
            $siblingIds        = Booking::where('group_code', $groupCode)->orderBy('id')->pluck('id')->values();
            $groupVehicleCount = $siblingIds->count();
            $pos               = $siblingIds->search($booking->id);
            $groupPosition     = $pos !== false ? $pos + 1 : 1;
        }

        return [
            'id'                  => $booking->id,
            'booking_code'        => $booking->booking_code,
            'status'              => $booking->status,
            'pickup_address'      => $booking->pickup_address,
            'pickup_lat'          => (float) $booking->pickup_lat,
            'pickup_lng'          => (float) $booking->pickup_lng,
            'dropoff_address'     => $booking->dropoff_address,
            'dropoff_lat'         => (float) $booking->dropoff_lat,
            'dropoff_lng'         => (float) $booking->dropoff_lng,
            'distance_km'         => (float) $booking->distance_km,
            'service_type'        => $booking->service_type,
            'truck_type_name'     => $booking->truckType?->name ?? 'Tow Truck',
            'customer_name'       => $customer?->full_name ?? $customer?->name ?? 'Customer',
            'customer_phone'      => $customer?->phone ?? '',
            'customer_email'      => $customer?->email ?? '',
            'final_total'         => (float) ($booking->final_total ?? $booking->computed_total ?? 0),
            'vehicle_info'        => $this->vehicleInfo($booking),
            'vehicle_image_url'   => $booking->vehicle_image_path ? Storage::url($booking->vehicle_image_path) : null,
            'notes'               => $booking->notes,
            'scheduled_date'      => $booking->scheduled_date?->toDateString(),
            'scheduled_time'      => $booking->scheduled_time,
            'arrival_photo'       => $booking->arrival_photo_path ? Storage::url($booking->arrival_photo_path) : null,
            'dropoff_photo'       => $booking->dropoff_photo_path ? Storage::url($booking->dropoff_photo_path) : null,
            'group_code'          => $groupCode,
            'group_vehicle_count' => $groupVehicleCount,
            'group_position'      => $groupPosition,
        ];
    }

    private function vehicleInfo(Booking $booking): ?string
    {
        $parts = array_filter([
            $booking->vehicle_make,
            $booking->vehicle_model,
            $booking->vehicle_plate_number ? 'Â· ' . $booking->vehicle_plate_number : null,
        ]);

        return $parts ? implode(' ', $parts) : null;
    }
}
