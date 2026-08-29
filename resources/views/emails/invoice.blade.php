<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice – {{ $invoice->invoice_number }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
@php
    $booking      = $invoice->booking;
    $custName     = $booking->customer->full_name ?? ($booking->customer->name ?? 'Customer');
    $custPhone    = $booking->customer->phone ?? '—';
    $custEmail    = $booking->customer->email ?? '—';
    $pickup       = $booking->pickup_address ?? '—';
    $dropoff      = $booking->dropoff_address ?? '—';
    $truckType    = $booking->truckType->name ?? '—';
    $fmt = fn(float $v) => '&#8369;' . number_format($v, 2);
@endphp

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;padding:20px 12px;">
  <tr>
    <td align="center">
      <table width="680" cellpadding="0" cellspacing="0" border="0" style="max-width:680px;width:100%;background:#ffffff;border:1px solid #d1d5db;">

        {{-- ── HEADER ── --}}
        <tr>
          <td style="padding:16px 20px 12px;border-bottom:4px solid #f59e0b;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="80" style="vertical-align:middle;">
                  <img src="{{ asset('customer/image/TowingLogo.png') }}"
                       alt="Jarz Towing" width="72" height="72"
                       style="display:block;border:0;outline:none;">
                </td>
                <td style="text-align:center;vertical-align:middle;padding:0 10px;">
                  <div style="font-size:26px;font-weight:900;color:#0f172a;letter-spacing:2px;line-height:1;text-transform:uppercase;">JARZ TOWING</div>
                  <div style="font-size:22px;font-weight:900;color:#f59e0b;letter-spacing:2px;line-height:1.1;text-transform:uppercase;">SERVICES</div>
                </td>
                <td width="80" style="vertical-align:middle;text-align:right;">
                  <img src="{{ asset('customer/image/accridetedlogo.png') }}"
                       alt="MMDA Accredited" width="72" height="72"
                       style="display:block;margin-left:auto;border:0;outline:none;">
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- ── TITLE BAND ── --}}
        <tr>
          <td style="padding:0;margin:0;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="65%" style="background:#f59e0b;padding:10px 20px;vertical-align:middle;">
                  <div style="font-size:13px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:1px;">Service Invoice</div>
                </td>
                <td style="background:#0f172a;padding:10px 20px;vertical-align:middle;text-align:right;">
                  <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;">
                    DATE: {{ now()->format('F d, Y') }}
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- ── 3-COLUMN ── --}}
        <tr>
          <td style="padding:0;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
              <tr>
                <td width="33%" style="padding:8px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-top:none;">
                  <div style="font-size:10px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:1px;">Billed To</div>
                </td>
                <td width="33%" style="padding:8px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-left:none;">
                  <div style="font-size:10px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:1px;">Pick-up Location</div>
                </td>
                <td width="34%" style="padding:8px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-left:none;">
                  <div style="font-size:10px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:1px;">Drop-off Location</div>
                </td>
              </tr>
              <tr>
                <td style="padding:12px 14px;border:1px solid #e2e8f0;border-top:none;vertical-align:top;">
                  <div style="font-size:13px;color:#0f172a;font-weight:700;line-height:1.4;">{{ $custName }}</div>
                  <div style="font-size:11px;color:#475569;margin-top:3px;">{{ $custPhone }}</div>
                  <div style="font-size:11px;color:#475569;word-break:break-all;">{{ $custEmail }}</div>
                </td>
                <td style="padding:12px 14px;border:1px solid #e2e8f0;border-top:none;border-left:none;vertical-align:top;">
                  <div style="font-size:11px;color:#0f172a;line-height:1.55;">{{ $pickup }}</div>
                </td>
                <td style="padding:12px 14px;border:1px solid #e2e8f0;border-top:none;border-left:none;vertical-align:top;">
                  <div style="font-size:11px;color:#0f172a;line-height:1.55;">{{ $dropoff }}</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- ── REFERENCE ── --}}
        <tr>
          <td style="padding:8px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:11px;color:#64748b;">Job Order #:&nbsp;</span>
            <span style="font-size:12px;font-weight:700;color:#0f172a;font-family:'Courier New',Courier,monospace;">{{ $booking->job_code ?? $booking->booking_code }}</span>
            &nbsp;&nbsp;&nbsp;
            <span style="font-size:11px;color:#64748b;">Invoice #:&nbsp;</span>
            <span style="font-size:12px;font-weight:700;color:#0f172a;font-family:'Courier New',Courier,monospace;">{{ $invoice->invoice_number }}</span>
            @if ($invoice->previousInvoice)
              <div style="font-size:10px;color:#94a3b8;margin-top:4px;">Replaces {{ $invoice->previousInvoice->invoice_number }} (voided)</div>
            @endif
          </td>
        </tr>

        {{-- ── BREAKDOWN ── --}}
        <tr>
          <td style="padding:0;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
              <tr>
                <td style="background:#0f172a;padding:10px 16px;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:1px;" width="55%">Type of Vehicle / Service</td>
                <td style="background:#0f172a;padding:10px 16px;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:1px;text-align:right;" width="45%">Amount</td>
              </tr>
              <tr>
                <td style="padding:11px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;">{{ $truckType }}</td>
                <td style="padding:11px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;text-align:right;">{!! $fmt((float) $invoice->subtotal) !!}</td>
              </tr>
              @if ((float) $invoice->additional_fee > 0)
              <tr>
                <td style="padding:11px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;">Additional Fee</td>
                <td style="padding:11px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;text-align:right;">{!! $fmt((float) $invoice->additional_fee) !!}</td>
              </tr>
              @endif
              @if ((float) $invoice->discount > 0)
              <tr>
                <td style="padding:11px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;">Discount</td>
                <td style="padding:11px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#16a34a;text-align:right;">-{!! $fmt((float) $invoice->discount) !!}</td>
              </tr>
              @endif
              <tr>
                <td style="padding:12px 16px;background:#f8fafc;border-top:2px solid #0f172a;font-size:13px;font-weight:700;color:#0f172a;text-align:right;text-transform:uppercase;letter-spacing:1px;">
                  TOTAL
                </td>
                <td style="padding:12px 16px;background:#f8fafc;border-top:2px solid #0f172a;font-size:16px;font-weight:700;color:#0f172a;text-align:right;">{!! $fmt((float) $invoice->total) !!}</td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- ── INSTRUCTIONS ── --}}
        <tr>
          <td style="padding:14px 20px;">
            <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
              Please settle this amount with your team leader, or through the payment link sent for this booking.
            </p>
          </td>
        </tr>

        {{-- ── FOOTER ── --}}
        <tr>
          <td style="background:#0f172a;padding:14px 20px;border-top:3px solid #f59e0b;">
            <p style="margin:0;font-size:11px;color:#94a3b8;text-align:center;line-height:1.8;">
              +63 933 022 3679
              &nbsp;&bull;&nbsp;
              #3A 1st St. Carreon Village, San Bartolome, Q.C.
              &nbsp;&bull;&nbsp;
              jarztowingservices@gmail.com
            </p>
            <p style="margin:6px 0 0;font-size:10px;color:#475569;text-align:center;">
              Generated {{ now()->format('M d, Y \a\t h:i A') }}
              @if (!empty($invoiceUrl))
                &nbsp;&bull;&nbsp;<a href="{{ $invoiceUrl }}" style="color:#f59e0b;text-decoration:none;">Download PDF</a>
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
