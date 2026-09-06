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

    protected array $reviewableStatuses = Booking::REVIEWABLE_STATUSES;

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

                $query->whereIn('status', ['accepted', 'assigned'])
                    ->orWhere(function ($returnedQuery) {
                        $returnedQuery->whereIn('status', ['accepted', 'assigned'])
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
            ->get(['id', 'status', 'source_booking_id', 'estimated_price', 'counter_offer_amount', 'price_change_log', 'additional_fee'])
            ->unique('source_booking_id')          // keep latest per booking
            ->keyBy('source_booking_id');
        $bookNowRequests = $bookNowRequests->map(function ($b) use ($bookNowQuotationMap) {
            $q = $bookNowQuotationMap->get($b->id);
            $b->active_quotation_id     = $q?->id;
            $b->active_quotation_status = $q?->status;
            $b->active_quotation_price   = $q?->estimated_price;
            $b->active_quotation_counter = $q?->counter_offer_amount;
            $b->active_quotation_price_change_log = $q?->price_change_log ?? [];
            // The current quotation's own additional_fee, not the booking's own
            // denormalized column — the two can diverge once a price is set via
            // the Price Review "Adjust price" flow (a direct total override that
            // never touches additional_fee), and the dispatcher drawer needs the
            // real value to avoid inventing a phantom "Adjustments" figure.
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
            ->get(['id', 'status', 'source_booking_id', 'estimated_price', 'counter_offer_amount', 'price_change_log', 'additional_fee', 'expires_at'])
            ->unique('source_booking_id')          // keep latest per booking
            ->keyBy('source_booking_id');
        // Data parity with $bookNowRequests above — same fields, same source —
        // so the shared .rb-drawer (booking-drawer.js) can render a Scheduled
        // card exactly like a Book Now one instead of needing a second code path.
        $scheduledRequests = $scheduledRequests->map(function ($b) use ($activeQuotationMap) {
            $q = $activeQuotationMap->get($b->id);
            $b->active_quotation_id     = $q?->id;
            $b->active_quotation_status = $q?->status;
            $b->active_quotation_price   = $q?->estimated_price;
            $b->active_quotation_counter = $q?->counter_offer_amount;
            $b->active_quotation_price_change_log = $q?->price_change_log ?? [];
            $b->active_quotation_additional_fee = $q?->additional_fee ?? $b->additional_fee;
            $b->active_quotation_expires_at = $q?->expires_at;
            $b->dispatch_zone_label = $this->inferDispatchZoneLabel($b->pickup_address);

            // Single filter bucket driving both the Scheduled tab's 7-item
            // filter dropdown and its counts: Draft nests under Needs Quote,
            // Price Review Requested nests under Quote Sent (each row's own
            // STATUS text still shows the full distinction) — mirrors the
            // locked mock's filter design.
            if ($b->status === 'scheduled_confirmed') {
                $b->filter_bucket = $b->scheduling_bucket; // confirmed / upcoming / ready / overdue
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
        $groupedBookNow  = $bookNowRequests->groupBy(fn($b) => $b->group_code ?: $b->booking_code);
        $groupedScheduled = $scheduledRequests->groupBy(fn($b) => $b->group_code ?: $b->booking_code);

        $pendingQuotationCount = Quotation::where('status', 'pending')->current()->count();

        $queueCounts = [
            'all' => $incomingRequests->count(),
            'returned' => $returnedRequests->count(),
            'active' => $activeBookings->count(),
            'ready_completion' => $readyCompletionBookings->count(),
            'not_responding' => $notRespondingBookings->count(),
            'pending-quotations' => $pendingQuotationCount,
            'book-now' => $bookNowRequests->count(),
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

        $availableUnitProfiles = Unit::with(['truckType', 'driver', 'teamLeader'])
            ->where('status', 'available')
            ->whereNotNull('team_leader_id')
            ->whereNotNull('driver_id')
            ->orderBy('name')
            ->get()
            ->map(function (Unit $unit) use ($busyTeamLeaderIds, $teamLeaderStatuses, $reservedUnitBookings) {
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
                    'truck_class' => $unit->truckType->class ?? '',
                    'base_rate' => (float) ($unit->truckType->base_rate ?? 0),
                    'per_km_rate' => (float) ($unit->truckType->per_km_rate ?? 0),
                    'team_leader_name' => $unit->teamLeader->full_name ?? $unit->teamLeader->name ?? 'No team leader',
                    'driver_name' => $unit->driver->full_name ?? $unit->driver->name ?? 'No saved driver',
                    'crew_names' => collect(Unit::SLOT_COLUMNS)
                        ->reject(fn($col) => $col === 'driver_name') // driver already shown separately above
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

        // recommendUnitForBooking() needs $availableUnits, which doesn't exist yet at
        // the point $bookNowRequests is first built above — this pass has to happen
        // here instead. Previously never wired up at all for Book Now (only the old
        // #incomingList queue below got it), so the ★ recommended-unit star has never
        // actually been backed by real data until now.
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

    /**
     * Builds the "Floating Quotations" panel data (draft/pending/sent/negotiating
     * quotations + their group-sibling batches and summary stats), shared between
     * the full dispatch page load and the lightweight AJAX panel refresh.
     */
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

    /**
     * Lightweight AJAX endpoint used to refresh just the Floating Quotations panel
     * after actions like Approve, without a full dispatch-page reload.
     */
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

        // Hard filter: only ever recommend a unit whose truck class matches what the
        // customer selected. Zone history breaks ties within that class — it never
        // outweighs a class mismatch. If no same-class unit is ready, return no
        // recommendation at all rather than silently suggesting the wrong class.
        // Also exclude units soft-reserved by a *different* booking's pending quote —
        // never recommend a unit this booking can't actually pick.
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
            'count' => Booking::where(function ($query) {
                $query->whereIn('status', ['requested', 'reviewed', 'delayed'])
                    ->orWhere(function ($returnedQuery) {
                        $returnedQuery->whereIn('status', ['confirmed', 'accepted', 'assigned'])
                            ->whereNotNull('returned_at');
                    });
            })->count(),
            'scheduled_count' => Booking::where('status', 'scheduled')->count(),
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
                Rule::requiredIf(fn() => $request->input('action') === 'accept' && !in_array($booking->status, ['confirmed', 'scheduled_confirmed'])),
                'nullable',
                'numeric',
                'min:0.01',
                'max:10000',
            ],
            'distance_fee' => [
                Rule::requiredIf(fn() => $request->input('action') === 'accept' && !in_array($booking->status, ['confirmed', 'scheduled_confirmed'])),
                'nullable',
                'numeric',
                'min:0',
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $booking) {
                    if ($request->input('action') !== 'accept' || in_array($booking->status, ['confirmed', 'scheduled_confirmed']) || $value === null || $value === '') {
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
            // Re-fetch under a row lock — the $booking passed in is whatever
            // the route model binder resolved before this transaction began,
            // which could be stale if a concurrent request (a customer
            // cancellation, another dispatcher's assign/reject) is racing
            // this one. Every read/write below uses this locked instance.
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->first();
            if (! $booking) {
                return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
            }
            $booking->loadMissing(['customer', 'truckType', 'unit.teamLeader']);

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
                    $selectedUnit = Unit::with(['teamLeader', 'truckType'])
                        ->where('id', $validated['assigned_unit_id'])
                        ->lockForUpdate()
                        ->first();
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

                // Server-side Ready/Overdue dispatch guard — a Scheduled booking
                // never reserves a unit in advance, so dispatch (unit selection)
                // may only actually commit once the locked row's own computed
                // scheduling_bucket says ready/overdue. This is independent of
                // whatever the drawer UI shows, so a direct endpoint call, a
                // stale client, or a manipulated request can't dispatch early.
                // Book Now (status === 'confirmed') is untouched by this check.
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

                    AuditLog::create([
                        'user_id'     => auth()->id(),
                        'action'      => 'booking_reassigned',
                        'category'    => 'dispatch',
                        'entity_type' => 'Booking',
                        'entity_id'   => $booking->id,
                        'reference'   => $booking->job_code,
                        'description' => 'Returned task reassigned to unit ' . ($selectedUnit?->name ?? 'N/A'),
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Returned task reassigned successfully. The selected team leader can accept it now.',
                        'status' => $booking->status,
                        'assigned_unit' => $selectedUnit?->name,
                        'team_leader' => $selectedUnit?->teamLeader?->full_name ?? $selectedUnit?->teamLeader?->name,
                    ]);
                }

                if (in_array($booking->status, ['confirmed', 'scheduled_confirmed'])) {
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
            // Not rounded to 2dp here — that would reintroduce the same
                // precision-loss bug the distance_km column widening (4dp) exists
                // to fix; only final money figures get rounded, never the raw km.
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
                    'assigned_unit_id' => $selectedUnit?->id ?? $booking->assigned_unit_id,
                    'assigned_team_leader_id' => $selectedUnit?->teamLeader?->id ?? $booking->assigned_team_leader_id,
                    'base_rate' => $unitBaseRate,
                    'per_km_rate' => $totals['per_km_rate'],
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

                $initialQuotePath = $this->documentGenerationService->generateQuotation($booking);
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

            // A dispatcher cancelling an already-accepted Scheduled booking
            // (the Overdue row's "Cancel Booking" button, wired through this
            // same action:'reject' contract) is a different event from
            // declining a request that was never quoted — it must not create
            // a second "declining" Quotation, and the real accepted Quotation
            // must be left completely untouched as historical evidence of
            // what the customer agreed to.
            $isScheduledCancellation = $booking->status === 'scheduled_confirmed';

            if ($isScheduledCancellation && $rejectionReason === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'A reason is required to cancel this scheduled booking.',
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

            AuditLog::create([
                'user_id'     => auth()->id(),
                'action'      => $isScheduledCancellation ? 'scheduled_booking_cancelled_by_dispatcher' : 'booking_rejected',
                'entity_type' => 'Booking',
                'entity_id'   => $booking->id,
                'reference'   => $booking->job_code,
                'description' => $rejectionReason,
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

    /**
     * Reschedule an already-confirmed Scheduled booking to a new date/time.
     * No reschedule endpoint existed anywhere in the app before this — built
     * fresh rather than reusing something that doesn't exist. Never touches
     * assigned_unit_id/assigned_team_leader_id: rescheduling never creates a
     * future Unit/TL reservation, matching the rest of the Scheduled flow.
     * Confirmed/Upcoming/Ready/Overdue recompute automatically off the new
     * scheduled_date/scheduled_time via Booking::getSchedulingBucketAttribute()
     * — nothing else needs to be recalculated by hand.
     */
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
                // Defensive only — a confirmed Scheduled booking should never
                // carry a soft reservation in the first place (no future-unit
                // concept exists for Scheduled), but clear it just in case.
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

        // Load group siblings for linked-vehicle display
        $groupSiblings = [];
        $sourceBooking = $quotation->sourceBooking;
        if ($sourceBooking && $sourceBooking->group_code) {
            $groupSiblings = \App\Models\Booking::where('group_code', $sourceBooking->group_code)
                ->where('id', '!=', $sourceBooking->id)
                ->with('truckType')
                ->get(['id', 'booking_code', 'status', 'service_type', 'truck_type_id', 'scheduled_date', 'scheduled_time', 'group_code', 'final_total'])
                ->map(fn($b) => [
                    'booking_code'   => $b->booking_code,
                    'status'         => $b->status,
                    'service_type'   => $b->service_type,
                    'truck_type'     => $b->truckType?->name ?? 'Unknown',
                    'truck_class'    => $b->truckType?->class ?? null,
                    'scheduled_date' => $b->scheduled_date,
                    'scheduled_time' => $b->scheduled_time,
                    'final_total'    => (float) $b->final_total,
                ])
                ->values()
                ->toArray();
        }

        $distanceKm    = (float) ($quotation->distance_km ?? 0);
        $finalTotal    = (float) ($quotation->estimated_price ?? 0);
        $additionalFee = (float) ($quotation->additional_fee ?? 0);
        $discount      = (float) ($quotation->discount ?? 0);
        $basePrice     = (float) ($quotation->truckType?->base_rate ?? 0);
        $perKmRate     = (float) ($quotation->truckType?->per_km_rate ?? 0);
        $distanceFee   = $this->bookingService->distanceFeeFor($distanceKm, $perKmRate);

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
                'subtotal'              => $finalTotal,
                'counter_offer_amount'  => $quotation->counter_offer_amount,
                'response_note'         => $quotation->response_note,
                'status'                => $quotation->status,
                'service_type'          => $quotation->service_type,
                'link_version'          => $quotation->link_version ?? 1,
                'vehicle_image_paths'   => $quotation->vehicle_image_paths    ?: ($quotation->sourceBooking?->vehicle_image_paths ?? []),
                'extra_vehicles'        => $this->enrichExtraVehicles($quotation->extra_vehicles ?? []),
                'total_vehicles'        => 1 + count($quotation->extra_vehicles ?? []),
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

        // Use the price already recorded/edited by the dispatcher — no unit required at this stage
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

        return response()->json([
            'success' => true,
            'message' => 'Quotation sent to customer successfully.',
            'quotation_number' => $quotation->quotation_number,
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

    /**
     * Book Now-only. New, dedicated endpoints for the price-review flow —
     * deliberately NOT folded into updateQuotationPrice()/cancelQuotation()
     * above, since Scheduled's old _quotation-modal.blade.php still calls
     * those two exact methods and must keep working unchanged.
     */
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
        ]);
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

        try {
            $next = $this->quotationService->resolvePriceReviewWithNewPrice(
                $quotation,
                (float) $validated['new_price'],
                $validated['note'] ?? null,
                (float) ($validated['additional_fee'] ?? 0),
            );
        } catch (ScheduledQuoteCutoffPassedException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($next->source_booking_id) {
            $sourceBooking = Booking::find($next->source_booking_id);
            $sourceBooking?->update($this->bookingService->filterPayloadForTable('bookings', [
                'final_total'   => $next->estimated_price,
                'quotation_id'  => $next->id,
            ]));
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

        $validated = $request->validate([
            'new_price' => 'required|numeric|min:0.01',
            'additional_fee' => 'nullable|numeric',
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
            'additional_fee'       => $validated['additional_fee'] ?? 0,
            'discount'             => 0,
            'counter_offer_amount' => null,
            'response_note'        => null,
            'status'               => $newStatus,
            'price_change_log'     => $changeLog,
        ];

        try {
            $quotation = $quotation->newVersion($updateData);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'This quotation was already revised by someone else. Please refresh and try again.',
            ], 409);
        }

        $sourceBooking = $quotation->source_booking_id
            ? Booking::find($quotation->source_booking_id)
            : Booking::where('quotation_id', $previousQuotationId)->first();
        if ($sourceBooking) {
            $bookingUpdate = ['final_total' => $newPrice, 'quotation_id' => $quotation->id];
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
            'new_price' => number_format($validated['new_price'], 2),
        ]);
    }

    public function saveQuotationDraft(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'price'            => 'required|numeric|min:0',
            'additional_fee'   => 'nullable|numeric',
            'selected_unit_id' => 'nullable|integer|exists:units,id',
            'dispatcher_note'  => [
                Rule::requiredIf(fn() => (float) $request->input('additional_fee', 0) !== 0.0),
                'nullable',
                'string',
                'max:1000',
            ],
            'distance_km'      => 'nullable|numeric|min:0.01|max:10000',
        ]);

        // Scheduled bookings never reserve a Unit/TL before Ready/Overdue —
        // this endpoint is shared with Book Now (which still gets its normal
        // soft-reservation behavior below), so a Scheduled booking's own
        // pre-dispatch quote-phase saves must always ignore any
        // selected_unit_id the request carries, regardless of who sent it or
        // why. Stripped here, before the value is used or persisted anywhere
        // below.
        if ($booking->is_scheduled) {
            $validated['selected_unit_id'] = null;
        }

        $alreadySent = Quotation::where('source_booking_id', $booking->id)
            ->current()
            ->whereIn('status', ['sent', 'negotiating', 'accepted'])
            ->exists();
        if ($alreadySent) {
            return response()->json([
                'success' => false,
                'message' => 'The customer has already accepted this quotation. Use "Open Booking" to dispatch instead.',
            ], 422);
        }

        // A unit picked here is only a soft reservation (see Booking::REVIEWABLE_STATUSES
        // / scopeReservingUnit) — but a dispatcher on a stale page could still submit a
        // pick another booking already grabbed in the meantime. Reject that server-side
        // regardless of what the client believed was available.
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

        // Not rounded to 2dp here — that would reintroduce the same
        // precision-loss bug the distance_km column widening (4dp) exists to
        // fix; only final money figures get rounded, never the raw km.
        $distanceKm    = max((float) ($validated['distance_km'] ?? ($booking->distance_km ?? 0)), 0);
        $selectedUnit  = null;
        $unitBaseRate  = (float) ($booking->truckType?->base_rate ?? 0);

        if (!empty($validated['selected_unit_id'])) {
            $selectedUnit = Unit::with(['teamLeader', 'truckType'])->find($validated['selected_unit_id']);
            $unitBaseRate = (float) ($selectedUnit?->truckType?->base_rate ?? $unitBaseRate);
        }

        // Save or update draft quotation linked to source booking
        $existing = Quotation::where('source_booking_id', $booking->id)
            ->whereIn('status', ['draft', 'pending'])
            ->latest()
            ->first();

        // Use the existing draft's number if updating; always generate fresh when creating
        // to avoid unique-constraint violations from previously cancelled quotation numbers.
        $quotationNumber = $existing
            ? ($existing->quotation_number ?: $this->bookingService->generateQuotationNumber($booking))
            : $this->bookingService->generateQuotationNumber($booking);

        $draftData = [
            'source_booking_id'   => $booking->id,
            'customer_id'         => $booking->customer_id,
            'truck_type_id'       => $selectedUnit?->truckType?->id ?? $booking->truck_type_id,
            'pickup_address'      => $booking->pickup_address,
            'dropoff_address'     => $booking->dropoff_address,
            'distance_km'         => $distanceKm,
            'eta_minutes'         => $booking->eta_minutes,
            'vehicle_make'        => $booking->vehicle_make,
            'vehicle_model'       => $booking->vehicle_model,
            'vehicle_year'        => $booking->vehicle_year,
            'vehicle_color'       => $booking->vehicle_color,
            'vehicle_plate_number' => $booking->vehicle_plate_number,
            'vehicle_image_path'  => $booking->vehicle_image_path,
            'estimated_price'     => (float) $validated['price'],
            'additional_fee'      => (float) ($validated['additional_fee'] ?? 0),
            'service_type'        => $booking->service_type ?? null,
            'scheduled_date'      => $booking->scheduled_date?->toDateString(),
            'scheduled_time'      => $booking->scheduled_time,
            'pickup_notes'        => $booking->notes,
            'extra_vehicles'      => $booking->extra_vehicles,
            'quotation_number'    => $quotationNumber,
            'status'              => 'draft',
        ];

        if ($existing) {
            // Log price change if price changed — but only when comparing against
            // a REAL prior dispatcher-set price ('draft'). A 'pending' $existing
            // row is just the mobile app's own auto-generated placeholder estimate
            // (CustomerBookingController), computed from the customer's raw/unrounded
            // distance before it was stored (and rounded) into distance_km — so a
            // dispatcher's first-ever save recomputing from that rounded value
            // almost always lands a few centavos off the customer's original
            // number, even with zero manual edits. That reconciliation isn't a
            // deliberate "price update" and must not be logged as one.
            $changeLog = $existing->price_change_log ?? [];
            if ($existing->status === 'draft' && (float) $existing->estimated_price !== (float) $validated['price']) {
                $changeLog[] = [
                    'at'     => now()->toISOString(),
                    'old'    => (float) $existing->estimated_price,
                    'new'    => (float) $validated['price'],
                    'reason' => $validated['dispatcher_note'] ?? null,
                    'by'     => auth()->user()?->name ?? 'Dispatcher',
                ];
            }
            $draftData['price_change_log'] = $changeLog;
            $existing->update($draftData);
            $quotation = $existing->fresh();
        } else {
            $additionalFee = (float) ($validated['additional_fee'] ?? 0);
            $draftData['price_change_log'] = $additionalFee !== 0.0 ? [[
                'at'     => now()->toISOString(),
                'old'    => (float) $validated['price'] - $additionalFee,
                'new'    => (float) $validated['price'],
                'reason' => $validated['dispatcher_note'] ?? null,
                'by'     => auth()->user()?->name ?? 'Dispatcher',
            ]] : [];
            $quotation = Quotation::create($draftData);
        }

        $booking->update($this->bookingService->filterPayloadForTable('bookings', [
            'quotation_number'   => $quotationNumber,
            'quotation_generated' => true,
            'reviewed_at'        => $booking->reviewed_at ?? now(),
            // Always the request's own value (never a fallback to the old
            // one) — the drawer's payload always carries selected_unit_id
            // explicitly, including null for a deliberate deselect, and a
            // `??` fallback here would silently ignore that and keep the
            // stale unit reserved.
            'selected_unit_id'   => $selectedUnit?->id,
            'final_total'        => (float) $validated['price'],
            'dispatcher_note'    => filled($validated['dispatcher_note'] ?? null)
                ? trim(strip_tags((string) $validated['dispatcher_note'])) : null,
        ]));

        AuditLog::create([
            'user_id'     => auth()->id(),
            'action'      => $existing ? 'quotation_draft_updated' : 'quotation_drafted',
            'entity_type' => 'Booking',
            'entity_id'   => $booking->id,
            'reference'   => $booking->job_code,
            'description' => 'Price recorded: ₱' . number_format((float) $validated['price'], 2)
                . (filled($validated['dispatcher_note'] ?? null) ? ' — ' . $validated['dispatcher_note'] : ''),
        ]);

        return response()->json([
            'success'          => true,
            'message'          => 'Quotation recorded. Send it to the customer when ready.',
            'quotation_id'     => $quotation->id,
            'quotation_number' => $quotationNumber,
            'quotation_status' => 'draft',
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

        // Batched once for all units — the real, granular job status (never
        // reflected by the Unit.status/dispatcher_status columns, which only
        // ever change at coarse assignment/override/completion moments).
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
            // Keep a unit on the map if it's presence+GPS online, OR it has an
            // active job — a TL can briefly go presence-stale mid-job (e.g. app
            // backgrounded) without losing their booking, and shouldn't vanish
            // from live tracking while still actively working.
            ->filter(fn(array $unit) => $unit['is_online'] || $unit['job_status'] !== null)
            ->values();

        return response()->json($data);
    }
}
