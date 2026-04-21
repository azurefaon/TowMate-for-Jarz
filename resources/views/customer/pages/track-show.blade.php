@extends('customer.layouts.app')

@section('title', 'Track Booking')

@section('content')
    <link rel="stylesheet" href="{{ asset('customer/css/track.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    @php
        $preDispatchStatuses = ['requested', 'reviewed', 'quoted', 'quotation_sent', 'confirmed'];
        $isPreDispatch = $booking && in_array($booking->status, $preDispatchStatuses, true);
        $statusLabel = $booking ? strtoupper(str_replace('_', ' ', $booking->status)) : 'PENDING';
        $statusMessage = match ($booking->status ?? null) {
            'requested'
                => 'Your booking request was received. Dispatch is reviewing the trip details and service needs now.',
            'reviewed'
                => 'Dispatch is reviewing the latest pricing update. The amount shown below stays available for your records.',
            'quoted',
            'quotation_sent'
                => 'Dispatch has set the current price summary for your booking. The details below are now view-only on your side.',
            'confirmed' => 'Your confirmed amount is already saved as part of your booking record.',
            'assigned' => 'A tow unit has been assigned and is preparing to move.',
            'on_the_way' => 'Your crew is already on the way to the pickup location.',
            'in_progress' => 'Your towing service is currently in progress.',
            'waiting_verification' => 'The team has requested final customer confirmation.',
            default => 'Your booking is active and being monitored.',
        };
    @endphp

    @if ($booking)
        @if ($isPreDispatch)
            <style>
                .quote-flow-wrapper {
                    max-width: 1120px;
                    margin: 0 auto;
                    padding: 12px 0 24px;
                }

                .quote-flow-grid {
                    display: grid;
                    grid-template-columns: 1.2fr 0.8fr;
                    gap: 20px;
                    margin-top: 16px;
                }

                .quote-summary-card,
                .quote-action-card {
                    background: #fff;
                    border: 1px solid #e5e7eb;
                    border-radius: 18px;
                    padding: 22px;
                    box-shadow: 0 14px 36px rgba(15, 23, 42, 0.06);
                }

                .quote-status-pill {
                    display: inline-flex;
                    align-items: center;
                    padding: 6px 12px;
                    border-radius: 999px;
                    font-size: 12px;
                    font-weight: 700;
                    letter-spacing: 0.04em;
                    background: #e0f2fe;
                    color: #075985;
                    margin-bottom: 12px;
                }

                .quote-status-pill.reviewed {
                    background: #fff7ed;
                    color: #c2410c;
                }

                .quote-status-pill.quoted,
                .quote-status-pill.quotation_sent {
                    background: #eef2ff;
                    color: #4338ca;
                }

                .quote-status-pill.confirmed {
                    background: #ecfdf5;
                    color: #047857;
                }

                .quote-meta {
                    display: grid;
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    gap: 12px;
                    margin-top: 18px;
                }

                .quote-meta div {
                    background: #f8fafc;
                    border-radius: 12px;
                    padding: 12px 14px;
                }

                .quote-meta span {
                    display: block;
                    font-size: 12px;
                    color: #64748b;
                    margin-bottom: 4px;
                }

                .quote-meta strong,
                .quote-meta p {
                    margin: 0;
                    color: #0f172a;
                }

                .quote-note-box {
                    margin-top: 16px;
                    padding: 14px 16px;
                    border-radius: 12px;
                    background: #fff7ed;
                    border: 1px solid #fed7aa;
                    color: #9a3412;
                }

                .quote-form {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                    margin-top: 16px;
                }

                .quote-form input,
                .quote-form textarea,
                .quote-form select {
                    width: 100%;
                    border: 1px solid #d1d5db;
                    border-radius: 12px;
                    padding: 12px 14px;
                    font: inherit;
                    background: #fff;
                }

                .quote-btn,
                .quote-secondary-btn {
                    width: 100%;
                    border: 0;
                    border-radius: 12px;
                    padding: 12px 16px;
                    font-weight: 700;
                    cursor: pointer;
                }

                .quote-btn {
                    background: #0f172a;
                    color: #fff;
                }

                .quote-secondary-btn {
                    background: #facc15;
                    color: #111827;
                }

                .quote-empty-card {
                    background: #f8fafc;
                    border-radius: 14px;
                    padding: 16px;
                    color: #475569;
                }

                @media (max-width: 900px) {
                    .quote-flow-grid {
                        grid-template-columns: 1fr;
                    }
                }
            </style>

            <div class="quote-flow-wrapper">
                <a href="{{ route('customer.track.index') }}" class="map-back-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path d="M15 18L9 12L15 6" stroke="#0f172a" stroke-width="2" />
                    </svg>
                    <span>Back</span>
                </a>

                <div class="quote-flow-grid">
                    <div class="quote-summary-card">
                        <span class="quote-status-pill {{ strtolower($booking->status) }}">{{ $statusLabel }}</span>
                        <h2 style="margin: 0 0 8px;">Booking Price Summary</h2>
                        <p style="margin: 0; color: #475569;">{{ $statusMessage }}</p>

                        <div class="quote-meta">
                            <div>
                                <span>Booking #</span>
                                <strong>{{ $booking->job_code }}</strong>
                            </div>
                            <div>
                                <span>Quotation #</span>
                                <strong>{{ $booking->quotation_number ?? 'Pending dispatch review' }}</strong>
                            </div>
                            <div>
                                <span>Vehicle Type</span>
                                <p>{{ $booking->truckType->name ?? '-' }}</p>
                            </div>
                            <div>
                                <span>Current Price</span>
                                <strong>₱{{ number_format((float) ($booking->final_total ?? 0), 2) }}</strong>
                            </div>
                            <div>
                                <span>Pickup</span>
                                <p>{{ $booking->pickup_address }}</p>
                            </div>
                            <div>
                                <span>Drop-off</span>
                                <p>{{ $booking->dropoff_address }}</p>
                            </div>
                        </div>

                        @if (filled($booking->dispatcher_note))
                            <div class="quote-note-box">
                                <strong>Dispatch note:</strong><br>
                                {{ $booking->dispatcher_note }}
                            </div>
                        @endif

                        @if (filled($booking->customer_response_note) || filled($booking->counter_offer_amount))
                            <div class="quote-note-box" style="background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8;">
                                <strong>Your latest negotiation request:</strong><br>
                                @if (filled($booking->counter_offer_amount))
                                    Counter-offer: ₱{{ number_format((float) $booking->counter_offer_amount, 2) }}<br>
                                @endif
                                {{ $booking->customer_response_note ?? 'Requested a quotation adjustment.' }}
                            </div>
                        @endif
                    </div>

                    <div class="quote-action-card">
                        @php
                            $quoteBreakdown = $booking->quotation_breakdown ?? [];
                            $baseRate = (float) ($quoteBreakdown['base_rate'] ?? ($booking->base_rate ?? 0));
                            $distanceFee =
                                (float) ($quoteBreakdown['distance_fee'] ??
                                    ($booking->distance_km ?? 0) * ($booking->per_km_rate ?? 0));
                            $additionalFee = (float) ($quoteBreakdown['additional_fee'] ?? 0);
                            $discountAmount = (float) ($quoteBreakdown['discount'] ?? 0);
                            $displayTotal =
                                (float) ($quoteBreakdown['final_total'] ??
                                    ($booking->final_total ?? ($booking->computed_total ?? 0)));
                        @endphp

                        <h3 style="margin-top: 0;">Price Breakdown</h3>
                        <p style="color: #475569;">This section is now read-only and serves as your booking price record.
                        </p>

                        <div class="quote-empty-card">
                            <p style="margin: 0 0 10px;"><strong>Base rate:</strong> ₱{{ number_format($baseRate, 2) }}</p>
                            <p style="margin: 0 0 10px;"><strong>Distance fee:</strong>
                                ₱{{ number_format($distanceFee, 2) }}</p>
                            @if ($additionalFee > 0)
                                <p style="margin: 0 0 10px;"><strong>Additional fee:</strong>
                                    ₱{{ number_format($additionalFee, 2) }}</p>
                            @endif
                            @if ($discountAmount > 0)
                                <p style="margin: 0 0 10px;"><strong>Discount:</strong> -
                                    ₱{{ number_format($discountAmount, 2) }}</p>
                            @endif
                            <p style="margin: 0;"><strong>Estimated total:</strong> ₱{{ number_format($displayTotal, 2) }}
                            </p>
                        </div>

                        <form action="{{ route('customer.booking.update', $booking) }}" method="POST" class="quote-form">
                            @csrf
                            @method('PATCH')
                            <h3 style="margin: 0;">Need to update the trip details?</h3>
                            <p style="margin: 0; color: #475569;">Correct the route or vehicle type here and the quotation
                                record will refresh automatically.</p>

                            <select name="truck_type_id" required>
                                @foreach ($truckTypes ?? [$booking->truckType] as $truckTypeOption)
                                    @if ($truckTypeOption)
                                        <option value="{{ $truckTypeOption->id }}" @selected((int) old('truck_type_id', $booking->truck_type_id) === (int) $truckTypeOption->id)>
                                            {{ $truckTypeOption->name }}
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                            <input type="text" name="pickup_address"
                                value="{{ old('pickup_address', $booking->pickup_address) }}" placeholder="Pickup address"
                                required>
                            <input type="text" name="dropoff_address"
                                value="{{ old('dropoff_address', $booking->dropoff_address) }}"
                                placeholder="Drop-off address" required>
                            <input type="number" step="0.1" min="0.1" name="distance_km"
                                value="{{ old('distance_km', $booking->distance_km) }}" placeholder="Distance in KM"
                                required>
                            <textarea name="pickup_notes" rows="3" placeholder="Pickup notes or landmark">{{ old('pickup_notes', $booking->pickup_notes) }}</textarea>
                            <button type="submit" class="quote-secondary-btn">Update booking & refresh quote</button>
                        </form>

                        @if ($booking->status === 'reviewed')
                            <div class="quote-empty-card" style="margin-top: 12px;">
                                <h3 style="margin-top: 0;">Dispatch update in progress</h3>
                                <p style="margin-bottom: 0;">The latest amount is already saved here while dispatch reviews
                                    the request.</p>
                            </div>
                        @elseif ($booking->status === 'confirmed')
                            <div class="quote-empty-card" style="margin-top: 12px; background:#ecfdf5; color:#047857;">
                                <h3 style="margin-top: 0;">Price confirmed</h3>
                                <p style="margin-bottom: 0;">Your total is already locked and kept in your booking record.
                                </p>
                            </div>
                        @else
                            <div class="quote-empty-card" style="margin-top: 12px;">
                                <h3 style="margin-top: 0;">Booking record only</h3>
                                <p style="margin-bottom: 0;">No action is required from your side. Keep this summary for
                                    reference.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @else
            <div class="track-split">

                <div class="track-map">
                    <a href="{{ route('customer.track.index') }}" class="map-back-btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                            <path d="M15 18L9 12L15 6" stroke="#0f172a" stroke-width="2" />
                        </svg>
                        <span>Back</span>
                    </a>
                    <div id="map"></div>
                </div>

                <div class="track-card-premium">
                    <div class="eta-card-clean">
                        <div class="eta-header">
                            <span>BOOKING STATUS</span>
                            <span class="status-badge">{{ $statusLabel }}</span>
                        </div>

                        <div class="eta-main">
                            <h1><span id="eta">--</span> <small>min</small></h1>
                            <p id="distance">{{ $statusMessage }}</p>
                        </div>
                    </div>

                    <div class="premium-section driver-section">
                        <div class="driver-row">
                            <div class="driver-avatar">
                                {{ strtoupper(substr($booking->driver_name ?? (optional(optional($booking->unit)->driver)->name ?? 'N'), 0, 1)) }}
                            </div>

                            <div class="driver-info">
                                <strong>
                                    {{ $booking->driver_name ?? (optional(optional($booking->unit)->driver)->name ?? 'Searching driver...') }}
                                </strong>
                                <small>Tow truck driver</small>
                            </div>
                        </div>
                    </div>

                    <div class="premium-section route-section">
                        <div class="info-row">
                            <div class="info-icon green">
                                <i data-lucide="navigation"></i>
                            </div>
                            <div class="info-text">
                                <span>PICKUP</span>
                                <p>{{ $booking->pickup_address }}</p>
                            </div>
                        </div>

                        <div class="info-row">
                            <div class="info-icon blue">
                                <i data-lucide="map-pin"></i>
                            </div>
                            <div class="info-text">
                                <span>DROPOFF</span>
                                <p>{{ $booking->dropoff_address }}</p>
                            </div>
                        </div>

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

                    <button class="cancel-premium cancel-track-btn" data-id="{{ $booking->job_code }}">
                        ✕ Cancel booking
                    </button>
                </div>
            </div>
        @endif
    @else
        <div class="track-wrapper center-track">
            <h2>Booking not found</h2>
        </div>
    @endif

    @if ($booking && !$isPreDispatch)
        <script>
            window.bookingData = {
                pickup_lat: @json($booking->pickup_lat),
                pickup_lng: @json($booking->pickup_lng),
                drop_lat: @json($booking->dropoff_lat),
                drop_lng: @json($booking->dropoff_lng)
            };
        </script>
    @endif

    {{-- Google Maps is disabled for now while Leaflet is active.
    <script
        src="https://maps.googleapis.com/maps/api/js?key={{ urlencode(config('services.google_maps.key')) }}&libraries=places">
    </script>
    --}}
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{ asset('customer/js/track.js') }}?v={{ filemtime(public_path('customer/js/track.js')) }}"></script>

@endsection
