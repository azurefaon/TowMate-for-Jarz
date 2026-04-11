<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isFinal ? 'Final Quotation' : 'Tow Service Quotation' }}</title>
    <style>
        body {
            margin: 0;
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            background: #e5e7eb;
            color: #111111;
        }

        .sheet {
            max-width: 920px;
            min-height: 1180px;
            margin: 8px auto;
            background: #efefef;
            border: 1px solid #cfcfcf;
            padding: 28px 34px 22px;
            box-sizing: border-box;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .brand-table td {
            vertical-align: top;
        }

        .brand-logo-cell {
            width: 110px;
        }

        .brand-logo-left {
            text-align: left;
        }

        .brand-logo-right {
            text-align: right;
        }

        .brand-logo {
            width: 88px;
            height: 88px;
            object-fit: contain;
        }

        .brand-title {
            text-align: center;
            padding-top: 8px;
        }

        .brand-title .top {
            margin: 0;
            font-size: 32px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #000000;
        }

        .brand-title .bottom {
            margin: 10px 0 0;
            font-size: 30px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #f4bc09;
        }

        .banner-table {
            margin-top: 24px;
        }

        .banner-title {
            background: #f4bc09;
            color: #ffffff;
            font-size: 18px;
            font-weight: 700;
            padding: 7px 10px;
            text-transform: uppercase;
        }

        .banner-meta {
            width: 300px;
            background: #000000;
            color: #ffffff;
            font-size: 13px;
            padding: 8px 12px;
            text-align: right;
        }

        .banner-status {
            display: block;
            margin-top: 4px;
            font-weight: 700;
            letter-spacing: 0.6px;
        }

        .meta-table {
            margin-top: 16px;
            table-layout: fixed;
            border-bottom: 1px solid #8ca0ba;
        }

        .meta-table td {
            width: 33.33%;
            text-align: center;
            padding: 10px 8px 14px;
            vertical-align: top;
        }

        .meta-label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            color: #57708d;
            text-transform: uppercase;
        }

        .customer-details {
            margin-top: 20px;
            min-height: 100px;
            line-height: 1.7;
            font-size: 15px;
        }

        .customer-details strong {
            display: inline-block;
            min-width: 140px;
        }

        .item-table {
            margin-top: 14px;
        }

        .item-table thead th {
            background: #000000;
            color: #ffffff;
            text-transform: uppercase;
            font-size: 13px;
            padding: 7px 10px;
            text-align: center;
        }

        .item-table tbody td {
            padding: 12px 10px;
            font-size: 14px;
            text-align: center;
            border-bottom: 1px solid #1f2937;
        }

        .spacer-row td {
            padding: 22px 10px;
            border-bottom: 1px solid #1f2937;
        }

        .total-wrap {
            width: 270px;
            margin-left: auto;
            margin-top: 8px;
            border-top: 1px solid #c9c9c9;
        }

        .total-wrap td {
            padding: 8px 0;
            font-size: 14px;
        }

        .total-label {
            color: #57708d;
            text-transform: uppercase;
            text-align: center;
        }

        .total-amount {
            text-align: right;
            font-weight: 700;
        }

        .payment-signature {
            margin-top: 26px;
        }

        .payment-cell {
            width: 63%;
            vertical-align: bottom;
            font-size: 13px;
            line-height: 1.9;
            color: #555555;
        }

        .payment-cell strong {
            color: #4b4b4b;
        }

        .signature-cell {
            width: 37%;
            vertical-align: bottom;
            text-align: center;
            color: #525252;
            font-size: 13px;
        }

        .signature-img {
            max-width: 185px;
            max-height: 86px;
            object-fit: contain;
            display: block;
            margin: 0 auto 6px;
        }

        .signature-placeholder {
            height: 86px;
        }

        .signature-name {
            font-weight: 700;
            line-height: 1.2;
        }

        .signature-role {
            font-weight: 700;
            line-height: 1.2;
        }

        .footer-table {
            table-layout: fixed;
            margin-top: 14px;
            border-top: 3px solid #f4d10d;
        }

        .footer-table td {
            width: 33.33%;
            padding-top: 10px;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            color: #4b4b4b;
        }
    </style>
</head>

<body>
    @php
        $companyName = trim((string) ($settings['company_name'] ?? 'JARZ TOWING SERVICES'));
        $companyWords = preg_split('/\s+/', $companyName) ?: [];
        $companyBottom = count($companyWords) > 1 ? array_pop($companyWords) : '';
        $companyTop = $companyBottom !== '' ? trim(implode(' ', $companyWords)) : $companyName;
        $signatoryName = trim(
            (string) preg_replace('/\bfranchisee\b/i', '', $settings['gcash_name'] ?? $settings['bank_account_name']),
        );
        $peso = '₱';
    @endphp

    <div class="sheet">
        <table class="brand-table">
            <tr>
                <td class="brand-logo-cell brand-logo-left">
                    <img src="{{ $settings['logo_url'] }}" alt="Company logo" class="brand-logo">
                </td>
                <td>
                    <div class="brand-title">
                        <p class="top">{{ $companyTop }}</p>
                        @if ($companyBottom !== '')
                            <p class="bottom">{{ $companyBottom }}</p>
                        @endif
                    </div>
                </td>
                <td class="brand-logo-cell brand-logo-right">
                    <img src="{{ $settings['secondary_logo_url'] ?? $settings['logo_url'] }}" alt="Accreditation logo"
                        class="brand-logo">
                </td>
            </tr>
        </table>

        <table class="banner-table">
            <tr>
                <td class="banner-title">Tow Service Quotation</td>
                <td class="banner-meta">
                    DATE: {{ $generatedAt->format('Y') }}
                    @if ($isFinal)
                        <span class="banner-status">FINAL / CONFIRMED</span>
                    @endif
                </td>
            </tr>
        </table>

        <table class="meta-table">
            <tr>
                <td>
                    <span class="meta-label">Billed To</span>
                    <span>{{ $booking->customer->full_name ?? 'Customer' }}</span>
                </td>
                <td>
                    <span class="meta-label">Pick-up Location</span>
                    <span>{{ $booking->pickup_address ?: '.' }}</span>
                </td>
                <td>
                    <span class="meta-label">Drop-off Location</span>
                    <span>{{ $booking->dropoff_address ?: '.' }}</span>
                </td>
            </tr>
        </table>

        <div class="customer-details">
            <div><strong>Name:</strong> {{ $booking->customer->full_name ?? 'Customer' }}</div>
            <div><strong>Email:</strong> {{ $booking->customer->email ?? 'N/A' }}</div>
            <div><strong>Contact Number:</strong> {{ $booking->customer->phone ?? 'N/A' }}</div>
        </div>

        <table class="item-table">
            <thead>
                <tr>
                    <th>Type of Vehicle</th>
                    <th>Unit</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $booking->truckType->name ?? 'Towing Service' }}</td>
                    <td>1</td>
                    <td>{{ $peso }}{{ number_format((float) ($booking->final_total ?? 0), 2) }}</td>
                </tr>
                <tr class="spacer-row">
                    <td colspan="3"></td>
                </tr>
            </tbody>
        </table>

        <table class="total-wrap">
            <tr>
                <td class="total-label">Total</td>
                <td class="total-amount">
                    {{ $peso }}{{ number_format((float) ($booking->final_total ?? 0), 2) }}</td>
            </tr>
        </table>

        <table class="payment-signature">
            <tr>
                <td class="payment-cell">
                    <strong>PLEASE MAKE PAYMENT TO:</strong><br>
                    {{ $settings['bank_account_name'] }}<br>
                    {{ $settings['bank_name'] }} Acc. #: <strong>{{ $settings['bank_account_number'] }}</strong><br>
                    GCASH: <strong>{{ $settings['gcash_number'] }}</strong><br>
                    <span>{{ $settings['payment_terms'] }}</span>
                </td>
                <td class="signature-cell">
                    @if (!empty($settings['signature_url']))
                        <img src="{{ $settings['signature_url'] }}" alt="Uploaded signature image"
                            class="signature-img">
                    @else
                        <div class="signature-placeholder"></div>
                    @endif
                    <div class="signature-name">
                        {{ $signatoryName ?: $settings['bank_account_name'] ?? 'Authorized Signatory' }}</div>
                    <div class="signature-role">Franchisee</div>
                </td>
            </tr>
        </table>

        <table class="footer-table">
            <tr>
                <td>{{ $settings['company_phone'] }}</td>
                <td>{{ $settings['company_address'] }}</td>
                <td>{{ $settings['company_email'] }}</td>
            </tr>
        </table>
    </div>
</body>

</html>
