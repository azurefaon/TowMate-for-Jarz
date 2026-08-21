@extends('layouts.superadmin')

@section('title', 'Reports')

@push('styles')
    <style>
        .reports-page {
            color: #111111;
        }

        .reports-page h1 {
            margin: 0 0 4px;
            font-size: 1.6rem;
            color: #111111;
        }

        .reports-page .subtitle {
            margin: 0 0 16px;
            color: #111111;
        }

        .reports-tabs {
            display: flex;
            gap: 22px;
            margin-bottom: 18px;
            padding: 6px 18px 0;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 14px rgba(15, 23, 42, 0.06);
        }

        .reports-tabs a {
            padding: 12px 2px;
            border-bottom: 3px solid transparent;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.92rem;
            color: #6b7280;
            background: transparent;
        }

        .reports-tabs a:hover {
            color: #111111;
        }

        .reports-tabs a.active {
            color: #111111;
            font-weight: 700;
            border-bottom-color: #facc15;
        }

        .reports-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 20px;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 14px rgba(15, 23, 42, 0.06);
        }

        .reports-toolbar .filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }


        .pdf-btn {
            padding: 9px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 800;
            text-decoration: none;
            border: 1px solid #111111;
            background: #111111;
            color: #facc15;
        }

        .report-section {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #ffffff;
            margin-bottom: 22px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 14px rgba(15, 23, 42, 0.06);
        }

        .report-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
        }

        .report-section-head h2 {
            margin: 0;
            font-size: 1.05rem;
            color: #111111;
        }

        .section-pdf-btn {
            flex: none;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 800;
            text-decoration: none;
            background: #111111;
            color: #facc15;
        }

        .report-section table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-section thead th {
            text-align: left;
            padding: 10px 14px;
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #111111;
            background: #ffffff;
            border-bottom: 2px solid #111111;
        }

        .report-section thead th.num,
        .report-section tbody td.num,
        .report-section tfoot td.num {
            text-align: right;
        }

        .report-section tbody td {
            padding: 10px 14px;
            font-size: 0.92rem;
            color: #111111;
            border-bottom: 1px solid #e5e7eb;
        }

        .report-section tbody td a {
            color: #111111;
            text-decoration: underline;
            text-decoration-color: #facc15;
            text-decoration-thickness: 2px;
            font-weight: 700;
        }

        .report-section tfoot td {
            padding: 12px 14px;
            font-weight: 800;
            background: #facc15;
            color: #111111;
        }

        .report-empty {
            padding: 18px;
            text-align: center;
            font-weight: 600;
            color: #111111;
        }
    </style>
@endpush

@section('content')
    <div class="reports-page">

        <h1>Reports</h1>
        <p class="subtitle">Booking, vehicle, and financial statistics for the selected period.</p>

        <div class="reports-tabs">
            <a href="{{ route('superadmin.reports.index', request()->except('page')) }}" class="active">Summary</a>
            <a href="{{ route('superadmin.reports.activity') }}">Activity Log</a>
        </div>

        <form class="reports-toolbar" method="GET" action="{{ route('superadmin.reports.index') }}">

            <div class="filter-group">
                <div class="date-range-picker" data-range-picker>
                    <input type="date" name="from" data-role="from" value="{{ $fromInput }}" form="reportsRangeForm"
                        onchange="this.form.submit()">
                    <input type="date" name="to" data-role="to" value="{{ $toInput }}" form="reportsRangeForm"
                        onchange="this.form.submit()">
                </div>

                <select name="truck_type_id" class="vehicle-type-select" data-custom form="reportsRangeForm"
                    onchange="document.getElementById('reportsRangeForm').submit()">
                    <option value="">All vehicle types</option>
                    @foreach ($truckTypes as $truckType)
                        <option value="{{ $truckType->id }}"
                            {{ (int) ($filters['truck_type_id'] ?? 0) === $truckType->id ? 'selected' : '' }}>
                            {{ $truckType->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </form>

        <form id="reportsRangeForm" method="GET" action="{{ route('superadmin.reports.index') }}"></form>

        @php
            $pdfBaseParams = array_filter(array_merge($filters, $period === 'custom' ? ['from' => $fromInput, 'to' => $toInput] : ['period' => $period]));
        @endphp

        <div class="report-section">
            <div class="report-section-head">
                <h2>Booking Report - Status Breakdown</h2>
                <a class="section-pdf-btn"
                    href="{{ route('superadmin.reports.export-pdf', array_merge($pdfBaseParams, ['section' => 'booking'])) }}">Download
                    PDF</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th class="num">Bookings</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pipeline as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="num">
                                @if (!empty($row['key']) && $row['count'] > 0)
                                    <a
                                        href="{{ route(
                                            'superadmin.reports.bookings',
                                            array_filter(
                                                array_merge($filters, [
                                                    'bucket' => $row['key'],
                                                    'period' => $period !== 'custom' ? $period : null,
                                                    'from' => $period === 'custom' ? $fromInput : null,
                                                    'to' => $period === 'custom' ? $toInput : null,
                                                ]),
                                            ),
                                        ) }}">
                                        {{ $row['count'] }}
                                    </a>
                                @else
                                    {{ $row['count'] }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total Bookings</td>
                        <td class="num">{{ $totalBookings }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="report-section">
            <div class="report-section-head">
                <h2>Vehicle Report - Units Used</h2>
                <a class="section-pdf-btn"
                    href="{{ route('superadmin.reports.export-pdf', array_merge($pdfBaseParams, ['section' => 'vehicle'])) }}">Download
                    PDF</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th class="num">Trips</th>
                        <th class="num">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vehicleReport as $row)
                        <tr>
                            <td>{{ $row['unit_name'] }}</td>
                            <td class="num">{{ $row['trips'] }}</td>
                            <td class="num">&#8369;{{ number_format($row['revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="report-empty">No vehicles dispatched in this range.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td>{{ $totalVehiclesUsed }} vehicles used</td>
                        <td class="num">{{ $totalTrips }} trips</td>
                        <td class="num"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="report-section">
            <div class="report-section-head">
                <h2>Financial Report</h2>
                <a class="section-pdf-btn"
                    href="{{ route('superadmin.reports.export-pdf', array_merge($pdfBaseParams, ['section' => 'financial'])) }}">Download
                    PDF</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Additional Fees Collected</td>
                        <td class="num">&#8369;{{ number_format($financial['additionalFees'], 2) }}</td>
                    </tr>
                    <tr>
                        <td>VAT Collected</td>
                        <td class="num">&#8369;{{ number_format($financial['vatCollected'], 2) }}</td>
                    </tr>
                    <tr>
                        <td>Cash Received</td>
                        <td class="num">&#8369;{{ number_format($financial['cashReceived'], 2) }}</td>
                    </tr>
                    <tr>
                        <td>Average Revenue per Completed Booking</td>
                        <td class="num">&#8369;{{ number_format($financial['averagePerBooking'], 2) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total Revenue</td>
                        <td class="num">&#8369;{{ number_format($financial['totalRevenue'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

    </div>
@endsection
