@extends('layouts.superadmin')

@section('title', 'Finance Reports')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerFinanceReports">
        <header class="ob-header fin-header">
            <div>
                <h1>Finance Reports</h1>
                <p>{{ $periodLabel }}. Same filters and totals on screen, in print / PDF and in Excel.</p>
            </div>
            <div class="fin-header-actions">
                <a class="oa-btn" href="{{ route('superadmin.finance.reports.print', $query) }}" target="_blank" rel="noopener" data-fr-export="print">Print / Save as PDF</a>
                <a class="oa-btn" href="{{ route('superadmin.finance.reports.pdf', $query) }}" data-fr-export="pdf">Download PDF</a>
                <a class="oa-btn oa-btn--primary" href="{{ route('superadmin.finance.reports.xlsx', $query) }}" data-fr-export="xlsx">Download Excel</a>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        <form method="GET" action="{{ route('superadmin.finance.reports.index') }}" class="oa-card oa-filter-card" role="search">
            <div class="oa-filters fin-filters">
                <div class="oa-field">
                    <span class="oa-field-label">Reporting period</span>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        @include('superadmin.analytics._range', ['label' => $periodLabel, 'from' => $from->toDateString(), 'to' => $to->toDateString()])
                        <a href="{{ route('superadmin.finance.reports.index') }}" class="oa-btn">This week</a>
                    </div>
                </div>
                <div class="oa-field">
                    <label for="frUnit">Towing unit</label>
                    <select id="frUnit" name="unit_id" class="oa-select">
                        <option value="">All units</option>
                        @foreach ($units as $unit)<option value="{{ $unit->id }}" @selected(($filters['unit_id'] ?? null) === $unit->id)>{{ $unit->name }}{{ $unit->archived_at ? ' (archived)' : '' }}</option>@endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="frPerson">Personnel (as recorded on jobs)</label>
                    <select id="frPerson" name="personnel" class="oa-select">
                        <option value="">All personnel</option>
                        @foreach ($people as $key => $name)<option value="{{ $key }}" @selected(($filters['personnel'] ?? null) === $key)>{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="frPay">Payment status</label>
                    <select id="frPay" name="payment_status" class="oa-select">
                        <option value="">All</option>
                        @foreach ($paymentStatuses as $key => $label)<option value="{{ $key }}" @selected(($filters['payment_status'] ?? null) === $key)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="frExp">Expense status</label>
                    <select id="frExp" name="expense_status" class="oa-select">
                        <option value="">All</option>
                        @foreach (\App\Services\Finance\FinanceReportService::EXPENSE_FILTERS as $key => $label)<option value="{{ $key }}" @selected(($filters['expense_status'] ?? null) === $key)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="frSt">Statements</label>
                    <select id="frSt" name="statement_status" class="oa-select">
                        @foreach (\App\Services\Finance\FinanceReportService::STATEMENT_FILTERS as $key => $label)<option value="{{ $key }}" @selected(($filters['statement_status'] ?? 'final') === $key)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="oa-field"><span class="oa-field-label">&nbsp;</span><button type="submit" class="oa-btn oa-btn--primary">Apply filters</button></div>
            </div>
        </form>

        <section class="oa-card oa-card-body fr-screen" aria-label="Finance report">
            @include('documents.partials.finance-report-body', ['r' => $r])
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/analytics.js') }}?v={{ filemtime(public_path('superadmin/js/analytics.js')) }}" defer></script>
@endpush
