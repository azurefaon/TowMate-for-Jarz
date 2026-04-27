<!DOCTYPE html>
<html>

<head>
    <title>Jarz Quotation Summary</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            max-width: 680px;
            margin: 0 auto;
            padding: 20px;
            background: #f8fafc;
        }

        .header {
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
            color: white;
            padding: 36px 30px;
            text-align: center;
            border-radius: 18px 18px 0 0;
        }

        .content {
            padding: 32px 30px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-top: 0;
            border-radius: 0 0 18px 18px;
        }

        .footer {
            text-align: center;
            padding: 18px;
            font-size: 13px;
            color: #6b7280;
        }

        .status-box,
        .price-box {
            background: #f8fafc;
            border: 1px solid #dbeafe;
            padding: 20px;
            margin: 24px 0;
            border-radius: 12px;
        }

        .price-box {
            border-color: #fde68a;
            background: #fffdf4;
        }

        .note-box {
            background: #fff7ed;
            border-left: 4px solid #f59e0b;
            padding: 14px 16px;
            border-radius: 8px;
            margin: 18px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0 0;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        th {
            width: 36%;
            color: #374151;
        }

        .price-total td {
            font-weight: 700;
            color: #111827;
            border-bottom: 0;
        }

        .logo-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            padding: 14px 0 0;
        }

        .logo-bar img {
            height: 52px;
            width: auto;
            object-fit: contain;
        }

        .logo-divider {
            width: 1px;
            height: 44px;
            background: rgba(255, 255, 255, 0.35);
        }

        .btn-download {
            display: inline-block;
            margin: 22px 0 6px;
            padding: 13px 28px;
            background: #1d4ed8;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
    </style>
</head>

<body>
    @php
        $breakdown = $booking->quotation_breakdown ?? [];
        $baseRate = (float) ($breakdown['base_rate'] ?? ($booking->base_rate ?? 0));
        $distanceKm = (float) ($booking->distance_km ?? 0);
        $perKmRate = (float) ($booking->per_km_rate ?? 0);
        $distanceFee =
            (float) ($breakdown['distance_fee'] ?? ($distanceKm > 0 && $perKmRate > 0 ? $distanceKm * $perKmRate : 0));
        $additionalFee = (float) ($breakdown['additional_fee'] ?? 0);
        $discountAmount = (float) ($breakdown['discount'] ?? 0);
        $estimatedTotal =
            (float) ($breakdown['final_total'] ??
                ($booking->final_total ??
                    ($booking->computed_total ?? $baseRate + $distanceFee + $additionalFee - $discountAmount)));
    @endphp

    <div class="header">
        <div class="logo-bar">
            <img src="{{ asset('customer/image/accridetedlogo.png') }}" alt="MMDA Accredited">
            <div class="logo-divider"></div>
            <img src="{{ asset('customer/image/TowingLogo.png') }}" alt="Jarz Towing">
        </div>
        <h1>{{ !empty($isReminder) ? '⏰ Quotation follow-up reminder' : '📩 Your booking price summary' }}</h1>
        <p>{{ !empty($isReminder) ? 'Your quotation is still active. Please review the validity period below.' : 'Your towing quotation and booking details are ready for your records.' }}
        </p>
    </div>

    <div class="content">
        <h2>Hello {{ $booking->customer->full_name ?? 'Customer' }},</h2>

        <p>
            {{ !empty($isReminder)
                ? 'This is your day-5 follow-up reminder from dispatch. Your quotation is still available for this booking, but it will expire automatically if not updated in time.'
                : 'Dispatch has reviewed your towing request. This email now serves as your booking summary only, so you can view the estimated total and your booking credentials in one place.' }}
        </p>

        <div class="status-box">
            <h3 style="margin-top: 0;">Booking Details</h3>
            <table>
                <tr>
                    <th>Booking #</th>
                    <td>{{ $booking->job_code }}</td>
                </tr>
                <tr>
                    <th>Quotation #</th>
                    <td>{{ $booking->quotation_number ?? 'Pending' }}</td>
                </tr>
                <tr>
                    <th>Service</th>
                    <td>{{ $booking->truckType->name ?? 'General Towing' }}</td>
                </tr>
                <tr>
                    <th>Pickup</th>
                    <td>{{ $booking->pickup_address ?? 'Not provided' }}</td>
                </tr>
                <tr>
                    <th>Drop-off</th>
                    <td>{{ $booking->dropoff_address ?? 'Not provided' }}</td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><strong style="color: #2563eb;">Quoted / Recorded</strong></td>
                </tr>
                @if (!empty($validUntilLabel))
                    <tr>
                        <th>Valid Until</th>
                        <td>{{ $validUntilLabel }}</td>
                    </tr>
                @endif
            </table>
        </div>

        @if (filled($booking->dispatcher_note))
            <div class="note-box">
                <strong>Dispatch note:</strong><br>
                {{ $booking->dispatcher_note }}
            </div>
        @endif

        <div class="price-box">
            <h3 style="margin-top: 0;">Price Breakdown</h3>
            <table>
                <tr>
                    <th>Base rate</th>
                    <td>₱{{ number_format($baseRate, 2) }}</td>
                </tr>
                <tr>
                    <th>Distance fee</th>
                    <td>₱{{ number_format($distanceFee, 2) }}</td>
                </tr>
                @if ($additionalFee > 0)
                    <tr>
                        <th>Additional fee</th>
                        <td>₱{{ number_format($additionalFee, 2) }}</td>
                    </tr>
                @endif
                @if ($discountAmount > 0)
                    <tr>
                        <th>Discount</th>
                        <td>- ₱{{ number_format($discountAmount, 2) }}</td>
                    </tr>
                @endif
                <tr class="price-total">
                    <th>Estimated Total</th>
                    <td>₱{{ number_format($estimatedTotal, 2) }}</td>
                </tr>
            </table>
        </div>

        <p>
            Please keep this email as your booking record. If you need help, contact dispatch at
            <strong>{{ config('app.dispatch_phone', '(555) 123-4567') }}</strong>.
        </p>

        @if (!empty($documentUrl))
            <div style="text-align:center;">
                <a href="{{ $documentUrl }}" class="btn-download" target="_blank">⬇ Download Quotation PDF</a>
            </div>
        @endif

        <div style="text-align:center;margin-top:24px;padding-top:20px;border-top:1px solid #e5e7eb;">
            <p style="margin:0 0 12px;font-size:13px;color:#475569;">Want to keep an eye on your booking?</p>
            <a href="{{ route('customer.track', $booking->booking_code) }}"
               style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:10px;">
                Track My Booking →
            </a>
            <p style="margin:10px 0 0;font-size:11px;color:#9ca3af;">You'll need to be logged in to view the tracking page.</p>
        </div>
    </div>

    <div class="footer">
        <p>&copy; {{ date('Y') }} Jarz</p>
        <p>Price breakdown • Booking credentials • Safe towing</p>
    </div>
</body>

</html>
