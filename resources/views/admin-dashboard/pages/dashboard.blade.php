@extends('admin-dashboard.layouts.app')

@section('title', 'Dispatcher Dashboard')

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('admin/css/dashboard.css') }}">
    <style>
        .schedule-overview-card {
            grid-column: 1 / -1;
        }

        .schedule-summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin: 14px 0 18px;
        }

        .schedule-summary-pill {
            border-radius: 16px;
            padding: 14px;
            border: 1px solid #e5e7eb;
            background: #fff;
        }

        .schedule-summary-pill small {
            display: block;
            color: #64748b;
            margin-bottom: 6px;
        }

        .schedule-summary-pill strong {
            font-size: 1.35rem;
            color: #0f172a;
        }

        .schedule-summary-pill.due {
            background: #fff1f2;
            border-color: #fecdd3;
        }

        .schedule-summary-pill.today {
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .schedule-summary-pill.upcoming {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .schedule-status {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .schedule-status.due-now {
            background: #fee2e2;
            color: #991b1b;
        }

        .schedule-status.today {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .schedule-status.upcoming {
            background: #dcfce7;
            color: #166534;
        }

        @media (max-width: 768px) {
            .schedule-summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

@section('content')
    <div class="dispatcher-dashboard" id="dispatcherDashboard" data-live-overview-url="{{ route('admin.live-overview') }}">
        <div class="dashboard-hero">
            <div class="hero-copy">
                <h1>Jarz Command Center</h1>
                <p>Live operations overview for requests, crew activity, and towing readiness.</p>
            </div>

            <div class="hero-tools">
                <div class="date-display" id="currentDate"></div>
                <button class="refresh-btn" id="refreshDashboardBtn" type="button" title="Refresh data">
                    <i data-lucide="refresh-cw"></i>
                    <span>Refresh</span>
                </button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card pending-card">
                <div class="stat-top">
                    <span class="stat-kicker">Incoming</span>
                    <i data-lucide="inbox"></i>
                </div>
                <div class="stat-number" id="incomingCount">{{ $pendingRequests }}</div>
                <div class="stat-label">Live Requests</div>
            </div>

            <div class="stat-card active-card">
                <div class="stat-top">
                    <span class="stat-kicker">Workload</span>
                    <i data-lucide="truck"></i>
                </div>
                <div class="stat-number" id="activeJobsCount">{{ $activeJobs }}</div>
                <div class="stat-label">Active Jobs</div>
            </div>

            <div class="stat-card crew-card">
                <div class="stat-top">
                    <span class="stat-kicker">Leaders</span>
                    <i data-lucide="check-circle"></i>
                </div>
                <div class="stat-number" id="availableLeadersCount">{{ $available }}</div>
                <div class="stat-label">Available Team Leaders</div>
            </div>

            <div class="stat-card pending-card">
                <div class="stat-top">
                    <span class="stat-kicker">Scheduled</span>
                    <i data-lucide="calendar-clock"></i>
                </div>
                <div class="stat-number" id="scheduledQueueCount">{{ $scheduledTodayCount + $upcomingScheduledCount }}</div>
                <div class="stat-label">Planned Pickups</div>
            </div>

        </div>

        <div class="dashboard-main-grid">
            <section class="chart-card">
                <div class="card-header">
                    <div>
                        <h3>Job mix snapshot</h3>
                        <p>Current mix of completed, assigned, and pending towing work.</p>
                    </div>
                    <div class="chart-legend">
                        <span class="legend-item"><span class="legend-dot completed"></span>Completed</span>
                        <span class="legend-item"><span class="legend-dot assigned"></span>Assigned</span>
                        <span class="legend-item"><span class="legend-dot pending"></span>Pending</span>
                    </div>
                </div>

                <div class="chart-wrap">
                    <canvas id="performanceChart" data-completed="{{ $chartData['completed'] }}"
                        data-assigned="{{ $chartData['assigned'] }}" data-pending="{{ $chartData['pending'] }}"></canvas>
                </div>
            </section>

            <aside class="actions-card">
                <div class="actions-head">
                    <h3>Quick Actions</h3>
                    <p>Fast dispatcher shortcuts for the live operation board.</p>
                </div>

                <div class="action-buttons">
                    <a href="{{ route('admin.dispatch') }}" class="action-btn primary">
                        <i data-lucide="inbox"></i>
                        <span>Review Requests</span>
                        <small>Open the live queue and respond fast.</small>
                    </a>
                    <a href="{{ route('admin.jobs') }}" class="action-btn success">
                        <i data-lucide="briefcase-business"></i>
                        <span>Manage Jobs</span>
                        <small>Track active tow operations in one place.</small>
                    </a>
                    <a href="{{ route('admin.drivers') }}" class="action-btn info">
                        <i data-lucide="users"></i>
                        <span>View Team Leaders</span>
                        <small>Check leader availability and assignments.</small>
                    </a>
                    <a href="{{ route('admin.available-units') }}" class="action-btn warning">
                        <i data-lucide="check-square"></i>
                        <span>View Units</span>
                        <small>See which towing units are ready now.</small>
                    </a>
                </div>
            </aside>

            <section class="activity-card schedule-overview-card">
                <div class="card-header">
                    <div>
                        <h3>Schedule Overview</h3>
                        <p>Track due-now jobs and upcoming scheduled pickups before they enter the urgent queue.</p>
                    </div>
                    <a href="{{ route('admin.dispatch') }}" class="action-btn warning" style="max-width: 220px;">
                        <i data-lucide="calendar-range"></i>
                        <span>Open Dispatch Queue</span>
                        <small>Manage planned jobs</small>
                    </a>
                </div>

                <div class="schedule-summary-grid">
                    <div class="schedule-summary-pill due">
                        <small>Due Now</small>
                        <strong id="dueNowScheduledCount">{{ $dueNowScheduledCount }}</strong>
                    </div>
                    <div class="schedule-summary-pill today">
                        <small>Scheduled Today</small>
                        <strong id="scheduledTodayCount">{{ $scheduledTodayCount }}</strong>
                    </div>
                    <div class="schedule-summary-pill upcoming">
                        <small>Upcoming Later</small>
                        <strong id="upcomingScheduledCount">{{ $upcomingScheduledCount }}</strong>
                    </div>
                </div>

                <div class="activity-list" id="scheduleOverviewList">
                    @forelse ($scheduleOverview as $item)
                        <div class="activity-item" data-type="schedule">
                            <div class="activity-icon request-icon">
                                <i data-lucide="calendar-clock"></i>
                            </div>

                            <div class="activity-content">
                                <div class="activity-line">
                                    <strong>{{ $item['booking_code'] }}</strong>
                                    <span>{{ $item['truck_type'] }}</span>
                                </div>

                                <div class="activity-meta">
                                    <span>{{ $item['customer_name'] }}</span>
                                    <span>{{ $item['schedule_window_label'] }}</span>
                                    <span>{{ $item['pickup_address'] }}</span>
                                    <span>{{ $item['dropoff_address'] }}</span>
                                </div>
                            </div>

                            <div class="schedule-status {{ $item['tone'] }}">{{ $item['status'] }}</div>
                        </div>
                    @empty
                        <div class="no-activity">
                            <i data-lucide="calendar-clock"></i>
                            <p>No scheduled bookings are waiting right now.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="activity-card">
                <div class="card-header">
                    <div>
                        <h3>Incoming request feed</h3>
                        <p>Newest towing requests waiting for dispatcher review.</p>
                    </div>
                    <div class="activity-filter">
                        <button class="filter-btn active" data-filter="all">All</button>
                        <button class="filter-btn" data-filter="request">Queue</button>
                        <button class="filter-btn" data-filter="priority">Priority</button>
                    </div>
                </div>

                <div class="activity-list" id="incomingRequestList">
                    @forelse ($incomingRequests as $request)
                        <div class="activity-item" data-type="{{ $loop->first ? 'priority' : 'request' }}">
                            <div class="activity-icon request-icon">
                                <i data-lucide="siren"></i>
                            </div>

                            <div class="activity-content">
                                <div class="activity-line">
                                    <strong>{{ $request['customer_name'] }}</strong>
                                    <span>{{ $request['truck_type'] }}</span>
                                </div>

                                <div class="activity-meta">
                                    <span>{{ $request['booking_code'] }}</span>
                                    <span>{{ $request['created_at_human'] }}</span>
                                    <span>{{ $request['pickup_address'] }}</span>
                                    <span>{{ $request['dropoff_address'] }}</span>
                                </div>
                            </div>

                            <div class="activity-status pending">Pending</div>
                        </div>
                    @empty
                        <div class="no-activity">
                            <i data-lucide="activity"></i>
                            <p>No pending requests right now.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="activity-card">
                <div class="card-header">
                    <div>
                        <h3>Current activity</h3>
                        <p>Live handoffs from team leaders with the assigned unit and driver.</p>
                    </div>
                </div>

                <div class="activity-list" id="currentActivityList">
                    @forelse ($currentActivities as $activity)
                        <div class="activity-item" data-type="request">
                            <div class="activity-icon request-icon">
                                <i data-lucide="truck"></i>
                            </div>

                            <div class="activity-content">
                                <div class="activity-line">
                                    <strong>{{ $activity['booking_code'] }}</strong>
                                    <span>{{ $activity['status'] }}</span>
                                </div>

                                <div class="activity-meta">
                                    <span>{{ $activity['customer_name'] }}</span>
                                    <span>{{ $activity['unit_name'] }} · {{ $activity['unit_plate'] }}</span>
                                    <span>{{ $activity['team_leader_name'] }} · {{ $activity['driver_name'] }}</span>
                                    <span>{{ $activity['team_leader_status_summary'] }}</span>
                                    <span>{{ $activity['updated_at_human'] }}</span>
                                </div>
                            </div>

                            <div class="activity-status available">Live</div>
                        </div>
                    @empty
                        <div class="no-activity">
                            <i data-lucide="truck"></i>
                            <p>No jobs are active right now.</p>
                        </div>
                    @endforelse
                </div>
            </section>


        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="{{ asset('admin/js/dashboard.js') }}"></script>
@endpush
