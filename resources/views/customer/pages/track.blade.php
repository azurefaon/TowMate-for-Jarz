@extends('customer.layouts.app')

@section('title', 'Track Booking')

@section('content')
    <link rel="stylesheet" href="{{ asset('customer/css/track-list.css') }}">

    @if ($bookings->count())

        <div class="track-wrapper">

            <div class="history-list">

                @foreach ($bookings as $booking)
                    <div class="history-card">

                        <!-- LEFT -->
                        <div class="card-left">

                            <!-- TOP ROW -->
                            <div class="card-top">
                                <h4>#TM-{{ $booking->id }}</h4>
                                <span class="dot">•</span>
                                <span class="time">{{ $booking->created_at->format('M d, Y • H:i') }}</span>

                                <span class="status-badge {{ strtolower($booking->status) }}">
                                    {{ strtoupper(str_replace('_', ' ', $booking->status)) }}
                                </span>
                            </div>

                            <!-- ROUTE -->
                            <div class="card-route">

                                <div class="route-row">
                                    <div class="route-icon pickup"></div>
                                    <div>
                                        <span class="route-label">PICKUP</span>
                                        <p>{{ $booking->pickup_address }}</p>
                                    </div>
                                </div>

                                <div class="route-divider"></div>

                                <div class="route-row">
                                    <div class="route-icon dropoff"></div>
                                    <div>
                                        <span class="route-label">DROPOFF</span>
                                        <p>{{ $booking->dropoff_address }}</p>
                                    </div>
                                </div>

                            </div>

                            <!-- DRIVER -->
                            <div class="driver-row">
                                <div class="driver-avatar">
                                    {{ strtoupper(substr(optional(optional($booking->unit)->driver)->name ?? 'D', 0, 1)) }}
                                </div>
                                <div>
                                    <span class="driver-label">Driver</span>
                                    <p>
                                        {{ optional(optional($booking->unit)->driver)->name ?? 'Waiting to approve' }}
                                    </p>
                                </div>
                            </div>

                        </div>

                        <!-- RIGHT -->
                        <div class="card-right">
                            <a href="{{ route('customer.track', $booking->id) }}" class="btn-view">
                                Track →
                            </a>
                        </div>

                    </div>
                @endforeach

            </div>

        </div>
    @else
        <div class="track-wrapper center-track">

            <div class="empty-track-premium">

                <div class="empty-icon">
                    <i data-lucide="truck"></i>
                </div>

                <h2>No Active Booking</h2>

                <p>You don’t have any ongoing towing service right now.</p>

                <div class="empty-actions">
                    <a href="{{ route('customer.book') }}" class="primary-btn">Book Now</a>
                    <a href="{{ route('customer.history') }}" class="secondary-btn">View History</a>
                </div>

            </div>

        </div>

    @endif

@endsection
