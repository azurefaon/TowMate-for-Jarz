@extends('layouts.superadmin')

@section('title', 'Commission Statements')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $badge = ['draft' => 'grey', 'for_review' => 'amber', 'approved' => 'green', 'voided' => 'red'];
@endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerStatements">
        <header class="ob-header fin-header">
            <div>
                <h1>Commission Statements</h1>
                <p>One statement per towing unit and Monday–Saturday week: Draft → For Review → Approved. Payouts are recorded in a later phase.</p>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        <section class="oa-card oa-card-body" aria-labelledby="stNewTitle">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="stNewTitle"><i data-lucide="file-plus" aria-hidden="true"></i> Prepare a statement</h2>
                <p class="oa-card-sub">Choose the week and unit, then select which completed jobs to include.</p>
            </div></div>
            <form method="GET" action="{{ route('superadmin.finance.statements.create') }}" class="fin-form">
                <div class="fin-grid fin-grid--compact">
                    <label class="fin-field"><span>Week (any day; Monday is used)</span><input type="date" name="week" required value="{{ $defaultWeek->toDateString() }}"></label>
                    <label class="fin-field"><span>Towing unit</span>
                        <select name="unit_id" class="oa-select" required>
                            @foreach ($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary oa-btn--sm">Select jobs</button></div>
            </form>
        </section>

        <form method="GET" action="{{ route('superadmin.finance.statements.index') }}" class="oa-card oa-filter-card" role="search">
            <div class="oa-filters">
                <div class="oa-field">
                    <label for="stStatus">Status</label>
                    <select id="stStatus" name="status" class="oa-select">
                        <option value="">All statuses</option>
                        @foreach (\App\Models\Finance\CommissionStatement::STATUS_LABELS as $key => $label)
                            <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="stUnit">Unit</label>
                    <select id="stUnit" name="unit_id" class="oa-select">
                        <option value="">All units</option>
                        @foreach ($units as $unit)<option value="{{ $unit->id }}" @selected($unitId === $unit->id)>{{ $unit->name }}</option>@endforeach
                    </select>
                </div>
                <div class="oa-field"><span class="oa-field-label">&nbsp;</span><button type="submit" class="oa-btn oa-btn--primary">Filter</button></div>
            </div>
        </form>

        <section class="oa-card oa-card-body" aria-labelledby="stListTitle">
            <div class="oa-card-head"><div><h2 class="oa-card-title" id="stListTitle"><i data-lucide="files" aria-hidden="true"></i> Statements</h2></div></div>
            @if ($statements->isEmpty())
                <div class="oa-empty">No commission statements yet.</div>
            @else
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Reference</th><th scope="col">Week</th><th scope="col">Unit</th><th scope="col" class="is-num">Jobs</th><th scope="col" class="is-num">Sharing base</th><th scope="col" class="is-num">Crew</th><th scope="col">Status</th><th scope="col"><span class="fin-sr">Open</span></th></tr></thead>
                        <tbody>
                            @foreach ($statements as $s)
                                <tr class="{{ $s->status === 'voided' ? 'fin-voided' : '' }}" data-statement-row="{{ $s->id }}">
                                    <td><span class="fin-ref">{{ $s->reference_code }}</span><div class="is-dim">Revision {{ $s->revision }} · policy v{{ $s->policy?->version }}</div></td>
                                    <td>{{ $s->period_start->format('M j') }} – {{ $s->period_end->format('M j, Y') }}</td>
                                    <td>{{ $s->unit?->name ?? '—' }}</td>
                                    <td class="is-num">{{ $s->job_count }}</td>
                                    <td class="is-num fin-strong">{{ \App\Support\Money::of($s->total_base)->format() }}</td>
                                    <td class="is-num">{{ \App\Support\Money::of($s->personnel_amount)->format() }}</td>
                                    <td><span class="fin-status fin-status--{{ $badge[$s->status] ?? 'grey' }}">{{ $s->statusLabel() }}</span></td>
                                    <td><a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.statements.show', $s->id) }}">Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="fin-pager">{{ $statements->links() }}</div>
            @endif
        </section>
    </div>
@endsection
