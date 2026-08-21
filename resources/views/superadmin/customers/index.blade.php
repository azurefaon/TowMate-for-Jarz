@extends('layouts.superadmin')

@section('title', 'Customers')

@push('styles')
    <style>
        .customers-page .page-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 18px;
        }

        .customers-page h1 {
            margin: 0 0 4px;
            font-size: 1.6rem;
            color: #111827;
        }

        .customers-page .page-top p {
            margin: 0;
            color: #374151;
        }

        .customers-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }

        .customers-stat-card {
            flex: 1 1 180px;
            background: #fff;
            border: 1px solid #111827;
            border-radius: 12px;
            padding: 14px 16px;
        }

        .customers-stat-card .stat-number {
            font-size: 1.6rem;
            font-weight: 800;
            color: #111827;
        }

        .customers-stat-card .stat-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #111827;
        }

        .customers-stat-card.accent {
            background: #facc15;
        }

        .customers-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 18px;
        }

        .customers-filters input[type="text"],
        .customers-filters select {
            border: 1px solid #111827;
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 0.9rem;
            color: #111827;
            background: #fff;
        }

        .customers-filters input[type="text"] {
            min-width: 240px;
        }

        .customers-filters button {
            border: 1px solid #111827;
            background: #facc15;
            color: #111827;
            font-weight: 700;
            padding: 9px 16px;
            border-radius: 8px;
            cursor: pointer;
        }

        .customers-filters .clear-link {
            color: #111827;
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: underline;
        }

        .customers-table-shell {
            overflow-x: auto;
            border: 1px solid #111827;
            border-radius: 12px;
            background: #fff;
        }

        table.customers-table {
            width: 100%;
            border-collapse: collapse;
        }

        table.customers-table thead th {
            background: #facc15;
            color: #111827;
            text-align: left;
            padding: 12px 14px;
            font-size: 0.85rem;
            font-weight: 700;
            border-bottom: 1px solid #111827;
        }

        table.customers-table tbody td {
            padding: 12px 14px;
            font-size: 0.92rem;
            color: #111827;
            border-bottom: 1px solid #e5e7eb;
        }

        table.customers-table .cell-main {
            display: block;
            font-weight: 600;
            color: #111827;
        }

        table.customers-table .cell-sub {
            display: block;
            margin-top: 2px;
            color: #374151;
            font-size: 0.82rem;
        }

        .risk-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            border: 1px solid #111827;
        }

        .risk-badge.clear {
            background: #fff;
            color: #111827;
        }

        .risk-badge.watchlist {
            background: #facc15;
            color: #111827;
        }

        .risk-badge.blacklisted {
            background: #111827;
            color: #facc15;
        }

        .customers-table .view-link {
            font-weight: 700;
            color: #111827;
            text-decoration: underline;
        }

        .customers-empty {
            text-align: center;
            padding: 26px;
            color: #111827;
            font-weight: 600;
        }

        .customers-pagination {
            padding: 14px;
        }
    </style>
@endpush

@section('content')
    <div class="customers-page">

        <div class="page-top">
            <div>
                <h1>Customers</h1>
                <p>Every customer on record, with booking history and risk status.</p>
            </div>
        </div>

        <div class="customers-stats">
            <div class="customers-stat-card accent">
                <div class="stat-number">{{ $stats['total'] }}</div>
                <div class="stat-label">Total Customers</div>
            </div>
            <div class="customers-stat-card">
                <div class="stat-number">{{ $stats['watchlist'] }}</div>
                <div class="stat-label">Watchlist</div>
            </div>
            <div class="customers-stat-card">
                <div class="stat-number">{{ $stats['blacklisted'] }}</div>
                <div class="stat-label">Blacklisted</div>
            </div>
        </div>

        <form method="GET" class="customers-filters">
            <input type="text" name="search" value="{{ $filters['search'] }}"
                placeholder="Search name, phone, or email">

            <select name="risk_level">
                <option value="">All risk levels</option>
                <option value="watchlist" {{ $filters['risk_level'] === 'watchlist' ? 'selected' : '' }}>Watchlist</option>
                <option value="blacklisted" {{ $filters['risk_level'] === 'blacklisted' ? 'selected' : '' }}>Blacklisted</option>
            </select>

            <select name="customer_type">
                <option value="">All types</option>
                <option value="regular" {{ $filters['customer_type'] === 'regular' ? 'selected' : '' }}>Regular</option>
                <option value="pwd" {{ $filters['customer_type'] === 'pwd' ? 'selected' : '' }}>PWD</option>
                <option value="senior" {{ $filters['customer_type'] === 'senior' ? 'selected' : '' }}>Senior</option>
            </select>

            <button type="submit">Filter</button>

            @if ($filters['search'] !== '' || $filters['risk_level'] !== '' || $filters['customer_type'] !== '')
                <a href="{{ route('superadmin.customers.index') }}" class="clear-link">Clear filters</a>
            @endif
        </form>

        <div class="customers-table-shell">
            <table class="customers-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Type</th>
                        <th>Risk</th>
                        <th>Bookings</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td>
                                <span class="cell-main">{{ $customer->full_name }}</span>
                                <span class="cell-sub">Customer since {{ $customer->created_at?->format('M Y') }}</span>
                            </td>
                            <td>
                                <span class="cell-main">{{ $customer->phone ?: '—' }}</span>
                                <span class="cell-sub">{{ $customer->email ?: '—' }}</span>
                            </td>
                            <td>{{ ucfirst($customer->customer_type ?: 'regular') }}</td>
                            <td>
                                <span class="risk-badge {{ strtolower($customer->risk_level ?: 'clear') }}">
                                    {{ $customer->risk_status_label }}
                                </span>
                            </td>
                            <td>{{ $customer->bookings_count }}</td>
                            <td>
                                <a href="{{ route('superadmin.customers.show', $customer) }}" class="view-link">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="customers-empty">No customers match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($customers->hasPages())
                <div class="customers-pagination">
                    {{ $customers->onEachSide(1)->links() }}
                </div>
            @endif
        </div>

    </div>
@endsection
