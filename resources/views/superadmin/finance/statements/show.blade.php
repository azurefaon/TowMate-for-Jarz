@extends('layouts.superadmin')

@section('title', 'Commission Statement · ' . $statement->reference_code)

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $m = fn ($v) => \App\Support\Money::of((string) $v)->format();
    $badge = ['draft' => 'grey', 'for_review' => 'amber', 'approved' => 'green', 'paid' => 'blue', 'voided' => 'red'];
    $lines = $calc['lines'] ?? [];
    $policy = $calc['policy'] ?? null;
    $blockers = $review['blockers'] ?? [];
    $byPerson = [];
    foreach ($lines as $l) {
        foreach ($l['roles'] as $role => $r) {
            foreach ($r['recipients'] as $p) {
                $byPerson[$p['recipient_key']] ??= ['name' => $p['name'], 'roles' => [], 'amount' => \App\Support\Money::zero(), 'jobs' => 0];
                $byPerson[$p['recipient_key']]['roles'][$r['label']] = true;
                $byPerson[$p['recipient_key']]['amount'] = $byPerson[$p['recipient_key']]['amount']->plus($p['amount']);
                $byPerson[$p['recipient_key']]['jobs']++;
            }
        }
    }
    $isOwner = \App\Support\Finance\FinanceAccess::isOwner(auth()->user());
@endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerStatementShow" data-statement-status="{{ $statement->status }}">
        <header class="ob-header fin-header">
            <div>
                <h1>{{ $statement->reference_code }} <span class="fin-status fin-status--{{ $badge[$statement->status] ?? 'grey' }}">{{ $statement->statusLabel() }}</span></h1>
                <p>{{ $statement->unit?->name }} · week of {{ $statement->period_start->format('M j') }} – {{ $statement->period_end->format('M j, Y') }} (Monday to Saturday) · revision {{ $statement->revision }}
                    @if ($statement->supersedes) · corrects <a href="{{ route('superadmin.finance.statements.show', $statement->supersedes->id) }}">{{ $statement->supersedes->reference_code }}</a>@endif</p>
            </div>
            <div class="fin-header-actions">
                <a class="oa-btn" href="{{ route('superadmin.finance.statements.print', $statement->id) }}" target="_blank" rel="noopener">Printable statement</a>
                <a class="oa-btn" href="{{ route('superadmin.finance.statements.index') }}">Back to statements</a>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        @if ($statement->correction_reason)
            <div class="fin-flash fin-flash--warning" role="status"><i data-lucide="history" aria-hidden="true"></i><span>Correction reason: {{ $statement->correction_reason }}</span></div>
        @endif
        @foreach ($corrections as $c)
            <div class="fin-flash fin-flash--warning" role="status"><i data-lucide="history" aria-hidden="true"></i><span>Corrected by <a href="{{ route('superadmin.finance.statements.show', $c->id) }}">{{ $c->reference_code }}</a> ({{ $c->statusLabel() }}).</span></div>
        @endforeach

        {{-- Policy --}}
        <div class="fin-flash {{ ($policy && $policy['status'] === 'active' && $policy['interpretation_status'] === 'confirmed') ? 'fin-flash--success' : 'fin-flash--warning' }}" role="status" data-statement-policy>
            <i data-lucide="percent" aria-hidden="true"></i>
            <span>Policy v{{ $policy['version'] ?? '—' }}
                ({{ ($policy && $policy['interpretation_status'] === 'confirmed' && $policy['status'] === 'active') ? 'active, client-confirmed' : 'Draft / For Client Review: interpretation not confirmed' }}):
                @foreach (\App\Services\Finance\CommissionCalculator::inRoleOrder($policy['rates'] ?? []) as $role => $rate)
                    {{ \App\Services\Finance\CommissionCalculator::ROLE_LABELS[$role] ?? $role }} {{ rtrim(rtrim($rate, '0'), '.') }}%{{ $loop->last ? '.' : ',' }}
                @endforeach
            </span>
        </div>

        {{-- Approval eligibility / record --}}
        <section class="oa-card oa-card-body" aria-labelledby="stEligTitle">
            @if ($statement->isOpen())
                <div class="oa-card-head"><div>
                    <h2 class="oa-card-title" id="stEligTitle"><i data-lucide="shield-check" aria-hidden="true"></i> Approval eligibility</h2>
                    <p class="oa-card-sub" data-statement-eligible="{{ $review['can_approve'] ? 'yes' : 'no' }}">
                        {{ $review['can_approve'] ? 'Eligible: every check passes.' : count($blockers) . ' issue(s) must be resolved before approval.' }}</p>
                </div></div>
                @if ($blockers)
                    <ul class="fin-trail">
                        @foreach ($blockers as $b)<li data-statement-blocker="{{ $b['code'] }}"><span class="fin-flag fin-flag--missing_evidence">{{ $b['message'] }}</span></li>@endforeach
                    </ul>
                @endif
                @if ($isOwner)
                    <div class="fin-form-actions" style="flex-wrap:wrap; gap:10px;">
                        @if ($statement->status === 'draft')
                            <form method="POST" action="{{ route('superadmin.finance.statements.recalculate', $statement->id) }}" data-fin-once>@csrf<button class="oa-btn oa-btn--sm" type="submit">Recalculate</button></form>
                            <form method="POST" action="{{ route('superadmin.finance.statements.submit', $statement->id) }}" data-fin-once>@csrf<button class="oa-btn oa-btn--sm oa-btn--primary" type="submit" @disabled($review['stale'])>Submit for review</button></form>
                            <a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.statements.create', ['week' => $statement->period_start->toDateString(), 'unit_id' => $statement->unit_id]) }}">Change job selection</a>
                        @else
                            <form method="POST" action="{{ route('superadmin.finance.statements.approve', $statement->id) }}" data-fin-once>@csrf<button class="oa-btn oa-btn--sm oa-btn--primary" type="submit" @disabled(! $review['can_approve'])>Approve statement</button></form>
                            <form method="POST" action="{{ route('superadmin.finance.statements.return', $statement->id) }}" class="fin-inline-form" data-fin-once>@csrf
                                <input type="text" name="reason" required minlength="5" maxlength="500" placeholder="Reason" aria-label="Reason for returning to draft">
                                <button class="oa-btn oa-btn--sm" type="submit">Return to draft</button>
                            </form>
                        @endif
                    </div>
                @endif
            @else
                <div class="oa-card-head"><div>
                    <h2 class="oa-card-title" id="stEligTitle"><i data-lucide="lock" aria-hidden="true"></i> Approval record</h2>
                    <p class="oa-card-sub">Approved by {{ $statement->approved_by_name }} on {{ $statement->approved_at?->format('M j, Y g:i A') }}. The figures below come from the frozen approval snapshot; later crew, rate or report changes do not affect them.</p>
                </div></div>
                <p class="fin-help">Snapshot hash <span class="fin-ref">{{ $statement->approval_hash }}</span> ·
                    <span data-statement-integrity="{{ $integrity ? 'ok' : 'failed' }}">{{ $integrity ? 'Integrity verified' : 'Integrity check FAILED' }}</span></p>
                @if ($statement->status === 'voided')
                    <p class="fin-help fin-negative">Voided {{ $statement->voided_at?->format('M j, Y g:i A') }}: {{ $statement->void_reason }}</p>
                @endif
                @if ($statement->status === 'approved' && $isOwner)
                    <div class="fin-form-actions" style="flex-wrap:wrap; gap:10px;">
                        <form method="POST" action="{{ route('superadmin.finance.statements.correct', $statement->id) }}" class="fin-inline-form" data-fin-once>@csrf
                            <input type="text" name="reason" required minlength="5" maxlength="500" placeholder="Reason for correction" aria-label="Reason for correcting this statement">
                            <button class="oa-btn oa-btn--sm oa-btn--primary" type="submit">Correct (void and re-issue)</button>
                        </form>
                        <form method="POST" action="{{ route('superadmin.finance.statements.void', $statement->id) }}" class="fin-inline-form" data-fin-once>@csrf
                            <input type="text" name="reason" required minlength="5" maxlength="500" placeholder="Reason for voiding" aria-label="Reason for voiding this statement">
                            <button class="oa-btn oa-btn--sm fin-btn-danger" type="submit">Void</button>
                        </form>
                    </div>
                @endif
            @endif
        </section>

        @if ($payouts)
            <section class="oa-card oa-card-body" aria-labelledby="stPayoutTitle" id="stPayouts">
                <div class="oa-card-head"><div>
                    <h2 class="oa-card-title" id="stPayoutTitle"><i data-lucide="hand-coins" aria-hidden="true"></i> Payouts</h2>
                    <p class="oa-card-sub">Record each payment after you have paid the person by cash, GCash or bank transfer. TowMate does not transfer money, and recording a payment never marks the statement Paid. Amounts come from the approved snapshot. One full payout per person; partial and combined payouts are pending client confirmation.</p>
                </div></div>
                <div class="oa-kpis" style="--oa-kpi-count: 4;">
                    <div class="oa-kpi"><span class="oa-kpi-label">Sharing base</span><div class="oa-kpi-value">{{ $payouts['base']->format() }}</div></div>
                    <div class="oa-kpi"><span class="oa-kpi-label">Company share (not a payout)</span><div class="oa-kpi-value" data-po-total="company">{{ $payouts['company']->format() }}</div></div>
                    <div class="oa-kpi is-dark"><span class="oa-kpi-label">Crew payable</span><div class="oa-kpi-value" data-po-total="payable">{{ $payouts['payable']->format() }}</div></div>
                    <div class="oa-kpi"><span class="oa-kpi-label">Recorded / remaining</span><div class="oa-kpi-value" data-po-total="paid">{{ $payouts['paid']->format() }}</div><span class="fin-kpi-sub" data-po-total="remaining">Remaining {{ $payouts['remaining']->format() }}</span></div>
                </div>
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Person</th><th scope="col">Role(s)</th><th scope="col" class="is-num">Approved amount</th><th scope="col">Payout</th><th scope="col"><span class="fin-sr">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($payouts['rows'] as $row)
                                <tr data-po-row="{{ $row['recipient_key'] }}">
                                    <td class="fin-strong">{{ $row['recipient_name'] }}</td>
                                    <td>{{ implode(', ', $row['roles']) }}</td>
                                    <td class="is-num fin-strong">{{ $row['earned']->format() }}</td>
                                    <td>
                                        @if ($row['payout'])
                                            <span class="fin-status fin-status--green">Recorded</span> <span class="fin-ref">{{ $row['payout']->reference_code }}</span>
                                            <div class="is-dim">{{ $row['payout']->methodLabel() }} · {{ $row['payout']->paid_at?->format('M j, Y') }} · {{ $row['payout']->external_reference ?: 'no reference' }} · by {{ $row['payout']->recorded_by_name }}</div>
                                            @if ($row['payout']->evidence_path)<a href="{{ route('superadmin.finance.payouts.evidence', $row['payout']->id) }}" target="_blank" rel="noopener">View evidence</a>@endif
                                        @else
                                            <span class="fin-status fin-status--amber">Not recorded</span>
                                        @endif
                                    </td>
                                    <td class="fin-actions">
                                        @if ($statement->status === 'approved' && \App\Support\Finance\FinanceAccess::isOwner(auth()->user()))
                                            <details class="fin-correct" @if (! $row['payout'] && old('recipient_key') === $row['recipient_key']) open @endif>
                                                <summary>{{ $row['payout'] ? 'Correct' : 'Record payout' }}</summary>
                                                <form method="POST" enctype="multipart/form-data" class="fin-form" data-fin-once
                                                      action="{{ $row['payout'] ? route('superadmin.finance.payouts.correct', $row['payout']->id) : route('superadmin.finance.payouts.store', $statement->id) }}">
                                                    @csrf
                                                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                                    <input type="hidden" name="recipient_key" value="{{ $row['recipient_key'] }}">
                                                    <div class="fin-grid fin-grid--compact">
                                                        <label class="fin-field"><span>Amount</span><input type="text" value="{{ $row['earned']->format() }}" readonly aria-readonly="true"></label>
                                                        <label class="fin-field"><span>Date paid</span><input type="date" name="paid_at" required max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}"></label>
                                                        <label class="fin-field"><span>Method</span>
                                                            <select name="payout_method" class="oa-select" required>
                                                                @foreach (config('finance.payout_methods') as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                                            </select>
                                                        </label>
                                                        <label class="fin-field"><span>Transaction reference</span><input type="text" name="external_reference" maxlength="100" placeholder="GCash / bank ref, or cash acknowledgement no."></label>
                                                        <label class="fin-field"><span>Evidence <small>(if no reference)</small></span><input type="file" name="evidence" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
                                                        <label class="fin-field"><span>Notes <small>(optional)</small></span><input type="text" name="notes" maxlength="500"></label>
                                                        @if ($row['payout'])<label class="fin-field fin-field--wide"><span>Reason for the correction</span><input type="text" name="reason" required minlength="5" maxlength="500"></label>@endif
                                                    </div>
                                                    <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--sm oa-btn--primary">{{ $row['payout'] ? 'Void and record correction' : 'Record payout' }}</button></div>
                                                </form>
                                            </details>
                                            @if ($row['payout'])
                                                <form method="POST" action="{{ route('superadmin.finance.payouts.void', $row['payout']->id) }}" class="fin-inline-form" data-fin-once>@csrf
                                                    <input type="text" name="reason" required minlength="5" maxlength="500" placeholder="Reason" aria-label="Reason for voiding {{ $row['payout']->reference_code }}">
                                                    <button class="oa-btn oa-btn--sm fin-btn-danger" type="submit">Void</button>
                                                </form>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @foreach ($payouts['issues'] as $issue)<div class="fin-flag" data-po-issue>{{ $issue }}</div>@endforeach
                @if ($statement->status === 'approved' && \App\Support\Finance\FinanceAccess::isOwner(auth()->user()))
                    <div class="fin-form-actions">
                        <form method="POST" action="{{ route('superadmin.finance.payouts.mark-paid', $statement->id) }}" data-fin-once>@csrf
                            <button type="submit" class="oa-btn oa-btn--primary" data-po-mark-paid="{{ $payouts['can_mark_paid'] ? 'enabled' : 'disabled' }}" @disabled(! $payouts['can_mark_paid'])>Mark statement Paid</button>
                        </form>
                    </div>
                @elseif ($statement->status === 'paid')
                    <p class="fin-help" data-po-paid>Marked Paid by {{ $statement->paid_by_name }} on {{ $statement->paid_at?->format('M j, Y g:i A') }}. Payouts are final.</p>
                @endif
                @php $voided = collect($payouts['history'])->where('status', 'voided'); @endphp
                @if ($voided->isNotEmpty())
                    <details class="fin-details"><summary>Voided payouts ({{ $voided->count() }})</summary>
                        <div class="fin-details-body"><ul class="fin-trail">
                            @foreach ($voided as $po)<li data-po-voided="{{ $po->reference_code }}">{{ $po->reference_code }} · {{ $po->recipient_name }} · {{ \App\Support\Money::of($po->amount)->format() }} · {{ $po->methodLabel() }} {{ $po->external_reference }} · voided {{ $po->voided_at?->format('M j, Y g:i A') }}: {{ $po->void_reason }}</li>@endforeach
                        </ul></div>
                    </details>
                @endif
            </section>
        @endif

        <div class="oa-kpis oa-kpis--six" style="--oa-kpi-count: 6;">
            <div class="oa-kpi"><span class="oa-kpi-label">Verified collections</span><div class="oa-kpi-value" data-st-total="collection">{{ $m($statement->total_collection) }}</div></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Approved expenses</span><div class="oa-kpi-value" data-st-total="expenses">{{ $m($statement->total_expenses) }}</div><span class="fin-kpi-sub">Deductions {{ $m($statement->total_deductions) }}</span></div>
            <div class="oa-kpi is-dark"><span class="oa-kpi-label">Sharing base</span><div class="oa-kpi-value" data-st-total="base">{{ $m($statement->total_base) }}</div></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Company</span><div class="oa-kpi-value" data-st-total="company">{{ $m($statement->company_amount) }}</div></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Personnel</span><div class="oa-kpi-value" data-st-total="personnel">{{ $m($statement->personnel_amount) }}</div></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Unallocated</span><div class="oa-kpi-value" data-st-total="unallocated">{{ $m($statement->unallocated_amount) }}</div></div>
        </div>

        <section class="oa-card oa-card-body" aria-labelledby="stJobsTitle">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="stJobsTitle"><i data-lucide="calculator" aria-hidden="true"></i> Included jobs</h2>
                <p class="oa-card-sub">{{ $statement->job_count }} job(s). Shares are rounded to the centavo; the remainder goes to Company.</p>
            </div></div>
            @forelse ($lines as $l)
                <details class="fin-details fin-cm-line" data-st-line="{{ $l['reference'] }}" open>
                    <summary><span class="fin-ref">{{ $l['reference'] }}</span> · {{ $l['service_date'] }} · {{ $l['unit_name'] }} · base <strong>{{ $m($l['base']) }}</strong></summary>
                    <div class="fin-details-body">
                        <table class="oa-table fin-table">
                            <tbody>
                                <tr><td>Allocated verified collection ({{ $l['allocation_status'] === 'manual' ? 'split recorded by Owner' : 'allocated automatically' }})</td><td class="is-num">{{ $m($l['collection']) }}</td></tr>
                                @foreach ($l['expenses'] as $e)<tr><td>− {{ $e['category'] }} ({{ $e['reference'] }})</td><td class="is-num">{{ $m($e['amount']) }}</td></tr>@endforeach
                                @foreach ($l['deductions'] as $d)<tr><td>− {{ $d['type'] }} ({{ $d['reference'] }})</td><td class="is-num">{{ $m($d['amount']) }}</td></tr>@endforeach
                                <tr><th scope="row">Sharing base</th><td class="is-num fin-strong">{{ $m($l['base']) }}</td></tr>
                                <tr><td>Company</td><td class="is-num">{{ $m($l['company']) }}</td></tr>
                                @foreach (\App\Services\Finance\CommissionCalculator::inRoleOrder($l['roles']) as $r)
                                    <tr><td>{{ $r['label'] }} ({{ rtrim(rtrim($r['rate'], '0'), '.') }}%):
                                        @forelse ($r['recipients'] as $p)
                                            {{ $p['name'] }} {{ $m($p['amount']) }}{{ $p['is_substitute'] ? ' (substitute)' : '' }}{{ $p['role_confirmed'] ? '' : ' (role provisional)' }}{{ $loop->last ? '' : ',' }}
                                        @empty
                                            <em>nobody recorded, unallocated</em>
                                        @endforelse
                                    </td><td class="is-num">{{ $m($r['share']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @empty
                <div class="oa-empty">No included job has an allocated verified collection.</div>
            @endforelse
            @foreach ($statement->calculation['blocked_jobs'] ?? [] as $bj)
                <div class="fin-inline-warning">Job {{ $bj['reference'] }} is included but has no calculation yet: {{ collect($bj['blockers'])->pluck('message')->implode(' ') }}</div>
            @endforeach
        </section>

        @if ($byPerson)
            <section class="oa-card oa-card-body" aria-labelledby="stPeopleTitle">
                <div class="oa-card-head"><div><h2 class="oa-card-title" id="stPeopleTitle"><i data-lucide="users" aria-hidden="true"></i> Personnel commission</h2></div></div>
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Person</th><th scope="col">Roles</th><th scope="col" class="is-num">Jobs</th><th scope="col" class="is-num">Commission</th></tr></thead>
                        <tbody>
                            @foreach (collect($byPerson)->sortBy('name') as $p)
                                <tr data-st-person="{{ $p['name'] }}"><td>{{ $p['name'] }}</td><td>{{ implode(', ', array_keys($p['roles'])) }}</td><td class="is-num">{{ $p['jobs'] }}</td><td class="is-num fin-strong">{{ $p['amount']->format() }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="oa-card oa-card-body" aria-labelledby="stHistTitle">
            <div class="oa-card-head"><div><h2 class="oa-card-title" id="stHistTitle"><i data-lucide="history" aria-hidden="true"></i> History</h2></div></div>
            <ul class="fin-trail">
                @foreach ($history as $h)<li>{{ $h->created_at?->format('M j, Y g:i A') }} · {{ $h->actor_label }} · {{ $h->description }}</li>@endforeach
            </ul>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/finance.js') }}?v={{ filemtime(public_path('superadmin/js/finance.js')) }}" defer></script>
@endpush
