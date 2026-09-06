<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation {{ $quotation->quotation_number }} — TowMate</title>
</head>

<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:40px 16px;">
        <tr>
            <td align="center">
                <table width="480" cellpadding="0" cellspacing="0"
                    style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(24,24,27,0.10);">

                    {{-- Header band --}}
                    <tr>
                        <td style="background:#18181b;padding:22px 28px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="56" style="vertical-align:middle;">
                                        <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('customer/image/TowingLogo-email.png'))) }}"
                                            alt="Jarz Towing" width="52" height="52" style="display:block;border:0;">
                                    </td>
                                    <td style="text-align:center;vertical-align:middle;">
                                        <div style="font-size:13px;font-weight:bold;letter-spacing:0.14em;text-transform:uppercase;color:#ffffff;">
                                            TowMate Quotation</div>
                                    </td>
                                    <td width="56" style="vertical-align:middle;text-align:right;">
                                        <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('customer/image/accridetedlogo-email.png'))) }}"
                                            alt="MMDA Accredited" width="52" height="52"
                                            style="display:block;margin-left:auto;border:0;">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Greeting + reference --}}
                    <tr>
                        <td style="padding:24px 28px 0;">
                            <p style="margin:0 0 10px;font-size:15px;color:#3f3f46;line-height:1.5;">
                                Hi <strong style="color:#18181b;">{{ $quotation->customer->full_name }}</strong>,
                                your quotation has been cancelled.
                            </p>
                            <p style="margin:0;font-size:12.5px;font-weight:bold;letter-spacing:0.03em;color:#71717a;font-family:'Courier New',Courier,monospace;">
                                {{ $quotation->quotation_number }}</p>
                        </td>
                    </tr>

                    {{-- Cancellation notice --}}
                    <tr>
                        <td style="padding:20px 28px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px 18px;">
                                        <span style="font-size:13.5px;color:#b91c1c;line-height:1.5;">Your towing
                                            service quotation for the trip below has been cancelled by our dispatch
                                            team and is no longer valid.</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Route card --}}
                    <tr>
                        <td style="padding:16px 28px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#fafafa;border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 18px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:3px 0;vertical-align:top;width:64px;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">
                                                    From</td>
                                                <td style="padding:3px 0;font-size:14px;color:#18181b;">
                                                    {{ $quotation->pickup_address }}</td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="padding:8px 0;">
                                                    <div style="border-top:1px dashed #e4e4e7;"></div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:3px 0;vertical-align:top;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">
                                                    To</td>
                                                <td style="padding:3px 0;font-size:14px;color:#18181b;">
                                                    {{ $quotation->dropoff_address }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                                <tr>
                                    <td style="font-size:12.5px;color:#71717a;">Vehicle: <strong
                                            style="color:#18181b;">{{ $quotation->truckType->name }}</strong></td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Next step --}}
                    <tr>
                        <td align="center" style="padding:22px 28px 28px;">
                            <p style="margin:0;font-size:13.5px;color:#71717a;">
                                If you still need towing service, please book again through the TowMate app.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>

</html>
