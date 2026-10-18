@extends('layouts.superadmin')

@section('title', 'Revenue')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
@endpush

@section('content')
    <div class="ob-page oa-page" id="ownerRevenue">

        <header class="ob-header">
            <h1>Revenue</h1>
            <p>View completed and collected revenue for your selected period.</p>
        </header>

        <form method="GET" action="{{ route('superadmin.revenue.index') }}" class="oa-card oa-filter-card" role="search">
            {{-- Filters the page already honours through the URL are carried through Apply. --}}
            @foreach (['truck_type_id', 'min_amount', 'max_amount'] as $carried)
                @if (request()->filled($carried))
                    <input type="hidden" name="{{ $carried }}" value="{{ request()->query($carried) }}">
                @endif
            @endforeach

            <div class="oa-filters">
                <div class="oa-field">
                    <span class="oa-field-label">Reporting period</span>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        @include('superadmin.analytics._range', [
                            'label' => $start->format('M j, Y') . ' – ' . $end->format('M j, Y'),
                            'from' => $fromInput,
                            'to' => $toInput,
                        ])
                        <a href="{{ route('superadmin.revenue.index') }}" class="oa-btn">Reset</a>
                    </div>
                </div>
            </div>
        </form>

        <div class="oa-kpis oa-kpis--six" style="--oa-kpi-count: 6;">
            <div class="oa-kpi is-dark">
                <span class="oa-kpi-icon"><i data-lucide="banknote" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Completed revenue, by completion date</span>
                <div class="oa-kpi-value">₱{{ number_format($financial['totalRevenue'], 2) }}</div>
            </div>

            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="circle-check" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Completed Jobs</span>
                <div class="oa-kpi-value">{{ number_format($completedCount) }}</div>
            </div>

            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="divide" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Avg. Revenue / Job</span>
                <div class="oa-kpi-value">₱{{ number_format($financial['averagePerBooking'], 2) }}</div>
            </div>

            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="percent" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">VAT Collected</span>
                <div class="oa-kpi-value">₱{{ number_format($financial['vatCollected'], 2) }}</div>
            </div>

            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="circle-plus" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Additional Fees</span>
                <div class="oa-kpi-value">₱{{ number_format($financial['additionalFees'], 2) }}</div>
            </div>

            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="wallet" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Cash Received</span>
                <div class="oa-kpi-value">₱{{ number_format($financial['cashReceived'], 2) }}</div>
            </div>
        </div>

        <p class="oa-note">
            <i data-lucide="info" aria-hidden="true"></i>
            <span>Completed revenue, by completion date: completed, paid jobs only, with group fees and discounts applied. Open quotations and in-progress bookings are excluded. Amount filters apply to each booking's total.</span>
        </p>

        <div class="oa-grid oa-grid--revenue">
            <section class="oa-card oa-card-body" aria-labelledby="revTrendTitle">
                <div class="oa-card-head">
                    <div>
                        <h2 class="oa-card-title" id="revTrendTitle"><i data-lucide="trending-up" aria-hidden="true"></i> Revenue Trend</h2>
                        <p class="oa-card-sub">Completed revenue per day</p>
                    </div>
                </div>

                @if (collect($revenueTrend)->sum('revenue') > 0)
                    <div class="oa-chart"><canvas id="revenueTrendChartPage" aria-label="Completed revenue per day" role="img"></canvas></div>
                @else
                    <div class="oa-empty oa-empty--tall">No completed revenue recorded for this period.</div>
                @endif
            </section>

            <section class="oa-card oa-card-body" aria-labelledby="revBreakdownTitle">
                <div class="oa-card-head">
                    <div>
                        <h2 class="oa-card-title" id="revBreakdownTitle"><i data-lucide="truck" aria-hidden="true"></i> Revenue Breakdown</h2>
                        <p class="oa-card-sub">By truck type</p>
                    </div>
                </div>

                @if ($revenueByTruckType->isNotEmpty())
                    <div class="oa-table-wrap">
                        <table class="oa-table">
                            <thead>
                                <tr>
                                    <th scope="col">Truck Type</th>
                                    <th scope="col" class="is-num">Completed Jobs</th>
                                    <th scope="col" class="is-num">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($revenueByTruckType as $row)
                                    <tr>
                                        <td>{{ $row['truck_type_name'] }}</td>
                                        <td class="is-num">{{ $row['trips'] }}</td>
                                        <td class="is-num">₱{{ number_format($row['revenue'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="oa-empty">No completed jobs recorded for this period.</div>
                @endif
            </section>
        </div>

        <section class="oa-card oa-card-body" aria-labelledby="revUnitsTitle">
            <div class="oa-card-head">
                <div>
                    <h2 class="oa-card-title" id="revUnitsTitle"><i data-lucide="trophy" aria-hidden="true"></i> Top Performing Units</h2>
                    <p class="oa-card-sub">Top 5 units</p>
                </div>
            </div>

            @if ($topUnits->isNotEmpty())
                <div class="oa-table-wrap">
                    <table class="oa-table">
                        <thead>
                            <tr>
                                <th scope="col">Unit Name</th>
                                <th scope="col" class="is-num">Completed Jobs</th>
                                <th scope="col" class="is-num">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topUnits as $row)
                                <tr>
                                    <td>{{ $row['unit_name'] }}</td>
                                    <td class="is-num">{{ $row['trips'] }}</td>
                                    <td class="is-num">₱{{ number_format($row['revenue'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="oa-empty">No completed unit activity recorded for this period.</div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/analytics.js') }}?v={{ filemtime(public_path('superadmin/js/analytics.js')) }}" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const canvas = document.getElementById('revenueTrendChartPage');
            if (canvas) {
                const labels = @json(collect($revenueTrend)->pluck('label'));
                const revenueData = @json(collect($revenueTrend)->pluck('revenue'));
                const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2 });
                const pointSizes = revenueData.map(value => value > 0 ? 4 : 0);
                const pointHoverSizes = revenueData.map(value => value > 0 ? 6 : 3);

                Chart.defaults.font.family = '"JARZ Inter", "Inter", "Segoe UI", Arial, sans-serif';
                Chart.defaults.font.size = 13;
                Chart.defaults.color = '#525252';

                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Revenue',
                            data: revenueData,
                            borderColor: '#171717',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false,
                            pointBackgroundColor: '#facc15',
                            pointBorderColor: '#171717',
                            pointBorderWidth: 1.5,
                            pointRadius: pointSizes,
                            pointHoverRadius: pointHoverSizes,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        layout: { padding: { left: 6 } },
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#171717',
                                displayColors: false,
                                padding: 10,
                                cornerRadius: 8,
                                callbacks: {
                                    title: function () { return ''; },
                                    label: function (context) { return context.label + ' · ' + peso.format(context.parsed.y); },
                                },
                            },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: { color: '#f0f0f0' },
                                border: { display: false },
                                ticks: {
                                    callback: function (value) { return '₱' + Number(value).toLocaleString('en-PH'); },
                                },
                            },
                            x: {
                                grid: { display: false },
                                border: { display: false },
                                ticks: { autoSkip: true, maxTicksLimit: 8 },
                            },
                        }
                    }
                });
            }
        });
    </script>
@endpush
