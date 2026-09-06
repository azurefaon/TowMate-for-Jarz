@extends('admin-dashboard.layouts.app')

@section('title', 'Dashboard')

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('admin/css/dashboard.css') }}">
@endpush

@section('content')
    <div class="dispatcher-dashboard" id="dispatcherDashboard" data-live-overview-url="{{ route('admin.live-overview') }}">

        <div class="dash-header">
            <h1>Dashboard</h1>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number" id="incomingCount">{{ $pendingRequests }}</div>
                <div class="stat-label">Requests</div>
            </div>

            <div class="stat-card">
                <div class="stat-number" id="activeJobsCount">{{ $activeJobs }}</div>
                <div class="stat-label">Active Jobs</div>
            </div>

            <div class="stat-card">
                <div class="stat-number" id="availableLeadersCount">{{ $available }}</div>
                <div class="stat-label">Team Leaders Available</div>
            </div>

            <div class="stat-card">
                <div class="stat-number" id="scheduledQueueCount">{{ $scheduledTodayCount + $upcomingScheduledCount }}</div>
                <div class="stat-label">Scheduled</div>
            </div>
        </div>

        <div class="dashboard-main-grid">
            <section class="panel">
                <div class="panel-header">
                    <h3>Job Status</h3>
                </div>

                <div class="chart-body">
                    <div class="chart-wrap">
                        <canvas id="performanceChart" data-completed="{{ $chartData['completed'] }}"
                            data-assigned="{{ $chartData['assigned'] }}"
                            data-pending="{{ $chartData['pending'] }}"></canvas>
                        <div class="chart-center">
                            <div class="chart-center-number" id="totalJobsCount">{{ array_sum($chartData) }}</div>
                            <div class="chart-center-label">Total Jobs</div>
                        </div>
                    </div>

                    <ul class="chart-legend-list">
                        <li>
                            <span class="legend-dot assigned"></span>
                            <span class="legend-name">Assigned</span>
                            <span class="legend-count" id="legendAssigned">{{ $chartData['assigned'] }}</span>
                        </li>
                        <li>
                            <span class="legend-dot completed"></span>
                            <span class="legend-name">Completed</span>
                            <span class="legend-count" id="legendCompleted">{{ $chartData['completed'] }}</span>
                        </li>
                        <li>
                            <span class="legend-dot pending"></span>
                            <span class="legend-name">Pending</span>
                            <span class="legend-count" id="legendPending">{{ $chartData['pending'] }}</span>
                        </li>
                    </ul>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h3>Incoming Requests</h3>
                    <a href="{{ route('admin.dispatch') }}" class="panel-link">View All</a>
                </div>

                <div class="simple-list" id="incomingRequestList">
                    @forelse ($incomingRequests as $request)
                        <div class="simple-row">
                            <div class="simple-row-top">
                                <strong>{{ $request['customer_name'] }}</strong>
                                <span class="badge-pending">Pending</span>
                            </div>
                            <div class="simple-row-sub">{{ $request['truck_type'] }} &middot;
                                {{ $request['booking_code'] }}</div>
                            <div class="simple-row-time">{{ $request['created_at_human'] }}</div>
                        </div>
                    @empty
                        <p class="empty-note">No pending requests right now.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="panel table-panel">
            <div class="panel-header">
                <h3>Current Activity</h3>
                <a href="{{ route('admin.jobs') }}" class="panel-link">View All</a>
            </div>

            <div class="table-scroll">
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>Booking #</th>
                            <th>Status</th>
                            <th>Unit</th>
                            <th>Customer</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody id="currentActivityList">
                        @forelse ($currentActivities as $activity)
                            <tr>
                                <td>{{ $activity['booking_code'] }}</td>
                                <td>{{ $activity['status'] }}</td>
                                <td>{{ $activity['unit_name'] }} &middot; {{ $activity['unit_plate'] }}</td>
                                <td>{{ $activity['customer_name'] }}</td>
                                <td>{{ $activity['updated_at_human'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="empty-note">No jobs are active right now.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="{{ asset('admin/js/dashboard.js') }}"></script>
@endpush
