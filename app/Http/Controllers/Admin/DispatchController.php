<?php

namespace App\Http\Controllers\Admin;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Mail\BookingAcceptedMail;
use App\Mail\BookingRejectedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\DocumentGenerationService;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class DispatchController extends Controller
{
    protected BookingService $bookingService;
    protected DocumentGenerationService $documentGenerationService;
    protected TeamLeaderAvailabilityService $teamLeaderAvailability;

    protected array $reviewableStatuses = ['requested', 'reviewed', 'quoted', 'quotation_sent'];

    public function __construct(
        BookingService $bookingService,
        DocumentGenerationService $documentGenerationService,
        TeamLeaderAvailabilityService $teamLeaderAvailability,
    ) {
        $this->bookingService = $bookingService;
        $this->documentGenerationService = $documentGenerationService;
        $this->teamLeaderAvailability = $teamLeaderAvailability;
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
                $query->whereIn('status', ['requested', 'reviewed', 'delayed'])
                    ->orWhere(function ($returnedQuery) {
                        $returnedQuery->whereIn('status', ['confirmed', 'accepted', 'assigned'])
                            ->whereNotNull('returned_at');
                    });
            })
            ->get();

        $delayedRequests = $queueBase
            ->filter(fn(Booking $booking) => ! $booking->needs_reassignment && $booking->is_dispatch_delayed)
            ->sortBy(fn(Booking $booking) => $booking->scheduled_for?->getTimestamp() ?? 0)
            ->values()
            ->map(function (Booking $booking) {
                $booking->queue_bucket = 'delayed';

                return $booking;
            });

        $returnedRequests = $queueBase
            ->filter(fn(Booking $booking) => $booking->needs_reassignment)
            ->sortByDesc(fn(Booking $booking) => $booking->returned_at?->getTimestamp() ?? 0)
            ->values()
            ->map(function (Booking $booking) {
                $booking->queue_bucket = 'returned';

                return $booking;
            });

        $bookNowRequests = $queueBase
            ->filter(fn(Booking $booking) => ! $booking->needs_reassignment
                && ! $booking->is_dispatch_delayed
                && $booking->status !== 'reviewed'
                && (! $booking->is_scheduled || $booking->is_due_for_dispatch))
            ->sortBy(fn(Booking $booking) => $booking->is_due_for_dispatch
                ? ($booking->scheduled_for?->getTimestamp() ?? 0)
                : ($booking->created_at?->getTimestamp() ?? 0))
            ->values()
            ->map(function (Booking $booking) {
                $booking->queue_bucket = 'book-now';

                return $booking;
            });

        $scheduledRequests = $queueBase
            ->filter(fn(Booking $booking) => ! $booking->needs_reassignment
                && ! $booking->is_dispatch_delayed
                && $booking->status !== 'reviewed'
                && $booking->is_scheduled
                && ! $booking->is_due_for_dispatch)
            ->sortBy(fn(Booking $booking) => $booking->scheduled_for?->getTimestamp() ?? PHP_INT_MAX)
            ->values()
            ->map(function (Booking $booking) {
                $booking->queue_bucket = 'scheduled';

                return $booking;
            });

        $negotiationRequests = $queueBase
            ->filter(fn(Booking $booking) => ! $booking->needs_reassignment && ! $booking->is_dispatch_delayed && $booking->status === 'reviewed')
            ->sortByDesc(fn(Booking $booking) => $booking->updated_at?->getTimestamp() ?? 0)
            ->values()
            ->map(function (Booking $booking) {
                $booking->queue_bucket = 'negotiation';

                return $booking;
            });

        $incomingRequests = $returnedRequests
            ->concat($bookNowRequests)
            ->concat($scheduledRequests)
            ->concat($delayedRequests)
            ->concat($negotiationRequests)
            ->values();

        $queueCounts = [
            'all' => $incomingRequests->count(),
            'returned' => $returnedRequests->count(),
            'book-now' => $bookNowRequests->count(),
            'scheduled' => $scheduledRequests->count(),
            'delayed' => $delayedRequests->count(),
            'negotiation' => $negotiationRequests->count(),
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
                    'label' => trim(($unit->name ?? 'Unit') . ' · ' . ($unit->plate_number ?? 'No plate')),
                    'truck_type_id' => (int) ($unit->truck_type_id ?? 0),
                    'truck_type' => $unit->truckType->name ?? 'Unknown truck type',
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

                return $booking;
            })
            ->values();

        return view('admin-dashboard.pages.dispatch', compact('incomingRequests', 'availableUnits', 'queueCounts'));
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
            ? 'Online and ready for dispatch · Familiar with ' . implode(', ', $zones)
            : 'Online and ready for dispatch · No saved zone history yet';

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
            'Parañaque Zone' => ['paranaque', 'sucat', 'baclaran'],
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
                Rule::requiredIf(fn() => $request->input('action') === 'accept'),
                'nullable',
                'numeric',
                'min:0.01',
                'max:10000',
            ],
            'distance_fee' => [
                Rule::requiredIf(fn() => $request->input('action') === 'accept'),
                'nullable',
                'numeric',
                'min:0',
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $booking) {
                    if ($request->input('action') !== 'accept' || $value === null || $value === '') {
                        return;
                    }

                    $distanceKm = (float) $request->input('distance_km', $booking->distance_km ?? 0);
                    $expectedDistanceFee = round($distanceKm * (float) ($booking->per_km_rate ?? 0), 2);

                    if (abs($expectedDistanceFee - (float) $value) > 0.11) {
                        $fail('Distance fee must match the distance and per KM rate.');
                    }
                },
            ],
            'discount_percentage' => [
                Rule::requiredIf(fn() => $request->input('action') === 'accept'),
                'nullable',
                'numeric',
                'min:0',
                'max:100',
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
                $selectedUnit = Unit::with(['teamLeader'])->find($validated['assigned_unit_id']);
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
                event(new BookingStatusUpdated($booking));

                return response()->json([
                    'success' => true,
                    'message' => 'Returned task reassigned successfully. The selected team leader can accept it now.',
                    'status' => $booking->status,
                    'assigned_unit' => $selectedUnit?->name,
                    'team_leader' => $selectedUnit?->teamLeader?->full_name ?? $selectedUnit?->teamLeader?->name,
                ]);
            }
            $remarks = filled($validated['remarks'] ?? null)
                ? trim(strip_tags((string) $validated['remarks']))
                : $dispatcherNote;
            $distanceKm = round((float) ($validated['distance_km'] ?? ($booking->distance_km ?? 0)), 2);
            $discountPercentage = round((float) ($validated['discount_percentage'] ?? ($booking->discount_percentage ?? 0)), 2);
            $totals = $this->bookingService->calculateQuotationTotals(
                $booking,
                (string) ($validated['additional_fee'] ?? null),
                (string) ($validated['price'] ?? null),
                $distanceKm,
                $discountPercentage,
            );

            $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                'status' => 'quotation_sent',
                'quotation_status' => 'active',
                'assigned_unit_id' => $selectedUnit?->id ?? $booking->assigned_unit_id,
                'assigned_team_leader_id' => $selectedUnit?->teamLeader?->id ?? $booking->assigned_team_leader_id,
                'distance_km' => $totals['distance_km'],
                'computed_total' => $totals['computed_total'],
                'discount_percentage' => $totals['discount_percentage'],
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

            $initialQuotePath = $this->documentGenerationService->generateQuotation($booking);
            $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                'initial_quote_path' => $initialQuotePath,
            ]));

            $booking->refresh()->loadMissing(['customer', 'truckType']);

            if (filled($booking->customer?->email)) {
                Mail::to($booking->customer->email)->send(new BookingAcceptedMail($booking));
            }

            event(new BookingStatusUpdated($booking));

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

        $booking->update($this->bookingService->filterPayloadForTable('bookings', [
            'status' => 'cancelled',
            'quotation_status' => 'cancelled',
            'rejection_reason' => $rejectionReason,
        ]));

        $booking->refresh()->loadMissing(['customer', 'truckType']);
        $this->syncCustomerRiskFlag($booking->customer, $rejectionReason);
        $booking->refresh()->loadMissing(['customer', 'truckType']);

        event(new \App\Events\BookingCancelled($booking));
        event(new BookingStatusUpdated($booking));

        if (filled($booking->customer?->email)) {
            Mail::to($booking->customer->email)->send(new BookingRejectedMail($booking));
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking rejected and the customer was notified by email.',
        ]);
    }
}
