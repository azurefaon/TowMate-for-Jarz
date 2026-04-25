@extends('landing.layouts.app')

@section('content')
    @push('styles')
        <style>
            * {
                box-sizing: border-box;
            }

            body {
                background: #fff;
                margin: 0;
            }

            .conf-page {
                max-width: 600px;
                margin: 0 auto;
                padding: 32px 20px 60px;
                font-family: Arial, sans-serif;
                color: #000;
            }

            .conf-top {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                padding-bottom: 14px;
                border-bottom: 3px solid #facc15;
                margin-bottom: 24px;
            }

            .conf-top-left h1 {
                margin: 0 0 3px;
                font-size: 1.1rem;
                font-weight: 700;
            }

            .conf-top-left p {
                margin: 0;
                font-size: 0.78rem;
                color: #6b7280;
            }

            .conf-top-brand {
                font-size: 1.25rem;
                font-weight: 800;
                letter-spacing: 0.1em;
                white-space: nowrap;
            }

            .ref-block {
                background: #facc15;
                padding: 18px 20px;
                margin-bottom: 16px;
            }

            .ref-label {
                font-size: 0.7rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                margin-bottom: 6px;
            }

            .ref-number {
                font-family: 'Courier New', Courier, monospace;
                font-size: 1.9rem;
                font-weight: 700;
                letter-spacing: 0.06em;
                word-break: break-all;
                line-height: 1.2;
            }

            .ref-note {
                font-size: 0.78rem;
                color: #374151;
                margin-top: 6px;
            }

            .copy-ref-btn {
                margin-top: 12px;
                background: #000;
                color: #fff;
                border: none;
                padding: 8px 18px;
                font-size: 0.8rem;
                font-weight: 700;
                cursor: pointer;
                letter-spacing: 0.02em;
            }

            .reminder-box {
                border: 2px solid #000;
                padding: 13px 16px;
                margin-bottom: 26px;
            }

            .reminder-box b {
                font-size: 0.88rem;
                display: block;
                margin-bottom: 5px;
            }

            .reminder-box span {
                font-size: 0.8rem;
                color: #374151;
                line-height: 1.55;
            }

            .section {
                margin-bottom: 22px;
            }

            .sec-head {
                font-size: 0.68rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.09em;
                border-bottom: 1px solid #000;
                padding-bottom: 5px;
                margin-bottom: 10px;
            }

            .drow {
                display: flex;
                gap: 10px;
                padding: 6px 0;
                border-bottom: 1px solid #f0f0f0;
                font-size: 0.84rem;
                line-height: 1.4;
            }

            .drow:last-child {
                border-bottom: none;
            }

            .dl {
                color: #6b7280;
                min-width: 120px;
                flex-shrink: 0;
                font-size: 0.82rem;
            }

            .dv {
                font-weight: 600;
                word-break: break-word;
            }

            .price-bar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #facc15;
                padding: 13px 20px;
                margin: 6px 0 22px;
            }

            .price-bar .p-label {
                font-size: 0.85rem;
                font-weight: 600;
            }

            .price-bar .p-amount {
                font-family: 'Courier New', Courier, monospace;
                font-size: 1.45rem;
                font-weight: 700;
            }

            .next-box {
                padding: 13px 16px;
                background: #f9f9f9;
                /* border-left: 4px solid #facc15; */
                margin-bottom: 24px;
                font-size: 0.83rem;
                line-height: 1.6;
            }

            .next-box p {
                margin: 0 0 6px;
            }

            .next-box p:last-child {
                margin: 0;
            }

            .action-row {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }

            .btn-home {
                background: #000;
                color: #fff;
                padding: 11px 24px;
                font-weight: 700;
                font-size: 0.875rem;
                text-decoration: none;
                display: inline-block;
            }

            .btn-print {
                background: #fff;
                color: #000;
                border: 2px solid #000;
                padding: 11px 24px;
                font-weight: 700;
                font-size: 0.875rem;
                cursor: pointer;
            }

            @media (max-width: 480px) {
                .conf-top {
                    flex-direction: column;
                    gap: 6px;
                }

                .ref-number {
                    font-size: 1.4rem;
                }

                .dl {
                    min-width: 90px;
                }
            }

            @media print {
                .no-print {
                    display: none !important;
                }

                .conf-page {
                    padding: 10px;
                }

                body {
                    background: #fff;
                }
            }
        </style>
    @endpush

    <div class="conf-page">

        {{-- Header --}}
        <div class="conf-top">
            <div class="conf-top-left">
                <h1>Booking Request Confirmed</h1>
                <p>Submitted {{ $data['submitted_at'] }}</p>
            </div>
            <div class="conf-top-brand">JARZ</div>
        </div>

        {{-- Reference Number --}}
        <div class="ref-block">
            <div class="ref-label">Reference Number</div>
            <div class="ref-number" id="refNum">{{ $data['reference'] }}</div>
            <div class="ref-note">Use this number to follow up on your booking.</div>
            <button class="copy-ref-btn no-print" onclick="copyRef()">Copy Reference Number</button>
        </div>

        {{-- Screenshot reminder --}}
        <div class="reminder-box no-print">
            <b>Take a screenshot of this page.</b>
            <span>
                This page will not load again once you leave.
                Your reference number is the only record of this booking keep it safe.
            </span>
        </div>

        {{-- Booking info --}}
        <div class="section">
            <div class="sec-head">Booking Info</div>
            <div class="drow">
                <span class="dl">Status</span>
                <span class="dv">Pending Quotation</span>
            </div>
            <div class="drow">
                <span class="dl">Service</span>
                <span class="dv">{{ $data['service_type'] }}</span>
            </div>
            @if (!empty($data['scheduled_date']))
                <div class="drow">
                    <span class="dl">Scheduled Date</span>
                    <span class="dv">{{ $data['scheduled_date'] }}</span>
                </div>
            @endif
            <div class="drow">
                <span class="dl">Reference No.</span>
                <span class="dv">{{ $data['reference'] }}</span>
            </div>
        </div>

        {{-- Customer --}}
        <div class="section">
            <div class="sec-head">Customer</div>
            <div class="drow">
                <span class="dl">Name</span>
                <span class="dv">{{ $data['name'] }}</span>
            </div>
            <div class="drow">
                <span class="dl">Phone</span>
                <span class="dv">{{ $data['phone'] }}</span>
            </div>
            @if (!empty($data['email']))
                <div class="drow">
                    <span class="dl">Email</span>
                    <span class="dv">{{ $data['email'] }}</span>
                </div>
            @endif
        </div>

        {{-- Service locations --}}
        <div class="section">
            <div class="sec-head">Service</div>
            <div class="drow">
                <span class="dl">Pickup</span>
                <span class="dv">{{ $data['pickup'] }}</span>
            </div>
            <div class="drow">
                <span class="dl">Drop-off</span>
                <span class="dv">{{ $data['dropoff'] }}</span>
            </div>
            @if (!empty($data['distance_km']))
                <div class="drow">
                    <span class="dl">Distance</span>
                    <span class="dv">{{ number_format($data['distance_km'], 1) }} km</span>
                </div>
            @endif
            @if (!empty($data['eta_minutes']))
                <div class="drow">
                    <span class="dl">Est. Travel Time</span>
                    <span class="dv">{{ $data['eta_minutes'] }} min</span>
                </div>
            @endif
            @if (!empty($data['truck_type']))
                <div class="drow">
                    <span class="dl">Truck Type</span>
                    <span class="dv">{{ $data['truck_type'] }}</span>
                </div>
            @endif
        </div>

        {{-- Vehicle --}}
        @php
            $hasVehicle =
                !empty($data['vehicle_make']) ||
                !empty($data['vehicle_model']) ||
                !empty($data['vehicle_plate']) ||
                !empty($data['vehicle_color']) ||
                !empty($data['vehicle_year']);
        @endphp
        @if ($hasVehicle)
            <div class="section">
                <div class="sec-head">Vehicle</div>
                @if (!empty($data['vehicle_make']) || !empty($data['vehicle_model']))
                    <div class="drow">
                        <span class="dl">Make / Model</span>
                        <span
                            class="dv">{{ trim(($data['vehicle_make'] ?? '') . ' ' . ($data['vehicle_model'] ?? '')) }}</span>
                    </div>
                @endif
                @if (!empty($data['vehicle_year']))
                    <div class="drow">
                        <span class="dl">Year</span>
                        <span class="dv">{{ $data['vehicle_year'] }}</span>
                    </div>
                @endif
                @if (!empty($data['vehicle_color']))
                    <div class="drow">
                        <span class="dl">Color</span>
                        <span class="dv">{{ $data['vehicle_color'] }}</span>
                    </div>
                @endif
                @if (!empty($data['vehicle_plate']))
                    <div class="drow">
                        <span class="dl">Plate Number</span>
                        <span class="dv">{{ $data['vehicle_plate'] }}</span>
                    </div>
                @endif
            </div>
        @endif

        {{-- Notes --}}
        @if (!empty($data['notes']))
            <div class="section">
                <div class="sec-head">Notes</div>
                <p style="font-size:0.84rem;margin:0;line-height:1.5;">{{ $data['notes'] }}</p>
            </div>
        @endif

        {{-- Price --}}
        <div class="section">
            <div class="sec-head">Pricing</div>
            <div class="drow">
                <span class="dl">Estimated Total</span>
                <span class="dv" style="font-size:0.9rem;">PHP {{ number_format($data['estimated_price'], 2) }}</span>
            </div>
            <div class="drow">
                <span class="dl">Final Price</span>
                <span class="dv" style="color:#6b7280;font-weight:400;font-size:0.8rem;">Set by dispatcher after
                    review</span>
            </div>
        </div>

        <div class="price-bar">
            <span class="p-label">Estimated Total</span>
            <span class="p-amount">PHP {{ number_format($data['estimated_price'], 2) }}</span>
        </div>

        {{-- What's next --}}
        <div class="next-box">
            <p><strong>What happens next</strong></p>
            <p>The dispatcher will review your request and send you a quotation. You will need to accept the quotation
                before a unit is assigned.</p>
            @if ($data['service_type'] === 'Scheduled')
                <p>Your slot for the scheduled date has been reserved.</p>
            @endif
            <p>To follow up, call us and provide your reference number: <strong>{{ $data['reference'] }}</strong></p>
        </div>

        {{-- Actions --}}
        <div class="action-row no-print">
            <a href="{{ route('landing') }}" class="btn-home">Back to Home</a>
            <button class="btn-print" onclick="window.print()">Print this page</button>
        </div>

    </div>

    @push('scripts')
        <script>
            function copyRef() {
                var ref = document.getElementById('refNum').textContent.trim();
                var btn = document.querySelector('.copy-ref-btn');
                var orig = btn.textContent;

                if (navigator.clipboard) {
                    navigator.clipboard.writeText(ref).then(function() {
                        btn.textContent = 'Copied!';
                        setTimeout(function() {
                            btn.textContent = orig;
                        }, 2000);
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = ref;
                    ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    btn.textContent = 'Copied!';
                    setTimeout(function() {
                        btn.textContent = orig;
                    }, 2000);
                }
            }
        </script>
    @endpush

@endsection
