<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed - TowMate</title>
</head>

<body
    style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0"
                    style="background:#ffffff;border:1px solid #cbd5e1;overflow:hidden;">

                    {{-- Header --}}
                    <tr>
                        <td style="background:#09090b;padding:24px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>
                                        <img src="{{ asset('admin/images/TowingLogo.png') }}" alt="TowMate"
                                            style="height:40px;width:auto;display:block;margin-bottom:10px;">
                                        <p style="margin:0;font-size:20px;color:#ffffff;">Booking Confirmed</p>
                                        <p
                                            style="margin:4px 0 0;font-size:11px;color:#ffffff;letter-spacing:0.08em;text-transform:uppercase;">
                                            Accredited Towing Service</p>
                                    </td>
                                    <td align="right" valign="top">
                                        <p
                                            style="margin:0;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.08em;">
                                            Date Issued</p>
                                        <p style="margin:4px 0 0;font-size:13px;color:#a1a1aa;font-family:monospace;">
                                            {{ now()->format('M d, Y') }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#18181b;padding:12px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>
                                        <span
                                            style="font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Quotation
                                            No.</span>
                                        <span
                                            style="font-size:15px;color:#ffffff;font-family:monospace;margin-left:12px;">{{ $booking->quotation_number ?? ($booking->reference_number ?? '—') }}</span>
                                    </td>
                                    <td align="right">
                                        <span
                                            style="display:inline-block;background:#ffffff;color:#000000;font-size:11px;padding:3px 10px;text-transform:uppercase;letter-spacing:0.08em;">CONFIRMED
                                            &amp; LOCKED</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px 32px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width:50%;vertical-align:top;padding-right:16px;">
                                        <p
                                            style="margin:0 0 6px;font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;border-bottom:1px solid #e2e8f0;padding-bottom:4px;">
                                            Bill To</p>
                                        <p style="margin:0 0 2px;font-size:14px;color:#09090b;">
                                            {{ $booking->customer->full_name ?? 'Customer' }}</p>
                                        <p style="margin:0 0 1px;font-size:12px;color:#64748b;">
                                            {{ $booking->customer->email ?? '—' }}</p>
                                        <p style="margin:0;font-size:12px;color:#64748b;">
                                            {{ $booking->customer->phone ?? '—' }}</p>
                                    </td>
                                    <td style="width:50%;vertical-align:top;padding-left:16px;">
                                        <p
                                            style="margin:0 0 6px;font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;border-bottom:1px solid #e2e8f0;padding-bottom:4px;">
                                            Service Provider</p>
                                        <p style="margin:0 0 2px;font-size:14px;color:#09090b;">TowMate
                                            Towing Services</p>
                                        <p style="margin:0 0 1px;font-size:12px;color:#64748b;">support@towmate.com</p>
                                        <p style="margin:0;font-size:12px;color:#64748b;">(123) 456-7890</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 32px 0;">
                            <p
                                style="margin:0 0 8px;font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;border-bottom:1px solid #e2e8f0;padding-bottom:4px;">
                                Service Details</p>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;">
                                <tr style="background:#f8fafc;">
                                    <td
                                        style="padding:8px 12px;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;width:30%;border-right:1px solid #e2e8f0;">
                                        Field</td>
                                    <td
                                        style="padding:8px 12px;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;">
                                        Details</td>
                                </tr>
                                <tr style="border-top:1px solid #e2e8f0;">
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#64748b;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">
                                        Pickup Location</td>
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#0f172a;border-top:1px solid #e2e8f0;">
                                        {{ $booking->pickup_address ?? '—' }}</td>
                                </tr>
                                <tr style="border-top:1px solid #e2e8f0;background:#fafafa;">
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#64748b;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">
                                        Drop-off Location</td>
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#0f172a;border-top:1px solid #e2e8f0;">
                                        {{ $booking->dropoff_address ?? '—' }}</td>
                                </tr>
                                <tr style="border-top:1px solid #e2e8f0;">
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#64748b;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">
                                        Truck Type</td>
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#0f172a;border-top:1px solid #e2e8f0;">
                                        {{ $booking->truckType->name ?? '—' }}</td>
                                </tr>
                                <tr style="background:#fafafa;border-top:1px solid #e2e8f0;">
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#64748b;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">
                                        Distance</td>
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#0f172a;border-top:1px solid #e2e8f0;">
                                        {{ number_format((float) ($booking->distance_km ?? 0), 2) }} km</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Price Breakdown Table --}}
                    <tr>
                        <td style="padding:20px 32px 0;">
                            <p
                                style="margin:0 0 8px;font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;border-bottom:1px solid #e2e8f0;padding-bottom:4px;">
                                Price Breakdown</p>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;">
                                {{-- Table Head --}}
                                <tr style="background:#f8fafc;">
                                    <td
                                        style="padding:8px 12px;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;border-right:1px solid #e2e8f0;">
                                        Description</td>
                                    <td style="padding:8px 12px;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;width:100px;"
                                        align="right">Amount</td>
                                </tr>
                                {{-- Base Rate --}}
                                <tr>
                                    <td
                                        style="padding:10px 12px;font-size:13px;color:#374151;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">
                                        Base Rate
                                        <span
                                            style="font-size:11px;color:#94a3b8;display:block;margin-top:1px;">{{ $booking->truckType->name ?? 'Tow Truck' }}</span>
                                    </td>
                                    <td style="padding:10px 12px;font-size:13px;color:#0f172a;border-top:1px solid #e2e8f0;"
                                        align="right">₱{{ number_format($priceBreakdown['base_rate'], 2) }}</td>
                                </tr>
                                {{-- Distance Fee --}}
                                <tr style="background:#fafafa;">
                                    <td
                                        style="padding:10px 12px;font-size:13px;color:#374151;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">
                                        Distance Fee
                                        <span
                                            style="font-size:11px;color:#94a3b8;display:block;margin-top:1px;">{{ $priceBreakdown['km_increments'] }}
                                            × ₱200 per 4km ({{ number_format($priceBreakdown['distance_km'], 2) }}
                                            km)</span>
                                    </td>
                                    <td style="padding:10px 12px;font-size:13px;color:#0f172a;border-top:1px solid #e2e8f0;"
                                        align="right">₱{{ number_format($priceBreakdown['distance_fee'], 2) }}</td>
                                </tr>
                                @if ($priceBreakdown['additional_fee'] > 0)
                                    {{-- Additional Fees --}}
                                    <tr>
                                        <td
                                            style="padding:10px 12px;font-size:13px;color:#374151;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">
                                            Additional Fees
                                        </td>
                                        <td style="padding:10px 12px;font-size:13px;color:#0f172a;border-top:1px solid #e2e8f0;"
                                            align="right">₱{{ number_format($priceBreakdown['additional_fee'], 2) }}
                                        </td>
                                    </tr>
                                @endif
                                {{-- Total Row --}}
                                <tr style="background:#09090b;">
                                    <td
                                        style="padding:13px 12px;font-size:13px;color:#ffffff;border-top:2px solid #000000;">
                                        TOTAL (LOCKED)</td>
                                    <td style="padding:13px 12px;font-size:18px;color:#ffffff;border-top:2px solid #000000;font-family:monospace;"
                                        align="right">₱{{ number_format($priceBreakdown['total'], 2) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Confirmation Notice --}}
                    <tr>
                        <td style="padding:20px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;">
                                <tr>
                                    <td style="padding:14px 16px;">
                                        <p
                                            style="margin:0 0 4px;font-size:12px;color:#000000;text-transform:uppercase;letter-spacing:0.06em;">
                                            Booking Active</p>
                                        <p style="margin:0;font-size:12px;color:#000000;line-height:1.6;">Your booking
                                            is confirmed and the price above is final. The dispatcher will assign a
                                            towing unit and you will be notified when the team is on the way.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:14px 32px;border-top:1px solid #e2e8f0;background:#f8fafc;">
                            <p style="margin:0;font-size:11px;color:#94a3b8;line-height:1.7;">
                                This is a computer-generated quotation. No signature is required.<br>
                                Questions? Call <strong style="color:#64748b;">(123) 456-7890</strong> or email <strong
                                    style="color:#64748b;">support@towmate.com</strong><br>
                                Do not reply to this email — it is sent automatically.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>

</html>
