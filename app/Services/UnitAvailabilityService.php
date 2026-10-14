<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Personnel;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The single, shared source of truth for "is this Unit available for
 * dispatch right now" — replaces the three previously-independent formulas
 * found in DispatchController::index(), DispatchController::assignBooking(),
 * and drivers.blade.php's own inline $eff precedence.
 *
 * Deliberately keeps four concepts separate and never lets one imitate
 * another:
 *
 *   PRESENCE  — Team Leader mobile heartbeat only (online/offline).
 *               Informational. Never gates availability.
 *   DUTY      — Dispatcher-set operational attendance (available/
 *               unavailable), persisted on users.duty_status (Team Leader)
 *               and units.driver_duty_status / crew_*_duty_status
 *               (Driver/Crew, tracked per Unit slot since those are usually
 *               free-text names with no stable account of their own).
 *   WORKLOAD  — system-derived only, from the Team Leader's live Booking
 *               status (delegates to TeamLeaderAvailabilityService's
 *               canonical busy-status list — never duplicated here).
 *   AVAILABILITY — the final computed Available/Not Available verdict.
 */
class UnitAvailabilityService
{
    public function __construct(protected TeamLeaderAvailabilityService $teamLeaderAvailability)
    {
    }

    public function presence(?User $teamLeader): string
    {
        return $this->teamLeaderAvailability->isOnline($teamLeader) ? 'online' : 'offline';
    }

    public function teamLeaderDuty(?User $teamLeader): string
    {
        return $teamLeader?->dutyStatus() ?? 'unavailable';
    }

    /**
     * A unit's team_leader_id only counts when it resolves to a live Team
     * Leader account — a dangling, archived/anonymized or non-TL reference
     * must not leave the unit dispatchable with nobody to receive the job.
     */
    protected function isValidTeamLeader(?User $teamLeader): bool
    {
        return $teamLeader !== null
            && (int) $teamLeader->role_id === 3
            && $teamLeader->archived_at === null
            && $teamLeader->anonymized_at === null;
    }

    public function driverDuty(Unit $unit): string
    {
        return $unit->driverDutyStatus();
    }

    public function crewDuty(Unit $unit, int $slot): string
    {
        return $unit->crewDutyStatus($slot);
    }

    public function hasDriver(Unit $unit): bool
    {
        return filled($unit->driver_id) || filled($unit->driver_name);
    }

    /**
     * Whether the required people on a unit are eligible to work, using the
     * app's existing definitions only:
     *   - accounts (Team Leader, Driver account): User::canUseApi() — the
     *     same rule that revokes app access (status active, not archived,
     *     pending deletion or anonymized). Presence (online/offline) and the
     *     temporary brute-force lockout are deliberately not part of it.
     *   - personnel records: personnel_status = active — the rule dispatch
     *     already applies before placing a record on a unit.
     * A legacy free-text driver name has no status to check and stays as is.
     */
    public function accountIsEligible(?User $account): bool
    {
        return $account !== null && $account->canUseApi();
    }

    protected function driverIsEligible(Unit $unit, Collection $driverAccounts, Collection $driverRecords): bool
    {
        if (filled($unit->driver_id)) {
            return $this->accountIsEligible($driverAccounts->get((int) $unit->driver_id));
        }

        $record = filled($unit->driver_personnel_id) ? $driverRecords->get((int) $unit->driver_personnel_id) : null;

        return $record === null || $record->personnel_status === 'active';
    }

    /**
     * Evaluate one Unit. Prefer evaluateMany() when evaluating more than a
     * handful of units — this re-runs the batch queries every call.
     */
    public function evaluate(Unit $unit): array
    {
        return $this->evaluateMany(collect([$unit]))->first();
    }

    /**
     * Batch-evaluates a collection of Units in a small, fixed number of
     * queries regardless of how many units are passed in. Callers should
     * eager-load ['teamLeader', 'truckType'] on $units beforehand (not
     * required, but avoids per-unit lazy-load queries for those relations).
     *
     * Returns a Collection of arrays, keyed by unit id, each shaped:
     *   [
     *     'unit' => Unit,
     *     'operational_state' => 'available'|'maintenance',
     *     'presence' => 'online'|'offline'|null,           // TL only
     *     'team_leader_duty' => 'available'|'unavailable'|null,
     *     'driver_duty' => 'available'|'unavailable',
     *     'crew_duty' => ['available'|'unavailable', 'available'|'unavailable'],
     *     'workload' => 'free'|'busy'|null,                // null = no TL
     *     'active_booking' => Booking|null,
     *     'reservation' => Booking|null,
     *     'available' => bool,
     *     'reasons' => string[],   // machine-readable reason codes when NOT available
     *   ]
     */
    public function evaluateMany(Collection $units): Collection
    {
        $units = $units->values();
        $unitIds = $units->pluck('id')->all();
        $teamLeaderIds = $units->pluck('team_leader_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $busyTeamLeaderIds = $this->teamLeaderAvailability->busyTeamLeaderIds();

        $activeBookingsByLeaderId = $this->teamLeaderAvailability->activeBookingsByLeaderId();
        $activeBookingsByUnitId = $this->activeBookingsQuery()
            ->whereIn('assigned_unit_id', $unitIds)
            ->get()
            ->keyBy('assigned_unit_id');

        $reservationsByUnitId = Booking::unitReservations()
            ->whereIn('selected_unit_id', $unitIds)
            ->get(['id', 'booking_code', 'status', 'selected_unit_id'])
            ->keyBy('selected_unit_id');

        // Driver eligibility inputs, batched like everything else here.
        $driverAccounts = User::whereIn('id', $units->pluck('driver_id')->filter()->unique()->values())->get()->keyBy('id');
        $driverRecords = Personnel::whereIn('id', $units->pluck('driver_personnel_id')->filter()->unique()->values())->get()->keyBy('id');

        return $units->mapWithKeys(function (Unit $unit) use ($busyTeamLeaderIds, $activeBookingsByLeaderId, $activeBookingsByUnitId, $reservationsByUnitId, $driverAccounts, $driverRecords) {
            $teamLeader = $unit->relationLoaded('teamLeader') ? $unit->teamLeader : $unit->teamLeader()->first();
            $teamLeaderId = (int) ($unit->team_leader_id ?? 0);

            $operationalState = $unit->status === 'maintenance' ? 'maintenance' : 'available';
            $presence = $teamLeaderId > 0 ? $this->presence($teamLeader) : null;
            $teamLeaderDuty = $teamLeaderId > 0 ? $this->teamLeaderDuty($teamLeader) : null;
            $driverDuty = $this->driverDuty($unit);
            $crewDuty = [$this->crewDuty($unit, 1), $this->crewDuty($unit, 2)];

            $workload = $teamLeaderId > 0
                ? ($busyTeamLeaderIds->contains($teamLeaderId) ? 'busy' : 'free')
                : null;

            $activeBooking = $activeBookingsByUnitId->get($unit->id)
                ?? ($teamLeaderId > 0 ? $activeBookingsByLeaderId->get($teamLeaderId) : null);
            $reservation = $reservationsByUnitId->get($unit->id);

            $reasons = [];

            if ($unit->archived_at) {
                $reasons[] = 'archived';
            }
            if ($operationalState === 'maintenance') {
                $reasons[] = 'maintenance';
            }
            if ($teamLeaderId <= 0 || ! $this->isValidTeamLeader($teamLeader)) {
                $reasons[] = 'no_team_leader';
            } elseif (! $this->accountIsEligible($teamLeader)) {
                $reasons[] = 'team_leader_inactive';
            } elseif ($teamLeaderDuty === 'unavailable') {
                $reasons[] = 'team_leader_duty_unavailable';
            }
            if (! $this->hasDriver($unit)) {
                $reasons[] = 'no_driver';
            } elseif (! $this->driverIsEligible($unit, $driverAccounts, $driverRecords)) {
                $reasons[] = 'driver_inactive';
            } elseif ($driverDuty === 'unavailable') {
                $reasons[] = 'driver_duty_unavailable';
            }
            if ($workload === 'busy' || $activeBooking) {
                $reasons[] = 'busy';
            }
            if ($reservation) {
                $reasons[] = 'reserved';
            }

            return [$unit->id => [
                'unit' => $unit,
                'operational_state' => $operationalState,
                'presence' => $presence,
                'team_leader_duty' => $teamLeaderDuty,
                'driver_duty' => $driverDuty,
                'crew_duty' => $crewDuty,
                'workload' => $workload,
                'active_booking' => $activeBooking,
                'reservation' => $reservation,
                'available' => empty($reasons),
                'reasons' => $reasons,
            ]];
        });
    }

    /** Bookings in the canonical busy lifecycle, same filter evaluateMany() uses. */
    /**
     * The canonical "active job" query (busy lifecycle statuses, payment-submitted
     * waiting_verification excluded, returned bookings excluded) for callers that
     * list or count active jobs, such as the Owner Operations Monitor.
     */
    public function activeJobsQuery()
    {
        return $this->activeBookingsQuery();
    }

    protected function activeBookingsQuery()
    {
        return Booking::query()
            ->whereIn('status', $this->teamLeaderAvailability->busyStatusesList())
            ->where(function ($q) {
                $q->where('status', '!=', 'waiting_verification')->orWhereNull('payment_submitted_at');
            })
            ->whereNull('returned_at');
    }

    /**
     * Whether an active booking is using this Unit: assigned to it directly,
     * or assigned to its Team Leader with no Unit recorded on the booking.
     * A Team Leader's job on a different Unit does not hold this one.
     */
    public function bookingHoldsUnit(Booking $booking, Unit $unit): bool
    {
        if ($booking->assigned_unit_id) {
            return (int) $booking->assigned_unit_id === (int) $unit->id;
        }

        return $unit->team_leader_id && (int) $booking->assigned_team_leader_id === (int) $unit->team_leader_id;
    }

    /**
     * The booking that must keep this Unit in service right now — an active
     * job using it, or a booking reserving it (Booking::unitReservations(),
     * the same definition behind the "reserved" reason). Personnel and duty
     * are deliberately ignored: they never stop maintenance or archiving.
     * Queried fresh on every call so actions can check immediately before
     * they write.
     *
     * @return array{type: 'job'|'reservation', booking: Booking}|null
     */
    public function bookingHoldingUnit(Unit $unit, array $ignoreBookingIds = []): ?array
    {
        $job = $this->activeBookingsQuery()
            ->when($ignoreBookingIds, fn ($q) => $q->whereNotIn('id', $ignoreBookingIds))
            ->where(function ($q) use ($unit) {
                $q->where('assigned_unit_id', $unit->id);

                if ($unit->team_leader_id) {
                    $q->orWhere(fn ($q) => $q->whereNull('assigned_unit_id')->where('assigned_team_leader_id', $unit->team_leader_id));
                }
            })
            ->latest('updated_at')
            ->first();

        if ($job) {
            return ['type' => 'job', 'booking' => $job];
        }

        $reservation = Booking::unitReservations()
            ->where('selected_unit_id', $unit->id)
            ->latest('updated_at')
            ->first();

        return $reservation ? ['type' => 'reservation', 'booking' => $reservation] : null;
    }

    /**
     * An active job (same definition as above) assigned to this Team Leader,
     * whichever Unit it names. $ignoreBookingIds lets a caller that has just
     * returned a booking to dispatch leave it out.
     */
    public function activeBookingForLeader(int $teamLeaderId, array $ignoreBookingIds = []): ?Booking
    {
        return $this->activeBookingsQuery()
            ->where('assigned_team_leader_id', $teamLeaderId)
            ->when($ignoreBookingIds, fn ($q) => $q->whereNotIn('id', $ignoreBookingIds))
            ->latest('updated_at')
            ->first();
    }

    /**
     * Every non-archived Unit's availability in one pass — the shared base
     * every summary/consumer below builds on.
     */
    public function evaluateAll(): Collection
    {
        $units = Unit::with(['teamLeader', 'truckType'])
            ->whereNull('archived_at')
            ->get();

        return $this->evaluateMany($units);
    }

    /**
     * Per-Truck-Type available-unit counts (Light/Medium/Heavy summary,
     * Book Now eligibility) — the exact Truck Type only, never a
     * substitution across classes.
     */
    public function truckTypeAvailabilitySummary(): Collection
    {
        return $this->evaluateAll()
            ->filter(fn (array $row) => $row['available'])
            ->groupBy(fn (array $row) => (int) $row['unit']->truck_type_id)
            ->map(fn (Collection $rows, $truckTypeId) => [
                'truck_type_id' => (int) $truckTypeId,
                'available_count' => $rows->count(),
            ])
            ->values();
    }

    public function availableUnitIdsForTruckType(int $truckTypeId): array
    {
        return $this->evaluateAll()
            ->filter(fn (array $row) => $row['available'] && (int) $row['unit']->truck_type_id === $truckTypeId)
            ->map(fn (array $row) => $row['unit']->id)
            ->values()
            ->all();
    }

    public function isTruckTypeAvailableForBookNow(int $truckTypeId): bool
    {
        return count($this->availableUnitIdsForTruckType($truckTypeId)) > 0;
    }

    /**
     * Truck-Type-id list with at least one available Unit — kept in the same
     * shape BookingService::dispatchAvailability() already returns as
     * 'ready_truck_type_ids', so that method can delegate here instead of
     * running its own, separate formula.
     */
    public function readyTruckTypeIds(): array
    {
        return $this->evaluateAll()
            ->filter(fn (array $row) => $row['available'])
            ->map(fn (array $row) => (int) $row['unit']->truck_type_id)
            ->unique()
            ->values()
            ->all();
    }

    public function readyByClass(): array
    {
        $all = $this->evaluateAll()->filter(fn (array $row) => $row['available']);

        $truckTypes = TruckType::whereIn('id', $all->map(fn ($row) => $row['unit']->truck_type_id)->unique())
            ->get()
            ->keyBy('id');

        return $all
            ->map(fn (array $row) => strtolower((string) ($truckTypes->get($row['unit']->truck_type_id)?->class ?? '')))
            ->filter()
            ->countBy()
            ->all();
    }
}
