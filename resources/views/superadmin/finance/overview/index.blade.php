@extends('layouts.superadmin')

@section('title', 'Finance Overview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $c = $o['collections'];
    $e = $o['expenses'];
    $f = $o['funds'];
    $d = $o['draft'];
    $a = $o['approved'];
    $q = fn (array $params = []) => array_filter(array_merge(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'unit_id' => $unitId], $params));
    $blockerLabels = [
        'policy_for_client_review' => 'Commission policy awaiting client confirmation',
        'no_personnel' => 'Jobs with no crew recorded',
        'missing_role' => 'Roles nobody was recorded for',
        'payment_not_verified' => 'Customer payments not verified',
        'allocation_pending' => 'Unit revenue allocations pending',
        'not_allocated' => 'Jobs not allocated to a unit',
        'expenses_pending' => 'Job expenses awaiting review',
        'monthly_allocation_pending' => 'Monthly expenses without an allocation rule',
        'personal_advance_pending' => 'Personal advances awaiting a rule',
        'rider_role_pending' => 'Rider / Substitute rule pending',
        'substitute_rule_pending' => 'Substitute rule pending',
        'shared_role_rule_pending' => 'Shared-role rule pending',
        'combined_role_rule_pending' => 'Combined-role rule pending',
        'role_mapping_unresolved' => 'Crew records without a role',
        'personnel_needs_review' => 'Crew records needing review',
        'deduction_pending' => 'Deductions awaiting review',
        'payment_fee_unresolved' => 'Online payment fees not recorded',
        'refund_unresolved' => 'Refunds under review',
        'non_positive_base' => 'Jobs with a zero or negative sharing base',
        'job_unfinished' => 'Unfinished jobs',
        'invalid_percentages' => 'Invalid commission percentages',
    ];
@endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerFinanceOverview">
        <header class="ob-header fin-header">
            <div>
                <h1>Finance Overview</h1>
                <p>{{ $periodLabel }}{{ $unitId ? ' · ' . ($units->firstWhere('id', $unitId)?->name ?? 'Unit') : ' · All units' }}. Actual money, draft estimates, approved commission and recorded payouts are shown separately and are never added together.</p>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        <form method="GET" action="{{ route('superadmin.finance.overview') }}" class="oa-card oa-filter-card" role="search">
            <div class="oa-filters">
                <div class="oa-field">
                    <label for="ovUnit">Unit</label>
                    <select id="ovUnit" name="unit_id" class="oa-select" onchange="this.form.submit()">
                        <option value="">All units</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" @selected($unitId === $unit->id)>{{ $unit->name }}{{ $unit->archived_at ? ' (archived)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <span class="oa-field-label">Reporting period</span>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        @include('superadmin.analytics._range', ['label' => $periodLabel, 'from' => $from->toDateString(), 'to' => $to->toDateString()])
                        <a href="{{ route('superadmin.finance.overview', array_filter(['unit_id' => $unitId])) }}" class="oa-btn">This week</a>
                    </div>
                </div>
            </div>
        </form>

        {{-- 1. Actual collections --}}
        <section class="oa-card oa-card-body" aria-labelledby="ovCollTitle" data-ov-section="collections">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="ovCollTitle"><i data-lucide="banknote" aria-hidden="true"></i> Customer collections (actual)</h2>
                <p class="oa-card-sub">Jobs serviced in the period. Only Dispatcher-verified payments and live online payments count as collected.</p>
            </div><a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.collections.index', $q()) }}">Jobs &amp; Collections</a></div>
            <div class="oa-kpis" style="--oa-kpi-count: 4;">
                <div class="oa-kpi"><span class="oa-kpi-label">Jobs ({{ $c['vehicles'] }} vehicles)</span><div class="oa-kpi-value" data-ov="jobs">{{ $c['bookings'] }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Agreed charges</span><div class="oa-kpi-value" data-ov="agreed">{{ $c['agreed']->format() }}</div></div>
                <div class="oa-kpi is-dark"><span class="oa-kpi-label">Verified collections</span><div class="oa-kpi-value" data-ov="collected">{{ $c['collected']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Outstanding balances</span><div class="oa-kpi-value" data-ov="outstanding">{{ $c['outstanding']->format() }}</div>
                    @if ($c['over_collected']->isPositive())<span class="fin-kpi-sub">Over-collected {{ $c['over_collected']->format() }}</span>@endif</div>
            </div>
        </section>

        {{-- 2. Expenses and unit funds --}}
        <section class="oa-card oa-card-body" aria-labelledby="ovExpTitle" data-ov-section="expenses">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="ovExpTitle"><i data-lucide="receipt" aria-hidden="true"></i> Expenses (by expense date)</h2>
                <p class="oa-card-sub">Only approved expenses are costs. Pending ones are listed separately and are not deducted.</p>
            </div><a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.expenses.index', $q()) }}">Expenses</a></div>
            <div class="oa-kpis" style="--oa-kpi-count: 4;">
                <div class="oa-kpi is-dark"><span class="oa-kpi-label">Approved expenses ({{ $e['approved']['count'] }})</span><div class="oa-kpi-value" data-ov="expenses-approved">{{ $e['approved']['total']->format() }}</div>
                    <span class="fin-kpi-sub">Unit funds {{ $e['approved_unit_fund']->format() }} · Company {{ $e['approved_company']->format() }} · Personal {{ $e['approved_personal']->format() }}</span></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Awaiting Dispatcher ({{ $e['submitted']['count'] }})</span><div class="oa-kpi-value" data-ov="expenses-submitted">{{ $e['submitted']['total']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Awaiting Owner ({{ $e['verified']['count'] }})</span><div class="oa-kpi-value" data-ov="expenses-verified">{{ $e['verified']['total']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Rejected ({{ $e['rejected']['count'] }})</span><div class="oa-kpi-value">{{ $e['rejected']['total']->format() }}</div></div>
            </div>
        </section>

        <section class="oa-card oa-card-body" aria-labelledby="ovFundTitle" data-ov-section="funds">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="ovFundTitle"><i data-lucide="wallet" aria-hidden="true"></i> Unit funds (cash held by units)</h2>
                <p class="oa-card-sub">Opening + issued − spent (approved unit-fund expenses) − returned = remaining. Not revenue and not commission.</p>
            </div><a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.unit-funds.index', $q()) }}">Unit Funds</a></div>
            <div class="oa-kpis oa-kpis--six" style="--oa-kpi-count: 5;">
                <div class="oa-kpi"><span class="oa-kpi-label">Opening</span><div class="oa-kpi-value" data-ov="funds-opening">{{ $f['opening']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Issued</span><div class="oa-kpi-value" data-ov="funds-issued">{{ $f['issued']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Spent</span><div class="oa-kpi-value" data-ov="funds-spent">{{ $f['spent']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Returned</span><div class="oa-kpi-value" data-ov="funds-returned">{{ $f['returned']->format() }}</div></div>
                <div class="oa-kpi is-dark"><span class="oa-kpi-label">Remaining</span><div class="oa-kpi-value" data-ov="funds-remaining">{{ $f['remaining']->format() }}</div>
                    @if ($f['pending_count'])<span class="fin-kpi-sub">{{ $f['pending_amount']->format() }} awaiting review</span>@endif</div>
            </div>
        </section>

        {{-- 3. Draft commission --}}
        <section class="oa-card oa-card-body" aria-labelledby="ovDraftTitle" data-ov-section="draft">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="ovDraftTitle"><i data-lucide="file-pen-line" aria-hidden="true"></i> Commission — draft estimate</h2>
                <p class="oa-card-sub">Not approved and may change. Weeks {{ $o['weeks_from']?->format('M j') }} – {{ $o['weeks_to']?->format('M j, Y') }} (Monday to Saturday).
                    @if (! $d['policy'] || $d['policy']['interpretation_status'] !== 'confirmed') The commission interpretation is pending client confirmation (Draft / For Client Review). @endif</p>
            </div><a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.commission.index', array_filter(['week' => $o['weeks_from']?->toDateString(), 'unit_id' => $unitId])) }}">Draft breakdown</a></div>
            <div class="oa-kpis" style="--oa-kpi-count: 4;">
                <div class="oa-kpi is-dark"><span class="oa-kpi-label">Draft sharing base</span><div class="oa-kpi-value" data-ov="draft-base">{{ $d['base']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Company share</span><div class="oa-kpi-value" data-ov="draft-company">{{ $d['company']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Crew commission</span><div class="oa-kpi-value" data-ov="draft-crew">{{ $d['crew']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Unallocated</span><div class="oa-kpi-value" data-ov="draft-unallocated">{{ $d['unallocated']->format() }}</div></div>
            </div>
            @if ($d['by_role'])
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Role</th><th scope="col" class="is-num">Allocated</th><th scope="col" class="is-num">Unallocated</th></tr></thead>
                        <tbody>
                            @foreach ($d['by_role'] as $role => $row)
                                <tr data-ov-role="{{ $role }}"><td>{{ $row['label'] }}</td><td class="is-num">{{ $row['allocated']->format() }}</td><td class="is-num">{{ $row['unallocated']->format() }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- 4. Approved commission and payouts --}}
        <section class="oa-card oa-card-body" aria-labelledby="ovApprovedTitle" data-ov-section="approved">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="ovApprovedTitle"><i data-lucide="badge-check" aria-hidden="true"></i> Approved commission and recorded payouts</h2>
                <p class="oa-card-sub">From approved and Paid statements only (frozen figures). Payouts are records of payments the Owner already made; TowMate does not transfer money.</p>
            </div><a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.statements.index', array_filter(['unit_id' => $unitId])) }}">Statements & payouts</a></div>
            <div class="oa-kpis oa-kpis--six" style="--oa-kpi-count: 5;">
                <div class="oa-kpi"><span class="oa-kpi-label">Statements approved ({{ $a['paid_count'] }} paid)</span><div class="oa-kpi-value" data-ov="approved-count">{{ $a['count'] }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Approved sharing base</span><div class="oa-kpi-value" data-ov="approved-base">{{ $a['base']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Approved company share</span><div class="oa-kpi-value" data-ov="approved-company">{{ $a['company']->format() }}</div></div>
                <div class="oa-kpi is-dark"><span class="oa-kpi-label">Approved crew payable</span><div class="oa-kpi-value" data-ov="approved-personnel">{{ $a['personnel']->format() }}</div></div>
                <div class="oa-kpi"><span class="oa-kpi-label">Payouts recorded ({{ $a['payouts_count'] }})</span><div class="oa-kpi-value" data-ov="payouts-recorded">{{ $a['payouts_recorded']->format() }}</div>
                    <span class="fin-kpi-sub" data-ov="payouts-remaining">Remaining {{ $a['payouts_remaining']->format() }}</span></div>
            </div>
        </section>

        {{-- 5. Needs attention --}}
        <section class="oa-card oa-card-body" aria-labelledby="ovAttnTitle" data-ov-section="attention">
            <div class="oa-card-head"><div><h2 class="oa-card-title" id="ovAttnTitle"><i data-lucide="shield-alert" aria-hidden="true"></i> Needs attention</h2></div></div>
            <ul class="fin-trail">
                <li data-ov-pending="expenses">{{ $o['pending']['expenses_submitted']['count'] }} expense(s) awaiting Dispatcher verification, {{ $o['pending']['expenses_verified']['count'] }} awaiting your approval
                    @if ($o['pending']['missing_evidence']) · {{ $o['pending']['missing_evidence'] }} without evidence @endif
                    · <a href="{{ route('superadmin.finance.expenses.index', $q(['status' => 'pending'])) }}">Review</a></li>
                <li data-ov-pending="allocations">{{ $o['pending']['allocations'] }} job(s) with a unit allocation pending review</li>
                <li data-ov-pending="statements">{{ $a['open_count'] }} open statement(s), {{ $o['pending']['statements_for_review'] }} For Review</li>
                @foreach ($d['blockers'] as $code => $count)
                    <li data-ov-blocker="{{ $code }}"><span class="fin-flag fin-flag--missing_evidence">{{ $blockerLabels[$code] ?? str_replace('_', ' ', ucfirst($code)) }}</span> <span class="is-dim">({{ $count }})</span></li>
                @endforeach
                @if (! $d['blockers'])
                    <li><span class="fin-ok">No commission approval blockers in these weeks.</span></li>
                @endif
            </ul>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/analytics.js') }}?v={{ filemtime(public_path('superadmin/js/analytics.js')) }}" defer></script>
@endpush
