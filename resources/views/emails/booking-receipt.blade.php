<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Completion Record – {{ $booking->job_code }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
@php
    $baseRate     = (float) ($booking->base_rate ?? 0);
    $distanceKm   = (float) ($booking->distance_km ?? 0);
    $distanceFee  = (float) ($booking->distance_fee_amount ?? 0);
    $vatAmount    = (float) ($booking->vat_amount ?? 0);
    $finalTotal   = (float) ($booking->final_total ?? 0);
    $custName     = $booking->customer->full_name ?? ($booking->customer->name ?? 'Customer');
    $custPhone    = $booking->customer->phone ?? '—';
    $custEmail    = $booking->customer->email ?? '—';
    $custType     = ucfirst($booking->customer_type ?? ($booking->customer->customer_type ?? 'regular'));
    $pickup       = $booking->pickup_address ?? '—';
    $dropoff      = $booking->dropoff_address ?? '—';
    $payMethod    = match ($booking->payment_method ?? '') {
        'gcash' => 'GCash', 'bank' => 'Bank Transfer',
        'cash'  => 'Cash',  'cheque' => 'Cheque', default => 'Cash',
    };
    $payRef       = $booking->paymongo_intent_id ?? ($booking->paymongo_link_id ?? '');
    $unitName     = $booking->unit->name ?? '—';
    $unitPlate    = $booking->unit->plate_number ?? '—';
    $truckType    = $booking->truckType->name ?? '—';
    $tlName       = $booking->unit->teamLeader->full_name ?? ($booking->unit->teamLeader->name ?? '—');
    $driverName   = $booking->unit->driver->full_name ?? ($booking->unit->driver->name ?? '—');
    $receiptNum   = $booking->receipt->receipt_number ?? '—';
    $fmt = fn(float $v) => '₱' . number_format($v, 2);
@endphp

<div style="max-width:640px;margin:24px auto;background:#fff;border:1px solid #e2e8f0;overflow:hidden;">

    {{-- ── HEADER ── --}}
    <div style="background:#fff;padding:24px 28px 16px;border-bottom:1px solid #f1f5f9;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td width="80" style="vertical-align:middle;">
                    <img src="{{ asset('customer/image/accridetedlogo.png') }}" alt="MMDA Accredited"
                        width="72" height="72" style="object-fit:contain;display:block;">
                </td>
                <td style="text-align:center;vertical-align:middle;">
                    <div style="font-size:1.4rem;font-weight:700;color:#0f172a;letter-spacing:.06em;text-transform:uppercase;line-height:1.1;">TowMate</div>
                    <div style="font-size:.6rem;color:#000;letter-spacing:.14em;text-transform:uppercase;margin-top:3px;">Towing Management System</div>
                </td>
                <td width="80" style="text-align:right;vertical-align:middle;">
                    <img src="{{ asset('customer/image/TowingLogo.png') }}" alt="Jarz Towing"
                        width="72" height="72" style="object-fit:contain;display:block;margin-left:auto;">
                </td>
            </tr>
        </table>
        <div style="margin-top:16px;border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;padding:10px 0;text-align:center;">
            <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.14em;color:#b45309;">Job Completion Record</div>
            <div style="font-size:.8rem;color:#475569;font-family:monospace;letter-spacing:.06em;margin-top:3px;">#{{ $booking->job_code }}</div>
        </div>
    </div>

    {{-- ── PRICE SUMMARY BAND ── --}}
    <div style="background:linear-gradient(90deg,#fef9c3 0%,#fef3c7 100%);padding:16px 28px;border-bottom:1px solid #fde68a;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td style="vertical-align:top;">
                    <div style="font-size:.58rem;text-transform:uppercase;letter-spacing:.1em;color:#92400e;margin-bottom:4px;">Total Amount Collected</div>
                    <div style="font-size:2rem;font-weight:700;color:#0f172a;letter-spacing:-.02em;line-height:1;">{{ $fmt($finalTotal) }}</div>
                </td>
                <td style="vertical-align:top;text-align:right;">
                    <table cellpadding="0" cellspacing="0" border="0" style="margin-left:auto;">
                        <tr>
                            <td style="font-size:.73rem;color:#78716c;padding:2px 20px 2px 0;white-space:nowrap;">Base Rate</td>
                            <td style="font-size:.73rem;color:#1c1917;padding:2px 0;text-align:right;">{{ $fmt($baseRate) }}</td>
                        </tr>
                        <tr>
                            <td style="font-size:.73rem;color:#78716c;padding:2px 20px 2px 0;white-space:nowrap;">Distance Fee</td>
                            <td style="font-size:.73rem;color:#1c1917;padding:2px 0;text-align:right;">{{ $fmt($distanceFee) }}{{ $distanceKm > 0 ? ' (' . number_format($distanceKm, 1) . ' km)' : '' }}</td>
                        </tr>
                        @if ($vatAmount > 0)
                        <tr>
                            <td style="font-size:.73rem;color:#78716c;padding:2px 20px 2px 0;white-space:nowrap;">VAT (12%)</td>
                            <td style="font-size:.73rem;color:#1c1917;padding:2px 0;text-align:right;">{{ $fmt($vatAmount) }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td colspan="2" style="padding:4px 0;">
                                <div style="height:1px;background:#fde68a;"></div>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:.73rem;font-weight:700;color:#0f172a;padding:2px 20px 2px 0;white-space:nowrap;">Final Total</td>
                            <td style="font-size:.73rem;font-weight:700;color:#0f172a;padding:2px 0;text-align:right;">{{ $fmt($finalTotal) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div style="padding:20px 28px;background:#fafafa;display:flex;flex-direction:column;gap:18px;">

        {{-- ── CUSTOMER INFORMATION ── --}}
        <div>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:8px;">
                <tr>
                    <td><div style="height:1px;background:#e2e8f0;"></div></td>
                    <td style="padding:0 10px;white-space:nowrap;font-size:.6rem;text-transform:uppercase;letter-spacing:.12em;color:#000;">Customer Information</td>
                    <td><div style="height:1px;background:#e2e8f0;"></div></td>
                </tr>
            </table>
            <table width="100%" cellpadding="0" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#e2e8f0;background:#fff;">
                <tr>
                    <td width="50%" style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Full Name</div>
                        <div style="font-size:.85rem;color:#0f172a;font-weight:600;">{{ $custName }}</div>
                    </td>
                    <td width="50%" style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Customer Type</div>
                        <div style="font-size:.85rem;color:#0f172a;font-weight:600;">{{ $custType }}</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Phone</div>
                        <div style="font-size:.85rem;color:#0f172a;font-weight:600;">{{ $custPhone }}</div>
                    </td>
                    <td style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Email</div>
                        <div style="font-size:.8rem;color:#0f172a;font-weight:600;word-break:break-all;">{{ $custEmail }}</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Pickup</div>
                        <div style="font-size:.8rem;color:#0f172a;line-height:1.45;font-weight:500;">{{ $pickup }}</div>
                    </td>
                    <td style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Drop-off</div>
                        <div style="font-size:.8rem;color:#0f172a;line-height:1.45;font-weight:500;">{{ $dropoff }}</div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- ── PAYMENT INFORMATION ── --}}
        <div>
            <div style="font-size:.6rem;text-transform:uppercase;letter-spacing:.12em;color:#000;margin-bottom:8px;">Payment Information</div>
            <table width="100%" cellpadding="0" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#e2e8f0;background:#fff;">
                <tr>
                    <td width="33%" style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Mode</div>
                        <div style="font-size:.88rem;color:#0f172a;font-weight:600;">{{ $payMethod }}</div>
                    </td>
                    <td width="33%" style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Status</div>
                        <div style="font-size:.88rem;color:#0f172a;font-weight:600;">Completed</div>
                    </td>
                    <td width="34%" style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Reference #</div>
                        <div style="font-size:.78rem;color:#0f172a;font-weight:500;word-break:break-all;">{{ $payRef ?: 'N/A' }}</div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- ── UNIT & TEAM DETAILS ── --}}
        <div>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:8px;">
                <tr>
                    <td><div style="height:1px;background:#e2e8f0;"></div></td>
                    <td style="padding:0 10px;white-space:nowrap;font-size:.6rem;text-transform:uppercase;letter-spacing:.12em;color:#000;">Unit &amp; Team Details</td>
                    <td><div style="height:1px;background:#e2e8f0;"></div></td>
                </tr>
            </table>
            <table width="100%" cellpadding="0" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#e2e8f0;background:#fff;">
                <tr>
                    <td width="33%" style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Unit Name</div>
                        <div style="font-size:.85rem;color:#0f172a;font-weight:600;">{{ $unitName }}</div>
                    </td>
                    <td width="33%" style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Plate No.</div>
                        <div style="font-size:.85rem;color:#0f172a;font-weight:600;">{{ $unitPlate }}</div>
                    </td>
                    <td width="34%" style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Truck Type</div>
                        <div style="font-size:.85rem;color:#0f172a;font-weight:600;">{{ $truckType }}</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Team Leader</div>
                        <div style="font-size:.85rem;color:#0f172a;font-weight:600;">{{ $tlName }}</div>
                    </td>
                    <td colspan="2" style="padding:10px 14px;border:1px solid #f1f5f9;vertical-align:top;">
                        <div style="font-size:.58rem;text-transform:uppercase;color:#64748b;margin-bottom:2px;">Driver</div>
                        <div style="font-size:.85rem;color:#0f172a;font-weight:600;">{{ $driverName }}</div>
                    </td>
                </tr>
            </table>
        </div>

    </div>

    {{-- ── FOOTER ── --}}
    <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 28px;">
        <p style="margin:0;font-size:.65rem;color:#475569;line-height:1.6;">
            Generated by TowMate &middot; {{ now()->format('M d, Y') }}<br>
            Receipt #: {{ $receiptNum }}
            @if (!empty($receiptUrl))
                &middot; <a href="{{ $receiptUrl }}" style="color:#b45309;text-decoration:none;" target="_blank">Download PDF</a>
            @endif
        </p>
    </div>

</div>
</body>
</html>
