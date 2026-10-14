@extends('layouts.superadmin')

@section('title', 'Dashboard')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/dashboard.css') }}?v={{ filemtime(public_path('superadmin/css/dashboard.css')) }}">
@endpush

@php
    $needsAction = [
        ['label' => 'Pending Bookings', 'note' => 'New requests not yet reviewed', 'icon' => 'inbox', 'count' => $pendingBookingsCount],
        ['label' => 'Awaiting Verification', 'note' => 'Completion or payment not yet confirmed', 'icon' => 'clipboard-check', 'count' => $verificationBookingsCount],
        ['label' => 'Returned Jobs', 'note' => 'Returned by a team leader, back in the queue', 'icon' => 'undo-2', 'count' => $returnedJobsCount],
    ];
    $nothingWaiting = collect($needsAction)->sum('count') === 0;
@endphp

@section('content')
    <div class="od-page">
        <header class="od-header">
            <h1>Dashboard</h1>
            <p>Business overview for {{ $periodLabel }}</p>
        </header>

        <section class="od-kpis" aria-label="Key figures">
            <article class="od-kpi">
                <div class="od-kpi-head">
                    <h2>Monthly Revenue</h2>
                    <i data-lucide="banknote" aria-hidden="true"></i>
                </div>
                @php($revenueDisplay = '₱' . number_format($revenueThisMonth, 2))
                <p @class(['od-kpi-value', 'is-long' => mb_strlen($revenueDisplay) > 11])>{{ $revenueDisplay }}</p>
                <p class="od-kpi-note">Completed, paid jobs · {{ $periodLabel }}</p>
            </article>

            <article class="od-kpi">
                <div class="od-kpi-head">
                    <h2>Completed Jobs</h2>
                    <i data-lucide="circle-check" aria-hidden="true"></i>
                </div>
                <p class="od-kpi-value">{{ number_format($completedJobsCount) }}</p>
                <p class="od-kpi-note">Completed, paid · this month</p>
            </article>

            <article class="od-kpi">
                <div class="od-kpi-head">
                    <h2>Active Jobs</h2>
                    <i data-lucide="route" aria-hidden="true"></i>
                </div>
                <p class="od-kpi-value">{{ number_format($activeJobsCount) }}</p>
                <p class="od-kpi-note">In progress now</p>
            </article>

            <article class="od-kpi">
                <div class="od-kpi-head">
                    <h2>Available Units</h2>
                    <i data-lucide="truck" aria-hidden="true"></i>
                </div>
                @if ($totalUnitsCount > 0)
                    <p class="od-kpi-value">
                        {{ number_format($availableUnitsCount) }}<span class="od-kpi-total"><span class="od-sr"> of</span> / {{ number_format($totalUnitsCount) }}</span>
                    </p>
                    <p class="od-kpi-note">Ready for dispatch / all units</p>
                @else
                    <p class="od-kpi-value">0</p>
                    <p class="od-kpi-note">No units registered</p>
                @endif
            </article>
        </section>

        <div class="od-main">
            <section class="od-panel od-revenue" aria-labelledby="odRevenueTitle">
                <div class="od-panel-head">
                    <div>
                        <h2 id="odRevenueTitle">Revenue Trend</h2>
                        <p>Completed, paid jobs · last 7 days</p>
                    </div>
                    <div class="od-total">
                        <span>7-day total</span>
                        <strong>₱{{ number_format($revenueTrendTotal, 2) }}</strong>
                    </div>
                </div>

                @if ($revenueTrendTotal > 0)
                    <div class="od-chart" aria-hidden="true">
                        <div class="od-chart-axis">
                            @foreach ($revenueChart['ticks'] as $tick)
                                <span style="bottom: {{ $tick['percent'] }}%">{{ $tick['label'] }}</span>
                            @endforeach
                        </div>
                        <div class="od-chart-plot">
                            @foreach ($revenueChart['ticks'] as $tick)
                                <span class="od-chart-grid {{ $tick['percent'] == 0 ? 'is-base' : '' }}" style="bottom: {{ $tick['percent'] }}%"></span>
                            @endforeach
                            @foreach ($revenueChart['bars'] as $bar)
                                <div class="od-chart-col">
                                    <span class="od-chart-bar {{ $bar['revenue'] > 0 ? '' : 'is-zero' }}" @if ($bar['revenue'] > 0) style="height: {{ $bar['percent'] }}%" @endif>
                                        @if ($bar['is_peak'])
                                            <span class="od-chart-peak">₱{{ number_format($bar['revenue']) }}</span>
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        <div class="od-chart-labels">
                            @foreach ($revenueChart['bars'] as $bar)
                                <span>{{ $bar['label'] }}</span>
                            @endforeach
                        </div>
                    </div>

                    <table class="od-sr">
                        <caption>Daily revenue, last 7 days</caption>
                        <tbody>
                            @foreach ($revenueChart['bars'] as $bar)
                                <tr>
                                    <th scope="row">{{ $bar['label'] }}</th>
                                    <td>₱{{ number_format($bar['revenue'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="od-empty od-empty-fill">
                        <span class="od-empty-icon"><i data-lucide="chart-column" aria-hidden="true"></i></span>
                        <p class="od-empty-title">No revenue in the last 7 days</p>
                        <p class="od-empty-text">Revenue is recorded when a completed job's payment is confirmed.</p>
                    </div>
                @endif
            </section>

            <section class="od-panel od-ops" aria-labelledby="odOpsTitle">
                <div class="od-panel-head">
                    <div>
                        <h2 id="odOpsTitle">Operations Overview</h2>
                        <p>Current counts, all dates</p>
                    </div>
                    <a href="{{ route('superadmin.monitoring.index') }}" class="od-link-btn">
                        Operations Monitor <i data-lucide="arrow-right" aria-hidden="true"></i>
                    </a>
                </div>

                <div class="od-section-label">
                    <h3>Needs action</h3>
                    @if ($nothingWaiting)
                        <span>Nothing waiting</span>
                    @endif
                </div>
                <ul class="od-ops-list">
                    @foreach ($needsAction as $row)
                        <li class="od-ops-row">
                            <span class="od-tile"><i data-lucide="{{ $row['icon'] }}" aria-hidden="true"></i></span>
                            <div class="od-ops-text">
                                <strong>{{ $row['label'] }}</strong>
                                <span>{{ $row['note'] }}</span>
                            </div>
                            <span class="od-count {{ $row['count'] > 0 ? 'is-attention' : '' }}">{{ number_format($row['count']) }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="od-section-label is-plain">
                    <h3>Upcoming</h3>
                </div>
                <div class="od-ops-row od-ops-upcoming">
                    <span class="od-tile"><i data-lucide="calendar" aria-hidden="true"></i></span>
                    <div class="od-ops-text">
                        <strong>Scheduled Bookings</strong>
                        <span>Booked for a later date</span>
                    </div>
                    <span class="od-count {{ $scheduledBookingsCount > 0 ? '' : 'is-zero' }}">{{ number_format($scheduledBookingsCount) }}</span>
                </div>
            </section>
        </div>

        <section class="od-panel od-activity" aria-labelledby="odActivityTitle">
            <div class="od-panel-head">
                <div>
                    <h2 id="odActivityTitle">Recent Activity</h2>
                    <p>Latest booking and quotation events</p>
                </div>
                @if (count($activityGroups) > 0)
                    <a href="{{ route('superadmin.reports.activity') }}" class="od-link-btn">
                        Activity Report <i data-lucide="arrow-right" aria-hidden="true"></i>
                    </a>
                @endif
            </div>

            @forelse ($activityGroups as $group)
                <div class="od-section-label">
                    <h3>{{ $group['label'] }}</h3>
                </div>
                <ul class="od-activity-list">
                    @foreach ($group['items'] as $activity)
                        <li class="od-activity-row">
                            <span class="od-tile"><i data-lucide="{{ $activity['icon'] }}" aria-hidden="true"></i></span>
                            <span class="od-activity-title">{{ $activity['title'] }}</span>
                            <span class="od-activity-ref">{{ $activity['reference'] }}</span>
                            <time class="od-activity-time" datetime="{{ $activity['created_at']->toIso8601String() }}">{{ $activity['created_at']->format('g:i A') }}</time>
                        </li>
                    @endforeach
                </ul>
            @empty
                <div class="od-empty">
                    <span class="od-empty-icon"><i data-lucide="history" aria-hidden="true"></i></span>
                    <p class="od-empty-title">No recent activity</p>
                    <p class="od-empty-text">Booking and quotation events will appear here.</p>
                </div>
            @endforelse
        </section>
    </div>
@endsection
