<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __construct(protected TeamLeaderAvailabilityService $teamLeaderAvailability) {}

    protected array $activeStatuses = ['assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'];

    public function index(Request $request)
    {
        return view('admin-dashboard.pages.dashboard', $this->buildPayload());
    }

    public function liveOverview(): JsonResponse
    {
        return response()->json($this->buildPayload());
    }

    protected function buildPayload(): array
    {
        $teamLeaders = User::where('role_id', 3)
            ->with(['unit', 'unit.driver'])
            ->get();

        $activeBookings = Booking::with(['customer', 'truckType', 'unit.driver', 'unit.teamLeader', 'assignedTeamLeader'])
            ->whereIn('status', $this->activeStatuses)
            ->latest('updated_at')
            ->get();

        $busyTeamLeaderIds = $activeBookings
            ->map(function (Booking $booking) {
                return $booking->assigned_team_leader_id ?: optional($booking->unit)->team_leader_id;
            })
            ->filter()
            ->unique()
            ->values();

        $teamLeaderSummary = $this->teamLeaderAvailability->summarize($teamLeaders, $busyTeamLeaderIds);
        $teamLeaderStatuses = $teamLeaderSummary['leaders'];
        $teamLeaderStatusMap = $teamLeaderStatuses->keyBy('id');

        $busyTeamLeadersCount = $teamLeaderSummary['busy_count'];
        $available = $teamLeaderSummary['available_count'];
        $onlineTeamLeadersCount = $teamLeaderSummary['online_count'];
        $offlineTeamLeadersCount = $teamLeaderSummary['offline_count'];

        $pendingRequests = Booking::where('status', 'requested')->count();
        $activeJobs = $activeBookings->count();
        $delayed = Booking::where('status', 'delayed')->count();
        $completedToday = Booking::where('status', 'completed')
            ->whereDate('updated_at', today())
            ->count();

        $dispatchReadyCount = $teamLeaderStatuses
            ->filter(fn(array $leader) => $leader['presence'] === 'online' && $leader['workload'] === 'available')
            ->count();

        $fleetHealth = $teamLeaders->count() > 0
            ? round(($dispatchReadyCount / $teamLeaders->count()) * 100)
            : 100;

        $incomingRequests = Booking::with(['customer', 'truckType'])
            ->where('status', 'requested')
            ->latest('updated_at')
            ->take(6)
            ->get()
            ->map(fn(Booking $booking) => [
                'booking_code' => $booking->job_code,
                'customer_name' => $booking->customer->full_name ?? 'New request',
                'truck_type' => $booking->truckType->name ?? 'Tow request',
                'pickup_address' => Str::limit($booking->pickup_address ?? 'Unknown pickup', 28),
                'dropoff_address' => Str::limit($booking->dropoff_address ?? 'Unknown drop-off', 26),
                'created_at_human' => optional($booking->created_at)->diffForHumans() ?? 'Just now',
            ])
            ->values();

        $currentActivities = $activeBookings
            ->take(6)
            ->map(function (Booking $booking) use ($teamLeaderStatusMap) {
                $teamLeaderId = $booking->assigned_team_leader_id ?: optional($booking->unit)->team_leader_id;
                $leaderSync = $teamLeaderStatusMap->get((int) $teamLeaderId, []);
                $teamLeader = optional(optional($booking->unit)->teamLeader)->name
                    ?? optional($booking->assignedTeamLeader)->name
                    ?? 'Awaiting crew';

                $driver = $booking->driver_name
                    ?? optional(optional($booking->unit)->driver)->name
                    ?? 'Driver pending';

                return [
                    'booking_code' => $booking->job_code,
                    'status' => str($booking->status)->replace('_', ' ')->title()->toString(),
                    'customer_name' => $booking->customer->full_name ?? 'Customer',
                    'unit_name' => $booking->unit->name ?? 'Unit pending',
                    'unit_plate' => $booking->unit->plate_number ?? 'Plate pending',
                    'driver_name' => $driver,
                    'team_leader_name' => $teamLeader,
                    'team_leader_status_summary' => $leaderSync['status_summary'] ?? 'Offline · Busy',
                    'updated_at_human' => optional($booking->updated_at)->diffForHumans() ?? 'Just now',
                ];
            })
            ->values();

        $chartData = [
            'completed' => $completedToday,
            'assigned' => $activeJobs,
            'pending' => $pendingRequests,
        ];

        return compact(
            'teamLeaders',
            'teamLeaderStatuses',
            'available',
            'activeJobs',
            'delayed',
            'fleetHealth',
            'incomingRequests',
            'currentActivities',
            'busyTeamLeadersCount',
            'onlineTeamLeadersCount',
            'offlineTeamLeadersCount',
            'pendingRequests',
            'completedToday',
            'chartData'
        );
    }
}
