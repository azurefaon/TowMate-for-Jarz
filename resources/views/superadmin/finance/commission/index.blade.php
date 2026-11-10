@extends('layouts.superadmin')

@section('title', 'Commission (Draft)')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $t = $draft['totals'];
    $openForm = old('form');
@endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerCommission">
        <header class="ob-header fin-header">
            <div>
                <h1>Commission (Draft)</h1>
                <p>Week of {{ $draft['week_start']->format('M j') }} – {{ $draft['week_end']->format('M j, Y') }} (Monday to Saturday). This page calculates; it does not approve or pay.</p>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        <div class="fin-flash fin-flash--warning" role="status" data-cm-status="{{ $draft['status'] }}">
            <i data-lucide="file-pen-line" aria-hidden="true"></i>
            <span><strong>Draft / For Client Review.</strong>
                Policy v{{ $draft['policy']['version'] ?? '—' }}:
                @foreach ($draft['policy']['rates'] ?? [] as $role => $rate)
                    {{ \App\Services\Finance\CommissionCalculator::ROLE_LABELS[$role] ?? $role }} {{ rtrim(rtrim($rate, '0'), '.') }}%{{ $loop->last ? '.' : ',' }}
                @endforeach
                Every percentage applies to the same sharing base: allocated verified collection − approved job expenses − approved deductions.
                The interpretation is still subject to client confirmation.
            </span>
        </div>

        <form method="GET" action="{{ route('superadmin.finance.commission.index') }}" class="oa-card oa-filter-card" role="search">
            <div class="oa-filters">
                <div class="oa-field">
                    <label for="cmWeek">Week (any day; Monday is used)</label>
                    <input id="cmWeek" type="date" name="week" class="oa-select" value="{{ $week->toDateString() }}">
                </div>
                <div class="oa-field">
                    <label for="cmUnit">Unit</label>
                    <select id="cmUnit" name="unit_id" class="oa-select">
                        <option value="">All units</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" @selected($unitId === $unit->id)>{{ $unit->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <span class="oa-field-label">&nbsp;</span>
                    <div style="display:flex; gap:12px;">
                        <button type="submit" class="oa-btn oa-btn--primary">Show</button>
                        <a class="oa-btn" href="{{ route('superadmin.finance.commission.index', ['week' => $week->subWeek()->toDateString(), 'unit_id' => $unitId]) }}">Previous week</a>
                    </div>
                </div>
            </div>
        </form>

        <div class="oa-kpis oa-kpis--six" style="--oa-kpi-count: 6;">
            <div class="oa-kpi"><span class="oa-kpi-label">Allocated collections</span><div class="oa-kpi-value" data-cm-total="collection">{{ $t['collection']->format() }}</div></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Approved expenses and deductions</span><div class="oa-kpi-value" data-cm-total="costs">{{ $t['expenses']->plus($t['deductions'])->format() }}</div></div>
            <div class="oa-kpi is-dark"><span class="oa-kpi-label">Sharing base</span><div class="oa-kpi-value" data-cm-total="base">{{ $t['base']->format() }}</div></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Company share</span><div class="oa-kpi-value" data-cm-total="company">{{ $t['company']->format() }}</div></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Crew shares</span><div class="oa-kpi-value" data-cm-total="crew">{{ $t['crew_allocated']->format() }}</div></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Unallocated (missing roles)</span><div class="oa-kpi-value" data-cm-total="unallocated">{{ $t['unallocated']->format() }}</div></div>
        </div>

        <section class="oa-card oa-card-body" aria-labelledby="cmBlockersTitle">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="cmBlockersTitle"><i data-lucide="shield-alert" aria-hidden="true"></i> Why this week cannot be approved yet</h2>
                <p class="oa-card-sub">{{ $draft['can_approve'] ? 'No blockers. Approval is handled in the next phase.' : count($draft['blockers']) . ' item(s) to resolve.' }}</p>
            </div></div>
            @if ($draft['blockers'])
                <ul class="fin-trail" id="cmBlockers">
                    @foreach ($draft['blockers'] as $b)
                        <li data-cm-blocker="{{ $b['code'] }}"><span class="fin-flag fin-flag--missing_evidence">{{ $b['message'] }}</span></li>
                    @endforeach
                </ul>
            @endif
            @foreach ($draft['notices'] as $notice)
                <p class="fin-help">{{ $notice }}</p>
            @endforeach
        </section>

        <section class="oa-card oa-card-body" aria-labelledby="cmLinesTitle">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="cmLinesTitle"><i data-lucide="calculator" aria-hidden="true"></i> Calculation by job and unit</h2>
                <p class="oa-card-sub">Shares are rounded to the centavo; any rounding remainder goes to Company so every line adds up to its base exactly.</p>
            </div></div>
            @forelse ($draft['lines'] as $line)
                <details class="fin-details fin-cm-line" data-cm-line="{{ $line['reference'] }}-{{ $line['unit_id'] }}">
                    <summary>
                        <span class="fin-ref">{{ $line['reference'] }}</span> · {{ $line['unit_name'] }} ·
                        base <strong>{{ $line['base']->format() }}</strong>
                        @if ($line['blockers'])<span class="fin-status fin-status--amber">{{ count($line['blockers']) }} blocker(s)</span>@endif
                    </summary>
                    <div class="fin-details-body">
                        <table class="oa-table fin-table">
                            <tbody>
                                <tr><td>Allocated verified collection</td><td class="is-num">{{ $line['collection']->format() }}</td></tr>
                                @foreach ($line['expenses'] as $e)
                                    <tr><td>− {{ $e['category'] }} ({{ $e['reference'] }})</td><td class="is-num">{{ $e['amount']->format() }}</td></tr>
                                @endforeach
                                @foreach ($line['deductions'] as $d)
                                    <tr><td>− {{ $d['type'] }} ({{ $d['reference'] }})</td><td class="is-num">{{ $d['amount']->format() }}</td></tr>
                                @endforeach
                                <tr><th scope="row">Sharing base</th><td class="is-num fin-strong">{{ $line['base']->format() }}</td></tr>
                                <tr><td>Company</td><td class="is-num">{{ $line['company']->format() }}</td></tr>
                                @foreach ($line['roles'] as $row)
                                    <tr>
                                        <td>{{ $row['label'] }} ({{ rtrim(rtrim($row['rate'], '0'), '.') }}%):
                                            @forelse ($row['recipients'] as $r)
                                                {{ $r['name'] }} {{ $r['amount']->format() }}{{ $r['role_confirmed'] ? '' : ' (role provisional)' }}{{ $loop->last ? '' : ',' }}
                                            @empty
                                                <em>nobody recorded, unallocated</em>
                                            @endforelse
                                        </td>
                                        <td class="is-num">{{ $row['share']->format() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @foreach ($line['blockers'] as $b)
                            <div class="fin-flag">{{ $b['message'] }}</div>
                        @endforeach
                    </div>
                </details>
            @empty
                <div class="oa-empty">No job in this week has an allocated verified collection yet.</div>
            @endforelse

            @foreach ($draft['blocked_jobs'] as $bj)
                <div class="fin-inline-warning">Job {{ $bj['job']['reference'] }} is not in the calculation: {{ collect($bj['blockers'])->pluck('message')->implode(' ') }}</div>
            @endforeach
        </section>

        @if ($t['by_recipient'])
            <section class="oa-card oa-card-body" aria-labelledby="cmPeopleTitle">
                <div class="oa-card-head"><div><h2 class="oa-card-title" id="cmPeopleTitle"><i data-lucide="users" aria-hidden="true"></i> Draft shares by person</h2></div></div>
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Person</th><th scope="col">Roles</th><th scope="col" class="is-num">Jobs</th><th scope="col" class="is-num">Draft share</th></tr></thead>
                        <tbody>
                            @foreach ($t['by_recipient'] as $r)
                                <tr><td>{{ $r['name'] }}</td><td>{{ $r['role_list'] }}</td><td class="is-num">{{ $r['jobs'] }}</td><td class="is-num fin-strong">{{ $r['amount']->format() }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="oa-card oa-card-body" aria-labelledby="cmDeductionsTitle">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="cmDeductionsTitle"><i data-lucide="minus-circle" aria-hidden="true"></i> Deductions</h2>
                <p class="oa-card-sub">Payment fees, refunds or damage charges that reduce a job's sharing base. They count only after you approve them. Never enter an expense here.</p>
            </div></div>
            @if ($deductions->isNotEmpty())
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Reference</th><th scope="col">Job / unit</th><th scope="col">Type</th><th scope="col" class="is-num">Amount</th><th scope="col">Status</th><th scope="col"><span class="fin-sr">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($deductions as $d)
                                <tr class="{{ $d->status === 'voided' ? 'fin-voided' : '' }}">
                                    <td><span class="fin-ref">{{ $d->reference_code }}</span><div class="is-dim">{{ $d->description }}</div></td>
                                    <td>{{ $d->booking?->booking_code }} · {{ $d->unit?->name }}</td>
                                    <td>{{ $d->typeLabel() }}</td>
                                    <td class="is-num fin-strong">{{ \App\Support\Money::of($d->amount)->format() }}</td>
                                    <td>{{ ['pending_review' => 'Awaiting review', 'approved' => 'Approved', 'voided' => 'Voided'][$d->status] }}</td>
                                    <td class="fin-actions">
                                        @if ($d->status === 'pending_review')
                                            <form method="POST" action="{{ route('superadmin.finance.commission.deductions.approve', $d->id) }}" data-fin-once>@csrf<button class="oa-btn oa-btn--sm oa-btn--primary" type="submit">Approve</button></form>
                                        @endif
                                        @if ($d->status !== 'voided')
                                            <form method="POST" action="{{ route('superadmin.finance.commission.deductions.void', $d->id) }}" class="fin-inline-form" data-fin-once>@csrf
                                                <input type="text" name="reason" required maxlength="500" placeholder="Reason" aria-label="Reason for voiding {{ $d->reference_code }}">
                                                <button class="oa-btn oa-btn--sm fin-btn-danger" type="submit">Void</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <details class="fin-correct" @if ($openForm === 'deduction') open @endif>
                <summary>Record a deduction</summary>
                <form method="POST" action="{{ route('superadmin.finance.commission.deductions.store') }}" class="fin-form" data-fin-once>
                    @csrf
                    <input type="hidden" name="form" value="deduction">
                    <input type="hidden" name="idempotency_key" value="{{ $deductionKey }}">
                    <div class="fin-grid">
                        <label class="fin-field"><span>Booking code</span><input type="text" name="booking_code" required maxlength="40" value="{{ $openForm === 'deduction' ? old('booking_code') : '' }}"></label>
                        <label class="fin-field"><span>Unit</span>
                            <select name="unit_id" class="oa-select" required>
                                @foreach ($units as $unit)<option value="{{ $unit->id }}" @selected((string) old('unit_id') === (string) $unit->id)>{{ $unit->name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="fin-field"><span>Type</span>
                            <select name="deduction_type" class="oa-select" required>
                                @foreach (\App\Models\Finance\CommissionDeduction::TYPES as $key => $label)<option value="{{ $key }}" @selected(old('deduction_type') === $key)>{{ $label }}</option>@endforeach
                            </select>
                        </label>
                        <label class="fin-field"><span>Amount (₱)</span><input type="text" name="amount" inputmode="decimal" required value="{{ $openForm === 'deduction' ? old('amount') : '' }}"></label>
                        <label class="fin-field"><span>Reference <small>(optional, prevents duplicates)</small></span><input type="text" name="external_reference" maxlength="100" value="{{ $openForm === 'deduction' ? old('external_reference') : '' }}"></label>
                        <label class="fin-field fin-field--wide"><span>Description</span><input type="text" name="description" required maxlength="2000" value="{{ $openForm === 'deduction' ? old('description') : '' }}"></label>
                    </div>
                    <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary oa-btn--sm">Record deduction</button></div>
                </form>
            </details>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/finance.js') }}?v={{ filemtime(public_path('superadmin/js/finance.js')) }}" defer></script>
@endpush
