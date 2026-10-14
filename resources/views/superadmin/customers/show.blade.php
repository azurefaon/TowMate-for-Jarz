@extends('layouts.superadmin')

@section('title', $customer->full_name ?: 'Customer')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/customers.css') }}?v={{ filemtime(public_path('superadmin/css/customers.css')) }}">
@endpush

@section('content')
    <div class="ob-page" id="ownerCustomer">

        <a href="{{ route('superadmin.customers.index') }}" class="oc-back">
            <i data-lucide="arrow-left" aria-hidden="true"></i> Back to Customers
        </a>

        <section class="ob-card oc-profile" aria-label="Customer information">
            <div class="oc-title-row">
                <h1 class="oc-customer-name">{{ $customer->full_name ?: 'Customer' }}</h1>
                <span class="ob-badge tone-{{ $risk['tone'] }}">{{ $risk['label'] }}</span>
            </div>

            <dl class="oc-facts">
                <div><dt>Phone</dt><dd>{{ $phone ?: '—' }}</dd></div>
                <div><dt>Email</dt><dd>{{ $customer->email ?: '—' }}</dd></div>
                <div><dt>Customer type</dt><dd>{{ $type }}</dd></div>
                <div><dt>Bookings</dt><dd>{{ number_format($bookingCount) }}</dd></div>
                <div><dt>Joined</dt><dd>{{ $customer->created_at?->format('M j, Y') ?? '—' }}</dd></div>
            </dl>

            @if ($risk['tone'] !== 'slate')
                <div class="oc-risk-note">
                    <strong>Risk reason:</strong> {{ $customer->risk_reason ?: 'No reason recorded.' }}
                    @if ($customer->blacklisted_at)
                        <br><strong>Blacklisted on:</strong> {{ $customer->blacklisted_at->format('M j, Y') }}
                    @endif
                </div>
            @endif
        </section>

        <h2 class="oc-section-title">Booking history</h2>

        <div class="ob-card">
            @if ($bookings->count() === 0)
                <div class="ob-state">
                    <span class="ob-state-icon"><i data-lucide="clipboard-list" aria-hidden="true"></i></span>
                    <p class="ob-state-title">No bookings yet</p>
                    <p class="ob-state-text">This customer has not made a booking.</p>
                </div>
            @else
                <div class="ob-table-wrap">
                    <table class="ob-table oc-table">
                        <thead>
                            <tr>
                                <th scope="col">Booking</th>
                                <th scope="col">Truck</th>
                                <th scope="col">Unit</th>
                                <th scope="col">Date</th>
                                <th scope="col" class="ob-col-amount">Amount</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php $booking = $row['booking']; @endphp
                                <tr class="ob-row">
                                    <td>
                                        <a class="oc-name" href="{{ route('superadmin.bookings.index', ['search' => $booking->job_code]) }}">{{ $booking->job_code }}</a>
                                    </td>
                                    <td>{{ $booking->truckType->name ?? '—' }}</td>
                                    <td>
                                        @if ($booking->unit)
                                            {{ $booking->unit->name }}
                                        @else
                                            <span class="ob-sub">Not assigned</span>
                                        @endif
                                    </td>
                                    <td>{{ $booking->created_at?->format('M j, Y') ?? '—' }}</td>
                                    <td class="ob-amount">
                                        @if ($row['amount']['amount'] !== null)
                                            <span class="ob-main">₱{{ number_format($row['amount']['amount'], 2) }}</span>
                                            @if ($row['amount']['note'])
                                                <span class="ob-sub">{{ $row['amount']['note'] }}</span>
                                            @endif
                                        @else
                                            <span class="ob-dash" aria-label="No amount yet">—</span>
                                        @endif
                                    </td>
                                    <td><span class="ob-badge tone-{{ $row['status']['tone'] }}">{{ $row['status']['label'] }}</span></td>
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
