<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice – {{ $invoice->invoice_number }}</title>
</head>

<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
    @php
        $booking = $invoice->booking;
        $custName = $booking->customer->full_name ?? ($booking->customer->name ?? 'Customer');
        $custPhone = $booking->customer->phone ?? '—';
        $custEmail = $booking->customer->email ?? '—';
        $pickup = $booking->pickup_address ?? '—';
        $dropoff = $booking->dropoff_address ?? '—';
        $truckType = $booking->truckType->name ?? '—';
        $baseRate = (float) ($booking->base_rate ?? 0);
        $distanceKm = (float) ($booking->distance_km ?? 0);
        $distanceFee = app(\App\Services\BookingService::class)->distanceFeeFor(
            $distanceKm,
            (float) ($booking->truckType?->per_km_rate ?? 0),
        );
        $additionalFeeNote = $booking->dispatcher_note
            ?? collect($booking->quotation?->price_change_log ?? [])->last()['reason']
            ?? null;
        $fmt = fn(float $v) => '&#8369;' . number_format($v, 2);
        $groupTotal = ! empty($groupVehicles)
            ? array_sum(array_column($groupVehicles, 'final_total')) + $groupAdjustment
            : null;
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
                                            TowMate Invoice</div>
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
                                Hi <strong style="color:#18181b;">{{ $custName }}</strong>, here's your invoice for
                                Job {{ $booking->job_code ?? $booking->booking_code }}.
                            </p>
                            <p
                                style="margin:0;font-size:12.5px;font-weight:bold;letter-spacing:0.03em;color:#71717a;font-family:'Courier New',Courier,monospace;">
                                Job Order #: {{ $booking->job_code ?? $booking->booking_code }} &nbsp;&middot;&nbsp;
                                Invoice #: {{ $invoice->invoice_number }}
                            </p>
                            @if ($invoice->previousInvoice)
                                <p style="margin:6px 0 0;font-size:11.5px;color:#a1a1aa;">
                                    Replaces {{ $invoice->previousInvoice->invoice_number }} (voided)</p>
                            @endif
                            <p style="margin:6px 0 0;font-size:11.5px;color:#a1a1aa;">
                                Date: {{ now()->format('F d, Y') }}</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 28px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;padding-bottom:4px;">
                                        Billed To</td>
                                </tr>
                                <tr>
                                    <td style="font-size:13px;color:#18181b;font-weight:bold;">{{ $custName }}</td>
                                </tr>
                                <tr>
                                    <td style="font-size:11.5px;color:#71717a;padding-top:2px;">{{ $custPhone }}
                                        &nbsp;&middot;&nbsp; {{ $custEmail }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:14px 28px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#fafafa;border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 18px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td
                                                    style="padding:3px 0;vertical-align:top;width:64px;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">
                                                    From</td>
                                                <td style="padding:3px 0;font-size:14px;color:#18181b;">{{ $pickup }}
                                                </td>
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
                                                <td style="padding:3px 0;font-size:14px;color:#18181b;">{{ $dropoff }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                                <tr>
                                    <td style="font-size:12.5px;color:#71717a;">Vehicle: <strong
                                            style="color:#18181b;">{{ $truckType }}</strong></td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 28px 0;">
                            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                <tr>
                                    <td style="background:#18181b;padding:10px 16px;border-radius:10px 0 0 0;font-size:11px;font-weight:bold;color:#ffffff;text-transform:uppercase;letter-spacing:0.08em;"
                                        width="60%">Type of Vehicle / Service</td>
                                    <td style="background:#18181b;padding:10px 16px;border-radius:0 10px 0 0;font-size:11px;font-weight:bold;color:#ffffff;text-transform:uppercase;letter-spacing:0.08em;text-align:right;"
                                        width="40%">Amount</td>
                                </tr>
                                @if (! empty($groupVehicles))
                                    @foreach ($groupVehicles as $index => $vehicle)
                                        <tr>
                                            <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#18181b;">
                                                Vehicle {{ $index + 1 }} — {{ $vehicle['truck_type_name'] ?? 'Towing Service' }}</td>
                                            <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#18181b;text-align:right;">
                                                {!! $fmt((float) ($vehicle['final_total'] ?? 0)) !!}</td>
                                        </tr>
                                    @endforeach
                                    @if ($groupAdjustment != 0)
                                        <tr>
                                            <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#18181b;">
                                                Quotation Adjustment</td>
                                            <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#18181b;text-align:right;">
                                                {{ $groupAdjustment > 0 ? '+' : '' }}{!! $fmt($groupAdjustment) !!}</td>
                                        </tr>
                                    @endif
                                @else
                                    <tr>
                                        <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#18181b;">
                                            {{ $truckType }} — Base Rate</td>
                                        <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#18181b;text-align:right;">
                                            {!! $fmt($baseRate) !!}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#18181b;">
                                            Distance Fee
                                            @if ($distanceKm > 0)
                                                <span style="font-size:11px;color:#a1a1aa;">&nbsp;({{ number_format($distanceKm, 1) }}
                                                    km)</span>
                                            @endif
                                        </td>
                                        <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;text-align:right;">
                                            @if ($distanceFee > 0)
                                                <span style="color:#18181b;">{!! $fmt($distanceFee) !!}</span>
                                            @else
                                                <span style="color:#15803d;font-size:12px;">Free</span>
                                            @endif
                                        </td>
                                    </tr>
                                    @if ((float) $invoice->additional_fee > 0)
                                        <tr>
                                            <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#18181b;">
                                                Additional Fee</td>
                                            <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#18181b;text-align:right;">
                                                {!! $fmt((float) $invoice->additional_fee) !!}</td>
                                        </tr>
                                        @if (!empty($additionalFeeNote))
                                            <tr>
                                                <td colspan="2" style="padding:0 16px 8px;font-size:11.5px;color:#a1a1aa;">
                                                    ↳ {{ $additionalFeeNote }}</td>
                                            </tr>
                                        @endif
                                    @endif
                                    @if ((float) $invoice->discount > 0)
                                        <tr>
                                            <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#18181b;">
                                                Discount</td>
                                            <td style="padding:11px 16px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#15803d;text-align:right;">
                                                -{!! $fmt((float) $invoice->discount) !!}</td>
                                        </tr>
                                    @endif
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
                                                    Total (incl. VAT)</td>
                                                <td align="right"
                                                    style="color:#ffffff;font-size:22px;font-weight:bold;">
                                                    {!! $fmt($groupTotal ?? (float) $invoice->total) !!}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 24px;"></td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>

</html>
