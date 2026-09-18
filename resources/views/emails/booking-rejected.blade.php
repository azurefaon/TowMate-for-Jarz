<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Not Accepted - TowMate</title>
</head>

<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:40px 16px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0"
                    style="width:480px;max-width:480px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(24,24,27,0.10);">

                    <tr>
                        <td style="background:#18181b;padding:22px 28px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="56" style="vertical-align:middle;">
                                        <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('customer/image/TowingLogo-email.png'))) }}"
                                            alt="Jarz Towing" width="52" height="52" style="display:block;border:0;">
                                    </td>
                                    <td style="text-align:center;vertical-align:middle;">
                                        <div
                                            style="font-size:13px;font-weight:bold;letter-spacing:0.14em;text-transform:uppercase;color:#ffffff;">
                                            TowMate Booking</div>
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

                    <tr>
                        <td style="padding:24px 28px 0;">
                            <p style="margin:0 0 10px;font-size:15px;color:#3f3f46;line-height:1.5;">
                                Hi <strong style="color:#18181b;">{{ $booking->customer->full_name ?? 'Customer' }}</strong>,
                                we regret to inform you that your towing request could not be accommodated at
                                this time. Your booking has been reviewed by our dispatch team and was not
                                accepted. We apologize for any inconvenience this may cause.
                            </p>
                            <p
                                style="margin:0;font-size:12.5px;font-weight:bold;letter-spacing:0.03em;color:#71717a;font-family:'Courier New',Courier,monospace;">
                                {{ $booking->job_code ?? ($booking->reference_number ?? '—') }}</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 28px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:#fef2f2;border-radius:10px;padding:14px 18px;">
                                        <p style="margin:0 0 4px;font-size:14px;font-weight:bold;color:#991b1b;">
                                            Booking Not Accepted</p>
                                        <p style="margin:0;font-size:13px;color:#b91c1c;line-height:1.5;">
                                            {{ $booking->rejection_reason ?: 'Your request could not be accommodated at this time. Please contact dispatch for assistance.' }}
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 28px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#fafafa;border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 18px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td
                                                    style="padding:3px 0;vertical-align:top;width:64px;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">
                                                    From</td>
                                                <td style="padding:3px 0;font-size:14px;color:#18181b;">
                                                    {{ $booking->pickup_address ?? '—' }}</td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="padding:8px 0;">
                                                    <div style="border-top:1px dashed #e4e4e7;"></div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td
                                                    style="padding:3px 0;vertical-align:top;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">
                                                    To</td>
                                                <td style="padding:3px 0;font-size:14px;color:#18181b;">
                                                    {{ $booking->dropoff_address ?? '—' }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                                <tr>
                                    <td style="font-size:12.5px;color:#71717a;">Vehicle: <strong
                                            style="color:#18181b;">{{ $booking->truckType->name ?? '—' }}</strong>
                                    </td>
                                    <td align="right" style="font-size:12.5px;color:#71717a;">Submitted: <strong
                                            style="color:#18181b;">{{ $booking->created_at->format('M d, Y g:i A') }}</strong>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 28px 28px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:#fafafa;border-radius:10px;padding:14px 18px;">
                                        <p style="margin:0 0 4px;font-size:12px;font-weight:bold;color:#18181b;">
                                            What's next?</p>
                                        <p style="margin:0;font-size:13px;color:#71717a;line-height:1.5;">
                                            You may submit a new booking request at any time. If you believe this
                                            was in error or need further assistance, please contact our support
                                            team.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding:0 28px 28px;">
                            <p style="margin:0;font-size:11px;color:#a1a1aa;line-height:1.7;">
                                Questions? Call <strong style="color:#71717a;">(123) 456-7890</strong> or email
                                <strong style="color:#71717a;">support@towmate.com</strong><br>
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
