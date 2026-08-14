<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Updated {{ $quotation->quotation_number }} — TowMate</title>
</head>

<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#000000;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;padding:32px 16px;">
        <tr>
            <td align="center">
                <table width="480" cellpadding="0" cellspacing="0" style="border:1px solid #000000;">

                    {{-- Header: two logos + brand --}}
                    <tr>
                        <td style="padding:20px 28px;border-bottom:1px solid #000000;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="48" style="vertical-align:middle;">
                                        <img src="{{ asset('customer/image/TowingLogo.png') }}" alt="Jarz Towing"
                                            width="44" height="44" style="display:block;border:0;">
                                    </td>
                                    <td style="text-align:center;vertical-align:middle;">
                                        <div style="font-size:14px;letter-spacing:0.12em;text-transform:uppercase;">
                                            TowMate — Quotation Updated</div>
                                    </td>
                                    <td width="48" style="vertical-align:middle;text-align:right;">
                                        <img src="{{ asset('customer/image/accridetedlogo.png') }}"
                                            alt="MMDA Accredited" width="44" height="44"
                                            style="display:block;margin-left:auto;border:0;">
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:14px 0 0;font-size:15px;">
                                Hi {{ $quotation->customer->full_name }}, the price for your quotation has been revised.
                            </p>
                        </td>
                    </tr>

                    {{-- Reference --}}
                    <tr>
                        <td style="padding:12px 28px;border-bottom:1px solid #000000;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="font-size:13px;letter-spacing:0.07em;text-transform:uppercase;">Reference</td>
                                    <td align="right" style="font-size:15px;font-family:'Courier New',Courier,monospace;">
                                        {{ $quotation->quotation_number }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Service details --}}
                    <tr>
                        <td style="padding:20px 28px;border-bottom:1px solid #000000;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:5px 0;vertical-align:top;width:64px;font-size:13px;">From</td>
                                    <td style="padding:5px 0;font-size:15px;">{{ $quotation->pickup_address }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 0;vertical-align:top;font-size:13px;">To</td>
                                    <td style="padding:5px 0;font-size:15px;">{{ $quotation->dropoff_address }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 0;vertical-align:top;font-size:13px;">Vehicle</td>
                                    <td style="padding:5px 0;font-size:15px;">{{ $quotation->truckType->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 0;vertical-align:top;font-size:13px;">Distance</td>
                                    <td style="padding:5px 0;font-size:15px;">
                                        {{ number_format($priceBreakdown['distance_km'], 2) }} km</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Updated price breakdown --}}
                    <tr>
                        <td style="padding:20px 28px;border-bottom:1px solid #000000;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:4px 0;font-size:15px;">Base rate</td>
                                    <td align="right" style="padding:4px 0;font-size:15px;">
                                        ₱{{ number_format($priceBreakdown['base_price'], 2) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;font-size:15px;">Distance fee</td>
                                    <td align="right" style="padding:4px 0;font-size:15px;">
                                        ₱{{ number_format($priceBreakdown['distance_fee'], 2) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;font-size:15px;">VAT (12%)</td>
                                    <td align="right" style="padding:4px 0;font-size:15px;">
                                        ₱{{ number_format($priceBreakdown['vat_amount'], 2) }}</td>
                                </tr>
                                @if ($priceBreakdown['other_fees'] > 0)
                                    <tr>
                                        <td style="padding:4px 0;font-size:15px;">Additional fees</td>
                                        <td align="right" style="padding:4px 0;font-size:15px;">
                                            ₱{{ number_format($priceBreakdown['other_fees'], 2) }}</td>
                                    </tr>
                                    @if (!empty($priceBreakdown['additional_fee_note']))
                                        <tr>
                                            <td colspan="2" style="padding:0 0 4px;font-size:12px;color:#4b5563;">
                                                ↳ {{ $priceBreakdown['additional_fee_note'] }}</td>
                                        </tr>
                                    @endif
                                @endif
                                <tr>
                                    <td colspan="2" style="padding:8px 0 0;">
                                        <hr style="border:none;border-top:1px solid #000000;margin:0;">
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0 0;font-size:17px;">Total</td>
                                    <td align="right" style="padding:8px 0 0;font-size:19px;">
                                        ₱{{ number_format($priceBreakdown['total_amount'], 2) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Next step --}}
                    <tr>
                        <td align="center" style="padding:20px 28px;">
                            <p style="margin:0;font-size:15px;">
                                Open the TowMate app to review and accept the updated price.
                            </p>
                            @if ($quotation->expires_at)
                                <p style="margin:8px 0 0;font-size:14px;">
                                    Expires {{ $quotation->expires_at->format('M d, Y g:i A') }}
                                </p>
                            @endif
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:14px 28px;border-top:1px solid #000000;">
                            <p style="margin:0;font-size:13px;line-height:1.6;">
                                Questions? Call (123) 456-7890 or email support@towmate.com<br>
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
