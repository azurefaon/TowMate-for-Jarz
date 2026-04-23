<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ControlCenterService
{
    protected array $activeStatuses = ['accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'];

    public function __construct(protected TeamLeaderAvailabilityService $teamLeaderAvailability) {}

    public function buildPayload(User $user, array $filters = []): array
    {
        $filters = [
            'status' => trim((string) Arr::get($filters, 'status', '')),
            'search' => trim((string) Arr::get($filters, 'search', '')),
            'period' => trim((string) Arr::get($filters, 'period', 'today')),
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
            ->with(['customer', 'truckType', 'unit.driver', 'unit.teamLeader', 'assignedTeamLeader'])
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
        $teamLeaderStatuses = $teamLeaderSummary['leaders'];
        $teamLeaderStatusMap = $teamLeaderStatuses->keyBy('id');

        $dispatchers = User::query()
            ->visibleToOperations()
            ->where('role_id', 2)
            ->get();

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

        $dispatchers = $dispatchers
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

        $teamLeaderStatuses = $teamLeaderStatuses
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

        $recentActivities = AuditLog::query()
            ->with('user')
            ->whereHas('user', function ($query) {
                $query->visibleToOperations()->whereIn('role_id', [2, 3]);
            })
            ->latest('created_at')
            ->take(12)
            ->get()
            ->map(function (AuditLog $log) {
                return [
                    'actor' => $log->user->full_name ?? $log->user->name ?? 'System',
                    'action' => str((string) $log->action)->replace('_', ' ')->title()->toString(),
                    'description' => $log->description ?? 'Operational event recorded.',
                    'time' => $log->created_at?->diffForHumans() ?? 'Just now',
                ];
            })
            ->values();

        $returnedTasksCount = Booking::query()
            ->whereNotNull('returned_at')
            ->whereIn('status', ['confirmed', 'accepted', 'assigned'])
            ->count();

        $scheduledOverviewBookings = Booking::with(['customer', 'truckType'])
            ->whereNotIn('status', array_merge($this->activeStatuses, ['completed', 'cancelled', 'rejected']))
            ->latest('updated_at')
            ->get()
            ->filter(fn(Booking $booking) => $booking->is_scheduled)
            ->sortBy(fn(Booking $booking) => $booking->scheduled_for?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        $dueNowScheduledCount = $scheduledOverviewBookings
            ->filter(fn(Booking $booking) => $booking->is_due_for_dispatch)
            ->count();

        $scheduledTodayCount = $scheduledOverviewBookings
            ->filter(fn(Booking $booking) => $booking->scheduled_for?->isToday())
            ->count();

        $upcomingScheduledCount = $scheduledOverviewBookings
            ->filter(fn(Booking $booking) => $booking->scheduled_for?->isFuture())
            ->count();

        $scheduleOverview = $scheduledOverviewBookings
            ->take(6)
            ->map(function (Booking $booking) {
                $tone = $booking->is_due_for_dispatch
                    ? 'due-now'
                    : ($booking->scheduled_for?->isToday() ? 'today' : 'upcoming');

                return [
                    'booking_code' => $booking->job_code,
                    'customer_name' => $booking->customer->full_name ?? 'Customer',
                    'truck_type' => $booking->truckType->name ?? 'Tow request',
                    'pickup_address' => Str::limit($booking->pickup_address ?? 'Unknown pickup', 48),
                    'dropoff_address' => Str::limit($booking->dropoff_address ?? 'Unknown drop-off', 44),
                    'schedule_window_label' => $booking->schedule_window_label,
                    'status' => str($booking->status)->replace('_', ' ')->title()->toString(),
                    'tone' => $tone,
                ];
            })
            ->values();

        $availableUnitsCount = Unit::query()->where('status', 'available')->count();
        $onJobUnitsCount = Unit::query()->where('status', 'on_job')->count();
        $notAvailableUnitsCount = Unit::query()->where('status', 'maintenance')->count();
        $todayBookings = Booking::query()->whereDate('created_at', today())->count();
        $weekBookings = Booking::query()->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
        $completedToday = Booking::query()->where('status', 'completed')->whereDate('updated_at', today())->count();
        $pendingRequests = Booking::query()->where('status', 'requested')->count();
        $totalRevenue = (float) Booking::query()->where('status', 'completed')->sum('final_total');
        $weekRevenue = (float) Booking::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('final_total');

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
            })
            ->values();

        $offlineLeadersWithUnitsCount = collect($teamLeaderSummary['leaders'])
            ->filter(fn(array $leader) => $leader['presence'] === 'offline' && $leader['unit_name'] !== 'No assigned unit')
            ->count();

        $dueSoonScheduledCount = Booking::query()
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [now(), now()->copy()->addHours(2)])
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->count();

        $unassignedBookingsCount = Booking::query()
            ->whereIn('status', ['requested', 'confirmed', 'accepted', 'assigned'])
            ->whereNull('assigned_unit_id')
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
            ['label' => 'Completed Today', 'count' => $completedToday, 'tone' => 'success'],
            ['label' => 'Returned', 'count' => $returnedTasksCount, 'tone' => 'danger'],
            ['label' => 'Scheduled', 'count' => $scheduledTodayCount, 'tone' => 'info'],
        ])->values();

        $summaryCards = [
            [
                'label' => 'Live Jobs',
                'value' => $activeBookings->count(),
                'detail' => 'Bookings currently in field operations',
                'tone' => 'ink',
                'icon' => 'truck',
            ],
            [
                'label' => 'Pending Requests',
                'value' => $pendingRequests,
                'detail' => 'Waiting for dispatch review',
                'tone' => 'amber',
                'icon' => 'inbox',
            ],
            [
                'label' => 'Bookings This Week',
                'value' => $weekBookings,
                'detail' => 'Created since start of week',
                'tone' => 'sky',
                'icon' => 'calendar-range',
            ],
            [
                'label' => 'Revenue Tracked',
                'value' => 'P' . number_format($totalRevenue, 2),
                'detail' => 'Completed-booking revenue total',
                'tone' => 'mint',
                'icon' => 'banknote',
            ],
            [
                'label' => 'Revenue This Week',
                'value' => 'P' . number_format($weekRevenue, 2),
                'detail' => 'Completed revenue for current week',
                'tone' => 'rose',
                'icon' => 'wallet',
            ],
            [
                'label' => 'Ready Units',
                'value' => $availableUnitsCount,
                'detail' => $onJobUnitsCount . ' on job · ' . $notAvailableUnitsCount . ' unavailable',
                'tone' => 'gold',
                'icon' => 'shield-check',
            ],
            [
                'label' => 'Online Leaders',
                'value' => $teamLeaderSummary['online_count'],
                'detail' => $teamLeaderSummary['busy_count'] . ' busy · ' . $teamLeaderSummary['available_count'] . ' ready',
                'tone' => 'violet',
                'icon' => 'users-round',
            ],
            [
                'label' => 'Flagged Customers',
                'value' => $flaggedCustomers->count(),
                'detail' => 'Watchlist and blacklisted accounts',
                'tone' => 'danger',
                'icon' => 'shield-alert',
            ],
        ];


        $governanceSummary = [];
        if ((int) $user->role_id === 1) {
            $governanceSummary = [
                [
                    'label' => 'Archived Users',
                    'value' => User::query()->whereNotNull('archived_at')->count(),
                    'description' => 'Users archived for compliance or inactivity.',
                    'tone' => 'info',
                    'url' => route('superadmin.users.archived'),
                ],
                [
                    'label' => 'Completed Bookings',
                    'value' => Booking::query()->where('status', 'completed')->count(),
                    'description' => 'Total completed jobs in the system.',
                    'tone' => 'success',
                ],
                [
                    'label' => 'Cancelled Bookings',
                    'value' => Booking::query()->where('status', 'cancelled')->count(),
                    'description' => 'Bookings cancelled by customer or admin.',
                    'tone' => 'danger',
                ],
                [
                    'label' => 'Data Retention (days)',
                    'value' => 14,
                    'description' => 'Current data retention policy.',
                    'tone' => 'warning',
                ],
            ];
        }

        return [
            'filters' => $filters,
            'is_super_admin' => (int) $user->role_id === 1,
            'live_url' => route('control-center.live'),
            'summary_cards' => $summaryCards,
            'attention_alerts' => $attentionAlerts->values(),
            'booking_pipeline' => $bookingPipeline,
            'active_bookings' => $activeBookings->map(fn(Booking $booking) => $this->transformBookingCard($booking, $teamLeaderStatusMap))->values(),
            'recent_bookings' => $recentBookings->map(fn(Booking $booking) => $this->transformRecentBooking($booking))->values(),
            'schedule_overview' => $scheduleOverview,
            'team_leader_statuses' => $teamLeaderStatuses,
            'dispatchers' => $dispatchers,
            'units_monitor' => $unitsMonitor,
            'flagged_customers' => $flaggedCustomers,
            'recent_activities' => $recentActivities,
            'governance_summary' => $governanceSummary,
            'quick_links' => $this->buildQuickLinks($user),
            'highlights' => [
                [
                    'title' => 'Bookings Today',
                    'message' => $todayBookings . ' new bookings created today.',
                    'created_at_human' => now()->format('M d, Y'),
                ],
                [
                    'title' => 'Completed Today',
                    'message' => $completedToday . ' bookings completed today.',
                    'created_at_human' => now()->format('M d, Y'),
                ],
                [
                    'title' => 'Scheduled Today',
                    'message' => $scheduledTodayCount . ' jobs scheduled for today.',
                    'created_at_human' => now()->format('M d, Y'),
                ],
                [
                    'title' => 'Due Now Scheduled',
                    'message' => $dueNowScheduledCount . ' jobs are due for dispatch now.',
                    'created_at_human' => now()->format('M d, Y H:i'),
                ],
                [
                    'title' => 'Upcoming Scheduled',
                    'message' => $upcomingScheduledCount . ' jobs are scheduled for later.',
                    'created_at_human' => now()->format('M d, Y'),
                ],
            ],
        ];
    }

    protected function buildQuickLinks(User $user): array
    {
        $commonLinks = [
            [
                'label' => 'Dispatch Queue',
                'description' => 'Review requests, negotiations, and returned jobs.',
                'url' => route('admin.dispatch'),
                'icon' => 'clipboard-check',
            ],
            [
                'label' => 'Active Jobs',
                'description' => 'Track field operations and current towing work.',
                'url' => route('admin.jobs'),
                'icon' => 'briefcase-business',
            ],
            [
                'label' => 'Units & Leaders',
                'description' => 'Check readiness, assignments, and leader workload.',
                'url' => route('admin.drivers'),
                'icon' => 'users-round',
            ],
            [
                'label' => 'Monitoring Board',
                'description' => 'Open the existing live operations monitor.',
                'url' => route('superadmin.monitoring.index'),
                'icon' => 'radar',
            ],
        ];

        if ((int) $user->role_id === 1) {
            return array_merge($commonLinks, [
                [
                    'label' => 'Manage Users',
                    'description' => 'Review roles, status, archives, and access requests.',
                    'url' => route('superadmin.users.index'),
                    'icon' => 'users',
                ],
                [
                    'label' => 'Protection Center',
                    'description' => 'Inspect backups, archive totals, and retention tools.',
                    'url' => route('superadmin.backups.index'),
                    'icon' => 'shield-check',
                ],
                [
                    'label' => 'Audit Logs',
                    'description' => 'Inspect system events and administrative actions.',
                    'url' => route('superadmin.audit.logs'),
                    'icon' => 'file-search',
                ],
            ]);
        }

        return array_merge($commonLinks, [
            [
                'label' => 'Dispatcher Dashboard',
                'description' => 'Open the existing command center summary.',
                'url' => route('admin.dashboard'),
                'icon' => 'layout-dashboard',
            ],
            [
                'label' => 'Unit Availability',
                'description' => 'See which towing units are ready or blocked.',
                'url' => route('admin.available-units'),
                'icon' => 'truck',
            ],
        ]);
    }

    protected function transformBookingCard(Booking $booking, $teamLeaderStatusMap): array
    {
        $teamLeaderId = $booking->assigned_team_leader_id ?: optional($booking->unit)->team_leader_id;
        $leaderSync = $teamLeaderStatusMap->get((int) $teamLeaderId, []);
        $teamLeader = optional(optional($booking->unit)->teamLeader)->name
            ?? optional($booking->assignedTeamLeader)->name
            ?? 'Awaiting crew';

        $driver = $booking->driver_name
            ?? optional(optional($booking->unit)->driver)->name
            ?? 'Driver pending';

        return [
            'job_code' => $booking->job_code,
            'booking_code' => $booking->job_code,
            'status' => str($booking->status)->replace('_', ' ')->title()->toString(),
            'customer_name' => $booking->customer->full_name ?? 'Customer',
            'truck_type' => $booking->truckType->name ?? 'Tow request',
            'unit_name' => $booking->unit->name ?? 'Unit pending',
            'unit_plate' => $booking->unit->plate_number ?? 'Plate pending',
            'driver_name' => $driver,
            'team_leader_name' => $teamLeader,
            'team_leader_status_summary' => $leaderSync['status_summary'] ?? 'Offline · Busy',
            'pickup_address' => Str::limit($booking->pickup_address ?? 'Unknown pickup', 48),
            'dropoff_address' => Str::limit($booking->dropoff_address ?? 'Unknown drop-off', 44),
            'updated_at_human' => optional($booking->updated_at)->diffForHumans() ?? 'Just now',
            'service_mode_label' => $booking->service_mode_label ?? '',
            'schedule_window_label' => $booking->schedule_window_label ?? '',
        ];
    }

    protected function transformRecentBooking(Booking $booking): array
    {
        return [
            'job_code' => $booking->job_code,
            'booking_code' => $booking->job_code,
            'status' => str($booking->status)->replace('_', ' ')->title()->toString(),
            'customer_name' => $booking->customer->full_name ?? 'Customer',
            'truck_type' => $booking->truckType->name ?? 'Tow request',
            'pickup_address' => Str::limit($booking->pickup_address ?? 'Unknown pickup', 48),
            'dropoff_address' => Str::limit($booking->dropoff_address ?? 'Unknown drop-off', 44),
            'updated_at_human' => optional($booking->updated_at)->diffForHumans() ?? 'Just now',
        ];
    }
}
