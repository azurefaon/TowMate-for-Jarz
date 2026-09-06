<?php

namespace App\Http\Controllers\Api;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Services\BookingService;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerBookingController extends Controller
{
    private const INACTIVE_STATUSES = ['completed', 'cancelled', 'rejected', 'not_responding'];

    public function __construct(
        private readonly BookingService $bookingService,
        private readonly QuotationService $quotationService,
    ) {}

    public function truckTypes(): JsonResponse
    {
        $types = TruckType::with(['vehicleTypes' => fn($q) => $q->where('status', 'active')->orderBy('display_order')])
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(fn($t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'class'        => $t->class,
                'base_rate'    => (float) $t->base_rate,
                'per_km_rate'  => (float) $t->per_km_rate,
                'description'  => $t->description,
                'vehicle_types' => $t->vehicleTypes->map(fn($v) => [
                    'id'       => $v->id,
                    'name'     => $v->name,
                    'category' => $v->category,
                ])->values(),
            ]);

        return response()->json($types);
    }

    public function availability(): JsonResponse
    {
        $data = $this->bookingService->dispatchAvailability();
        return response()->json([
            'book_now_enabled'         => $data['book_now_enabled'],
            'ready_units_count'        => $data['ready_units_count'],
            'recommended_service_type' => $data['recommended_service_type'],
            'message'                  => $data['message'],
            'ready_by_class'           => $data['ready_by_class'] ?? (object) [],
        ]);
    }

    public function currentBooking(Request $request): JsonResponse
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();

        if (!$customer) {
            return response()->json(['data' => null]);
        }

        $booking = Booking::where('customer_id', $customer->id)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->with('truckType')
            ->latest()
            ->first();

        if (!$booking) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'id'              => $booking->id,
            'booking_code'    => $booking->booking_code,
            'status'          => $booking->status,
            'pickup_address'  => $booking->pickup_address,
            'dropoff_address' => $booking->dropoff_address,
            'distance_km'     => (float) $booking->distance_km,
            'computed_total'  => (float) $booking->computed_total,
            'final_total'     => (float) $booking->final_total,
            'truck_type'      => [
                'name'  => $booking->truckType?->name ?? '',
                'class' => $booking->truckType?->class ?? '',
            ],
        ]]);
    }

    public function bookingHistory(Request $request): JsonResponse
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();

        if (!$customer) {
            return response()->json(['data' => [], 'meta' => []]);
        }

        $bookings = Booking::where('customer_id', $customer->id)
            ->with('truckType')
            ->orderByDesc('created_at')
            ->paginate(10)
            ->through(fn($b) => [
                'id'              => $b->id,
                'booking_code'    => $b->booking_code,
                'status'          => $b->status,
                'pickup_address'  => $b->pickup_address,
                'dropoff_address' => $b->dropoff_address,
                'distance_km'     => (float) $b->distance_km,
                'computed_total'  => (float) $b->computed_total,
                'final_total'     => (float) $b->final_total,
                'truck_type_name' => $b->truckType?->name ?? '',
                'created_at'      => $b->created_at?->toDateTimeString(),
                'group_code'      => $b->group_code,
                'service_type'    => $b->service_type,
                'scheduled_date'  => $b->scheduled_date?->toDateString(),
                'scheduled_time'  => $b->scheduled_time,
            ]);

        return response()->json($bookings);
    }

    public function createBooking(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'truck_type_id'                    => 'required|integer|exists:truck_types,id',
            'vehicle_type_id'                  => 'nullable|integer|exists:vehicle_types,id',
            'pickup_address'                   => 'required|string|max:255',
            'pickup_lat'                       => 'required|numeric|between:-90,90',
            'pickup_lng'                       => 'required|numeric|between:-180,180',
            'dropoff_address'                  => 'required|string|max:255|different:pickup_address',
            'dropoff_lat'                      => 'required|numeric|between:-90,90',
            'dropoff_lng'                      => 'required|numeric|between:-180,180',
            'distance_km'                      => 'required|numeric|min:0.1|max:1000',
            'service_type'                     => 'nullable|in:book_now,schedule',
            'notes'                            => 'nullable|string|max:1000',
            'scheduled_date'                   => 'nullable|date|after_or_equal:today',
            'scheduled_time'                   => 'nullable|string|max:10',
            'extra_vehicles'                   => 'nullable|string',
        ]);

        $validImageFiles = collect($request->file('vehicle_images') ?? [])
            ->filter(fn($f) => $f instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $f->isValid())
            ->values();

        if ($validImageFiles->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'At least one vehicle photo is required. Please try again.'], 422);
        }

        $customer = Customer::where('user_id', $request->user()->id)->first();

        if (!$customer) {
            return response()->json(['message' => 'Customer profile not found.'], 422);
        }

        $hasActive = Booking::where('customer_id', $customer->id)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->exists();

        if ($hasActive) {
            return response()->json(['message' => 'You already have an active booking. Please wait for it to complete.'], 422);
        }

        $truckType     = TruckType::findOrFail($validated['truck_type_id']);
        $distanceKm    = (float) $validated['distance_km'];
        $distanceFee   = $this->bookingService->distanceFeeFor($distanceKm, (float) $truckType->per_km_rate);

        $validated['service_type'] = $validated['service_type'] ?? 'book_now';
        if ($validated['service_type'] === 'book_now') {
            $readyTruckTypeIds = $this->bookingService->dispatchAvailability()['ready_truck_type_ids'] ?? [];

            if (! in_array((int) $truckType->id, $readyTruckTypeIds, true)) {
                $validated['service_type'] = 'schedule';
                $validated['scheduled_date'] = $validated['scheduled_date'] ?? now()->addHour()->toDateString();
                $validated['scheduled_time'] = $validated['scheduled_time'] ?? now()->addHour()->format('H:i');
            }
        }

        $allExtraVehicles = null;
        if (!empty($validated['extra_vehicles'])) {
            $decoded = json_decode($validated['extra_vehicles'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                if (count($decoded) > 5) {
                    return response()->json(['success' => false, 'message' => 'Maximum 6 vehicles per booking.'], 422);
                }
                $extraIds = array_filter(array_column($decoded, 'truck_type_id'));
                if (count($extraIds) !== count($decoded)) {
                    return response()->json(['success' => false, 'message' => 'Each additional vehicle must have a truck type selected.'], 422);
                }
                $foundIds = TruckType::whereIn('id', $extraIds)->pluck('id')->all();
                $missing  = array_diff($extraIds, $foundIds);
                if (!empty($missing)) {
                    return response()->json(['success' => false, 'message' => 'One or more additional vehicles have an invalid truck type.'], 422);
                }
                $allExtraVehicles = $decoded;
            }
        }

        $scheduleExtras = array_values(array_filter($allExtraVehicles ?? [], fn($ev) => ($ev['service_type'] ?? 'book_now') === 'schedule'));
        $bookNowExtras  = array_values(array_filter($allExtraVehicles ?? [], fn($ev) => ($ev['service_type'] ?? 'book_now') !== 'schedule'));

        $extraVehiclesTotalBase = 0.0;
        $pricedBookNowExtras    = [];
        if (!empty($bookNowExtras)) {
            $extraTruckTypeIds = array_column($bookNowExtras, 'truck_type_id');
            $extraTruckTypes   = TruckType::whereIn('id', $extraTruckTypeIds)
                ->get(['id', 'base_rate'])
                ->keyBy('id');

            $pricedBookNowExtras = array_map(function ($ev) use ($extraTruckTypes, &$extraVehiclesTotalBase) {
                $evTruck = $extraTruckTypes->get($ev['truck_type_id'] ?? 0);
                $evBase  = (float) ($evTruck?->base_rate ?? 0);
                $evTotal = round($evBase * 1.12, 2);
                $extraVehiclesTotalBase += $evBase;
                return array_merge($ev, ['estimated_price' => $evTotal]);
            }, $bookNowExtras);
        }

        $allVehiclesBase = (float) $truckType->base_rate + $extraVehiclesTotalBase;
        $computedTotal   = round($allVehiclesBase + $distanceFee, 2);
        $finalTotal      = round($computedTotal * 1.12, 2);

        $booking = Booking::create([
            'customer_id'      => $customer->id,
            'truck_type_id'    => $validated['truck_type_id'],
            'vehicle_type_id'  => $validated['vehicle_type_id'] ?? null,
            'pickup_address'   => $validated['pickup_address'],
            'pickup_lat'       => $validated['pickup_lat'],
            'pickup_lng'       => $validated['pickup_lng'],
            'dropoff_address'  => $validated['dropoff_address'],
            'dropoff_lat'      => $validated['dropoff_lat'],
            'dropoff_lng'      => $validated['dropoff_lng'],
            'distance_km'      => $distanceKm,
            'base_rate'        => $truckType->base_rate,
            'per_km_rate'      => $truckType->per_km_rate,
            'computed_total'        => $computedTotal,
            'final_total'           => $finalTotal,
            'vat_exclusive_total'   => $computedTotal,
            'vat_amount'            => round($computedTotal * 0.12, 2),
            'additional_fee'        => 0,
            'status'           => ($validated['service_type'] ?? 'book_now') === 'schedule' ? 'scheduled' : 'requested',
            'service_type'     => $validated['service_type'] ?? 'book_now',
            'customer_type'    => $customer->customer_type ?? 'regular',
            'confirmation_type' => 'mobile',
            'notes'            => $validated['notes'] ?? null,
            'scheduled_date'   => $validated['scheduled_date'] ?? null,
            'scheduled_time'   => $validated['scheduled_time'] ?? null,
            'scheduled_expires_at' => ($validated['service_type'] ?? 'book_now') === 'schedule' ? now()->addDays(7) : null,
            'extra_vehicles'   => !empty($pricedBookNowExtras) ? $pricedBookNowExtras : null,
        ]);

        $booking->update(['booking_code' => 'TM-' . str_pad($booking->id, 5, '0', STR_PAD_LEFT)]);

        try {
            $imagePath = $this->bookingService->storeVehicleImages($validImageFiles->all());
            $booking->update(['vehicle_image_path' => $imagePath]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Vehicle image upload failed for booking', [
                'booking_id' => $booking->id,
                'error'      => $e->getMessage(),
            ]);
        }

        if (!empty($scheduleExtras)) {
            $groupCode = 'GRP-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT);
            $booking->update(['group_code' => $groupCode]);

            $scheduleExtraTruckIds   = array_column($scheduleExtras, 'truck_type_id');
            $scheduleExtraTruckTypes = TruckType::whereIn('id', $scheduleExtraTruckIds)
                ->get(['id', 'base_rate', 'per_km_rate'])
                ->keyBy('id');

            foreach ($scheduleExtras as $ev) {
                $evTruckType = $scheduleExtraTruckTypes->get($ev['truck_type_id'] ?? 0);
                if (!$evTruckType) continue;

                $evBase  = (float) $evTruckType->base_rate;
                $evTotal = round($evBase * 1.12, 2);

                $sibling = Booking::create([
                    'customer_id'       => $customer->id,
                    'truck_type_id'     => $ev['truck_type_id'],
                    'vehicle_type_id'   => $ev['vehicle_type_id'] ?? null,
                    'pickup_address'    => $validated['pickup_address'],
                    'pickup_lat'        => $validated['pickup_lat'],
                    'pickup_lng'        => $validated['pickup_lng'],
                    'dropoff_address'   => $validated['dropoff_address'],
                    'dropoff_lat'       => $validated['dropoff_lat'],
                    'dropoff_lng'       => $validated['dropoff_lng'],
                    'distance_km'       => $distanceKm,
                    'base_rate'         => $evTruckType->base_rate,
                    'per_km_rate'       => $evTruckType->per_km_rate,
                    'computed_total'    => $evBase,
                    'final_total'       => $evTotal,
                    'additional_fee'    => 0,
                    'status'            => 'scheduled',
                    'service_type'      => 'schedule',
                    'customer_type'     => $customer->customer_type ?? 'regular',
                    'confirmation_type' => 'mobile',
                    'notes'             => $validated['notes'] ?? null,
                    'scheduled_date'    => $ev['scheduled_date'] ?? null,
                    'scheduled_time'    => $ev['scheduled_time'] ?? null,
                    'scheduled_expires_at' => now()->addDays(7),
                    'group_code'        => $groupCode,
                ]);
                $sibling->update(['booking_code' => 'TM-' . str_pad($sibling->id, 5, '0', STR_PAD_LEFT)]);
            }
        }

        if (($validated['service_type'] ?? 'book_now') !== 'schedule') {
            try {
                $quotation = $this->quotationService->createQuotation([
                    'source_booking_id'  => $booking->id,
                    'customer_id'        => $customer->id,
                    'truck_type_id'      => $validated['truck_type_id'],
                    'pickup_address'     => $validated['pickup_address'],
                    'dropoff_address'    => $validated['dropoff_address'],
                    'distance_km'        => $distanceKm,
                    'estimated_price'    => $finalTotal,
                    'service_type'       => $validated['service_type'] ?? 'book_now',
                    'scheduled_date'     => $validated['scheduled_date'] ?? null,
                    'scheduled_time'     => $validated['scheduled_time'] ?? null,
                    'vehicle_image_path' => $booking->vehicle_image_path,
                    'extra_vehicles'     => !empty($pricedBookNowExtras) ? $pricedBookNowExtras : null,
                    'pickup_notes'       => $validated['notes'] ?? null,
                ]);
                $booking->update(['quotation_id' => $quotation->id]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Failed to auto-create quotation for mobile booking', [
                    'booking_id' => $booking->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success'      => true,
            'booking_code' => $booking->booking_code,
            'group_code'   => $booking->group_code,
            'message'      => 'Booking submitted successfully.',
        ], 201);
    }

    public function cancelBooking(Request $request, string $code): JsonResponse
    {
        $customer = Customer::where('user_id', auth()->id())->first();

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $result = DB::transaction(function () use ($code, $customer, $validated) {
            $booking = Booking::where('booking_code', $code)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                return ['status' => 404, 'body' => ['success' => false, 'message' => 'Booking not found.']];
            }

            if (!in_array($booking->status, ['requested', 'scheduled', 'scheduled_confirmed'])) {
                return ['status' => 422, 'body' => [
                    'success' => false,
                    'message' => 'This booking can no longer be cancelled — it has already progressed past the point cancellation is allowed.',
                ]];
            }

            $reason = $validated['reason'] ?? null;

            $booking->update([
                'status' => 'cancelled',
                'rejection_reason' => $reason,
            ]);

            AuditLog::create([
                'user_id'     => auth()->id(),
                'action'      => 'booking_cancelled_by_customer',
                'entity_type' => 'Booking',
                'entity_id'   => $booking->id,
                'reference'   => $booking->job_code,
                'description' => $reason ? "Cancelled by customer — {$reason}" : 'Cancelled by customer.',
            ]);

            $quotation = \App\Models\Quotation::where('source_booking_id', $booking->id)
                ->current()
                ->first();
            if ($quotation && !in_array($quotation->status, ['accepted', 'rejected', 'cancelled', 'expired'])) {
                $quotation->update([
                    'status' => 'rejected',
                    'responded_at' => now(),
                    'response_note' => 'Booking cancelled by customer.',
                ]);
            }

            return ['status' => 200, 'body' => ['success' => true, 'message' => 'Booking cancelled successfully.'], 'booking' => $booking];
        });

        if (isset($result['booking'])) {
            BookingStatusUpdated::safeFire($result['booking']);
        }

        return response()->json($result['body'], $result['status']);
    }

    public function detail(string $code): JsonResponse
    {
        $customer = Customer::where('user_id', auth()->id())->first();

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        $booking = Booking::where('booking_code', $code)
            ->where('customer_id', $customer->id)
            ->with(['truckType', 'assignedTeamLeader', 'unit.driver', 'unit.teamLeader'])
            ->firstOrFail();

        $teamLeaderName = $booking->assignedTeamLeader?->name
            ?? optional(optional($booking->unit)->teamLeader)->name;

        $driverName = $booking->driver_name
            ?? optional(optional($booking->unit)->driver)->name;

        $quotation = \App\Models\Quotation::where('source_booking_id', $booking->id)
            ->latest('id')
            ->first();
        $priceChangeLog = $quotation?->price_change_log ?? [];

        $cancelledAt = AuditLog::where('entity_type', 'Booking')
            ->where('entity_id', $booking->id)
            ->where('action', 'booking_cancelled_by_customer')
            ->orderByDesc('created_at')
            ->value('created_at');

        return response()->json([
            'success' => true,
            'data' => [
                'booking_code'      => $booking->booking_code,
                'status'            => $booking->status,
                'service_type'      => $booking->service_type,
                'pickup_address'    => $booking->pickup_address,
                'pickup_lat'        => $booking->pickup_lat !== null ? (float) $booking->pickup_lat : null,
                'pickup_lng'        => $booking->pickup_lng !== null ? (float) $booking->pickup_lng : null,
                'dropoff_address'   => $booking->dropoff_address,
                'dropoff_lat'       => $booking->dropoff_lat !== null ? (float) $booking->dropoff_lat : null,
                'dropoff_lng'       => $booking->dropoff_lng !== null ? (float) $booking->dropoff_lng : null,
                'distance_km'       => $booking->distance_km !== null ? (float) $booking->distance_km : null,
                'pickup_notes'      => $booking->pickup_notes,
                'truck_type_id'     => $booking->truck_type_id,
                'truck_type_name'   => $booking->truckType?->name,
                'truck_type_class'  => $booking->truckType?->class,
                'base_rate'         => $booking->base_rate !== null ? (float) $booking->base_rate : null,
                'per_km_rate'       => $booking->per_km_rate !== null ? (float) $booking->per_km_rate : null,
                'distance_fee'      => $booking->distance_km !== null && $booking->per_km_rate !== null
                    ? $this->bookingService->distanceFeeFor((float) $booking->distance_km, (float) $booking->per_km_rate)
                    : null,
                'computed_total'    => $booking->computed_total !== null ? (float) $booking->computed_total : null,
                'additional_fee'    => $booking->additional_fee !== null ? (float) $booking->additional_fee : null,
                'vat_amount'        => $booking->vat_amount !== null ? (float) $booking->vat_amount : null,
                'final_total'       => $booking->final_total !== null ? (float) $booking->final_total : null,
                'payment_method'    => $booking->payment_method,
                'scheduled_date'    => $booking->scheduled_date?->toDateString(),
                'scheduled_time'    => $booking->scheduled_time,
                'scheduled_for'     => $booking->scheduled_for?->toIso8601String(),
                'scheduling_bucket' => $booking->scheduling_bucket,
                'team_leader_name'  => $teamLeaderName,
                'driver_name'       => $driverName,
                'arrival_photo_url' => protected_file_url($booking->arrival_photo_path),
                'dropoff_photo_url' => protected_file_url($booking->dropoff_photo_path),
                'created_at'        => $booking->created_at?->toDateTimeString(),
                'completed_at'      => $booking->completed_at?->toDateTimeString(),
                'cancelled_at'      => $cancelledAt?->toIso8601String(),
                'price_change_log'  => $priceChangeLog,
            ],
        ]);
    }

    public function receipt(string $code): JsonResponse
    {
        $customer = Customer::where('user_id', auth()->id())->first();

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        $booking = Booking::where('booking_code', $code)
            ->where('customer_id', $customer->id)
            ->with('receipt')
            ->first();

        if (!$booking || $booking->status !== 'completed' || !$booking->receipt || !$booking->receipt->pdf_path) {
            return response()->json(['success' => false, 'message' => 'Receipt not available for this booking.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'receipt_number' => $booking->receipt->receipt_number,
                'pdf_url'        => app(\App\Services\DocumentGenerationService::class)->publicDocumentUrl($booking->receipt->pdf_path),
                'generated_at'   => $booking->receipt->created_at?->toDateTimeString(),
            ],
        ]);
    }
}
