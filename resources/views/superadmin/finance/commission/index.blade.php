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
                <p>Week of {{ $draft['week_start']->format('M j') }} – {{ $draft['week_end']->format('M j, Y') }} (Monday to Saturday). This page previews the calculation; statements are prepared and approved under Commission Statements.</p>
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
            <div class="oa-kpi"><span class="oa-kpi-label">Crew draft, fully paid jobs</span><div class="oa-kpi-value" data-cm-total="crew">{{ $eligibility['eligible']->format() }}</div>
                @if ($eligibility['estimate']->isPositive())<span class="fin-kpi-sub" data-cm-total="estimate">+ {{ $eligibility['estimate']->format() }} estimate, not payable</span>@endif</div>
            <div class="oa-kpi"><span class="oa-kpi-label">Unallocated (missing roles)</span><div class="oa-kpi-value" data-cm-total="unallocated">{{ $t['unallocated']->format() }}</div></div>
        </div>

        <section class="oa-card oa-card-body" aria-labelledby="cmBlockersTitle">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="cmBlockersTitle"><i data-lucide="shield-alert" aria-hidden="true"></i> Why this week cannot be approved yet</h2>
                <p class="oa-card-sub">{{ $draft['can_approve'] ? 'No blockers. Prepare a statement under Commission Statements to submit and approve it.' : count($draft['blockers']) . ' item(s) to resolve.' }}</p>
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

        {{-- Unit → completed jobs → crew → approved expenses → draft commission per job → weekly total. Every amount is
             read from the calculator's lines (CommissionCalculator::draft); nothing is recalculated in the view. --}}
        @php $byUnit = collect($draft['lines'])->groupBy('unit_id'); @endphp
        <section class="oa-card oa-card-body" aria-labelledby="cmLinesTitle">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="cmLinesTitle"><i data-lucide="truck" aria-hidden="true"></i> Draft commission by unit</h2>
                <p class="oa-card-sub">Each job: who worked it, the approved expenses taken off, and the crew's draft commission. Only completed, fully paid jobs count toward the weekly total. Partially paid, unpaid and over-collected jobs stay listed with their status; their shares are an estimate and not payable. Shares are rounded to the centavo; any remainder goes to Company.</p>
            </div></div>
            @forelse ($byUnit as $uid => $unitLines)
                @php
                    $unitSummary = $eligibility['units'][$uid];
                    $unitExpenses = \App\Support\Money::sum(collect($unitLines)->pluck('expense_total'))->plus(\App\Support\Money::sum(collect($unitLines)->pluck('deduction_total')));
                    $unitPending = collect($unitLines)->flatMap(fn ($l) => collect($l['roles'])->flatMap(fn ($r) => $r['recipients']))->where('pending', true)->count();
                @endphp
                <div class="fin-unit-block" data-cm-unit="{{ $uid }}">
                    <div class="fin-unit-head">
                        <h3>{{ $unitLines->first()['unit_name'] }}</h3>
                        <span class="is-dim">{{ $unitLines->count() }} job(s) · {{ $unitSummary['eligible_jobs'] }} fully paid · {{ collect($unitLines)->sum('vehicles') }} vehicle(s)@if ($unitPending) · <span class="fin-status fin-status--amber">{{ $unitPending }} crew pending confirmation</span>@endif</span>
                    </div>
                    <div class="oa-table-wrap">
                        <table class="oa-table fin-table fin-cm-table">
                            <thead><tr>
                                <th scope="col">Completed job</th>
                                <th scope="col">Crew members</th>
                                <th scope="col">Approved expenses</th>
                                <th scope="col" class="is-num">Sharing base</th>
                                <th scope="col" class="is-num">Draft commission</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($unitLines as $line)
                                    @php
                                        $state = \App\Services\Finance\CommissionEligibility::forLine($line);
                                        $isEligible = $state === \App\Services\Finance\CommissionEligibility::ELIGIBLE;
                                    @endphp
                                    <tr data-cm-line="{{ $line['reference'] }}-{{ $line['unit_id'] }}" data-cm-line-state="{{ $state }}" @class(['fin-cm-estimate' => ! $isEligible])>
                                        <td>
                                            <span class="fin-ref">{{ $line['reference'] }}</span>
                                            <div class="is-dim">{{ $line['service_date']?->format('D, M j') }} · {{ $line['vehicles'] }} vehicle(s){{ $line['group_code'] ? ' · Group ' . $line['group_code'] : '' }}</div>
                                            <div class="is-dim">Collected {{ $line['collection']->format() }}</div>
                                            @if ($line['blockers'])
                                                <details class="fin-cm-why"><summary><span class="fin-status fin-status--red">{{ count($line['blockers']) }} blocker(s)</span></summary>
                                                    @foreach ($line['blockers'] as $b)<div class="fin-flag">{{ $b['message'] }}</div>@endforeach
                                                </details>
                                            @endif
                                        </td>
                                        <td>
                                            <ul class="fin-crew-list">
                                                @foreach ($line['roles'] as $row)
                                                    @forelse ($row['recipients'] as $r)
                                                        <li data-cm-crew="{{ $r['recipient_key'] }}">
                                                            <div class="fin-crew-who">
                                                                <span class="fin-strong">{{ $r['name'] }}</span>
                                                                @if ($r['pending'] ?? false)<span class="fin-status fin-status--amber" data-cm-crew-state="pending">Pending</span>
                                                                @else<span class="fin-status fin-status--green" data-cm-crew-state="confirmed">Confirmed</span>@endif
                                                                <div class="is-dim">{{ $row['label'] }} {{ rtrim(rtrim($row['rate'], '0'), '.') }}%{{ $r['role_confirmed'] ? '' : ' (role provisional)' }}</div>
                                                            </div>
                                                            <span class="fin-crew-amount" data-cm-crew-amount>{{ $r['amount']->format() }}</span>
                                                        </li>
                                                    @empty
                                                        @if ($row['share']->isPositive())
                                                            <li class="fin-crew-missing"><div class="fin-crew-who">No {{ $row['label'] }} recorded <span class="fin-status fin-status--amber">Unallocated</span></div><span class="fin-crew-amount">{{ $row['share']->format() }}</span></li>
                                                        @endif
                                                    @endforelse
                                                @endforeach
                                            </ul>
                                        </td>
                                        <td>
                                            @forelse ($line['expenses'] as $e)
                                                <div class="fin-cm-cost"><div>{{ $e['category'] }}<div class="is-dim">{{ $e['reference'] }}</div></div><span class="fin-strong">− {{ $e['amount']->format() }}</span></div>
                                            @empty
                                                @if (! $line['deductions'])<span class="is-dim">None</span>@endif
                                            @endforelse
                                            @foreach ($line['deductions'] as $d)
                                                <div class="fin-cm-cost"><div>{{ $d['type'] }}<div class="is-dim">{{ $d['reference'] }}</div></div><span class="fin-strong">− {{ $d['amount']->format() }}</span></div>
                                            @endforeach
                                        </td>
                                        <td class="is-num" data-cm-line-base>{{ $line['base']->format() }}<div class="is-dim">Company {{ $line['company']->format() }}</div></td>
                                        @if ($isEligible)
                                            <td class="is-num fin-strong" data-cm-line-crew>{{ $line['crew_allocated']->format() }}</td>
                                        @else
                                            <td class="is-num" data-cm-line-crew-estimate>
                                                <span class="fin-status fin-status--{{ \App\Services\Finance\CommissionEligibility::TONES[$state] }}">{{ \App\Services\Finance\CommissionEligibility::LABELS[$state] }}</span>
                                                <div class="is-dim">{{ $line['payment_label'] }}</div>
                                                <div class="is-dim">Estimate {{ $line['crew_allocated']->format() }}, not payable</div>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                                <tr class="fin-week-total" data-cm-unit-total="{{ $uid }}">
                                    <td colspan="2">Weekly total, fully paid jobs · {{ $unitLines->first()['unit_name'] }}</td>
                                    <td class="is-num">@if ($unitExpenses->isPositive())− {{ $unitExpenses->format() }}@else<span class="is-dim">None</span>@endif</td>
                                    <td class="is-num">{{ \App\Support\Money::sum(collect($unitLines)->pluck('base'))->format() }}</td>
                                    <td class="is-num" data-cm-unit-crew>{{ $unitSummary['eligible']->format() }}</td>
                                </tr>
                                @if ($unitSummary['estimate']->isPositive())
                                    <tr class="fin-cm-estimate-total" data-cm-unit-estimate="{{ $uid }}">
                                        <td colspan="4">Not payable yet: estimate on {{ $unitSummary['other_jobs'] }} job(s) not fully paid</td>
                                        <td class="is-num">{{ $unitSummary['estimate']->format() }}</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="oa-empty">No job in this week has an allocated verified collection yet.</div>
            @endforelse

            @foreach ($draft['blocked_jobs'] as $bj)
                @php $bjState = \App\Services\Finance\CommissionEligibility::state($bj['job']['status'], (bool) $bj['job']['service_completed']); @endphp
                <div class="fin-inline-warning" data-cm-blocked-job="{{ $bj['job']['reference'] }}" data-cm-line-state="{{ $bjState }}">
                    @if ($bjState !== 'eligible')<span class="fin-status fin-status--{{ \App\Services\Finance\CommissionEligibility::TONES[$bjState] }}">{{ \App\Services\Finance\CommissionEligibility::LABELS[$bjState] }}</span>@endif
                    Job {{ $bj['job']['reference'] }} is not in the calculation yet: {{ collect($bj['blockers'])->pluck('message')->implode(' ') }}
                </div>
            @endforeach
        </section>

        @if ($eligibility['people'])
            <section class="oa-card oa-card-body" aria-labelledby="cmPeopleTitle">
                <div class="oa-card-head"><div><h2 class="oa-card-title" id="cmPeopleTitle"><i data-lucide="users" aria-hidden="true"></i> Draft shares by person</h2></div></div>
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Person</th><th scope="col">Roles</th><th scope="col" class="is-num">Fully paid jobs</th><th scope="col" class="is-num">Draft share</th><th scope="col" class="is-num">Not payable (estimate)</th></tr></thead>
                        <tbody>
                            @foreach ($eligibility['people'] as $key => $r)
                                <tr data-cm-person="{{ $key }}">
                                    <td>{{ $r['name'] }}</td><td>{{ $r['role_list'] }}</td><td class="is-num">{{ $r['eligible_jobs'] }}</td>
                                    <td class="is-num fin-strong" data-cm-person-eligible>{{ $r['eligible']->format() }}</td>
                                    <td class="is-num is-dim" data-cm-person-estimate>@if ($r['other_jobs']){{ $r['estimate']->format() }} <span>({{ $r['other_jobs'] }} job(s))</span>@else–@endif</td>
                                </tr>
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
                                    <td><span class="fin-status fin-status--{{ ['pending_review' => 'amber', 'approved' => 'green', 'voided' => 'red'][$d->status] }}">{{ ['pending_review' => 'Awaiting review', 'approved' => 'Approved', 'voided' => 'Voided'][$d->status] }}</span></td>
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
