@extends('customer.layouts.app')

@section('title', 'Track Booking')

@section('content')
    <link rel="stylesheet" href="{{ asset('customer/css/track-list.css') }}">

    @if ($bookings->count())

        <div class="track-wrapper">

            <div class="track-header">
                <h2>Active Bookings</h2>
            </div>

            <div class="history-list">

                @foreach ($bookings as $booking)
                    <div class="history-card">

                        <div class="card-left">

                            <div class="card-id">
                                <h4>#TM-{{ $booking->id }}</h4>
                                <span>{{ $booking->created_at->format('M d, Y • H:i') }}</span>

                                <span class="status-badge">
                                    {{ ucfirst($booking->status) }}
                                </span>
                            </div>

                            <div class="card-route">
                                <div class="route-text">
                                    <p>{{ $booking->pickup_address }}</p>
                                    <small>{{ $booking->dropoff_address }}</small>
                                </div>
                            </div>

                        </div>

                        <div class="card-right">

                            <div class="price-block">
                                <span>Driver</span>
                                <strong>
                                    {{ optional(optional($booking->unit)->driver)->name ?? 'Waiting for driver' }}
                                </strong>
                            </div>

                            <div class="actions">
                                <a href="{{ route('customer.track', $booking->id) }}" class="btn-view">
                                    Track
                                </a>
                            </div>

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
