{{-- Finance Report body, shared by the Owner page, the printable report and the PDF (same data, same totals). --}}
@php
    $m = $r['meta'];
    $t = $r['jobs']['totals'];
    $e = $r['expenses']['summary'];
    $f = $r['funds']['totals'];
    $d = $r['draft'];
    $a = $r['approved'];
    $p = $r['payouts'];
    $draftLabel = $m['policy_confirmed'] ? 'Draft estimate — not approved' : 'Draft / Pending Client Confirmation — not approved';
    $fmtDate = fn ($date) => $date ? $date->format('M j, Y') : '—';
@endphp

<div class="fr-meta" data-fr-meta>
    <div><strong>Period:</strong> {{ $m['from']->format('M j, Y') }} – {{ $m['to']->format('M j, Y') }} (Asia/Manila business dates)
        @if ($m['weeks_from']) · <strong>Commission weeks:</strong> {{ $m['weeks_from']->format('M j') }} – {{ $m['weeks_to']->format('M j, Y') }} (Monday to Saturday) @endif</div>
    <div><strong>Filters:</strong> Unit: {{ $m['unit'] ?? 'All units' }} · Personnel: {{ $m['person'] ?? 'All' }} · Payment: {{ $m['payment_status'] ?? 'All' }} · Expenses: {{ $m['expense_status'] ?? 'All' }} · Statements: {{ $m['statement_status'] }}</div>
    <div><strong>Commission policy:</strong> v{{ $m['policy_version'] ?? '—' }} — <span data-fr-policy="{{ $m['policy_confirmed'] ? 'confirmed' : 'pending' }}">{{ $m['policy_confirmed'] ? 'active, client-confirmed' : 'DRAFT / PENDING CLIENT CONFIRMATION' }}</span></div>
</div>

<h2 class="fr-h">1. Bookings and customer collections (actual, by service date)</h2>
<table class="fr-sum">
    <tr><td>Completed bookings</td><td class="fr-num" data-fr="bookings">{{ number_format($t['completed_bookings']) }}</td></tr>
    <tr><td>Vehicles towed</td><td class="fr-num" data-fr="vehicles">{{ number_format($t['vehicles']) }}</td></tr>
    <tr><td>Final agreed charges</td><td class="fr-num" data-fr="agreed">{{ $t['agreed']->format() }}</td></tr>
    <tr class="fr-key"><td>Verified customer collections</td><td class="fr-num" data-fr="collected">{{ $t['collected']->format() }}</td></tr>
    <tr><td>Outstanding customer balances</td><td class="fr-num" data-fr="outstanding">{{ $t['outstanding']->format() }}</td></tr>
    @if ($t['over_collected']->isPositive())<tr><td>Over-collected (review)</td><td class="fr-num">{{ $t['over_collected']->format() }}</td></tr>@endif
    <tr><td>Allocated to towing units ({{ $t['pending_allocations'] }} job(s) pending allocation review)</td><td class="fr-num" data-fr="allocated">{{ $t['allocated']->format() }}</td></tr>
</table>
@if ($t['by_unit']->isNotEmpty())
    <table class="fr-grid">
        <thead><tr><th>Unit</th><th class="fr-num">Jobs</th><th class="fr-num">Vehicles</th><th class="fr-num">Allocated collection</th></tr></thead>
        <tbody>
            @foreach ($t['by_unit'] as $u)
                <tr data-fr-unit-alloc="{{ $u['unit_name'] }}"><td>{{ $u['unit_name'] }}</td><td class="fr-num">{{ $u['jobs'] }}</td><td class="fr-num">{{ $u['vehicles'] }}</td><td class="fr-num">{{ $u['amount']->format() }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif
<table class="fr-grid">
    <thead><tr><th>Booking</th><th>Service date</th><th>Customer</th><th>Units</th><th class="fr-num">Vehicles</th><th class="fr-num">Agreed</th><th class="fr-num">Collected</th><th class="fr-num">Outstanding</th><th>Status</th></tr></thead>
    <tbody>
        @forelse ($r['jobs']['rows'] as $j)
            <tr data-fr-job="{{ $j['reference'] }}">
                <td>{{ $j['reference'] }}@if ($j['group_code'])<br><span class="fr-dim">Group {{ $j['group_code'] }}</span>@endif</td>
                <td>{{ $fmtDate($j['service_date']) }}</td><td>{{ $j['customer'] }}</td><td>{{ $j['units'] }}</td>
                <td class="fr-num">{{ $j['vehicles'] }}</td><td class="fr-num">{{ $j['agreed']->format() }}</td><td class="fr-num">{{ $j['collected']->format() }}</td><td class="fr-num">{{ $j['outstanding']->format() }}</td>
                <td>{{ $j['status_label'] }}<br><span class="fr-dim">{{ $j['allocation_label'] }}</span></td>
            </tr>
        @empty
            <tr><td colspan="9" data-fr-empty="jobs">No completed bookings match these filters.</td></tr>
        @endforelse
    </tbody>
</table>

<h2 class="fr-h">2. Kept visible for review (no rule applied; pending client confirmation)</h2>
@php $reviewSets = ['sunday' => 'Sunday jobs outside the Monday–Saturday week', 'late' => 'Collected in this period for service in an earlier period', 'unsettled' => 'Unsettled payments or unit allocations']; @endphp
@foreach ($reviewSets as $key => $label)
    <p class="fr-sub" data-fr-review="{{ $key }}"><strong>{{ $label }}:</strong> {{ count($r['review'][$key]) }}</p>
    @if ($r['review'][$key])
        <ul class="fr-list">
            @foreach ($r['review'][$key] as $j)
                <li>{{ $j['reference'] }} · {{ $fmtDate($j['service_date']) }} · {{ $j['units'] }} · {{ $j['status_label'] }} · collected {{ $j['collected']->format() }} of {{ $j['agreed']->format() }}{{ $j['payment_date'] ? ' · paid ' . $fmtDate($j['payment_date']) : '' }}</li>
            @endforeach
        </ul>
    @endif
@endforeach
@if ($r['review']['blockers'])
    <p class="fr-sub"><strong>Commission approval blockers:</strong>
        @foreach ($r['review']['blockers'] as $code => $count)<span data-fr-blocker="{{ $code }}">{{ str_replace('_', ' ', $code) }} ({{ $count }})</span>{{ $loop->last ? '' : ' · ' }}@endforeach</p>
@endif

<h2 class="fr-h">3. Expenses (by expense date; only Approved amounts are costs)</h2>
<table class="fr-sum">
    <tr class="fr-key"><td>Approved expenses ({{ $e['approved']['count'] }})</td><td class="fr-num" data-fr="expenses-approved">{{ $e['approved']['total']->format() }}</td></tr>
    <tr><td>&nbsp;&nbsp;paid from issued unit funds</td><td class="fr-num" data-fr="expenses-unit-fund">{{ $e['approved_unit_fund']->format() }}</td></tr>
    <tr><td>&nbsp;&nbsp;paid directly by the company</td><td class="fr-num" data-fr="expenses-company">{{ $e['approved_company']->format() }}</td></tr>
    <tr><td>&nbsp;&nbsp;personal advance (reimbursement rule pending client confirmation)</td><td class="fr-num" data-fr="expenses-personal">{{ $e['approved_personal']->format() }}</td></tr>
    <tr><td>Awaiting review — not deducted ({{ $e['submitted']['count'] + $e['verified']['count'] }})</td><td class="fr-num" data-fr="expenses-pending">{{ $e['submitted']['total']->plus($e['verified']['total'])->format() }}</td></tr>
    <tr><td>Rejected or voided</td><td class="fr-num">{{ $e['rejected']['total']->plus($e['voided']['total'])->format() }}</td></tr>
</table>
<table class="fr-grid">
    <thead><tr><th>By category</th><th class="fr-num">Count</th><th class="fr-num">Approved</th><th class="fr-num">Awaiting review</th></tr></thead>
    <tbody>
        @forelse ($r['expenses']['by_category'] as $x)
            <tr data-fr-category="{{ $x['key'] }}"><td>{{ $x['label'] }}</td><td class="fr-num">{{ $x['count'] }}</td><td class="fr-num">{{ $x['approved']->format() }}</td><td class="fr-num">{{ $x['pending']->format() }}</td></tr>
        @empty
            <tr><td colspan="4" data-fr-empty="expenses">No expenses match these filters.</td></tr>
        @endforelse
    </tbody>
</table>
@if ($r['expenses']['by_unit'])
    <table class="fr-grid">
        <thead><tr><th>By unit</th><th class="fr-num">Count</th><th class="fr-num">Approved</th><th class="fr-num">Awaiting review</th></tr></thead>
        <tbody>
            @foreach ($r['expenses']['by_unit'] as $x)
                <tr><td>{{ $x['label'] }}</td><td class="fr-num">{{ $x['count'] }}</td><td class="fr-num">{{ $x['approved']->format() }}</td><td class="fr-num">{{ $x['pending']->format() }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

<h2 class="fr-h">4. Unit funds (cash held by units — not income and not expenses)</h2>
<table class="fr-grid">
    <thead><tr><th>Unit</th><th class="fr-num">Opening (carried forward)</th><th class="fr-num">Issued</th><th class="fr-num">Spent</th><th class="fr-num">Returned</th><th class="fr-num">Remaining</th></tr></thead>
    <tbody>
        @foreach ($r['funds']['rows'] as $u)
            <tr data-fr-fund="{{ $u['unit_name'] }}"><td>{{ $u['unit_name'] }}</td><td class="fr-num">{{ $u['opening']->format() }}</td><td class="fr-num">{{ $u['issued']->format() }}</td><td class="fr-num">{{ $u['spent']->format() }}</td><td class="fr-num">{{ $u['returned']->format() }}</td><td class="fr-num">{{ $u['remaining']->format() }}</td></tr>
        @endforeach
        <tr class="fr-total"><td>Total</td><td class="fr-num" data-fr="funds-opening">{{ $f['opening']->format() }}</td><td class="fr-num" data-fr="funds-issued">{{ $f['issued']->format() }}</td><td class="fr-num" data-fr="funds-spent">{{ $f['spent']->format() }}</td><td class="fr-num" data-fr="funds-returned">{{ $f['returned']->format() }}</td><td class="fr-num" data-fr="funds-remaining">{{ $f['remaining']->format() }}</td></tr>
    </tbody>
</table>

<h2 class="fr-h">5. Commission — {{ $draftLabel }}</h2>
<table class="fr-sum">
    <tr class="fr-key"><td>Draft sharing base</td><td class="fr-num" data-fr="draft-base">{{ $d['base']->format() }}</td></tr>
    <tr><td>Draft company share</td><td class="fr-num" data-fr="draft-company">{{ $d['company']->format() }}</td></tr>
    <tr><td>Draft crew commission</td><td class="fr-num" data-fr="draft-crew">{{ $d['crew']->format() }}</td></tr>
    <tr><td>Unallocated (no one recorded in the role) — pending</td><td class="fr-num" data-fr="draft-unallocated">{{ $d['unallocated']->format() }}</td></tr>
</table>
<table class="fr-grid">
    <thead><tr><th>Role</th><th class="fr-num">Allocated</th><th class="fr-num">Unallocated</th></tr></thead>
    <tbody>
        @foreach ($d['by_role'] as $role => $x)
            <tr data-fr-draft-role="{{ $role }}"><td>{{ $x['label'] }}</td><td class="fr-num">{{ $x['allocated']->format() }}</td><td class="fr-num">{{ $x['unallocated']->format() }}</td></tr>
        @endforeach
    </tbody>
</table>
@if ($d['by_recipient'])
    <table class="fr-grid">
        <thead><tr><th>Person (draft)</th><th>Roles</th><th class="fr-num">Jobs</th><th class="fr-num">Draft amount</th></tr></thead>
        <tbody>
            @foreach ($d['by_recipient'] as $x)
                @continue($d['person'] && $x['recipient_key'] !== $d['person']['recipient_key'])
                <tr data-fr-draft-person="{{ $x['name'] }}"><td>{{ $x['name'] }}</td><td>{{ implode(', ', array_keys($x['roles'])) }}</td><td class="fr-num">{{ $x['jobs'] }}</td><td class="fr-num">{{ $x['amount']->format() }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

<h2 class="fr-h">6. Commission statements — {{ $a['filter_label'] }}{{ $a['is_draft_only'] ? ' (NOT APPROVED)' : ' (frozen approval snapshots)' }}</h2>
<table class="fr-sum">
    <tr><td>Statements</td><td class="fr-num" data-fr="approved-count">{{ $a['count'] }}</td></tr>
    <tr><td>Sharing base</td><td class="fr-num" data-fr="approved-base">{{ $a['base']->format() }}</td></tr>
    <tr><td>Company share</td><td class="fr-num" data-fr="approved-company">{{ $a['company']->format() }}</td></tr>
    <tr class="fr-key"><td>Crew payable</td><td class="fr-num" data-fr="approved-personnel">{{ $a['personnel']->format() }}</td></tr>
    @if ($a['person_amount'])<tr><td>{{ $m['person'] }} — approved amount</td><td class="fr-num" data-fr="approved-person">{{ $a['person_amount']->format() }}</td></tr>@endif
</table>
<table class="fr-grid">
    <thead><tr><th>Statement</th><th>Week</th><th>Unit</th><th>Status</th><th class="fr-num">Base</th><th class="fr-num">Company</th><th class="fr-num">Crew</th></tr></thead>
    <tbody>
        @forelse ($a['rows'] as $x)
            <tr data-fr-statement="{{ $x['reference'] }}"><td>{{ $x['reference'] }}</td><td>{{ $x['period_start']->format('M j') }} – {{ $x['period_end']->format('M j, Y') }}</td><td>{{ $x['unit'] }}</td><td>{{ $x['status_label'] }}</td>
                <td class="fr-num">{{ $x['base']->format() }}</td><td class="fr-num">{{ $x['company']->format() }}</td><td class="fr-num">{{ $x['personnel']->format() }}</td></tr>
        @empty
            <tr><td colspan="7" data-fr-empty="statements">No statements match these filters.</td></tr>
        @endforelse
    </tbody>
</table>
@if ($a['by_recipient'])
    <table class="fr-grid">
        <thead><tr><th>Person (approved)</th><th>Roles</th><th class="fr-num">Approved amount</th></tr></thead>
        <tbody>
            @foreach ($a['by_recipient'] as $x)
                <tr data-fr-approved-person="{{ $x['name'] }}"><td>{{ $x['name'] }}</td><td>{{ $x['role_list'] }}</td><td class="fr-num">{{ $x['amount']->format() }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

<h2 class="fr-h">7. Recorded payouts (actual disbursements recorded by the Owner)</h2>
<table class="fr-grid">
    <thead><tr><th>Payout</th><th>Statement</th><th>Recipient</th><th>Date paid</th><th>Method</th><th>Reference</th><th class="fr-num">Amount</th></tr></thead>
    <tbody>
        @forelse ($p['rows'] as $x)
            <tr data-fr-payout="{{ $x['reference'] }}"><td>{{ $x['reference'] }}</td><td>{{ $x['statement'] }}</td><td>{{ $x['recipient'] }}<br><span class="fr-dim">{{ $x['roles'] }}</span></td><td>{{ $fmtDate($x['paid_at']) }}</td><td>{{ $x['method'] }}</td><td>{{ $x['external_reference'] ?? '—' }}</td><td class="fr-num">{{ $x['amount']->format() }}</td></tr>
        @empty
            <tr><td colspan="7" data-fr-empty="payouts">No payouts recorded for these filters.</td></tr>
        @endforelse
        <tr class="fr-total"><td colspan="6">Total paid ({{ $p['count'] }})</td><td class="fr-num" data-fr="payouts-total">{{ $p['total']->format() }}</td></tr>
    </tbody>
</table>
<p class="fr-dim">TowMate records payouts made outside the system by cash, GCash or bank transfer; it does not transfer money. Draft, approved and paid amounts are separate and are never added together.</p>
