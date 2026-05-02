<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Not Accepted - TowMate</title>
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
                                        <p
                                            style="margin:0 0 2px;font-size:11px;color:#71717a;letter-spacing:0.12em;text-transform:uppercase;">
                                            TowMate</p>
                                        <p style="margin:0;font-size:20px;color:#ffffff;">Booking Not Accepted</p>
                                    </td>
                                    <td align="right" valign="top">
                                        <p
                                            style="margin:0;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.08em;">
                                            Date</p>
                                        <p style="margin:4px 0 0;font-size:13px;color:#a1a1aa;font-family:monospace;">
                                            {{ now()->format('M d, Y') }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Reference banner --}}
                    <tr>
                        <td style="background:#18181b;padding:12px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>
                                        <span
                                            style="font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Reference</span>
                                        <span
                                            style="font-size:15px;color:#ffffff;font-family:monospace;margin-left:12px;">{{ $booking->job_code ?? ($booking->reference_number ?? '—') }}</span>
                                    </td>
                                    <td align="right">
                                        <span
                                            style="display:inline-block;background:#dc2626;color:#ffffff;font-size:11px;padding:3px 10px;text-transform:uppercase;letter-spacing:0.08em;">NOT
                                            ACCEPTED</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Greeting --}}
                    <tr>
                        <td style="padding:24px 32px 0;">
                            <p style="margin:0 0 6px;font-size:14px;color:#18181b;">Hi
                                <strong>{{ $booking->customer->full_name ?? 'Customer' }}</strong>,</p>
                            <p style="margin:0;font-size:13px;color:#64748b;line-height:1.7;">We regret to inform you
                                that your towing request could not be accommodated at this time. Your booking has been
                                reviewed by our dispatch team and was not accepted. We apologize for any inconvenience
                                this may cause.</p>
                        </td>
                    </tr>

                    {{-- Reason box --}}
                    <tr>
                        <td style="padding:16px 32px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#fef2f2;border:1px solid #fca5a5;">
                                <tr>
                                    <td style="padding:14px 16px;">
                                        <p
                                            style="margin:0 0 4px;font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">
                                            Reason</p>
                                        <p style="margin:0;font-size:13px;color:#374151;line-height:1.6;">
                                            {{ $booking->rejection_reason ?: 'Your request could not be accommodated at this time. Please contact dispatch for assistance.' }}
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Booking details --}}
                    <tr>
                        <td style="padding:20px 32px 0;">
                            <p
                                style="margin:0 0 8px;font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;border-bottom:1px solid #e2e8f0;padding-bottom:4px;">
                                Booking Details</p>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;">
                                <tr>
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#94a3b8;border-right:1px solid #e2e8f0;width:35%;">
                                        Truck Type</td>
                                    <td style="padding:9px 12px;font-size:12px;color:#374151;">
                                        {{ $booking->truckType->name ?? '—' }}</td>
                                </tr>
                                <tr style="background:#fafafa;">
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#94a3b8;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">
                                        Pickup</td>
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#374151;border-top:1px solid #e2e8f0;">
                                        {{ $booking->pickup_address ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#94a3b8;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">
                                        Drop-off</td>
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#374151;border-top:1px solid #e2e8f0;">
                                        {{ $booking->dropoff_address ?? '—' }}</td>
                                </tr>
                                <tr style="background:#fafafa;">
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#94a3b8;border-right:1px solid #e2e8f0;border-top:1px solid #e2e8f0;">
                                        Date Submitted</td>
                                    <td
                                        style="padding:9px 12px;font-size:12px;color:#374151;border-top:1px solid #e2e8f0;">
                                        {{ $booking->created_at->format('M d, Y g:i A') }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Next step notice --}}
                    <tr>
                        <td style="padding:20px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#f8fafc;border:1px solid #e2e8f0;">
                                <tr>
                                    <td style="padding:14px 16px;">
                                        <p
                                            style="margin:0 0 4px;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;">
                                            What's next?</p>
                                        <p style="margin:0;font-size:12px;color:#64748b;line-height:1.6;">You may submit
                                            a new booking request at any time. If you believe this was in error or need
                                            further assistance, please contact our support team.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:14px 32px;border-top:1px solid #e2e8f0;background:#f8fafc;">
                            <p style="margin:0;font-size:11px;color:#94a3b8;line-height:1.7;">
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
