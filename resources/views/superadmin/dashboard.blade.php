@extends('layouts.superadmin')

@section('title', 'Dashboard')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/dashboard.css') }}">
@endpush

@section('content')
    <div class="dashboard-header">

        <div>
            <h1>Jarz Owner Overview</h1>
            <p>Bookings, revenue, and operational health in one clean analytics view.</p>
        </div>

        <div class="dashboard-meta">
            <div class="date-box">
                <i data-lucide="calendar"></i>
                {{ now()->format('F d, Y') }}
            </div>
        </div>

    </div>


    <div class="dashboard-overview">
        <div class="stats-grid">

            <div class="stat-card">
                <div class="stat-icon users">
                    <i data-lucide="users"></i>
                </div>
                <div>
                    <span>Total Users</span>
                    <h2 class="counter" data-target="{{ $totalUsers }}">0</h2>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bookings">
                    <i data-lucide="clipboard-list"></i>
                </div>
                <div>
                    <span>Total Bookings</span>
                    <h2 class="counter" data-target="{{ $totalBookings }}">0</h2>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon revenue">
                    <i data-lucide="banknote"></i>
                </div>
                <div>
                    <span>Revenue Tracked</span>
                    <h2>₱{{ number_format($totalRevenue, 2) }}</h2>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon trucks">
                    <i data-lucide="truck"></i>
                </div>
                <div>
                    <span>Active Units</span>
                    <h2 class="counter" data-target="{{ $activeUnits }}">0</h2>
                </div>
            </div>

        </div>

        <div class="metric-grid">

            <div class="metric-card metric-bookings">

                <div class="metric-header">
                    <span>TODAY'S BOOKINGS</span>

                    <div class="metric-icon">
                        <i data-lucide="package"></i>
                    </div>
                </div>

                <div class="metric-value" id="todayBookings">{{ $todayBookings }}</div>

                <div class="metric-desc">
                    @if ($todayBookings == 0)
                        No bookings yet today
                    @else
                        Active booking requests
                    @endif
                </div>

                <div class="metric-chart">
                    <svg viewBox="0 0 300 80">
                        <path d="M0 70 C60 40 120 60 180 45 C240 30 260 50 300 40 L300 80 L0 80 Z" />
                    </svg>
                </div>

            </div>

            <div class="metric-card metric-completed">

                <div class="metric-header">
                    <span>COMPLETED TODAY</span>

                    <div class="metric-icon">
                        <i data-lucide="check-circle"></i>
                    </div>
                </div>

                <div class="metric-value" id="completedToday">{{ $completedToday }}</div>

                <div class="metric-desc">
                    @if ($completedToday == 0)
                        No completions recorded
                    @else
                        Successful dispatches
                    @endif
                </div>

                <div class="metric-chart">
                    <svg viewBox="0 0 300 80">
                        <path d="M0 65 C80 55 140 35 200 55 C240 70 260 40 300 45 L300 80 L0 80 Z" />
                    </svg>
                </div>

            </div>


            <div class="metric-card metric-pending">

                <div class="metric-header">
                    <span>PENDING REVIEW</span>

                    <div class="metric-icon">
                        <i data-lucide="hourglass"></i>
                    </div>
                </div>

                <div class="metric-value" id="pendingBookingsMetric">{{ $pendingBookings }}</div>

                <div class="metric-desc">
                    @if ($pendingBookings == 0)
                        Dispatch queues are clear right now
                    @else
                        Customer requests are waiting for review
                    @endif
                </div>

                <div class="metric-chart">
                    <svg viewBox="0 0 300 80">
                        <path d="M0 60 C60 55 120 70 180 65 C220 55 260 50 300 55 L300 80 L0 80 Z" />
                    </svg>
                </div>

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
                        <div class="activity-item">

                            <div class="activity-icon">

                                @if (str_contains(strtolower($activity->action), 'completed'))
                                    <i data-lucide="check-circle"></i>
                                @elseif(str_contains(strtolower($activity->action), 'cancel'))
                                    <i data-lucide="x-circle"></i>
                                @elseif(str_contains(strtolower($activity->action), 'user'))
                                    <i data-lucide="user-plus"></i>
                                @elseif(str_contains(strtolower($activity->action), 'unit'))
                                    <i data-lucide="truck"></i>
                                @elseif(str_contains(strtolower($activity->action), 'setting'))
                                    <i data-lucide="settings"></i>
                                @else
                                    <i data-lucide="activity"></i>
                                @endif

                            </div>

                            <div class="activity-text">

                                <strong>

                                    @if (str_contains(strtolower($activity->action), 'booking') && str_contains(strtolower($activity->action), 'completed'))
                                        Booking #{{ $activity->reference ?? '' }} Completed
                                    @elseif(str_contains(strtolower($activity->action), 'booking') && str_contains(strtolower($activity->action), 'cancel'))
                                        Booking #{{ $activity->reference ?? '' }} Cancelled
                                    @elseif(str_contains(strtolower($activity->action), 'user'))
                                        New User Registered
                                    @elseif(str_contains(strtolower($activity->action), 'unit'))
                                        Unit {{ $activity->reference ?? '' }} Activated
                                    @elseif(str_contains(strtolower($activity->action), 'setting'))
                                        Settings Updated
                                    @else
                                        {{ ucfirst($activity->action) }}
                                    @endif

                                </strong>

                                <span>

                                    @if (str_contains(strtolower($activity->action), 'user'))
                                        {{ $activity->reference }} · {{ $activity->description }}
                                    @elseif(str_contains(strtolower($activity->action), 'unit'))
                                        {{ $activity->description }} · Ready
                                    @elseif(str_contains(strtolower($activity->action), 'booking'))
                                        {{ $activity->description }}
                                    @elseif(str_contains(strtolower($activity->action), 'setting'))
                                        {{ $activity->description }}
                                    @else
                                        {{ $activity->description }}
                                    @endif

                                </span>

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
        lucide.createIcons();
        updateDashboard();
    </script>

    <script>
        document.addEventListener("DOMContentLoaded", function() {

            const counters = document.querySelectorAll('.counter');

            counters.forEach(counter => {

                counter.innerText = '0';

                const updateCounter = () => {

                    const target = +counter.getAttribute('data-target');
                    const current = +counter.innerText;
                    const increment = target / 50;

                    if (current < target) {
                        counter.innerText = Math.ceil(current + increment);
                        setTimeout(updateCounter, 20);
                    } else {
                        counter.innerText = target;
                    }

                };

                updateCounter();

            });

        });

        const ctx = document.getElementById('bookingChart');

        const bookingChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Bookings',
                    data: [],
                    borderColor: '#facc15',
                    backgroundColor: 'rgba(250, 204, 21, 0.22)',
                    tension: 0.4,
                    fill: true
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
                        beginAtZero: true
                    }
                }
            }
        });

        function updateDashboard() {

            fetch("{{ route('superadmin.dashboard.stats') }}")
                .then(res => res.json())
                .then(data => {

                    const todayBookings = document.getElementById('todayBookings');
                    const completedToday = document.getElementById('completedToday');
                    const pendingBookingsMetric = document.getElementById('pendingBookingsMetric');

                    if (todayBookings) todayBookings.innerText = data.todayBookings ?? 0;
                    if (completedToday) completedToday.innerText = data.completedToday ?? 0;
                    if (pendingBookingsMetric) pendingBookingsMetric.innerText = data.pendingBookings ?? 0;

                    bookingChart.data.datasets[0].data = data.weekBookings;
                    bookingChart.update();

                });

        }

        setInterval(updateDashboard, 5000);

        updateDashboard();
    </script>
@endpush
