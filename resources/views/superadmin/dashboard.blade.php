@extends('layouts.superadmin')

@section('title', 'Dashboard')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/dashboard.css') }}?v={{ filemtime(public_path('superadmin/css/dashboard.css')) }}">
@endpush

@section('content')
    <div class="page-top">
        <div>
            <h1>Dashboard</h1>
            <p>Business overview for {{ $periodLabel }}.</p>
        </div>
    </div>

    <div class="od-summary-grid">
        <div class="od-summary-card">
            <span class="od-summary-label">Monthly Revenue</span>
            <div class="od-summary-value">₱{{ number_format($revenueThisMonth, 2) }}</div>
            <span class="od-summary-note">{{ $periodLabel }}</span>
        </div>

        <div class="od-summary-card">
            <span class="od-summary-label">Completed Jobs</span>
            <div class="od-summary-value">{{ number_format($completedJobsCount) }}</div>
            <span class="od-summary-note">This month</span>
        </div>

        <div class="od-summary-card">
            <span class="od-summary-label">Active Jobs</span>
            <div class="od-summary-value">{{ number_format($activeJobsCount) }}</div>
            <span class="od-summary-note">Currently in progress</span>
        </div>

        <div class="od-summary-card">
            <span class="od-summary-label">Available Units</span>
            <div class="od-summary-value">{{ number_format($availableUnitsCount) }}</div>
            <span class="od-summary-note">Ready for dispatch</span>
        </div>
    </div>

    <div class="od-row">
        <div class="od-panel">
            <div class="od-panel-header">
                <h2>Operations Overview</h2>
            </div>

            <div class="od-overview-list">
                <a href="{{ route('superadmin.monitoring.index') }}" class="od-overview-row">
                    <span>Pending Bookings</span>
                    <span class="od-overview-count">{{ number_format($pendingBookingsCount) }}</span>
                </a>
                <a href="{{ route('superadmin.monitoring.index') }}" class="od-overview-row">
                    <span>Scheduled Bookings</span>
                    <span class="od-overview-count">{{ number_format($scheduledBookingsCount) }}</span>
                </a>
                <a href="{{ route('superadmin.monitoring.index') }}" class="od-overview-row">
                    <span>Active Jobs</span>
                    <span class="od-overview-count">{{ number_format($activeJobsCount) }}</span>
                </a>
                <a href="{{ route('superadmin.monitoring.index') }}" class="od-overview-row">
                    <span>Jobs for Verification</span>
                    <span class="od-overview-count">{{ number_format($verificationBookingsCount) }}</span>
                </a>
                <a href="{{ route('superadmin.monitoring.index') }}" class="od-overview-row">
                    <span>Returned Jobs</span>
                    <span class="od-overview-count">{{ number_format($returnedJobsCount) }}</span>
                </a>
            </div>
        </div>

        <div class="od-panel">
            <div class="od-panel-header">
                <h2>Revenue Trend</h2>
                <span class="od-panel-note">Last 7 days</span>
            </div>
            <canvas id="revenueTrendChart" class="od-chart"></canvas>
        </div>
    </div>

    <div class="od-row">
        <div class="od-panel">
            <div class="od-panel-header">
                <h2>Recent Activity</h2>
                <a href="{{ route('superadmin.reports.activity') }}" class="od-panel-link">View All</a>
            </div>

            <div class="od-activity-list">
                @forelse ($recentActivity as $activity)
                    <div class="od-activity-row">
                        <div>
                            <strong>{{ $activity['title'] }}</strong>
                            <span class="od-activity-sub">{{ $activity['reference'] }}</span>
                        </div>
                        <span class="od-activity-time">{{ $activity['created_at']?->format('M j, Y g:i A') }}</span>
                    </div>
                @empty
                    <p class="od-empty">No recent activity recorded yet.</p>
                @endforelse
            </div>
        </div>

        <div class="od-panel">
            <div class="od-panel-header">
                <h2>Quick Access</h2>
            </div>

            <div class="od-quick-grid">
                <a href="{{ route('superadmin.bookings.index') }}" class="od-quick-item">
                    <strong>Manage Bookings</strong>
                    <span>View and handle all bookings</span>
                </a>
                <a href="{{ route('superadmin.unit-truck.index') }}" class="od-quick-item">
                    <strong>Manage Units</strong>
                    <span>View and update units</span>
                </a>
                <a href="{{ route('superadmin.personnel.index') }}" class="od-quick-item">
                    <strong>Manage Personnel</strong>
                    <span>Add or edit personnel records</span>
                </a>
                <a href="{{ route('superadmin.reports.index') }}" class="od-quick-item">
                    <strong>View Reports</strong>
                    <span>Revenue, performance and more</span>
                </a>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const revenueTrendCanvas = document.getElementById('revenueTrendChart');
            if (revenueTrendCanvas) {
                new Chart(revenueTrendCanvas, {
                    type: 'line',
                    data: {
                        labels: @json($revenueTrendLabels),
                        datasets: [{
                            label: 'Revenue',
                            data: @json($revenueTrend),
                            borderColor: '#171717',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false,
                            pointBackgroundColor: '#facc15',
                            pointBorderColor: '#facc15',
                            pointRadius: 3,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: { color: '#f0f0f0' },
                                border: { display: false },
                            },
                            x: {
                                grid: { display: false },
                                border: { display: false },
                            },
                        }
                    }
                });
            }
        });
    </script>
@endpush
