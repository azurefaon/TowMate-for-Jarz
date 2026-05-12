<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TeamLeaderAvailabilityService
{
    protected int $presenceWindowSeconds = 300;

    protected array $busyStatuses = ['assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'];

    public function markOnline(?User $user): void
    {
        if (! $user || (int) $user->role_id !== 3 || $user->archived_at) {
            return;
        }

        Cache::put(
            $this->cacheKey($user->id),
            now()->timestamp,
            now()->addSeconds($this->presenceWindowSeconds)
        );

        // Clear any dispatcher override so the TL is available when they log back in.
        Cache::forget($this->statusCacheKey((int) $user->id));
    }

    public function markOffline(?User $user): void
    {
        if (! $user || (int) $user->role_id !== 3) {
            return;
        }

        Cache::forget($this->cacheKey($user->id));

        $leaderIds = collect([(int) $user->id]);

        $this->releaseBookingsForOfflineLeaderIds($leaderIds);
        $this->releaseUnitsForOfflineLeaderIds($leaderIds);
    }

    public function isOnline(?User $user): bool
    {
        if (! $user || blank($user->id) || $user->archived_at) {
            return false;
        }

        return Cache::has($this->cacheKey($user->id));
    }

    public function lastSeenHuman(?User $user): string
    {
        if (! $user || blank($user->id)) {
            return 'Offline';
        }

        $lastSeen = Cache::get($this->cacheKey($user->id));

        if (blank($lastSeen)) {
            return 'Offline';
        }

        return 'Active ' . Carbon::createFromTimestamp((int) $lastSeen)->diffForHumans();
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

        $offlineLeaderIds = $teamLeaders
            ->filter(fn(User $teamLeader) => ! $this->isOnline($teamLeader))
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

        $activeBookingsByLeaderId = Booking::with(['unit.driver'])
            ->whereIn('status', $this->busyStatuses)
            ->whereNull('returned_at')
            ->latest('updated_at')
            ->get()
            ->mapWithKeys(function (Booking $booking) {
                $leaderId = (int) ($booking->assigned_team_leader_id ?: optional($booking->unit)->team_leader_id);

                return $leaderId > 0 ? [$leaderId => $booking] : [];
            });

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

    protected function releaseBookingsForOfflineLeaderIds(Collection $leaderIds): void
    {
        if ($leaderIds->isEmpty()) {
            return;
        }

        // Only release bookings that have NOT been started yet.
        // Jobs already on-the-way, in-progress, or further along must stay
        // with the team leader so they are not lost from the system.
        Booking::query()
            ->whereIn('assigned_team_leader_id', $leaderIds->all())
            ->where('status', 'assigned')
            ->update([
                'assigned_team_leader_id' => null,
                'status' => 'assigned',
                'driver_name' => null,
                'completion_requested_at' => null,
                'customer_verified_at' => null,
                'customer_verification_status' => null,
            ]);
    }

    protected function releaseUnitsForOfflineLeaderIds(Collection $leaderIds): void
    {
        if ($leaderIds->isEmpty()) {
            return;
        }

        // Keep the unit assigned to any TL who still has an active job in progress.
        // Only release units for TLs with no running job.
        $activeJobStatuses = ['on_the_way', 'in_progress', 'waiting_verification', 'payment_pending', 'payment_submitted'];

        $leadersWithActiveJobs = Booking::query()
            ->whereIn('assigned_team_leader_id', $leaderIds->all())
            ->whereIn('status', $activeJobStatuses)
            ->pluck('assigned_team_leader_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        $safeToRelease = $leaderIds->diff($leadersWithActiveJobs)->values();

        if ($safeToRelease->isEmpty()) {
            return;
        }

        Unit::query()
            ->whereIn('team_leader_id', $safeToRelease->all())
            ->update([
                'team_leader_id'    => null,
                'status'            => 'available',
                'dispatcher_status' => null,
                'dispatcher_note'   => null,
                'zone_confirmed'    => false,
                'last_updated_by'   => null,
                'last_updated_at'   => null,
            ]);
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
