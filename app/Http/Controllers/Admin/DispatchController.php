<?php

namespace App\Http\Controllers\Admin;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Mail\BookingAcceptedMail;
use App\Mail\BookingRejectedMail;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\DocumentGenerationService;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\Request;
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
        $incomingRequests = Booking::with(['customer', 'truckType', 'unit.teamLeader'])
            ->whereIn('status', ['requested', 'reviewed'])
            ->oldest('updated_at')
            ->get();

        $busyTeamLeaderIds = $this->teamLeaderAvailability->busyTeamLeaderIds();
        $teamLeaderStatuses = $this->teamLeaderAvailability
            ->summarize(
                User::where('role_id', 3)->with(['unit', 'unit.driver'])->get(),
                $busyTeamLeaderIds,
            )['leaders']
            ->keyBy('id');

        $availableUnits = Unit::with(['truckType', 'driver', 'teamLeader'])
            ->where('status', 'available')
            ->whereNotNull('team_leader_id')
            ->orderBy('name')
            ->get()
            ->map(function (Unit $unit) use ($busyTeamLeaderIds, $teamLeaderStatuses) {
                $teamLeaderId = (int) ($unit->team_leader_id ?? 0);
                $leaderStatus = $teamLeaderStatuses->get($teamLeaderId, []);
                $isOnline = ($leaderStatus['presence'] ?? 'offline') === 'online';
                $hasReadyLeader = $teamLeaderId > 0 && $isOnline && ! $busyTeamLeaderIds->contains($teamLeaderId);

                return [
                    'id' => $unit->id,
                    'label' => trim(($unit->name ?? 'Unit') . ' · ' . ($unit->plate_number ?? 'No plate')),
                    'truck_type' => $unit->truckType->name ?? 'Unknown truck type',
                    'team_leader_name' => $unit->teamLeader->full_name ?? $unit->teamLeader->name ?? 'No team leader',
                    'driver_name' => $unit->driver->full_name ?? $unit->driver->name ?? 'No saved driver',
                    'status_summary' => 'Online and ready for dispatch',
                    'selectable' => $hasReadyLeader,
                ];
            })
            ->filter(fn(array $unit) => $unit['selectable'])
            ->values();

        return view('admin-dashboard.pages.dispatch', compact('incomingRequests', 'availableUnits'));
    }

    public function pendingBookingsCount()
    {
        return response()->json([
            'count' => Booking::whereIn('status', ['requested', 'reviewed'])->count(),
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
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $booking) {
                    if ($request->input('action') !== 'accept' || $value === null || $value === '') {
                        return;
                    }

                    $expectedDiscount = round((float) ($booking->discount_percentage ?? 0), 2);

                    if (abs($expectedDiscount - (float) $value) > 0.11) {
                        $fail('Discount percent is auto-computed from the customer type.');
                    }
                },
            ],
        ]);

        if (! in_array($booking->status, $this->reviewableStatuses, true)) {
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
                'assigned_unit_id' => $selectedUnit?->id ?? $booking->assigned_unit_id,
                'assigned_team_leader_id' => null,
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
            ]);
        }

        $rejectionReason = trim((string) ($validated['rejection_reason'] ?? ''));

        if ($rejectionReason === '') {
            $rejectionReason = 'Your request could not be accommodated at this time. Please contact dispatch for assistance.';
        }

        $booking->update($this->bookingService->filterPayloadForTable('bookings', [
            'status' => 'cancelled',
            'rejection_reason' => $rejectionReason,
        ]));

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
