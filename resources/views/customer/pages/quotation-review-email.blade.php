<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Review</title>
    <style>
        :root {
            --ink: #111827;
            --ink-soft: #374151;
            --muted: #6b7280;
            --accent: #facc15;
            --accent-soft: #fefce8;
            --page: #f8fafc;
            --panel: #ffffff;
            --line: #e5e7eb;
            --shadow: 0 16px 40px rgba(17, 24, 39, 0.06);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(180deg, #ffffff 0%, var(--page) 100%);
            color: var(--ink);
            line-height: 1.6;
            padding: 24px 16px;
        }

        .shell {
            max-width: 1040px;
            margin: 0 auto;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            padding: 28px;
            border-bottom: 1px solid var(--line);
            background: #fcfcfd;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border: 1px solid #fde68a;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--ink);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            margin-bottom: 10px;
        }

        .topbar h1 {
            font-size: clamp(28px, 4vw, 36px);
            line-height: 1.15;
            margin-bottom: 8px;
        }

        .topbar p {
            color: var(--muted);
            max-width: 640px;
        }

        .status-chip {
            white-space: nowrap;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink-soft);
            font-size: 12px;
            font-weight: 700;
        }

        .status-chip.quoted,
        .status-chip.quotation_sent {
            background: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }

        .status-chip.reviewed {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }

        .status-chip.confirmed {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }

        .content {
            padding: 28px;
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(300px, 0.85fr);
            gap: 20px;
            align-items: start;
        }

        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 20px;
        }

        .panel h2 {
            font-size: 20px;
            margin-bottom: 6px;
        }

        .panel-intro {
            color: var(--muted);
            margin-bottom: 16px;
            font-size: 14px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .detail-card {
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            background: #fcfcfd;
        }

        .detail-card span {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .detail-card strong,
        .detail-card p {
            margin: 0;
            color: var(--ink);
            word-break: break-word;
            font-size: 14px;
        }

        .price-panel {
            position: sticky;
            top: 16px;
        }

        .price-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 14px;
        }

        .price-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--line);
            font-size: 14px;
        }

        .price-row:last-child {
            border-bottom: 0;
        }

        .price-row span {
            color: var(--muted);
        }

        .price-row strong {
            color: var(--ink);
        }

        .price-row.discount strong {
            color: #b45309;
        }

        .total-box {
            margin-top: 16px;
            padding: 16px;
            border-radius: 16px;
            border: 1px solid #fde68a;
            background: #fffdfa;
        }

        .total-box.confirmed {
            border-color: #a7f3d0;
            background: #f0fdf4;
        }

        .total-box span {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }

        .total-box strong {
            font-size: 32px;
            line-height: 1;
            color: var(--ink);
        }

        .meta-note,
        .state-box {
            margin-top: 16px;
            padding: 14px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #fcfcfd;
            color: var(--ink-soft);
            font-size: 14px;
        }

        .state-box.success {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }

        .meta-note {
            border-left: 4px solid var(--accent);
        }

        .btn {
            width: 100%;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 13px 16px;
            margin-top: 16px;
            border-radius: 12px;
            border: 1px solid var(--ink);
            background: #ffffff;
            color: var(--ink);
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(17, 24, 39, 0.08);
            border-color: #ca8a04;
        }

        .footer {
            padding: 18px 28px 24px;
            border-top: 1px solid var(--line);
            color: var(--muted);
            text-align: center;
            font-size: 12px;
        }

        @media (max-width: 900px) {

            .topbar,
            .layout,
            .detail-grid {
                grid-template-columns: 1fr;
            }

            .topbar {
                flex-direction: column;
            }

            .price-panel {
                position: static;
            }
        }
    </style>
</head>

<body>
    @php
        $breakdown = $booking->quotation_breakdown ?? [];
        $quotationStatus = strtolower((string) ($booking->quotation_status ?? 'active'));
        $statusLabel =
            $quotationStatus === 'expired'
                ? 'QUOTATION EXPIRED'
                : strtoupper(str_replace('_', ' ', (string) $booking->status));
        $distanceKm = (float) ($booking->distance_km ?? 0);
        $perKmRate = (float) ($booking->per_km_rate ?? 0);
        $discountAmount = (float) ($breakdown['discount'] ?? 0);

        $priceRows = [
            ['label' => 'Base rate', 'value' => (float) ($breakdown['base_rate'] ?? 0)],
            ['label' => 'Distance fee', 'value' => (float) ($breakdown['distance_fee'] ?? 0)],
        ];

        if ((float) ($breakdown['excess_fee'] ?? 0) > 0) {
            $priceRows[] = ['label' => 'Excess distance fee', 'value' => (float) $breakdown['excess_fee']];
        }

        if ((float) ($breakdown['additional_fee'] ?? 0) > 0) {
            $priceRows[] = ['label' => 'Additional fee', 'value' => (float) $breakdown['additional_fee']];
        }
    @endphp

    <div class="shell">
        <div class="topbar">
            <div>
                <span class="eyebrow">Secure quotation review</span>
                <h1>Review your quotation</h1>
                <p>Everything below is pulled from the latest booking pricing so the total stays up to date.</p>
            </div>
            <div class="status-chip {{ strtolower((string) $booking->status) }}">{{ $statusLabel }}</div>
        </div>

        <div class="content">
            <div class="layout">
                <section class="panel">
                    <h2>Trip summary</h2>
                    <p class="panel-intro">A quick overview of your towing request and route details.</p>

                    <div class="detail-grid">
                        <div class="detail-card">
                            <span>Customer</span>
                            <strong>{{ $booking->customer->full_name ?? 'N/A' }}</strong>
                        </div>
                        <div class="detail-card">
                            <span>Vehicle type</span>
                            <p>{{ $booking->truckType->name ?? 'N/A' }}</p>
                        </div>
                        <div class="detail-card">
                            <span>Booking reference</span>
                            <p>{{ $booking->job_code }}</p>
                        </div>
                        <div class="detail-card">
                            <span>Quotation number</span>
                            <p>{{ $booking->quotation_number ?? 'Pending' }}</p>
                        </div>
                        <div class="detail-card">
                            <span>Pickup</span>
                            <p>{{ $booking->pickup_address ?? 'N/A' }}</p>
                        </div>
                        <div class="detail-card">
                            <span>Drop-off</span>
                            <p>{{ $booking->dropoff_address ?? 'N/A' }}</p>
                        </div>
                        <div class="detail-card">
                            <span>Valid until</span>
                            <p>{{ $booking->quotation_validity_label ?? 'Pending dispatch review' }}</p>
                        </div>
                    </div>

                    @if ($distanceKm > 0 || $perKmRate > 0)
                        <div class="meta-note">
                            Route distance: <strong>{{ number_format($distanceKm, 2) }} km</strong>
                            @if ($perKmRate > 0)
                                • Rate per km: <strong>₱{{ number_format($perKmRate, 2) }}</strong>
                            @endif
                        </div>
                    @endif

                    @if (filled($booking->dispatcher_note))
                        <div class="meta-note">
                            <strong>Dispatcher note:</strong><br>
                            {{ $booking->dispatcher_note }}
                        </div>
                    @endif
                </section>

                <aside class="panel price-panel">
                    <h2>Price Breakdown</h2>
                    <p class="panel-intro">This summary is calculated from your actual booking rates.</p>

                    <div class="price-list">
                        @foreach ($priceRows as $row)
                            <div class="price-row">
                                <span>{{ $row['label'] }}</span>
                                <strong>₱{{ number_format((float) $row['value'], 2) }}</strong>
                            </div>
                        @endforeach

                        @if ($discountAmount > 0)
                            <div class="price-row discount">
                                <span>
                                    Discount{{ filled($booking->discount_reason) ? ' • ' . $booking->discount_reason : '' }}
                                </span>
                                <strong>- ₱{{ number_format($discountAmount, 2) }}</strong>
                            </div>
                        @endif
                    </div>

                    <div class="total-box {{ $booking->status === 'confirmed' ? 'confirmed' : '' }}">
                        <span>Final total</span>
                        <strong>₱{{ number_format((float) ($breakdown['final_total'] ?? ($booking->final_total ?? 0)), 2) }}</strong>
                    </div>

                    @if ($quotationStatus === 'expired')
                        <div class="state-box">
                            <strong>Quotation expired</strong><br>
                            This quotation already passed its 7-day validity window. Dispatch can send you an updated
                            quotation if needed.
                        </div>
                    @elseif (in_array($booking->status, ['quoted', 'quotation_sent'], true))
                        <div class="state-box">
                            <strong>Read-only booking copy</strong><br>
                            This page only shows your latest price breakdown and booking credentials for reference.
                            No response button is required on your side. A follow-up reminder is sent on day 5 if the
                            quotation stays pending.
                        </div>
                    @elseif ($booking->status === 'reviewed')
                        <div class="state-box">This page remains read-only while dispatch reviews the latest pricing
                            adjustment.</div>
                    @elseif ($booking->status === 'confirmed')
                        <div class="state-box success"><strong>✓ Price confirmed</strong><br>Your final total has been
                            saved successfully as part of your booking record.</div>
                    @else
                        <div class="state-box">This quotation summary is kept here for your booking record.</div>
                    @endif
                </aside>
            </div>
        </div>

        <div class="footer">
            Secure review link • Pricing updates reflect the latest booking values
        </div>
    </div>
</body>

</html>
