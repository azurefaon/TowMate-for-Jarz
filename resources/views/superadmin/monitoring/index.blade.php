@extends('layouts.superadmin')

@section('title', 'Operations Overview')

@push('styles')
    <style>
        .monitor-shell {
            display: grid;
            gap: 16px;
        }

        .monitor-hero,
        .monitor-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        .monitor-hero {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            align-items: center;
        }

        .monitor-hero h1 {
            margin: 0 0 4px;
            font-size: 1.55rem;
            line-height: 1.2;
            color: #0f172a;
        }

        .monitor-hero p {
            margin: 0;
            max-width: 700px;
            color: #64748b;
            font-size: 0.94rem;
            line-height: 1.5;
        }

        .monitor-sync {
            padding: 7px 11px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
        }

        .monitor-filter-grid,
        .monitor-stats,
        .monitor-grid {
            display: grid;
            gap: 14px;
        }

        .monitor-filter-grid {
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        }

        .monitor-stats {
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        }

        .monitor-grid {
            grid-template-columns: minmax(0, 1.3fr) minmax(300px, 0.9fr);
        }

        .monitor-stat {
            border-radius: 14px;
            padding: 14px;
            background: linear-gradient(135deg, #fffbe6 0%, #ffffff 100%);
            border: 1px solid #fde68a;
        }

        .monitor-stat small {
            color: #6b7280;
            display: block;
            margin-bottom: 6px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .monitor-stat strong {
            font-size: 1.6rem;
            line-height: 1.1;
            color: #0f172a;
        }

        .monitor-list {
            display: grid;
            gap: 10px;
        }

        .monitor-item {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 11px 12px;
            background: #fcfcfd;
        }

        .monitor-item-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }

        .monitor-badge {
            display: inline-flex;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            background: #f3f4f6;
        }

        .monitor-badge.online {
            background: #dcfce7;
            color: #166534;
        }

        .monitor-badge.offline {
            background: #f3f4f6;
            color: #4b5563;
        }

        .monitor-badge.busy {
            background: #fee2e2;
            color: #991b1b;
        }

        .monitor-badge.assigned,
        .monitor-badge.accepted,
        .monitor-badge.on_the_way,
        .monitor-badge.in_progress,
        .monitor-badge.waiting_verification,
        .monitor-badge.on_job {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .monitor-item p,
        .monitor-item small {
            margin: 3px 0 0;
            color: #6b7280;
            font-size: 0.88rem;
            line-height: 1.45;
        }

        .monitor-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .monitor-input,
        .monitor-select,
        .monitor-button {
            width: 100%;
            border-radius: 12px;
            border: 1px solid #d1d5db;
            padding: 9px 12px;
            font-size: 0.92rem;
        }

        .monitor-button,
        .monitor-card a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 40px;
            padding: 9px 13px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease, border-color 0.18s ease;
        }

        .monitor-card a {
            min-height: 34px;
            padding: 7px 10px;
            border-radius: 10px;
            font-size: 0.82rem;
            gap: 6px;
            white-space: nowrap;
        }

        .monitor-head-link {
            align-self: flex-start;
            min-height: 32px;
            padding: 6px 9px;
            border-radius: 9px;
            font-size: 0.8rem;
            box-shadow: 0 6px 14px rgba(245, 213, 101, 0.16);
        }

        .monitor-button {
            border: 1px solid #111827;
            background: linear-gradient(135deg, #111827 0%, #1f2937 100%);
            color: #fff;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.16);
        }

        .monitor-button i,
        .monitor-card a i {
            width: 16px;
            height: 16px;
        }

        .monitor-card a i {
            width: 14px;
            height: 14px;
        }

        .monitor-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.2);
        }

        .monitor-button:focus,
        .monitor-card a:focus,
        .monitor-input:focus,
        .monitor-select:focus {
            outline: none;
            box-shadow: 0 0 0 4px rgba(250, 204, 21, 0.22);
            border-color: #facc15;
        }

        .monitor-card a {
            border: 1px solid #f5d565;
            background: #fff8db;
            color: #7c5a00;
            box-shadow: 0 8px 18px rgba(245, 213, 101, 0.2);
        }

        .monitor-card a:hover {
            background: #fff1b8;
            border-color: #eab308;
            transform: translateY(-1px);
        }

        .monitor-stats-expanded,
        .monitor-pipeline,
        .monitor-alert-list {
            display: grid;
            gap: 12px;
        }

        .monitor-stats-expanded {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        }

        .monitor-alert {
            border-radius: 12px;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
        }

        .monitor-alert h4 {
            margin: 0 0 4px;
            font-size: 0.92rem;
        }

        .monitor-alert p {
            margin: 0;
            color: #475569;
        }

        .monitor-alert.danger {
            background: #fef2f2;
            border-color: #fecaca;
        }

        .monitor-alert.warning {
            background: #fff7ed;
            border-color: #fed7aa;
        }

        .monitor-alert.info {
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .monitor-alert.success {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .monitor-pipeline {
            grid-template-columns: repeat(auto-fit, minmax(125px, 1fr));
            margin-bottom: 12px;
        }

        .pipeline-card {
            border-radius: 12px;
            padding: 12px;
            border: 1px solid #e5e7eb;
            background: #fcfcfd;
        }

        .pipeline-card span {
            display: block;
            color: #64748b;
            font-size: 0.76rem;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .pipeline-card strong {
            font-size: 1.35rem;
            color: #0f172a;
            line-height: 1.1;
        }

        .pipeline-card.warning {
            background: #fff7ed;
        }

        .pipeline-card.info {
            background: #eff6ff;
        }

        .pipeline-card.active {
            background: #eef2ff;
        }

        .pipeline-card.success {
            background: #f0fdf4;
        }

        .pipeline-card.danger {
            background: #fef2f2;
        }

        .tracker-metrics {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            margin-top: 7px;
        }

        .tracker-metrics span {
            display: inline-flex;
            padding: 4px 9px;
            border-radius: 999px;
            background: #f8fafc;
            color: #334155;
            font-size: 11px;
            font-weight: 600;
        }

        .monitor-badge.available {
            background: #dcfce7;
            color: #166534;
        }

        .monitor-badge.maintenance {
            background: #fef2f2;
            color: #991b1b;
        }

        .monitor-badge.info {
            background: #e0f2fe;
            color: #075985;
        }

        .monitor-badge.watchlist {
            background: #fff7ed;
            color: #c2410c;
        }

        .monitor-badge.blacklisted {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 960px) {
            .monitor-grid {
                grid-template-columns: 1fr;
            }

            .monitor-card a,
            .monitor-button {
                width: 100%;
            }
        }
    </style>
@endpush

@section('content')
    <div class="monitor-shell">
        <section class="monitor-hero">
            <div>
                <h1>Operations Overview</h1>
                <p>Keep an eye on bookings, dispatcher activity, and team leader progress all in one place.</p>
            </div>
            <div class="monitor-sync" id="monitorLastSync">Live sync ready</div>
        </section>

        <section class="monitor-card">
            <form method="GET" action="{{ route('superadmin.monitoring.index') }}" class="monitor-filter-grid">
                <input class="monitor-input" type="text" name="search" value="{{ $filters['search'] }}"
                    placeholder="Search booking, customer, or route">

                <select class="monitor-select" name="status">
                    <option value="">All statuses</option>
                    @foreach (['requested', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'completed', 'rejected', 'cancelled'] as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>
                            {{ str($status)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>

                <select class="monitor-select" name="period">
                    <option value="today" @selected($filters['period'] === 'today')>Today</option>
                    <option value="week" @selected($filters['period'] === 'week')>This week</option>
                    <option value="all" @selected($filters['period'] === 'all')>All time</option>
                </select>

                <button class="monitor-button" type="submit">
                    <i data-lucide="sliders-horizontal"></i>
                    <span>Apply Filters</span>
                </button>
            </form>
        </section>

        <section class="monitor-card">
            <div class="monitor-item-head">
                <div>
                    <h3>Today's Snapshot</h3>
                    <p>A quick look at the numbers that matter most today.</p>
                </div>
            </div>

            <div class="monitor-stats-expanded">
                <div class="monitor-stat">
                    <small>Active Jobs</small>
                    <strong id="monitorActiveJobs">{{ $monitoringStats['active_jobs'] }}</strong>
                </div>
                <div class="monitor-stat">
                    <small>Pending Requests</small>
                    <strong id="monitorPendingRequests">{{ $monitoringStats['pending_requests'] }}</strong>
                </div>
                <div class="monitor-stat">
                    <small>Scheduled Today</small>
                    <strong id="monitorScheduledToday">{{ $monitoringStats['scheduled_today'] }}</strong>
                </div>
                <div class="monitor-stat">
                    <small>Returned Tasks</small>
                    <strong id="monitorReturnedTasks">{{ $monitoringStats['returned_tasks'] }}</strong>
                </div>
                <div class="monitor-stat">
                    <small>Online Team Leaders</small>
                    <strong id="monitorOnlineLeaders">{{ $monitoringStats['online_team_leaders'] }}</strong>
                </div>
                <div class="monitor-stat">
                    <small>Busy Team Leaders</small>
                    <strong id="monitorBusyLeaders">{{ $monitoringStats['busy_team_leaders'] }}</strong>
                </div>
                <div class="monitor-stat">
                    <small>Dispatchers</small>
                    <strong id="monitorDispatchers">{{ $monitoringStats['dispatchers'] }}</strong>
                </div>
                <div class="monitor-stat">
                    <small>Available Units</small>
                    <strong id="monitorUnitsReady">{{ $monitoringStats['available_units'] }}</strong>
                </div>
                <div class="monitor-stat">
                    <small>Watchlist Customers</small>
                    <strong>{{ $monitoringStats['watchlist_customers'] }}</strong>
                </div>
                <div class="monitor-stat">
                    <small>Blacklisted Customers</small>
                    <strong>{{ $monitoringStats['blacklisted_customers'] }}</strong>
                </div>
            </div>
        </section>

        <section class="monitor-card">
            <div class="monitor-item-head">
                <div>
                    <h3>Attention Needed</h3>
                    <p>Items that need immediate superadmin visibility.</p>
                </div>
            </div>

            <div class="monitor-alert-list">
                @foreach ($attentionAlerts as $alert)
                    <div class="monitor-alert {{ $alert['level'] }}">
                        <h4>{{ $alert['title'] }}</h4>
                        <p>{{ $alert['message'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="monitor-card">
            <div class="monitor-item-head">
                <div>
                    <h3>Booking Pipeline</h3>
                    <p>Track requests from incoming bookings to field progress and completion.</p>
                </div>
                <a href="{{ route('superadmin.bookings.index') }}" class="monitor-head-link">
                    <i data-lucide="book-open"></i>
                    <span>Open bookings</span>
                </a>
            </div>

            <div class="monitor-pipeline">
                @foreach ($bookingPipeline as $stage)
                    <div class="pipeline-card {{ $stage['tone'] }}">
                        <span>{{ $stage['label'] }}</span>
                        <strong>{{ $stage['count'] }}</strong>
                    </div>
                @endforeach
            </div>

            <div class="monitor-list">
                @forelse ($activeBookings as $booking)
                    <div class="monitor-item">
                        <div class="monitor-item-head">
                            <strong>{{ $booking->job_code }}</strong>
                            <span
                                class="monitor-badge {{ $booking->status }}">{{ str($booking->status)->replace('_', ' ')->title() }}</span>
                        </div>
                        <p>{{ $booking->pickup_address }} → {{ $booking->dropoff_address }}</p>
                        <small>
                            {{ optional($booking->customer)->full_name ?? 'Customer pending' }} ·
                            {{ optional($booking->assignedTeamLeader)->full_name ?: (optional($booking->assignedTeamLeader)->name ?: 'Awaiting crew') }}
                        </small>
                        <div class="tracker-metrics">
                            <span>{{ $booking->service_mode_label }}</span>
                            <span>{{ $booking->schedule_window_label }}</span>
                            <span>{{ optional($booking->updated_at)->diffForHumans() }}</span>
                        </div>
                    </div>
                @empty
                    <div class="monitor-item">
                        <strong>No active jobs right now.</strong>
                        <p>The superadmin queue is clear.</p>
                    </div>
                @endforelse
            </div>
        </section>

        <section class="monitor-grid">
            <div class="monitor-card">
                <div class="monitor-item-head">
                    <div>
                        <h3>Team Leader Tracker</h3>
                        <p>See each leader’s presence, current unit, active job, returns, and completions.</p>
                    </div>
                </div>

                <div class="monitor-list">
                    @forelse ($teamLeaderStatuses as $leader)
                        <div class="monitor-item">
                            <div class="monitor-item-head">
                                <strong>{{ $leader['name'] }}</strong>
                                <span class="monitor-badge {{ $leader['presence'] }} {{ $leader['workload'] }}">
                                    {{ $leader['status_summary'] }}
                                </span>
                            </div>
                            <p>{{ $leader['unit_name'] }} · {{ $leader['driver_name'] }}</p>
                            <small>{{ $leader['active_job_code'] }} · {{ $leader['active_job_status'] }}</small>
                            <div class="tracker-metrics">
                                <span>Returns: {{ $leader['returns_today'] }}</span>
                                <span>Completed: {{ $leader['completed_today'] }}</span>
                                <span>{{ $leader['schedule_note'] }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="monitor-item">No team leaders found.</div>
                    @endforelse
                </div>
            </div>

            <div class="monitor-card">
                <div class="monitor-item-head">
                    <div>
                        <h3>Dispatcher Tracker</h3>
                        <p>Watch dispatcher activity, quote output, and current operational workload.</p>
                    </div>
                    <a href="{{ route('superadmin.audit.logs') }}" class="monitor-head-link">
                        <i data-lucide="activity"></i>
                        <span>View logs</span>
                    </a>
                </div>

                <div class="monitor-list">
                    @forelse ($dispatchers as $dispatcher)
                        <div class="monitor-item">
                            <div class="monitor-item-head">
                                <strong>{{ $dispatcher['name'] }}</strong>
                                <span class="monitor-badge">{{ $dispatcher['workload_label'] }}</span>
                            </div>
                            <p>{{ $dispatcher['last_action'] }}</p>
                            <small>{{ $dispatcher['last_seen'] }}</small>
                            <div class="tracker-metrics">
                                <span>Actions: {{ $dispatcher['actions_today'] }}</span>
                                <span>Quotes: {{ $dispatcher['quotes_today'] }}</span>
                                <span>Bookings: {{ $dispatcher['bookings_today'] }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="monitor-item">No dispatcher activity yet.</div>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="monitor-grid">
            <div class="monitor-card">
                <div class="monitor-item-head">
                    <div>
                        <h3>Unit & Schedule Monitor</h3>
                        <p>See which units are ready, who is assigned to them, and what jobs are coming up.</p>
                    </div>
                </div>

                <div class="monitor-list">
                    @forelse ($unitsMonitor as $unit)
                        <div class="monitor-item">
                            <div class="monitor-item-head">
                                <strong>{{ $unit['name'] }} · {{ $unit['plate_number'] }}</strong>
                                <span class="monitor-badge {{ $unit['status'] }}">{{ $unit['status_label'] }}</span>
                            </div>
                            <p>{{ $unit['team_leader'] }} · {{ $unit['driver'] }}</p>
                            <small>{{ $unit['truck_type'] }} · {{ $unit['booking_code'] }}</small>
                            <div class="tracker-metrics">
                                <span>{{ $unit['schedule_label'] }}</span>
                                <span>{{ $unit['updated_at'] }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="monitor-item">No units found.</div>
                    @endforelse
                </div>
            </div>

            <div class="monitor-card">
                <div class="monitor-item-head">
                    <div>
                        <h3>Risk Watchlist</h3>
                        <p>Customers tagged for unreachable, no-show, or non-payment behavior.</p>
                    </div>
                </div>

                <div class="monitor-list">
                    @forelse ($flaggedCustomers as $customer)
                        <div class="monitor-item">
                            <div class="monitor-item-head">
                                <strong>{{ $customer['name'] }}</strong>
                                <span
                                    class="monitor-badge {{ $customer['risk_level'] }}">{{ $customer['risk_label'] }}</span>
                            </div>
                            <p>{{ $customer['reason'] }}</p>
                            <small>{{ $customer['phone'] }} · {{ $customer['updated_at'] }}</small>
                        </div>
                    @empty
                        <div class="monitor-item">
                            <strong>No flagged customers right now.</strong>
                            <p>The customer risk list is currently clear.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="monitor-card">
                <div class="monitor-item-head">
                    <div>
                        <h3>Recent System Activity</h3>
                        <p>Latest dispatcher and team leader actions from the audit stream.</p>
                    </div>
                    <a href="{{ route('superadmin.audit.logs') }}" class="monitor-head-link">
                        <i data-lucide="history"></i>
                        <span>Audit logs</span>
                    </a>
                </div>

                <div class="monitor-list">
                    @forelse ($recentActivities as $activity)
                        <div class="monitor-item">
                            <div class="monitor-item-head">
                                <strong>{{ str($activity->action)->replace('_', ' ')->title() }}</strong>
                                <small>{{ optional($activity->created_at)->diffForHumans() }}</small>
                            </div>
                            <p>{{ optional($activity->user)->full_name ?: (optional($activity->user)->name ?: 'System') }}
                            </p>
                            <small>{{ $activity->entity_type }} #{{ $activity->entity_id }}</small>
                        </div>
                    @empty
                        <div class="monitor-item">No audit activity available.</div>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        const liveUrl = '{{ route('superadmin.monitoring.live', request()->query()) }}';

        function setMonitorMetric(id, value) {
            const element = document.getElementById(id);

            if (element) {
                element.textContent = value;
            }
        }

        async function refreshMonitoringSnapshot() {
            try {
                const response = await fetch(liveUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json();
                setMonitorMetric('monitorActiveJobs', data.monitoringStats.active_jobs);
                setMonitorMetric('monitorOnlineLeaders', data.monitoringStats.online_team_leaders);
                setMonitorMetric('monitorDispatchers', data.monitoringStats.dispatchers);
                setMonitorMetric('monitorPendingRequests', data.monitoringStats.pending_requests);
                setMonitorMetric('monitorScheduledToday', data.monitoringStats.scheduled_today);
                setMonitorMetric('monitorReturnedTasks', data.monitoringStats.returned_tasks);
                setMonitorMetric('monitorBusyLeaders', data.monitoringStats.busy_team_leaders);
                setMonitorMetric('monitorUnitsReady', data.monitoringStats.available_units);
                document.getElementById('monitorLastSync').textContent = 'Updated a few seconds ago';
            } catch (error) {
                document.getElementById('monitorLastSync').textContent = 'Trying to refresh again';
            }
        }

        refreshMonitoringSnapshot();
        setInterval(refreshMonitoringSnapshot, 15000);
    </script>
@endpush
