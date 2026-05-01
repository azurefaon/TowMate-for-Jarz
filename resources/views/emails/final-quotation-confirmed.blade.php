<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed — TowMate</title>
</head>

<body
    style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0"
                    style="background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e4e4e7;">

                    {{-- Header --}}
                    <tr>
                        <td style="background:#09090b;padding:28px 32px;">
                            <p
                                style="margin:0 0 10px;font-size:12px;font-weight:700;color:#facc15;letter-spacing:0.1em;text-transform:uppercase;">
                                TowMate</p>
                            <h1 style="margin:0 0 6px;font-size:21px;font-weight:800;color:#ffffff;line-height:1.3;">
                                Booking Confirmed</h1>
                            <p style="margin:0;font-size:13px;color:#a1a1aa;">Your final quotation has been accepted and
                                the price is locked.</p>
                        </td>
                    </tr>

                    {{-- Greeting + Reference --}}
                    <tr>
                        <td style="padding:20px 32px 0;">
                            <p style="margin:0 0 4px;font-size:14px;color:#18181b;">Hi
                                <strong>{{ $booking->customer->full_name ?? 'Customer' }}</strong>,</p>
                            <p style="margin:0;font-size:13px;color:#71717a;line-height:1.6;">Thank you for confirming.
                                Your towing booking is now active and the dispatcher will proceed with your service.</p>
                        </td>
                    </tr>

                    {{-- Reference badge --}}
                    <tr>
                        <td style="padding:16px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#fafafa;border:1px solid #e4e4e7;border-radius:8px;">
                                <tr>
                                    <td
                                        style="padding:12px 16px;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.07em;font-weight:600;">
                                        Booking Reference</td>
                                    <td align="right"
                                        style="padding:12px 16px;font-size:14px;font-weight:800;color:#09090b;font-family:monospace;">
                                        {{ $booking->quotation_number ?? ($booking->reference_number ?? '—') }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Route --}}
                    <tr>
                        <td style="padding:0 32px 16px;">
                            <p
                                style="margin:0 0 10px;font-size:11px;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.07em;">
                                Route</p>
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:5px 0;vertical-align:top;width:70px;"><span
                                            style="font-size:11px;color:#a1a1aa;font-weight:600;">FROM</span></td>
                                    <td style="padding:5px 0;font-size:13px;color:#18181b;font-weight:500;">
                                        {{ $booking->pickup_address ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 0;vertical-align:top;"><span
                                            style="font-size:11px;color:#a1a1aa;font-weight:600;">TO</span></td>
                                    <td style="padding:5px 0;font-size:13px;color:#18181b;font-weight:500;">
                                        {{ $booking->dropoff_address ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 0;vertical-align:top;"><span
                                            style="font-size:11px;color:#a1a1aa;font-weight:600;">TRUCK</span></td>
                                    <td style="padding:5px 0;font-size:13px;color:#18181b;font-weight:500;">
                                        {{ $booking->truckType->name ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 0;vertical-align:top;"><span
                                            style="font-size:11px;color:#a1a1aa;font-weight:600;">DISTANCE</span></td>
                                    <td style="padding:5px 0;font-size:13px;color:#18181b;font-weight:500;">
                                        {{ number_format((float) ($booking->distance_km ?? 0), 2) }} km</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Price Breakdown --}}
                    <tr>
                        <td style="padding:16px 32px;border-top:1px solid #e4e4e7;border-bottom:1px solid #e4e4e7;">
                            <p
                                style="margin:0 0 12px;font-size:11px;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.07em;">
                                Final Price Breakdown</p>
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:5px 0;font-size:13px;color:#3f3f46;">Base rate
                                        ({{ $booking->truckType->name ?? 'Truck' }})</td>
                                    <td align="right"
                                        style="padding:5px 0;font-size:13px;color:#18181b;font-weight:600;">
                                        ₱{{ number_format($priceBreakdown['base_rate'], 2) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 0;font-size:13px;color:#3f3f46;">Distance fee
                                        ({{ $priceBreakdown['km_increments'] }} &times; ₱200 per 4km)</td>
                                    <td align="right"
                                        style="padding:5px 0;font-size:13px;color:#18181b;font-weight:600;">
                                        ₱{{ number_format($priceBreakdown['distance_fee'], 2) }}</td>
                                </tr>
                                @if ($priceBreakdown['additional_fee'] > 0)
                                    <tr>
                                        <td style="padding:5px 0;font-size:13px;color:#3f3f46;">Additional fees</td>
                                        <td align="right"
                                            style="padding:5px 0;font-size:13px;color:#18181b;font-weight:600;">
                                            ₱{{ number_format($priceBreakdown['additional_fee'], 2) }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td colspan="2" style="padding:8px 0 0;">
                                        <hr style="border:none;border-top:1px solid #e4e4e7;margin:0;">
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0 0;font-size:15px;font-weight:700;color:#09090b;">Total
                                        (Locked)</td>
                                    <td align="right"
                                        style="padding:10px 0 0;font-size:20px;font-weight:800;color:#facc15;">
                                        ₱{{ number_format($priceBreakdown['total'], 2) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Status notice --}}
                    <tr>
                        <td style="padding:20px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
                                <tr>
                                    <td
                                        style="padding:14px 16px;font-size:13px;color:#166534;font-weight:600;line-height:1.6;">
                                        Your booking is now confirmed. The dispatcher will assign a towing unit and you
                                        will be notified when the team is on the way.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:16px 32px;border-top:1px solid #e4e4e7;background:#fafafa;">
                            <p style="margin:0;font-size:12px;color:#a1a1aa;line-height:1.6;">
                                Questions? Call us at (123) 456-7890 or email support@towmate.com<br>
                                Do not reply to this email — it is sent automatically.
                            </p>
                        </td>
                    </tr>

                </table>
                <p style="margin:16px 0 0;font-size:11px;color:#a1a1aa;text-align:center;">&copy; {{ date('Y') }}
                    TowMate. All rights reserved.</p>
            </td>
        </tr>
    </table>

</body>

</html>
