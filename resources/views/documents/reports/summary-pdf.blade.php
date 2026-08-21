<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reports Summary</title>
    <style>
        body {
            margin: 0;
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            background: #ffffff;
            color: #111111;
            font-size: 13px;
        }

        .sheet {
            padding: 24px 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .brand-table td {
            vertical-align: middle;
        }

        .brand-logo-cell {
            width: 90px;
        }

        .brand-logo {
            width: 72px;
            height: 72px;
            object-fit: contain;
        }

        .brand-title {
            text-align: center;
        }

        .brand-title .top {
            margin: 0;
            font-size: 22px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #000000;
        }

        .brand-title .bottom {
            margin: 4px 0 0;
            font-size: 18px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #b8860b;
        }

        .banner-table {
            margin-top: 18px;
        }

        .banner-title {
            background: #facc15;
            color: #111111;
            font-size: 16px;
            font-weight: 800;
            padding: 8px 12px;
            text-transform: uppercase;
        }

        .banner-meta {
            width: 320px;
            background: #111111;
            color: #ffffff;
            font-size: 11px;
            padding: 8px 12px;
            text-align: right;
            line-height: 1.6;
        }

        .filters-line {
            margin-top: 8px;
            font-size: 11px;
            color: #374151;
        }

        h2.section-title {
            margin: 22px 0 8px;
            font-size: 14px;
            font-weight: 800;
            color: #111111;
            border-bottom: 2px solid #111111;
            padding-bottom: 4px;
            text-transform: uppercase;
        }

        table.report-table {
            margin-top: 6px;
        }

        table.report-table thead th {
            background: #ffffff;
            color: #111111;
            font-size: 11px;
            text-transform: uppercase;
            padding: 6px 8px;
            text-align: left;
            border-bottom: 2px solid #111111;
        }

        table.report-table thead th.num {
            text-align: right;
        }

        table.report-table tbody td {
            font-size: 12px;
            padding: 6px 8px;
            border-bottom: 1px solid #d1d5db;
        }

        table.report-table tbody td.num {
            text-align: right;
        }

        table.report-table tfoot td {
            font-size: 12px;
            font-weight: 800;
            padding: 8px;
            background: #facc15;
            color: #111111;
        }

        table.report-table tfoot td.num {
            text-align: right;
        }

        .kpi-table td {
            width: 20%;
            border: 1px solid #111111;
            padding: 8px 10px;
            text-align: center;
        }

        .kpi-table .kpi-value {
            display: block;
            font-size: 18px;
            font-weight: 800;
            color: #111111;
        }

        .kpi-table .kpi-label {
            display: block;
            font-size: 9px;
            text-transform: uppercase;
            color: #374151;
            margin-top: 2px;
        }

        .footer-table {
            table-layout: fixed;
            margin-top: 26px;
            border-top: 3px solid #facc15;
        }

        .footer-table td {
            width: 33.33%;
            padding-top: 8px;
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            color: #4b4b4b;
        }
    </style>
</head>

<body>
    @php
        $companyName = $settings['company_name'] ?? 'JARZ TOWING SERVICES';
        $companyWords = explode(' ', $companyName);
        $companyBottom = count($companyWords) > 1 ? array_pop($companyWords) : '';
        $companyTop = $companyBottom !== '' ? trim(implode(' ', $companyWords)) : $companyName;
        $peso = '₱';
    @endphp

    <div class="sheet">
        <table class="brand-table">
            <tr>
                <td class="brand-logo-cell">
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
                <td class="brand-logo-cell"></td>
            </tr>
        </table>

        <table class="banner-table">
            <tr>
                <td class="banner-title">{{ $sectionTitle }} — {{ ucfirst($period) }}</td>
                <td class="banner-meta">
                    RANGE: {{ $summary['start']->format('M j, Y') }} &ndash; {{ $summary['end']->format('M j, Y') }}<br>
                    GENERATED: {{ $generatedAt->format('M j, Y g:i A') }}<br>
                    BY: {{ $generatedBy }}
                </td>
            </tr>
        </table>

        @if ($truckTypeName || $filters['min_amount'] !== null || $filters['max_amount'] !== null)
            <p class="filters-line">
                Filters applied:
                @if ($truckTypeName) Vehicle type: {{ $truckTypeName }}. @endif
                @if ($filters['min_amount'] !== null) Min amount: {{ $peso }}{{ number_format($filters['min_amount'], 2) }}. @endif
                @if ($filters['max_amount'] !== null) Max amount: {{ $peso }}{{ number_format($filters['max_amount'], 2) }}. @endif
            </p>
        @endif

        @if ($section === 'all')
            <table class="kpi-table" style="margin-top:16px;">
                <tr>
                    <td>
                        <span class="kpi-value">{{ $summary['totalBookings'] }}</span>
                        <span class="kpi-label">Total Bookings</span>
                    </td>
                    <td>
                        <span class="kpi-value">{{ $peso }}{{ number_format($summary['totalRevenue'], 2) }}</span>
                        <span class="kpi-label">Total Revenue</span>
                    </td>
                    <td>
                        <span class="kpi-value">{{ $summary['completedCount'] }}</span>
                        <span class="kpi-label">Completed</span>
                    </td>
                    <td>
                        <span class="kpi-value">{{ $summary['pendingCount'] }}</span>
                        <span class="kpi-label">Pending</span>
                    </td>
                    <td>
                        <span class="kpi-value">{{ $summary['cancelledCount'] }}</span>
                        <span class="kpi-label">Cancelled</span>
                    </td>
                </tr>
            </table>
        @endif

        @if (in_array($section, ['all', 'booking']))
            <h2 class="section-title">Booking Report — Status Breakdown</h2>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th class="num">Bookings</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['pipeline'] as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="num">{{ $row['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total Bookings</td>
                        <td class="num">{{ $summary['totalBookings'] }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif

        @if (in_array($section, ['all', 'vehicle']))
            <h2 class="section-title">Vehicle Report — Units Used</h2>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th class="num">Trips</th>
                        <th class="num">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summary['vehicleReport'] as $row)
                        <tr>
                            <td>{{ $row['unit_name'] }}</td>
                            <td class="num">{{ $row['trips'] }}</td>
                            <td class="num">{{ $peso }}{{ number_format($row['revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">No vehicles dispatched in this range.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td>{{ $summary['totalVehiclesUsed'] }} vehicles used</td>
                        <td class="num">{{ $summary['totalTrips'] }} trips</td>
                        <td class="num"></td>
                    </tr>
                </tfoot>
            </table>
        @endif

        @if (in_array($section, ['all', 'financial']))
            <h2 class="section-title">Financial Report</h2>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Additional Fees Collected</td>
                        <td class="num">{{ $peso }}{{ number_format($summary['financial']['additionalFees'], 2) }}</td>
                    </tr>
                    <tr>
                        <td>VAT Collected</td>
                        <td class="num">{{ $peso }}{{ number_format($summary['financial']['vatCollected'], 2) }}</td>
                    </tr>
                    <tr>
                        <td>Cash Received</td>
                        <td class="num">{{ $peso }}{{ number_format($summary['financial']['cashReceived'], 2) }}</td>
                    </tr>
                    <tr>
                        <td>Average Revenue per Completed Booking</td>
                        <td class="num">{{ $peso }}{{ number_format($summary['financial']['averagePerBooking'], 2) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total Revenue</td>
                        <td class="num">{{ $peso }}{{ number_format($summary['financial']['totalRevenue'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif

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
