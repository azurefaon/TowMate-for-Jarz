@extends('layouts.superadmin')

@section('title', 'Revenue')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/revenue.css') }}?v={{ filemtime(public_path('superadmin/css/revenue.css')) }}">
@endpush

@section('content')
    <div class="page-top revenue-header">
        <div>
            <h1>Revenue</h1>
            <p>View completed and collected revenue for your selected period.</p>
        </div>

        <div class="revenue-toolbar">
            <details class="revenue-range-menu">
                <summary class="revenue-range-trigger">
                    <i data-lucide="calendar"></i>
                    <span>{{ $start->format('M j, Y') }} &ndash; {{ $end->format('M j, Y') }}</span>
                    <i data-lucide="chevron-down"></i>
                </summary>

                <div class="revenue-range-dropdown">
                    <form class="revenue-range-form" method="GET" action="{{ route('superadmin.revenue.index') }}">
                        <label>
                            <span>From</span>
                            <input type="date" name="from" value="{{ $fromInput }}">
                        </label>
                        <label>
                            <span>To</span>
                            <input type="date" name="to" value="{{ $toInput }}">
                        </label>
                        <button type="submit" class="revenue-apply-btn">Apply</button>
                    </form>
                </div>
            </details>

            <a href="{{ route('superadmin.revenue.index') }}" class="revenue-reset-btn">Reset</a>
        </div>
    </div>

    <div class="revenue-metrics">
        <div class="revenue-metric">
            <span class="owner-kpi-label">Completed Revenue</span>
            <div class="owner-kpi-value">₱{{ number_format($financial['totalRevenue'], 2) }}</div>
        </div>

        <div class="revenue-metric">
            <span class="owner-kpi-label">Completed Jobs</span>
            <div class="owner-kpi-value">{{ number_format($completedCount) }}</div>
        </div>

        <div class="revenue-metric">
            <span class="owner-kpi-label">Avg. Revenue / Job</span>
            <div class="owner-kpi-value">₱{{ number_format($financial['averagePerBooking'], 2) }}</div>
        </div>

        <div class="revenue-metric">
            <span class="owner-kpi-label">VAT Collected</span>
            <div class="owner-kpi-value">₱{{ number_format($financial['vatCollected'], 2) }}</div>
        </div>

        <div class="revenue-metric">
            <span class="owner-kpi-label">Additional Fees</span>
            <div class="owner-kpi-value">₱{{ number_format($financial['additionalFees'], 2) }}</div>
        </div>

        <div class="revenue-metric">
            <span class="owner-kpi-label">Cash Received</span>
            <div class="owner-kpi-value">₱{{ number_format($financial['cashReceived'], 2) }}</div>
        </div>
    </div>

    <p class="revenue-note">Figures reflect completed, paid jobs only. Open quotations and in-progress bookings are excluded.</p>

    <div class="owner-grid revenue-grid-primary">
        <div class="owner-panel revenue-trend-panel">
            <h2>Revenue Trend</h2>
            @if (collect($revenueTrend)->sum('revenue') > 0)
                <canvas id="revenueTrendChartPage"></canvas>
            @else
                <div class="owner-chart-empty">
                    <p>No completed revenue recorded for this period.</p>
                </div>
            @endif
        </div>

        <div class="owner-panel">
            <h2>Revenue Breakdown</h2>
            @if ($revenueByTruckType->isNotEmpty())
                <div class="revenue-table-head">
                    <span>Truck Type</span>
                    <span>Completed Jobs</span>
                    <span>Revenue</span>
                </div>
                @foreach ($revenueByTruckType as $row)
                    <div class="revenue-table-row">
                        <span>{{ $row['truck_type_name'] }}</span>
                        <span>{{ $row['trips'] }}</span>
                        <span>₱{{ number_format($row['revenue'], 2) }}</span>
                    </div>
                @endforeach
            @else
                <p class="owner-empty">No completed jobs recorded for this period.</p>
            @endif
        </div>
    </div>

    <div class="owner-panel revenue-top-units-panel">
        <h2>Top Performing Units</h2>
        @if ($topUnits->isNotEmpty())
            <div class="revenue-table-head">
                <span>Unit Name</span>
                <span>Completed Jobs</span>
                <span>Revenue</span>
            </div>
            @foreach ($topUnits as $row)
                <div class="revenue-table-row">
                    <span>{{ $row['unit_name'] }}</span>
                    <span>{{ $row['trips'] }}</span>
                    <span>₱{{ number_format($row['revenue'], 2) }}</span>
                </div>
            @endforeach
        @else
            <p class="owner-empty">No completed unit activity recorded for this period.</p>
        @endif
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const canvas = document.getElementById('revenueTrendChartPage');
            if (canvas) {
                const revenueData = @json(collect($revenueTrend)->pluck('revenue'));
                const pointSizes = revenueData.map(value => value > 0 ? 3 : 0);
                const pointHoverSizes = revenueData.map(value => value > 0 ? 5 : 3);

                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: @json(collect($revenueTrend)->pluck('label')),
                        datasets: [{
                            label: 'Revenue',
                            data: revenueData,
                            borderColor: '#171717',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false,
                            pointBackgroundColor: '#facc15',
                            pointBorderColor: '#facc15',
                            pointRadius: pointSizes,
                            pointHoverRadius: pointHoverSizes,
                        }]
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
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
                                ticks: { autoSkip: true, maxTicksLimit: 10 },
                            },
                        }
                    }
                });
            }
        });
    </script>
@endpush
