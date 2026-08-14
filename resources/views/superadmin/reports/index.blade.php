@extends('layouts.superadmin')

@section('title', 'Reports')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/dashboard.css') }}">
@endpush

@section('content')
    <div class="dashboard-header">

        <div>
            <h1>Reports Summary</h1>
            <p>Bookings and revenue for the selected period.</p>
        </div>

        <div class="dashboard-meta">
            <div class="date-box">
                {{ $start->format('M d, Y') }} &ndash; {{ $end->format('M d, Y') }}
            </div>
        </div>

    </div>

    <div class="dashboard-overview">

        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                @foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'] as $value => $label)
                    <a href="{{ route('superadmin.reports.index', ['period' => $value]) }}"
                        style="padding:8px 16px; border-radius:6px; font-size:0.85rem; font-weight:600; text-decoration:none;
                            {{ $period === $value ? 'background:#111827; color:#fff;' : 'background:#f1f5f9; color:#334155;' }}">
                        {{ $label }}
                    </a>
                @endforeach

                <form action="{{ route('superadmin.reports.index') }}" method="GET"
                    style="display:flex; align-items:center; gap:6px; margin-left:6px; padding:4px 8px; border-radius:6px;
                        {{ $period === 'custom' ? 'background:#111827;' : 'background:#f1f5f9;' }}">
                    <input type="date" name="from" value="{{ $fromInput }}" required
                        style="border:none; background:transparent; font-size:0.8rem; font-weight:600; color:{{ $period === 'custom' ? '#fff' : '#334155' }};">
                    <span style="font-size:0.8rem; color:{{ $period === 'custom' ? '#fff' : '#334155' }};">&ndash;</span>
                    <input type="date" name="to" value="{{ $toInput }}" required
                        style="border:none; background:transparent; font-size:0.8rem; font-weight:600; color:{{ $period === 'custom' ? '#fff' : '#334155' }};">
                    <button type="submit"
                        style="border:none; background:#facc15; color:#111827; padding:5px 10px; border-radius:4px; font-size:0.78rem; font-weight:700; cursor:pointer;">
                        Go
                    </button>
                </form>
            </div>

            <a href="{{ route('superadmin.reports.export', $period === 'custom' ? ['from' => $fromInput, 'to' => $toInput] : ['period' => $period]) }}"
                style="padding:8px 16px; border-radius:6px; font-size:0.85rem; font-weight:600; text-decoration:none; background:#facc15; color:#111827;">
                Download CSV
            </a>
        </div>

        <div class="kpi-row">

            <div class="kpi-card">
                <span class="kpi-label">Total Bookings</span>
                <div class="kpi-value">{{ $totalBookings }}</div>
            </div>

            <div class="kpi-card">
                <span class="kpi-label">Total Revenue</span>
                <div class="kpi-value">&#8369;{{ number_format($totalRevenue, 2) }}</div>
            </div>

            <div class="kpi-card kpi-accent-green">
                <span class="kpi-label">Completed</span>
                <div class="kpi-value">{{ $completedCount }}</div>
            </div>

            <div class="kpi-card kpi-accent-yellow">
                <span class="kpi-label">Pending</span>
                <div class="kpi-value">{{ $pendingCount }}</div>
            </div>

            <div class="kpi-card">
                <span class="kpi-label">Cancelled</span>
                <div class="kpi-value">{{ $cancelledCount }}</div>
            </div>

        </div>

        <div class="activity-card">
            <div class="activity-header">
                <h2>Status Breakdown</h2>
            </div>

            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left; border-bottom:1px solid #e5e7eb;">
                        <th style="padding:10px 8px; font-size:0.78rem; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">Status</th>
                        <th style="padding:10px 8px; font-size:0.78rem; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; text-align:right;">Bookings</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pipeline as $row)
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px 8px; font-size:0.9rem; color:#111827;">{{ $row['label'] }}</td>
                            <td style="padding:10px 8px; font-size:0.9rem; text-align:right; font-weight:600;">
                                @if (!empty($row['key']) && $row['count'] > 0)
                                    <a href="{{ route('superadmin.reports.bookings', array_filter([
                                            'bucket' => $row['key'],
                                            'period' => $period !== 'custom' ? $period : null,
                                            'from'   => $period === 'custom' ? $fromInput : null,
                                            'to'     => $period === 'custom' ? $toInput : null,
                                        ])) }}"
                                        style="color:#111827; text-decoration:underline; text-decoration-color:#facc15; text-decoration-thickness:2px;">
                                        {{ $row['count'] }}
                                    </a>
                                @else
                                    <span style="color:#111827;">{{ $row['count'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    </div>
@endsection
