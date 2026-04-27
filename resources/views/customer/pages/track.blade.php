@extends('customer.layouts.app')

@section('title', 'My Active Bookings')

@section('content')

<style>
    .trk-list-wrap {
        max-width: 720px;
        margin: 0 auto;
        padding: 0 0 60px;
    }
    .trk-list-heading {
        font-size: 22px;
        font-weight: 800;
        color: #09090b;
        margin: 0 0 4px;
    }
    .trk-list-sub {
        font-size: 13px;
        color: #71717a;
        margin: 0 0 24px;
    }

    /* Individual booking card */
    .trk-item {
        background: #fff;
        border: 1px solid #e4e4e7;
        border-radius: 18px;
        padding: 20px 22px;
        margin-bottom: 12px;
        display: flex;
        align-items: stretch;
        gap: 20px;
        transition: box-shadow .15s, border-color .15s;
        text-decoration: none;
    }
    .trk-item:hover {
        border-color: #d4d4d8;
        box-shadow: 0 6px 24px rgba(9,9,11,.07);
    }

    .trk-item-left { flex: 1; min-width: 0; }
    .trk-item-right {
        display: flex;
        align-items: center;
        flex-shrink: 0;
    }

    /* Top row */
    .trk-item-top {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }
    .trk-item-code {
        font-size: 15px;
        font-weight: 800;
        color: #09090b;
    }
    .trk-item-date {
        font-size: 12px;
        color: #a1a1aa;
    }
    .trk-item-pill {
        margin-left: auto;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 11px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .03em;
        white-space: nowrap;
    }
    .trk-item-pill-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
    }

    /* Route rows */
    .trk-item-route { display: flex; flex-direction: column; gap: 0; }
    .trk-item-route-row {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 3px 0;
    }
    .trk-item-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-top: 4px;
        flex-shrink: 0;
    }
    .trk-item-dot.pickup  { background: #22c55e; }
    .trk-item-dot.dropoff { background: #3b82f6; }
    .trk-item-route-label {
        font-size: 10px;
        font-weight: 700;
        color: #a1a1aa;
        text-transform: uppercase;
        letter-spacing: .05em;
        margin-bottom: 1px;
    }
    .trk-item-route-addr {
        font-size: 12px;
        color: #3f3f46;
        font-weight: 500;
        line-height: 1.35;
    }
    .trk-item-connector {
        width: 2px;
        height: 12px;
        background: #e4e4e7;
        border-radius: 2px;
        margin: 2px 0 2px 3px;
    }

    /* Footer row */
    .trk-item-footer {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px solid #f4f4f5;
        flex-wrap: wrap;
    }
    .trk-item-meta {
        font-size: 12px;
        color: #71717a;
    }
    .trk-item-meta strong { color: #3f3f46; }

    /* Track button */
    .trk-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #09090b;
        color: #fff;
        font-size: 13px;
        font-weight: 700;
        padding: 10px 18px;
        border-radius: 10px;
        text-decoration: none;
        white-space: nowrap;
        transition: background .15s;
    }
    .trk-btn:hover { background: #27272a; }

    /* Empty state */
    .trk-empty {
        text-align: center;
        padding: 60px 24px;
    }
    .trk-empty-icon {
        font-size: 48px;
        margin-bottom: 16px;
    }
    .trk-empty h2 {
        font-size: 20px;
        font-weight: 800;
        color: #09090b;
        margin: 0 0 8px;
    }
    .trk-empty p {
        font-size: 14px;
        color: #71717a;
        margin: 0 0 24px;
        max-width: 320px;
        margin-left: auto;
        margin-right: auto;
        line-height: 1.6;
    }
    .trk-empty-actions {
        display: flex;
        justify-content: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .trk-empty-primary {
        background: #09090b;
        color: #fff;
        padding: 12px 24px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 700;
        text-decoration: none;
    }
    .trk-empty-secondary {
        background: #f4f4f5;
        color: #3f3f46;
        padding: 12px 24px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 700;
        text-decoration: none;
    }
</style>

<div class="trk-list-wrap">

    @if ($bookings->count())

        <h1 class="trk-list-heading">Active Bookings</h1>
        <p class="trk-list-sub">{{ $bookings->count() }} {{ Str::plural('booking', $bookings->count()) }} in progress.</p>

        @foreach ($bookings as $booking)
        @php
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
                'completed'         => 'Completed',
            ];
            $pillColors = [
                'requested'         => ['bg' => '#fff7ed', 'text' => '#c2410c', 'dot' => '#fb923c'],
                'reviewed'          => ['bg' => '#fff7ed', 'text' => '#c2410c', 'dot' => '#fb923c'],
                'quoted'            => ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'dot' => '#60a5fa'],
                'quotation_sent'    => ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'dot' => '#60a5fa'],
                'confirmed'         => ['bg' => '#f0fdf4', 'text' => '#15803d', 'dot' => '#4ade80'],
                'assigned'          => ['bg' => '#faf5ff', 'text' => '#7c3aed', 'dot' => '#a78bfa'],
                'on_the_way'        => ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'dot' => '#3b82f6'],
                'in_progress'       => ['bg' => '#fefce8', 'text' => '#a16207', 'dot' => '#facc15'],
                'payment_pending'   => ['bg' => '#f0fdf4', 'text' => '#15803d', 'dot' => '#22c55e'],
                'payment_submitted' => ['bg' => '#f0fdf4', 'text' => '#15803d', 'dot' => '#22c55e'],
                'completed'         => ['bg' => '#f0fdf4', 'text' => '#166534', 'dot' => '#16a34a'],
            ];
            $pill   = $pillColors[$booking->status] ?? ['bg' => '#f4f4f5', 'text' => '#71717a', 'dot' => '#a1a1aa'];
            $label  = $statusLabels[$booking->status] ?? ucwords(str_replace('_', ' ', $booking->status));
            $driver = optional(optional($booking->unit)->driver)->name ?? null;
        @endphp

        <div class="trk-item">
            <div class="trk-item-left">
                <div class="trk-item-top">
                    <span class="trk-item-code">#{{ $booking->job_code }}</span>
                    <span class="trk-item-date">{{ $booking->created_at->format('M d, Y') }}</span>
                    <span class="trk-item-pill" style="background:{{ $pill['bg'] }};color:{{ $pill['text'] }};">
                        <span class="trk-item-pill-dot" style="background:{{ $pill['dot'] }};"></span>
                        {{ $label }}
                    </span>
                </div>

                <div class="trk-item-route">
                    <div class="trk-item-route-row">
                        <div class="trk-item-dot pickup"></div>
                        <div>
                            <div class="trk-item-route-label">Pickup</div>
                            <div class="trk-item-route-addr">{{ Str::limit($booking->pickup_address, 55) }}</div>
                        </div>
                    </div>
                    <div class="trk-item-connector"></div>
                    <div class="trk-item-route-row">
                        <div class="trk-item-dot dropoff"></div>
                        <div>
                            <div class="trk-item-route-label">Drop-off</div>
                            <div class="trk-item-route-addr">{{ Str::limit($booking->dropoff_address, 55) }}</div>
                        </div>
                    </div>
                </div>

                <div class="trk-item-footer">
                    <span class="trk-item-meta">
                        <strong>{{ $booking->truckType->name ?? 'Towing Service' }}</strong>
                    </span>
                    @if ($booking->final_total > 0)
                    <span class="trk-item-meta">
                        ₱{{ number_format((float)$booking->final_total, 2) }}
                    </span>
                    @endif
                    @if ($driver)
                    <span class="trk-item-meta">Driver: <strong>{{ $driver }}</strong></span>
                    @endif
                    <a href="{{ route('customer.track', $booking->job_code) }}" class="trk-btn" style="margin-left:auto;">
                        Track →
                    </a>
                </div>
            </div>
        </div>

        @endforeach

    @else

        <div class="trk-empty">
            <div class="trk-empty-icon">🚛</div>
            <h2>No active bookings</h2>
            <p>You don't have any ongoing towing service right now. Book one and we'll be right there.</p>
            <div class="trk-empty-actions">
                <a href="{{ route('customer.book') }}" class="trk-empty-primary">Book Now</a>
                <a href="{{ route('customer.history') }}" class="trk-empty-secondary">View History</a>
            </div>
        </div>

    @endif

</div>

@endsection
