@extends('layouts.superadmin')

@section('title', 'Reports')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
@endpush

@section('content')
    @php
        $pdfBaseParams = array_filter(array_merge($filters, $customRange ? ['from' => $fromInput, 'to' => $toInput] : ['period' => $period]));
        $bucketLinkParams = fn (string $bucket) => array_filter(array_merge($filters, [
            'bucket' => $bucket,
            'period' => ! $customRange ? $period : null,
            'from' => $customRange ? $fromInput : null,
            'to' => $customRange ? $toInput : null,
        ]));
        $rangeLabel = $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
    @endphp

    <div class="ob-page oa-page" id="ownerReports">

        <header class="ob-header">
            <h1>Reports</h1>
            <p>View booking and financial performance.</p>
        </header>

        <form method="GET" action="{{ route('superadmin.reports.index') }}" class="oa-card oa-filter-card" id="reportsFilters" role="search">
            @foreach (['min_amount', 'max_amount'] as $carried)
                @if (($filters[$carried] ?? null) !== null)
                    <input type="hidden" name="{{ $carried }}" value="{{ $filters[$carried] }}">
                @endif
            @endforeach

            <div class="oa-filters">
                <div class="oa-field">
                    <span class="oa-field-label">Reporting period</span>
                    @include('superadmin.analytics._range', ['label' => $rangeLabel, 'from' => $start->toDateString(), 'to' => $end->toDateString()])
                </div>

                <div class="oa-field oa-select">
                    <label for="reportsTruckTypeSelect">Truck type</label>
                    <select id="reportsTruckTypeSelect" name="truck_type_id" data-custom>
                        <option value="">All Truck Types</option>
                        @foreach ($truckTypes as $truckType)
                            <option value="{{ $truckType->id }}" {{ (int) ($filters['truck_type_id'] ?? 0) === $truckType->id ? 'selected' : '' }}>
                                {{ $truckType->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </form>

        <div class="oa-kpis" style="--oa-kpi-count: 5;">
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="clipboard-list" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Total Bookings</span>
                <div class="oa-kpi-value">{{ number_format($totalBookings) }}</div>
                <span class="oa-kpi-sub">Created in period</span>
            </div>

            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="circle-check" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Completed Jobs (booked in period)</span>
                <div class="oa-kpi-value is-positive">{{ number_format($completedCount) }}</div>
                <span class="oa-kpi-sub">Created in period</span>
            </div>

            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="circle-x" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Cancelled Jobs</span>
                <div class="oa-kpi-value is-negative">{{ number_format($cancelledCount) }}</div>
                <span class="oa-kpi-sub">Created in period</span>
            </div>

            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="percent" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Completion Rate</span>
                <div class="oa-kpi-value">{{ number_format($completionRate, 1) }}%</div>
                <span class="oa-kpi-sub">Created in period</span>
            </div>

            <div class="oa-kpi is-dark">
                <span class="oa-kpi-icon"><i data-lucide="banknote" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Completed revenue, by completion date</span>
                <div class="oa-kpi-value">₱{{ number_format($totalRevenue, 2) }}</div>
                <span class="oa-kpi-sub">Completed in period</span>
            </div>
        </div>

        <p class="oa-note">
            <i data-lucide="info" aria-hidden="true"></i>
            <span>Bookings, completed and cancelled jobs, and completion rate count bookings <strong>created</strong> in the period. Revenue counts jobs <strong>completed</strong> in the period, with group fees and discounts applied, and matches the Revenue page and Dashboard. Amount filters apply to each booking's total.</span>
        </p>

        <section class="oa-card oa-card-body" style="margin-bottom:16px;" aria-labelledby="repTrendTitle">
            <div class="oa-card-head">
                <div>
                    <h2 class="oa-card-title" id="repTrendTitle"><i data-lucide="trending-up" aria-hidden="true"></i> Bookings Trend</h2>
                    <p class="oa-card-sub">Bookings created per day</p>
                </div>
                <span class="oa-pill"><i data-lucide="calendar" aria-hidden="true"></i> {{ $rangeLabel }}</span>
            </div>

            @if (collect($bookingsTrend)->sum('total') > 0)
                <div class="oa-chart"><canvas id="reportsBookingsTrendChart" aria-label="Bookings created per day" role="img"></canvas></div>
            @else
                <div class="oa-empty oa-empty--tall">No bookings recorded for this period.</div>
            @endif
        </section>

        <div class="oa-grid oa-grid--reports">
            <section class="oa-card oa-card-body" aria-labelledby="repPipelineTitle">
                <div class="oa-card-head">
                    <div>
                        <h2 class="oa-card-title" id="repPipelineTitle"><i data-lucide="list-checks" aria-hidden="true"></i> Booking Performance</h2>
                        <p class="oa-card-sub">Booking pipeline by status</p>
                    </div>
                </div>

                @if ($totalBookings > 0)
                    <div class="oa-table-wrap">
                        <table class="oa-table">
                            <thead>
                                <tr>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="is-num">Jobs</th>
                                    <th scope="col" class="is-num">Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pipeline as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td class="is-num {{ $row['count'] > 0 ? '' : 'is-dim' }}">
                                            @if (! empty($row['key']) && $row['count'] > 0)
                                                <a class="oa-count" href="{{ route('superadmin.reports.bookings', $bucketLinkParams($row['key'])) }}">{{ $row['count'] }}</a>
                                            @else
                                                {{ $row['count'] }}
                                            @endif
                                        </td>
                                        <td class="is-num">
                                            <span class="oa-share">
                                                <span class="oa-share-bar" aria-hidden="true"><span style="width: {{ min(100, max(0, round($row['share'], 1))) }}%"></span></span>
                                                <span class="oa-share-value">{{ number_format($row['share'], 1) }}%</span>
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="oa-foot-note">Job counts above zero open the booking report for that status.</p>
                @else
                    <div class="oa-empty">No bookings recorded for this period.</div>
                @endif
            </section>

            <div class="oa-stack">
                <section class="oa-card oa-card-body" aria-labelledby="repFinancialTitle">
                    <div class="oa-card-head" style="margin-bottom:8px;">
                        <h2 class="oa-card-title" id="repFinancialTitle"><i data-lucide="banknote" aria-hidden="true"></i> Financial Summary</h2>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="oa-btn oa-btn--sm" href="{{ route('superadmin.reports.export-pdf', array_merge($pdfBaseParams, ['section' => 'financial'])) }}">
                            <i data-lucide="file-text" aria-hidden="true"></i> Export Financial PDF
                        </a>
                        <a class="oa-btn oa-btn--sm" href="{{ route('superadmin.reports.export-xlsx', array_merge($pdfBaseParams, ['section' => 'financial'])) }}">
                            <i data-lucide="file-spreadsheet" aria-hidden="true"></i> Export Financial Excel
                        </a>
                    </div>
                        </div>

                    <div class="oa-rows">
                        <div><span>Completed revenue, by completion date</span><strong>₱{{ number_format($financial['totalRevenue'], 2) }}</strong></div>
                        <div><span>VAT Collected</span><strong>₱{{ number_format($financial['vatCollected'], 2) }}</strong></div>
                        <div><span>Additional Fees</span><strong>₱{{ number_format($financial['additionalFees'], 2) }}</strong></div>
                        <div><span>Average Revenue / Job</span><strong>₱{{ number_format($financial['averagePerBooking'], 2) }}</strong></div>
                    </div>
                </section>

                <section class="oa-card oa-card-body" aria-labelledby="repTruckTitle">
                    <div class="oa-card-head" style="margin-bottom:8px;">
                        <h2 class="oa-card-title" id="repTruckTitle"><i data-lucide="truck" aria-hidden="true"></i> Truck Type Performance</h2>
                    </div>

                    @if ($truckTypePerformance->isNotEmpty())
                        <div class="oa-table-wrap">
                            <table class="oa-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Truck Type</th>
                                        <th scope="col" class="is-num">Jobs</th>
                                        <th scope="col" class="is-num">Completed</th>
                                        <th scope="col" class="is-num">Cancelled</th>
                                        <th scope="col" class="is-num">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($truckTypePerformance as $row)
                                        <tr>
                                            <td>{{ $row['truck_type_name'] }}</td>
                                            <td class="is-num">{{ $row['total_jobs'] }}</td>
                                            <td class="is-num">{{ $row['completed_jobs'] }}</td>
                                            <td class="is-num">{{ $row['cancelled_jobs'] }}</td>
                                            <td class="is-num">₱{{ number_format($row['revenue'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="oa-empty">No truck type activity recorded for this period.</div>
                    @endif
                </section>
            </div>
        </div>

        <section class="oa-card oa-card-body" style="margin-bottom:16px;" aria-labelledby="repFleetTitle">
            <div class="oa-card-head" style="margin-bottom:8px;">
                <h2 class="oa-card-title" id="repFleetTitle"><i data-lucide="trophy" aria-hidden="true"></i> Fleet Performance</h2>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a class="oa-btn oa-btn--sm" href="{{ route('superadmin.reports.export-pdf', array_merge($pdfBaseParams, ['section' => 'vehicle'])) }}">
                    <i data-lucide="file-text" aria-hidden="true"></i> Export Fleet PDF
                </a>
                <a class="oa-btn oa-btn--sm" href="{{ route('superadmin.reports.export-xlsx', array_merge($pdfBaseParams, ['section' => 'vehicle'])) }}">
                    <i data-lucide="file-spreadsheet" aria-hidden="true"></i> Export Fleet Excel
                </a>
                </div>
            </div>

            @if ($fleetPerformance->isNotEmpty())
                <div class="oa-table-wrap">
                    <table class="oa-table">
                        <thead>
                            <tr>
                                <th scope="col">Unit</th>
                                <th scope="col">Truck Type</th>
                                <th scope="col" class="is-num">Jobs</th>
                                <th scope="col" class="is-num">Completed</th>
                                <th scope="col" class="is-num">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fleetPerformance as $row)
                                <tr>
                                    <td>{{ $row['unit_name'] }}</td>
                                    <td>{{ $row['truck_type_name'] }}</td>
                                    <td class="is-num">{{ $row['total_jobs'] }}</td>
                                    <td class="is-num">{{ $row['completed_jobs'] }}</td>
                                    <td class="is-num">₱{{ number_format($row['revenue'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="oa-empty">No fleet activity recorded for this period.</div>
            @endif
        </section>

        <section class="oa-band" aria-labelledby="repDetailTitle">
            <div>
                <h2 id="repDetailTitle"><i data-lucide="table" aria-hidden="true"></i> Detailed Booking Report</h2>
                <p>Full booking-by-booking detail for this reporting period, including customer, truck type, unit, status, and amount.</p>
            </div>
            <div class="oa-band-actions">
                <a class="oa-btn oa-btn--primary" href="{{ route('superadmin.reports.bookings', $bucketLinkParams('')) }}">
                    <i data-lucide="table" aria-hidden="true"></i> View Detailed Booking Report
                </a>
                <a class="oa-btn oa-btn--on-dark" href="{{ route('superadmin.reports.export-pdf', array_merge($pdfBaseParams, ['section' => 'booking'])) }}">
                    <i data-lucide="file-text" aria-hidden="true"></i> Export Booking PDF
                </a>
                <a class="oa-btn oa-btn--on-dark" href="{{ route('superadmin.reports.export-xlsx', array_merge($pdfBaseParams, ['section' => 'booking'])) }}">
                    <i data-lucide="file-spreadsheet" aria-hidden="true"></i> Export Booking Excel
                </a>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/analytics.js') }}?v={{ filemtime(public_path('superadmin/js/analytics.js')) }}" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const filters = document.getElementById('reportsFilters');
            const truckSelect = document.getElementById('reportsTruckTypeSelect');

            if (filters && truckSelect) {
                truckSelect.addEventListener('change', function() {
                    filters.requestSubmit();
                });
            }

            const canvas = document.getElementById('reportsBookingsTrendChart');
            if (canvas) {
                const labels = @json(collect($bookingsTrend)->pluck('label'));
                const totals = @json(collect($bookingsTrend)->pluck('total'));

                Chart.defaults.font.family = '"JARZ Inter", "Inter", "Segoe UI", Arial, sans-serif';
                Chart.defaults.font.size = 13;
                Chart.defaults.color = '#525252';

                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Bookings',
                            data: totals,
                            borderColor: '#171717',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false,
                            pointBackgroundColor: '#facc15',
                            pointBorderColor: '#171717',
                            pointBorderWidth: 1.5,
                            pointRadius: totals.map(value => value > 0 ? 4 : 0),
                            pointHoverRadius: totals.map(value => value > 0 ? 6 : 3),
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
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
                                    label: function (context) {
                                        const n = context.parsed.y;
                                        return context.label + ' · ' + n + (n === 1 ? ' booking' : ' bookings');
                                    },
                                },
                            },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { precision: 0 },
                                grid: { color: '#f0f0f0' },
                                border: { display: false },
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
