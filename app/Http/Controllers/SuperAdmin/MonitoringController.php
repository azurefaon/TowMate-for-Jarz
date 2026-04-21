<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    protected TeamLeaderAvailabilityService $teamLeaderAvailability;

    public function __construct(TeamLeaderAvailabilityService $teamLeaderAvailability)
    {
        $this->teamLeaderAvailability = $teamLeaderAvailability;
    }

    protected array $activeStatuses = ['accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'];

    public function index(Request $request)
    {
        return view('superadmin.monitoring.index', $this->buildPayload($request));
    }

    public function live(Request $request): JsonResponse
    {
        return response()->json($this->buildPayload($request));
    }

    protected function buildPayload(Request $request): array
    {
        $filters = [
            'status' => (string) $request->input('status', ''),
            'search' => trim((string) $request->input('search', '')),
            'period' => (string) $request->input('period', 'today'),
        ];

        $recentBookingsQuery = Booking::query()
            ->with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
            ->latest('updated_at');

        if ($filters['status'] !== '') {
            $recentBookingsQuery->where('status', $filters['status']);
        }

        if ($filters['search'] !== '') {
            $search = $filters['search'];

            $recentBookingsQuery->where(function ($query) use ($search) {
                $query->where('booking_code', 'like', "%{$search}%")
                    ->orWhere('pickup_address', 'like', "%{$search}%")
                    ->orWhere('dropoff_address', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        if ($filters['period'] === 'today') {
            $recentBookingsQuery->whereDate('created_at', today());
        } elseif ($filters['period'] === 'week') {
            $recentBookingsQuery->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
        }

        $activeBookings = Booking::query()
            ->with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
            ->whereIn('status', $this->activeStatuses)
            ->latest('updated_at')
            ->take(8)
            ->get();

        $recentBookings = $recentBookingsQuery
            ->take(10)
            ->get();

        $teamLeaders = User::query()
            ->visibleToOperations()
            ->where('role_id', 3)
            ->with(['unit', 'unit.driver'])
            ->get();

        $busyTeamLeaderIds = $activeBookings
            ->map(function (Booking $booking) {
                return $booking->assigned_team_leader_id ?: optional($booking->unit)->team_leader_id;
            })
            ->filter()
            ->unique()
            ->values();

        $teamLeaderSummary = $this->teamLeaderAvailability->summarize($teamLeaders, $busyTeamLeaderIds);

        $leaderReturnCounts = Booking::query()
            ->selectRaw('returned_by_team_leader_id, COUNT(*) as total')
            ->whereNotNull('returned_by_team_leader_id')
            ->whereDate('returned_at', today())
            ->groupBy('returned_by_team_leader_id')
            ->pluck('total', 'returned_by_team_leader_id');

        $leaderCompletedCounts = Booking::query()
            ->selectRaw('assigned_team_leader_id, COUNT(*) as total')
            ->whereNotNull('assigned_team_leader_id')
            ->whereDate('completed_at', today())
            ->groupBy('assigned_team_leader_id')
            ->pluck('total', 'assigned_team_leader_id');

        $activeBookingByLeaderId = Booking::query()
            ->with('unit')
            ->whereIn('status', $this->activeStatuses)
            ->latest('updated_at')
            ->get()
            ->mapWithKeys(function (Booking $booking) {
                $leaderId = (int) ($booking->assigned_team_leader_id ?: optional($booking->unit)->team_leader_id);

                return $leaderId > 0 ? [$leaderId => $booking] : [];
            });

        $teamLeaderStatuses = $teamLeaderSummary['leaders']
            ->map(function (array $leader) use ($leaderReturnCounts, $leaderCompletedCounts, $activeBookingByLeaderId) {
                $activeBooking = $activeBookingByLeaderId->get((int) $leader['id']);

                $leader['active_job_code'] = $activeBooking?->job_code ?? 'No active booking';
                $leader['active_job_status'] = $activeBooking
                    ? str($activeBooking->status)->replace('_', ' ')->title()->toString()
                    : 'Awaiting dispatch';
                $leader['returns_today'] = (int) ($leaderReturnCounts[$leader['id']] ?? 0);
                $leader['completed_today'] = (int) ($leaderCompletedCounts[$leader['id']] ?? 0);
                $leader['schedule_note'] = $activeBooking?->schedule_window_label ?? 'Ready for new assignment';

                return $leader;
            })
            ->take(8)
            ->values();

        $dispatcherActionCounts = AuditLog::query()
            ->selectRaw('user_id, COUNT(*) as total')
            ->whereDate('created_at', today())
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $dispatcherLastAction = AuditLog::query()
            ->with('user')
            ->whereNotNull('user_id')
            ->latest('created_at')
            ->get()
            ->groupBy('user_id');

        $dispatcherQuoteCounts = Booking::query()
            ->selectRaw('created_by_admin_id, COUNT(*) as total')
            ->whereNotNull('created_by_admin_id')
            ->whereDate('quoted_at', today())
            ->groupBy('created_by_admin_id')
            ->pluck('total', 'created_by_admin_id');

        $dispatcherCreatedCounts = Booking::query()
            ->selectRaw('created_by_admin_id, COUNT(*) as total')
            ->whereNotNull('created_by_admin_id')
            ->whereDate('created_at', today())
            ->groupBy('created_by_admin_id')
            ->pluck('total', 'created_by_admin_id');

        $dispatchers = User::query()
            ->visibleToOperations()
            ->where('role_id', 2)
            ->get()
            ->map(function (User $dispatcher) use ($dispatcherActionCounts, $dispatcherLastAction, $dispatcherQuoteCounts, $dispatcherCreatedCounts) {
                $lastAction = optional($dispatcherLastAction->get($dispatcher->id))->first();
                $actionsToday = (int) ($dispatcherActionCounts[$dispatcher->id] ?? 0);

                return [
                    'name' => $dispatcher->full_name ?: $dispatcher->name,
                    'actions_today' => $actionsToday,
                    'quotes_today' => (int) ($dispatcherQuoteCounts[$dispatcher->id] ?? 0),
                    'bookings_today' => (int) ($dispatcherCreatedCounts[$dispatcher->id] ?? 0),
                    'workload_label' => $actionsToday >= 10 ? 'High activity' : ($actionsToday > 0 ? 'Active' : 'Idle'),
                    'last_action' => $lastAction?->action ?? 'No recent action',
                    'last_seen' => $lastAction?->created_at?->diffForHumans() ?? 'No activity yet',
                ];
            })
            ->sortByDesc('actions_today')
            ->take(8)
            ->values();

        $recentActivities = AuditLog::query()
            ->with('user')
            ->whereHas('user', function ($query) {
                $query->visibleToOperations()->whereIn('role_id', [2, 3]);
            })
            ->latest('created_at')
            ->take(12)
            ->get();

        $returnedTasksCount = Booking::query()
            ->whereNotNull('returned_at')
            ->whereIn('status', ['confirmed', 'accepted', 'assigned'])
            ->count();

        $scheduledTodayCount = Booking::query()
            ->whereNotNull('scheduled_for')
            ->whereDate('scheduled_for', today())
            ->count();

        $dueSoonScheduledCount = Booking::query()
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [now(), now()->copy()->addHours(2)])
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->count();

        $availableUnitsCount = Unit::query()->where('status', 'available')->count();
        $onJobUnitsCount = Unit::query()->where('status', 'on_job')->count();
        $notAvailableUnitsCount = Unit::query()->where('status', 'maintenance')->count();

        $flaggedCustomers = Customer::query()
            ->whereIn('risk_level', ['watchlist', 'blacklisted'])
            ->latest('updated_at')
            ->take(8)
            ->get()
            ->map(function (Customer $customer) {
                return [
                    'name' => $customer->full_name,
                    'risk_level' => strtolower((string) ($customer->risk_level ?? 'watchlist')),
                    'risk_label' => $customer->risk_status_label,
                    'phone' => $customer->phone ?: 'No phone',
                    'reason' => $customer->risk_reason ?: 'Flagged by operations review.',
                    'updated_at' => $customer->blacklisted_at?->diffForHumans() ?? $customer->updated_at?->diffForHumans() ?? 'Recently updated',
                ];
            })
            ->values();

        $unassignedBookingsCount = Booking::query()
            ->whereIn('status', ['requested', 'confirmed', 'accepted', 'assigned'])
            ->whereNull('assigned_unit_id')
            ->count();

        $offlineLeadersWithUnitsCount = collect($teamLeaderSummary['leaders'])
            ->filter(fn(array $leader) => $leader['presence'] === 'offline' && $leader['unit_name'] !== 'No assigned unit')
            ->count();

        $attentionAlerts = collect([
            $returnedTasksCount > 0 ? [
                'level' => 'danger',
                'title' => 'Returned tasks pending reassignment',
                'message' => $returnedTasksCount . ' returned booking(s) still need dispatch review.',
            ] : null,
            $unassignedBookingsCount > 0 ? [
                'level' => 'warning',
                'title' => 'Bookings still need a unit',
                'message' => $unassignedBookingsCount . ' active booking(s) are still waiting for unit assignment.',
            ] : null,
            $dueSoonScheduledCount > 0 ? [
                'level' => 'warning',
                'title' => 'Scheduled jobs are due soon',
                'message' => $dueSoonScheduledCount . ' scheduled booking(s) are approaching their dispatch time.',
            ] : null,
            $offlineLeadersWithUnitsCount > 0 ? [
                'level' => 'danger',
                'title' => 'Offline leaders still have units assigned',
                'message' => $offlineLeadersWithUnitsCount . ' team leader(s) are offline while still linked to a unit.',
            ] : null,
            $notAvailableUnitsCount > 0 ? [
                'level' => 'info',
                'title' => 'Units temporarily unavailable',
                'message' => $notAvailableUnitsCount . ' unit(s) are currently marked not available.',
            ] : null,
            $flaggedCustomers->where('risk_level', 'blacklisted')->count() > 0 ? [
                'level' => 'danger',
                'title' => 'Blacklisted customers on record',
                'message' => $flaggedCustomers->where('risk_level', 'blacklisted')->count() . ' customer account(s) are restricted from new bookings.',
            ] : null,
        ])->filter()->values();

        if ($attentionAlerts->isEmpty()) {
            $attentionAlerts = collect([[
                'level' => 'success',
                'title' => 'No urgent blockers',
                'message' => 'Dispatch, leaders, bookings, and units are all currently stable.',
            ]]);
        }

        $bookingPipeline = collect([
            ['label' => 'Requested', 'count' => Booking::query()->where('status', 'requested')->count(), 'tone' => 'neutral'],
            ['label' => 'Quoted', 'count' => Booking::query()->whereIn('status', ['reviewed', 'quotation_sent'])->count(), 'tone' => 'warning'],
            ['label' => 'Assigned', 'count' => Booking::query()->whereIn('status', ['confirmed', 'accepted', 'assigned'])->count(), 'tone' => 'info'],
            ['label' => 'In Progress', 'count' => Booking::query()->whereIn('status', ['on_the_way', 'in_progress', 'on_job'])->count(), 'tone' => 'active'],
            ['label' => 'Waiting Verification', 'count' => Booking::query()->where('status', 'waiting_verification')->count(), 'tone' => 'warning'],
            ['label' => 'Completed Today', 'count' => Booking::query()->where('status', 'completed')->whereDate('updated_at', today())->count(), 'tone' => 'success'],
            ['label' => 'Returned', 'count' => $returnedTasksCount, 'tone' => 'danger'],
            ['label' => 'Scheduled', 'count' => $scheduledTodayCount, 'tone' => 'info'],
        ]);

        $bookingsByUnitId = Booking::query()
            ->with(['customer', 'assignedTeamLeader'])
            ->whereNotNull('assigned_unit_id')
            ->where(function ($query) {
                $query->whereIn('status', $this->activeStatuses)
                    ->orWhere(function ($scheduleQuery) {
                        $scheduleQuery->whereNotNull('scheduled_for')
                            ->where('scheduled_for', '>', now())
                            ->whereNotIn('status', ['completed', 'cancelled', 'rejected']);
                    });
            })
            ->latest('updated_at')
            ->get()
            ->keyBy('assigned_unit_id');

        $unitsMonitor = Unit::query()
            ->with(['truckType', 'driver', 'teamLeader'])
            ->latest('updated_at')
            ->take(10)
            ->get()
            ->map(function (Unit $unit) use ($bookingsByUnitId) {
                $booking = $bookingsByUnitId->get($unit->id);
                $statusLabel = match ($unit->status) {
                    'available' => 'Available',
                    'on_job' => 'On Job',
                    'maintenance' => 'Not Available',
                    default => str($unit->status)->replace('_', ' ')->title()->toString(),
                };

                return [
                    'name' => $unit->name,
                    'plate_number' => $unit->plate_number,
                    'truck_type' => optional($unit->truckType)->name ?? 'N/A',
                    'team_leader' => optional($unit->teamLeader)->full_name ?: (optional($unit->teamLeader)->name ?: 'Unassigned'),
                    'driver' => optional($unit->driver)->full_name ?: (optional($unit->driver)->name ?: 'No member driver'),
                    'status' => $unit->status,
                    'status_label' => $statusLabel,
                    'booking_code' => $booking?->job_code ?? 'No linked booking',
                    'schedule_label' => $booking?->schedule_window_label ?? ($unit->status === 'available' ? 'Ready for dispatch' : 'Temporarily unavailable'),
                    'updated_at' => $unit->updated_at?->diffForHumans() ?? 'Recently updated',
                ];
            });

        $monitoringStats = [
            'active_jobs' => Booking::whereIn('status', $this->activeStatuses)->count(),
            'pending_requests' => Booking::where('status', 'requested')->count(),
            'completed_today' => Booking::where('status', 'completed')->whereDate('updated_at', today())->count(),
            'online_team_leaders' => $teamLeaderSummary['online_count'],
            'busy_team_leaders' => $teamLeaderSummary['busy_count'],
            'dispatchers' => User::visibleToOperations()->where('role_id', 2)->count(),
            'scheduled_today' => $scheduledTodayCount,
            'returned_tasks' => $returnedTasksCount,
            'available_units' => $availableUnitsCount,
            'units_on_job' => $onJobUnitsCount,
            'not_available_units' => $notAvailableUnitsCount,
            'watchlist_customers' => Customer::query()->where('risk_level', 'watchlist')->count(),
            'blacklisted_customers' => Customer::query()->where('risk_level', 'blacklisted')->count(),
            'total_bookings' => Booking::count(),
        ];

        return compact(
            'filters',
            'monitoringStats',
            'attentionAlerts',
            'bookingPipeline',
            'activeBookings',
            'recentBookings',
            'teamLeaderStatuses',
            'dispatchers',
            'unitsMonitor',
            'flaggedCustomers',
            'recentActivities'
        );
    }
}
