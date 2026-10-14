@extends('layouts.superadmin')

@section('title', $bucketLabel)

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
@endpush

@section('content')
    <div class="ob-page oa-page" id="ownerBookingReport">

        <a href="{{ route('superadmin.reports.index', $period !== 'custom' ? ['period' => $period] : ['from' => $start->toDateString(), 'to' => $end->toDateString()]) }}" class="oa-back">
            <i data-lucide="arrow-left" aria-hidden="true"></i> Back to Reports
        </a>

        <header class="ob-header">
            <h1>{{ $bucketLabel }}</h1>
            <p>{{ $start->format('M d, Y') }} &ndash; {{ $end->format('M d, Y') }} &middot; {{ $bookings->total() }} booking(s)</p>
        </header>

        <div class="oa-card oa-list-card">
            @if ($bookings->count() === 0)
                <div class="oa-empty oa-empty--tall">No bookings in this range.</div>
            @else
                <div class="oa-table-wrap">
                    <table class="oa-table">
                        <thead>
                            <tr>
                                <th scope="col">Booking #</th>
                                <th scope="col">Date</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Truck Type</th>
                                <th scope="col">Unit</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="is-num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php $booking = $row['booking']; @endphp
                                <tr>
                                    <td>
                                        <a class="oa-code" href="{{ route('superadmin.bookings.index', ['search' => $booking->job_code]) }}">{{ $booking->job_code }}</a>
                                    </td>
                                    <td>
                                        {{ $booking->created_at?->format('M d, Y') }}
                                        <span class="oa-sub">{{ $booking->created_at?->format('h:i A') }}</span>
                                    </td>
                                    <td>{{ $booking->customer->full_name ?? 'Guest' }}</td>
                                    <td>{{ $booking->truckType->name ?? '—' }}</td>
                                    <td>{{ $booking->display_unit_name ?? '—' }}</td>
                                    <td><span class="oa-badge tone-{{ $row['status']['tone'] }}">{{ $row['status']['label'] }}</span></td>
                                    <td class="is-num">₱{{ number_format((float) $booking->final_total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @include('superadmin.customers._pager', ['paginator' => $bookings, 'noun' => 'booking'])
            @endif
        </div>
    </div>
@endsection
