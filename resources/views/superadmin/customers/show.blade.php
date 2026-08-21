@extends('layouts.superadmin')

@section('title', $customer->full_name)

@push('styles')
    <style>
        .customer-profile .back-link {
            display: inline-block;
            margin-bottom: 14px;
            color: #111827;
            font-weight: 600;
            text-decoration: underline;
        }

        .customer-profile-header {
            background: #fff;
            border: 1px solid #111827;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            justify-content: space-between;
        }

        .customer-profile-header h1 {
            margin: 0 0 6px;
            font-size: 1.5rem;
            color: #111827;
        }

        .customer-profile-header .meta-row {
            display: flex;
            gap: 22px;
            flex-wrap: wrap;
            color: #111827;
            font-size: 0.92rem;
        }

        .customer-profile-header .meta-row strong {
            display: block;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #374151;
            margin-bottom: 2px;
        }

        .risk-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            border: 1px solid #111827;
        }

        .risk-badge.clear { background: #fff; color: #111827; }
        .risk-badge.watchlist { background: #facc15; color: #111827; }
        .risk-badge.blacklisted { background: #111827; color: #facc15; }

        .risk-note {
            margin-top: 10px;
            padding: 12px 14px;
            border: 1px solid #111827;
            border-radius: 10px;
            background: #facc15;
            color: #111827;
            font-size: 0.88rem;
        }

        .customer-bookings h2 {
            font-size: 1.15rem;
            color: #111827;
            margin: 0 0 12px;
        }

        .customer-bookings-shell {
            overflow-x: auto;
            border: 1px solid #111827;
            border-radius: 12px;
            background: #fff;
        }

        table.customer-bookings-table {
            width: 100%;
            border-collapse: collapse;
        }

        table.customer-bookings-table thead th {
            background: #facc15;
            color: #111827;
            text-align: left;
            padding: 12px 14px;
            font-size: 0.85rem;
            font-weight: 700;
            border-bottom: 1px solid #111827;
        }

        table.customer-bookings-table tbody td {
            padding: 12px 14px;
            font-size: 0.9rem;
            color: #111827;
            border-bottom: 1px solid #e5e7eb;
        }

        table.customer-bookings-table .view-link {
            font-weight: 700;
            color: #111827;
            text-decoration: underline;
        }

        .customer-bookings-empty {
            text-align: center;
            padding: 24px;
            color: #111827;
            font-weight: 600;
        }
    </style>
@endpush

@section('content')
    <div class="customer-profile">

        <a href="{{ route('superadmin.customers.index') }}" class="back-link">&larr; Back to Customers</a>

        <div class="customer-profile-header">
            <div>
                <h1>{{ $customer->full_name }}</h1>
                <span class="risk-badge {{ strtolower($customer->risk_level ?: 'clear') }}">{{ $customer->risk_status_label }}</span>
            </div>

            <div class="meta-row">
                <div>
                    <strong>Phone</strong>
                    {{ $customer->phone ?: '—' }}
                </div>
                <div>
                    <strong>Email</strong>
                    {{ $customer->email ?: '—' }}
                </div>
                <div>
                    <strong>Type</strong>
                    {{ ucfirst($customer->customer_type ?: 'regular') }}
                </div>
                <div>
                    <strong>Customer Since</strong>
                    {{ $customer->created_at?->format('M j, Y') }}
                </div>
                <div>
                    <strong>Total Bookings</strong>
                    {{ $customer->bookings->count() }}
                </div>
            </div>

            @if ($customer->risk_level && strtolower($customer->risk_level) !== 'clear')
                <div class="risk-note" style="flex-basis: 100%;">
                    <strong>Risk reason:</strong> {{ $customer->risk_reason ?: 'No reason recorded.' }}
                    @if ($customer->blacklisted_at)
                        <br><strong>Blacklisted on:</strong> {{ $customer->blacklisted_at->format('M j, Y') }}
                    @endif
                </div>
            @endif
        </div>

        <div class="customer-bookings">
            <h2>Booking History</h2>

            <div class="customer-bookings-shell">
                <table class="customer-bookings-table">
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Truck</th>
                            <th>Unit</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customer->bookings as $booking)
                            <tr>
                                <td>{{ $booking->job_code }}</td>
                                <td>{{ $booking->truckType->name ?? '—' }}</td>
                                <td>{{ $booking->unit->name ?? 'Unassigned' }}</td>
                                <td>{{ $booking->created_at?->format('M j, Y') }}</td>
                                <td>₱{{ number_format($booking->final_total ?? 0, 2) }}</td>
                                <td>{{ ucfirst($booking->status) }}</td>
                                <td>
                                    <a href="{{ route('superadmin.bookings.show', $booking->id) }}" class="view-link">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="customer-bookings-empty">This customer has no bookings yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
@endsection
