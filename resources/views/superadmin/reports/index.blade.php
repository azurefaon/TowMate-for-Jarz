@extends('layouts.superadmin')

@section('title', 'Reports')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/reports.css') }}?v={{ filemtime(public_path('superadmin/css/reports.css')) }}">
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
    @endphp

    <div class="page-top reports-header">
        <div>
            <h1>Reports</h1>
            <p>Business performance from {{ $start->format('M j') }}&ndash;{{ $end->format('M j, Y') }}.</p>
        </div>

        <div class="reporting-toolbar">
            <span class="reporting-toolbar-label">Reporting Period</span>

            <div class="reporting-controls-row">
                <div class="reporting-period-group" role="group" aria-label="Reporting period">
                    @foreach (['today' => ['Today', 'calendar'], 'week' => ['This Week', 'calendar-days'], 'month' => ['This Month', 'calendar-range'], 'quarter' => ['Quarter', 'bar-chart-2']] as $value => $meta)
                        <a href="{{ route('superadmin.reports.index', array_filter(array_merge($filters, ['period' => $value]))) }}"
                            class="{{ ! $customRange && $period === $value ? 'active' : '' }}">
                            <i data-lucide="{{ $meta[1] }}"></i>
                            <span>{{ $meta[0] }}</span>
                        </a>
                    @endforeach
                    <button type="button" id="reportsCustomToggle" class="{{ $customRange ? 'active' : '' }}">
                        <i data-lucide="calendar-plus"></i>
                        <span>Custom</span>
                    </button>
                </div>

                <select class="reporting-truck-select" id="reportsTruckTypeSelect">
                    <option value="">All Truck Types</option>
                    @foreach ($truckTypes as $truckType)
                        <option value="{{ $truckType->id }}" {{ (int) ($filters['truck_type_id'] ?? 0) === $truckType->id ? 'selected' : '' }}>
                            {{ $truckType->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <form class="reporting-range-form" method="GET" action="{{ route('superadmin.reports.index') }}"
                id="reportsRangeForm" @if (! $customRange) hidden @endif>
                <label>
                    <span>From</span>
                    <input type="date" name="from" value="{{ $fromInput }}">
                </label>
                <label>
                    <span>To</span>
                    <input type="date" name="to" value="{{ $toInput }}">
                </label>
                <input type="hidden" name="truck_type_id" id="reportsTruckTypeHidden" value="{{ $filters['truck_type_id'] ?? '' }}">
                <button type="submit" class="reporting-apply-btn">Apply</button>
            </form>
        </div>
    </div>

    <div class="reports-metric-row">
        <div class="owner-kpi">
            <span class="owner-kpi-label">Total Bookings</span>
            <div class="owner-kpi-value">{{ number_format($totalBookings) }}</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Completed Jobs</span>
            <div class="owner-kpi-value is-positive">{{ number_format($completedCount) }}</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Cancelled Jobs</span>
            <div class="owner-kpi-value is-negative">{{ number_format($cancelledCount) }}</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Completion Rate</span>
            <div class="owner-kpi-value">{{ number_format($completionRate, 1) }}%</div>
        </div>

        <div class="owner-kpi">
            <span class="owner-kpi-label">Completed Revenue</span>
            <div class="owner-kpi-value">₱{{ number_format($totalRevenue, 2) }}</div>
        </div>
    </div>

    <div class="owner-panel reports-chart-panel">
        <h2><i data-lucide="trending-up"></i> Bookings Trend</h2>
        @if (collect($bookingsTrend)->sum('total') > 0)
            <canvas id="reportsBookingsTrendChart"></canvas>
        @else
            <div class="owner-chart-empty">
                <p>No bookings recorded for this period.</p>
            </div>
        @endif
    </div>

    <div class="reports-summary-grid">
        <div class="reports-section reports-section--tight">
            <h2 class="reports-section-title"><i data-lucide="list-checks"></i> Booking Performance</h2>

            @if ($totalBookings > 0)
                <div class="reports-table--pipeline">
                    <div class="reports-table-head">
                        <span>Status</span>
                        <span>Jobs</span>
                        <span>Share</span>
                    </div>
                    @foreach ($pipeline as $row)
                        <div class="reports-table-row">
                            <span>{{ $row['label'] }}</span>
                            <span>
                                @if (! empty($row['key']) && $row['count'] > 0)
                                    <a href="{{ route('superadmin.reports.bookings', $bucketLinkParams($row['key'])) }}">{{ $row['count'] }}</a>
                                @else
                                    {{ $row['count'] }}
                                @endif
                            </span>
                            <span>{{ number_format($row['share'], 1) }}%</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="owner-empty">No bookings recorded for this period.</p>
            @endif
        </div>

        <div class="reports-section reports-section--tight">
            <h2 class="reports-section-title"><i data-lucide="banknote"></i> Financial Summary</h2>

            <div class="reports-financial-list">
                <div class="reports-financial-row">
                    <span>Completed Revenue</span>
                    <strong>₱{{ number_format($financial['totalRevenue'], 2) }}</strong>
                </div>
                <div class="reports-financial-row">
                    <span>VAT Collected</span>
                    <strong>₱{{ number_format($financial['vatCollected'], 2) }}</strong>
                </div>
                <div class="reports-financial-row">
                    <span>Additional Fees</span>
                    <strong>₱{{ number_format($financial['additionalFees'], 2) }}</strong>
                </div>
                <div class="reports-financial-row">
                    <span>Average Revenue / Job</span>
                    <strong>₱{{ number_format($financial['averagePerBooking'], 2) }}</strong>
                </div>
            </div>

            <div class="reports-export-row reports-export-row--bordered">
                <a class="reports-export-btn" href="{{ route('superadmin.reports.export-pdf', array_merge($pdfBaseParams, ['section' => 'financial'])) }}">
                    <i data-lucide="file-text"></i> Export Financial PDF
                </a>
            </div>
        </div>
    </div>

    <div class="reports-section">
        <h2 class="reports-section-title"><i data-lucide="truck"></i> Truck Type Performance</h2>

        @if ($truckTypePerformance->isNotEmpty())
            <div class="reports-table--truck">
                <div class="reports-table-head">
                    <span>Truck Type</span>
                    <span>Jobs</span>
                    <span>Completed</span>
                    <span>Cancelled</span>
                    <span>Revenue</span>
                </div>
                @foreach ($truckTypePerformance as $row)
                    <div class="reports-table-row">
                        <span>{{ $row['truck_type_name'] }}</span>
                        <span>{{ $row['total_jobs'] }}</span>
                        <span class="reports-status-completed">{{ $row['completed_jobs'] }}</span>
                        <span class="reports-status-cancelled">{{ $row['cancelled_jobs'] }}</span>
                        <span>₱{{ number_format($row['revenue'], 2) }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="owner-empty">No truck type activity recorded for this period.</p>
        @endif
    </div>

    <div class="reports-section">
        <h2 class="reports-section-title"><i data-lucide="trophy"></i> Fleet Performance</h2>

        @if ($fleetPerformance->isNotEmpty())
            <div class="reports-table--fleet">
                <div class="reports-table-head">
                    <span>Unit</span>
                    <span>Truck Type</span>
                    <span>Jobs</span>
                    <span>Completed</span>
                    <span>Revenue</span>
                </div>
                @foreach ($fleetPerformance as $row)
                    <div class="reports-table-row">
                        <span>{{ $row['unit_name'] }}</span>
                        <span>{{ $row['truck_type_name'] }}</span>
                        <span>{{ $row['total_jobs'] }}</span>
                        <span class="reports-status-completed">{{ $row['completed_jobs'] }}</span>
                        <span>₱{{ number_format($row['revenue'], 2) }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="owner-empty">No fleet activity recorded for this period.</p>
        @endif

        <div class="reports-export-row">
            <a class="reports-export-btn" href="{{ route('superadmin.reports.export-pdf', array_merge($pdfBaseParams, ['section' => 'vehicle'])) }}">
                <i data-lucide="file-text"></i> Export Fleet PDF
            </a>
        </div>
    </div>

    <div class="reports-section">
        <h2 class="reports-section-title"><i data-lucide="table"></i> Detailed Booking Report</h2>

        <p class="owner-empty" style="padding-top:0;">Full booking-by-booking detail for this reporting period, including customer, truck type, unit, status, and amount.</p>

        <div class="reports-export-row">
            <a class="reports-detail-link" href="{{ route('superadmin.reports.bookings', array_filter(array_merge($filters, ['period' => ! $customRange ? $period : null, 'from' => $customRange ? $fromInput : null, 'to' => $customRange ? $toInput : null]))) }}">
                <i data-lucide="table"></i> View Detailed Booking Report
            </a>
            <a class="reports-export-btn" href="{{ route('superadmin.reports.export-pdf', array_merge($pdfBaseParams, ['section' => 'booking'])) }}">
                <i data-lucide="file-text"></i> Export Booking PDF
            </a>
            <a class="reports-export-btn" href="{{ route('superadmin.reports.export', $pdfBaseParams) }}">
                <i data-lucide="download"></i> Export Summary CSV
            </a>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.getElementById('reportsCustomToggle');
            const form = document.getElementById('reportsRangeForm');
            if (toggle && form) {
                toggle.addEventListener('click', function() {
                    form.hidden = !form.hidden;
                });
            }

            const truckSelect = document.getElementById('reportsTruckTypeSelect');
            const truckHidden = document.getElementById('reportsTruckTypeHidden');
            if (truckSelect) {
                truckSelect.addEventListener('change', function() {
                    const url = new URL(window.location.href);
                    if (truckSelect.value) {
                        url.searchParams.set('truck_type_id', truckSelect.value);
                    } else {
                        url.searchParams.delete('truck_type_id');
                    }
                    if (truckHidden) {
                        truckHidden.value = truckSelect.value;
                    }
                    window.location.href = url.toString();
                });
            }

            const canvas = document.getElementById('reportsBookingsTrendChart');
            if (canvas) {
                const totals = @json(collect($bookingsTrend)->pluck('total'));

                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: @json(collect($bookingsTrend)->pluck('label')),
                        datasets: [{
                            label: 'Bookings',
                            data: totals,
                            borderColor: '#171717',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false,
                            pointBackgroundColor: '#facc15',
                            pointBorderColor: '#facc15',
                            pointRadius: totals.map(value => value > 0 ? 3 : 0),
                            pointHoverRadius: totals.map(value => value > 0 ? 5 : 3),
                        }]
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
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
                                ticks: { autoSkip: true, maxTicksLimit: 10 },
                            },
                        }
                    }
                });
            }
        });
    </script>
@endpush
