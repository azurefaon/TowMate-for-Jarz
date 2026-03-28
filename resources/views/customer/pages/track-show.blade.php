@extends('customer.layouts.app')

@section('title', 'Track Booking')

@section('content')
    <link rel="stylesheet" href="{{ asset('customer/css/track.css') }}">

    @if ($booking)
        <div class="track-wrapper-grab">

            <!-- HEADER -->
            <div class="track-topbar">
                <a href="{{ route('customer.track.index') }}" class="back-btn">
                    <i data-lucide="arrow-left"></i>
                </a>

                <div>
                    <h3>Tracking Tow</h3>
                    <p>#TM-{{ $booking->id }}</p>
                </div>
            </div>

            <!-- MAP -->
            <div class="map-full">
                <div id="map"></div>
            </div>

            <!-- FLOATING PANEL -->
            <div class="floating-panel">

                <!-- ETA -->
                <div class="eta-box">
                    <h1>{{ $booking->eta ?? '12' }}</h1>
                    <span>mins away</span>
                </div>

                <!-- DRIVER -->
                <div class="driver-box">
                    <div class="driver-info">
                        <div class="driver-avatar">
                            {{ strtoupper(substr(optional(optional($booking->unit)->driver)->name ?? 'N', 0, 1)) }}
                        </div>

                        <div>
                            <strong>{{ optional(optional($booking->unit)->driver)->name ?? 'Waiting for driver' }}</strong>
                            <p>Driver</p>
                        </div>
                    </div>

                    <button class="cancel-btn" data-id="{{ $booking->id }}">
                        Cancel Booking
                    </button>
                </div>

                <div class="details-box">

                    <div class="detail-item">
                        <span class="label">Pickup</span>
                        <p class="value">{{ $booking->pickup_address }}</p>
                    </div>

                    <div class="detail-item">
                        <span class="label">Dropoff</span>
                        <p class="value">{{ $booking->dropoff_address }}</p>
                    </div>

                    <div class="detail-item">
                        <span class="label">Vehicle</span>
                        <p class="value">{{ $booking->truckType->name ?? '-' }}</p>
                    </div>

                </div>

            </div>

        </div>
    @else
        <div class="track-wrapper center-track">
            <h2>Booking not found</h2>
        </div>
    @endif

    <script src="{{ asset('customer/js/track.js') }}"></script>

@endsection
