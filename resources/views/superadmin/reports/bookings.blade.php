@extends('layouts.superadmin')

@section('title', 'Reports — ' . $bucketLabel)

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/reports.css') }}?v={{ filemtime(public_path('superadmin/css/reports.css')) }}">
    <style>
        .rb-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 20px;
            color: #171717;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
        }

        .rb-back svg {
            width: 15px;
            height: 15px;
        }

        .rb-meta {
            margin: 4px 0 32px;
            color: #525252;
            font-size: 0.85rem;
        }

        .rb-table-wrap {
            overflow-x: auto;
        }

        .rb-table {
            width: 100%;
            border-collapse: collapse;
        }

        .rb-table th {
            text-align: left;
            padding: 10px 14px 10px 0;
            font-size: 0.78rem;
            font-weight: 500;
            color: #525252;
            white-space: nowrap;
        }

        .rb-table td {
            padding: 12px 14px 12px 0;
            font-size: 0.88rem;
            color: #171717;
            white-space: nowrap;
        }

        .rb-status-completed {
            color: #15803d;
        }

        .rb-status-cancelled {
            color: #b91c1c;
        }
    </style>
@endpush

@section('content')
    <a href="{{ route('superadmin.reports.index', $period !== 'custom' ? ['period' => $period] : ['from' => $start->toDateString(), 'to' => $end->toDateString()]) }}" class="rb-back">
        <i data-lucide="arrow-left"></i> Back to Reports
    </a>

    <div class="page-top">
        <div>
            <h1>{{ $bucketLabel }}</h1>
            <p class="rb-meta">{{ $start->format('M d, Y') }} &ndash; {{ $end->format('M d, Y') }} &middot; {{ $bookings->total() }} booking(s)</p>
        </div>
    </div>

    <div class="rb-table-wrap">
        @forelse ($bookings as $booking)
            @if ($loop->first)
                <table class="rb-table">
                    <thead>
                        <tr>
                            <th>Booking #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Truck Type</th>
                            <th>Unit</th>
                            <th>Status</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
            @endif
                <tr>
                    <td>{{ $booking->job_code }}</td>
                    <td>{{ $booking->created_at?->format('M d, Y h:i A') }}</td>
                    <td>{{ $booking->customer->full_name ?? 'Guest' }}</td>
                    <td>{{ $booking->truckType->name ?? '—' }}</td>
                    <td>{{ $booking->unit->name ?? '—' }}</td>
                    <td class="{{ $booking->status === 'completed' ? 'rb-status-completed' : ($booking->status === 'cancelled' ? 'rb-status-cancelled' : '') }}">
                        {{ ucfirst(str_replace('_', ' ', $booking->status)) }}
                    </td>
                    <td>₱{{ number_format((float) $booking->final_total, 2) }}</td>
                </tr>
            @if ($loop->last)
                    </tbody>
                </table>
            @endif
        @empty
            <p class="owner-empty">No bookings in this range.</p>
        @endforelse
    </div>

    @if ($bookings->hasPages())
        <div style="margin-top:20px;">{{ $bookings->links() }}</div>
    @endif
@endsection
