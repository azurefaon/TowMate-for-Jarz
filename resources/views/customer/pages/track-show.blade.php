@extends('customer.layouts.app')

@section('title', 'Track Booking · ' . ($booking->job_code ?? ''))

@section('content')
@php
    $stages = [
        ['key' => 'received',   'label' => 'Received',     'statuses' => ['requested', 'reviewed']],
        ['key' => 'quotation',  'label' => 'Quotation',    'statuses' => ['quoted', 'quotation_sent']],
        ['key' => 'confirmed',  'label' => 'Confirmed',    'statuses' => ['confirmed']],
        ['key' => 'crew_sent',  'label' => 'Crew Sent',    'statuses' => ['assigned']],
        ['key' => 'on_the_way', 'label' => 'On the Way',   'statuses' => ['on_the_way']],
        ['key' => 'towing',     'label' => 'Towing',       'statuses' => ['in_progress']],
        ['key' => 'payment',    'label' => 'Payment',      'statuses' => ['payment_pending', 'payment_submitted']],
    ];

    $currentStageIdx = 0;
    foreach ($stages as $i => $stage) {
        if (in_array($booking->status, $stage['statuses'])) {
            $currentStageIdx = $i;
            break;
        }
    }

    $preDispatchStatuses = ['requested', 'reviewed', 'quoted', 'quotation_sent', 'confirmed'];
    $isPreDispatch = in_array($booking->status, $preDispatchStatuses);

    $statusMessages = [
        'requested'         => 'Got it — we\'re looking over your trip details now. Hang tight.',
        'reviewed'          => 'Our team reviewed your request and is putting together the price.',
        'quoted'            => 'Your quote is ready. Check the amount below and let us know if it works.',
        'quotation_sent'    => 'We sent you a quotation by email. Review and accept it when you\'re ready.',
        'confirmed'         => 'Booking confirmed. We\'re getting a crew ready to head your way.',
        'assigned'          => 'A tow truck crew has been assigned to your booking.',
        'on_the_way'        => 'Your tow truck is on the way to the pickup point right now.',
        'in_progress'       => 'The tow is underway — your vehicle is being taken care of.',
        'payment_pending'   => 'Service done. The crew is sorting out the payment with you now.',
        'payment_submitted' => 'Payment submitted. We\'re confirming everything on our end.',
    ];

    $statusLabels = [
        'requested'         => 'Pending Review',
        'reviewed'          => 'Being Reviewed',
        'quoted'            => 'Quote Ready',
        'quotation_sent'    => 'Quote Sent',
        'confirmed'         => 'Confirmed',
        'assigned'          => 'Crew Assigned',
        'on_the_way'        => 'On the Way',
        'in_progress'       => 'In Progress',
        'payment_pending'   => 'Collecting Payment',
        'payment_submitted' => 'Payment Submitted',
    ];

    $statusLabel  = $statusLabels[$booking->status] ?? ucwords(str_replace('_', ' ', $booking->status));
    $statusMsg    = $statusMessages[$booking->status] ?? 'Your booking is active.';
    $finalTotal   = (float) ($booking->final_total ?? $booking->computed_total ?? 0);

    $driverName = $booking->driver_name
        ?? optional(optional($booking->unit)->driver)->full_name
        ?? optional(optional($booking->unit)->driver)->name
        ?? null;
    $tlName = optional($booking->assignedTeamLeader)->full_name
        ?? optional($booking->assignedTeamLeader)->name
        ?? null;

    $statusColor = match ($booking->status) {
        'requested', 'reviewed'           => ['bg' => '#fff7ed', 'text' => '#c2410c', 'dot' => '#fb923c'],
        'quoted', 'quotation_sent'        => ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'dot' => '#60a5fa'],
        'confirmed'                       => ['bg' => '#f0fdf4', 'text' => '#15803d', 'dot' => '#4ade80'],
        'assigned'                        => ['bg' => '#faf5ff', 'text' => '#7c3aed', 'dot' => '#a78bfa'],
        'on_the_way'                      => ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'dot' => '#3b82f6'],
        'in_progress'                     => ['bg' => '#fefce8', 'text' => '#a16207', 'dot' => '#facc15'],
        'payment_pending', 'payment_submitted' => ['bg' => '#f0fdf4', 'text' => '#15803d', 'dot' => '#22c55e'],
        default                           => ['bg' => '#f4f4f5', 'text' => '#3f3f46', 'dot' => '#a1a1aa'],
    };

    $activeForPolling = in_array($booking->status, ['assigned', 'on_the_way', 'in_progress', 'payment_pending', 'payment_submitted']);
@endphp

<style>
    .trk-wrap {
        max-width: 900px;
        margin: 0 auto;
        padding: 0 0 60px;
    }

    .trk-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        font-weight: 600;
        color: #52525b;
        text-decoration: none;
        margin-bottom: 20px;
        transition: color .15s;
    }
    .trk-back:hover { color: #09090b; }

    /* Header */
    .trk-header {
        background: #fff;
        border: 1px solid #e4e4e7;
        border-radius: 18px;
        padding: 22px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 14px;
        flex-wrap: wrap;
    }
    .trk-code {
        font-size: 19px;
        font-weight: 800;
        color: #09090b;
        letter-spacing: -.01em;
    }
    .trk-meta {
        font-size: 12px;
        color: #71717a;
        margin-top: 3px;
    }
    .trk-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 7px 14px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .02em;
        white-space: nowrap;
    }
    .trk-pill-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
    }

    /* Timeline */
    .trk-timeline {
        background: #fff;
        border: 1px solid #e4e4e7;
        border-radius: 18px;
        padding: 22px 24px 18px;
        margin-bottom: 14px;
        overflow-x: auto;
    }
    .trk-timeline-inner {
        display: flex;
        align-items: flex-start;
        min-width: 540px;
    }
    .trk-stage {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 7px;
        flex: 1;
    }
    .trk-bubble {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 800;
        border: 2px solid #d4d4d8;
        background: #f4f4f5;
        color: #71717a;
        transition: all .2s;
        flex-shrink: 0;
    }
    .trk-stage.done .trk-bubble {
        background: #16a34a;
        border-color: #16a34a;
        color: #fff;
    }
    .trk-stage.active .trk-bubble {
        background: #facc15;
        border-color: #eab308;
        color: #09090b;
        box-shadow: 0 0 0 4px rgba(234, 179, 8, .18);
    }
    .trk-stage-label {
        font-size: 10px;
        font-weight: 600;
        color: #a1a1aa;
        text-align: center;
        letter-spacing: .02em;
        line-height: 1.3;
    }
    .trk-stage.done .trk-stage-label { color: #16a34a; }
    .trk-stage.active .trk-stage-label { color: #09090b; font-weight: 700; }
    .trk-connector {
        flex: 1;
        height: 2px;
        background: #e4e4e7;
        margin-top: 16px;
        min-width: 12px;
        transition: background .2s;
    }
    .trk-connector.done { background: #16a34a; }

    /* Status message */
    .trk-msg-card {
        background: #09090b;
        border-radius: 14px;
        padding: 18px 22px;
        color: #fff;
        font-size: 14px;
        font-weight: 500;
        line-height: 1.55;
        margin-bottom: 14px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .trk-msg-icon {
        font-size: 22px;
        flex-shrink: 0;
    }

    /* Grid */
    .trk-grid {
        display: grid;
        grid-template-columns: 1.1fr .9fr;
        gap: 14px;
    }
    @media (max-width: 640px) {
        .trk-grid { grid-template-columns: 1fr; }
    }

    /* Cards */
    .trk-card {
        background: #fff;
        border: 1px solid #e4e4e7;
        border-radius: 18px;
        padding: 20px 22px;
    }
    .trk-card + .trk-card { margin-top: 14px; }
    .trk-section-label {
        font-size: 10px;
        font-weight: 800;
        color: #a1a1aa;
        text-transform: uppercase;
        letter-spacing: .1em;
        margin-bottom: 14px;
        padding-bottom: 10px;
        border-bottom: 1px solid #f4f4f5;
    }

    /* Route */
    .trk-route-row {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 2px 0;
    }
    .trk-route-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        margin-top: 4px;
        flex-shrink: 0;
    }
    .trk-route-dot.pickup  { background: #22c55e; }
    .trk-route-dot.dropoff { background: #3b82f6; }
    .trk-route-lbl {
        font-size: 10px;
        font-weight: 700;
        color: #a1a1aa;
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: 2px;
    }
    .trk-route-addr {
        font-size: 13px;
        color: #18181b;
        font-weight: 500;
        line-height: 1.4;
    }
    .trk-route-divider {
        width: 2px;
        height: 16px;
        background: #e4e4e7;
        border-radius: 2px;
        margin: 4px 0 4px 4px;
    }

    /* Details table */
    .trk-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid #f4f4f5;
    }
    .trk-detail-lbl {
        font-size: 11px;
        color: #a1a1aa;
        font-weight: 600;
        margin-bottom: 3px;
    }
    .trk-detail-val {
        font-size: 13px;
        color: #09090b;
        font-weight: 600;
    }

    /* Crew */
    .trk-crew-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 0;
    }
    .trk-crew-row + .trk-crew-row {
        border-top: 1px solid #f4f4f5;
    }
    .trk-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #09090b;
        color: #fff;
        font-size: 15px;
        font-weight: 800;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .trk-avatar.alt { background: #3b82f6; }
    .trk-crew-name {
        font-size: 14px;
        font-weight: 700;
        color: #09090b;
    }
    .trk-crew-role {
        font-size: 11px;
        color: #71717a;
        margin-top: 1px;
    }

    /* Dispatcher note */
    .trk-note {
        background: #fff7ed;
        border: 1px solid #fed7aa;
        border-radius: 12px;
        padding: 14px 16px;
        font-size: 13px;
        color: #9a3412;
        line-height: 1.5;
        margin-top: 14px;
    }
    .trk-note strong {
        display: block;
        margin-bottom: 4px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    /* Pre-dispatch form */
    .trk-form {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 14px;
    }
    .trk-form select,
    .trk-form input,
    .trk-form textarea {
        width: 100%;
        border: 1px solid #d4d4d8;
        border-radius: 10px;
        padding: 11px 13px;
        font: inherit;
        font-size: 13px;
        color: #09090b;
        background: #fff;
        box-sizing: border-box;
        outline: none;
        transition: border-color .15s;
    }
    .trk-form select:focus,
    .trk-form input:focus,
    .trk-form textarea:focus { border-color: #09090b; }
    .trk-form button {
        background: #09090b;
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 12px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
    }

    /* Price summary (pre-dispatch) */
    .trk-price-rows {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .trk-price-row {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
        color: #52525b;
    }
    .trk-price-row.total {
        font-size: 15px;
        font-weight: 800;
        color: #09090b;
        padding-top: 10px;
        border-top: 1px solid #e4e4e7;
        margin-top: 4px;
    }

    /* Refresh note */
    .trk-refresh {
        text-align: center;
        font-size: 12px;
        color: #a1a1aa;
        margin-top: 22px;
    }

    /* Emoji icons map */
    .trk-msg-icon-requested  { content: '📋'; }
</style>

<div class="trk-wrap">

    <a href="{{ route('customer.track.index') }}" class="trk-back">
        ← My Bookings
    </a>

    {{-- ── Header ── --}}
    <div class="trk-header">
        <div>
            <div class="trk-code">Booking #{{ $booking->job_code }}</div>
            <div class="trk-meta">
                {{ $booking->created_at->format('F d, Y') }}
                &middot;
                {{ $booking->service_mode_label ?? ($booking->service_type === 'book_now' ? 'Book Now' : 'Scheduled') }}
            </div>
        </div>
        <span class="trk-pill" style="background:{{ $statusColor['bg'] }};color:{{ $statusColor['text'] }};">
            <span class="trk-pill-dot" style="background:{{ $statusColor['dot'] }};"></span>
            {{ $statusLabel }}
        </span>
    </div>

    {{-- ── Timeline stepper ── --}}
    <div class="trk-timeline">
        <div class="trk-timeline-inner">
            @foreach ($stages as $i => $stage)
                @php
                    $cls = $i < $currentStageIdx ? 'done' : ($i === $currentStageIdx ? 'active' : 'pending');
                @endphp
                <div class="trk-stage {{ $cls }}">
                    <div class="trk-bubble">
                        @if ($i < $currentStageIdx) ✓ @else {{ $i + 1 }} @endif
                    </div>
                    <div class="trk-stage-label">{{ $stage['label'] }}</div>
                </div>
                @if (!$loop->last)
                    <div class="trk-connector {{ $i < $currentStageIdx ? 'done' : '' }}"></div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- ── Status message bar ── --}}
    <div class="trk-msg-card">
        <span class="trk-msg-icon">
            @if (in_array($booking->status, ['requested', 'reviewed'])) 📋
            @elseif (in_array($booking->status, ['quoted', 'quotation_sent'])) 💰
            @elseif ($booking->status === 'confirmed') ✅
            @elseif ($booking->status === 'assigned') 🚚
            @elseif ($booking->status === 'on_the_way') 📍
            @elseif ($booking->status === 'in_progress') 🔧
            @elseif (in_array($booking->status, ['payment_pending', 'payment_submitted'])) 💳
            @else 📌
            @endif
        </span>
        <span>{{ $statusMsg }}</span>
    </div>

    {{-- ── Main grid ── --}}
    <div class="trk-grid">

        {{-- LEFT: Route + Booking Details --}}
        <div>
            <div class="trk-card">
                <div class="trk-section-label">Route</div>

                <div class="trk-route-row">
                    <div class="trk-route-dot pickup"></div>
                    <div>
                        <div class="trk-route-lbl">Pickup</div>
                        <div class="trk-route-addr">{{ $booking->pickup_address }}</div>
                    </div>
                </div>
                <div class="trk-route-divider"></div>
                <div class="trk-route-row">
                    <div class="trk-route-dot dropoff"></div>
                    <div>
                        <div class="trk-route-lbl">Drop-off</div>
                        <div class="trk-route-addr">{{ $booking->dropoff_address }}</div>
                    </div>
                </div>

                <div class="trk-details">
                    <div>
                        <div class="trk-detail-lbl">Vehicle type</div>
                        <div class="trk-detail-val">{{ $booking->truckType->name ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="trk-detail-lbl">Reference</div>
                        <div class="trk-detail-val" style="font-family:monospace;">{{ $booking->job_code }}</div>
                    </div>
                    @if ($finalTotal > 0)
                    <div>
                        <div class="trk-detail-lbl">Total amount</div>
                        <div class="trk-detail-val">₱{{ number_format($finalTotal, 2) }}</div>
                    </div>
                    @endif
                    @if ($booking->distance_km)
                    <div>
                        <div class="trk-detail-lbl">Distance</div>
                        <div class="trk-detail-val">{{ number_format((float)$booking->distance_km, 1) }} km</div>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Pre-dispatch price breakdown --}}
            @if ($isPreDispatch && $finalTotal > 0)
            @php
                $baseRate     = (float) ($booking->base_rate ?? 0);
                $distanceKm   = (float) ($booking->distance_km ?? 0);
                $perKmRate    = (float) ($booking->per_km_rate ?? 0);
                $distanceFee  = $distanceKm > 0 && $perKmRate > 0 ? round($distanceKm * $perKmRate, 2) : 0;
                $addFee       = (float) ($booking->additional_fee ?? 0);
                $discountAmt  = (float) ($booking->discount_percentage > 0 ? ($booking->computed_total * $booking->discount_percentage / 100) : 0);
            @endphp
            <div class="trk-card" style="margin-top:14px;">
                <div class="trk-section-label">Price Breakdown</div>
                <div class="trk-price-rows">
                    @if ($baseRate > 0)
                    <div class="trk-price-row">
                        <span>Base rate</span>
                        <span>₱{{ number_format($baseRate, 2) }}</span>
                    </div>
                    @endif
                    @if ($distanceFee > 0)
                    <div class="trk-price-row">
                        <span>Distance fee</span>
                        <span>₱{{ number_format($distanceFee, 2) }}</span>
                    </div>
                    @endif
                    @if ($addFee > 0)
                    <div class="trk-price-row">
                        <span>Additional fees</span>
                        <span>₱{{ number_format($addFee, 2) }}</span>
                    </div>
                    @endif
                    @if ($discountAmt > 0)
                    <div class="trk-price-row" style="color:#16a34a;">
                        <span>Discount</span>
                        <span>-₱{{ number_format($discountAmt, 2) }}</span>
                    </div>
                    @endif
                    <div class="trk-price-row total">
                        <span>Total</span>
                        <span>₱{{ number_format($finalTotal, 2) }}</span>
                    </div>
                </div>
            </div>
            @endif

            {{-- Dispatcher note --}}
            @if (filled($booking->dispatcher_note))
            <div class="trk-note">
                <strong>Note from dispatch</strong>
                {{ $booking->dispatcher_note }}
            </div>
            @endif
        </div>

        {{-- RIGHT: Crew info + Actions --}}
        <div>
            @if ($driverName || $tlName)
            <div class="trk-card">
                <div class="trk-section-label">Assigned Crew</div>
                @if ($tlName)
                <div class="trk-crew-row">
                    <div class="trk-avatar">{{ strtoupper(substr($tlName, 0, 1)) }}</div>
                    <div>
                        <div class="trk-crew-name">{{ $tlName }}</div>
                        <div class="trk-crew-role">Team Leader</div>
                    </div>
                </div>
                @endif
                @if ($driverName)
                <div class="trk-crew-row">
                    <div class="trk-avatar alt">{{ strtoupper(substr($driverName, 0, 1)) }}</div>
                    <div>
                        <div class="trk-crew-name">{{ $driverName }}</div>
                        <div class="trk-crew-role">Driver</div>
                    </div>
                </div>
                @endif
            </div>
            @else
            <div class="trk-card">
                <div class="trk-section-label">Assigned Crew</div>
                <div style="font-size:13px;color:#a1a1aa;padding:4px 0;">
                    @if (in_array($booking->status, ['requested', 'reviewed', 'quoted', 'quotation_sent']))
                        A crew will be assigned once your booking is confirmed.
                    @else
                        Crew info will appear here once one is assigned.
                    @endif
                </div>
            </div>
            @endif

            {{-- Pre-dispatch: update form --}}
            @if ($isPreDispatch)
            <div class="trk-card" style="margin-top:14px;">
                <div class="trk-section-label">Update Trip Details</div>
                <p style="margin:0 0 12px;font-size:13px;color:#71717a;line-height:1.5;">Need to change the pickup spot or vehicle? You can still update it here.</p>
                <form action="{{ route('customer.booking.update', $booking) }}" method="POST" class="trk-form">
                    @csrf
                    @method('PATCH')
                    <select name="truck_type_id">
                        @foreach ($truckTypes ?? [] as $t)
                            <option value="{{ $t->id }}" @selected((int)$t->id === (int)$booking->truck_type_id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="pickup_address" value="{{ $booking->pickup_address }}" placeholder="Pickup address" required>
                    <input type="text" name="dropoff_address" value="{{ $booking->dropoff_address }}" placeholder="Drop-off address" required>
                    <input type="number" step="0.1" min="0.1" name="distance_km" value="{{ $booking->distance_km }}" placeholder="Distance (km)" required>
                    <textarea name="pickup_notes" rows="2" placeholder="Notes or landmark">{{ $booking->pickup_notes }}</textarea>
                    <button type="submit">Update & Refresh Quote</button>
                </form>
            </div>
            @endif

            {{-- Negotiation note --}}
            @if (filled($booking->customer_response_note) || filled($booking->counter_offer_amount))
            <div style="margin-top:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 16px;font-size:13px;color:#1d4ed8;line-height:1.5;">
                <strong style="display:block;margin-bottom:4px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;">Your last negotiation request</strong>
                @if (filled($booking->counter_offer_amount))
                    Counter-offer: ₱{{ number_format((float)$booking->counter_offer_amount, 2) }}<br>
                @endif
                {{ $booking->customer_response_note ?? '' }}
            </div>
            @endif
        </div>

    </div>{{-- /trk-grid --}}

    {{-- Auto-refresh note --}}
    <div class="trk-refresh" id="trkRefreshNote">
        @if ($activeForPolling)
            Updates automatically &mdash; refreshes every 30 seconds.
        @else
            This page refreshes when there's a new update.
        @endif
    </div>

</div>{{-- /trk-wrap --}}

@if ($activeForPolling)
<script>
(function () {
    var secs = 30;
    var note = document.getElementById('trkRefreshNote');
    var timer = setInterval(function () {
        secs--;
        if (note) {
            note.textContent = secs > 0
                ? 'Refreshing in ' + secs + 's…'
                : 'Refreshing…';
        }
        if (secs <= 0) {
            clearInterval(timer);
            window.location.reload();
        }
    }, 1000);
})();
</script>
@endif

@endsection
