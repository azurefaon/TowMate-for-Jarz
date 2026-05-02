@extends('layouts.superadmin')

@section('title', 'Dashboard')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/dashboard.css') }}">
@endpush

@section('content')
    <div class="dashboard-header">

        <div>
            <h1>Jarz Owner Overview</h1>
            <p>Bookings, revenue, and operational view.</p>
        </div>

        <div class="dashboard-meta">
            <div class="date-box">
                {{-- <i data-lucide="calendar"></i> --}}
                {{ now()->format('F d, Y') }}
            </div>
        </div>

    </div>


    <div class="dashboard-overview">

        <div class="kpi-row">

            <div class="kpi-card">
                <span class="kpi-label">Total Users</span>
                <div class="kpi-value counter" data-target="{{ $totalUsers }}">0</div>
            </div>

            <div class="kpi-card">
                <span class="kpi-label">Total Bookings</span>
                <div class="kpi-value counter" data-target="{{ $totalBookings }}">0</div>
            </div>

            <div class="kpi-card">
                <span class="kpi-label">Revenue Tracked</span>
                <div class="kpi-value">₱{{ number_format($totalRevenue, 2) }}</div>
            </div>

            <div class="kpi-card">
                <span class="kpi-label">Active Units</span>
                <div class="kpi-value counter" data-target="{{ $activeUnits }}">0</div>
            </div>

            <div class="kpi-card kpi-accent-yellow">
                <span class="kpi-label">Today's Bookings</span>
                <div class="kpi-value" id="todayBookings">{{ $todayBookings }}</div>
            </div>

            <div class="kpi-card kpi-accent-green">
                <span class="kpi-label">Completed Today</span>
                <div class="kpi-value" id="completedToday">{{ $completedToday }}</div>
            </div>

            <div class="kpi-card">
                <span class="kpi-label">Pending Review</span>
                <div class="kpi-value" id="pendingBookingsMetric">{{ $pendingBookings }}</div>
            </div>

        </div>



        <div class="dashboard-grid">

            <div class="chart-section">
                <h2>Bookings This Week</h2>
                <canvas id="bookingChart"></canvas>
            </div>

            <div class="activity-card">

                <div class="activity-header">
                    <h2>Recent Activity</h2>
                    <a href="{{ route('superadmin.audit.logs') }}">View all →</a>
                </div>

                <div class="activity-list">

                    @forelse($recentActivities as $activity)
                        @php
                            $act = strtolower($activity->action);
                            if (str_contains($act, 'booking') && str_contains($act, 'completed')) {
                                $title = 'Booking Completed';
                            } elseif (str_contains($act, 'booking') && str_contains($act, 'cancel')) {
                                $title = 'Booking Cancelled';
                            } else {
                                $title = ucwords(str_replace('_', ' ', $activity->action));
                            }
                        @endphp
                        <div class="activity-item">

                            <div class="activity-text">
                                <strong>{{ $title }}</strong>
                                <span>{{ $activity->description }}</span>
                            </div>

                            <div class="activity-time">
                                {{ $activity->created_at->diffForHumans() }}
                            </div>

                        </div>

                    @empty
                        <p class="no-activity">No recent activity</p>
                    @endforelse

                </div>

            </div>

        </div>

    </div>
@endsection



@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // — Counters —
            document.querySelectorAll('.counter').forEach(counter => {
                counter.innerText = '0';
                const updateCounter = () => {
                    const target = +counter.getAttribute('data-target');
                    const current = +counter.innerText;
                    const increment = Math.ceil(target / 50) || 1;
                    if (current < target) {
                        counter.innerText = Math.min(current + increment, target);
                        setTimeout(updateCounter, 20);
                    } else {
                        counter.innerText = target;
                    }
                };
                updateCounter();
            });

            // — Booking chart (initial data from server) —
            const ctx = document.getElementById('bookingChart');
            const bookingChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    datasets: [{
                        label: 'Bookings',
                        data: @json($weekBookings),
                        borderColor: '#facc15',
                        backgroundColor: 'rgba(250, 204, 21, 0.22)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#facc15',
                        pointRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });

            // — Live stats refresh —
            function updateDashboard() {
                fetch('{{ route('superadmin.dashboard.stats') }}')
                    .then(res => res.json())
                    .then(data => {
                        const set = (id, val) => {
                            const el = document.getElementById(id);
                            if (el) el.innerText = val ?? 0;
                        };
                        set('todayBookings', data.todayBookings);
                        set('completedToday', data.completedToday);
                        set('pendingBookingsMetric', data.pendingBookings);

                        // Update metric descriptions
                        const pendingDesc = document.getElementById('pendingDesc');
                        if (pendingDesc) {
                            pendingDesc.innerText = (data.pendingBookings == 0) ?
                                'Dispatch queues are clear right now' :
                                'Customer requests are waiting for review';
                        }

                        bookingChart.data.datasets[0].data = data.weekBookings;
                        bookingChart.update();
                    })
                    .catch(() => {}); // silent fail on network issues
            }

            updateDashboard();
            setInterval(updateDashboard, 10000);

            // — Lucide icons —
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    </script>
@endpush
