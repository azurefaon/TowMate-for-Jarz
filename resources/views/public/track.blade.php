<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Your Booking — TowMate</title>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #ffffff;
            color: #0f172a;
            min-height: 100vh;
        }

        /* ── NAV ── */
        .nav {
            background: #09090b;
            padding: 0 24px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .nav-brand {
            font-size: 1rem;
            font-weight: 800;
            color: #facc15;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            text-decoration: none;
        }

        .nav-sub {
            font-size: 0.75rem;
            color: #a1a1aa;
            font-weight: 500;
        }

        /* ── HERO ── */
        .hero {
            background: #09090b;
            padding: 52px 24px 40px;
            text-align: center;
        }

        .hero h1 {
            font-size: 2rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 8px;
            line-height: 1.2;
        }

        .hero h1 span {
            color: #facc15;
        }

        .hero p {
            font-size: 0.95rem;
            color: #a1a1aa;
            margin-bottom: 32px;
        }

        /* ── SEARCH ── */
        .search-shell {
            max-width: 520px;
            margin: 0 auto;
        }

        .search-form {
            display: flex;
            gap: 10px;
        }

        .search-input {
            flex: 1;
            height: 50px;
            padding: 0 18px;
            border: 2px solid #27272a;
            border-radius: 10px;
            background: #18181b;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 500;
            outline: none;
            transition: border-color 0.2s;
        }

        .search-input::placeholder {
            color: #71717a;
        }

        .search-input:focus {
            border-color: #facc15;
        }

        .search-btn {
            height: 50px;
            padding: 0 24px;
            background: #facc15;
            color: #09090b;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.2s;
        }

        .search-btn:hover {
            background: #fbbf24;
        }

        .search-hint {
            margin-top: 10px;
            font-size: 0.78rem;
            color: #71717a;
            text-align: center;
        }

        /* ── PAGE BODY ── */
        .page-body {
            max-width: 680px;
            margin: 0 auto;
            padding: 36px 20px 60px;
        }

        /* ── ERROR / EMPTY ── */
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-info {
            background: #fefce8;
            border: 1px solid #fde68a;
            color: #78350f;
        }

        /* ── RESULT CARD ── */
        .result-card {
            border: 1.5px solid #e4e4e7;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        }

        .card-header {
            background: #09090b;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .card-header-left h2 {
            font-size: 0.7rem;
            font-weight: 700;
            color: #facc15;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 4px;
        }

        .card-header-left .ref-number {
            font-size: 1.1rem;
            font-weight: 800;
            color: #ffffff;
            font-family: monospace;
            letter-spacing: 0.03em;
        }

        /* Status pill */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }

        .status-pill::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .pill-requested {
            background: #fef9c3;
            color: #78350f;
        }

        .pill-requested::before {
            background: #facc15;
        }

        .pill-reviewed {
            background: #fef9c3;
            color: #78350f;
        }

        .pill-reviewed::before {
            background: #facc15;
        }

        .pill-quotation_sent {
            background: #eff6ff;
            color: #1e40af;
        }

        .pill-quotation_sent::before {
            background: #3b82f6;
        }

        .pill-confirmed {
            background: #fef9c3;
            color: #78350f;
        }

        .pill-confirmed::before {
            background: #facc15;
            animation: blink 1.2s infinite;
        }

        .pill-assigned {
            background: #fef3c7;
            color: #92400e;
        }

        .pill-assigned::before {
            background: #f59e0b;
        }

        .pill-on_the_way {
            background: #fef9c3;
            color: #78350f;
        }

        .pill-on_the_way::before {
            background: #facc15;
            animation: blink 1.2s infinite;
        }

        .pill-in_progress {
            background: #fef9c3;
            color: #78350f;
        }

        .pill-waiting_verification {
            background: #fefce8;
            color: #713f12;
        }

        .pill-waiting_verification::before {
            background: #facc15;
        }

        .pill-payment_pending {
            background: #fef2f2;
            color: #991b1b;
        }

        .pill-payment_pending::before {
            background: #ef4444;
        }

        .pill-completed {
            background: #f0fdf4;
            color: #166534;
        }

        .pill-completed::before {
            background: #22c55e;
        }

        .pill-scheduled_confirmed {
            background: #fef9c3;
            color: #78350f;
        }

        .pill-scheduled_confirmed::before {
            background: #facc15;
        }

        .pill-cancelled {
            background: #f4f4f5;
            color: #71717a;
        }

        .pill-cancelled::before {
            background: #a1a1aa;
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.3;
            }
        }

        /* ── PROGRESS STEPPER ── */
        .stepper {
            padding: 20px 24px 4px;
            border-bottom: 1px solid #f1f5f9;
        }

        .stepper-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: #a1a1aa;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 14px;
        }

        .steps {
            display: flex;
            align-items: center;
            gap: 0;
            overflow-x: auto;
            padding-bottom: 8px;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            min-width: 60px;
            position: relative;
        }

        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 14px;
            left: calc(50% + 14px);
            right: calc(-50% + 14px);
            height: 2px;
            background: #e4e4e7;
            z-index: 0;
        }

        .step.done:not(:last-child)::after,
        .step.active:not(:last-child)::after {
            background: #facc15;
        }

        .step-dot {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid #e4e4e7;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            font-weight: 800;
            color: #a1a1aa;
            position: relative;
            z-index: 1;
        }

        .step.done .step-dot {
            background: #facc15;
            border-color: #facc15;
            color: #09090b;
        }

        .step.active .step-dot {
            background: #09090b;
            border-color: #facc15;
            color: #facc15;
            box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.2);
        }

        .step-text {
            margin-top: 6px;
            font-size: 0.65rem;
            font-weight: 600;
            color: #a1a1aa;
            text-align: center;
            white-space: nowrap;
        }

        .step.done .step-text,
        .step.active .step-text {
            color: #09090b;
            font-weight: 700;
        }

        /* ── DETAILS GRID ── */
        .details {
            padding: 20px 24px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media (max-width: 480px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        .detail-item label {
            display: block;
            font-size: 0.68rem;
            font-weight: 700;
            color: #a1a1aa;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 3px;
        }

        .detail-item p {
            font-size: 0.88rem;
            font-weight: 600;
            color: #09090b;
            line-height: 1.4;
        }

        .detail-item.full {
            grid-column: 1 / -1;
        }

        /* Route */
        .route-block {
            padding: 0 24px 16px;
        }

        .route-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 0;
        }

        .route-dot {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .dot-a {
            background: #22c55e;
        }

        .dot-b {
            background: #ef4444;
        }

        .route-addr label {
            font-size: 0.68rem;
            font-weight: 700;
            color: #a1a1aa;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .route-addr p {
            font-size: 0.85rem;
            font-weight: 500;
            color: #0f172a;
            margin-top: 2px;
        }

        /* Pricing row */
        .price-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 24px;
            border-top: 1px solid #f1f5f9;
            background: #fafafa;
        }

        .price-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #71717a;
        }

        .price-value {
            font-size: 1.15rem;
            font-weight: 800;
            color: #09090b;
        }

        .price-locked {
            font-size: 0.68rem;
            font-weight: 700;
            color: #166534;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 999px;
            padding: 2px 8px;
            margin-left: 8px;
        }

        /* Unit row */
        .unit-row {
            padding: 14px 24px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .unit-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: #a1a1aa;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .unit-value {
            font-size: 0.88rem;
            font-weight: 600;
            color: #0f172a;
        }

        /* Footer note */
        .card-footer {
            padding: 14px 24px;
            border-top: 1px solid #f1f5f9;
            background: #fafafa;
            font-size: 0.78rem;
            color: #a1a1aa;
            text-align: center;
        }

        /* Page footer */
        .page-footer {
            text-align: center;
            padding: 24px 20px;
            border-top: 1px solid #f1f5f9;
            font-size: 0.78rem;
            color: #a1a1aa;
        }
    </style>
</head>

<body>

    <!-- Nav -->
    <nav class="nav">
        <a href="{{ route('landing') }}" class="nav-brand">TowMate</a>
        <span class="nav-sub">Booking Tracker</span>
    </nav>

    <!-- Hero / Search -->
    <div class="hero">
        <h1>Track Your <span>Booking</span></h1>
        <p>Enter your booking or quotation reference number to see your current status.</p>
        <div class="search-shell">
            <form class="search-form" method="GET" action="{{ route('public.track') }}">
                <input type="text" name="ref" class="search-input" placeholder="ex. QT-20260502-0001"
                    value="{{ $ref }}" autocomplete="off" autofocus>
                <button type="submit" class="search-btn">Track</button>
            </form>
            <p class="search-hint">Use the reference number from your booking confirmation or quotation email.</p>
        </div>
    </div>

    <!-- Body -->
    <div class="page-body">

        @if ($ref !== '' && $error)
            <div class="alert alert-error">{!! $error !!}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-error">{{ session('error') }}</div>
        @endif

        @if ($ref === '' && !$booking)
            <div class="alert alert-info">
                Enter your reference number above to track your booking status in real time.
            </div>
        @endif

        @if ($booking)
            @php
                $status = $booking->status;

                $statusLabels = [
                    'requested' => 'Request Received',
                    'reviewed' => 'Under Review',
                    'quotation_sent' => 'Awaiting Confirmation',
                    'confirmed' => 'Booking Confirmed',
                    'assigned' => 'Unit Assigned',
                    'on_the_way' => 'Unit On The Way',
                    'in_progress' => 'Towing In Progress',
                    'waiting_verification' => 'Awaiting Completion',
                    'payment_pending' => 'Payment Pending',
                    'payment_submitted' => 'Payment Submitted',
                    'completed' => 'Completed',
                    'scheduled_confirmed' => 'Scheduled',
                    'cancelled' => 'Cancelled',
                ];

                $steps = [
                    ['key' => 'requested', 'label' => 'Received'],
                    ['key' => 'confirmed', 'label' => 'Confirmed'],
                    ['key' => 'assigned', 'label' => 'Assigned'],
                    ['key' => 'on_the_way', 'label' => 'On The Way'],
                    ['key' => 'in_progress', 'label' => 'Towing'],
                    ['key' => 'completed', 'label' => 'Done'],
                ];

                $stepOrder = array_column($steps, 'key');

                $currentIndex = array_search($status, $stepOrder);
                if ($currentIndex === false) {
                    // map related statuses to closest step
                    $statusMap = [
                        'reviewed' => 0,
                        'quotation_sent' => 0,
                        'assigned' => 2,
                        'waiting_verification' => 4,
                        'payment_pending' => 4,
                        'payment_submitted' => 4,
                        'scheduled_confirmed' => 1,
                    ];
                    $currentIndex = $statusMap[$status] ?? 0;
                }

                $statusLabel = $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));
                $pillClass = 'pill-' . $status;

                $baseRate = (float) ($booking->base_rate ?? ($booking->truckType?->base_rate ?? 0));
                $distanceKm = (float) ($booking->distance_km ?? 0);
                $kmIncrements = (int) floor($distanceKm / 4);
                $distanceFee = round($kmIncrements * 200, 2);
                $finalTotal = (float) ($booking->final_total ?? 0);
                $priceLocked = (bool) $booking->price_locked_at;
            @endphp

            <div class="result-card">

                <!-- Header -->
                <div class="card-header">
                    <div class="card-header-left">
                        <h2>Booking Reference</h2>
                        <div class="ref-number">{{ $booking->quotation_number ?? $booking->booking_code }}</div>
                    </div>
                    <span class="status-pill {{ $pillClass }}">{{ $statusLabel }}</span>
                </div>

                <!-- Progress Stepper -->
                <div class="stepper">
                    <div class="stepper-label">Progress</div>
                    <div class="steps">
                        @foreach ($steps as $i => $step)
                            @php
                                $stepClass = $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'active' : '');
                            @endphp
                            <div class="step {{ $stepClass }}">
                                <div class="step-dot">
                                    @if ($i < $currentIndex)
                                        ✓
                                    @else
                                        {{ $i + 1 }}
                                    @endif
                                </div>
                                <div class="step-text">{{ $step['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Route -->
                <div class="route-block">
                    <div class="route-row">
                        <div class="route-dot dot-a">A</div>
                        <div class="route-addr">
                            <label>Pickup</label>
                            <p>{{ $booking->pickup_address ?? '—' }}</p>
                        </div>
                    </div>
                    <div class="route-row">
                        <div class="route-dot dot-b">B</div>
                        <div class="route-addr">
                            <label>Drop-off</label>
                            <p>{{ $booking->dropoff_address ?? '—' }}</p>
                        </div>
                    </div>
                </div>

                <!-- Details -->
                <div class="details">
                    <div class="details-grid">
                        <div class="detail-item">
                            <label>Truck Type</label>
                            <p>{{ $booking->truckType?->name ?? '—' }}</p>
                        </div>
                        <div class="detail-item">
                            <label>Distance</label>
                            <p>{{ number_format($distanceKm, 2) }} km</p>
                        </div>
                        <div class="detail-item">
                            <label>Booked On</label>
                            <p>{{ $booking->created_at->format('M d, Y g:i A') }}</p>
                        </div>
                        @if ($booking->unit?->teamLeader)
                            <div class="detail-item">
                                <label>Team Leader</label>
                                <p>{{ $booking->unit->teamLeader->full_name ?? $booking->unit->teamLeader->name }}</p>
                            </div>
                        @endif
                        @if ($booking->unit)
                            <div class="detail-item">
                                <label>Assigned Unit</label>
                                <p>{{ $booking->unit->name }}
                                    {{ $booking->unit->plate_number ? '· ' . $booking->unit->plate_number : '' }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Pricing -->
                @if ($finalTotal > 0)
                    <div class="price-row">
                        <div>
                            <div class="price-label">Final Amount</div>
                            @if ($priceLocked)
                                <span class="price-locked">Price Locked</span>
                            @endif
                        </div>
                        <div class="price-value">₱{{ number_format($finalTotal, 2) }}</div>
                    </div>
                @endif

                <!-- Footer note -->
                <div class="card-footer">
                    Last updated: {{ $booking->updated_at->diffForHumans() }} &nbsp;·&nbsp;
                    Need help? Call (123) 456-7890
                </div>

            </div><!-- /.result-card -->
        @endif

        {{-- ── QUOTATION RESULT (pre-booking, no Booking record yet) ── --}}
        @if ($quotation)
            @php
                $qStatusLabels = [
                    'pending' => 'Request Received',
                    'sent' => 'Quotation Sent',
                    'accepted' => 'Accepted',
                    'rejected' => 'Rejected',
                    'expired' => 'Expired',
                ];
                $qLabel = $qStatusLabels[$quotation->status] ?? ucfirst($quotation->status);
                $qPillClass = 'pill-' . ($quotation->status === 'pending' ? 'requested' : $quotation->status);
                $qExpired = $quotation->expires_at?->isPast();
            @endphp

            <div class="result-card">

                <!-- Header -->
                <div class="card-header">
                    <div class="card-header-left">
                        <h2>Quotation Reference</h2>
                        <div class="ref-number">{{ $quotation->quotation_number }}</div>
                    </div>
                    <span class="status-pill {{ $qPillClass }}">{{ $qLabel }}</span>
                </div>

                <!-- Progress Stepper (simplified for quotation stage) -->
                <div class="stepper">
                    <div class="stepper-label">Progress</div>
                    <div class="steps">
                        @php
                            $qSteps = [
                                ['label' => 'Received', 'done' => true],
                                ['label' => 'Reviewing', 'done' => in_array($quotation->status, ['sent', 'accepted'])],
                                ['label' => 'Quoted', 'done' => in_array($quotation->status, ['sent', 'accepted'])],
                                ['label' => 'Accepted', 'done' => $quotation->status === 'accepted'],
                                ['label' => 'Assigned', 'done' => false],
                                ['label' => 'Done', 'done' => false],
                            ];
                            $qActiveIdx = 0;
                            foreach ($qSteps as $qi => $qs) {
                                if ($qs['done']) {
                                    $qActiveIdx = $qi;
                                }
                            }
                        @endphp
                        @foreach ($qSteps as $qi => $qs)
                            @php
                                $stepCls = $qs['done']
                                    ? ($qi < $qActiveIdx
                                        ? 'done'
                                        : ($qi === $qActiveIdx
                                            ? 'active'
                                            : 'done'))
                                    : '';
                                if ($qi === $qActiveIdx && !$qs['done']) {
                                    $stepCls = 'active';
                                }
                                if ($qs['done'] && $qi < $qActiveIdx) {
                                    $stepCls = 'done';
                                }
                                if ($qs['done'] && $qi === $qActiveIdx) {
                                    $stepCls = 'active';
                                }
                            @endphp
                            <div class="step {{ $stepCls }}">
                                <div class="step-dot">
                                    @if ($qs['done'] && $qi < $qActiveIdx)
                                        ✓
                                    @else
                                        {{ $qi + 1 }}
                                    @endif
                                </div>
                                <div class="step-text">{{ $qs['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Status message -->
                @if ($quotation->status === 'pending')
                    <div
                        style="padding:14px 24px;background:#fefce8;border-bottom:1px solid #fde68a;font-size:0.85rem;color:#713f12;line-height:1.6;">
                        <strong>Your request is being reviewed.</strong> Our dispatcher will send you a quotation
                        shortly. Check your email for the quotation link.
                    </div>
                @elseif ($quotation->status === 'sent')
                    <div
                        style="padding:14px 24px;background:#eff6ff;border-bottom:1px solid #bfdbfe;font-size:0.85rem;color:#1e40af;line-height:1.6;">
                        <strong>A quotation has been sent to your email.</strong>
                        Please check your inbox and accept or decline the quotation.
                        @if ($quotation->expires_at)
                            @if ($qExpired)
                                <br><span style="color:#991b1b;font-weight:700;">This quotation has expired.</span>
                            @else
                                <br>Expires: <strong>{{ $quotation->expires_at->format('M d, Y g:i A') }}</strong>
                                ({{ $quotation->expires_at->diffForHumans() }})
                            @endif
                        @endif
                    </div>
                @endif

                <!-- Route -->
                <div class="route-block">
                    <div class="route-row">
                        <div class="route-dot dot-a">A</div>
                        <div class="route-addr">
                            <label>Pickup</label>
                            <p>{{ $quotation->pickup_address ?? '—' }}</p>
                        </div>
                    </div>
                    <div class="route-row">
                        <div class="route-dot dot-b">B</div>
                        <div class="route-addr">
                            <label>Drop-off</label>
                            <p>{{ $quotation->dropoff_address ?? '—' }}</p>
                        </div>
                    </div>
                </div>

                <!-- Details -->
                <div class="details">
                    <div class="details-grid">
                        <div class="detail-item">
                            <label>Truck Type</label>
                            <p>{{ $quotation->truckType?->name ?? '—' }}</p>
                        </div>
                        <div class="detail-item">
                            <label>Distance</label>
                            <p>{{ number_format((float) $quotation->distance_km, 2) }} km</p>
                        </div>
                        <div class="detail-item">
                            <label>Requested On</label>
                            <p>{{ $quotation->created_at->format('M d, Y g:i A') }}</p>
                        </div>
                        @if ($quotation->service_type === 'schedule' && $quotation->scheduled_date)
                            <div class="detail-item">
                                <label>Scheduled Date</label>
                                <p>{{ $quotation->scheduled_date->format('M d, Y') }}{{ $quotation->scheduled_time ? ' at ' . $quotation->scheduled_time : '' }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Estimated Price -->
                @if ($quotation->estimated_price > 0)
                    <div class="price-row">
                        <div class="price-label">Estimated Amount</div>
                        <div class="price-value">₱{{ number_format($quotation->estimated_price, 2) }}</div>
                    </div>
                @endif

                <!-- Footer note -->
                <div class="card-footer">
                    Submitted: {{ $quotation->created_at->diffForHumans() }} &nbsp;·&nbsp;
                    Need help? Call (123) 456-7890
                </div>

            </div><!-- /.result-card (quotation) -->
        @endif

    </div><!-- /.page-body -->

    <div class="page-footer">
        <p>© {{ date('Y') }} TowMate · <a href="{{ route('landing') }}"
                style="color:#facc15;text-decoration:none;">Back to Home</a></p>
    </div>

</body>

</html>
