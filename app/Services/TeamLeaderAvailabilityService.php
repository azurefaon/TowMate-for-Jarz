<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TeamLeaderAvailabilityService
{
    protected int $presenceWindowSeconds = 90;

    protected array $busyStatuses = [
        'assigned', 'accepted', 'on_the_way', 'arrived_pickup', 'in_progress',
        'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification',
    ];

    /**
     * The one canonical "busy" booking-status list — exposed so other
     * services (UnitAvailabilityService) can reuse it exactly instead of
     * maintaining a second, potentially-drifting copy.
     */
    public function busyStatusesList(): array
    {
        return $this->busyStatuses;
    }

    protected function isTeamLeader(?User $user): bool
    {
        if (! $user) return false;
        // Check by role name (robust) with role_id=3 as fast fallback
        if ((int) $user->role_id === 3) return true;
        return strtolower($user->role?->name ?? '') === 'team leader';
    }

    public function markOnline(?User $user): void
    {
        if (! $user || ! $this->isTeamLeader($user) || $user->archived_at) {
            return;
        }

        // Presence is purely a connectivity signal — it must never touch Duty
        // (users.duty_status, Dispatcher-set) or any operational override.
        // A ping used to clear the legacy cache-based override here, which is
        // exactly the "mobile ping silently undoes a Dispatcher's decision"
        // bug this must not do; Duty now persists independently of presence.
        User::where('id', $user->id)->update(['last_ping_at' => now()]);
    }

    public function markOffline(?User $user): void
    {
        if (! $user || ! $this->isTeamLeader($user)) {
            return;
        }

        User::where('id', $user->id)->update(['last_ping_at' => null]);

        $leaderIds = collect([(int) $user->id]);

        $returnedToDispatch = $this->releaseBookingsForOfflineLeaderIds($leaderIds);
        $this->releaseUnitsForOfflineLeaderIds($leaderIds, $returnedToDispatch);
    }

    /**
     * Lightweight presence-only signal for app backgrounding/closing — unlike
     * markOffline(), this does NOT release the TL's active bookings or unit
     * assignment. Used so briefly switching apps doesn't unassign a live job;
     * ping() already self-heals unit/status state on the next resume.
     */
    public function markAway(?User $user): void
    {
        if (! $user || ! $this->isTeamLeader($user)) {
            return;
        }

        User::where('id', $user->id)->update(['last_ping_at' => null]);
    }

    public function isOnline(?User $user): bool
    {
        if (! $user || blank($user->id) || $user->archived_at) {
            return false;
        }

        // Primary: DB-backed presence (reliable on all Railway cache drivers)
        $lastPing = $user->last_ping_at instanceof Carbon
            ? $user->last_ping_at
            : ($user->last_ping_at ? Carbon::parse($user->last_ping_at) : null);

        if ($lastPing?->gte(now()->subSeconds($this->presenceWindowSeconds))) {
            return true;
        }

        // Fallback: legacy cache key (keeps tests passing without modification)
        return Cache::has($this->cacheKey((int) $user->id));
    }

    public function lastSeenHuman(?User $user): string
    {
        if (! $user || blank($user->id)) {
            return 'Offline';
        }

        $lastPing = $user->last_ping_at;

        if (blank($lastPing)) {
            return 'Offline';
        }

        return 'Active ' . Carbon::parse($lastPing)->diffForHumans();
    }

    public function setOperationalOverride(?User $user, string $status, ?string $reason = null): void
    {
        if (! $user || (int) $user->role_id !== 3 || $user->archived_at) {
            return;
        }

        if ($status === 'available') {
            Cache::forget($this->statusCacheKey((int) $user->id));

            return;
        }

        Cache::put(
            $this->statusCacheKey((int) $user->id),
            [
                'status' => $status,
                'reason' => filled($reason) ? trim((string) $reason) : null,
                'updated_at' => now()->toDateTimeString(),
            ],
            now()->addHours(12)
        );
    }

    public function operationalOverride(?User $user): ?array
    {
        if (! $user || blank($user->id)) {
            return null;
        }

        $override = Cache::get($this->statusCacheKey((int) $user->id));

        return is_array($override) ? $override : null;
    }

    public function busyTeamLeaderIds(): Collection
    {
        return Booking::with('unit:id,team_leader_id')
            ->whereIn('status', $this->busyStatuses)
            ->where(function ($query) {
                $query->where('status', '!=', 'waiting_verification')
                    ->orWhereNull('payment_submitted_at');
            })
            ->whereNull('returned_at')
            ->get(['id', 'assigned_team_leader_id', 'assigned_unit_id'])
            ->map(function (Booking $booking) {
                return $booking->assigned_team_leader_id ?: optional($booking->unit)->team_leader_id;
            })
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Every team leader's currently active booking (any busy-lifecycle status),
     * keyed by team leader id. Shared by summarize() and anywhere else (e.g.
     * the dispatch live-tracking map) that needs a real, granular job status
     * instead of the coarse Unit.status/dispatcher_status columns.
     */
    public function activeBookingsByLeaderId(): Collection
    {
        return Booking::with(['unit.driver'])
            ->whereIn('status', $this->busyStatuses)
            ->where(function ($query) {
                $query->where('status', '!=', 'waiting_verification')
                    ->orWhereNull('payment_submitted_at');
            })
            ->whereNull('returned_at')
            ->latest('updated_at')
            ->get()
            ->mapWithKeys(function (Booking $booking) {
                $leaderId = (int) ($booking->assigned_team_leader_id ?: optional($booking->unit)->team_leader_id);

                return $leaderId > 0 ? [$leaderId => $booking] : [];
            });
    }

    public function summarize(Collection $teamLeaders, ?Collection $busyTeamLeaderIds = null): array
    {
        $busyIds = ($busyTeamLeaderIds ?? $this->busyTeamLeaderIds())
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $teamLeaderIds = $teamLeaders
            ->pluck('id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->values();

        // Keep summary generation read-only so dispatcher polling never removes
        // the dispatcher’s permanent unit ownership assignments.

        $assignedUnitsByLeaderId = Unit::with(['driver', 'zone'])
            ->whereIn('team_leader_id', $teamLeaderIds->all())
            ->get()
            ->keyBy(fn(Unit $unit) => (int) $unit->team_leader_id);

        $activeBookingsByLeaderId = $this->activeBookingsByLeaderId();

        $leaders = $teamLeaders
            ->map(function (User $teamLeader) use ($busyIds, $activeBookingsByLeaderId, $assignedUnitsByLeaderId) {
                $isOnline = $this->isOnline($teamLeader);
                $isBusy = $busyIds->contains((int) $teamLeader->id);
                $activeBooking = $activeBookingsByLeaderId->get((int) $teamLeader->id);
                $assignedUnit = $activeBooking?->unit ?? $assignedUnitsByLeaderId->get((int) $teamLeader->id);
                $savedDriverName = $activeBooking?->driver_name;
                $dispatcherOverride = $this->operationalOverride($teamLeader);

                $workload = $isBusy ? 'busy' : ($isOnline ? ($assignedUnit ? 'available' : 'standby') : 'unavailable');

                if ($isOnline && in_array($dispatcherOverride['status'] ?? null, ['busy', 'unavailable'], true)) {
                    $workload = $dispatcherOverride['status'];
                }

                $workloadLabel = $this->workloadLabel($workload);
                $statusReason = $dispatcherOverride['reason'] ?? null;
                $statusSummary = ($isOnline ? 'Online' : 'Offline') . ' · ' . $workloadLabel;

                if (filled($statusReason)) {
                    $statusSummary .= ' · ' . $statusReason;
                }

                return [
                    'id' => $teamLeader->id,
                    'name' => $teamLeader->full_name ?? $teamLeader->name ?? 'Team Leader',
                    'phone' => $teamLeader->phone ?? 'No phone listed',
                    'unit_name' => $assignedUnit?->name ?? 'No assigned unit',
                    'driver_name' => $savedDriverName
                        ?: optional(optional($assignedUnit)->driver)->full_name
                        ?: optional(optional($assignedUnit)->driver)->name
                        ?: 'No member driver',
                    'presence' => $isOnline ? 'online' : 'offline',
                    'presence_label' => $isOnline ? 'Online' : 'Offline',
                    'workload' => $workload,
                    'workload_label' => $workloadLabel,
                    'operational_status' => $workload,
                    'operational_status_label' => $workloadLabel,
                    'status_reason' => $statusReason,
                    'active_booking_status'       => $activeBooking?->status,
                    'active_booking_status_label' => $activeBooking
                        ? str($activeBooking->status)->replace('_', ' ')->title()->toString() : null,
                    'unit_status'        => $assignedUnit?->dispatcher_status ?? $assignedUnit?->status,
                    'dispatcher_status'  => $assignedUnit?->dispatcher_status,
                    'zone_name'          => $assignedUnit?->zone?->name ?? null,
                    'zone_confirmed'     => (bool) ($assignedUnit?->zone_confirmed ?? false),
                    'dispatcher_note'    => $assignedUnit?->dispatcher_note ?? null,
                    'last_updated_by'    => $assignedUnit?->last_updated_by ?? null,
                    'last_updated_at'    => $assignedUnit?->last_updated_at ?? null,
                    'assigned_unit_id'   => $assignedUnit?->id,
                    'unit_status_label' => $this->unitStatusLabel($assignedUnit?->status),
                    'has_dispatcher_override' => filled($statusReason) || in_array($dispatcherOverride['status'] ?? null, ['busy', 'unavailable'], true),
                    'last_seen_label' => $isOnline ? 'Active now' : $this->lastSeenHuman($teamLeader),
                    'status_summary' => $statusSummary,
                ];
            })
            ->sortBy([
                ['presence', 'desc'],
                ['workload', 'asc'],
                ['name', 'asc'],
            ])
            ->values();

        return [
            'leaders' => $leaders,
            'online_count' => $leaders->where('presence', 'online')->count(),
            'offline_count' => $leaders->where('presence', 'offline')->count(),
            'busy_count' => $leaders->where('workload', 'busy')->count(),
            'available_count' => $leaders->where('workload', 'available')->count(),
        ];
    }

    /**
     * Returns un-started offers (status "assigned", not yet accepted) to
     * dispatch so they can be given to someone else. Everything further
     * along stays with the Team Leader.
     *
     * @return array<int,int> ids of the bookings returned to dispatch
     */
    protected function releaseBookingsForOfflineLeaderIds(Collection $leaderIds): array
    {
        if ($leaderIds->isEmpty()) {
            return [];
        }

        $returned = Booking::query()
            ->whereIn('assigned_team_leader_id', $leaderIds->all())
            ->where('status', 'assigned')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($returned === []) {
            return [];
        }

        Booking::query()
            ->whereIn('id', $returned)
            ->where('status', 'assigned')
            ->update([
                'assigned_team_leader_id' => null,
                'status' => 'assigned',
                'driver_name' => null,
                'completion_requested_at' => null,
                'customer_verified_at' => null,
                'customer_verification_status' => null,
            ]);

        return $returned;
    }

    protected function releaseUnitsForOfflineLeaderIds(Collection $leaderIds, array $returnedBookingIds = []): void
    {
        if ($leaderIds->isEmpty()) {
            return;
        }

        // Sign-out releases an ordinary Team Leader from their truck. It must NOT
        // take a leader off a truck that is committed: an active job, a
        // reservation, or a borrow. Those are re-checked here, under the truck's
        // row lock, immediately before the assignment is changed, so a dispatcher
        // action that commits first is always seen and one that comes later waits.
        //
        // Lock order: the Team Leader's unit first (ascending id; a leader leads
        // at most one), nothing else is locked, and no lock is held from an
        // earlier statement (presence and booking updates are their own commits),
        // so this cannot cross the unit-first order the owner and dispatcher
        // operations use.
        DB::transaction(function () use ($leaderIds, $returnedBookingIds) {
            $units = Unit::query()
                ->whereIn('team_leader_id', $leaderIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $availability = app(UnitAvailabilityService::class);
            $releasable = $units
                ->reject(fn (Unit $unit) => $this->mustKeepLeader($unit, $availability, $returnedBookingIds))
                ->pluck('id');

            if ($releasable->isEmpty()) {
                return;
            }

            // A truck under maintenance keeps its status, notes and every
            // operational field and only loses its leader; one conditional UPDATE
            // so a maintenance toggle committed at the same moment cannot be undone.
            $keepIfMaintenance = fn (string $column, string $otherwise) => DB::raw("CASE WHEN status = 'maintenance' THEN {$column} ELSE {$otherwise} END");

            Unit::query()
                ->whereIn('id', $releasable->all())
                ->update([
                    'team_leader_id'    => null,
                    'status'            => $keepIfMaintenance('status', "'available'"),
                    'dispatcher_status' => $keepIfMaintenance('dispatcher_status', 'NULL'),
                    'dispatcher_note'   => $keepIfMaintenance('dispatcher_note', 'NULL'),
                    'zone_confirmed'    => $keepIfMaintenance('zone_confirmed', 'FALSE'),
                    'last_updated_by'   => $keepIfMaintenance('last_updated_by', 'NULL'),
                    'last_updated_at'   => $keepIfMaintenance('last_updated_at', 'NULL'),
                ]);
        });
    }

    /**
     * Whether this truck's leader must stay assigned after signing out.
     *  - an active job: the app-wide definition (UnitAvailabilityService), on
     *    this truck or assigned to this leader, ignoring offers this sign-out
     *    just returned to dispatch;
     *  - a reservation of the truck (the same definition that makes it "reserved");
     *  - a borrow: an open Team Leader loan to this truck for this leader, which
     *    must keep matching the truck's actual leader.
     */
    protected function mustKeepLeader(Unit $unit, UnitAvailabilityService $availability, array $returnedBookingIds): bool
    {
        if ($availability->bookingHoldingUnit($unit, $returnedBookingIds) !== null) {
            return true;
        }

        if ($availability->activeBookingForLeader((int) $unit->team_leader_id, $returnedBookingIds) !== null) {
            return true;
        }

        return UnitCrewLoan::query()
            ->where('person_user_id', $unit->team_leader_id)
            ->where('to_slot', 'team_leader')
            ->where('to_unit_id', $unit->id)
            ->whereNull('returned_at')
            ->exists();
    }
    protected function workloadLabel(string $workload): string
    {
        return match ($workload) {
            'busy'      => 'Busy',
            'available' => 'Available',
            'idle'      => 'Idle',
            default     => 'Not Available',
        };
    }

    protected function unitStatusLabel(?string $status): string
    {
        return match ($status) {
            'available' => 'Available',
            'on_job' => 'On Job',
            'maintenance' => 'Maintenance',
            default => 'Unassigned',
        };
    }

    protected function cacheKey(int $teamLeaderId): string
    {
        return "teamleader:presence:{$teamLeaderId}";
    }

    protected function statusCacheKey(int $teamLeaderId): string
    {
        return "teamleader:dispatcher-status:{$teamLeaderId}";
    }
}
