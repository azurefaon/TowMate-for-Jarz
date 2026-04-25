<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Updated {{ $quotation->quotation_number }} — TowMate</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e4e4e7;">

    {{-- Header --}}
    <tr>
        <td style="padding:28px 32px 20px;border-bottom:1px solid #e4e4e7;">
            <p style="margin:0 0 14px;font-size:13px;font-weight:700;color:#18181b;letter-spacing:0.08em;text-transform:uppercase;">TowMate</p>
            <h1 style="margin:0 0 6px;font-size:20px;font-weight:700;color:#09090b;line-height:1.3;">Your quotation has been updated</h1>
            <p style="margin:0;font-size:13px;color:#71717a;">
                Hi {{ $quotation->customer->name }}, the dispatcher has revised the pricing. Please review the updated details below.
            </p>
        </td>
    </tr>

    {{-- Reference --}}
    <tr>
        <td style="padding:16px 32px;border-bottom:1px solid #e4e4e7;background:#fafafa;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.07em;font-weight:600;">Reference</td>
                    <td align="right" style="font-size:13px;font-weight:700;color:#09090b;font-family:monospace;">{{ $quotation->quotation_number }}</td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Route --}}
    <tr>
        <td style="padding:20px 32px;border-bottom:1px solid #e4e4e7;">
            <p style="margin:0 0 12px;font-size:11px;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.07em;">Service Details</p>
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="padding:6px 0;vertical-align:top;width:70px;">
                        <span style="font-size:11px;color:#a1a1aa;font-weight:600;">FROM</span>
                    </td>
                    <td style="padding:6px 0;font-size:13px;color:#18181b;font-weight:500;">{{ $quotation->pickup_address }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;vertical-align:top;">
                        <span style="font-size:11px;color:#a1a1aa;font-weight:600;">TO</span>
                    </td>
                    <td style="padding:6px 0;font-size:13px;color:#18181b;font-weight:500;">{{ $quotation->dropoff_address }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;vertical-align:top;">
                        <span style="font-size:11px;color:#a1a1aa;font-weight:600;">VEHICLE</span>
                    </td>
                    <td style="padding:6px 0;font-size:13px;color:#18181b;font-weight:500;">{{ $quotation->truckType->name }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;vertical-align:top;">
                        <span style="font-size:11px;color:#a1a1aa;font-weight:600;">DISTANCE</span>
                    </td>
                    <td style="padding:6px 0;font-size:13px;color:#18181b;font-weight:500;">{{ number_format($quotation->distance_km, 2) }} km</td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Price Breakdown --}}
    <tr>
        <td style="padding:20px 32px;border-bottom:1px solid #e4e4e7;">
            <p style="margin:0 0 12px;font-size:11px;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.07em;">Updated Price Breakdown</p>
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="padding:5px 0;font-size:13px;color:#3f3f46;">Base rate</td>
                    <td align="right" style="padding:5px 0;font-size:13px;color:#18181b;font-weight:600;">₱{{ number_format($priceBreakdown['base_price'], 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:5px 0;font-size:13px;color:#3f3f46;">Distance fee ({{ number_format($priceBreakdown['distance_km'], 2) }} km)</td>
                    <td align="right" style="padding:5px 0;font-size:13px;color:#18181b;font-weight:600;">₱{{ number_format($priceBreakdown['distance_fee'], 2) }}</td>
                </tr>
                @if ($priceBreakdown['has_excess'])
                <tr>
                    <td style="padding:3px 0 3px 14px;font-size:12px;color:#71717a;">First 4 km x ₱{{ number_format($priceBreakdown['per_km_rate'], 2) }}/km</td>
                    <td align="right" style="padding:3px 0;font-size:12px;color:#71717a;">₱{{ number_format($priceBreakdown['first_4km_fee'], 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:3px 0 3px 14px;font-size:12px;color:#71717a;">{{ number_format($priceBreakdown['excess_km'], 2) }} km excess x ₱200/km</td>
                    <td align="right" style="padding:3px 0;font-size:12px;color:#71717a;">₱{{ number_format($priceBreakdown['excess_fee'], 2) }}</td>
                </tr>
                @endif
                @if ($priceBreakdown['other_fees'] > 0)
                <tr>
                    <td style="padding:5px 0;font-size:13px;color:#3f3f46;">Additional fees</td>
                    <td align="right" style="padding:5px 0;font-size:13px;color:#18181b;font-weight:600;">₱{{ number_format($priceBreakdown['other_fees'], 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td colspan="2" style="padding:10px 0 0;"><hr style="border:none;border-top:1px solid #e4e4e7;margin:0;"></td>
                </tr>
                <tr>
                    <td style="padding:10px 0 0;font-size:15px;font-weight:700;color:#09090b;">Total</td>
                    <td align="right" style="padding:10px 0 0;font-size:18px;font-weight:800;color:#09090b;">₱{{ number_format($priceBreakdown['total_amount'], 2) }}</td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- CTA --}}
    <tr>
        <td align="center" style="padding:28px 32px;">
            <a href="{{ $quotationUrl }}"
               style="display:inline-block;background:#09090b;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:13px 36px;border-radius:8px;letter-spacing:0.01em;">
                View Updated Quotation
            </a>
            @if ($quotation->expires_at)
            <p style="margin:14px 0 0;font-size:12px;color:#a1a1aa;">
                Expires {{ $quotation->expires_at->diffForHumans() }}
                &nbsp;({{ $quotation->expires_at->format('M d, Y g:i A') }})
            </p>
            @endif
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
<p style="margin:16px 0 0;font-size:11px;color:#a1a1aa;text-align:center;">© {{ date('Y') }} TowMate. All rights reserved.</p>
</td></tr>
</table>

</body>
</html>
