@extends('customer.layouts.app')

@section('title', 'Track Booking')

@section('content')
    <link rel="stylesheet" href="{{ asset('customer/css/track.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    @if ($booking)
        <div class="track-split">

            <!-- LEFT: MAP -->
            <div class="track-map">
                <a href="{{ route('customer.track.index') }}" class="map-back-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path d="M15 18L9 12L15 6" stroke="#0f172a" stroke-width="2" />
                    </svg>
                    <span>Back</span>
                </a>
                <div id="map"></div>
            </div>

            <!-- RIGHT: DETAILS -->
            <div class="track-card-premium">

                <!-- ETA -->
                <div class="eta-card-clean">

                    <div class="eta-header">
                        <span>ESTIMATED ARRIVAL</span>
                        <span class="status-badge">On the way</span>
                    </div>

                    <div class="eta-main">
                        <h1><span id="eta">--</span> <small>min</small></h1>
                        <p id="distance">-- km away</p>
                    </div>

                </div>

                <!-- DRIVER -->
                <div class="premium-section driver-section">

                    <div class="driver-row">

                        <div class="driver-avatar">
                            {{ strtoupper(substr(optional(optional($booking->unit)->driver)->name ?? 'N', 0, 1)) }}
                        </div>

                        <div class="driver-info">
                            <strong>
                                {{ optional(optional($booking->unit)->driver)->name ?? 'Searching driver...' }}
                            </strong>
                            <small>Tow truck driver</small>
                        </div>

                    </div>

                </div>

                <!-- ROUTE -->
                <div class="premium-section route-section">

                    <!-- PICKUP -->
                    <div class="info-row">
                        <div class="info-icon green">
                            <i data-lucide="navigation"></i>
                        </div>
                        <div class="info-text">
                            <span>PICKUP</span>
                            <p>{{ $booking->pickup_address }}</p>
                        </div>
                    </div>

                    <!-- DROPOFF -->
                    <div class="info-row">
                        <div class="info-icon blue">
                            <i data-lucide="map-pin"></i>
                        </div>
                        <div class="info-text">
                            <span>DROPOFF</span>
                            <p>{{ $booking->dropoff_address }}</p>
                        </div>
                    </div>

                    <!-- VEHICLE -->
                    <div class="info-row">
                        <div class="info-icon gray">
                            <i data-lucide="truck"></i>
                        </div>
                        <div class="info-text">
                            <span>VEHICLE TYPE</span>
                            <p>{{ $booking->truckType->name ?? '-' }}</p>
                        </div>
                    </div>

                </div>

                <!-- CANCEL -->
                <button class="cancel-premium cancel-track-btn" data-id="{{ $booking->id }}">
                    ✕ Cancel booking
                </button>

            </div>

        </div>
    @else
        <div class="track-wrapper center-track">
            <h2>Booking not found</h2>
        </div>
    @endif


    @if ($booking)
        <script>
            window.bookingData = {
                pickup_lat: {{ $booking->pickup_lat }},
                pickup_lng: {{ $booking->pickup_lng }},
                drop_lat: {{ $booking->dropoff_lat }},
                drop_lng: {{ $booking->dropoff_lng }}
            };
        </script>
    @endif

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{ asset('customer/js/track.js') }}"></script>

@endsection
