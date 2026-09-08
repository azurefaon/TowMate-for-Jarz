@extends('layouts.superadmin')

@section('title', 'Dashboard')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/dashboard.css') }}?v={{ filemtime(public_path('superadmin/css/dashboard.css')) }}">
@endpush

@section('content')
    <div class="page-top">
        <div>
            <h1>Dashboard</h1>
            <p>Business performance for {{ $periodLabel }}.</p>
        </div>
    </div>

    <div class="owner-kpi-row">
        <div class="owner-kpi">
            <span class="owner-kpi-label">Revenue This Month</span>
            <div class="owner-kpi-value">₱{{ number_format($revenueThisMonth, 2) }}</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Completed Jobs</span>
            <div class="owner-kpi-value">{{ number_format($completedJobsCount) }}</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Avg. Revenue / Job</span>
            <div class="owner-kpi-value">₱{{ number_format($averageRevenuePerJob, 2) }}</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Cancellation Rate</span>
            <div class="owner-kpi-value">{{ number_format($cancellationRate, 1) }}%</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Fleet Utilization</span>
            <div class="owner-kpi-value">{{ number_format($fleetUtilization, 1) }}%</div>
        </div>
    </div>

    <div class="owner-grid owner-grid--analytics">
        <div class="owner-panel">
            <h2>Revenue Trend (7 days)</h2>
            <canvas id="revenueTrendChart"></canvas>
        </div>

        <div class="owner-panel">
            <h2>Bookings This Week</h2>
            @if (array_sum($weekBookings) > 0)
                <canvas id="bookingChart"></canvas>
            @else
                <div class="owner-chart-empty">
                    <p>No booking activity recorded this week.</p>
                </div>
            @endif
        </div>
    </div>

    <div class="owner-grid owner-grid--summary">
        <div class="owner-panel">
            <h2>Revenue by Truck Type</h2>
            @forelse ($revenueByTruckType as $row)
                <div class="owner-list-row">
                    <span>{{ $row['name'] }}</span>
                    <span>₱{{ number_format($row['revenue'], 2) }}</span>
                </div>
            @empty
                <p class="owner-empty">No completed jobs this month yet.</p>
            @endforelse
        </div>

        <div class="owner-panel">
            <h2>Top Performing Units</h2>
            @forelse ($topUnits as $row)
                <div class="owner-list-row">
                    <span>{{ $row['name'] }}</span>
                    <span class="owner-list-row-stat">
                        {{ $row['trips'] }} trips<br>
                        ₱{{ number_format($row['revenue'], 2) }}
                    </span>
                </div>
            @empty
                <p class="owner-empty">No completed jobs this month yet.</p>
            @endforelse
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
                        labels: [6, 5, 4, 3, 2, 1, 0].map(d => {
                            const date = new Date();
                            date.setDate(date.getDate() - d);
                            return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                        }),
                        datasets: [{
                            label: 'Revenue',
                            data: @json($revenueTrend),
                            borderColor: '#171717',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false,
                            pointBackgroundColor: '#facc15',
                            pointBorderColor: '#facc15',
                            pointRadius: 4,
                        }]
                    },
                    options: {
                        responsive: true,
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

            const bookingCanvas = document.getElementById('bookingChart');
            if (bookingCanvas) {
                new Chart(bookingCanvas, {
                    type: 'bar',
                    data: {
                        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                        datasets: [{
                            label: 'Bookings',
                            data: @json($weekBookings),
                            backgroundColor: '#facc15',
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { stepSize: 1 },
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

            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    </script>
@endpush
