<?php

namespace App\Http\Controllers\Admin;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Mail\BookingReceiptMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Services\CustomerNotificationService;
use App\Services\DocumentGenerationService;
use App\Services\TeamLeaderAvailabilityService;
use App\Services\UnitAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class JobsController extends Controller
{
    protected array $activeStatuses = [
        'assigned', 'accepted', 'on_the_way', 'arrived_pickup',
        'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff',
        'waiting_verification', 'payment_pending', 'payment_submitted', 'delayed',
    ];

    protected array $verificationStatuses = ['waiting_verification', 'payment_pending', 'payment_submitted'];

    /**
     * Correcting an accidental initial assignment is only safe before the Team
     * Leader has accepted — nothing customer-facing has happened yet and no
     * GPS/arrival milestones exist to unwind. Once accepted, dispatchers must
     * use the normal Returned flow instead.
     */
    public const REASSIGNABLE_STATUS = 'assigned';

    public const REASSIGN_REASONS = [
        'Wrong TL / Unit selected',
        'Assigned TL unavailable',
        'Vehicle / unit issue',
        'Dispatch adjustment',
        'Other',
    ];

    public function index()
    {
        $allJobs = Booking::with(['customer', 'truckType', 'unit.driver', 'assignedTeamLeader'])
            ->whereIn('status', $this->activeStatuses)
            ->latest()
            ->get();

        $groups = $allJobs
            ->groupBy(fn(Booking $b) => $b->group_code
                ? $b->group_code . '|' . $b->pickup_address . '|' . $b->dropoff_address
                : 'solo-' . $b->id)
            ->map(function ($members) {
                $sorted = $members->sortBy('id')->values();
                $primary = $sorted->first();
                $primary->sibling_bookings = $sorted;
                $primary->group_total = $this->isNormalizedGroupBooking($primary)
                    ? $this->activeGroupTotal($primary, $sorted)
                    : null;
                return $primary;
            })
            ->values()
            ->sortByDesc(fn(Booking $b) => $b->created_at?->getTimestamp() ?? 0)
            ->values();

        $page = (int) request('page', 1);
        $perPage = 12;
        $jobs = new \Illuminate\Pagination\LengthAwarePaginator(
            $groups->forPage($page, $perPage),
            $groups->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );

        $stats = [
            'total'             => Booking::whereIn('status', $this->activeStatuses)->count(),
            'operational'       => Booking::whereIn('status', array_values(array_diff($this->activeStatuses, $this->verificationStatuses)))->count(),
            'awaiting_payment'  => Booking::whereIn('status', $this->verificationStatuses)->count(),
            'delayed'           => Booking::where('status', 'delayed')->count(),
            'assigned'              => Booking::whereIn('status', ['assigned', 'accepted'])->count(),
            'en_route'              => Booking::whereIn('status', ['on_the_way', 'arrived_pickup'])->count(),
            'in_service'            => Booking::whereIn('status', ['in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'])->count(),
            'awaiting_verification' => Booking::where('status', 'waiting_verification')->count(),
        ];

        return view('admin-dashboard.pages.jobs', compact('jobs', 'stats'));
    }

    private function isNormalizedGroupBooking(Booking $booking): bool
    {
        if (! $booking->group_code || ! $booking->quotation_id) {
            return false;
        }
        $quotation = \App\Models\Quotation::find($booking->quotation_id);
        if (! $quotation) {
            return false;
        }
        return collect($quotation->extra_vehicles ?? [])->contains(fn($ev) => ! empty($ev['booking_id']));
    }

    private function activeGroupTotal(Booking $primary, \Illuminate\Support\Collection $activeMembers): ?float
    {
        $quotation = \App\Models\Quotation::find($primary->quotation_id);
        if (! $quotation) {
            return null;
        }

        $expectedMemberCount = 1 + collect($quotation->extra_vehicles ?? [])
            ->filter(fn($ev) => ! empty($ev['booking_id']))
            ->count();

        if ($activeMembers->count() >= $expectedMemberCount) {
            return (float) $quotation->estimated_price;
        }

        $adjustment = (float) ($quotation->additional_fee ?? 0) - (float) ($quotation->discount ?? 0);

        return max(round($activeMembers->sum(fn($member) => (float) $member->final_total) + $adjustment, 2), 0);
    }

    public function confirmPayment(Request $request, Booking $booking)
    {
        $readyStatuses = ['waiting_verification', 'payment_pending', 'payment_submitted'];
        $isGroup = $this->isNormalizedGroupBooking($booking);

        $outcome = DB::transaction(function () use ($booking, $readyStatuses, $isGroup) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if ($locked->status === 'completed') {
                return ['already' => $locked];
            }

            if (! in_array($locked->status, $readyStatuses, true)) {
                return ['error' => 'This booking is not ready for completion.'];
            }

            $members = $isGroup
                ? Booking::where('group_code', $locked->group_code)
                    ->where('pickup_address', $locked->pickup_address)
                    ->where('dropoff_address', $locked->dropoff_address)
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get()
                    ->reject(fn($member) => $member->status === 'cancelled')
                    ->values()
                : collect([$locked]);

            $notReady = $members->filter(fn($member) => $member->id !== $locked->id
                && $member->status !== 'completed'
                && ! in_array($member->status, $readyStatuses, true));
            if ($notReady->isNotEmpty()) {
                return ['error' => 'All vehicles in this group must submit payment before it can be confirmed.'];
            }

            foreach ($members as $member) {
                if ($member->status === 'completed') {
                    continue;
                }

                $wasSubmitted = $member->payment_submitted_at !== null;

                $member->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);

                if (! $wasSubmitted) {
                    if ($member->assigned_unit_id) {
                        Unit::whereKey($member->assigned_unit_id)->update(['status' => 'available']);
                    }
                    if ($member->assigned_team_leader_id) {
                        $tl = User::find($member->assigned_team_leader_id);
                        if ($tl) {
                            app(TeamLeaderAvailabilityService::class)->setOperationalOverride($tl, 'available');
                        }
                    }
                }
            }

            AuditLog::create([
                'user_id'     => auth()->id(),
                'action'      => 'payment_confirmed',
                'entity_type' => 'Booking',
                'entity_id'   => $locked->id,
                'reference'   => $locked->job_code,
                'description' => 'Job completed' . ($locked->cash_received !== null ? ' — cash received ₱' . number_format((float) $locked->cash_received, 2) : ''),
            ]);

            return ['booking' => $locked, 'members' => $members];
        });

        if (isset($outcome['error'])) {
            return response()->json(['success' => false, 'message' => $outcome['error']], 422);
        }

        if (isset($outcome['already'])) {
            return response()->json([
                'success' => true,
                'message' => 'This job was already confirmed.',
            ]);
        }

        $booking = $outcome['booking'];
        $members = $outcome['members'];
        $booking->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt']);

        BookingStatusUpdated::safeFire($booking);
        foreach ($members as $member) {
            if ($member->id === $booking->id) {
                continue;
            }
            try { BookingStatusUpdated::safeFire($member->fresh()); } catch (\Throwable) {}
        }

        if ($booking->customer && $booking->customer->user_id) {
            CustomerNotificationService::send(
                userId: $booking->customer->user_id,
                type: 'booking_update',
                title: 'Your booking is complete',
                body: 'Booking ' . $booking->booking_code . ' has been completed.',
                bookingCode: $booking->booking_code,
            );
        }

        $bookingId = $booking->id;
        $quotationId = $booking->quotation_id;
        app()->terminating(function () use ($bookingId, $isGroup, $quotationId) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            try {
                $b = Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt'])
                    ->find($bookingId);
                if (! $b) {
                    return;
                }
                if ($b->receipt && $b->receipt->email_sent) {
                    return;
                }

                $documentService = app(DocumentGenerationService::class);
                $finalQuotePath  = $documentService->generateQuotation($b, true);
                $b->update(['final_quote_path' => $finalQuotePath]);

                if ($isGroup && $quotationId) {
                    $quotation = \App\Models\Quotation::find($quotationId);
                    $cancelledGroupBookingIds = Booking::where('group_code', $b->group_code)
                        ->where('status', 'cancelled')
                        ->pluck('id');
                    $groupVehicles = collect($quotation->extra_vehicles ?? [])
                        ->reject(fn($ev) => in_array($ev['booking_id'] ?? null, $cancelledGroupBookingIds->all(), true))
                        ->map(function ($ev) {
                            $truckTypeName = $ev['truck_type_name']
                                ?? (\App\Models\TruckType::find($ev['truck_type_id'] ?? null)?->name);
                            return [
                                'truck_type_name' => $truckTypeName ?? 'Towing Service',
                                'final_total'     => (float) ($ev['final_total'] ?? $ev['estimated_price'] ?? 0),
                            ];
                        })->values()->all();
                    $groupAdjustment = (float) ($quotation->additional_fee ?? 0) - (float) ($quotation->discount ?? 0);
                    $receipt = $documentService->generateReceipt($b, $groupVehicles, $groupAdjustment);
                } else {
                    $receipt = $documentService->generateReceipt($b);
                }

                if (filled($b->customer?->email)) {
                    Mail::to($b->customer->email)->send(
                        new BookingReceiptMail(
                            $b->fresh(['customer', 'truckType', 'receipt']),
                            $groupVehicles ?? [],
                            $groupAdjustment ?? 0.0
                        )
                    );
                    $receipt->update(['email_sent' => true]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Background receipt generation failed', [
                    'booking_id' => $bookingId,
                    'error'      => $e->getMessage(),
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment confirmed. Job completed and receipt sent to customer.',
        ]);
    }

    /**
     * Fresh unit/team-leader options for the Reassign Task modal — fetched on
     * open rather than reused from the page's initial load, since availability
     * can change between when the Active Jobs table rendered and when the
     * dispatcher opens the drawer.
     */
    public function reassignOptions(Booking $booking, UnitAvailabilityService $unitAvailability, TeamLeaderAvailabilityService $teamLeaderAvailability)
    {
        if ($booking->status !== self::REASSIGNABLE_STATUS) {
            return response()->json([
                'success' => false,
                'message' => $this->reassignBlockedMessage($booking->status),
            ], 409);
        }

        $booking->loadMissing(['unit', 'assignedTeamLeader']);

        $candidateUnits = Unit::with(['truckType', 'driver', 'teamLeader'])
            ->whereNull('archived_at')
            // Matches the literal status the /admin-dashboard/dispatch picker
            // requires (DispatchController::index()) — not just "not in
            // maintenance". UnitAvailabilityService deliberately treats
            // Unit.status as maintenance-only, so this is enforced separately.
            ->where('status', 'available')
            ->where('truck_type_id', $booking->truck_type_id)
            ->whereNotNull('team_leader_id')
            ->where('id', '!=', $booking->assigned_unit_id)
            ->orderBy('name')
            ->get();

        $evaluated = $unitAvailability->evaluateMany($candidateUnits);

        $options = $candidateUnits
            ->filter(function (Unit $unit) use ($evaluated, $teamLeaderAvailability) {
                if (! ($evaluated->get($unit->id)['available'] ?? false)) {
                    return false;
                }

                // Matches DispatchController::index()'s picker: a dispatcher-set
                // busy/unavailable override on the Team Leader excludes the unit
                // there too — UnitAvailabilityService doesn't know about overrides.
                $override = $unit->teamLeader ? $teamLeaderAvailability->operationalOverride($unit->teamLeader) : null;

                return ! in_array($override['status'] ?? null, ['busy', 'unavailable'], true);
            })
            ->map(function (Unit $unit) {
                $teamLeaderName = $unit->teamLeader?->full_name ?? $unit->teamLeader?->name ?? 'Unassigned';

                return [
                    'unit_id' => $unit->id,
                    'unit_name' => $unit->name,
                    'plate_number' => $unit->plate_number,
                    'team_leader_id' => $unit->team_leader_id,
                    'team_leader_name' => $teamLeaderName,
                    'driver_name' => $unit->driver?->full_name ?? $unit->driver?->name ?? $unit->driver_name ?? null,
                    'label' => trim(($unit->name ?? 'Unit') . ' · ' . $teamLeaderName),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'current' => [
                'unit_id' => $booking->assigned_unit_id,
                'unit_name' => $booking->unit?->name,
                'team_leader_id' => $booking->assigned_team_leader_id,
                'team_leader_name' => $booking->assignedTeamLeader?->full_name ?? $booking->assignedTeamLeader?->name,
            ],
            'options' => $options,
            'reasons' => self::REASSIGN_REASONS,
        ]);
    }

    /**
     * Dispatcher-initiated correction of an accidental initial assignment.
     * Deliberately kept separate from DispatchController::assignBooking() —
     * this only ever swaps assignment ownership on a booking that stays
     * 'assigned'; it never touches quotation, payment, or Returned-flow state.
     */
    public function reassign(Request $request, Booking $booking, UnitAvailabilityService $unitAvailability, TeamLeaderAvailabilityService $teamLeaderAvailability)
    {
        $validated = $request->validate([
            'assigned_unit_id' => ['required', 'integer', 'exists:units,id'],
            'reason' => ['required', 'string', Rule::in(self::REASSIGN_REASONS)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['reason'] === 'Other' && blank($validated['notes'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'Please add a note describing the reason.',
                'errors' => ['notes' => ['Notes are required when selecting "Other".']],
            ], 422);
        }

        $notes = filled($validated['notes'] ?? null) ? trim(strip_tags((string) $validated['notes'])) : null;

        return DB::transaction(function () use ($booking, $validated, $notes, $unitAvailability, $teamLeaderAvailability) {
            // Never trust the status/ownership read before the lock — a concurrent
            // dispatcher reassignment or a TL accept() could have already landed.
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if (! $locked) {
                return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
            }

            if ($locked->status !== self::REASSIGNABLE_STATUS) {
                return response()->json([
                    'success' => false,
                    'message' => $this->reassignBlockedMessage($locked->status),
                ], 409);
            }

            if ((int) $validated['assigned_unit_id'] === (int) $locked->assigned_unit_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Select a different unit/team leader — this is already the current assignment.',
                ], 422);
            }

            $selectedUnit = Unit::with(['teamLeader', 'truckType', 'driver'])
                ->where('id', $validated['assigned_unit_id'])
                ->lockForUpdate()
                ->first();

            if (! $selectedUnit || $selectedUnit->archived_at || empty($selectedUnit->team_leader_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected unit no longer has a Team Leader assigned.',
                ], 422);
            }

            // Re-verified independently of reassignOptions()'s query filter —
            // stale modal data (unit went to maintenance/on a job after the
            // dispatcher opened the modal) must not slip through on submit.
            if ($selectedUnit->status !== 'available') {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected unit is no longer available. Please choose another.',
                ], 422);
            }

            if ((int) $selectedUnit->truck_type_id !== (int) $locked->truck_type_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'This unit is not compatible with the booking vehicle.',
                ], 422);
            }

            // Re-verified independently for the same reason — a dispatcher
            // could mark this Team Leader busy/unavailable after the modal
            // was opened but before this submit landed.
            $override = $teamLeaderAvailability->operationalOverride($selectedUnit->teamLeader);
            if (in_array($override['status'] ?? null, ['busy', 'unavailable'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This Team Leader is currently marked ' . $override['status'] . ' by dispatch and cannot be assigned.',
                ], 422);
            }

            $evaluation = $unitAvailability->evaluate($selectedUnit);
            if (! ($evaluation['available'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This Team Leader/unit is no longer available. Please choose another.',
                ], 422);
            }

            $locked->loadMissing(['unit', 'assignedTeamLeader']);
            $previousUnit = $locked->unit;
            $previousTeamLeader = $locked->assignedTeamLeader;

            $oldValue = [
                'status' => $locked->status,
                'unit_id' => $locked->assigned_unit_id,
                'unit_name' => $previousUnit?->name,
                'team_leader_id' => $locked->assigned_team_leader_id,
                'team_leader_name' => $previousTeamLeader?->full_name ?? $previousTeamLeader?->name,
                'assigned_at' => $locked->assigned_at?->toIso8601String(),
            ];

            $locked->update([
                'assigned_unit_id' => $selectedUnit->id,
                'assigned_team_leader_id' => $selectedUnit->team_leader_id,
                'assigned_at' => now(),
                // Clears any stale driver-name override left over from the previous
                // unit — mirrors the same cleanup DispatchController::assignBooking()
                // already does whenever assignment ownership changes.
                'driver_name' => null,
            ]);

            $locked->refresh()->loadMissing(['customer', 'truckType', 'unit.teamLeader', 'unit.driver', 'assignedTeamLeader']);

            $newTeamLeaderName = $selectedUnit->teamLeader?->full_name ?? $selectedUnit->teamLeader?->name;

            $newValue = [
                'unit_id' => $selectedUnit->id,
                'unit_name' => $selectedUnit->name,
                'team_leader_id' => $selectedUnit->team_leader_id,
                'team_leader_name' => $newTeamLeaderName,
                'reason' => $validated['reason'],
                'notes' => $notes,
            ];

            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'dispatcher_reassigned',
                'category' => 'dispatch',
                'entity_type' => 'Booking',
                'entity_id' => $locked->id,
                'reference' => $locked->job_code,
                'description' => 'Reassigned from ' . ($oldValue['team_leader_name'] ?? 'N/A') . ' (' . ($oldValue['unit_name'] ?? 'N/A') . ') to '
                    . ($newTeamLeaderName ?? 'N/A') . ' (' . $selectedUnit->name . ') — ' . $validated['reason'],
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ]);

            BookingStatusUpdated::safeFire($locked);

            return response()->json([
                'success' => true,
                'message' => 'Task reassigned. The previous Team Leader no longer has access to this booking.',
                'status' => $locked->status,
                'unit_id' => $locked->assigned_unit_id,
                'unit_name' => $locked->unit?->name,
                'team_leader_id' => $locked->assigned_team_leader_id,
                'team_leader_name' => $locked->assignedTeamLeader?->full_name ?? $locked->assignedTeamLeader?->name,
                'driver_name' => $locked->driver_name
                    ?? $locked->unit?->driver?->full_name
                    ?? $locked->unit?->driver?->name
                    ?? $locked->unit?->driver_name,
            ]);
        });
    }

    private function reassignBlockedMessage(string $status): string
    {
        if ($status === 'accepted') {
            return 'This task was already accepted by the Team Leader and can no longer be reassigned from here.';
        }

        return 'This booking is no longer in the Assigned state — it can\'t be reassigned from here anymore.';
    }
}
