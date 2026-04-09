@extends('admin-dashboard.layouts.app')

@section('title', 'Dispatcher Dashboard')

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('admin/css/dashboard.css') }}">
@endpush

@section('content')
    <div class="dispatcher-dashboard">
        <div class="dashboard-hero">
            <div class="hero-copy">
                <h1>Command Center</h1>
                <p>Live dispatcher view for requests, jobs, and team leader availability.</p>
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
                <div class="stat-number">{{ $activeJobs }}</div>
                <div class="stat-label">Active Jobs</div>
            </div>

            <div class="stat-card crew-card">
                <div class="stat-top">
                    <span class="stat-kicker">Leaders</span>
                    <i data-lucide="check-circle"></i>
                </div>
                <div class="stat-number">{{ $available }}</div>
                <div class="stat-label">Available Team Leaders</div>
            </div>

            <div class="stat-card busy-card">
                <div class="stat-top">
                    <span class="stat-kicker">Field</span>
                    <i data-lucide="users-round"></i>
                </div>
                <div class="stat-number">{{ $busyTeamLeadersCount }}</div>
                <div class="stat-label">Active Team Leaders</div>
            </div>

            <div class="stat-card health-card">
                <div class="stat-top">
                    <span class="stat-kicker">Focus</span>
                    <i data-lucide="triangle-alert"></i>
                </div>
                <div class="stat-number">{{ $delayed }}</div>
                <div class="stat-label">Delayed Jobs</div>
                <small class="stat-note">Readiness {{ $fleetHealth }}%</small>
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
                <h3>Quick Actions</h3>
                <div class="action-buttons">
                    <a href="{{ route('admin.dispatch') }}" class="action-btn primary">
                        <i data-lucide="inbox"></i>
                        <span>Review Requests</span>
                    </a>
                    <a href="{{ route('admin.jobs') }}" class="action-btn success">
                        <i data-lucide="briefcase-business"></i>
                        <span>Manage Jobs</span>
                    </a>
                    <a href="{{ route('admin.drivers') }}" class="action-btn info">
                        <i data-lucide="users"></i>
                        <span>View Team Leaders</span>
                    </a>
                    <a href="{{ route('admin.available-units') }}" class="action-btn warning">
                        <i data-lucide="check-square"></i>
                        <span>View Units</span>
                    </a>
                </div>
            </aside>

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

                <div class="activity-list">
                    @forelse ($incomingRequests as $request)
                        <div class="activity-item" data-type="{{ $loop->first ? 'priority' : 'request' }}">
                            <div class="activity-icon request-icon">
                                <i data-lucide="siren"></i>
                            </div>

                            <div class="activity-content">
                                <div class="activity-line">
                                    <strong>{{ $request->customer->full_name ?? 'New Request' }}</strong>
                                    <span>{{ $request->truckType->name ?? 'Tow request' }}</span>
                                </div>

                                <div class="activity-meta">
                                    <span>{{ $request->created_at->diffForHumans() }}</span>
                                    <span>{{ Str::limit($request->pickup_address, 28) }}</span>
                                    <span>{{ Str::limit($request->dropoff_address, 26) }}</span>
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


        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="{{ asset('admin/js/dashboard.js') }}"></script>
@endpush
