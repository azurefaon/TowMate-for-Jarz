<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Activity Log</title>
    <style>
        body {
            margin: 0;
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            background: #ffffff;
            color: #111111;
            font-size: 12px;
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
            width: 64px;
            height: 64px;
            object-fit: contain;
        }

        .brand-title {
            text-align: center;
        }

        .brand-title .top {
            margin: 0;
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 1px;
        }

        .brand-title .bottom {
            margin: 4px 0 0;
            font-size: 16px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #b8860b;
        }

        .banner-table {
            margin-top: 16px;
        }

        .banner-title {
            background: #facc15;
            color: #111111;
            font-size: 15px;
            font-weight: 800;
            padding: 8px 12px;
            text-transform: uppercase;
        }

        .banner-meta {
            width: 320px;
            background: #111111;
            color: #ffffff;
            font-size: 10px;
            padding: 8px 12px;
            text-align: right;
            line-height: 1.6;
        }

        .truncated-note {
            margin-top: 10px;
            padding: 8px 10px;
            background: #fef3c7;
            border: 1px solid #111111;
            font-size: 11px;
            font-weight: 700;
        }

        table.activity-table {
            margin-top: 14px;
        }

        table.activity-table thead {
            display: table-header-group;
        }

        table.activity-table thead th {
            background: #111111;
            color: #ffffff;
            font-size: 10px;
            text-transform: uppercase;
            padding: 6px 6px;
            text-align: left;
        }

        table.activity-table tbody tr {
            page-break-inside: avoid;
        }

        table.activity-table tbody td {
            font-size: 10.5px;
            padding: 5px 6px;
            border-bottom: 1px solid #d1d5db;
            vertical-align: top;
        }

        .category-badge {
            display: inline-block;
            padding: 1px 6px;
            border: 1px solid #111111;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .footer-table {
            table-layout: fixed;
            margin-top: 20px;
            border-top: 3px solid #facc15;
        }

        .footer-table td {
            width: 33.33%;
            padding-top: 8px;
            text-align: center;
            font-size: 9px;
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
                <td class="banner-title">Activity Log — {{ ucfirst($period) }}</td>
                <td class="banner-meta">
                    RANGE: {{ $start->format('M j, Y') }} &ndash; {{ $end->format('M j, Y') }}<br>
                    GENERATED: {{ $generatedAt->format('M j, Y g:i A') }}<br>
                    BY: {{ $generatedBy }}
                    @if ($categoryLabel)
                        <br>CATEGORY: {{ $categoryLabel }}
                    @endif
                </td>
            </tr>
        </table>

        @if ($truncated)
            <p class="truncated-note">
                Showing first {{ $rowCap }} of {{ $total }} matching entries — narrow your filters for a complete report.
            </p>
        @endif

        <table class="activity-table">
            <thead>
                <tr>
                    <th style="width:13%;">Date / Time</th>
                    <th style="width:15%;">Actor</th>
                    <th style="width:12%;">Category</th>
                    <th style="width:15%;">Record</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td>{{ $log->created_at?->format('M j, Y g:i A') }}</td>
                        <td>{{ $log->user->full_name ?? 'System' }}</td>
                        <td><span class="category-badge">{{ ucfirst(str_replace('_', ' ', $log->category ?? 'system')) }}</span></td>
                        <td>{{ $log->entity_type }}{{ $log->reference ? ' — ' . $log->reference : '' }}</td>
                        <td>{{ $log->description }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">No activity recorded for this range.</td>
                    </tr>
                @endforelse
            </tbody>
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
