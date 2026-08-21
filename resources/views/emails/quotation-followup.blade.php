<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reminder — Quotation {{ $quotation->quotation_number }} — TowMate</title>
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
                                        <div style="font-size:12px;letter-spacing:0.12em;text-transform:uppercase;">
                                            TowMate — Reminder</div>
                                    </td>
                                    <td width="48" style="vertical-align:middle;text-align:right;">
                                        <img src="{{ asset('customer/image/accridetedlogo.png') }}"
                                            alt="MMDA Accredited" width="44" height="44"
                                            style="display:block;margin-left:auto;border:0;">
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:14px 0 0;font-size:13px;">
                                Hi {{ $quotation->customer->full_name ?? $quotation->customer->name }}, your quotation
                                is still waiting for a response.
                            </p>
                        </td>
                    </tr>

                    {{-- Reference --}}
                    <tr>
                        <td style="padding:12px 28px;border-bottom:1px solid #000000;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="font-size:11px;letter-spacing:0.07em;text-transform:uppercase;">Reference</td>
                                    <td align="right" style="font-size:13px;font-family:'Courier New',Courier,monospace;">
                                        {{ $quotation->quotation_number }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Amount --}}
                    <tr>
                        <td style="padding:20px 28px;border-bottom:1px solid #000000;">
                            <p style="margin:0 0 4px;font-size:11px;letter-spacing:0.07em;text-transform:uppercase;">
                                Quoted total</p>
                            <p style="margin:0;font-size:22px;">
                                ₱{{ number_format((float) $quotation->estimated_price, 2) }}</p>
                        </td>
                    </tr>

                    {{-- Next step --}}
                    <tr>
                        <td align="center" style="padding:20px 28px;">
                            <p style="margin:0;font-size:13px;">
                                Open the TowMate app to review and respond to this quotation.
                            </p>
                            @if ($quotation->expires_at)
                                <p style="margin:8px 0 0;font-size:12px;">
                                    Expires {{ $quotation->expires_at->format('M d, Y g:i A') }}. No response by then
                                    and the request will close automatically.
                                </p>
                            @endif
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:14px 28px;border-top:1px solid #000000;">
                            <p style="margin:0;font-size:11px;line-height:1.6;">
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
