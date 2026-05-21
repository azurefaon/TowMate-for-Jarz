<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Receipt – {{ $booking->booking_code }}</title>
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
        'gcash' => 'GCash', 'bank_transfer' => 'Bank Transfer',
        'cash'  => 'Cash',  'cheque' => 'Cheque', default => 'Cash',
    };
    $payRef       = $booking->paymongo_intent_id ?? ($booking->paymongo_link_id ?? '');
    $unitName     = $booking->unit->name ?? '—';
    $unitPlate    = $booking->unit->plate_number ?? '—';
    $truckType    = $booking->truckType->name ?? '—';
    $tlName       = $booking->unit->teamLeader->full_name ?? ($booking->unit->teamLeader->name ?? '—');
    $driverName   = $booking->unit->driver->full_name ?? ($booking->unit->driver->name ?? '—');
    $receiptNum   = $booking->receipt->receipt_number ?? '—';
    $fmt = fn(float $v) => '&#8369;' . number_format($v, 2);
@endphp

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;padding:32px 16px;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;">

        {{-- ── HEADER ── --}}
        <tr>
          <td style="background:#0f172a;padding:24px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <div style="font-size:22px;font-weight:700;color:#f59e0b;letter-spacing:2px;text-transform:uppercase;line-height:1;">TowMate</div>
                  <div style="font-size:11px;color:#94a3b8;letter-spacing:1px;text-transform:uppercase;margin-top:4px;">Jarz Towing Management System</div>
                </td>
                <td align="right">
                  <div style="font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:1px;">Booking Receipt</div>
                  <div style="font-size:15px;color:#f59e0b;font-weight:700;margin-top:4px;font-family:'Courier New',Courier,monospace;">{{ $booking->booking_code }}</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- ── TOTAL BAND ── --}}
        <tr>
          <td style="background:#f59e0b;padding:24px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <div style="font-size:11px;font-weight:700;color:#78350f;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Total Amount Collected</div>
                  <div style="font-size:36px;font-weight:700;color:#0f172a;line-height:1;">{{ $fmt($finalTotal) }}</div>
                </td>
                <td align="right" style="vertical-align:bottom;">
                  <div style="font-size:12px;color:#78350f;font-weight:600;">{{ now()->format('M d, Y') }}</div>
                  <div style="font-size:11px;color:#92400e;margin-top:3px;">Receipt #{{ $receiptNum }}</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- ── PRICE BREAKDOWN ── --}}
        <tr>
          <td style="background:#fffbeb;padding:0 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding:12px 0 8px;" colspan="2">
                  <div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;">Price Breakdown</div>
                </td>
              </tr>
              <tr>
                <td style="padding:5px 0;font-size:13px;color:#44403c;">Base Rate</td>
                <td align="right" style="padding:5px 0;font-size:13px;color:#0f172a;font-weight:600;">{!! $fmt($baseRate) !!}</td>
              </tr>
              <tr>
                <td style="padding:5px 0;font-size:13px;color:#44403c;">
                  Distance Fee
                  @if ($distanceKm > 0)
                    <span style="font-size:11px;color:#92400e;">({{ number_format($distanceKm, 1) }} km)</span>
                  @endif
                </td>
                <td align="right" style="padding:5px 0;font-size:13px;font-weight:600;">
                  @if ($distanceFee > 0)
                    <span style="color:#0f172a;">{!! $fmt($distanceFee) !!}</span>
                  @else
                    <span style="color:#16a34a;">Free</span>
                  @endif
                </td>
              </tr>
              @if ($vatAmount > 0)
              <tr>
                <td style="padding:5px 0;font-size:13px;color:#44403c;">VAT (12%)</td>
                <td align="right" style="padding:5px 0;font-size:13px;color:#0f172a;font-weight:600;">{!! $fmt($vatAmount) !!}</td>
              </tr>
              @endif
              <tr>
                <td colspan="2" style="padding:6px 0;"><div style="height:1px;background:#fde68a;"></div></td>
              </tr>
              <tr>
                <td style="padding:6px 0 16px;font-size:14px;font-weight:700;color:#0f172a;">Total</td>
                <td align="right" style="padding:6px 0 16px;font-size:14px;font-weight:700;color:#0f172a;">{!! $fmt($finalTotal) !!}</td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- ── DIVIDER ── --}}
        <tr><td style="height:8px;background:#f1f5f9;"></td></tr>

        {{-- ── CUSTOMER INFORMATION ── --}}
        <tr>
          <td style="padding:24px 32px 0;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#f59e0b;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid #f59e0b;">Customer Information</div>
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="50%" style="padding-bottom:14px;vertical-align:top;padding-right:12px;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Full Name</div>
                  <div style="font-size:14px;color:#0f172a;font-weight:600;">{{ $custName }}</div>
                </td>
                <td width="50%" style="padding-bottom:14px;vertical-align:top;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Customer Type</div>
                  <div style="font-size:14px;color:#0f172a;font-weight:600;">{{ $custType }}</div>
                </td>
              </tr>
              <tr>
                <td style="padding-bottom:14px;vertical-align:top;padding-right:12px;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Phone</div>
                  <div style="font-size:14px;color:#0f172a;font-weight:600;">{{ $custPhone }}</div>
                </td>
                <td style="padding-bottom:14px;vertical-align:top;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Email</div>
                  <div style="font-size:13px;color:#0f172a;font-weight:600;word-break:break-all;">{{ $custEmail }}</div>
                </td>
              </tr>
              <tr>
                <td colspan="2" style="padding-bottom:14px;vertical-align:top;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Pickup Location</div>
                  <div style="font-size:13px;color:#0f172a;line-height:1.55;">{{ $pickup }}</div>
                </td>
              </tr>
              <tr>
                <td colspan="2" style="padding-bottom:4px;vertical-align:top;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Drop-off Location</div>
                  <div style="font-size:13px;color:#0f172a;line-height:1.55;">{{ $dropoff }}</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- ── DIVIDER ── --}}
        <tr><td style="height:8px;background:#f1f5f9;"></td></tr>

        {{-- ── PAYMENT INFORMATION ── --}}
        <tr>
          <td style="padding:24px 32px 0;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#f59e0b;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid #f59e0b;">Payment Information</div>
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="50%" style="padding-bottom:14px;vertical-align:top;padding-right:12px;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Payment Method</div>
                  <div style="font-size:14px;color:#0f172a;font-weight:600;">{{ $payMethod }}</div>
                </td>
                <td width="50%" style="padding-bottom:14px;vertical-align:top;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Status</div>
                  <div style="font-size:14px;color:#16a34a;font-weight:700;">&#10003; Completed</div>
                </td>
              </tr>
              @if ($payRef)
              <tr>
                <td colspan="2" style="padding-bottom:4px;vertical-align:top;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Reference Number</div>
                  <div style="font-size:13px;color:#0f172a;font-family:'Courier New',Courier,monospace;">{{ $payRef }}</div>
                </td>
              </tr>
              @endif
            </table>
          </td>
        </tr>

        {{-- ── DIVIDER ── --}}
        <tr><td style="height:8px;background:#f1f5f9;"></td></tr>

        {{-- ── UNIT & TEAM DETAILS ── --}}
        <tr>
          <td style="padding:24px 32px 28px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#f59e0b;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid #f59e0b;">Unit &amp; Team Details</div>
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="50%" style="padding-bottom:14px;vertical-align:top;padding-right:12px;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Unit Name</div>
                  <div style="font-size:14px;color:#0f172a;font-weight:600;">{{ $unitName }}</div>
                </td>
                <td width="50%" style="padding-bottom:14px;vertical-align:top;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Plate Number</div>
                  <div style="font-size:14px;color:#0f172a;font-weight:700;font-family:'Courier New',Courier,monospace;">{{ $unitPlate }}</div>
                </td>
              </tr>
              <tr>
                <td style="padding-bottom:14px;vertical-align:top;padding-right:12px;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Truck Type</div>
                  <div style="font-size:14px;color:#0f172a;font-weight:600;">{{ $truckType }}</div>
                </td>
                <td style="padding-bottom:14px;vertical-align:top;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Team Leader</div>
                  <div style="font-size:14px;color:#0f172a;font-weight:600;">{{ $tlName }}</div>
                </td>
              </tr>
              @if ($driverName !== '—')
              <tr>
                <td colspan="2" style="padding-bottom:4px;vertical-align:top;">
                  <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;margin-bottom:4px;">Driver</div>
                  <div style="font-size:14px;color:#0f172a;font-weight:600;">{{ $driverName }}</div>
                </td>
              </tr>
              @endif
            </table>
          </td>
        </tr>

        {{-- ── FOOTER ── --}}
        <tr>
          <td style="background:#0f172a;padding:18px 32px;">
            <p style="margin:0;font-size:11px;color:#64748b;line-height:1.8;text-align:center;">
              Thank you for choosing Jarz Towing &bull; TowMate<br>
              Generated {{ now()->format('M d, Y \a\t h:i A') }}
              @if (!empty($receiptUrl))
                &bull; <a href="{{ $receiptUrl }}" style="color:#f59e0b;text-decoration:none;font-weight:600;">Download PDF Receipt</a>
              @endif
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
