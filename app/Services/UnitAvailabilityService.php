<?php

namespace App\Services;

use App\Models\Booking;
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
        $activeBookingsByUnitId = Booking::query()
            ->whereIn('assigned_unit_id', $unitIds)
            ->whereIn('status', $this->teamLeaderAvailability->busyStatusesList())
            ->where(function ($q) {
                $q->where('status', '!=', 'waiting_verification')->orWhereNull('payment_submitted_at');
            })
            ->whereNull('returned_at')
            ->get()
            ->keyBy('assigned_unit_id');

        $reservationsByUnitId = Booking::unitReservations()
            ->whereIn('selected_unit_id', $unitIds)
            ->get(['id', 'booking_code', 'status', 'selected_unit_id'])
            ->keyBy('selected_unit_id');

        return $units->mapWithKeys(function (Unit $unit) use ($busyTeamLeaderIds, $activeBookingsByLeaderId, $activeBookingsByUnitId, $reservationsByUnitId) {
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
            if ($teamLeaderId <= 0) {
                $reasons[] = 'no_team_leader';
            } elseif ($teamLeaderDuty === 'unavailable') {
                $reasons[] = 'team_leader_duty_unavailable';
            }
            if (! $this->hasDriver($unit)) {
                $reasons[] = 'no_driver';
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
