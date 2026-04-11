<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review your quotation - Jarz</title>
    <style>
        :root {
            --bg: #f7f7f5;
            --card: #ffffff;
            --text: #111111;
            --muted: #5f5f5f;
            --line: #ece7d8;
            --yellow: #ffeb00;
            --yellow-soft: #fffde7;
            --green: #0f8a4b;
            --green-soft: #ecfdf3;
            --dark: #111111;
            --shadow: 0 18px 45px rgba(17, 17, 17, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(180deg, #fffef7 0%, var(--bg) 180px);
            color: var(--text);
        }

        .page {
            max-width: 1040px;
            margin: 0 auto;
            padding: 32px 16px 56px;
        }

        .hero {
            background: linear-gradient(135deg, #ffffff 0%, #fffdf6 100%);
            color: var(--text);
            border-radius: 26px;
            padding: 28px 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--line);
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 160px;
            height: 160px;
            background: radial-gradient(circle, rgba(255, 235, 0, 0.18) 0%, rgba(255, 235, 0, 0) 70%);
            pointer-events: none;
        }

        .hero-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            position: relative;
            z-index: 1;
        }

        .hero-brand {
            flex: 1;
            min-width: 0;
        }

        .hero span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--yellow-soft);
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            margin-bottom: 12px;
            color: #7a6500;
        }

        .hero-logo {
            width: 150px;
            height: auto;
            object-fit: contain;
            flex-shrink: 0;
            filter: drop-shadow(0 8px 18px rgba(17, 17, 17, 0.12));
        }

        .hero h1 {
            margin: 0 0 8px;
            font-size: clamp(28px, 4vw, 40px);
        }

        .hero p {
            margin: 0;
            color: var(--muted);
            max-width: 760px;
        }

        .flash {
            margin-top: 16px;
            border-radius: 14px;
            padding: 14px 16px;
            font-weight: 600;
        }

        .flash.success {
            background: var(--green-soft);
            color: var(--green);
            border: 1px solid #b7ebc9;
        }

        .flash.error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 20px;
            margin-top: 20px;
            align-items: start;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            margin-bottom: 12px;
        }

        .status-pill.quoted,
        .status-pill.quotation_sent {
            background: var(--yellow-soft);
            color: #7a6500;
        }

        .status-pill.reviewed {
            background: #fff7ed;
            color: #b45309;
        }

        .status-pill.confirmed {
            background: var(--green-soft);
            color: var(--green);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .summary-item {
            background: linear-gradient(180deg, #ffffff 0%, #fafaf8 100%);
            border-radius: 16px;
            padding: 14px 15px;
            border: 1px solid #f0ead6;
            min-height: 82px;
        }

        .summary-item.highlight {
            background: linear-gradient(180deg, #fffef5 0%, #fff9d8 100%);
            border-color: #f4df68;
        }

        .summary-item span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 4px;
        }

        .summary-item strong,
        .summary-item p {
            margin: 0;
            word-break: break-word;
        }

        .price-box {
            margin-top: 20px;
            padding: 20px;
            border-radius: 18px;
            background: linear-gradient(180deg, #fffdf2 0%, #fff8c9 100%);
            border: 1px solid #f6e889;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.92);
        }

        .price-box small {
            color: #7a6500;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .price-box h2 {
            margin: 8px 0 0;
            font-size: clamp(32px, 4vw, 40px);
            color: var(--dark);
        }

        .note-box {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            background: #fffdf2;
            border: 1px solid #f6e889;
            border-left: 4px solid #facc15;
            color: #6f5d00;
        }

        .note-box.blue {
            background: #fafaf8;
            border-color: #ece7d8;
            color: #444444;
        }

        .actions {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .form-stack {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 0;
        }

        .btn {
            width: 100%;
            border: 0;
            border-radius: 12px;
            padding: 14px 16px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--dark);
            color: #ffffff;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .btn:hover {
            background: #222222;
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(17, 17, 17, 0.18);
        }

        .state-box {
            border-radius: 16px;
            padding: 16px;
            background: #fafaf8;
            color: #334155;
            border: 1px solid var(--line);
        }

        .footer-note {
            margin-top: 18px;
            padding-top: 12px;
            border-top: 1px dashed var(--line);
            font-size: 12px;
            color: var(--muted);
            text-align: center;
        }

        @media (max-width: 900px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .hero-top {
                flex-direction: column-reverse;
            }

            .hero-logo {
                width: 72px;
                align-self: flex-end;
            }
        }
    </style>
</head>

<body>
    @php
        $statusLabel = strtoupper(str_replace('_', ' ', $booking->status));
        $statusMessage = match ($booking->status) {
            'quoted', 'quotation_sent' => 'Your quotation is ready for approval.',
            'reviewed' => 'Your adjustment request has been sent back to dispatch.',
            'confirmed' => 'Your quotation is already approved. Dispatch can now continue with the towing job.',
            default => 'This quotation has already been updated.',
        };
    @endphp

    <div class="page">
        <div class="hero">
            <div class="hero-top">
                <div class="hero-brand">
                    <span>📩 Jarz quotation</span>
                    <h1>Review your quotation</h1>
                    <p>{{ $statusMessage }}</p>
                </div>
                <img src="{{ asset('customer/image/TowingLogo.png') }}" alt="Jarz logo" class="hero-logo">
            </div>
        </div>

        @if (session('success'))
            <div class="flash success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="flash error">{{ session('error') }}</div>
        @endif

        <div class="grid">
            <section class="card">
                <span class="status-pill {{ strtolower($booking->status) }}">{{ $statusLabel }}</span>
                <h2 style="margin: 0 0 8px;">Quotation Summary</h2>
                <p style="margin: 0; color: var(--muted);">Review the trip details and final amount before continuing.
                </p>

                <div class="summary-grid">
                    <div class="summary-item highlight">
                        <span>Quotation Reference</span>
                        <strong>{{ $booking->quotation_number ?? 'Pending' }}</strong>
                    </div>
                    <div class="summary-item">
                        <span>Current Status</span>
                        <p>{{ ucwords(str_replace('_', ' ', $booking->status)) }}</p>
                    </div>
                    <div class="summary-item">
                        <span>Vehicle Type</span>
                        <p>{{ $booking->truckType->name ?? '-' }}</p>
                    </div>
                    <div class="summary-item">
                        <span>Customer</span>
                        <p>{{ $booking->customer->full_name ?? 'Customer' }}</p>
                    </div>
                    <div class="summary-item">
                        <span>Pickup</span>
                        <p>{{ $booking->pickup_address }}</p>
                    </div>
                    <div class="summary-item">
                        <span>Drop-off</span>
                        <p>{{ $booking->dropoff_address }}</p>
                    </div>
                </div>

                <div class="price-box">
                    <small>FINAL PRICE</small>
                    <h2>₱{{ number_format((float) ($booking->final_total ?? 0), 2) }}</h2>

                    @if (in_array($booking->status, ['quoted', 'quotation_sent'], true))
                        <form method="POST" action="{{ $signedActionUrl }}" class="form-stack"
                            style="margin-top: 16px;">
                            @csrf
                            <input type="hidden" name="action" value="accept">
                            <button type="submit" class="btn">Accept & continue</button>
                        </form>
                    @endif
                </div>

                @if (filled($booking->dispatcher_note))
                    <div class="note-box">
                        <strong>Dispatch message</strong><br>
                        {{ $booking->dispatcher_note }}
                    </div>
                @endif

                @if (filled($booking->customer_response_note) || filled($booking->counter_offer_amount))
                    <div class="note-box blue">
                        <strong>Your latest request</strong><br>
                        @if (filled($booking->counter_offer_amount))
                            Counter-offer: ₱{{ number_format((float) $booking->counter_offer_amount, 2) }}<br>
                        @endif
                        {{ $booking->customer_response_note ?? 'Requested a price adjustment.' }}
                    </div>
                @endif
            </section>

            <aside class="card actions">
                @if ($booking->status === 'quoted')
                    <div class="state-box" style="background:#fffdf2; border-color:#f6e889; color:#6f5d00;">
                        <h2 style="margin-top: 0;">Almost done</h2>
                        <p style="margin-bottom: 0;">Review the details on the left, then use the button under the
                            quoted amount to approve and continue.</p>
                    </div>
                @elseif ($booking->status === 'reviewed')
                    <div class="state-box">
                        <h2 style="margin-top: 0;">Adjustment sent</h2>
                        <p style="margin-bottom: 0;">Your request is already with dispatch. Once they update the
                            quotation, you can review the new amount from the latest email or this same secure page.</p>
                    </div>
                @elseif ($booking->status === 'confirmed')
                    <div class="state-box"
                        style="background: var(--green-soft); color: var(--green); border-color: #a7f3d0;">
                        <h2 style="margin-top: 0;">Quotation approved</h2>
                        <p style="margin-bottom: 0;">Your approval has been recorded successfully. Dispatch can now
                            assign the towing unit and continue the service.</p>
                    </div>
                @else
                    <div class="state-box">
                        <h2 style="margin-top: 0;">Quotation unavailable</h2>
                        <p style="margin-bottom: 0;">This booking is no longer waiting for a quotation response.</p>
                    </div>
                @endif

                <div class="footer-note">
                    Secure review link • Final customer approval required
                </div>
            </aside>
        </div>
    </div>
</body>

</html>
