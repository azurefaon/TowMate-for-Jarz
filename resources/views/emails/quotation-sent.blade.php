<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Quotation is Ready</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .container {
            background: #ffffff;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 2px solid #f59e0b;
        }

        .logo {
            font-size: 32px;
            font-weight: 700;
            color: #f59e0b;
            margin-bottom: 8px;
        }

        .title {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px;
        }

        .subtitle {
            font-size: 14px;
            color: #64748b;
            margin: 0;
        }

        .content {
            margin: 24px 0;
        }

        .info-box {
            background: #fef3c7;
            border: 2px solid #fbbf24;
            border-radius: 8px;
            padding: 16px;
            margin: 20px 0;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #fde68a;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #92400e;
        }

        .info-value {
            color: #78350f;
            font-weight: 700;
        }

        .price-box {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
        }

        .price-label {
            font-size: 16px;
            color: #92400e;
            margin-bottom: 12px;
            font-weight: 700;
            text-align: center;
        }

        .price-amount {
            font-size: 36px;
            font-weight: 700;
            color: #92400e;
            text-align: center;
        }

        .cta-button {
            display: inline-block;
            background: #f59e0b;
            color: #ffffff;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            text-align: center;
            margin: 24px 0;
            transition: background 0.2s;
        }

        .cta-button:hover {
            background: #d97706;
        }

        .expiry-notice {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            padding: 12px;
            margin: 20px 0;
            text-align: center;
            color: #991b1b;
            font-size: 14px;
            font-weight: 600;
        }

        .footer {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 12px;
            color: #64748b;
        }

        .service-details {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            margin: 20px 0;
        }

        .service-row {
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .service-row:last-child {
            border-bottom: none;
        }

        .service-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .service-value {
            font-size: 14px;
            color: #0f172a;
            font-weight: 600;
            margin-top: 4px;
        }
    </style>

</head>

<body>
    <div class="container">
        <div class="header">
            <div class="logo">🚗 TowMate</div>
            <h1 class="title">Your Quotation is Ready !</h1>
            <p class="subtitle">Reference: {{ $quotation->quotation_number }}</p>
        </div>
        <div class="content">
            <p>Hi <strong>{{ $quotation->customer->name }}</strong>,
            </p>
            <p>Thank you for choosing TowMate ! We've prepared your towing service quotation.</p>
            <div class="service-details">
                <div class="service-row">
                    <div class="service-label">📍 Pickup Location</div>
                    <div class="service-value">{{ $quotation->pickup_address }}</div>
                </div>
                <div class="service-row">
                    <div class="service-label">📍 Drop-off Location</div>
                    <div class="service-value">{{ $quotation->dropoff_address }}</div>
                </div>
                <div class="service-row">
                    <div class="service-label">🚚 Vehicle Type</div>
                    <div class="service-value">{{ $quotation->truckType->name }}</div>
                </div>
                <div class="service-row">
                    <div class="service-label">📏 Distance</div>
                    <div class="service-value">{{ number_format($quotation->distance_km, 2) }} km</div>
                </div>
            </div>
            <div class="price-box">
                <div class="price-label">💵 Price Breakdown</div>
                <div
                    style="text-align: left; margin: 16px 0; padding: 16px; background: rgba(255,255,255,0.5); border-radius: 8px;">
                    <div style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px;">
                        <span>Base Price</span><span
                            style="font-weight: 600;">₱{{ number_format($priceBreakdown['base_price'], 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px;">
                        <span>Distance Fee ({{ number_format($priceBreakdown['distance_km'], 2) }} km)</span><span
                            style="font-weight: 600;">₱{{ number_format($priceBreakdown['distance_fee'], 2) }}</span>
                    </div>
                    @if ($priceBreakdown['has_excess'])
                        <div
                            style="padding-left: 16px; border-left: 2px solid #fbbf24; margin-left: 8px; margin-top: 4px;">
                            <div
                                style="display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; color: #78350f;">
                                <span>First 4 km ×
                                    ₱{{ number_format($priceBreakdown['per_km_rate'], 2) }}/km</span><span
                                    style="font-weight: 600;">₱{{ number_format($priceBreakdown['first_4km_fee'], 2) }}</span>
                            </div>
                            <div
                                style="display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; color: #78350f;">
                                <span>{{ number_format($priceBreakdown['excess_km'], 2) }} km × ₱200/km</span><span
                                    style="font-weight: 600;">₱{{ number_format($priceBreakdown['excess_fee'], 2) }}</span>
                            </div>
                        </div>
                    @endif
                    <div
                        style="border-top: 1px dashed #fbbf24; margin-top: 8px; padding-top: 8px; display: flex; justify-content: space-between; font-size: 15px; font-weight: 600; color: #92400e;">
                        <span>Customer's Expected Price</span>
                        <span>₱{{ number_format($priceBreakdown['customer_price'], 2) }}</span>
                    </div>
                    @if ($priceBreakdown['other_fees'] > 0)
                        <div style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px;">
                            <span>Other Fees</span><span
                                style="font-weight: 600;">₱{{ number_format($priceBreakdown['other_fees'], 2) }}</span>
                        </div>
                    @endif
                </div>
                <div style="border-top: 3px solid #92400e; padding-top: 16px; margin-top: 8px;">
                    <div class="price-label">TOTAL AMOUNT</div>
                    <div class="price-amount">₱{{ number_format($priceBreakdown['total_amount'], 2) }}</div>
                </div>
            </div>
            <div style="text-align: center;"><a href="{{ $signedAcceptUrl }}" class="cta-button">✅ Accept &
                    Continue</a></div>
            <div class="expiry-notice">⏰ This quotation expires in
                {{ $quotation->expires_at->diffForHumans() }}<br><small>({{ $quotation->expires_at->format('M d, Y g:i A') }})</small>
            </div>
            <div class="info-box">
                <div class="info-row"><span class="info-label">What's Next?</span>
                </div>
                <div style="padding-top: 12px; font-size: 14px; color: #78350f;">1. Click the button above to view full
                    details<br>2. Review the quotation and price breakdown<br>3. Click "Accept & Continue to Start" to
                    confirm<br>4. Your booking will be confirmed and service will begin </div>
            </div>
            <p style="margin-top: 24px;">If you have any questions,
                feel free to contact us:</p>
            <p style="margin: 8px 0;"><strong>Phone:</strong>(123)
                456-7890<br><strong>Email:</strong>support@towmate.com </p>
        </div>
        <div class="footer">
            <p>© {{ date('Y') }} TowMate. All rights reserved.</p>
            <p style="margin-top: 8px;"><small>This is an automated email. Please do not reply directly to this
                    message.</small></p>
        </div>
    </div>
</body>

</html>
@if ($priceBreakdown['has_excess'])
    feel free to contact us:
    </p>
    <p style="margin: 8px 0;"><strong>Phone:</strong>(123) 456-7890<br><strong>Email:</strong>support@towmate.com </p>
    </div>
    <div class="footer">
        <p>© {{ date('Y') }} TowMate. All rights reserved.</p>
        <p style="margin-top: 8px;"><small>This is an automated email. Please do not reply directly to this
                message.</small></p>
    </div>
    </div>
    </body>

    </html>
    @if ($priceBreakdown['has_excess'])
