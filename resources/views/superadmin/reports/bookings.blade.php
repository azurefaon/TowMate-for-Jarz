@extends('layouts.superadmin')

@section('title', 'Reports — ' . $bucketLabel)

@push('styles')
    <style>
        .rb-page { display:flex; flex-direction:column; gap:16px; }
        .rb-back { color:#111827; font-size:0.85rem; font-weight:600; text-decoration:none; }
        .rb-meta { font-size:0.82rem; color:#111827; opacity:0.65; }
        .rb-table-wrap { background:#fff; border:1px solid #111827; overflow-x:auto; }
        .rb-table { width:100%; border-collapse:collapse; }
        .rb-table th {
            font-size:0.68rem; text-transform:uppercase; letter-spacing:0.06em;
            color:#fff; background:#111827; text-align:left; padding:10px 14px; white-space:nowrap;
        }
        .rb-table td { padding:12px 14px; font-size:0.85rem; color:#111827; border-bottom:1px solid #111827; }
        .rb-table tbody tr:last-child td { border-bottom:none; }
        .rb-table tbody tr:hover { background:#fffbea; }
        .rb-badge {
            display:inline-block; padding:4px 10px; font-size:0.7rem; font-weight:700;
            text-transform:uppercase; letter-spacing:0.04em; border-radius:999px; border:1px solid #111827;
            background:#111827; color:#facc15;
        }
        .rb-empty { padding:60px 20px; text-align:center; color:#111827; }
    </style>
@endpush

@section('content')
    <div class="rb-page">
        <div>
            <a href="{{ route('superadmin.reports.index', $period !== 'custom' ? ['period' => $period] : ['from' => $start->toDateString(), 'to' => $end->toDateString()]) }}" class="rb-back">
                &larr; Back to Reports
            </a>
        </div>

        <div>
            <h1 style="margin:0 0 4px; font-size:1.3rem; color:#111827;">{{ $bucketLabel }}</h1>
            <p class="rb-meta">{{ $start->format('M d, Y') }} &ndash; {{ $end->format('M d, Y') }} &middot; {{ $bookings->total() }} booking(s)</p>
        </div>

        <div class="rb-table-wrap">
            @forelse ($bookings as $booking)
                @if ($loop->first)
                    <table class="rb-table">
                        <thead>
                            <tr>
                                <th>Booking #</th>
                                <th>Customer</th>
                                <th>Truck Type</th>
                                <th>Final Total</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                @endif
                <tr>
                    <td>{{ $booking->job_code }}</td>
                    <td>{{ $booking->customer->full_name ?? 'Guest' }}</td>
                    <td>{{ $booking->truckType->name ?? '—' }}</td>
                    <td>₱{{ number_format((float) $booking->final_total, 2) }}</td>
                    <td><span class="rb-badge">{{ str_replace('_', ' ', $booking->status) }}</span></td>
                    <td>{{ $booking->created_at?->format('M d, Y h:i A') }}</td>
                </tr>
                @if ($loop->last)
                        </tbody>
                    </table>
                @endif
            @empty
                <div class="rb-empty">No bookings in this range.</div>
            @endforelse
        </div>

        @if ($bookings->hasPages())
            <div>{{ $bookings->links() }}</div>
        @endif
    </div>
@endsection
