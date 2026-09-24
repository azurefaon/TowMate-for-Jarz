<?php

namespace App\Http\Controllers\Admin;

use App\Events\BookingStatusUpdated;
use App\Exceptions\Booking\ScheduledQuoteCutoffPassedException;
use App\Http\Controllers\Controller;
use App\Mail\BookingAcceptedMail;
use App\Mail\BookingRejectedMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\PriceAdjustment;
use App\Models\Quotation;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingService;
use App\Services\DocumentGenerationService;
use App\Services\QuotationService;
use App\Services\ReturnReasonHandler;
use App\Services\TeamLeaderAvailabilityService;
use App\Services\UnitAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    protected UnitAvailabilityService $unitAvailability;

    protected array $reviewableStatuses = Booking::REVIEWABLE_STATUSES;
    protected array $operationallyAssignedStatuses = ['confirmed', 'scheduled_confirmed', 'assigned', 'returned'];

    public function __construct(
        BookingService $bookingService,
        DocumentGenerationService $documentGenerationService,
        TeamLeaderAvailabilityService $teamLeaderAvailability,
        ReturnReasonHandler $returnReasonHandler,
        QuotationService $quotationService,
        UnitAvailabilityService $unitAvailability
    ) {
        $this->bookingService = $bookingService;
        $this->documentGenerationService = $documentGenerationService;
        $this->teamLeaderAvailability = $teamLeaderAvailability;
        $this->returnReasonHandler = $returnReasonHandler;
        $this->quotationService = $quotationService;
        $this->unitAvailability = $unitAvailability;
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
            ->whereIn('status', ['accepted', 'assigned', 'returned'])
            ->get();

        $returnedRequests = $queueBase
            ->filter(fn(Booking $booking) => $booking->needs_reassignment === true)
            ->sortByDesc(fn(Booking $booking) => $booking->returned_at?->getTimestamp() ?? 0)
            ->values()
            ->map(function (Booking $booking) {
                $booking->queue_bucket = 'returned';
                return $booking;
            });


        $activeBookings = $queueBase
            ->filter(function (Booking $booking) {
                return $booking->status === 'accepted'
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

        $bookNowRequests = Booking::with(['customer', 'truckType'])
            ->whereIn('status', $this->reviewableStatuses)
            ->where(function ($q) {
                $q->whereNull('service_type')
                    ->orWhere('service_type', 'book_now');
            })
            ->oldest('created_at')
            ->get()
            ->map(fn($b) => tap($b, function ($b) {
                $b->queue_bucket = 'book-now';
                $b->dispatch_zone_label = $this->inferDispatchZoneLabel($b->pickup_address);
            }));

        $bookNowBookingIds = $bookNowRequests->pluck('id');
        $bookNowQuotationMap = \App\Models\Quotation::whereIn('source_booking_id', $bookNowBookingIds)
            ->current()
            ->whereIn('status', ['pending', 'draft', 'sent', 'negotiating', 'accepted', 'expired', 'price_review_requested'])
            ->orderByDesc('id')
            ->get(['id', 'status', 'source_booking_id', 'quotation_number', 'estimated_price', 'counter_offer_amount', 'price_change_log', 'additional_fee'])
            ->unique('source_booking_id')
            ->keyBy('source_booking_id');
        $bookNowRequests = $bookNowRequests->map(function ($b) use ($bookNowQuotationMap) {
            $q = $bookNowQuotationMap->get($b->id);
            $b->active_quotation_id     = $q?->id;
            $b->active_quotation_number = $q?->quotation_number;
            $b->active_quotation_status = $q?->status;
            $b->active_quotation_price   = $q?->estimated_price;
            $b->active_quotation_counter = $q?->counter_offer_amount;
            $b->active_quotation_price_change_log = $q?->price_change_log ?? [];
            
            
            
            
            
            $b->active_quotation_additional_fee = $q?->additional_fee ?? $b->additional_fee;
            return $b;
        });

        $scheduledRequests = Booking::with(['customer', 'truckType', 'vehicleType'])
            ->whereIn('status', ['scheduled_confirmed', 'scheduled'])
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", ['scheduled_confirmed'])
            ->oldest('created_at')
            ->get()
            ->map(fn($b) => tap($b, fn($b) => $b->queue_bucket = 'scheduled'));

        $scheduledBookingIds = $scheduledRequests->pluck('id');
        $activeQuotationMap = \App\Models\Quotation::whereIn('source_booking_id', $scheduledBookingIds)
            ->current()
            ->whereIn('status', ['pending', 'draft', 'sent', 'negotiating', 'price_review_requested', 'accepted', 'expired'])
            ->orderByDesc('id')
            ->get(['id', 'status', 'source_booking_id', 'quotation_number', 'estimated_price', 'counter_offer_amount', 'price_change_log', 'additional_fee', 'expires_at'])
            ->unique('source_booking_id')
            ->keyBy('source_booking_id');



        $scheduledRequests = $scheduledRequests->map(function ($b) use ($activeQuotationMap) {
            $q = $activeQuotationMap->get($b->id);
            $b->active_quotation_id     = $q?->id;
            $b->active_quotation_number = $q?->quotation_number;
            $b->active_quotation_status = $q?->status;
            $b->active_quotation_price   = $q?->estimated_price;
            $b->active_quotation_counter = $q?->counter_offer_amount;
            $b->active_quotation_price_change_log = $q?->price_change_log ?? [];
            $b->active_quotation_additional_fee = $q?->additional_fee ?? $b->additional_fee;
            $b->active_quotation_expires_at = $q?->expires_at;
            $b->dispatch_zone_label = $this->inferDispatchZoneLabel($b->pickup_address);

            
            
            
            
            
            if ($b->status === 'scheduled_confirmed') {
                $b->filter_bucket = $b->scheduling_bucket; 
            } elseif (in_array($b->active_quotation_status, ['sent', 'negotiating', 'price_review_requested'], true)) {
                $b->filter_bucket = 'quote-sent';
            } else {
                $b->filter_bucket = 'needs-quote';
            }

            return $b;
        });

        $scheduledGroupCodes = $scheduledRequests->pluck('group_code')->filter()->unique()->values();
        if ($scheduledGroupCodes->isNotEmpty()) {
            $scheduledSiblingsByGroup = Booking::whereIn('group_code', $scheduledGroupCodes)
                ->whereNotIn('id', $scheduledRequests->pluck('id'))
                ->with('truckType')
                ->get(['id', 'booking_code', 'status', 'service_type', 'truck_type_id', 'scheduled_date', 'scheduled_time', 'group_code', 'final_total'])
                ->groupBy('group_code');
            $scheduledRequests = $scheduledRequests->map(function ($b) use ($scheduledSiblingsByGroup) {
                $b->group_siblings = $b->group_code
                    ? $scheduledSiblingsByGroup->get($b->group_code, collect())
                    : collect();
                return $b;
            });
        }

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

        $notRespondingBookings = Booking::with(['customer', 'truckType'])
            ->where('status', 'not_responding')
            ->latest('updated_at')
            ->get()
            ->map(function (Booking $booking) {
                $booking->queue_bucket = 'not_responding';
                $booking->needs_reassignment = false;
                $booking->needs_assignment = false;
                return $booking;
            });

        $incomingRequests = $returnedRequests
            ->concat($activeBookings)
            ->concat($readyCompletionBookings)
            ->concat($notRespondingBookings)
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

        $incomingGroupCodes = $incomingRequests->pluck('group_code')->filter()->unique()->values();
        if ($incomingGroupCodes->isNotEmpty()) {
            $incomingSiblingsByGroup = Booking::whereIn('group_code', $incomingGroupCodes)
                ->whereNotIn('id', $incomingRequests->pluck('id'))
                ->with('truckType')
                ->get(['id', 'booking_code', 'status', 'service_type', 'truck_type_id', 'scheduled_date', 'scheduled_time', 'group_code', 'final_total'])
                ->groupBy('group_code');
            $incomingRequests = $incomingRequests->map(function ($b) use ($incomingSiblingsByGroup) {
                $b->group_siblings = $b->group_code
                    ? $incomingSiblingsByGroup->get($b->group_code, collect())
                    : collect();
                return $b;
            });
        }

        $groupedIncoming = $incomingRequests->groupBy(fn($b) => $b->group_code ?: $b->booking_code);
        $groupedBookNow  = $bookNowRequests
            ->groupBy(fn($b) => $b->group_code ?: $b->booking_code)
            ->map(fn($group) => $group->sortBy(fn($booking) => [
                $booking->active_quotation_id ? 0 : 1,
                (int) $booking->id,
            ])->values());
        $groupedScheduled = $scheduledRequests->groupBy(fn($b) => $b->group_code ?: $b->booking_code);

        $pendingQuotationCount = Quotation::where('status', 'pending')->current()->count();

        $queueCounts = [
            'all' => $incomingRequests->count(),
            'returned' => $returnedRequests->count(),
            'active' => $activeBookings->count(),
            'ready_completion' => $readyCompletionBookings->count(),
            'not_responding' => $notRespondingBookings->count(),
            'pending-quotations' => $pendingQuotationCount,
            'book-now' => $groupedBookNow->count(),
            'scheduled' => $scheduledRequests->count(),
            'scheduled-needs-quote' => $scheduledRequests->where('filter_bucket', 'needs-quote')->count(),
            'scheduled-quote-sent' => $scheduledRequests->where('filter_bucket', 'quote-sent')->count(),
            'scheduled-confirmed' => $scheduledRequests->where('filter_bucket', 'confirmed')->count(),
            'scheduled-upcoming' => $scheduledRequests->where('filter_bucket', 'upcoming')->count(),
            'scheduled-ready' => $scheduledRequests->where('filter_bucket', 'ready')->count(),
            'scheduled-overdue' => $scheduledRequests->where('filter_bucket', 'overdue')->count(),
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

        $reservedUnitBookings = Booking::unitReservations()
            ->get(['id', 'booking_code', 'selected_unit_id'])
            ->keyBy('selected_unit_id');

        $availableUnitCandidates = Unit::with(['truckType', 'driver', 'teamLeader'])
            ->where('status', 'available')
            ->whereNotNull('team_leader_id')
            ->orderBy('name')
            ->get();

        $availabilityByUnitId = $this->unitAvailability->evaluateMany($availableUnitCandidates);

        $availableUnitProfiles = $availableUnitCandidates
            ->map(function (Unit $unit) use ($busyTeamLeaderIds, $reservedUnitBookings, $availabilityByUnitId) {
                $teamLeaderId = (int) ($unit->team_leader_id ?? 0);
                $override = $unit->teamLeader ? $this->teamLeaderAvailability->operationalOverride($unit->teamLeader) : null;
                $overrideStatus = $override['status'] ?? null;
                $isAvailableForDispatch = (bool) ($availabilityByUnitId->get($unit->id)['available'] ?? false);
                $hasReadyLeader = $teamLeaderId > 0
                    && $isAvailableForDispatch
                    && ! $busyTeamLeaderIds->contains($teamLeaderId)
                    && ! in_array($overrideStatus, ['busy', 'unavailable'], true);
                $coverage = $this->resolveUnitCoverageProfile($unit);

                return [
                    'id' => $unit->id,
                    'label' => trim(($unit->name ?? 'Unit') . ' · ' . ($unit->plate_number ?? 'No plate')),
                    'truck_type_id' => (int) ($unit->truck_type_id ?? 0),
                    'truck_type' => $unit->truckType->name ?? 'Unknown truck type',
                    'truck_class' => $unit->truckType->class ?? '',
                    'base_rate' => (float) ($unit->truckType->base_rate ?? 0),
                    'per_km_rate' => (float) ($unit->truckType->per_km_rate ?? 0),
                    'team_leader_name' => $unit->teamLeader->full_name ?? $unit->teamLeader->name ?? 'No team leader',
                    'driver_name' => $unit->driver->full_name ?? $unit->driver->name ?? $unit->driver_name ?? 'No saved driver',
                    'crew_names' => collect(Unit::SLOT_COLUMNS)
                        ->reject(fn($col) => $col === 'driver_name') 
                        ->map(fn($col) => $unit->{$col})
                        ->filter()
                        ->values()
                        ->all(),
                    'status_summary' => $hasReadyLeader ? $coverage['summary'] : 'Team leader is not ready for dispatch',
                    'coverage_zones' => $coverage['zones'],
                    'coverage_scores' => $coverage['scores'],
                    'coverage_total' => $coverage['total'],
                    'selectable' => $hasReadyLeader,
                    'reserved_by_booking_code' => optional($reservedUnitBookings->get($unit->id))->booking_code,
                ];
            });

        $availableUnits = $availableUnitProfiles
            ->filter(fn(array $unit) => $unit['selectable'])
            ->sortByDesc('coverage_total')
            ->values();

        
        
        
        
        
        $bookNowRequests = $bookNowRequests->map(function (Booking $booking) use ($availableUnits) {
            $recommendation = $this->recommendUnitForBooking($booking, $availableUnits);
            $booking->recommended_unit_id = $recommendation['id'] ?? null;
            $booking->recommended_unit_label = $recommendation['label'] ?? 'Dispatcher will choose the best ready unit.';
            $booking->recommended_unit_summary = $recommendation['recommendation'] ?? 'No saved zone history yet; dispatcher can still assign any ready crew.';
            return $booking;
        });

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

        $returnReasonHandler = $this->returnReasonHandler;


        ['allQuotations' => $allQuotations, 'quotationStats' => $quotationStats] = $this->buildFloatingQuotationsData();

        return view('admin-dashboard.pages.dispatch', compact('incomingRequests', 'availableUnits', 'queueCounts', 'zones', 'teamLeaderStatuses', 'returnReasonHandler', 'allQuotations', 'quotationStats', 'bookNowRequests', 'scheduledRequests', 'groupedIncoming', 'groupedBookNow', 'groupedScheduled'));
    }

    
    protected function buildFloatingQuotationsData(): array
    {
        $allQuotations = Quotation::with(['customer', 'truckType', 'sourceBooking'])
            ->whereIn('status', ['draft', 'pending', 'sent', 'negotiating'])
            ->current()
            ->where(function ($q) {
                $q->where('service_type', '!=', 'schedule')
                    ->orWhereNull('service_type');
            })
            ->orderByRaw("CASE WHEN status = 'draft' THEN 0 WHEN status = 'pending' THEN 1 WHEN status = 'negotiating' THEN 2 ELSE 3 END")
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($quotation) {
                $timeRemaining = $quotation->getTimeRemaining();
                $quotation->urgency_level = $timeRemaining['urgency'] ?? 'normal';
                $quotation->time_remaining_text = $timeRemaining['message'] ?? 'N/A';
                return $quotation;
            });

        $quotationGroupCodes = $allQuotations
            ->filter(fn($q) => $q->sourceBooking?->group_code)
            ->map(fn($q) => $q->sourceBooking->group_code)
            ->unique()->values();
        $quotationSourceIds = $allQuotations
            ->filter(fn($q) => $q->sourceBooking)
            ->map(fn($q) => $q->sourceBooking->id)
            ->filter()->values();

        $quotationSiblingsByGroup = collect();
        if ($quotationGroupCodes->isNotEmpty()) {
            $quotationSiblingsByGroup = Booking::whereIn('group_code', $quotationGroupCodes)
                ->when($quotationSourceIds->isNotEmpty(), fn($q) => $q->whereNotIn('id', $quotationSourceIds))
                ->with('truckType')
                ->get(['id', 'booking_code', 'status', 'service_type', 'truck_type_id', 'scheduled_date', 'scheduled_time', 'group_code', 'final_total'])
                ->groupBy('group_code');
        }

        $allQuotations = $allQuotations->map(function ($q) use ($quotationSiblingsByGroup) {
            $groupCode = $q->sourceBooking?->group_code;
            $siblings  = $groupCode ? $quotationSiblingsByGroup->get($groupCode, collect()) : collect();
            $q->group_sibling_count = $siblings->count();
            $q->group_siblings      = $siblings;
            return $q;
        });

        $quotationStats = [
            'total' => Quotation::current()->count(),
            'active' => Quotation::whereIn('status', ['pending', 'sent'])->current()->count(),
            'urgent' => $allQuotations->where('urgency_level', 'urgent')->count(),
            'expired' => Quotation::where('status', 'expired')->current()->count(),
        ];

        return ['allQuotations' => $allQuotations, 'quotationStats' => $quotationStats];
    }

    
    public function floatingQuotationsPanel(): \Illuminate\Http\JsonResponse
    {
        $data = $this->buildFloatingQuotationsData();

        return response()->json([
            'html' => view('admin-dashboard.pages._quotations-card', $data)->render(),
        ]);
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

        
        
        
        
        
        
        $sameClassUnits = $availableUnits->filter(
            fn(array $unit) => (int) ($unit['truck_type_id'] ?? 0) === (int) ($booking->truck_type_id ?? 0)
                && (empty($unit['reserved_by_booking_code']) || $unit['reserved_by_booking_code'] === $booking->booking_code)
        );

        if ($sameClassUnits->isEmpty()) {
            return null;
        }

        $recommended = $sameClassUnits
            ->map(function (array $unit) use ($dispatchZone) {
                $zoneMatches = (int) ($unit['coverage_scores'][$dispatchZone] ?? 0);
                $score = 1 + ($zoneMatches * 4) + ((int) ($unit['coverage_total'] ?? 0) > 0 ? 1 : 0);

                $recommendation = $zoneMatches > 0
                    ? 'Recommended for ' . $dispatchZone . ' based on ' . $zoneMatches . ' recent zone-matched job(s).'
                    : 'Best ready crew for ' . $dispatchZone . ' based on truck match and recent availability.';

                return $unit + [
                    'score' => $score,
                    'recommendation' => $recommendation,
                    'class_matched' => true,
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
            ? 'Available for dispatch · Familiar with ' . implode(', ', $zones)
            : 'Available for dispatch · No saved zone history yet';

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
            'Makati City' => ['makati', 'salcedo', 'legazpi village', 'ayala', 'paseo de roxas'],
            'Taguig City' => ['taguig', 'bgc', 'bonifacio global city', 'market market'],
            'Quezon City' => ['quezon city', 'qc', 'cubao', 'commonwealth', 'fairview', 'diliman', 'katipunan'],
            'Pasig City' => ['pasig', 'ortigas', 'kapitolyo'],
            'Pasay City' => ['pasay', 'moa', 'mall of asia', 'edsa taft'],
            'Manila' => ['manila', 'ermita', 'malate', 'sampaloc', 'quiapo', 'tondo', 'binondo', 'intramuros', 'santa mesa'],
            'Muntinlupa City' => ['muntinlupa', 'alabang'],
            'Parañaque City' => ['paranaque', 'parañaque', 'sucat', 'baclaran'],
            'Caloocan City' => ['caloocan'],
            'Malabon City' => ['malabon'],
            'Navotas City' => ['navotas'],
            'Valenzuela City' => ['valenzuela'],
            'Marikina City' => ['marikina'],
            'San Juan City' => ['san juan'],
            'Mandaluyong City' => ['mandaluyong'],
            'Las Piñas City' => ['las pinas', 'las piñas'],
            'Pateros' => ['pateros'],
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

        return $fallback !== '' ? ucwords($fallback) : 'General Dispatch Zone';
    }

    public function pendingBookingsCount()
    {
        return response()->json([
            'count' => Booking::whereIn('status', ['requested', 'reviewed', 'delayed', 'returned'])->count(),
            'scheduled_count' => Booking::where('status', 'scheduled')->count(),
        ]);
    }

    public function assignBooking(Request $request, Booking $booking)
    {
        $request->merge([
            'rejection_reason' => $request->input('rejection_reason', $request->input('reason')),
        ]);

        $booking->loadMissing(['customer', 'truckType', 'unit.teamLeader']);

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
                'nullable',
                'integer',
                'exists:units,id',
            ],
            'distance_km' => [
                Rule::requiredIf(fn() => $request->input('action') === 'accept' && !in_array($booking->status, $this->operationallyAssignedStatuses, true)),
                'nullable',
                'numeric',
                'min:0.01',
                'max:10000',
            ],
            'distance_fee' => [
                Rule::requiredIf(fn() => $request->input('action') === 'accept' && !in_array($booking->status, $this->operationallyAssignedStatuses, true)),
                'nullable',
                'numeric',
                'min:0',
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $booking) {
                    if ($request->input('action') !== 'accept' || in_array($booking->status, $this->operationallyAssignedStatuses, true) || $value === null || $value === '') {
                        return;
                    }

                    $distanceKm = (float) $request->input('distance_km', $booking->distance_km ?? 0);
                    $perKmRate = (float) ($booking->truckType?->per_km_rate ?? 0);
                    $expectedDistanceFee = $this->bookingService->distanceFeeFor($distanceKm, $perKmRate);

                    if (abs($expectedDistanceFee - (float) $value) > 0.11) {
                        $fail('Distance fee must match the truck type\'s per-km rate (₱' . number_format($perKmRate, 2) . '/km after the first 4 km).');
                    }
                },
            ],
        ]);

        return DB::transaction(function () use ($request, $booking, $validated) {
            
            
            
            
            
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->first();
            if (! $booking) {
                return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
            }
            $booking->loadMissing(['customer', 'truckType', 'unit.teamLeader']);

            $isReturnedTask = $booking->needs_reassignment;
            $isOperationalAssignment = $validated['action'] === 'accept'
                && ($isReturnedTask || in_array($booking->status, $this->operationallyAssignedStatuses, true));

            if ($validated['action'] === 'accept' && ! $isOperationalAssignment) {
                $validated['assigned_unit_id'] = null;
            }

            if ($validated['action'] === 'accept' && $isOperationalAssignment && blank($validated['assigned_unit_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please choose an available unit before dispatching.',
                    'errors' => [
                        'assigned_unit_id' => ['Please choose an available unit before dispatching.'],
                    ],
                ], 422);
            }

            if (! in_array($booking->status, $this->reviewableStatuses, true) && ! $isReturnedTask && ! $isOperationalAssignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'This booking can no longer be revised from the dispatcher queue.',
                ], 422);
            }

            if ($validated['action'] === 'accept') {
                $selectedUnit = null;

                if ($isOperationalAssignment && ! empty($validated['assigned_unit_id'])) {
                    $selectedUnit = Unit::with(['teamLeader', 'truckType'])
                        ->where('id', $validated['assigned_unit_id'])
                        ->lockForUpdate()
                        ->first();
                    if ($selectedUnit && (int) $selectedUnit->truck_type_id !== (int) $booking->truck_type_id) {
                        return response()->json([
                            'success' => false,
                            'message' => 'This unit is not compatible with the booking vehicle.',
                        ], 422);
                    }
                    if ($selectedUnit && Booking::where(function ($query) use ($selectedUnit) {
                        $query->where('assigned_unit_id', $selectedUnit->id)
                            ->orWhere('selected_unit_id', $selectedUnit->id);
                    })->where('id', '!=', $booking->id)->whereIn('status', Booking::REVIEWABLE_STATUSES)->exists()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'This unit is already reserved for another booking.',
                        ], 422);
                    }
                    $busyTeamLeaderIds = $this->teamLeaderAvailability->busyTeamLeaderIds()
                        ->reject(fn($id) => (int) $id === (int) $selectedUnit->team_leader_id
                            && (int) $booking->assigned_team_leader_id === (int) $selectedUnit->team_leader_id);

                    if (
                        ! $selectedUnit
                        || $selectedUnit->status !== 'available'
                        || empty($selectedUnit->team_leader_id)
                        || $busyTeamLeaderIds->contains((int) $selectedUnit->team_leader_id)
                    ) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Team Leader not available. Please choose another unit.',
                        ], 422);
                    }
                }

                
                
                
                
                
                
                
                if (
                    $booking->status === 'scheduled_confirmed'
                    && ! in_array($booking->scheduling_bucket, ['ready', 'overdue'], true)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This booking is not yet ready for dispatch — unit selection opens 1 hour before the scheduled service time.',
                    ], 422);
                }

                $quotationNumber = $booking->quotation_number ?: $this->bookingService->generateQuotationNumber($booking);
                $dispatcherNote = filled($validated['dispatcher_note'] ?? null)
                    ? trim(strip_tags((string) $validated['dispatcher_note']))
                    : null;

                if ($isReturnedTask) {
                    $previousTeamLeader = $booking->assignedTeamLeader;
                    $previousUnit = $booking->unit;

                    AuditLog::create([
                        'user_id'     => auth()->id(),
                        'action'      => 'booking_reassigned',
                        'category'    => 'dispatch',
                        'entity_type' => 'Booking',
                        'entity_id'   => $booking->id,
                        'reference'   => $booking->job_code,
                        'description' => 'Returned task reassigned to unit ' . ($selectedUnit?->name ?? 'N/A'),
                        'old_value'   => [
                            'team_leader_id'   => $booking->returned_by_team_leader_id ?? $booking->assigned_team_leader_id,
                            'team_leader_name' => $previousTeamLeader?->full_name ?? $previousTeamLeader?->name,
                            'unit_id'          => $booking->assigned_unit_id,
                            'unit_name'        => $previousUnit?->name,
                            'return_reason'    => $booking->return_reason,
                            'return_notes'     => $booking->return_notes,
                            'returned_at'      => $booking->returned_at?->toIso8601String(),
                        ],
                        'new_value'   => [
                            'team_leader_id'   => $selectedUnit?->teamLeader?->id,
                            'team_leader_name' => $selectedUnit?->teamLeader?->full_name ?? $selectedUnit?->teamLeader?->name,
                            'unit_id'          => $selectedUnit?->id,
                            'unit_name'        => $selectedUnit?->name,
                        ],
                    ]);

                    $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                        'status' => 'assigned',
                        'assigned_unit_id' => $selectedUnit?->id,
                        'assigned_team_leader_id' => $selectedUnit?->teamLeader?->id,
                        'assigned_at' => now(),
                        'driver_name' => null,
                        'dispatcher_note' => $dispatcherNote,
                        'returned_at' => null,
                        'return_reason' => null,
                        'return_notes' => null,
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

                if (in_array($booking->status, $this->operationallyAssignedStatuses, true)) {
                    $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                        'status' => 'assigned',
                        'assigned_unit_id' => $selectedUnit?->id,
                        'assigned_team_leader_id' => $selectedUnit?->teamLeader?->id,
                        'assigned_at' => now(),
                        'dispatcher_note' => $dispatcherNote,
                    ]));

                    $booking->refresh()->loadMissing(['customer', 'truckType', 'unit.teamLeader']);
                    BookingStatusUpdated::safeFire($booking);

                    AuditLog::create([
                        'user_id'     => auth()->id(),
                        'action'      => 'booking_assigned',
                        'category'    => 'dispatch',
                        'entity_type' => 'Booking',
                        'entity_id'   => $booking->id,
                        'reference'   => $booking->job_code,
                        'description' => 'Assigned to unit ' . ($selectedUnit?->name ?? 'N/A'),
                    ]);

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
            
                
                
                $distanceKm = max((float) ($validated['distance_km'] ?? ($booking->distance_km ?? 0)), 0);
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
                    'assigned_unit_id' => $isOperationalAssignment ? $selectedUnit?->id : null,
                    'assigned_team_leader_id' => $isOperationalAssignment ? $selectedUnit?->teamLeader?->id : null,
                    'selected_unit_id' => $isOperationalAssignment ? $selectedUnit?->id : null,
                    'base_rate' => $unitBaseRate,
                    'per_km_rate' => $totals['per_km_rate'],
                    'distance_km' => $totals['distance_km'],
                    'computed_total' => $totals['computed_total'],
                    'additional_fee' => $totals['additional_fee'],
                    'final_total' => $totals['final_total'],
                    'vat_amount' => $totals['vat_amount'],
                    'vat_exclusive_total' => $totals['vat_exclusive_total'],
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
                    'source_booking_id' => $booking->id,
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
                    'scheduled_date' => $booking->scheduled_date?->toDateString(),
                    'scheduled_time' => $booking->scheduled_time,
                    'pickup_notes' => $booking->notes,
                    'extra_vehicles' => $booking->extra_vehicles,
                ]);

                $this->quotationService->sendQuotation($quotation);

                $initialQuotePath = $this->documentGenerationService->generateQuotation($booking, false, $quotation);
                $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                    'initial_quote_path' => $initialQuotePath,
                ]));

                $booking->refresh()->loadMissing(['customer', 'truckType']);

                AuditLog::create([
                    'user_id'     => auth()->id(),
                    'action'      => 'quotation_sent',
                    'entity_type' => 'Booking',
                    'entity_id'   => $booking->id,
                    'reference'   => $booking->job_code,
                    'description' => 'Quotation ' . $quotationNumber . ' sent — ₱' . number_format((float) $booking->final_total, 2),
                ]);

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

            $isScheduledCancellation = $booking->status === 'scheduled_confirmed';

            $groupAnchor = null;
            $groupQuotation = null;
            if (! $isScheduledCancellation && $booking->status === 'quotation_sent' && $booking->group_code) {
                $groupAnchor = Booking::where('group_code', $booking->group_code)
                    ->where('pickup_address', $booking->pickup_address)
                    ->where('dropoff_address', $booking->dropoff_address)
                    ->orderBy('id')
                    ->first();
                $groupQuotation = $groupAnchor
                    ? Quotation::where('source_booking_id', $groupAnchor->id)->current()->where('status', 'sent')->first()
                    : null;
            }
            $isGroupedSentCancellation = $groupQuotation
                && collect($groupQuotation->extra_vehicles ?? [])->contains(fn($ev) => ($ev['booking_id'] ?? null) === $booking->id);

            $isAcceptedGroupCancellation = false;
            if (! $isScheduledCancellation && ! $isGroupedSentCancellation && $booking->group_code) {
                $acceptedGroupAnchor = Booking::where('group_code', $booking->group_code)
                    ->where('pickup_address', $booking->pickup_address)
                    ->where('dropoff_address', $booking->dropoff_address)
                    ->orderBy('id')
                    ->first();
                $acceptedGroupQuotation = $acceptedGroupAnchor
                    ? Quotation::where('source_booking_id', $acceptedGroupAnchor->id)->current()->where('status', 'accepted')->first()
                    : null;
                $isAcceptedGroupCancellation = $acceptedGroupQuotation !== null;
            }

            if ($isReturnedTask && $rejectionReason === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Rejection reason is required.',
                ], 422);
            }

            if (($isScheduledCancellation || $isGroupedSentCancellation || $isAcceptedGroupCancellation) && $rejectionReason === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'A reason is required to cancel this booking.',
                ], 422);
            }

            if ($rejectionReason === '') {
                $rejectionReason = 'Your request could not be accommodated at this time. Please contact dispatch for assistance.';
            }

            $updatePayload = [
                'status' => 'cancelled',
                'quotation_status' => 'cancelled',
                'rejection_reason' => $rejectionReason,
            ];

            $returnedTaskAuditSnapshot = null;
            if ($isReturnedTask) {
                $returnedTaskAuditSnapshot = [
                    'team_leader_id'   => $booking->returned_by_team_leader_id ?? $booking->assigned_team_leader_id,
                    'team_leader_name' => $booking->returnedByTeamLeader?->full_name ?? $booking->returnedByTeamLeader?->name,
                    'unit_id'          => $booking->assigned_unit_id,
                    'unit_name'        => $booking->unit?->name,
                    'return_reason'    => $booking->return_reason,
                    'return_notes'     => $booking->return_notes,
                    'returned_at'      => $booking->returned_at?->toIso8601String(),
                ];

                $updatePayload = array_merge($updatePayload, [
                    'returned_at' => null,
                    'return_reason' => null,
                    'return_notes' => null,
                    'returned_by_team_leader_id' => null,
                    'assigned_team_leader_id' => null,
                    'assigned_unit_id' => null,
                    'driver_name' => null,
                ]);
            }

            $booking->update($this->bookingService->filterPayloadForTable('bookings', $updatePayload));

            $booking->refresh()->loadMissing(['customer', 'truckType']);

            AuditLog::create([
                'user_id'     => auth()->id(),
                'action'      => $isScheduledCancellation ? 'scheduled_booking_cancelled_by_dispatcher' : 'booking_rejected',
                'entity_type' => 'Booking',
                'entity_id'   => $booking->id,
                'reference'   => $booking->job_code,
                'description' => $rejectionReason,
                'old_value'   => $returnedTaskAuditSnapshot,
            ]);

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

            if ($isScheduledCancellation) {
                return response()->json([
                    'success' => true,
                    'message' => 'Scheduled booking cancelled. The accepted quotation is preserved for your records.',
                ]);
            }

            if ($isGroupedSentCancellation) {
                $remainingLineItems = collect($groupQuotation->extra_vehicles)
                    ->reject(fn($ev) => ($ev['booking_id'] ?? null) === $booking->id)
                    ->values();

                $groupQuotation->newVersion([
                    'extra_vehicles' => $remainingLineItems->all(),
                    'estimated_price' => max(round($remainingLineItems->sum('final_total') - (float) $groupQuotation->discount, 2), 0),
                    'status' => 'draft',
                    'price_change_log' => array_merge($groupQuotation->price_change_log ?? [], [[
                        'at' => now()->toISOString(),
                        'reason' => 'Vehicle removed (' . $booking->job_code . '): ' . $rejectionReason,
                        'by' => auth()->user()?->name ?? 'Dispatcher',
                    ]]),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Vehicle removed. The group quotation was repriced as a new draft — review and send it to the customer when ready.',
                ]);
            }

            if ($isAcceptedGroupCancellation) {
                return response()->json([
                    'success' => true,
                    'message' => 'Vehicle cancelled. The accepted quotation is preserved for your records.',
                ]);
            }

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
        });
    }

    
    public function rescheduleBooking(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'new_scheduled_date' => 'required|date|after_or_equal:today',
            'new_scheduled_time' => 'required|date_format:H:i',
            'reason' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($validated, $booking) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->first();
            if (! $booking) {
                return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
            }

            if ($booking->status !== 'scheduled_confirmed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only a confirmed Scheduled booking can be rescheduled.',
                ], 422);
            }

            $newScheduledFor = \Carbon\Carbon::parse($validated['new_scheduled_date'] . ' ' . $validated['new_scheduled_time']);

            if ($newScheduledFor->lte(now())) {
                return response()->json([
                    'success' => false,
                    'message' => 'The new schedule must be in the future.',
                ], 422);
            }

            $oldScheduledFor = $booking->scheduled_for;

            $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                'scheduled_date' => $validated['new_scheduled_date'],
                'scheduled_time' => $validated['new_scheduled_time'],
                'scheduled_for' => $newScheduledFor,
                'selected_unit_id' => null,
            ]));

            AuditLog::create([
                'user_id'     => auth()->id(),
                'action'      => 'booking_rescheduled',
                'entity_type' => 'Booking',
                'entity_id'   => $booking->id,
                'reference'   => $booking->job_code,
                'description' => 'Rescheduled from ' . ($oldScheduledFor?->format('M d, Y g:i A') ?? 'N/A')
                    . ' to ' . $newScheduledFor->format('M d, Y g:i A')
                    . (filled($validated['reason'] ?? null) ? ' — ' . $validated['reason'] : ''),
            ]);

            $booking->refresh();
            BookingStatusUpdated::safeFire($booking);

            return response()->json([
                'success' => true,
                'message' => 'Booking rescheduled successfully.',
                'scheduling_bucket' => $booking->scheduling_bucket,
            ]);
        });
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

        AuditLog::create([
            'user_id'     => auth()->id(),
            'action'      => 'service_fee_applied',
            'entity_type' => 'Booking',
            'entity_id'   => $booking->id,
            'reference'   => $booking->job_code,
            'description' => '₱' . number_format($validated['service_fee_amount'], 2) . ' — ' . $validated['service_fee_reason'],
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
        $previousRiskLevel = $customer->risk_level;

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

        $oldRiskLabel = $this->customerRiskLabel($previousRiskLevel);
        $newRiskLabel = $this->customerRiskLabel($riskLevel);

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'customer_risk_updated',
            'entity_type' => 'Customer',
            'entity_id' => $customer->id,
            'reference' => $customer->full_name,
            'description' => "Customer risk changed from {$oldRiskLabel} to {$newRiskLabel}. Reason: {$validated['risk_reason']}",
            'old_value' => ['risk' => $oldRiskLabel],
            'new_value' => ['risk' => $newRiskLabel, 'reason' => $validated['risk_reason']],
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

        
        $groupSiblings = [];
        $sourceBooking = $quotation->sourceBooking;
        if ($sourceBooking && $sourceBooking->group_code) {
            $groupSiblings = \App\Models\Booking::where('group_code', $sourceBooking->group_code)
                ->where('id', '!=', $sourceBooking->id)
                ->where('status', '!=', 'cancelled')
                ->where('pickup_address', $sourceBooking->pickup_address)
                ->where('dropoff_address', $sourceBooking->dropoff_address)
                ->with('truckType')
                ->get(['id', 'booking_code', 'status', 'service_type', 'truck_type_id', 'base_rate', 'per_km_rate', 'scheduled_date', 'scheduled_time', 'group_code', 'final_total'])
                ->map(fn($b) => [
                    'booking_code'    => $b->booking_code,
                    'status'          => $b->status,
                    'service_type'    => $b->service_type,
                    'truck_type'      => $b->truckType?->name ?? 'Unknown',
                    'truck_type_name' => $b->truckType?->name ?? 'Unknown',
                    'truck_class'     => $b->truckType?->class ?? null,
                    'base_rate'       => (float) $b->base_rate,
                    'per_km_rate'     => (float) $b->per_km_rate,
                    'scheduled_date'  => $b->scheduled_date,
                    'scheduled_time'  => $b->scheduled_time,
                    'final_total'     => (float) $b->final_total,
                ])
                ->values()
                ->toArray();
        }

        $distanceKm    = (float) ($quotation->distance_km ?? 0);
        $finalTotal    = (float) ($quotation->estimated_price ?? 0);
        $additionalFee = (float) ($quotation->additional_fee ?? 0);
        $discount      = (float) ($quotation->discount ?? 0);
        $basePrice     = (float) ($sourceBooking?->base_rate ?? $quotation->truckType?->base_rate ?? 0);
        $perKmRate     = (float) ($sourceBooking?->per_km_rate ?? $quotation->truckType?->per_km_rate ?? 0);
        $distanceFee   = $this->bookingService->distanceFeeFor($distanceKm, $perKmRate);

        $groupVehicles = [];
        $groupExtraVehicles = collect($quotation->extra_vehicles ?? [])->filter(fn($ev) => isset($ev['booking_id']));
        if ($groupExtraVehicles->isNotEmpty()) {
            $groupBookingsById = Booking::whereIn('id', $groupExtraVehicles->pluck('booking_id')->filter()->all())
                ->with('vehicleType')
                ->get(['id', 'booking_code', 'vehicle_type_id', 'assigned_unit_id', 'selected_unit_id', 'status'])
                ->keyBy('id');

            $groupVehicles = $groupExtraVehicles
                ->map(function ($ev) use ($groupBookingsById) {
                    $b = $groupBookingsById->get($ev['booking_id'] ?? null);
                    $baseRate = (float) ($ev['base_rate'] ?? 0);
                    $distanceFee = (float) ($ev['distance_fee'] ?? 0);
                    $vatAmount = (float) ($ev['vat_amount'] ?? 0);
                    $vatExclusiveTotal = round($baseRate + $distanceFee, 2);
                    $vatRate = $this->bookingService->resolveVatRate(
                        isset($ev['vat_rate']) && $ev['vat_rate'] !== null ? (float) $ev['vat_rate'] : null,
                        $vatAmount,
                        $vatExclusiveTotal,
                    );

                    return [
                        'booking_id' => $ev['booking_id'],
                        'booking_code' => $b?->booking_code,
                        'vehicle_type_id' => $ev['vehicle_type_id'] ?? $b?->vehicle_type_id,
                        'vehicle_name' => $b?->vehicleType?->name,
                        'truck_type_id' => $ev['truck_type_id'] ?? null,
                        'truck_type_name' => $ev['truck_type_name'] ?? null,
                        'base_rate' => $baseRate,
                        'distance_fee' => $distanceFee,
                        'vat_exclusive_total' => $vatExclusiveTotal,
                        'vat_amount' => $vatAmount,
                        'vat_rate' => $vatRate,
                        'final_total' => (float) ($ev['final_total'] ?? $ev['estimated_price'] ?? 0),
                        'assigned_unit_id' => $b?->assigned_unit_id,
                        'selected_unit_id' => $b?->selected_unit_id,
                        'status' => $b?->status,
                    ];
                })
                ->values()
                ->all();
        }
        $isGroupedQuotation = ! empty($groupVehicles);

        $isEditableDraft = $this->isEditableDraftStatus($quotation->status);
        $vatRate = $isEditableDraft
            ? $this->bookingService->vatRate()
            : $this->bookingService->resolveVatRate(
                $quotation->vat_rate !== null ? (float) $quotation->vat_rate : null,
                $sourceBooking?->vat_amount !== null ? (float) $sourceBooking->vat_amount : null,
                $sourceBooking?->vat_exclusive_total !== null ? (float) $sourceBooking->vat_exclusive_total : null,
            );
        $vatBreakdown = $sourceBooking
            ? $this->bookingService->applyVatAndAdjustment(
                $this->bookingService->taxableSubtotalFor($sourceBooking, $isEditableDraft),
                $additionalFee,
                $vatRate,
            )
            : $this->bookingService->applyVatAndAdjustment(round($finalTotal / (1 + $vatRate), 2), 0, $vatRate);

        if ($isEditableDraft && $sourceBooking && ! $isGroupedQuotation) {
            $finalTotal = $vatBreakdown['final_total'];
        }

        $customerName = $quotation->customer->full_name
            ?? $quotation->customer->name
            ?? 'N/A';

        $rescheduleEvents = $quotation->source_booking_id
            ? AuditLog::where('entity_type', 'Booking')
                ->where('entity_id', $quotation->source_booking_id)
                ->where('action', 'booking_rescheduled')
                ->orderBy('created_at')
                ->get()
                ->map(fn($log) => [
                    'at'               => $log->created_at->toIso8601String(),
                    'old_scheduled_for' => $this->resolveAuditScheduleLabel($log->old_value ?? []),
                    'new_scheduled_for' => $this->resolveAuditScheduleLabel($log->new_value ?? []),
                    'description'      => $log->description,
                ])
                ->values()
            : collect();

        return response()->json([
            'success'   => true,
            'quotation' => [
                'id'                    => $quotation->id,
                'version'               => $quotation->version,
                'sent_at'               => $quotation->sent_at?->toIso8601String(),
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
                'extra_distance'        => round($distanceKm, 2),
                'distance_fee'          => $distanceFee,
                'price_change_log'      => $quotation->price_change_log ?? [],
                'additional_fee'        => $additionalFee,
                'discount'              => $discount,
                'estimated_price'       => $finalTotal,
                'subtotal'              => $vatBreakdown['subtotal'],
                'vat_amount'            => $vatBreakdown['vat_amount'],
                'vat_rate'              => $vatRate,
                'base_total'            => $vatBreakdown['base_total'],
                'counter_offer_amount'  => $quotation->counter_offer_amount,
                'response_note'         => $quotation->response_note,
                'status'                => $quotation->status,
                'service_type'          => $quotation->service_type,
                'link_version'          => $quotation->link_version ?? 1,
                'vehicle_image_paths'   => collect($quotation->vehicle_image_paths ?: ($quotation->sourceBooking?->vehicle_image_paths ?? []))->map(fn($p) => protected_file_url($p))->values()->all(),
                'extra_vehicles'        => ! empty($groupVehicles)
                    ? $groupVehicles
                    : $this->enrichExtraVehicles($quotation->extra_vehicles ?? []),
                'group_vehicles'        => $groupVehicles,
                'total_vehicles'        => ! empty($groupVehicles)
                    ? count($groupVehicles)
                    : 1 + count($quotation->extra_vehicles ?? []),
                'created_at'            => $quotation->created_at->toIso8601String(),
                'vehicle_make'          => $quotation->vehicle_make,
                'vehicle_model'         => $quotation->vehicle_model,
                'vehicle_year'          => $quotation->vehicle_year,
                'vehicle_color'         => $quotation->vehicle_color,
                'vehicle_plate_number'  => $quotation->vehicle_plate_number,
                'notes'                 => $quotation->pickup_notes,
                'source_booking_id'     => $quotation->source_booking_id,
                'source_booking_code'   => $quotation->sourceBooking?->booking_code,
                'booking_final_total'   => (float) ($quotation->sourceBooking?->final_total ?? 0),
                'is_mobile_booking'     => $quotation->source_booking_id !== null,
                'group_code'            => $sourceBooking?->group_code,
                'group_siblings'        => $groupSiblings,
                'reschedule_events'     => $rescheduleEvents,
                'price_adjustments'     => PriceAdjustment::forQuotation($quotation->quotation_number)
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn(PriceAdjustment $a) => [
                        'id'          => $a->id,
                        'type'        => $a->type,
                        'amount'      => (float) $a->amount,
                        'reason'      => $a->reason,
                        'status'      => $a->status,
                        'created_at'  => $a->created_at->toIso8601String(),
                        'reverted_at' => $a->reverted_at?->toIso8601String(),
                    ])
                    ->values(),
            ],
        ]);
    }

    private function resolveAuditScheduleLabel(array $value): ?string
    {
        $date = $value['scheduled_date'] ?? null;
        $time = $value['scheduled_time'] ?? null;

        if (! $date) {
            return null;
        }

        $dateStr = strlen((string) $date) <= 10
            ? $date
            : \Carbon\Carbon::parse($date)->setTimezone(config('app.timezone'))->toDateString();

        return \Carbon\Carbon::parse($dateStr . ' ' . ($time ?: '00:00'))->format('M d, Y g:i A');
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
        if ($quotation->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => $quotation->status === 'pending'
                    ? 'Quotation must be recorded (drafted) before sending. Click "Record Quotation" first.'
                    : 'This quotation has already been sent or cannot be resent.',
            ], 422);
        }

        $validated = $request->validate([
            'expiry_hours' => 'nullable|integer|min:1|max:720',
        ]);

        $anchor = $quotation->sourceBooking;
        $groupSiblings = $anchor ? $this->bookingService->groupSiblingBookings($anchor) : collect();
        $isGrouped = $groupSiblings->count() > 1;

        if ($isGrouped) {
            $pricedBookingIds = collect($quotation->extra_vehicles ?? [])->pluck('booking_id')->all();
            $groupComplete = $groupSiblings->every(fn($sibling) => in_array($sibling->id, $pricedBookingIds, true));

            if (! $groupComplete) {
                return response()->json([
                    'success' => false,
                    'message' => 'This quotation cannot be sent yet — every vehicle in the group must be priced first.',
                ], 422);
            }
        }

        $sendPrice = (float) $quotation->estimated_price;

        $booking = Booking::where('quotation_id', $quotation->id)->first();
        if ($booking) {
            $booking->update($this->bookingService->filterPayloadForTable('bookings', [
                'final_total' => $sendPrice,
            ]));
        }

        $expiryHours = ($quotation->service_type === 'book_now') ? 1 : ($validated['expiry_hours'] ?? 168);

        try {
            $this->quotationService->sendQuotation($quotation, $expiryHours);
        } catch (ScheduledQuoteCutoffPassedException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($isGrouped) {
            foreach ($groupSiblings as $sibling) {
                $sibling->update($this->bookingService->filterPayloadForTable('bookings', [
                    'status' => 'quotation_sent',
                    'quotation_number' => $quotation->quotation_number,
                    'quotation_sent_at' => now(),
                    'quotation_expires_at' => $quotation->fresh()->expires_at,
                ]));
                BookingStatusUpdated::safeFire($sibling->fresh());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Quotation sent to customer successfully.',
            'quotation_number' => $quotation->quotation_number,
            'quotation_id' => $quotation->id,
        ]);
    }

    public function cancelQuotation(Request $request, Quotation $quotation)
    {
        if (!in_array($quotation->status, ['draft', 'pending', 'sent'])) {
            return response()->json([
                'success' => false,
                'message' => 'This quotation cannot be cancelled.',
            ], 422);
        }

        $this->quotationService->cancelQuotation($quotation);

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

    
    public function keepQuotationPrice(Request $request, Quotation $quotation)
    {
        if (! $quotation->is_current || $quotation->status !== 'price_review_requested') {
            return response()->json([
                'success' => false,
                'message' => 'This quotation is not awaiting a price review.',
            ], 422);
        }

        $validated = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $this->quotationService->keepCurrentPrice($quotation, $validated['note'] ?? null);
        } catch (ScheduledQuoteCutoffPassedException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        AuditLog::create([
            'user_id'     => auth()->id(),
            'action'      => 'quotation_price_review_kept',
            'entity_type' => 'Quotation',
            'entity_id'   => $quotation->id,
            'reference'   => $quotation->quotation_number,
            'description' => 'Price review completed — amount unchanged.',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Price kept unchanged; customer notified and a new response window has started.',
            'quotation_id' => $quotation->id,
        ]);
    }

    private function customerRiskLabel(?string $riskLevel): string
    {
        return match (strtolower((string) $riskLevel)) {
            '', 'null' => 'Normal',
            'blacklisted' => 'Blacklisted',
            default => 'Watchlist',
        };
    }

    private function enforcePriceAdjustmentLimits(float $canonicalBaseTotal, float $derivedAdjustment, ?string $note): ?string
    {
        if ($derivedAdjustment < 0) {
            if (SystemSetting::getValue('dispatcher_discount_enabled', '1') === '0') {
                return 'Discounted pricing is currently disabled by the Owner.';
            }

            $maxDiscount = SystemSetting::getValue('max_dispatcher_discount_percentage');
            if (filled($maxDiscount) && $canonicalBaseTotal > 0) {
                $discountPercentage = (abs($derivedAdjustment) / $canonicalBaseTotal) * 100;
                if ($discountPercentage > (float) $maxDiscount + 0.01) {
                    return 'This discount exceeds the maximum allowed dispatcher discount of ' . number_format((float) $maxDiscount, 2) . '%.';
                }
            }

            if (SystemSetting::getValue('dispatcher_discount_require_reason', '0') === '1' && blank($note)) {
                return 'A reason is required when reducing the price.';
            }
        }

        if ($derivedAdjustment > 0) {
            $maxCharge = SystemSetting::getValue('max_additional_charge');
            if (filled($maxCharge) && $derivedAdjustment > (float) $maxCharge + 0.01) {
                return 'This additional charge exceeds the maximum allowed amount of ₱' . number_format((float) $maxCharge, 2) . '.';
            }

            if (SystemSetting::getValue('additional_charge_require_reason', '0') === '1' && blank($note)) {
                return 'A reason is required when applying an additional charge.';
            }
        }

        return null;
    }

    private function isEditableDraftStatus(string $status): bool
    {
        return in_array($status, ['draft', 'pending'], true);
    }

    private function canonicalBaseTotalFor(?Booking $sourceBooking, bool $isEditableDraft = false): ?float
    {
        return $this->quotationService->canonicalBaseTotalFor($sourceBooking, $isEditableDraft);
    }

    public function adjustQuotationPriceAfterReview(Request $request, Quotation $quotation)
    {
        if (! $quotation->is_current || $quotation->status !== 'price_review_requested') {
            return response()->json([
                'success' => false,
                'message' => 'This quotation is not awaiting a price review.',
            ], 422);
        }

        $validated = $request->validate([
            'new_price' => 'required|numeric|min:0.01',
            'additional_fee' => 'nullable|numeric',
            'note' => 'nullable|string|max:1000',
        ]);

        $newPrice = (float) $validated['new_price'];
        $sourceBooking = $quotation->source_booking_id ? Booking::find($quotation->source_booking_id) : null;
        $canonicalBaseTotal = $this->canonicalBaseTotalFor($sourceBooking);
        $derivedAdjustment = $canonicalBaseTotal !== null
            ? round($newPrice - $canonicalBaseTotal, 2)
            : (float) ($validated['additional_fee'] ?? 0);

        $limitError = $this->enforcePriceAdjustmentLimits(
            $canonicalBaseTotal ?? (float) $quotation->estimated_price,
            $derivedAdjustment,
            $validated['note'] ?? null,
        );

        if ($limitError !== null) {
            return response()->json(['success' => false, 'message' => $limitError], 422);
        }

        try {
            $next = $this->quotationService->resolvePriceReviewWithNewPrice(
                $quotation,
                $newPrice,
                $validated['note'] ?? null,
                $derivedAdjustment,
            );
        } catch (ScheduledQuoteCutoffPassedException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($next->source_booking_id) {
            $sourceBooking = Booking::find($next->source_booking_id);
            if ($sourceBooking) {
                $totals = $this->bookingService->applyVatAndAdjustment(
                    $this->bookingService->resolveTaxableSubtotal($sourceBooking),
                    $derivedAdjustment,
                );
                $sourceBooking->update($this->bookingService->filterPayloadForTable('bookings', [
                    'final_total'         => $totals['final_total'],
                    'vat_amount'          => $totals['vat_amount'],
                    'vat_exclusive_total' => $totals['subtotal'],
                    'vat_rate'            => $totals['vat_rate'],
                    'quotation_id'        => $next->id,
                ]));
            }
        }

        AuditLog::create([
            'user_id'     => auth()->id(),
            'action'      => 'quotation_price_review_adjusted',
            'entity_type' => 'Quotation',
            'entity_id'   => $next->id,
            'reference'   => $next->quotation_number,
            'description' => 'Price adjusted to ₱' . number_format((float) $next->estimated_price, 2) . ' after review'
                . (filled($validated['note'] ?? null) ? ' — ' . $validated['note'] : ''),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'New price sent to customer.',
            'quotation_id' => $next->id,
            'new_price' => number_format((float) $next->estimated_price, 2),
        ]);
    }

    public function updateQuotationPrice(Request $request, Quotation $quotation)
    {
        if (! $quotation->is_current) {
            return response()->json([
                'success' => false,
                'message' => 'This quotation was already revised by someone else. Please refresh and try again.',
            ], 409);
        }

        if (in_array($quotation->status, $this->lockedQuotationStatuses(), true)) {
            return response()->json([
                'success' => false,
                'message' => 'This quotation is locked and can no longer be adjusted.',
            ], 422);
        }

        $validated = $request->validate([
            'new_price' => 'required|numeric|min:0.01',
            'additional_fee' => 'nullable|numeric',
            'discount' => 'nullable|numeric|min:0',
            'note' => [
                Rule::requiredIf(fn() => (float) $request->input('additional_fee', 0) !== 0.0),
                'nullable',
                'string',
                'max:1000',
            ],
            'assigned_unit_id' => 'nullable|integer|exists:units,id',
        ]);

        $previousStatus = $quotation->status;
        $newStatus = in_array($previousStatus, ['sent', 'negotiating']) ? 'sent'
            : ($previousStatus === 'draft' ? 'draft' : 'pending');

        $oldPrice = (float) $quotation->estimated_price;
        $newPrice = (float) $validated['new_price'];
        $previousQuotationId = $quotation->id;

        $sourceBooking = $quotation->source_booking_id
            ? Booking::find($quotation->source_booking_id)
            : Booking::where('quotation_id', $previousQuotationId)->first();

        $isGroupedQuotation = collect($quotation->extra_vehicles ?? [])->contains(fn($ev) => isset($ev['booking_id']));

        $isEditableDraft = $this->isEditableDraftStatus($previousStatus);

        $recomputedGroupVehicles = collect();
        if ($isGroupedQuotation) {
            $groupBookingIds = collect($quotation->extra_vehicles)->pluck('booking_id')->filter()->all();
            $recomputedGroupVehicles = Booking::whereIn('id', $groupBookingIds)
                ->with('truckType')
                ->get()
                ->map(function (Booking $sibling) {
                    $siblingTotals = $this->bookingService->calculateQuotationTotals(
                        $sibling,
                        null,
                        null,
                        (float) $sibling->distance_km,
                        0,
                        (float) $sibling->base_rate,
                    );

                    return [
                        'booking_id' => $sibling->id,
                        'truck_type_id' => $sibling->truck_type_id,
                        'truck_type_name' => $sibling->truckType?->name,
                        'vehicle_type_id' => $sibling->vehicle_type_id,
                        'service_type' => $sibling->service_type,
                        'distance_km' => (float) $sibling->distance_km,
                        'base_rate' => $siblingTotals['base_rate'],
                        'distance_fee' => $siblingTotals['distance_fee'],
                        'vat_exclusive_total' => $siblingTotals['vat_exclusive_total'],
                        'vat_amount' => $siblingTotals['vat_amount'],
                        'vat_rate' => $siblingTotals['vat_rate'],
                        'final_total' => $siblingTotals['final_total'],
                        'estimated_price' => $siblingTotals['final_total'],
                    ];
                })
                ->values();
        }

        $canonicalGroupServiceTotal = $isGroupedQuotation
            ? round($recomputedGroupVehicles->sum('final_total') - (float) ($validated['discount'] ?? $quotation->discount ?? 0), 2)
            : null;

        $canonicalBaseTotal = $isGroupedQuotation ? $canonicalGroupServiceTotal : $this->canonicalBaseTotalFor($sourceBooking, $isEditableDraft);
        $derivedAdjustment = $canonicalBaseTotal !== null
            ? round($newPrice - $canonicalBaseTotal, 2)
            : (float) ($validated['additional_fee'] ?? 0);

        $limitError = $this->enforcePriceAdjustmentLimits(
            $canonicalBaseTotal ?? $oldPrice,
            $derivedAdjustment,
            $validated['note'] ?? null,
        );

        if ($limitError !== null) {
            return response()->json(['success' => false, 'message' => $limitError], 422);
        }

        $oldAdditionalFee = (float) $quotation->additional_fee;

        $changeLog = $quotation->price_change_log ?? [];
        if ($oldPrice !== $newPrice) {
            $changeLog[] = [
                'at'     => now()->toISOString(),
                'old'    => $oldPrice,
                'new'    => $newPrice,
                'reason' => $validated['note'] ?? null,
                'by'     => auth()->user()?->name ?? 'Dispatcher',
            ];
        }

        $updateData = [
            'estimated_price'      => $newPrice,
            'additional_fee'       => $derivedAdjustment,
            'discount'             => $validated['discount'] ?? (float) $quotation->discount,
            'vat_rate'             => $this->bookingService->vatRate(),
            'counter_offer_amount' => null,
            'response_note'        => null,
            'status'               => $newStatus,
            'price_change_log'     => $changeLog,
        ];

        if ($isGroupedQuotation) {
            $updateData['extra_vehicles'] = $recomputedGroupVehicles->all();
        }

        try {
            $quotation = $quotation->newVersion($updateData);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'This quotation was already revised by someone else. Please refresh and try again.',
            ], 409);
        }

        $this->quotationService->recordAdjustmentDelta(
            $quotation->quotation_number,
            $oldAdditionalFee,
            $derivedAdjustment,
            $validated['note'] ?? null,
            auth()->id(),
        );

        if ($sourceBooking && ! $isGroupedQuotation) {
            $sourceBookingTotals = $this->bookingService->applyVatAndAdjustment(
                $this->bookingService->taxableSubtotalFor($sourceBooking, $isEditableDraft),
                $derivedAdjustment,
            );
            $bookingUpdate = [
                'final_total'         => $sourceBookingTotals['final_total'],
                'vat_amount'          => $sourceBookingTotals['vat_amount'],
                'vat_exclusive_total' => $sourceBookingTotals['subtotal'],
                'vat_rate'            => $sourceBookingTotals['vat_rate'],
                'quotation_id'        => $quotation->id,
            ];
            if ($isEditableDraft) {
                $bookingUpdate['computed_total'] = round(
                    (float) $sourceBooking->base_rate
                    + $this->bookingService->distanceFeeFor((float) $sourceBooking->distance_km, (float) $sourceBooking->per_km_rate),
                    2,
                );
            }
            if (!empty($validated['assigned_unit_id'])) {
                $selectedUnit = Unit::with(['teamLeader', 'truckType'])->find($validated['assigned_unit_id']);
                if ($selectedUnit) {
                    $bookingUpdate = array_merge($bookingUpdate, [
                        'assigned_unit_id'        => $selectedUnit->id,
                        'assigned_team_leader_id' => $selectedUnit->teamLeader?->id,
                        'base_rate'               => (float) ($selectedUnit->truckType?->base_rate ?? 0),
                        'per_km_rate'             => (float) ($selectedUnit->truckType?->per_km_rate ?? 0),
                    ]);
                }
            }
            $sourceBooking->update($this->bookingService->filterPayloadForTable('bookings', $bookingUpdate));
        }

        $quotation->increment('link_version');

        AuditLog::create([
            'user_id'     => auth()->id(),
            'action'      => 'quotation_price_updated',
            'entity_type' => $sourceBooking ? 'Booking' : 'Quotation',
            'entity_id'   => $sourceBooking?->id ?? $quotation->id,
            'reference'   => $sourceBooking?->job_code ?? $quotation->quotation_number,
            'description' => "Price changed from ₱" . number_format($oldPrice, 2) . " to ₱" . number_format($newPrice, 2)
                . (filled($validated['note'] ?? null) ? ' — ' . $validated['note'] : ''),
        ]);

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

        if ($quotation->customer && $quotation->customer->user_id && $oldPrice !== $newPrice) {
            $bookingCode = $quotation->sourceBooking?->booking_code ?? $sourceBooking?->booking_code;
            \App\Services\CustomerNotificationService::send(
                userId: $quotation->customer->user_id,
                type: 'quotation_updated',
                title: 'Your quotation price was updated',
                body: 'The price for your booking has been revised. Tap to view the updated quotation.',
                bookingCode: $bookingCode,
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Quotation price updated and email sent to customer successfully.',
            'quotation_id' => $quotation->id,
            'new_price' => number_format($newPrice, 2),
        ]);
    }

    private function lockedQuotationStatuses(): array
    {
        return ['accepted', 'rejected', 'expired', 'disregarded', 'cancelled'];
    }

    public function undoPriceAdjustment(Request $request, Quotation $quotation, PriceAdjustment $adjustment)
    {
        if (! $quotation->is_current || $adjustment->quotation_number !== $quotation->quotation_number) {
            return response()->json([
                'success' => false,
                'message' => 'This adjustment no longer belongs to the current quotation. Please refresh and try again.',
            ], 422);
        }

        if (in_array($quotation->status, $this->lockedQuotationStatuses(), true)) {
            return response()->json([
                'success' => false,
                'message' => 'This quotation is locked and can no longer be adjusted.',
            ], 422);
        }

        if ($adjustment->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'This adjustment has already been reverted.',
            ], 422);
        }

        try {
            $next = $this->quotationService->undoPriceAdjustment($quotation, $adjustment, auth()->id());
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        AuditLog::create([
            'user_id'     => auth()->id(),
            'action'      => 'quotation_adjustment_reverted',
            'entity_type' => 'Quotation',
            'entity_id'   => $next->id,
            'reference'   => $next->quotation_number,
            'description' => 'Reverted a ' . strtoupper($adjustment->type) . ' adjustment of ₱' . number_format((float) $adjustment->amount, 2)
                . (filled($adjustment->reason) ? ' — ' . $adjustment->reason : ''),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Adjustment undone. Quotation total recalculated.',
            'new_price' => number_format((float) $next->estimated_price, 2),
            'quotation_id' => $next->id,
        ]);
    }

    public function saveQuotationDraft(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'price'            => 'required|numeric|min:0',
            'additional_fee'   => 'nullable|numeric',
            'adjustments'                 => 'nullable|array',
            'adjustments.*.type'          => 'required_with:adjustments|in:add,deduct',
            'adjustments.*.amount'        => 'required_with:adjustments|numeric|min:0.01',
            'adjustments.*.reason'        => 'required_with:adjustments|string|max:1000',
            'selected_unit_id' => 'nullable|integer|exists:units,id',
            'dispatcher_note'  => [
                Rule::requiredIf(fn() => (float) $request->input('additional_fee', 0) !== 0.0 && empty($request->input('adjustments'))),
                'nullable',
                'string',
                'max:1000',
            ],
            'distance_km'      => 'nullable|numeric|min:0.01|max:10000',
        ]);

        
        
        
        
        
        
        
        if ($booking->is_scheduled) {
            $validated['selected_unit_id'] = null;
        }

        $groupSiblings = $this->bookingService->groupSiblingBookings($booking);
        $anchor = $groupSiblings->first();
        $isGrouped = $groupSiblings->count() > 1;
        $isNormalizedBookNow = $isGrouped && $booking->service_type === 'book_now';

        $alreadySent = Quotation::where('source_booking_id', $anchor->id)
            ->current()
            ->whereIn('status', ['sent', 'negotiating', 'accepted'])
            ->exists();
        if ($alreadySent) {
            return response()->json([
                'success' => false,
                'message' => 'The customer has already accepted this quotation. Use "Open Booking" to dispatch instead.',
            ], 422);
        }

        
        
        
        
        if (!empty($validated['selected_unit_id'])) {
            $conflictingReservation = Booking::reservingUnit((int) $validated['selected_unit_id'])
                ->where('id', '!=', $booking->id)
                ->exists();

            if ($conflictingReservation) {
                return response()->json([
                    'success' => false,
                    'message' => 'This unit was just reserved by another booking. Please choose a different unit.',
                ], 422);
            }
        }

        
        
        
        $distanceKm    = max((float) ($validated['distance_km'] ?? ($booking->distance_km ?? 0)), 0);
        $selectedUnit  = null;
        $unitBaseRate  = (float) ($booking->truckType?->base_rate ?? 0);

        if (!empty($validated['selected_unit_id'])) {
            $selectedUnit = Unit::with(['teamLeader', 'truckType'])->find($validated['selected_unit_id']);
            $unitBaseRate = (float) ($selectedUnit?->truckType?->base_rate ?? $unitBaseRate);
        }

        $existing = Quotation::where('source_booking_id', $anchor->id)
            ->whereIn('status', ['draft', 'pending'])
            ->latest()
            ->first();

        $persistedAdditionalFee = $existing ? (float) $existing->additional_fee : 0.0;
        $submittedAdjustments = $validated['adjustments'] ?? [];
        $submittedDelta = ! empty($submittedAdjustments)
            ? round(collect($submittedAdjustments)->sum(fn($a) => $a['type'] === 'add' ? (float) $a['amount'] : -(float) $a['amount']), 2)
            : (float) ($validated['additional_fee'] ?? 0);
        $effectiveAdditionalFee = round($persistedAdditionalFee + $submittedDelta, 2);

        $limitError = $this->enforcePriceAdjustmentLimits(
            $this->canonicalBaseTotalFor($booking, true) ?? 0.0,
            $effectiveAdditionalFee,
            $validated['dispatcher_note'] ?? null,
        );

        if ($limitError !== null) {
            return response()->json(['success' => false, 'message' => $limitError], 422);
        }

        $totals = $this->bookingService->calculateQuotationTotals(
            $booking,
            $isNormalizedBookNow ? null : (string) $effectiveAdditionalFee,
            null,
            $distanceKm,
            0,
            $unitBaseRate,
        );
        $resolvedPrice = $totals['final_total'];
        $resolvedAdditionalFee = $totals['additional_fee'];

        
        
        $quotationNumber = $existing
            ? ($existing->quotation_number ?: $this->bookingService->generateQuotationNumber($anchor))
            : $this->bookingService->generateQuotationNumber($anchor);

        $groupLineItems = $isNormalizedBookNow
            ? $groupSiblings->map(function (Booking $sibling) {
                $siblingTotals = $this->bookingService->calculateQuotationTotals(
                    $sibling,
                    null,
                    null,
                    (float) $sibling->distance_km,
                    0,
                    (float) $sibling->base_rate,
                );

                return [
                    'booking_id' => $sibling->id,
                    'truck_type_id' => $sibling->truck_type_id,
                    'truck_type_name' => $sibling->truckType?->name,
                    'vehicle_type_id' => $sibling->vehicle_type_id,
                    'service_type' => $sibling->service_type,
                    'distance_km' => (float) $sibling->distance_km,
                    'base_rate' => $siblingTotals['base_rate'],
                    'distance_fee' => $siblingTotals['distance_fee'],
                    'vat_exclusive_total' => $siblingTotals['vat_exclusive_total'],
                    'vat_amount' => $siblingTotals['vat_amount'],
                    'vat_rate' => $siblingTotals['vat_rate'],
                    'final_total' => $siblingTotals['final_total'],
                    'estimated_price' => $siblingTotals['final_total'],
                ];
            })->values()
            : ($isGrouped
                ? collect($existing->extra_vehicles ?? [])
                    ->reject(fn($ev) => ($ev['booking_id'] ?? null) === $booking->id)
                    ->push([
                        'booking_id' => $booking->id,
                        'truck_type_id' => $selectedUnit?->truckType?->id ?? $booking->truck_type_id,
                        'truck_type_name' => ($selectedUnit?->truckType?->name) ?? $booking->truckType?->name,
                        'vehicle_type_id' => $booking->vehicle_type_id,
                        'service_type' => $booking->service_type,
                        'distance_km' => $distanceKm,
                        'base_rate' => $totals['base_rate'],
                        'distance_fee' => $totals['distance_fee'],
                        'vat_exclusive_total' => $totals['vat_exclusive_total'],
                        'vat_amount' => $totals['vat_amount'],
                        'vat_rate' => $totals['vat_rate'],
                        'final_total' => $resolvedPrice,
                        'estimated_price' => $resolvedPrice,
                    ])
                    ->values()
                : collect());

        $groupBaseTotal = $isNormalizedBookNow ? $groupLineItems->sum('final_total') : 0;
        $groupDiscount = (float) ($existing?->discount ?? 0);
        $groupFinalTotal = $isNormalizedBookNow
            ? max(round($groupBaseTotal - $groupDiscount + $effectiveAdditionalFee, 2), 0)
            : 0;

        $draftData = [
            'source_booking_id'   => $anchor->id,
            'customer_id'         => $anchor->customer_id,
            'truck_type_id'       => $isGrouped ? $anchor->truck_type_id : ($selectedUnit?->truckType?->id ?? $booking->truck_type_id),
            'pickup_address'      => $anchor->pickup_address,
            'dropoff_address'     => $anchor->dropoff_address,
            'distance_km'         => $distanceKm,
            'eta_minutes'         => $booking->eta_minutes,
            'vehicle_make'        => $booking->vehicle_make,
            'vehicle_model'       => $booking->vehicle_model,
            'vehicle_year'        => $booking->vehicle_year,
            'vehicle_color'       => $booking->vehicle_color,
            'vehicle_plate_number' => $booking->vehicle_plate_number,
            'vehicle_image_path'  => $booking->vehicle_image_path,
            'estimated_price'     => $isNormalizedBookNow
                ? $groupFinalTotal
                : ($isGrouped ? max(round($groupLineItems->sum('final_total') - $groupDiscount, 2), 0) : $resolvedPrice),
            'additional_fee'      => $isNormalizedBookNow ? $effectiveAdditionalFee : $resolvedAdditionalFee,
            'vat_rate'            => $totals['vat_rate'],
            'service_type'        => $booking->service_type ?? null,
            'scheduled_date'      => $booking->scheduled_date?->toDateString(),
            'scheduled_time'      => $booking->scheduled_time,
            'pickup_notes'        => $booking->notes,
            'extra_vehicles'      => $isGrouped ? $groupLineItems->all() : $booking->extra_vehicles,
            'quotation_number'    => $quotationNumber,
            'status'              => 'draft',
        ];

        if ($existing) {
            
            
            
            
            
            
            
            
            
            $changeLog = $existing->price_change_log ?? [];
            $newQuotationPrice = $isNormalizedBookNow
                ? $groupFinalTotal
                : ($isGrouped ? max(round($groupLineItems->sum('final_total') - $groupDiscount, 2), 0) : $resolvedPrice);
            if ($existing->status === 'draft' && (float) $existing->estimated_price !== $newQuotationPrice) {
                $changeLog[] = [
                    'at'     => now()->toISOString(),
                    'old'    => (float) $existing->estimated_price,
                    'new'    => $newQuotationPrice,
                    'reason' => $validated['dispatcher_note'] ?? null,
                    'by'     => auth()->user()?->name ?? 'Dispatcher',
                ];
            }
            $draftData['price_change_log'] = $changeLog;
            $existing->update($draftData);
            $quotation = $existing->fresh();
        } else {
            $newQuotationPrice = $isNormalizedBookNow
                ? $groupFinalTotal
                : ($isGrouped ? max(round($groupLineItems->sum('final_total') - $groupDiscount, 2), 0) : $resolvedPrice);
            $draftData['price_change_log'] = ($isNormalizedBookNow ? $effectiveAdditionalFee : $resolvedAdditionalFee) !== 0.0 ? [[
                'at'     => now()->toISOString(),
                'old'    => $isNormalizedBookNow ? $groupBaseTotal : $totals['base_total'],
                'new'    => $newQuotationPrice,
                'reason' => $validated['dispatcher_note'] ?? null,
                'by'     => auth()->user()?->name ?? 'Dispatcher',
            ]] : [];
            $quotation = Quotation::create($draftData);
        }

        if (! empty($submittedAdjustments)) {
            $this->quotationService->recordPriceAdjustments($quotation->quotation_number, $submittedAdjustments, auth()->id());
        } else {
            $this->quotationService->recordAdjustmentDelta(
                $quotation->quotation_number,
                $persistedAdditionalFee,
                (float) $quotation->additional_fee,
                $validated['dispatcher_note'] ?? null,
                auth()->id(),
            );
        }

        $bookingUpdate = [
            'quotation_number'   => $quotationNumber,
            'quotation_generated' => true,
            'reviewed_at'        => $booking->reviewed_at ?? now(),
            
            
            
            
            
            'selected_unit_id'   => $selectedUnit?->id,
            'dispatcher_note'    => filled($validated['dispatcher_note'] ?? null)
                ? trim(strip_tags((string) $validated['dispatcher_note'])) : null,
        ];
        if (! $isNormalizedBookNow) {
            $bookingUpdate = array_merge($bookingUpdate, [
                'base_rate'          => $unitBaseRate,
                'per_km_rate'        => $totals['per_km_rate'],
                'computed_total'     => $totals['computed_total'],
                'additional_fee'     => $resolvedAdditionalFee,
                'final_total'        => $resolvedPrice,
                'vat_amount'         => $totals['vat_amount'],
                'vat_exclusive_total' => $totals['vat_exclusive_total'],
                'vat_rate'           => $totals['vat_rate'],
            ]);
        }
        $booking->update($this->bookingService->filterPayloadForTable('bookings', $bookingUpdate));

        AuditLog::create([
            'user_id'     => auth()->id(),
            'action'      => $existing ? 'quotation_draft_updated' : 'quotation_drafted',
            'entity_type' => 'Booking',
            'entity_id'   => $booking->id,
            'reference'   => $booking->job_code,
            'description' => 'Price recorded: ₱' . number_format($isNormalizedBookNow ? $groupFinalTotal : $resolvedPrice, 2)
                . (filled($validated['dispatcher_note'] ?? null) ? ' — ' . $validated['dispatcher_note'] : ''),
        ]);

        return response()->json([
            'success'          => true,
            'message'          => 'Quotation recorded. Send it to the customer when ready.',
            'quotation_id'     => $quotation->id,
            'quotation_number' => $quotationNumber,
            'quotation_status' => 'draft',
            'price'            => number_format($isNormalizedBookNow ? $groupFinalTotal : $resolvedPrice, 2, '.', ''),
        ]);
    }

    public function extendQuotation(Request $request, Quotation $quotation)
    {
        $validated = $request->validate([
            'additional_hours' => 'required|integer|min:1|max:168',
        ]);

        try {
            $this->quotationService->extendQuotation($quotation, $validated['additional_hours']);
        } catch (ScheduledQuoteCutoffPassedException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

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
        $units = Unit::with(['teamLeader', 'truckType', 'zone'])
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->where('status', '!=', 'maintenance')
            ->get();

        
        
        
        $activeBookingsByLeaderId = $this->teamLeaderAvailability->activeBookingsByLeaderId();

        $data = $units
            ->map(function (Unit $unit) use ($activeBookingsByLeaderId) {
                $secsAgo = $unit->location_updated_at
                    ? (int) $unit->location_updated_at->diffInSeconds(now())
                    : null;

                $locationFresh = $secsAgo !== null && $secsAgo < 60;
                $teamLeaderOnline = $this->teamLeaderAvailability->isOnline($unit->teamLeader);
                $activeBooking = $unit->team_leader_id
                    ? $activeBookingsByLeaderId->get((int) $unit->team_leader_id)
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
                    'is_online'           => $teamLeaderOnline && $locationFresh,
                    'updated_seconds_ago' => $secsAgo,
                    'job_status'          => $activeBooking?->status,
                    'job_status_label'    => $activeBooking
                        ? str($activeBooking->status)->replace('_', ' ')->title()->toString() : null,
                    'zone_name'           => $unit->zone?->name,
                ];
            })
            
            
            
            
            ->filter(fn(array $unit) => $unit['is_online'] || $unit['job_status'] !== null)
            ->values();

        return response()->json($data);
    }
}
