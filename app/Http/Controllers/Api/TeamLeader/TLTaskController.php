<?php

namespace App\Http\Controllers\Api\TeamLeader;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use App\Services\CustomerNotificationService;
use App\Services\DocumentGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TLTaskController extends Controller
{
    private const TERMINAL_STATUSES = ['completed', 'cancelled', 'rejected', 'returned'];

    private const TL_TASK_STATUSES = [
        'assigned', 'accepted', 'on_the_way', 'arrived_pickup',
        'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff',
        'waiting_verification', 'completed', 'returned',
    ];

    private const ARRIVAL_RADIUS_METERS = 150;
    private const ARRIVAL_CLAIMS = [
        'on_the_way' => ['arrived_pickup', 'pickup_lat', 'pickup_lng'],
        'on_job'     => ['arrived_dropoff', 'dropoff_lat', 'dropoff_lng'],
    ];

    private const VALID_TRANSITIONS = [
        'assigned'              => ['accepted'],
        'accepted'              => ['on_the_way', 'returned'],
        'on_the_way'            => ['arrived_pickup', 'returned', 'accepted'],
        'arrived_pickup'        => ['in_progress', 'returned', 'on_the_way'],
        'in_progress'           => ['loading_vehicle', 'returned', 'arrived_pickup'],
        'loading_vehicle'       => ['on_job', 'returned', 'in_progress'],
        'on_job'                => ['arrived_dropoff', 'returned', 'loading_vehicle'],
        'arrived_dropoff'       => ['returned', 'on_job'],
        'waiting_verification'  => ['returned', 'arrived_dropoff'],
    ];

    public function current(Request $request): JsonResponse
    {
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
            'status'   => 'required|string',
            'lat'      => 'nullable|numeric|between:-90,90',
            'lng'      => 'nullable|numeric|between:-180,180',
            'is_demo'  => 'nullable|boolean',
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

        $isDemo = $request->boolean('is_demo');
        if ($isDemo && ! config('towmate.demo_arrival_enabled')) {
            return response()->json(['success' => false, 'message' => 'Demo arrival is not enabled.'], 403);
        }

        $arrivalClaim = self::ARRIVAL_CLAIMS[$booking->status] ?? null;
        $isDemoArrival = false;
        if ($arrivalClaim && $arrivalClaim[0] === $newStatus) {
            $isDemoArrival = $isDemo;

            if (! $isDemo) {
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
        }

        $updates = ['status' => $newStatus];

        if ($newStatus === 'completed') {
            $updates['completed_at'] = now();
        }

        $booking->update($updates);
        $booking->load(['customer', 'truckType', 'unit']);

        if ($isDemoArrival) {
            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'demo_arrival_confirmed',
                'entity_type' => 'Booking',
                'entity_id'   => $booking->id,
                'reference'   => $booking->booking_code,
                'description' => "Team Leader confirmed '{$newStatus}' via Demo Arrival (GPS proximity check skipped).",
            ]);
        }

        if ($newStatus === 'arrived_dropoff' && ! $booking->currentInvoice()->exists()) {
            try {
                $this->issueInvoice($booking, $request->user()->id);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            }
        }

        try { BookingStatusUpdated::safeFire($booking); } catch (\Throwable) {}

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

        $path = $request->file('photo')->store('task-photos', 'local');

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
            'url'     => protected_file_url($path),
        ]);
    }

    public function complete(Booking $booking, Request $request): JsonResponse
    {
        if ((int) $booking->assigned_team_leader_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'This task is not assigned to you.'], 403);
        }

        $validated = $request->validate([
            'signature'      => 'required|file|mimes:jpg,jpeg,png|max:2048',
            'payment_method' => 'required|string|in:cash,gcash,bank_transfer',
            'cash_received'  => ['required_if:payment_method,cash', 'nullable', 'numeric', 'min:0'],
        ]);

        $signaturePath = $request->file('signature')->store('signatures', 'local');

        $outcome = DB::transaction(function () use ($booking, $validated, $signaturePath, $request) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if (in_array($locked->status, self::TERMINAL_STATUSES, true)) {
                return ['error' => ['message' => 'Task is already in a terminal state.', 'status' => 422]];
            }

            if ($locked->status === 'waiting_verification' && $locked->payment_submitted_at !== null) {
                $locked->load(['customer', 'truckType', 'unit']);

                return ['already' => $locked];
            }

            if (! $locked->currentInvoice()->exists()) {
                return ['error' => ['message' => 'No invoice has been issued for this job yet. Payment cannot be recorded.', 'status' => 422]];
            }

            if ($validated['payment_method'] === 'cash' && (float) $validated['cash_received'] < (float) $locked->final_total) {
                return ['error' => ['message' => 'Cash received must cover the final total.', 'status' => 422]];
            }

            if (in_array($validated['payment_method'], ['gcash', 'bank_transfer'], true) && blank($locked->payment_proof_path)) {
                return ['error' => ['message' => 'Payment proof must be uploaded before completing this task.', 'status' => 422]];
            }

            $updates = [
                'status'                       => 'waiting_verification',
                'customer_verified_at'         => now(),
                'customer_verification_status' => 'verified',
                'payment_method'               => $validated['payment_method'],
                'payment_submitted_at'         => now(),
                'completion_requested_at'      => $locked->completion_requested_at ?? now(),
            ];

            if ($validated['payment_method'] === 'cash') {
                $updates['cash_received'] = $validated['cash_received'];
            }

            $updates['customer_signature_path'] = $signaturePath;

            $locked->update($updates);

            $sibling = null;
            if ($locked->group_code) {
                $sibling = Booking::where('group_code', $locked->group_code)
                    ->where('id', '!=', $locked->id)
                    ->whereIn('status', ['requested', 'scheduled', 'scheduled_confirmed', 'confirmed'])
                    ->lockForUpdate()
                    ->first();
                if ($sibling) {
                    $sibling->update([
                        'assigned_team_leader_id' => $locked->assigned_team_leader_id,
                        'assigned_unit_id'        => $locked->assigned_unit_id,
                        'status'                  => 'accepted',
                        'assigned_at'             => now(),
                    ]);
                    $sibling->load(['customer', 'truckType', 'unit']);
                }
            }

            if (! $sibling) {
                if ($locked->assigned_unit_id) {
                    Unit::where('id', $locked->assigned_unit_id)
                        ->where('status', 'on_job')
                        ->update(['status' => 'available']);
                }
            }

            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'payment_submitted',
                'entity_type' => 'Booking',
                'entity_id'   => $locked->id,
                'reference'   => $locked->booking_code,
                'description' => 'Team Leader submitted service completion and payment for dispatcher verification.',
            ]);

            $locked->load(['customer', 'truckType', 'unit']);

            return ['booking' => $locked, 'sibling' => $sibling];
        });

        if (isset($outcome['error'])) {
            return response()->json(['success' => false, 'message' => $outcome['error']['message']], $outcome['error']['status']);
        }

        if (isset($outcome['already'])) {
            return response()->json([
                'success'   => true,
                'message'   => 'Task already submitted for dispatcher confirmation.',
                'data'      => $this->formatTask($outcome['already']),
                'next_task' => null,
            ]);
        }

        $booking = $outcome['booking'];
        $sibling = $outcome['sibling'];

        try { BookingStatusUpdated::safeFire($booking); } catch (\Throwable) {}
        if ($sibling) {
            try { BookingStatusUpdated::safeFire($sibling); } catch (\Throwable) {}
        }

        return response()->json([
            'success'   => true,
            'message'   => 'Task submitted for dispatcher confirmation.',
            'data'      => $this->formatTask($booking),
            'next_task' => $sibling ? $this->formatTask($sibling) : null,
        ]);
    }

    public function claimNext(string $groupCode, Request $request): JsonResponse
    {
        $tl = $request->user();

        $alreadyAssigned = Booking::where('group_code', $groupCode)
            ->where('assigned_team_leader_id', $tl->id)
            ->whereIn('status', ['assigned', 'accepted', 'on_the_way', 'arrived_pickup',
                'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification'])
            ->first();

        if ($alreadyAssigned) {
            $alreadyAssigned->load(['customer', 'truckType', 'unit']);
            return response()->json(['success' => true, 'data' => $this->formatTask($alreadyAssigned)]);
        }

        $hasGroupHistory = Booking::where('group_code', $groupCode)
            ->where('assigned_team_leader_id', $tl->id)
            ->exists();

        if (! $hasGroupHistory) {
            return response()->json([
                'success' => false,
                'message' => 'You are not assigned to this booking group.',
            ], 403);
        }

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
                ? protected_file_url($booking->vehicle_image_paths[0]) : null,
            'notes'               => $booking->notes,
            'scheduled_date'      => $booking->scheduled_date?->toDateString(),
            'scheduled_time'      => $booking->scheduled_time,
            'arrival_photo'       => protected_file_url($booking->arrival_photo_path),
            'dropoff_photo'       => protected_file_url($booking->dropoff_photo_path),
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

    private function issueInvoice(Booking $booking, ?int $createdBy): Invoice
    {
        $invoice = Invoice::create([
            'booking_id' => $booking->id,
            'quotation_id' => $booking->quotation_id,
            'subtotal' => (float) ($booking->vat_exclusive_total ?? $booking->final_total ?? 0),
            'additional_fee' => (float) ($booking->additional_fee ?? 0),
            'discount' => (float) ($booking->discount_amount ?? 0),
            'total' => (float) ($booking->final_total ?? 0),
            'status' => 'issued',
            'is_current' => true,
            'created_by' => $createdBy,
        ]);

        $invoiceId = $invoice->id;
        app()->terminating(function () use ($invoiceId) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            try {
                $invoice = Invoice::with('booking.customer')->find($invoiceId);
                if (! $invoice) {
                    return;
                }

                app(DocumentGenerationService::class)->generateInvoice($invoice);

                if (filled($invoice->booking->customer?->email)) {
                    Mail::to($invoice->booking->customer->email)->send(new InvoiceMail($invoice->fresh()));
                    $invoice->update(['email_sent' => true]);
                }
            } catch (\Throwable $e) {
                Log::error('Background invoice generation failed', [
                    'invoice_id' => $invoiceId,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $invoice;
    }
}
