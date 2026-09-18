<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isReminder ? 'Reminder' : 'Your' }} Jarz Quotation — TowMate</title>
</head>

<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
    @php
        $breakdown = $booking->quotation_breakdown ?? [];
        $baseRate = (float) ($breakdown['base_rate'] ?? ($booking->base_rate ?? 0));
        $distanceKm = (float) ($booking->distance_km ?? 0);
        $distanceFee = (float) ($breakdown['distance_fee'] ?? app(\App\Services\BookingService::class)->distanceFeeFor($distanceKm, (float) ($booking->truckType?->per_km_rate ?? 0)));
        $additionalFee = (float) ($breakdown['additional_fee'] ?? 0);
        $discountAmount = (float) ($breakdown['discount'] ?? 0);
        $estimatedTotal = (float) ($breakdown['final_total'] ?? ($booking->final_total ?? ($booking->computed_total ?? $baseRate + $distanceFee + $additionalFee - $discountAmount)));
    @endphp

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
                                            {{ $isReminder ? 'TowMate Reminder' : 'TowMate Quotation' }}</div>
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
                                @if ($isReminder)
                                    this is a friendly reminder that your quotation is still active.
                                @else
                                    dispatch has reviewed your towing request and your booking summary is ready.
                                @endif
                            </p>
                            <p
                                style="margin:0;font-size:12.5px;font-weight:bold;letter-spacing:0.03em;color:#71717a;font-family:'Courier New',Courier,monospace;">
                                Job Order #: {{ $booking->job_code }}
                                @if (!empty($booking->quotation_number))
                                    &nbsp;&middot;&nbsp; Quotation #: {{ $booking->quotation_number }}
                                @endif
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 28px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:#fffbeb;border-radius:10px;padding:14px 18px;">
                                        <p style="margin:0 0 4px;font-size:14px;font-weight:bold;color:#92400e;">
                                            {{ $isReminder ? 'Quotation Still Active' : 'Quoted / Recorded' }}</p>
                                        <p style="margin:0;font-size:13px;color:#b45309;line-height:1.5;">
                                            @if ($isReminder)
                                                Your quotation is still available for this booking, but it will
                                                expire automatically if not updated in time.
                                            @else
                                                This email now serves as your booking summary only, so you can
                                                view the estimated total and your booking details in one place.
                                            @endif
                                        </p>
                                        @if (!empty($validUntilLabel))
                                            <p style="margin:8px 0 0;font-size:12px;color:#b45309;">Valid until
                                                {{ $validUntilLabel }}</p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @if (filled($booking->dispatcher_note))
                        <tr>
                            <td style="padding:16px 28px 0;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="background:#fafafa;border-radius:10px;padding:14px 18px;">
                                            <p style="margin:0 0 4px;font-size:12px;font-weight:bold;color:#18181b;">
                                                Dispatch note</p>
                                            <p style="margin:0;font-size:13px;color:#71717a;line-height:1.5;">
                                                {{ $booking->dispatcher_note }}</p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

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
                                                    {{ $booking->pickup_address ?? 'Not provided' }}</td>
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
                                                    {{ $booking->dropoff_address ?? 'Not provided' }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                                <tr>
                                    <td style="font-size:12.5px;color:#71717a;">Vehicle: <strong
                                            style="color:#18181b;">{{ $booking->truckType->name ?? 'General Towing' }}</strong>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 28px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:4px 0;font-size:14px;color:#52525b;">Base rate</td>
                                    <td align="right" style="padding:4px 0;font-size:14px;color:#18181b;">
                                        ₱{{ number_format($baseRate, 2) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;font-size:14px;color:#52525b;">Distance fee</td>
                                    <td align="right" style="padding:4px 0;font-size:14px;color:#18181b;">
                                        ₱{{ number_format($distanceFee, 2) }}</td>
                                </tr>
                                @if ($additionalFee > 0)
                                    <tr>
                                        <td style="padding:4px 0;font-size:14px;color:#52525b;">Additional fee</td>
                                        <td align="right" style="padding:4px 0;font-size:14px;color:#18181b;">
                                            ₱{{ number_format($additionalFee, 2) }}</td>
                                    </tr>
                                @endif
                                @if ($discountAmount > 0)
                                    <tr>
                                        <td style="padding:4px 0;font-size:14px;color:#52525b;">Discount</td>
                                        <td align="right" style="padding:4px 0;font-size:14px;color:#15803d;">
                                            -₱{{ number_format($discountAmount, 2) }}</td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 28px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#18181b;border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="color:#a1a1aa;font-size:12.5px;vertical-align:middle;">
                                                    Estimated Total</td>
                                                <td align="right"
                                                    style="color:#ffffff;font-size:22px;font-weight:bold;">
                                                    ₱{{ number_format($estimatedTotal, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding:22px 28px 28px;">
                            <p style="margin:0;font-size:13.5px;color:#3f3f46;">
                                Open the TowMate app to track your booking status.
                            </p>
                            @if (!empty($documentUrl))
                                <p style="margin:8px 0 0;font-size:12.5px;color:#a1a1aa;">
                                    A PDF copy of this quotation is available in the TowMate app.
                                </p>
                            @endif
                            <p style="margin:14px 0 0;font-size:11.5px;color:#a1a1aa;">
                                Please keep this email as your booking record. If you need help, contact dispatch
                                at {{ config('app.dispatch_phone', '(555) 123-4567') }}.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>

</html>
