<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation {{ $quotation->quotation_number }} - TowMate</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: #f4f4f5;
            font-family: Arial, sans-serif;
            color: #18181b;
        }

        .qv-wrap {
            max-width: 680px;
            margin: 0 auto;
            padding: 28px 16px 60px;
        }

        .qv-brand {
            font-size: 1.3rem;
            letter-spacing: .08em;
            margin-bottom: 24px;
        }

        .qv-ref {
            font-size: 0.75rem;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .qv-ref-num {
            font-size: 1.1rem;
            color: #000000;
            font-family: sans-serif;
        }

        .qv-banner {
            padding: 14px 18px;
            margin-bottom: 18px;
            border: 1px solid;
        }

        .qv-banner.pending {
            background: #fffbeb;
            border-color: #fcd34d;
            color: #92400e;
        }

        .qv-banner.accepted {
            background: #09090b;
            border-color: #18181b;
            color: #fff;
        }

        .qv-banner.expired {
            background: #f4f4f5;
            border-color: #d4d4d8;
            color: #52525b;
        }

        .qv-banner.outdated {
            background: #fff7ed;
            border-color: #fb923c;
            color: #9a3412;
        }

        .qv-banner.cancelled {
            background: #f4f4f5;
            border-color: #d4d4d8;
            color: #52525b;
        }

        .qv-banner-title {
            font-size: 0.9rem;
            margin-bottom: 3px;
        }

        .qv-banner-sub {
            font-size: 0.8rem;
            line-height: 1.5;
        }

        .qv-card {
            background: #fff;
            border: 1px solid #e4e4e7;
            padding: 20px 22px;
            margin-bottom: 14px;
        }

        .qv-section-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #000000;
            border-bottom: 1px solid #f4f4f5;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }

        .qv-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 5px 0;
            font-size: 0.85rem;
            border-bottom: 1px solid #f4f4f5;
        }

        .qv-row:last-child {
            border-bottom: none;
        }

        .qv-label {
            color: #000000;
            flex-shrink: 0;
        }

        .qv-value {
            text-align: right;
            word-break: break-word;
        }

        .qv-vehicle-block {
            border: 1px solid #e4e4e7;
            padding: 14px 16px;
            margin-bottom: 10px;
        }

        .qv-vehicle-block:last-child {
            margin-bottom: 0;
        }

        .qv-vehicle-head {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: #000000;
            margin-bottom: 10px;
        }

        .qv-price-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #facc15;
            padding: 14px 20px;
            margin-bottom: 18px;
        }

        .qv-price-label {
            font-size: 0.85rem;
        }

        .qv-price-amount {
            font-size: 1.4rem;
            font-family: sans-serif;
        }

        .qv-price-note {
            font-size: 0.75rem;
            color: #52525b;
            margin-top: 4px;
        }

        .qv-accept-btn {
            display: block;
            width: 100%;
            padding: 16px;
            background: #18181b;
            color: #facc15;
            font-size: 1rem;
            text-align: center;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            letter-spacing: .02em;
        }

        .qv-accept-btn:hover {
            background: #09090b;
        }

        .qv-terms {
            font-size: 0.75rem;
            color: #71717a;
            text-align: center;
            margin-top: 10px;
            line-height: 1.5;
        }

        .qv-track-btn {
            display: block;
            width: 100%;
            padding: 14px;
            background: #facc15;
            color: #18181b;
            font-size: 0.9rem;
            text-align: center;
            text-decoration: none;
            margin-bottom: 10px;
        }

        .qv-row-emphasis {
            font-weight: 700;
            padding-top: 9px;
            border-top: 1px dashed #d4d4d8;
        }

        .qv-is-add {
            color: #12804a;
        }

        .qv-is-deduct {
            color: #d8402c;
        }

        .qv-sched-badge {
            display: inline-block;
            background: #e0f2fe;
            color: #075985;
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .qv-countdown {
            font-size: 0.78rem;
            margin-top: 4px;
        }

        @media (max-width: 480px) {
            .qv-price-amount {
                font-size: 1.15rem;
            }
        }
    </style>
</head>

<body>
    <div class="qv-wrap">

        <div class="qv-brand">TowMate</div>

        <div style="margin-bottom:18px;">
            <div class="qv-ref">Booking Reference</div>
            <div class="qv-ref-num">{{ $quotation->quotation_number }}</div>
            @if ($totalVehicles > 1)
                <div style="font-size:0.78rem;color:#000000;margin-top:3px;">{{ $totalVehicles }} vehicles included in
                    this quotation</div>
            @endif
        </div>

        @if (session('success'))
            <div class="qv-banner accepted" style="margin-bottom:18px;">
                <div class="qv-banner-title">Done</div>
                <div class="qv-banner-sub">{{ session('success') }}</div>
            </div>
        @endif

        @if (session('error'))
            <div class="qv-banner" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b;margin-bottom:18px;">
                <div class="qv-banner-title">Error</div>
                <div class="qv-banner-sub">{{ session('error') }}</div>
            </div>
        @endif

        @if (!empty($isOutdated) && $isOutdated)
            <div class="qv-banner outdated">
                <div class="qv-banner-title">Quotation Updated</div>
                <div class="qv-banner-sub">The dispatcher revised this quotation. This link is no longer valid for
                    acceptance. A new link will be sent to your contact shortly.</div>
            </div>
        @endif

        @php
            $timeRemaining = $quotation->getTimeRemaining();
            $isSent = $quotation->status === 'sent';
            $isAccepted = $quotation->status === 'accepted';
            $isCancelled = $quotation->status === 'cancelled';
            $isExpired = (!$isSent && !$isCancelled) || ($timeRemaining['expired'] ?? false);
            $canAccept = $isSent && !$isExpired && !$isOutdated;
        @endphp

        @if ($isAccepted)
            <div class="qv-banner accepted">
                <div class="qv-banner-title">Quotation Accepted</div>
                <div class="qv-banner-sub">Your booking has been confirmed. Track your booking below.</div>
            </div>
        @elseif ($isCancelled)
            <div class="qv-banner cancelled">
                <div class="qv-banner-title">Quotation Cancelled</div>
                <div class="qv-banner-sub">This quotation is no longer available. Please contact us if you still
                    need towing service.</div>
            </div>
        @elseif ($isExpired && !$isAccepted)
            <div class="qv-banner expired">
                <div class="qv-banner-title">Quotation Expired</div>
                <div class="qv-banner-sub">This quotation has expired. Please contact us for a new one.</div>
            </div>
        @elseif ($quotation->status === 'pending')
            <div class="qv-banner pending">
                <div class="qv-banner-title">Under Review</div>
                <div class="qv-banner-sub">Our team is reviewing your request. You will receive a notification once the
                    quotation is ready.</div>
            </div>
        @elseif ($isSent && !$isExpired)
            <div class="qv-banner pending">
                <div class="qv-banner-title">Awaiting Your Confirmation</div>
                <div class="qv-banner-sub qv-countdown" id="countdown">{{ $timeRemaining['message'] ?? '' }}</div>
            </div>
        @endif

        <div class="qv-card">
            <div class="qv-section-label">Customer</div>
            <div class="qv-row">
                <span class="qv-label">Name</span>
                <span class="qv-value">{{ $quotation->customer->full_name }}</span>
            </div>
            <div class="qv-row">
                <span class="qv-label">Phone</span>
                <span class="qv-value">{{ $quotation->customer->phone }}</span>
            </div>
            @if ($quotation->customer->email)
                <div class="qv-row">
                    <span class="qv-label">Email</span>
                    <span class="qv-value">{{ $quotation->customer->email }}</span>
                </div>
            @endif
        </div>

        <div class="qv-card">
            <div class="qv-section-label">Route</div>
            <div class="qv-row">
                <span class="qv-label">Pickup</span>
                <span class="qv-value">{{ $quotation->pickup_address }}</span>
            </div>
            <div class="qv-row">
                <span class="qv-label">Drop-off</span>
                <span class="qv-value">{{ $quotation->dropoff_address }}</span>
            </div>
            @if ($quotation->distance_km)
                <div class="qv-row">
                    <span class="qv-label">Distance</span>
                    <span class="qv-value">{{ number_format($quotation->distance_km, 2) }} km</span>
                </div>
            @endif
            @if ($quotation->service_type === 'schedule' && $quotation->scheduled_date)
                <div class="qv-row">
                    <span class="qv-label">Scheduled</span>
                    <span class="qv-value">{{ $quotation->scheduled_date->format('M d, Y') }}
                        {{ $quotation->scheduled_time ? '· ' . $quotation->scheduled_time : '' }}</span>
                </div>
            @endif
        </div>

        @php
            $bookingService = app(\App\Services\BookingService::class);
            $isMultiBookingGroup = collect($extraVehicles)->contains(fn($ev) => isset($ev['booking_id']));
            $serviceDiscount = (float) ($quotation->discount ?? 0);
            $quotationVatRate = $bookingService->resolveVatRate(
                $quotation->vat_rate !== null ? (float) $quotation->vat_rate : null,
            );
            $pctLabel = fn($rate) => rtrim(rtrim(number_format($rate * 100, 2), '0'), '.') . '%';

            $deriveVehiclePricing = function (?float $baseRate, ?float $distanceFee, float $finalTotal, ?float $vatRateSnapshot, ?float $vatAmountSnapshot) use ($bookingService) {
                $hasRateBreakdown = $baseRate !== null && $distanceFee !== null;
                $vatExclusiveFromRates = $hasRateBreakdown ? round($baseRate + $distanceFee, 2) : null;
                $vatRate = $bookingService->resolveVatRate($vatRateSnapshot, $vatAmountSnapshot, $vatExclusiveFromRates);

                if ($hasRateBreakdown) {
                    $subtotal = $vatExclusiveFromRates;
                    $vatAmount = $vatAmountSnapshot !== null ? round($vatAmountSnapshot, 2) : round($subtotal * $vatRate, 2);
                } else {
                    $subtotal = round($finalTotal / (1 + $vatRate), 2);
                    $vatAmount = round($finalTotal - $subtotal, 2);
                }

                return [
                    'has_rate_breakdown' => $hasRateBreakdown,
                    'base_rate' => $baseRate,
                    'distance_fee' => $distanceFee,
                    'subtotal' => $subtotal,
                    'vat_amount' => $vatAmount,
                    'vat_rate' => $vatRate,
                    'total' => round($finalTotal, 2),
                ];
            };

            $primaryVehiclePricing = null;
            if (!$isMultiBookingGroup) {
                $sourceBooking = $quotation->sourceBooking;
                $primaryVehicleTotal = (float) $quotation->estimated_price + $serviceDiscount - collect($extraVehicles)->sum(fn($ev) => (float) ($ev['final_total'] ?? $ev['estimated_price'] ?? 0));

                if ($sourceBooking) {
                    $primaryBaseRate = (float) ($sourceBooking->base_rate ?? 0);
                    $primaryDistanceFee = $bookingService->distanceFeeFor((float) ($sourceBooking->distance_km ?? $quotation->distance_km ?? 0), (float) ($quotation->truckType->per_km_rate ?? 0));
                    $primaryVatAmountSnapshot = $sourceBooking->vat_amount !== null ? (float) $sourceBooking->vat_amount : null;
                } else {
                    $primaryBaseRate = (float) ($quotation->truckType->base_rate ?? 0);
                    $primaryDistanceFee = $bookingService->distanceFeeFor((float) ($quotation->distance_km ?? 0), (float) ($quotation->truckType->per_km_rate ?? 0));
                    $primaryVatAmountSnapshot = null;
                }

                $primaryVehiclePricing = $deriveVehiclePricing(
                    $primaryBaseRate,
                    $primaryDistanceFee,
                    $primaryVehicleTotal,
                    $quotation->vat_rate !== null ? (float) $quotation->vat_rate : null,
                    $primaryVatAmountSnapshot,
                );
            }

            $extraVehiclesPriced = collect($extraVehicles)->map(function ($ev) use ($deriveVehiclePricing) {
                $evTotal = (float) ($ev['final_total'] ?? $ev['estimated_price'] ?? 0);
                $evBaseRate = isset($ev['base_rate']) ? (float) $ev['base_rate'] : null;
                $evDistanceFee = isset($ev['distance_fee']) ? (float) $ev['distance_fee'] : null;
                $evVatRateSnapshot = isset($ev['vat_rate']) && $ev['vat_rate'] !== null ? (float) $ev['vat_rate'] : null;
                $evVatAmountSnapshot = isset($ev['vat_amount']) ? (float) $ev['vat_amount'] : null;

                return array_merge($ev, [
                    'pricing' => $deriveVehiclePricing($evBaseRate, $evDistanceFee, $evTotal, $evVatRateSnapshot, $evVatAmountSnapshot),
                ]);
            });

            $allVehiclePricings = collect($primaryVehiclePricing ? [$primaryVehiclePricing] : [])
                ->concat($extraVehiclesPriced->pluck('pricing'));
            $combinedSubtotal = round($allVehiclePricings->sum('subtotal'), 2);
            $combinedVat = round($allVehiclePricings->sum('vat_amount'), 2);
            $combinedServiceTotal = round($combinedSubtotal + $combinedVat, 2);
            $finalQuotedTotal = (float) $quotation->estimated_price;
            $quotationAdjustment = round($finalQuotedTotal - $combinedServiceTotal + $serviceDiscount, 2);
        @endphp

        <div class="qv-card">
            <div class="qv-section-label">Vehicles & Pricing</div>

            @unless ($isMultiBookingGroup)
                <div class="qv-vehicle-block">
                    <div class="qv-vehicle-head">
                        Vehicle 1 - {{ $quotation->truckType->name ?? 'Tow Truck' }}
                        @if ($quotation->service_type === 'schedule')
                            <span class="qv-sched-badge">Scheduled</span>
                        @endif
                    </div>
                    @if ($quotation->vehicle_make || $quotation->vehicle_model)
                        <div class="qv-row">
                            <span class="qv-label">Make / Model</span>
                            <span
                                class="qv-value">{{ trim(($quotation->vehicle_make ?? '') . ' ' . ($quotation->vehicle_model ?? '')) }}</span>
                        </div>
                    @endif
                    @if ($quotation->vehicle_year)
                        <div class="qv-row">
                            <span class="qv-label">Year</span>
                            <span class="qv-value">{{ $quotation->vehicle_year }}</span>
                        </div>
                    @endif
                    @if ($quotation->vehicle_color)
                        <div class="qv-row">
                            <span class="qv-label">Color</span>
                            <span class="qv-value">{{ $quotation->vehicle_color }}</span>
                        </div>
                    @endif
                    @if ($quotation->vehicle_plate_number)
                        <div class="qv-row">
                            <span class="qv-label">Plate</span>
                            <span class="qv-value">{{ $quotation->vehicle_plate_number }}</span>
                        </div>
                    @endif
                    @if ($primaryVehiclePricing['has_rate_breakdown'])
                        <div class="qv-row" style="margin-top:6px;">
                            <span class="qv-label">Base Rate</span>
                            <span class="qv-value">&#8369;{{ number_format($primaryVehiclePricing['base_rate'], 2) }}</span>
                        </div>
                        <div class="qv-row">
                            <span class="qv-label">Distance Fee</span>
                            <span class="qv-value">&#8369;{{ number_format($primaryVehiclePricing['distance_fee'], 2) }}</span>
                        </div>
                    @endif
                    <div class="qv-row" style="{{ $primaryVehiclePricing['has_rate_breakdown'] ? '' : 'margin-top:6px;' }}">
                        <span class="qv-label">Taxable Subtotal</span>
                        <span class="qv-value">&#8369;{{ number_format($primaryVehiclePricing['subtotal'], 2) }}</span>
                    </div>
                    <div class="qv-row">
                        <span class="qv-label">VAT ({{ $pctLabel($primaryVehiclePricing['vat_rate']) }})</span>
                        <span class="qv-value">&#8369;{{ number_format($primaryVehiclePricing['vat_amount'], 2) }}</span>
                    </div>
                    <div class="qv-row qv-row-emphasis">
                        <span class="qv-label">Vehicle Service Total</span>
                        <span class="qv-value" style="color:#09090b;">&#8369;{{ number_format($primaryVehiclePricing['total'], 2) }}</span>
                    </div>
                </div>
            @endunless

            @foreach ($extraVehiclesPriced as $ev)
                @php
                    $evIndex = $isMultiBookingGroup ? $loop->iteration : ($ev['vehicle_index'] ?? $loop->index + 2);
                    $vp = $ev['pricing'];
                @endphp
                <div class="qv-vehicle-block">
                    <div class="qv-vehicle-head">
                        Vehicle {{ $evIndex }} - {{ $ev['truck_type_name'] ?? 'Tow Truck' }}
                        @if (($ev['service_type'] ?? $quotation->service_type) === 'schedule')
                            <span class="qv-sched-badge">Scheduled</span>
                        @endif
                    </div>
                    @if (!empty($ev['distance_km']))
                        <div class="qv-row">
                            <span class="qv-label">Distance</span>
                            <span class="qv-value">{{ number_format($ev['distance_km'], 2) }} km</span>
                        </div>
                    @endif
                    @if ($vp['has_rate_breakdown'])
                        <div class="qv-row" style="margin-top:6px;">
                            <span class="qv-label">Base Rate</span>
                            <span class="qv-value">&#8369;{{ number_format($vp['base_rate'], 2) }}</span>
                        </div>
                        <div class="qv-row">
                            <span class="qv-label">Distance Fee</span>
                            <span class="qv-value">&#8369;{{ number_format($vp['distance_fee'], 2) }}</span>
                        </div>
                    @endif
                    <div class="qv-row" style="{{ $vp['has_rate_breakdown'] ? '' : 'margin-top:6px;' }}">
                        <span class="qv-label">Taxable Subtotal</span>
                        <span class="qv-value">&#8369;{{ number_format($vp['subtotal'], 2) }}</span>
                    </div>
                    <div class="qv-row">
                        <span class="qv-label">VAT ({{ $pctLabel($vp['vat_rate']) }})</span>
                        <span class="qv-value">&#8369;{{ number_format($vp['vat_amount'], 2) }}</span>
                    </div>
                    <div class="qv-row qv-row-emphasis">
                        <span class="qv-label">Vehicle Service Total</span>
                        <span class="qv-value" style="color:#09090b;">&#8369;{{ number_format($vp['total'], 2) }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="qv-card">
            <div class="qv-section-label">Price Summary</div>
            <div class="qv-row">
                <span class="qv-label">Taxable Subtotal</span>
                <span class="qv-value">&#8369;{{ number_format($combinedSubtotal, 2) }}</span>
            </div>
            <div class="qv-row">
                <span class="qv-label">VAT ({{ $pctLabel($quotationVatRate) }})</span>
                <span class="qv-value">&#8369;{{ number_format($combinedVat, 2) }}</span>
            </div>
            <div class="qv-row qv-row-emphasis">
                <span class="qv-label">Service Total (incl. VAT)</span>
                <span class="qv-value">&#8369;{{ number_format($combinedServiceTotal, 2) }}</span>
            </div>
            @if ($serviceDiscount > 0)
                <div class="qv-row">
                    <span class="qv-label">Service Discount</span>
                    <span class="qv-value qv-is-deduct">-&#8369;{{ number_format($serviceDiscount, 2) }}</span>
                </div>
            @endif
            @if ($quotationAdjustment != 0)
                <div class="qv-row">
                    <span class="qv-label">{{ $quotationAdjustment < 0 ? 'Discount' : 'Additional Fee' }}</span>
                    <span class="qv-value {{ $quotationAdjustment < 0 ? 'qv-is-deduct' : 'qv-is-add' }}">{{ $quotationAdjustment < 0 ? '-' : '+' }}&#8369;{{ number_format(abs($quotationAdjustment), 2) }}</span>
                </div>
            @endif
        </div>
        <div class="qv-price-bar">
            <div class="qv-price-label">Final Quoted Total</div>
            <div class="qv-price-amount">&#8369;{{ number_format($finalQuotedTotal, 2) }}</div>
        </div>

        @if ($canAccept)
            <div class="qv-card" style="text-align:center;">
                <p style="font-size:0.88rem;margin-bottom:16px;color:#18181b;">Review the details above and confirm
                    your booking.</p>
                <a href="{{ $signedAcceptUrl }}" class="qv-accept-btn">Accept and Continue</a>
                <p class="qv-terms">By accepting, you agree to the quoted price and service terms.</p>
            </div>
        @elseif ($isAccepted)
            <div class="qv-card" style="text-align:center;">
                <p style="font-size:0.88rem;margin-bottom:14px;color:#18181b;">Your booking is confirmed. Track its
                    progress below.</p>
                <a href="{{ route('public.track') }}?ref={{ urlencode($quotation->quotation_number) }}"
                    class="qv-track-btn">
                    Track Your Booking
                </a>
            </div>
        @endif

        <div style="text-align:center;font-size:0.75rem;color:#a1a1aa;margin-top:24px;">
            <p>Need help? Contact TowMate dispatch.</p>
        </div>

    </div>

    @if ($isSent && !$isExpired)
        <script>
            const expiresAt = new Date('{{ $quotation->expires_at?->toIso8601String() }}').getTime();

            function updateCountdown() {
                const dist = expiresAt - Date.now();
                if (dist < 0) {
                    document.getElementById('countdown').textContent = 'Expired';
                    setTimeout(() => location.reload(), 2000);
                    return;
                }
                const d = Math.floor(dist / 86400000);
                const h = Math.floor((dist % 86400000) / 3600000);
                const m = Math.floor((dist % 3600000) / 60000);
                document.getElementById('countdown').textContent = d > 0 ? `${d}d ${h}h ${m}m remaining` : h > 0 ?
                    `${h}h ${m}m remaining` : `${m}m remaining`;
            }
            updateCountdown();
            setInterval(updateCountdown, 60000);
        </script>
    @endif
</body>

</html>
