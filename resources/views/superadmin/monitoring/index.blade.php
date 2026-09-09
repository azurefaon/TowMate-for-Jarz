@extends('layouts.superadmin')

@section('title', 'Operations Monitor')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/monitoring.css') }}?v={{ filemtime(public_path('superadmin/css/monitoring.css')) }}">
@endpush

@section('content')
    <div class="monitor-page">
        <div class="page-top">
            <div>
                <h1>Operations Monitor</h1>
                <p>View current jobs, fleet readiness, and operational activity.</p>
            </div>
            <span class="monitor-sync-text" id="monitorLastSync">Live sync ready</span>
        </div>

        <form method="GET" action="{{ route('superadmin.monitoring.index') }}" class="monitor-toolbar">
            <div class="monitor-toolbar-left">
                <div class="monitor-search">
                    <i data-lucide="search"></i>
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search booking, customer, or route">
                </div>

                <select name="status" data-custom>
                    <option value="">All Statuses</option>
                    @foreach (['requested', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'completed', 'rejected', 'cancelled'] as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>
                            {{ str($status)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="monitor-toolbar-right">
                <select name="period" data-custom>
                    <option value="today" @selected($filters['period'] === 'today')>Today</option>
                    <option value="week" @selected($filters['period'] === 'week')>This Week</option>
                    <option value="all" @selected($filters['period'] === 'all')>All Time</option>
                </select>

                <button type="submit" class="monitor-apply-btn">Apply</button>
            </div>
        </form>

        <div class="monitor-section">
            <div class="monitor-section-head">
                <h2>Operations Summary</h2>
            </div>
            <div class="monitor-section-body">
                <div class="monitor-summary">
                    <div class="owner-kpi">
                        <span class="owner-kpi-label">Active Jobs</span>
                        <div class="owner-kpi-value" id="monitorActiveJobs">{{ $monitoringStats['active_jobs'] }}</div>
                    </div>
                    <div class="owner-kpi">
                        <span class="owner-kpi-label">Pending Requests</span>
                        <div class="owner-kpi-value" id="monitorPendingRequests">{{ $monitoringStats['pending_requests'] }}</div>
                    </div>
                    <div class="owner-kpi">
                        <span class="owner-kpi-label">Scheduled Today</span>
                        <div class="owner-kpi-value" id="monitorScheduledToday">{{ $monitoringStats['scheduled_today'] }}</div>
                    </div>
                    <div class="owner-kpi">
                        <span class="owner-kpi-label">Available Units</span>
                        <div class="owner-kpi-value" id="monitorUnitsReady">{{ $monitoringStats['available_units'] }}</div>
                    </div>
                    <div class="owner-kpi">
                        <span class="owner-kpi-label">Online Team Leaders</span>
                        <div class="owner-kpi-value" id="monitorOnlineLeaders">{{ $monitoringStats['online_team_leaders'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="monitor-section">
            <div class="monitor-section-head">
                <h2>Needs Attention</h2>
            </div>
            <div class="monitor-section-body">
                <div class="monitor-attention-list">
                    @foreach ($attentionAlerts as $alert)
                        <div class="monitor-attention-row {{ $alert['level'] }}">
                            <span class="monitor-attention-title">{{ $alert['title'] }}</span>
                            <span class="monitor-attention-desc">{{ $alert['message'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="monitor-section">
            <div class="monitor-section-head">
                <h2>Current Operations</h2>
                <a href="{{ route('superadmin.bookings.index') }}" class="monitor-link-action">Open Bookings →</a>
            </div>
            <div class="monitor-section-body">
                <div class="monitor-pipeline-scroll">
                    <div class="monitor-pipeline-row">
                        @foreach ($bookingPipeline as $stage)
                            <div class="monitor-pipeline-item">
                                <span class="monitor-pipeline-label">{{ $stage['label'] }}</span>
                                <span class="monitor-pipeline-count">{{ $stage['count'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="monitor-table-wrap">
                    <table class="monitor-table">
                        <thead>
                            <tr>
                                <th>Booking</th>
                                <th>Customer</th>
                                <th>Route</th>
                                <th>Unit / Team</th>
                                <th>Status</th>
                                <th>Schedule</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($activeBookings as $booking)
                                @php
                                    $route = $booking->pickup_address . ' → ' . $booking->dropoff_address;
                                    $unitName = optional($booking->unit)->name;
                                    $leaderName = optional($booking->assignedTeamLeader)->full_name ?: optional($booking->assignedTeamLeader)->name;
                                @endphp
                                <tr>
                                    <td class="monitor-num">{{ $booking->job_code }}</td>
                                    <td>{{ optional($booking->customer)->full_name ?? '—' }}</td>
                                    <td class="monitor-route-cell" title="{{ $route }}">{{ $route }}</td>
                                    <td>
                                        @if ($unitName)
                                            <span class="monitor-cell-primary">{{ $unitName }}</span>
                                            @if ($leaderName)
                                                <span class="monitor-cell-secondary">{{ $leaderName }}</span>
                                            @endif
                                        @elseif ($leaderName)
                                            <span class="monitor-cell-primary">{{ $leaderName }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="monitor-status--{{ $booking->status }}">{{ str($booking->status)->replace('_', ' ')->title() }}</td>
                                    <td>{{ $booking->schedule_window_label }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="monitor-empty-row">No active jobs right now.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="monitor-readiness-stack">
            <div class="monitor-section">
                <div class="monitor-section-head">
                    <h2>Team Leaders</h2>
                </div>
                <div class="monitor-section-body">
                    <div class="monitor-table-wrap">
                        <table class="monitor-table">
                            <colgroup>
                                <col style="width: 24%">
                                <col style="width: 28%">
                                <col style="width: 15%">
                                <col style="width: 17%">
                                <col style="width: 16%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Team Leader</th>
                                    <th>Unit</th>
                                    <th>Presence</th>
                                    <th>Workload</th>
                                    <th>Current Job</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($teamLeaderStatuses as $leader)
                                    <tr>
                                        <td>{{ $leader['name'] }}</td>
                                        <td class="monitor-nowrap">{{ $leader['unit_name'] === 'No assigned unit' ? '—' : $leader['unit_name'] }}</td>
                                        <td class="monitor-nowrap monitor-presence--{{ $leader['presence'] }}">{{ $leader['presence_label'] }}</td>
                                        <td class="monitor-nowrap monitor-workload--{{ $leader['workload'] }}">{{ $leader['workload_label'] }}</td>
                                        <td class="monitor-num">{{ $leader['active_job_code'] === 'No active booking' ? '—' : $leader['active_job_code'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="monitor-empty-row">No team leaders found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="monitor-section">
                <div class="monitor-section-head">
                    <h2>Units</h2>
                </div>
                <div class="monitor-section-body">
                    <div class="monitor-table-wrap">
                        <table class="monitor-table">
                            <colgroup>
                                <col style="width: 27%">
                                <col style="width: 16%">
                                <col style="width: 25%">
                                <col style="width: 16%">
                                <col style="width: 16%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Unit</th>
                                    <th>Truck Type</th>
                                    <th>Team Leader</th>
                                    <th>Current Job</th>
                                    <th>Availability</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($unitsMonitor as $unit)
                                    <tr>
                                        <td>
                                            <span class="monitor-cell-primary">{{ $unit['name'] }}</span>
                                            <span class="monitor-cell-secondary">{{ $unit['plate_number'] }}</span>
                                        </td>
                                        <td class="monitor-nowrap">{{ $unit['truck_type'] }}</td>
                                        <td>{{ $unit['team_leader'] === 'Unassigned' ? '—' : $unit['team_leader'] }}</td>
                                        <td class="monitor-num">{{ $unit['booking_code'] === 'No linked booking' ? '—' : $unit['booking_code'] }}</td>
                                        <td class="monitor-nowrap monitor-availability--{{ $unit['status'] }}">{{ $unit['status_label'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="monitor-empty-row">No units found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="monitor-section">
            <div class="monitor-section-head">
                <h2>Dispatcher Activity</h2>
                <a href="{{ route('superadmin.reports.activity') }}" class="monitor-link-action">View Logs →</a>
            </div>
            <div class="monitor-section-body">
                <div class="monitor-table-wrap">
                    <table class="monitor-table">
                        <thead>
                            <tr>
                                <th>Dispatcher</th>
                                <th>Last Activity</th>
                                <th>Bookings Today</th>
                                <th>Quotes Today</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($dispatchers as $dispatcher)
                                <tr>
                                    <td>{{ $dispatcher['name'] }}</td>
                                    <td>{{ $dispatcher['last_seen'] }}</td>
                                    <td class="monitor-num">{{ $dispatcher['bookings_today'] }}</td>
                                    <td class="monitor-num">{{ $dispatcher['quotes_today'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="monitor-empty-row">No dispatcher activity yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="monitor-section">
            <div class="monitor-section-head">
                <h2>Risk Watchlist</h2>
            </div>

            @if ($flaggedCustomers->isEmpty())
                <p class="monitor-empty-body">No flagged customers.</p>
            @else
                <div class="monitor-section-body">
                    <div class="monitor-table-wrap">
                        <table class="monitor-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Risk</th>
                                    <th>Reason</th>
                                    <th>Phone</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($flaggedCustomers as $customer)
                                    <tr>
                                        <td>{{ $customer['name'] }}</td>
                                        <td class="monitor-risk--{{ $customer['risk_level'] }}">{{ $customer['risk_label'] }}</td>
                                        <td>{{ $customer['reason'] }}</td>
                                        <td class="monitor-num">{{ $customer['phone'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
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
                setMonitorMetric('monitorPendingRequests', data.monitoringStats.pending_requests);
                setMonitorMetric('monitorScheduledToday', data.monitoringStats.scheduled_today);
                setMonitorMetric('monitorUnitsReady', data.monitoringStats.available_units);
                setMonitorMetric('monitorOnlineLeaders', data.monitoringStats.online_team_leaders);
                document.getElementById('monitorLastSync').textContent = 'Updated a few seconds ago';
            } catch (error) {
                document.getElementById('monitorLastSync').textContent = 'Trying to refresh again';
            }
        }

        refreshMonitoringSnapshot();
        setInterval(refreshMonitoringSnapshot, 15000);
    </script>
@endpush
