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
    protected int $presenceWindowSeconds = 120;

    protected array $busyStatuses = ['assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'];

    public function markOnline(?User $user): void
    {
        if (! $user || (int) $user->role_id !== 3) {
            return;
        }

        Cache::put(
            $this->cacheKey($user->id),
            now()->timestamp,
            now()->addSeconds($this->presenceWindowSeconds)
        );
    }

    public function markOffline(?User $user): void
    {
        if (! $user || (int) $user->role_id !== 3) {
            return;
        }

        Cache::forget($this->cacheKey($user->id));

        Unit::query()
            ->where('team_leader_id', $user->id)
            ->update(['team_leader_id' => null]);
    }

    public function isOnline(?User $user): bool
    {
        if (! $user || blank($user->id)) {
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

    public function busyTeamLeaderIds(): Collection
    {
        return Booking::with('unit:id,team_leader_id')
            ->whereIn('status', $this->busyStatuses)
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

        if ($offlineLeaderIds->isNotEmpty()) {
            Unit::query()
                ->whereIn('team_leader_id', $offlineLeaderIds->all())
                ->update(['team_leader_id' => null]);
        }

        $assignedUnitsByLeaderId = Unit::with('driver')
            ->whereIn('team_leader_id', $teamLeaderIds->all())
            ->get()
            ->keyBy(fn(Unit $unit) => (int) $unit->team_leader_id);

        $activeBookingsByLeaderId = Booking::with(['unit.driver'])
            ->whereIn('status', $this->busyStatuses)
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
                $assignedUnit = $isOnline
                    ? ($activeBooking?->unit ?? $assignedUnitsByLeaderId->get((int) $teamLeader->id))
                    : null;
                $savedDriverName = $isOnline ? $activeBooking?->driver_name : null;

                $workload = $isBusy ? 'busy' : ($isOnline ? 'available' : 'unavailable');
                $workloadLabel = $isBusy ? 'Busy' : ($isOnline ? 'Available' : 'Not Available');

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
                    'last_seen_label' => $isOnline ? 'Active now' : $this->lastSeenHuman($teamLeader),
                    'status_summary' => ($isOnline ? 'Online' : 'Offline') . ' · ' . $workloadLabel,
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

    protected function cacheKey(int $teamLeaderId): string
    {
        return "teamleader:presence:{$teamLeaderId}";
    }
}
