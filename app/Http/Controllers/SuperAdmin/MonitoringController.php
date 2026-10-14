<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use App\Services\UnitAvailabilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Owner → Operations Monitor. Every job and truck figure comes from the same
 * services the dispatcher uses (TeamLeaderAvailabilityService for busy leaders,
 * UnitAvailabilityService for active jobs and truck states), so the Monitor
 * cannot disagree with Units & Leaders or Dispatch.
 */
class MonitoringController extends Controller
{
    protected const STATUS_FILTERS = ['requested', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'completed', 'rejected', 'cancelled'];

    protected const PERIODS = ['today' => 'created today', 'week' => 'created this week', 'all' => 'all time'];

    protected const IN_PROGRESS_STATUSES = ['on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'];

    public function __construct(
        protected TeamLeaderAvailabilityService $teamLeaderAvailability,
        protected UnitAvailabilityService $unitAvailability,
    ) {
    }

    public function index(Request $request)
    {
        $payload = $this->buildPayload($request);
        $html = view('superadmin.monitoring._sections', $payload)->render();

        return view('superadmin.monitoring.index', $payload + [
            'sectionsHtml' => $html,
            'sectionsHash' => md5($html),
            'generatedLabel' => now()->format('g:i:s A'),
        ]);
    }

    /**
     * Polled every 15 seconds. The sections are re-rendered on the server, but the
     * browser sends the hash of what it already shows, so an unchanged board costs a
     * few hundred bytes instead of the whole page.
     */
    public function live(Request $request): JsonResponse
    {
        $payload = $this->buildPayload($request);
        $html = view('superadmin.monitoring._sections', $payload)->render();
        $hash = md5($html);
        $unchanged = $request->query('view_hash') === $hash;

        return response()->json(array_filter([
            'monitoringStats' => $payload['monitoringStats'],
            'hash' => $hash,
            'unchanged' => $unchanged,
            'generated_label' => now()->format('g:i:s A'),
            'html' => $unchanged ? null : $html,
        ], fn ($value) => $value !== null));
    }

    protected function buildPayload(Request $request): array
    {
        $status = (string) $request->input('status', '');
        $period = (string) $request->input('period', 'today');

        $filters = [
            'status' => in_array($status, self::STATUS_FILTERS, true) ? $status : '',
            'search' => trim((string) $request->input('search', '')),
            'period' => array_key_exists($period, self::PERIODS) ? $period : 'today',
        ];

        // ---- Jobs -----------------------------------------------------------------------
        // Unfiltered, the table lists the current active jobs (canonical definition). With a
        // search or status chosen, it lists matching bookings instead, by date created.
        $filtered = $filters['status'] !== '' || $filters['search'] !== '';
        $with = ['customer', 'truckType', 'unit', 'assignedTeamLeader'];

        if ($filtered) {
            $listQuery = Booking::query()->with($with)->latest('updated_at');

            if ($filters['status'] !== '') {
                $listQuery->where('status', $filters['status']);
            }
            if ($filters['search'] !== '') {
                $this->applySearch($listQuery, $filters['search']);
            }
            if ($filters['period'] === 'today') {
                $listQuery->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]);
            } elseif ($filters['period'] === 'week') {
                $listQuery->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
            }
        } else {
            $listQuery = $this->unitAvailability->activeJobsQuery()->with($with)->latest('updated_at');
        }

        $activeBookings = $listQuery->take(8)->get();
        $listCaption = $filtered
            ? 'Bookings matching your filters (' . self::PERIODS[$filters['period']] . '), newest activity first.'
            : 'Current active jobs, newest activity first. Search or choose a status to browse bookings by date created.';

        $activeJobsCount = $this->unitAvailability->activeJobsQuery()->count();

        // ---- Team leaders: busy = every qualifying active job, not just the ones listed -----
        $teamLeaders = User::query()
            ->visibleToOperations()
            ->where('role_id', 3)
            ->with(['unit', 'unit.driver'])
            ->get();

        $busyTeamLeaderIds = $this->teamLeaderAvailability->busyTeamLeaderIds();
        $teamLeaderSummary = $this->teamLeaderAvailability->summarize($teamLeaders, $busyTeamLeaderIds);
        $activeBookingByLeaderId = $this->teamLeaderAvailability->activeBookingsByLeaderId();

        $todayRange = [now()->startOfDay(), now()->endOfDay()];

        $leaderReturnCounts = Booking::query()
            ->selectRaw('returned_by_team_leader_id, COUNT(*) as total')
            ->whereNotNull('returned_by_team_leader_id')
            ->whereBetween('returned_at', $todayRange)
            ->groupBy('returned_by_team_leader_id')
            ->pluck('total', 'returned_by_team_leader_id');

        $leaderCompletedCounts = Booking::query()
            ->selectRaw('assigned_team_leader_id, COUNT(*) as total')
            ->whereNotNull('assigned_team_leader_id')
            ->where('status', 'completed')
            ->whereBetween('completed_at', $todayRange)
            ->groupBy('assigned_team_leader_id')
            ->pluck('total', 'assigned_team_leader_id');

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

        // ---- Dispatchers: counts per user, and each user's latest action in one small query --
        $dispatcherActionCounts = AuditLog::query()
            ->selectRaw('user_id, COUNT(*) as total')
            ->whereBetween('created_at', $todayRange)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $dispatcherQuoteCounts = Booking::query()
            ->selectRaw('created_by_admin_id, COUNT(*) as total')
            ->whereNotNull('created_by_admin_id')
            ->whereBetween('quoted_at', $todayRange)
            ->groupBy('created_by_admin_id')
            ->pluck('total', 'created_by_admin_id');

        $dispatcherCreatedCounts = Booking::query()
            ->selectRaw('created_by_admin_id, COUNT(*) as total')
            ->whereNotNull('created_by_admin_id')
            ->whereBetween('created_at', $todayRange)
            ->groupBy('created_by_admin_id')
            ->pluck('total', 'created_by_admin_id');

        $dispatcherUsers = User::query()->visibleToOperations()->where('role_id', 2)->get();

        $lastActionIds = AuditLog::query()
            ->selectRaw('MAX(id) as last_id')
            ->whereIn('user_id', $dispatcherUsers->pluck('id'))
            ->groupBy('user_id')
            ->pluck('last_id');
        $dispatcherLastAction = AuditLog::query()->whereIn('id', $lastActionIds)->get()->keyBy('user_id');

        $dispatchers = $dispatcherUsers
            ->map(function (User $dispatcher) use ($dispatcherActionCounts, $dispatcherLastAction, $dispatcherQuoteCounts, $dispatcherCreatedCounts) {
                $lastAction = $dispatcherLastAction->get($dispatcher->id);
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

        // ---- Returned tasks: what a Team Leader return actually writes is status "returned";
        // older rows kept an accepted/assigned status with returned_at set, so both count.
        $returnedTasksCount = Booking::query()
            ->where(function (Builder $query) {
                $query->where('status', 'returned')
                    ->orWhere(fn (Builder $legacy) => $legacy->whereNotNull('returned_at')->whereIn('status', ['confirmed', 'accepted', 'assigned']));
            })
            ->count();

        $scheduledTodayCount = Booking::query()
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', $todayRange)
            ->count();

        $dueSoonScheduledCount = Booking::query()
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [now(), now()->copy()->addHours(2)])
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->count();

        // ---- Fleet: one category per non-archived truck, in the Units & Leaders order ----------
        // maintenance > busy (active job) > reserved > available > unavailable (no leader/driver,
        // off duty, inactive). The five counts therefore add up to the fleet total.
        $unitRows = $this->unitAvailability->evaluateAll()->map(function (array $row) {
            $row['category'] = $this->unitCategory($row);

            return $row;
        });
        $unitCounts = $unitRows->countBy(fn (array $row) => $row['category'][0]);

        $availableUnitsCount = (int) ($unitCounts['available'] ?? 0);
        $onJobUnitsCount = (int) ($unitCounts['on_job'] ?? 0);
        $reservedUnitsCount = (int) ($unitCounts['reserved'] ?? 0);
        $maintenanceUnitsCount = (int) ($unitCounts['maintenance'] ?? 0);
        $notAvailableUnitsCount = (int) ($unitCounts['unavailable'] ?? 0);

        $blacklistedCount = Customer::query()->whereRaw('LOWER(risk_level) = ?', ['blacklisted'])->count();
        $watchlistCount = Customer::query()->whereRaw('LOWER(risk_level) = ?', ['watchlist'])->count();

        $flaggedCustomers = Customer::query()
            ->whereRaw('LOWER(risk_level) IN (?, ?)', ['watchlist', 'blacklisted'])
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
            ->filter(fn (array $leader) => $leader['presence'] === 'offline' && $leader['unit_name'] !== 'No assigned unit')
            ->count();

        $outOfServiceUnits = $maintenanceUnitsCount + $notAvailableUnitsCount;

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
            $outOfServiceUnits > 0 ? [
                'level' => 'info',
                'title' => 'Units not available for dispatch',
                'message' => $outOfServiceUnits . ' unit(s) are in maintenance or otherwise unavailable (no leader or driver, or off duty).',
            ] : null,
            // BookingService::checkCustomerEligibility refuses new bookings from blacklisted customers.
            $blacklistedCount > 0 ? [
                'level' => 'danger',
                'title' => 'Blacklisted customers on record',
                'message' => $blacklistedCount . ' customer account(s) are blacklisted and cannot place new bookings.',
            ] : null,
        ])->filter()->values();

        if ($attentionAlerts->isEmpty()) {
            $attentionAlerts = collect([[
                'level' => 'success',
                'title' => 'No urgent blockers',
                'message' => 'Dispatch, leaders, bookings, and units are all currently stable.',
            ]]);
        }

        $completedTodayCount = Booking::query()->where('status', 'completed')->whereBetween('completed_at', $todayRange)->count();

        $bookingPipeline = collect([
            ['label' => 'Requested', 'count' => Booking::query()->where('status', 'requested')->count(), 'tone' => 'neutral'],
            ['label' => 'Quoted', 'count' => Booking::query()->whereIn('status', ['reviewed', 'quotation_sent'])->count(), 'tone' => 'warning'],
            ['label' => 'Assigned', 'count' => Booking::query()->whereIn('status', ['confirmed', 'accepted', 'assigned'])->count(), 'tone' => 'info'],
            ['label' => 'In Progress', 'count' => Booking::query()->whereIn('status', self::IN_PROGRESS_STATUSES)->count(), 'tone' => 'active'],
            ['label' => 'Waiting Verification', 'count' => Booking::query()->where('status', 'waiting_verification')->count(), 'tone' => 'warning'],
            ['label' => 'Completed Today', 'count' => $completedTodayCount, 'tone' => 'success'],
            ['label' => 'Returned', 'count' => $returnedTasksCount, 'tone' => 'danger'],
            ['label' => 'Scheduled', 'count' => $scheduledTodayCount, 'tone' => 'info'],
        ]);

        $unitsMonitor = $unitRows
            ->sortByDesc(fn (array $row) => $row['unit']->updated_at?->getTimestamp() ?? 0)
            ->take(10)
            ->values()
            ->map(function (array $row) {
                $unit = $row['unit'];
                [$key, $label] = $row['category'];
                $booking = $row['active_booking'] ?? $row['reservation'];

                return [
                    'name' => $unit->name,
                    'plate_number' => $unit->plate_number,
                    'truck_type' => optional($unit->truckType)->name ?? 'N/A',
                    'team_leader' => optional($unit->teamLeader)->full_name ?: (optional($unit->teamLeader)->name ?: 'Unassigned'),
                    'status' => $key,
                    'status_label' => $label,
                    'booking_code' => $booking?->job_code ?? 'No linked booking',
                    'updated_at' => $unit->updated_at?->diffForHumans() ?? 'Recently updated',
                ];
            });

        $monitoringStats = [
            'active_jobs' => $activeJobsCount,
            'pending_requests' => Booking::where('status', 'requested')->count(),
            'completed_today' => $completedTodayCount,
            'online_team_leaders' => $teamLeaderSummary['online_count'],
            'busy_team_leaders' => $teamLeaderSummary['busy_count'],
            'dispatchers' => $dispatcherUsers->count(),
            'scheduled_today' => $scheduledTodayCount,
            'returned_tasks' => $returnedTasksCount,
            'total_units' => $unitRows->count(),
            'available_units' => $availableUnitsCount,
            'units_on_job' => $onJobUnitsCount,
            'reserved_units' => $reservedUnitsCount,
            'maintenance_units' => $maintenanceUnitsCount,
            'not_available_units' => $notAvailableUnitsCount,
            'watchlist_customers' => $watchlistCount,
            'blacklisted_customers' => $blacklistedCount,
            'total_bookings' => Booking::count(),
        ];

        return compact(
            'filters',
            'filtered',
            'listCaption',
            'monitoringStats',
            'attentionAlerts',
            'bookingPipeline',
            'activeBookings',
            'teamLeaderStatuses',
            'dispatchers',
            'unitsMonitor',
            'flaggedCustomers',
        );
    }

    /** @return array{0: string, 1: string} [css/state key, label] */
    protected function unitCategory(array $row): array
    {
        if ($row['operational_state'] === 'maintenance') {
            return ['maintenance', 'Maintenance'];
        }
        if ($row['active_booking'] !== null || in_array('busy', $row['reasons'], true)) {
            return ['on_job', 'Busy'];
        }
        if ($row['reservation'] !== null) {
            return ['reserved', 'Reserved'];
        }

        return $row['available'] ? ['available', 'Available'] : ['unavailable', 'Unavailable'];
    }

    /** Same matching as Owner → Bookings: case-insensitive on PostgreSQL, phone digits without the 0 / 63 prefix. */
    protected function applySearch(Builder $query, string $search): void
    {
        $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $phoneDigits = preg_replace('/^(63|0)/', '', preg_replace('/\D+/', '', $search));

        $query->where(function (Builder $q) use ($like, $operator, $phoneDigits) {
            $q->where('booking_code', $operator, $like)
                ->orWhere('pickup_address', $operator, $like)
                ->orWhere('dropoff_address', $operator, $like)
                ->orWhereHas('customer', function (Builder $customer) use ($like, $operator, $phoneDigits) {
                    $customer->where('full_name', $operator, $like);

                    if (strlen($phoneDigits) >= 3 && DB::getDriverName() === 'pgsql') {
                        $customer->orWhereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') like ?", ['%' . $phoneDigits . '%']);
                    }
                });
        });
    }
}
