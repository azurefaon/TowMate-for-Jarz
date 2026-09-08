@extends('layouts.superadmin')

@section('title', 'Revenue')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/revenue.css') }}?v={{ filemtime(public_path('superadmin/css/revenue.css')) }}">
@endpush

@section('content')
    <div class="page-top revenue-header">
        <div>
            <h1>Revenue</h1>
            <p>Completed and collected revenue from {{ $start->format('M j') }}&ndash;{{ $end->format('M j, Y') }}.</p>
        </div>

        <div class="revenue-toolbar">
            <span class="revenue-toolbar-label">Reporting Period</span>

            <div class="revenue-period-group" role="group" aria-label="Reporting period">
                @foreach (['today' => ['Today', 'calendar'], 'week' => ['This Week', 'calendar-days'], 'month' => ['This Month', 'calendar-range'], 'quarter' => ['Quarter', 'bar-chart-2']] as $value => $meta)
                    <a href="{{ route('superadmin.revenue.index', ['period' => $value]) }}"
                        class="{{ ! $customRange && $period === $value ? 'active' : '' }}">
                        <i data-lucide="{{ $meta[1] }}"></i>
                        <span>{{ $meta[0] }}</span>
                    </a>
                @endforeach
                <button type="button" id="revenueCustomToggle" class="{{ $customRange ? 'active' : '' }}">
                    <i data-lucide="calendar-plus"></i>
                    <span>Custom</span>
                </button>
            </div>

            <form class="revenue-range-form" method="GET" action="{{ route('superadmin.revenue.index') }}"
                id="revenueRangeForm" @if (! $customRange) hidden @endif>
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
    </div>

    <div class="revenue-metric-row revenue-metric-row--primary">
        <div class="owner-kpi">
            <span class="owner-kpi-label">Completed Revenue</span>
            <div class="owner-kpi-value owner-kpi-value--primary">₱{{ number_format($financial['totalRevenue'], 2) }}</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Completed Jobs</span>
            <div class="owner-kpi-value owner-kpi-value--primary">{{ number_format($completedCount) }}</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Avg. Revenue / Job</span>
            <div class="owner-kpi-value owner-kpi-value--primary">₱{{ number_format($financial['averagePerBooking'], 2) }}</div>
        </div>
    </div>

    <div class="revenue-metric-row revenue-metric-row--secondary">
        <div class="owner-kpi">
            <span class="owner-kpi-label">VAT Collected</span>
            <div class="owner-kpi-value owner-kpi-value--secondary">₱{{ number_format($financial['vatCollected'], 2) }}</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Additional Fees</span>
            <div class="owner-kpi-value owner-kpi-value--secondary">₱{{ number_format($financial['additionalFees'], 2) }}</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Cash Received</span>
            <div class="owner-kpi-value owner-kpi-value--secondary">₱{{ number_format($financial['cashReceived'], 2) }}</div>
        </div>
    </div>

    <p class="revenue-note">Figures reflect completed, paid jobs only. Open quotations and in-progress bookings are excluded.</p>

    <div class="owner-panel revenue-trend-panel">
        <h2><i data-lucide="trending-up"></i> Revenue Trend</h2>
        @if (collect($revenueTrend)->sum('revenue') > 0)
            <canvas id="revenueTrendChartPage"></canvas>
        @else
            <div class="owner-chart-empty">
                <p>No completed revenue recorded for this period.</p>
            </div>
        @endif
    </div>

    <h2 class="revenue-section-title"><i data-lucide="bar-chart-2"></i> Revenue Breakdown</h2>

    <div class="owner-grid">
        <div class="owner-panel">
            <h3>Revenue by Truck Type</h3>
            @if ($revenueByTruckType->isNotEmpty())
                <div class="revenue-table-head">
                    <span>Truck Type</span>
                    <span>Jobs</span>
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

        <div class="owner-panel">
            <h3><i data-lucide="trophy"></i> Top Performing Units</h3>
            @if ($topUnits->isNotEmpty())
                <div class="revenue-table-head">
                    <span>Unit</span>
                    <span>Jobs</span>
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
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.getElementById('revenueCustomToggle');
            const form = document.getElementById('revenueRangeForm');
            if (toggle && form) {
                toggle.addEventListener('click', function() {
                    form.hidden = !form.hidden;
                });
            }

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
