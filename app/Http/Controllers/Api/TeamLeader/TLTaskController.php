<?php

namespace App\Http\Controllers\Api\TeamLeader;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Unit;
use App\Services\CustomerNotificationService;
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

    // The two genuine "arrival claim" transitions (from → [to, lat field, lng field])
    // that require the TL to prove via GPS they're actually at the location — see
    // updateStatus(). Keyed by the FROM status so backward moves that happen to land
    // back on 'arrived_pickup'/'arrived_dropoff' (e.g. 'in_progress' → 'arrived_pickup')
    // are never mistaken for a fresh arrival claim and don't require a location check.
    private const ARRIVAL_RADIUS_METERS = 150;
    private const ARRIVAL_CLAIMS = [
        'on_the_way' => ['arrived_pickup', 'pickup_lat', 'pickup_lng'],
        'on_job'     => ['arrived_dropoff', 'dropoff_lat', 'dropoff_lng'],
    ];

    // Backward pairs let a TL undo an accidental advance by one step. They
    // deliberately stop short of 'completed' (terminal) and never touch
    // 'returned' (a separate task-abandonment flow, not a step-back).
    private const VALID_TRANSITIONS = [
        'assigned'              => ['accepted'],
        'accepted'              => ['on_the_way', 'returned'],
        'on_the_way'            => ['arrived_pickup', 'returned', 'accepted'],
        'arrived_pickup'        => ['in_progress', 'returned', 'on_the_way'],
        'in_progress'           => ['loading_vehicle', 'returned', 'arrived_pickup'],
        'loading_vehicle'       => ['on_job', 'returned', 'in_progress'],
        'on_job'                => ['arrived_dropoff', 'returned', 'loading_vehicle'],
        'arrived_dropoff'       => ['waiting_verification', 'returned', 'on_job'],
        'waiting_verification'  => ['completed', 'returned', 'arrived_dropoff'],
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

    public function history(Request $request): JsonResponse
    {
        $bookings = Booking::where('assigned_team_leader_id', $request->user()->id)
            ->whereIn('status', ['completed', 'returned'])
            ->with('customer')
            ->latest('updated_at')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $bookings->getCollection()->map(function (Booking $booking) {
                return [
                    'id'              => $booking->id,
                    'booking_code'    => $booking->booking_code,
                    'status'          => $booking->status,
                    'pickup_address'  => $booking->pickup_address,
                    'dropoff_address' => $booking->dropoff_address,
                    'customer_name'   => $booking->customer?->full_name ?? $booking->customer?->name ?? 'Customer',
                    'final_total'     => (float) ($booking->final_total ?? 0),
                    'completed_at'    => $booking->status === 'completed'
                        ? $booking->completed_at?->toIso8601String()
                        : $booking->updated_at?->toIso8601String(),
                ];
            }),
            'current_page' => $bookings->currentPage(),
            'last_page'    => $bookings->lastPage(),
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
            'lat'    => 'nullable|numeric|between:-90,90',
            'lng'    => 'nullable|numeric|between:-180,180',
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

        // Arrival claims ('arrived_pickup'/'arrived_dropoff') must be backed by GPS
        // proximity to the relevant location. Backward corrections (e.g. going back
        // to 'on_the_way', or 'in_progress' → 'arrived_pickup') skip this — only the
        // two genuine forward arrival-claim transitions require a location check.
        $arrivalClaim = self::ARRIVAL_CLAIMS[$booking->status] ?? null;
        if ($arrivalClaim && $arrivalClaim[0] === $newStatus) {
            [, $latField, $lngField] = $arrivalClaim;
            $targetLat = (float) $booking->{$latField};
            $targetLng = (float) $booking->{$lngField};

            if (! isset($validated['lat'], $validated['lng'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location is required to confirm arrival.',
                ], 422);
            }

            $distanceMeters = $this->haversineMeters(
                (float) $validated['lat'],
                (float) $validated['lng'],
                $targetLat,
                $targetLng,
            );

            if ($distanceMeters > self::ARRIVAL_RADIUS_METERS) {
                $distanceLabel = $distanceMeters >= 1000
                    ? round($distanceMeters / 1000, 1) . 'km'
                    : round($distanceMeters) . 'm';

                return response()->json([
                    'success' => false,
                    'message' => "You appear to be ~{$distanceLabel} from the location. Move closer and try again.",
                ], 422);
            }
        }

        $updates = ['status' => $newStatus];

        if ($newStatus === 'completed') {
            $updates['completed_at'] = now();
        }

        $booking->update($updates);
        $booking->load(['customer', 'truckType', 'unit']);

        try { BookingStatusUpdated::safeFire($booking); } catch (\Throwable) {}

        // Notify customer on key status transitions
        if ($booking->customer && $booking->customer->user_id) {
            $notifMap = [
                'on_the_way'     => 'Your tow truck is on the way',
                'arrived_pickup' => 'Your tow truck has arrived at the pickup location',
            ];
            if (isset($notifMap[$newStatus])) {
                CustomerNotificationService::send(
                    userId: $booking->customer->user_id,
                    type: 'booking_update',
                    title: $notifMap[$newStatus],
                    body: 'Booking ' . $booking->booking_code,
                    bookingCode: $booking->booking_code,
                );
            }
        }

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
            'url'     => Storage::disk('public')->url($path),
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

        $terminalStatuses = ['completed', 'cancelled', 'rejected', 'returned'];
        if (in_array($booking->status, $terminalStatuses)) {
            return response()->json(['success' => false, 'message' => 'Task is already in a terminal state.'], 422);
        }

        // Set to waiting_verification so the dispatcher can confirm payment and send the receipt email.
        $updates = [
            'status'                       => 'waiting_verification',
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

        // Unit is freed by the dispatcher when they confirm payment (JobsController::confirmPayment).
        // Only free early when a sibling group task takes over.
        if ($nextTask) {
            // Sibling task already assigned — unit stays on_job
        }

        $booking->load(['customer', 'truckType', 'unit']);

        try { BookingStatusUpdated::safeFire($booking); } catch (\Throwable) {}
        if ($sibling) {
            try { BookingStatusUpdated::safeFire($sibling); } catch (\Throwable) {}
        }

        return response()->json([
            'success'   => true,
            'message'   => 'Task submitted for dispatcher confirmation.',
            'data'      => $this->formatTask($booking),
            'next_task' => $nextTask,
        ]);
    }

    public function claimNext(string $groupCode, Request $request): JsonResponse
    {
        $tl = $request->user();

        // If auto-assign already ran (the common path), return the active sibling immediately
        $alreadyAssigned = Booking::where('group_code', $groupCode)
            ->where('assigned_team_leader_id', $tl->id)
            ->whereIn('status', ['assigned', 'accepted', 'on_the_way', 'arrived_pickup',
                'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification'])
            ->first();

        if ($alreadyAssigned) {
            $alreadyAssigned->load(['customer', 'truckType', 'unit']);
            return response()->json(['success' => true, 'data' => $this->formatTask($alreadyAssigned)]);
        }

        // Fallback: sibling still unassigned — TL self-assigns
        $sibling = Booking::where('group_code', $groupCode)
            ->whereNull('assigned_team_leader_id')
            ->whereIn('status', ['requested', 'scheduled', 'scheduled_confirmed', 'confirmed'])
            ->first();

        if (! $sibling) {
            return response()->json([
                'success' => false,
                'message' => 'No available sibling booking found in this group.',
            ], 404);
        }

        // Inherit unit from the TL's completed booking in the same group
        $completedBooking = Booking::where('group_code', $groupCode)
            ->where('assigned_team_leader_id', $tl->id)
            ->where('status', 'completed')
            ->first();

        $sibling->update([
            'assigned_team_leader_id' => $tl->id,
            'assigned_unit_id'        => $completedBooking?->assigned_unit_id,
            'status'                  => 'accepted',
            'assigned_at'             => now(),
        ]);
        $sibling->load(['customer', 'truckType', 'unit']);

        try { BookingStatusUpdated::safeFire($sibling); } catch (\Throwable) {}

        return response()->json(['success' => true, 'data' => $this->formatTask($sibling)]);
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
            'vehicle_image_url'   => !empty($booking->vehicle_image_paths)
                ? Storage::disk('public')->url($booking->vehicle_image_paths[0]) : null,
            'notes'               => $booking->notes,
            'scheduled_date'      => $booking->scheduled_date?->toDateString(),
            'scheduled_time'      => $booking->scheduled_time,
            'arrival_photo'       => $booking->arrival_photo_path ? Storage::disk('public')->url($booking->arrival_photo_path) : null,
            'dropoff_photo'       => $booking->dropoff_photo_path ? Storage::disk('public')->url($booking->dropoff_photo_path) : null,
            'payment_method'      => $booking->payment_method,
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

    /**
     * Great-circle distance between two coordinates, in meters.
     */
    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMeters = 6371000;

        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMeters * $c;
    }
}
