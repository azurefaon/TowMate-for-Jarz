<?php

namespace App\Http\Controllers\Admin;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Mail\BookingAcceptedMail;
use App\Mail\BookingRejectedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingService;
use App\Services\DocumentGenerationService;
use App\Services\QuotationService;
use App\Services\ReturnReasonHandler;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class DispatchController extends Controller
{
    protected BookingService $bookingService;
    protected DocumentGenerationService $documentGenerationService;
    protected TeamLeaderAvailabilityService $teamLeaderAvailability;
    protected ReturnReasonHandler $returnReasonHandler;
    protected QuotationService $quotationService;

    protected array $reviewableStatuses = ['requested', 'reviewed', 'quoted', 'quotation_sent', 'confirmed'];

    public function __construct(
        BookingService $bookingService,
        DocumentGenerationService $documentGenerationService,
        TeamLeaderAvailabilityService $teamLeaderAvailability,
        ReturnReasonHandler $returnReasonHandler,
        QuotationService $quotationService
    ) {
        $this->bookingService = $bookingService;
        $this->documentGenerationService = $documentGenerationService;
        $this->teamLeaderAvailability = $teamLeaderAvailability;
        $this->returnReasonHandler = $returnReasonHandler;
        $this->quotationService = $quotationService;
    }

    public function updateStatus(Request $request, $id)
    {
        $booking = Booking::query()
            ->where('booking_code', $id)
            ->orWhereKey($id)
            ->firstOrFail();

        $request->merge([
            'rejection_reason' => $request->input('rejection_reason', $request->input('reason')),
        ]);

        return $this->assignBooking($request, $booking);
    }

    public function index()
    {

        $queueBase = Booking::with(['customer', 'truckType', 'unit.teamLeader', 'returnedByTeamLeader'])
            ->where(function ($query) {

                $query->whereIn('status', ['confirmed', 'accepted', 'assigned'])
                    ->orWhere(function ($returnedQuery) {
                        $returnedQuery->whereIn('status', ['confirmed', 'accepted', 'assigned'])
                            ->whereNotNull('returned_at');
                    });
            })
            ->get();

        $returnedRequests = $queueBase
            ->filter(function (Booking $booking) {
                return in_array($booking->status, ['accepted', 'assigned'])
                    && $booking->needs_reassignment === true
                    && !is_null($booking->returned_at)
                    && !empty($booking->return_reason);
            })
            ->sortByDesc(fn(Booking $booking) => $booking->returned_at?->getTimestamp() ?? 0)
            ->values()
            ->map(function (Booking $booking) {
                $booking->queue_bucket = 'returned';
                return $booking;
            });


        $activeBookings = $queueBase
            ->filter(function (Booking $booking) {
                return $booking->status === 'confirmed'
                    || $booking->status === 'accepted'
                    || $booking->status === 'assigned';
            })
            ->sortByDesc(fn(Booking $booking) => $booking->created_at?->getTimestamp() ?? 0)
            ->values()
            ->map(function (Booking $booking) {
                $booking->queue_bucket = 'active';
                return $booking;
            });


        $delayedRequests = collect();
        $negotiationRequests = collect();

        // â”€â”€ Book-Now queue: requested/reviewed/quoted/quotation_sent, NOT scheduled â”€â”€
        // Exclude 'requested' bookings that already have a linked quotation â€” those are
        // mobile app bookings and are surfaced in the Floating Quotations panel instead.
        $bookNowRequests = Booking::with(['customer', 'truckType'])
            ->whereIn('status', $this->reviewableStatuses)
            ->where(function ($q) {
                $q->whereNull('service_type')
                    ->orWhere('service_type', 'book_now');
            })
            ->where(function ($q) {
                // Keep non-requested statuses always; exclude 'requested' only when a quotation exists
                $q->where('status', '!=', 'requested')
                    ->orWhereNull('quotation_id');
            })
            ->oldest('created_at')  // FIFO
            ->get()
            ->map(fn($b) => tap($b, fn($b) => $b->queue_bucket = 'book-now'));

        // â”€â”€ Scheduled queue: scheduled_confirmed first (FIFO), then scheduled (FIFO) â”€â”€
        $scheduledRequests = Booking::with(['customer', 'truckType'])
            ->whereIn('status', ['scheduled_confirmed', 'scheduled'])
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", ['scheduled_confirmed'])
            ->oldest('created_at')  // FIFO within same status
            ->get()
            ->map(fn($b) => tap($b, fn($b) => $b->queue_bucket = 'scheduled'));

        $readyCompletionBookings = Booking::with(['customer', 'truckType', 'unit.teamLeader', 'unit.driver'])
            ->whereIn('status', ['waiting_verification', 'payment_pending', 'payment_submitted'])
            ->whereNull('returned_at')
            ->latest('updated_at')
            ->get()
            ->map(function (Booking $booking) {
                $booking->queue_bucket = 'ready_completion';
                $booking->needs_reassignment = false;
                $booking->needs_assignment = false;
                return $booking;
            });

        $incomingRequests = $returnedRequests
            ->concat($activeBookings)
            ->concat($readyCompletionBookings)
            ->values();

        $incomingRequests = $incomingRequests->map(function (Booking $booking) {
            if ($booking->status === 'confirmed' && $booking->quotation_id) {
                $booking->needs_reassignment = false;
            }

            $booking->needs_assignment =
                $booking->status === 'confirmed' &&
                is_null($booking->assigned_unit_id);

            return $booking;
        });

        $groupedIncoming = $incomingRequests->groupBy(fn($b) => $b->group_code ?: $b->booking_code);
        $groupedBookNow  = $bookNowRequests->groupBy(fn($b) => $b->group_code ?: $b->booking_code);
        $groupedScheduled = $scheduledRequests->groupBy(fn($b) => $b->group_code ?: $b->booking_code);

        $pendingQuotationCount = Quotation::where('status', 'pending')->count();

        $queueCounts = [
            'all' => $incomingRequests->count(),
            'returned' => $returnedRequests->count(),
            'active' => $activeBookings->count(),
            'ready_completion' => $readyCompletionBookings->count(),
            'pending-quotations' => $pendingQuotationCount,
            'book-now' => $bookNowRequests->count(),
            'scheduled' => $scheduledRequests->count(),
            'delayed' => 0,
            'negotiation' => 0,
        ];

        $busyTeamLeaderIds = $this->teamLeaderAvailability->busyTeamLeaderIds();
        $teamLeaderStatuses = $this->teamLeaderAvailability
            ->summarize(
                User::visibleToOperations()->where('role_id', 3)->with(['unit', 'unit.driver'])->get(),
                $busyTeamLeaderIds,
            )['leaders']
            ->keyBy('id');

        $availableUnitProfiles = Unit::with(['truckType', 'driver', 'teamLeader'])
            ->where('status', 'available')
            ->whereNotNull('team_leader_id')
            ->orderBy('name')
            ->get()
            ->map(function (Unit $unit) use ($busyTeamLeaderIds, $teamLeaderStatuses) {
                $teamLeaderId = (int) ($unit->team_leader_id ?? 0);
                $leaderStatus = $teamLeaderStatuses->get($teamLeaderId, []);
                $isOnline = ($leaderStatus['presence'] ?? 'offline') === 'online';
                $hasReadyLeader = $teamLeaderId > 0 && $isOnline && ! $busyTeamLeaderIds->contains($teamLeaderId);
                $coverage = $this->resolveUnitCoverageProfile($unit);

                return [
                    'id' => $unit->id,
                    'label' => trim(($unit->name ?? 'Unit') . ' Â· ' . ($unit->plate_number ?? 'No plate')),
                    'truck_type_id' => (int) ($unit->truck_type_id ?? 0),
                    'truck_type' => $unit->truckType->name ?? 'Unknown truck type',
                    'truck_class' => $unit->truckType->class ?? '',
                    'base_rate' => (float) ($unit->truckType->base_rate ?? 0),
                    'team_leader_name' => $unit->teamLeader->full_name ?? $unit->teamLeader->name ?? 'No team leader',
                    'driver_name' => $unit->driver->full_name ?? $unit->driver->name ?? 'No saved driver',
                    'status_summary' => $hasReadyLeader ? $coverage['summary'] : 'Team leader is not ready for dispatch',
                    'coverage_zones' => $coverage['zones'],
                    'coverage_scores' => $coverage['scores'],
                    'coverage_total' => $coverage['total'],
                    'selectable' => $hasReadyLeader,
                ];
            });

        $availableUnits = $availableUnitProfiles
            ->filter(fn(array $unit) => $unit['selectable'])
            ->sortByDesc('coverage_total')
            ->values();

        $incomingRequests = $incomingRequests
            ->map(function (Booking $booking) use ($availableUnits) {
                $booking->dispatch_zone_label = $this->inferDispatchZoneLabel($booking->pickup_address);

                $recommendation = $this->recommendUnitForBooking($booking, $availableUnits);

                $booking->recommended_unit_id = $recommendation['id'] ?? null;
                $booking->recommended_unit_label = $recommendation['label'] ?? 'Dispatcher will choose the best ready unit.';
                $booking->recommended_unit_summary = $recommendation['recommendation'] ?? 'No saved zone history yet; dispatcher can still assign any ready crew.';

                if ($booking->needs_reassignment && filled($booking->return_reason)) {
                    $booking->return_reason_parsed = $this->returnReasonHandler->parse($booking->return_reason);
                }

                return $booking;
            })
            ->values();


        $zones = \App\Models\Zone::orderBy('name')->get();


        $teamLeaders = \App\Models\User::where('role_id', function ($query) {
            $query->select('id')->from('roles')->where('name', 'team leader');
        })->orderBy('name')->get();

        $returnReasonHandler = $this->returnReasonHandler;


        $allQuotations = Quotation::with(['customer', 'truckType', 'sourceBooking'])
            ->whereIn('status', ['pending', 'sent', 'negotiating'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'negotiating' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($quotation) {
                $timeRemaining = $quotation->getTimeRemaining();
                $quotation->urgency_level = $timeRemaining['urgency'] ?? 'normal';
                $quotation->time_remaining_text = $timeRemaining['message'] ?? 'N/A';
                return $quotation;
            });


        $quotationStats = [
            'total' => Quotation::count(),
            'active' => Quotation::whereIn('status', ['pending', 'sent'])->count(),
            'urgent' => $allQuotations->where('urgency_level', 'urgent')->count(),
            'expired' => Quotation::where('status', 'expired')->count(),
        ];

        $unitGpsData = Unit::whereNotNull('team_leader_id')
            ->get(['team_leader_id', 'location_updated_at'])
            ->keyBy('team_leader_id');

        $activeTasksByTL = Booking::whereNotNull('assigned_team_leader_id')
            ->whereNotIn('status', ['completed', 'cancelled', 'returned', 'rejected'])
            ->get(['booking_code', 'status', 'assigned_team_leader_id'])
            ->keyBy('assigned_team_leader_id');

        return view('admin-dashboard.pages.dispatch', compact('incomingRequests', 'availableUnits', 'queueCounts', 'zones', 'teamLeaders', 'teamLeaderStatuses', 'returnReasonHandler', 'allQuotations', 'quotationStats', 'bookNowRequests', 'scheduledRequests', 'groupedIncoming', 'groupedBookNow', 'groupedScheduled', 'unitGpsData', 'activeTasksByTL'));
    }

    protected function syncCustomerRiskFlag(?Customer $customer, ?string $reason): void
    {
        if (! $customer || blank($reason)) {
            return;
        }

        $reasonText = trim(strip_tags((string) $reason));
        $normalizedReason = strtolower($reasonText);
        $currentRisk = strtolower((string) ($customer->risk_level ?? ''));

        $blacklistKeywords = ['refused to pay', 'non paying', 'non-paying', 'did not pay', 'no payment', 'scam', 'fraud', 'fake booking'];
        $watchlistKeywords = ['unreachable', 'not responding', 'cannot contact', 'no answer', 'no-show', 'no show'];

        $resolvedRisk = null;

        foreach ($blacklistKeywords as $keyword) {
            if (str_contains($normalizedReason, $keyword)) {
                $resolvedRisk = 'blacklisted';
                break;
            }
        }

        if (! $resolvedRisk) {
            foreach ($watchlistKeywords as $keyword) {
                if (str_contains($normalizedReason, $keyword)) {
                    $resolvedRisk = 'watchlist';
                    break;
                }
            }
        }

        if (! $resolvedRisk || ($currentRisk === 'blacklisted' && $resolvedRisk !== 'blacklisted')) {
            return;
        }

        $payload = [
            'risk_level' => $resolvedRisk,
            'risk_reason' => $reasonText,
        ];

        if ($resolvedRisk === 'blacklisted') {
            $payload['blacklisted_at'] = now();
        }

        $customer->update($payload);
    }

    protected function recommendUnitForBooking(Booking $booking, Collection $availableUnits): ?array
    {
        $dispatchZone = $this->inferDispatchZoneLabel($booking->pickup_address);

        $recommended = $availableUnits
            ->map(function (array $unit) use ($booking, $dispatchZone) {
                $zoneMatches = (int) ($unit['coverage_scores'][$dispatchZone] ?? 0);
                $sameTruckType = (int) ($unit['truck_type_id'] ?? 0) === (int) ($booking->truck_type_id ?? 0);
                $score = ($sameTruckType ? 6 : 0) + ($zoneMatches * 4) + ((int) ($unit['coverage_total'] ?? 0) > 0 ? 1 : 0);

                $recommendation = $zoneMatches > 0
                    ? 'Recommended for ' . $dispatchZone . ' based on ' . $zoneMatches . ' recent zone-matched job(s).'
                    : 'Best ready crew for ' . $dispatchZone . ' based on truck match and recent availability.';

                return $unit + [
                    'score' => $score,
                    'recommendation' => $recommendation,
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->first();

        return $recommended && ($recommended['score'] ?? 0) > 0 ? $recommended : null;
    }

    protected function resolveUnitCoverageProfile(Unit $unit): array
    {
        $history = Booking::query()
            ->where(function ($query) use ($unit) {
                $query->where('assigned_unit_id', $unit->id);

                if ($unit->team_leader_id) {
                    $query->orWhere('assigned_team_leader_id', $unit->team_leader_id);
                }
            })
            ->whereIn('status', ['confirmed', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'completed', 'on_job'])
            ->latest('updated_at')
            ->limit(20)
            ->get(['pickup_address', 'dropoff_address']);

        $scores = [];

        foreach ($history as $trip) {
            foreach ([$trip->pickup_address, $trip->dropoff_address] as $address) {
                $zone = $this->inferDispatchZoneLabel($address);

                if ($zone === 'General Dispatch Zone') {
                    continue;
                }

                $scores[$zone] = ($scores[$zone] ?? 0) + 1;
            }
        }

        arsort($scores);

        $zones = array_slice(array_keys($scores), 0, 2);
        $summary = $zones !== []
            ? 'Online and ready for dispatch Â· Familiar with ' . implode(', ', $zones)
            : 'Online and ready for dispatch Â· No saved zone history yet';

        return [
            'zones' => $zones,
            'scores' => $scores,
            'summary' => $summary,
            'total' => array_sum($scores),
        ];
    }

    protected function inferDispatchZoneLabel(?string $address): string
    {
        $normalized = strtolower((string) $address);

        if ($normalized === '') {
            return 'General Dispatch Zone';
        }

        $zoneMap = [
            'Makati Zone' => ['makati', 'salcedo', 'legazpi village', 'ayala', 'paseo de roxas'],
            'Taguig/BGC Zone' => ['taguig', 'bgc', 'bonifacio global city', 'market market'],
            'Quezon City Zone' => ['quezon city', 'qc', 'cubao', 'commonwealth', 'fairview'],
            'Pasig Zone' => ['pasig', 'ortigas', 'kapitolyo'],
            'Pasay Zone' => ['pasay', 'moa', 'mall of asia', 'edsa taft'],
            'Manila Zone' => ['manila', 'ermita', 'malate', 'sampaloc', 'quiapo'],
            'Muntinlupa Zone' => ['muntinlupa', 'alabang'],
            'ParaÃ±aque Zone' => ['paranaque', 'sucat', 'baclaran'],
        ];

        foreach ($zoneMap as $label => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return $label;
                }
            }
        }

        $segments = array_values(array_filter(array_map('trim', explode(',', (string) $address))));
        $fallback = $segments !== [] ? end($segments) : $address;
        $fallback = trim((string) $fallback);

        return $fallback !== '' ? ucfirst($fallback) . ' Zone' : 'General Dispatch Zone';
    }

    public function pendingBookingsCount()
    {
        return response()->json([
            'count' => Booking::where(function ($query) {
                $query->whereIn('status', ['requested', 'reviewed', 'delayed'])
                    ->orWhere(function ($returnedQuery) {
                        $returnedQuery->whereIn('status', ['confirmed', 'accepted', 'assigned'])
                            ->whereNotNull('returned_at');
                    });
            })->count(),
        ]);
    }

    public function assignBooking(Request $request, Booking $booking)
    {
        $request->merge([
            'rejection_reason' => $request->input('rejection_reason', $request->input('reason')),
        ]);

        $booking->loadMissing(['customer', 'truckType', 'unit.teamLeader']);

        if ($request->input('action') === 'accept' && blank($request->input('assigned_unit_id'))) {
            return response()->json([
                'success' => false,
                'message' => 'Please choose an available unit before sending the quotation.',
                'errors' => [
                    'assigned_unit_id' => ['Please choose an available unit before sending the quotation.'],
                ],
            ], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:accept,reject',
            'price' => [
                'nullable',
                'max:50',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if ($this->bookingService->parsePrice((string) $value) < 0) {
                        $fail('Enter a valid quotation amount.');
                    }
                },
            ],
            'additional_fee' => [
                'nullable',
                'max:50',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if ($this->bookingService->parsePrice((string) $value) < 0) {
                        $fail('Enter a valid additional fee.');
                    }
                },
            ],
            'dispatcher_note' => 'nullable|string|max:1000',
            'remarks' => 'nullable|string|max:1000',
            'rejection_reason' => 'nullable|string|max:1000',
            'assigned_unit_id' => [
                Rule::requiredIf(fn() => $request->input('action') === 'accept'),
                'nullable',
                'integer',
                'exists:units,id',
            ],
            'distance_km' => [
                Rule::requiredIf(fn() => $request->input('action') === 'accept' && $booking->status !== 'confirmed'),
                'nullable',
                'numeric',
                'min:0.01',
                'max:10000',
            ],
            'distance_fee' => [
                Rule::requiredIf(fn() => $request->input('action') === 'accept' && $booking->status !== 'confirmed'),
                'nullable',
                'numeric',
                'min:0',
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $booking) {
                    if ($request->input('action') !== 'accept' || $booking->status === 'confirmed' || $value === null || $value === '') {
                        return;
                    }

                    $distanceKm = (float) $request->input('distance_km', $booking->distance_km ?? 0);
                    $expectedDistanceFee = round(floor($distanceKm / 4) * 200, 2);

                    if (abs($expectedDistanceFee - (float) $value) > 0.11) {
                        $fail('Distance fee must match the per-4km rate.');
                    }
                },
            ],
        ]);

        $isReturnedTask = $booking->needs_reassignment;

        if (! in_array($booking->status, $this->reviewableStatuses, true) && ! $isReturnedTask) {
            return response()->json([
                'success' => false,
                'message' => 'This booking can no longer be revised from the dispatcher queue.',
            ], 422);
        }

        if ($validated['action'] === 'accept') {
            $selectedUnit = null;

            if (! empty($validated['assigned_unit_id'])) {
                $selectedUnit = Unit::with(['teamLeader', 'truckType'])->find($validated['assigned_unit_id']);
                $busyTeamLeaderIds = $this->teamLeaderAvailability->busyTeamLeaderIds();

                if (
                    ! $selectedUnit
                    || $selectedUnit->status !== 'available'
                    || empty($selectedUnit->team_leader_id)
                    || ! $this->teamLeaderAvailability->isOnline($selectedUnit->teamLeader)
                    || $busyTeamLeaderIds->contains((int) $selectedUnit->team_leader_id)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Team Leader not available. Please choose another unit.',
                    ], 422);
                }
            }

            $quotationNumber = $booking->quotation_number ?: $this->bookingService->generateQuotationNumber($booking);
            $dispatcherNote = filled($validated['dispatcher_note'] ?? null)
                ? trim(strip_tags((string) $validated['dispatcher_note']))
                : null;

            if ($isReturnedTask) {
                $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                    'status' => 'confirmed',
                    'assigned_unit_id' => $selectedUnit?->id ?? $booking->assigned_unit_id,
                    'assigned_team_leader_id' => $selectedUnit?->teamLeader?->id ?? $booking->assigned_team_leader_id,
                    'assigned_at' => now(),
                    'driver_name' => null,
                    'dispatcher_note' => $dispatcherNote,
                    'returned_at' => null,
                    'return_reason' => null,
                    'returned_by_team_leader_id' => null,
                    'customer_verification_status' => null,
                    'customer_verified_at' => null,
                    'completion_requested_at' => null,
                    'customer_verification_note' => null,
                ]));

                $booking->refresh()->loadMissing(['customer', 'truckType', 'unit.teamLeader']);
                BookingStatusUpdated::safeFire($booking);

                return response()->json([
                    'success' => true,
                    'message' => 'Returned task reassigned successfully. The selected team leader can accept it now.',
                    'status' => $booking->status,
                    'assigned_unit' => $selectedUnit?->name,
                    'team_leader' => $selectedUnit?->teamLeader?->full_name ?? $selectedUnit?->teamLeader?->name,
                ]);
            }



            if ($booking->status === 'confirmed') {
                $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                    'status' => 'assigned',
                    'assigned_unit_id' => $selectedUnit?->id,
                    'assigned_team_leader_id' => $selectedUnit?->teamLeader?->id,
                    'assigned_at' => now(),
                    'dispatcher_note' => $dispatcherNote,
                ]));

                $booking->refresh()->loadMissing(['customer', 'truckType', 'unit.teamLeader']);
                BookingStatusUpdated::safeFire($booking);

                return response()->json([
                    'success' => true,
                    'message' => 'Job started. The team leader can now accept the task.',
                    'status' => $booking->status,
                    'assigned_unit' => $selectedUnit?->name,
                    'team_leader' => $selectedUnit?->teamLeader?->full_name ?? $selectedUnit?->teamLeader?->name,
                ]);
            }
            $remarks = filled($validated['remarks'] ?? null)
                ? trim(strip_tags((string) $validated['remarks']))
                : $dispatcherNote;
            $distanceKm = round((float) ($validated['distance_km'] ?? ($booking->distance_km ?? 0)), 2);
            $unitBaseRate = (float) ($selectedUnit?->truckType?->base_rate ?? $booking->truckType?->base_rate ?? 0);
            $totals = $this->bookingService->calculateQuotationTotals(
                $booking,
                (string) ($validated['additional_fee'] ?? null),
                (string) ($validated['price'] ?? null),
                $distanceKm,
                0,
                $unitBaseRate,
            );

            $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                'status' => 'quotation_sent',
                'quotation_status' => 'active',
                'assigned_unit_id' => $selectedUnit?->id ?? $booking->assigned_unit_id,
                'assigned_team_leader_id' => $selectedUnit?->teamLeader?->id ?? $booking->assigned_team_leader_id,
                'base_rate' => $unitBaseRate,
                'per_km_rate' => 0,
                'distance_km' => $totals['distance_km'],
                'computed_total' => $totals['computed_total'],
                'additional_fee' => $totals['additional_fee'],
                'final_total' => $totals['final_total'],
                'quotation_number' => $quotationNumber,
                'quotation_generated' => true,
                'reviewed_at' => $booking->reviewed_at ?? now(),
                'quoted_at' => now(),
                'quotation_sent_at' => now(),
                'quotation_expires_at' => now()->addDays(7),
                'quotation_follow_up_sent_at' => null,
                'dispatcher_note' => $dispatcherNote,
                'remarks' => $remarks,
                'rejection_reason' => null,
                'final_quote_path' => null,
            ]));

            $booking->refresh()->loadMissing(['customer', 'truckType']);

            $quotation = $this->quotationService->createQuotation([
                'customer_id' => $booking->customer_id,
                'truck_type_id' => $booking->truck_type_id,
                'pickup_address' => $booking->pickup_address,
                'dropoff_address' => $booking->dropoff_address,
                'distance_km' => $totals['distance_km'],
                'eta_minutes' => $booking->eta_minutes,
                'vehicle_make' => $booking->vehicle_make,
                'vehicle_model' => $booking->vehicle_model,
                'vehicle_year' => $booking->vehicle_year,
                'vehicle_color' => $booking->vehicle_color,
                'vehicle_plate_number' => $booking->vehicle_plate_number,
                'vehicle_image_path' => $booking->vehicle_image_path,
                'estimated_price' => $totals['final_total'],
                'additional_fee' => $totals['additional_fee'],
                'service_type' => $booking->service_type ?? null,
            ]);

            $this->quotationService->sendQuotation($quotation);

            $initialQuotePath = $this->documentGenerationService->generateQuotation($booking);
            $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                'initial_quote_path' => $initialQuotePath,
            ]));

            $booking->refresh()->loadMissing(['customer', 'truckType']);





            BookingStatusUpdated::safeFire($booking);

            return response()->json([
                'success' => true,
                'message' => 'Quotation sent to the customer with the updated pricing breakdown.',
                'quotation_number' => $quotationNumber,
                'quoted_price' => number_format((float) $booking->final_total, 2),
                'status' => $booking->status,
                'assigned_unit' => $selectedUnit?->name,
                'team_leader' => $selectedUnit?->teamLeader?->full_name ?? $selectedUnit?->teamLeader?->name,
                'assigned_team_leader_id' => $booking->assigned_team_leader_id,
                'drivers_url' => route('admin.drivers'),
            ]);
        }

        $rejectionReason = trim((string) ($validated['rejection_reason'] ?? ''));

        if ($rejectionReason === '') {
            $rejectionReason = 'Your request could not be accommodated at this time. Please contact dispatch for assistance.';
        }


        $updatePayload = [
            'status' => 'cancelled',
            'quotation_status' => 'cancelled',
            'rejection_reason' => $rejectionReason,
        ];

        if ($isReturnedTask) {
            $updatePayload = array_merge($updatePayload, [
                'returned_at' => null,
                'return_reason' => null,
                'returned_by_team_leader_id' => null,
                'assigned_team_leader_id' => null,
                'assigned_unit_id' => null,
                'driver_name' => null,
            ]);
        }

        $booking->update($this->bookingService->filterPayloadForTable('bookings', $updatePayload));

        $booking->refresh()->loadMissing(['customer', 'truckType']);
        $this->syncCustomerRiskFlag($booking->customer, $rejectionReason);


        if ($isReturnedTask && str_contains(strtolower($booking->return_reason ?? ''), 'unreachable')) {
            if ($booking->customer && !$booking->customer->risk_level) {
                $booking->customer->update([
                    'risk_level' => 'watchlist',
                    'risk_reason' => 'Customer was unreachable when team leader attempted service',
                ]);

                Log::info('Customer auto-marked as watchlist due to unreachable status', [
                    'customer_id' => $booking->customer_id,
                    'booking_id' => $booking->id,
                    'dispatcher_id' => auth()->id(),
                ]);
            }
        }

        $booking->refresh()->loadMissing(['customer', 'truckType']);

        event(new \App\Events\BookingCancelled($booking));
        BookingStatusUpdated::safeFire($booking);

        $quotation = $this->quotationService->createQuotation([
            'customer_id' => $booking->customer_id,
            'truck_type_id' => $booking->truck_type_id,
            'pickup_address' => $booking->pickup_address,
            'dropoff_address' => $booking->dropoff_address,
            'distance_km' => $booking->distance_km,
            'eta_minutes' => $booking->eta_minutes ?? null,
            'vehicle_make' => $booking->vehicle_make ?? null,
            'vehicle_model' => $booking->vehicle_model ?? null,
            'vehicle_year' => $booking->vehicle_year ?? null,
            'vehicle_color' => $booking->vehicle_color ?? null,
            'vehicle_plate_number' => $booking->vehicle_plate_number ?? null,
            'vehicle_image_path' => $booking->vehicle_image_path ?? null,
            'estimated_price' => $booking->final_total,
            'additional_fee' => $booking->additional_fee ?? 0,
        ]);

        $this->quotationService->sendQuotation($quotation);

        return response()->json([
            'success' => true,
            'message' => 'Booking rejected and the customer was notified by email.',
        ]);
    }

    public function applyServiceFee(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'service_fee_amount' => 'required|numeric|min:0|max:100000',
            'service_fee_reason' => 'required|string|max:500',
        ]);

        $booking->update([
            'additional_fee' => $validated['service_fee_amount'],
            'dispatcher_note' => 'Service fee applied: ' . $validated['service_fee_reason'],
        ]);

        Log::info('Service fee applied to booking', [
            'booking_id' => $booking->id,
            'amount' => $validated['service_fee_amount'],
            'reason' => $validated['service_fee_reason'],
            'dispatcher_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service fee of â‚±' . number_format($validated['service_fee_amount'], 2) . ' applied successfully.',
        ]);
    }

    public function markCustomerRisk(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'risk_level' => 'required|in:low,medium,high,blacklist',
            'risk_reason' => 'required|string|max:500',
        ]);

        $customer = $booking->customer;

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found.',
            ], 404);
        }

        $riskLevel = $validated['risk_level'] === 'blacklist' ? 'blacklisted' : $validated['risk_level'];

        $customer->update([
            'risk_level' => $riskLevel,
            'risk_reason' => $validated['risk_reason'],
            'blacklisted_at' => $riskLevel === 'blacklisted' ? now() : null,
        ]);

        Log::warning('Customer risk level updated', [
            'customer_id' => $customer->id,
            'risk_level' => $riskLevel,
            'reason' => $validated['risk_reason'],
            'booking_id' => $booking->id,
            'dispatcher_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Customer marked as ' . $riskLevel . ' risk.',
            'risk_level' => $riskLevel,
        ]);
    }

    public function getQuotationDetails(Quotation $quotation)
    {
        $quotation->load(['customer', 'truckType', 'sourceBooking']);

        $distanceKm    = (float) ($quotation->distance_km ?? 0);
        $finalTotal    = (float) ($quotation->estimated_price ?? 0);
        $additionalFee = (float) ($quotation->additional_fee ?? 0);
        $discount      = (float) ($quotation->discount ?? 0);
        $basePrice     = (float) ($quotation->truckType?->base_rate ?? 0);
        $perKmRate     = (float) ($quotation->truckType?->per_km_rate ?? 0);
        $kmIncrements  = (int) floor($distanceKm / 4);
        $distanceFee   = round($kmIncrements * 200.0, 2);

        $customerName = $quotation->customer->full_name
            ?? $quotation->customer->name
            ?? 'N/A';

        return response()->json([
            'success'   => true,
            'quotation' => [
                'id'                    => $quotation->id,
                'quotation_number'      => $quotation->quotation_number,
                'customer_name'         => $customerName,
                'customer_phone'        => $quotation->customer->phone ?? 'N/A',
                'customer_email'        => $quotation->customer->email ?? null,
                'pickup_address'        => $quotation->pickup_address,
                'dropoff_address'       => $quotation->dropoff_address,
                'distance_km'           => $distanceKm,
                'distance_km_formatted' => number_format($distanceKm, 2),
                'truck_type'            => $quotation->truckType->name ?? 'N/A',
                'truck_type_id'         => $quotation->truck_type_id,
                'truck_class'           => $quotation->truckType?->class ?? null,
                'base_price'            => $basePrice,
                'per_km_rate'           => $perKmRate,
                'km_increments'         => $kmIncrements,
                'km_charge_per_increment' => 200,
                'distance_fee'          => $distanceFee,
                'excess_km'             => 0,
                'has_excess'            => false,
                'additional_fee'        => $additionalFee,
                'discount'              => $discount,
                'estimated_price'       => $finalTotal,
                'subtotal'              => $finalTotal,
                'counter_offer_amount'  => $quotation->counter_offer_amount,
                'response_note'         => $quotation->response_note,
                'status'                => $quotation->status,
                'service_type'          => $quotation->service_type,
                'link_version'          => $quotation->link_version ?? 1,
                'vehicle_image_paths'   => $quotation->vehicle_image_paths ?? [],
                'extra_vehicles'        => $this->enrichExtraVehicles($quotation->extra_vehicles ?? []),
                'total_vehicles'        => 1 + count($quotation->extra_vehicles ?? []),
                'created_at'            => $quotation->created_at->format('M d, Y h:i A'),
                'vehicle_make'          => $quotation->vehicle_make,
                'vehicle_model'         => $quotation->vehicle_model,
                'vehicle_year'          => $quotation->vehicle_year,
                'vehicle_color'         => $quotation->vehicle_color,
                'vehicle_plate_number'  => $quotation->vehicle_plate_number,
                'notes'                 => $quotation->pickup_notes,
                'source_booking_id'     => $quotation->source_booking_id,
                'source_booking_code'   => $quotation->sourceBooking?->booking_code,
                'is_mobile_booking'     => $quotation->source_booking_id !== null,
            ],
        ]);
    }

    private function enrichExtraVehicles(array $vehicles): array
    {
        if (empty($vehicles)) return [];

        $truckTypeIds   = array_unique(array_filter(array_column($vehicles, 'truck_type_id')));
        $vehicleTypeIds = array_unique(array_filter(array_column($vehicles, 'vehicle_type_id')));

        $truckTypes   = TruckType::whereIn('id', $truckTypeIds)
                                 ->get(['id', 'name', 'class', 'base_rate'])->keyBy('id');
        $vehicleTypes = VehicleType::whereIn('id', $vehicleTypeIds)
                                   ->get(['id', 'name'])->keyBy('id');

        return array_map(function (array $ev) use ($truckTypes, $vehicleTypes): array {
            $tt = isset($ev['truck_type_id']) ? $truckTypes->get($ev['truck_type_id']) : null;
            $vt = isset($ev['vehicle_type_id']) ? $vehicleTypes->get($ev['vehicle_type_id']) : null;
            return array_merge($ev, [
                'truck_type_name' => $tt?->name  ?? 'Unknown Truck',
                'truck_class'     => $tt?->class  ?? null,
                'base_rate'       => (float) ($tt?->base_rate ?? 0),
                'vehicle_name'    => $vt?->name  ?? null,
            ]);
        }, $vehicles);
    }

    public function sendQuotation(Request $request, Quotation $quotation)
    {
        if ($quotation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This quotation has already been sent or is no longer pending.',
            ], 422);
        }

        $validated = $request->validate([
            'expiry_hours' => 'nullable|integer|min:1|max:720',
            'assigned_unit_id' => 'nullable|integer|exists:units,id',
        ]);

        if (empty($validated['assigned_unit_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Please assign a unit before sending the quotation.',
            ], 422);
        }

        $selectedUnit = Unit::with(['teamLeader', 'truckType'])->find($validated['assigned_unit_id']);
        $unitBaseRate = (float) ($selectedUnit?->truckType?->base_rate ?? 0);


        $distanceKm   = (float) ($quotation->distance_km ?? 0);
        $kmIncrements = (int) floor($distanceKm / 4);
        $kmCharge     = round($kmIncrements * 200.0, 2);
        $additionalFee = (float) ($quotation->additional_fee ?? 0);
        $newEstimatedPrice = round($unitBaseRate + $kmCharge + $additionalFee, 2);

        $quotation->update(['estimated_price' => $newEstimatedPrice]);

        $booking = Booking::where('quotation_id', $quotation->id)->first();
        if ($booking) {
            $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                'assigned_unit_id' => $selectedUnit?->id,
                'assigned_team_leader_id' => $selectedUnit?->teamLeader?->id,
                'base_rate' => $unitBaseRate,
                'per_km_rate' => 0,
                'final_total' => $newEstimatedPrice,
            ]));
        }

        $expiryHours = ($quotation->service_type === 'book_now') ? 1 : ($validated['expiry_hours'] ?? 168);

        $this->quotationService->sendQuotation($quotation, $expiryHours);

        return response()->json([
            'success' => true,
            'message' => 'Quotation sent to customer successfully.',
            'quotation_number' => $quotation->quotation_number,
        ]);
    }

    public function cancelQuotation(Request $request, Quotation $quotation)
    {
        if (!in_array($quotation->status, ['pending', 'sent'])) {
            return response()->json([
                'success' => false,
                'message' => 'This quotation cannot be cancelled.',
            ], 422);
        }

        $quotation->update([
            'status' => 'rejected',
            'responded_at' => now(),
            'response_note' => 'Cancelled by dispatcher at customer request',
        ]);

        Log::info('Quotation cancelled by dispatcher', [
            'quotation_id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'dispatcher_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Quotation cancelled successfully.',
        ]);
    }

    public function updateQuotationPrice(Request $request, Quotation $quotation)
    {
        $validated = $request->validate([
            'new_price' => 'required|numeric|min:0.01',
            'additional_fee' => 'nullable|numeric',
            'note' => 'nullable|string|max:1000',
            'assigned_unit_id' => 'nullable|integer|exists:units,id',
        ]);

        // Keep the quotation in its current stage unless it was pending (pending resets back to pending).
        // Revising a sent/negotiating quotation keeps it at 'sent' and clears the counter offer.
        $previousStatus = $quotation->status;
        $newStatus = in_array($previousStatus, ['sent', 'negotiating']) ? 'sent' : 'pending';

        $updateData = [
            'estimated_price'      => $validated['new_price'],
            'additional_fee'       => $validated['additional_fee'] ?? 0,
            'discount'             => 0,
            'counter_offer_amount' => null,
            'response_note'        => null,
            'status'               => $newStatus,
        ];

        $quotation->update($updateData);

        if (!empty($validated['assigned_unit_id'])) {
            $selectedUnit = Unit::with(['teamLeader', 'truckType'])->find($validated['assigned_unit_id']);
            $booking = Booking::where('quotation_id', $quotation->id)->first();
            if ($booking && $selectedUnit) {
                $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                    'assigned_unit_id' => $selectedUnit->id,
                    'assigned_team_leader_id' => $selectedUnit->teamLeader?->id,
                    'base_rate' => (float) ($selectedUnit->truckType?->base_rate ?? 0),
                    'per_km_rate' => 0,
                ]));
            }
        }

        $quotation->increment('link_version');


        if ($quotation->customer && $quotation->customer->email) {
            try {
                Mail::to($quotation->customer->email)
                    ->send(new \App\Mail\QuotationUpdatedMail($quotation));
            } catch (\Exception $e) {
                Log::error('Failed to send quotation update email', [
                    'quotation_id' => $quotation->id,
                    'customer_email' => $quotation->customer->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Quotation price updated and email sent to customer successfully.',
            'new_price' => number_format($validated['new_price'], 2),
        ]);
    }

    public function extendQuotation(Request $request, Quotation $quotation)
    {
        $validated = $request->validate([
            'additional_hours' => 'required|integer|min:1|max:168',
        ]);

        $this->quotationService->extendQuotation($quotation, $validated['additional_hours']);

        return response()->json([
            'success' => true,
            'message' => 'Quotation expiry extended by ' . $validated['additional_hours'] . ' hours.',
        ]);
    }

    public function viewQuotationResponse(Quotation $quotation)
    {
        $quotation->load(['customer', 'truckType']);

        return response()->json([
            'success' => true,
            'quotation' => [
                'quotation_number' => $quotation->quotation_number,
                'customer_name' => $quotation->customer->name,
                'estimated_price' => number_format($quotation->estimated_price, 2),
                'counter_offer_amount' => $quotation->counter_offer_amount ? number_format($quotation->counter_offer_amount, 2) : null,
                'response_note' => $quotation->response_note,
                'responded_at' => $quotation->responded_at?->format('M d, Y h:i A'),
                'status' => $quotation->status,
            ],
        ]);
    }

    public function unitLocations(): \Illuminate\Http\JsonResponse
    {
        $units = Unit::with(['teamLeader', 'truckType'])
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->where('status', '!=', 'maintenance')
            ->get();

        $data = $units->map(function (Unit $unit) {
            $secsAgo = $unit->location_updated_at
                ? (int) $unit->location_updated_at->diffInSeconds(now())
                : null;

            return [
                'unit_id'             => $unit->id,
                'unit_name'           => $unit->name ?? 'Unit',
                'plate_number'        => $unit->plate_number ?? '',
                'truck_type_name'     => $unit->truckType?->name ?? '',
                'lat'                 => $unit->current_lat,
                'lng'                 => $unit->current_lng,
                'status'              => $unit->status,
                'team_leader_name'    => $unit->teamLeader?->name ?? 'Unknown',
                'is_online'           => $secsAgo !== null && $secsAgo < 300,
                'updated_seconds_ago' => $secsAgo,
            ];
        });

        return response()->json($data);
    }
}
