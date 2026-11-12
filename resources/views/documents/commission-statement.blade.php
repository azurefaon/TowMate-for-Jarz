@php
    $m = fn ($v) => \App\Support\Money::of((string) $v)->format();
    $lines = $calc['lines'] ?? [];
    $rate = fn ($r) => rtrim(rtrim((string) $r, '0'), '.') . '%';
    $roleLabels = \App\Services\Finance\CommissionCalculator::ROLE_LABELS;
    $policyConfirmed = (bool) \App\Models\Finance\CommissionPolicy::find($policy['id'] ?? 0)?->isClientConfirmedActive();
    $statusText = [
        'draft' => 'DRAFT — NOT APPROVED',
        'for_review' => 'FOR REVIEW — NOT APPROVED',
        'approved' => 'APPROVED',
        'paid' => 'APPROVED AND PAID',
        'voided' => 'VOIDED — NOT VALID FOR PAYMENT',
    ][$statement->status] ?? strtoupper($statement->status);
    // Personnel totals from the same calculation the statement shows (the approval snapshot once approved).
    $people = [];
    foreach ($lines as $l) {
        foreach ($l['roles'] as $roleKey => $r) {
            foreach ($r['recipients'] as $p) {
                $k = $p['recipient_key'] . '|' . $roleKey;
                $people[$k] ??= ['name' => $p['name'], 'role' => $r['label'], 'rate' => $r['rate'], 'amount' => \App\Support\Money::zero(), 'jobs' => 0, 'substitute' => false];
                $people[$k]['amount'] = $people[$k]['amount']->plus($p['amount']);
                $people[$k]['jobs']++;
                $people[$k]['substitute'] = $people[$k]['substitute'] || ! empty($p['is_substitute']);
            }
        }
    }
    uasort($people, fn ($a, $b) => [$a['name'], $a['role']] <=> [$b['name'], $b['role']]);
    $recordedPayouts = $payouts ? collect($payouts['history'])->where('status', 'recorded') : collect();
    $voidedPayouts = $payouts ? collect($payouts['history'])->where('status', 'voided') : collect();
    $isVoided = $statement->status === 'voided';   // a voided statement keeps its figures but states no payment obligation
    $payableLabel = $isVoided ? 'Total personnel commission (voided, not payable)' : 'Total personnel commission payable';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Commission Statement {{ $statement->reference_code }}</title>
    <style>
        body { margin: 0; font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; color: #111; font-size: 11px; background: #fff; }
        .sheet { padding: 22px 28px; max-width: 900px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; }
        .brand td { vertical-align: middle; }
        .brand img { width: 58px; height: 58px; }
        .brand .name { font-size: 18px; font-weight: bold; letter-spacing: 1px; margin: 0; }
        .brand .sub { margin: 2px 0 0; color: #555; font-size: 10px; }
        h1 { font-size: 16px; margin: 16px 0 2px; letter-spacing: .5px; }
        h2 { font-size: 12px; margin: 13px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #999; text-transform: uppercase; letter-spacing: .5px; }
        .status { display: inline-block; padding: 3px 10px; border-radius: 10px; background: #404040; color: #fff; font-weight: bold; font-size: 11px; margin-top: 4px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .status.ok { background: #166534; }
        .status.review { background: #1d4ed8; }
        .status.void { background: #b91c1c; }
        .meta td { padding: 3px 6px 3px 0; vertical-align: top; }
        .meta td.k { color: #555; width: 150px; }
        .grid th, .grid td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; vertical-align: top; }
        .grid th { background: #f0f0f0; font-size: 10px; text-transform: uppercase; }
        .num { text-align: right !important; white-space: nowrap; }
        .total td { font-weight: bold; background: #fafafa; }
        .note { color: #555; font-size: 10px; margin: 4px 0; }
        .warn-box { border: 1px solid #b45309; color: #78350f; padding: 6px 8px; margin: 10px 0; }
        .summary td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
        .summary td.num { font-weight: bold; }
        .summary tr.key td { font-size: 12px; background: #f5f5f5; }
        .sign td { padding-top: 22px; width: 50%; }
        .sign, .footer { page-break-inside: avoid; }
        .sign .line { border-top: 1px solid #111; padding-top: 3px; width: 85%; }
        .footer { margin-top: 8px; color: #555; font-size: 9px; }
        .toolbar { background: #111; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; font-family: Arial, sans-serif; }
        .toolbar a, .toolbar button { color: #111; background: #fff; border: 0; padding: 6px 12px; border-radius: 4px; font-size: 13px; text-decoration: none; cursor: pointer; }
        @media print { .toolbar { display: none; } .sheet { padding: 0; } @page { size: A4; margin: 14mm; } }
    </style>
</head>
<body>
@unless ($forPdf)
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <a href="{{ route('superadmin.finance.statements.pdf', $statement->id) }}">Download PDF</a>
        <a href="{{ route('superadmin.finance.statements.show', $statement->id) }}">Back to statement</a>
    </div>
@endunless
<div class="sheet" id="commissionStatementDocument">
    <table class="brand">
        <tr>
            @if (! empty($settings['document_logo_url']))<td style="width:70px"><img src="{{ $settings['document_logo_url'] }}" alt="Company logo"></td>@endif
            <td>
                <p class="name">{{ $settings['company_name'] ?? 'TowMate' }}</p>
                <p class="sub">{{ $settings['company_address'] ?? '' }} · {{ $settings['company_phone'] ?? '' }} · {{ $settings['company_email'] ?? '' }}</p>
            </td>
        </tr>
    </table>

    <h1>WEEKLY COMMISSION STATEMENT</h1>
    @if (\App\Services\Finance\Demo\FinanceDemoGuard::enabled())<div style="border:2px solid #b91c1c;color:#b91c1c;padding:5px 8px;margin:6px 0;font-weight:bold" data-doc-demo>DEMONSTRATION DATA — sample records, not real financial records; the commission policy is a demo stand-in.</div>@endif
    <span class="status {{ ['draft' => '', 'for_review' => 'review', 'approved' => 'ok', 'paid' => 'ok', 'voided' => 'void'][$statement->status] ?? '' }}" data-doc-status="{{ $statement->status }}">{{ $statusText }}</span>

    <table class="meta" style="margin-top:10px">
        <tr><td class="k">Statement number</td><td><strong data-doc-ref>{{ $statement->reference_code }}</strong> (revision {{ $statement->revision }})</td>
            <td class="k">Towing unit</td><td><strong>{{ $statement->unit?->name ?? '—' }}</strong></td></tr>
        <tr><td class="k">Period</td><td>{{ $statement->period_start->format('F j, Y') }} – {{ $statement->period_end->format('F j, Y') }} (Monday to Saturday)</td>
            <td class="k">Commission policy</td><td>Version {{ $policy['version'] ?? '—' }} — {{ \App\Models\Finance\CommissionPolicy::labelForId($policy['id'] ?? null) }}</td></tr>
        @if ($statement->supersedes)
            <tr><td class="k">Corrects</td><td colspan="3">{{ $statement->supersedes->reference_code }} — {{ $statement->correction_reason }}</td></tr>
        @endif
    </table>

    @if (! $final)
        <div class="warn-box">This statement is not approved. Amounts may change and must not be paid.
            @if ($review && $review['blockers']) Outstanding issues: {{ count($review['blockers']) }}. @endif</div>
    @elseif ($statement->status === 'voided')
        <div class="warn-box">Voided on {{ $statement->voided_at?->format('F j, Y g:i A') }}: {{ $statement->void_reason }}. This statement is kept for the record and is not valid for payment.</div>
    @endif

    <h2>Summary</h2>
    <table class="summary">
        <tr><td>Verified customer collections</td><td class="num" data-doc-total="collection">{{ $m($statement->total_collection) }}</td></tr>
        <tr><td>Less: approved expenses</td><td class="num" data-doc-total="expenses">{{ $m($statement->total_expenses) }}</td></tr>
        <tr><td>Less: approved deductions</td><td class="num" data-doc-total="deductions">{{ $m($statement->total_deductions) }}</td></tr>
        <tr class="key"><td><strong>Sharing base</strong></td><td class="num" data-doc-total="base">{{ $m($statement->total_base) }}</td></tr>
        <tr><td>Company share (retained by the company, not a payout)</td><td class="num" data-doc-total="company">{{ $m($statement->company_amount) }}</td></tr>
        <tr class="key"><td><strong>{{ $payableLabel }}</strong></td><td class="num" data-doc-total="personnel">{{ $m($statement->personnel_amount) }}</td></tr>
        <tr><td>Unallocated / pending (not payable)</td><td class="num" data-doc-total="unallocated">{{ $m($statement->unallocated_amount) }}</td></tr>
    </table>
    <p class="note">Rates (each applied to the same sharing base):
        @foreach (\App\Services\Finance\CommissionCalculator::inRoleOrder($policy['rates'] ?? []) as $role => $r){{ $roleLabels[$role] ?? $role }} {{ $rate($r) }}{{ $loop->last ? '.' : ', ' }}@endforeach
        Shares are rounded to the centavo; any rounding remainder is assigned to the company share.</p>

    <h2>Included bookings</h2>
    <table class="grid">
        <thead><tr><th>Booking</th><th>Service date</th><th class="num">Verified collection</th><th class="num">Expenses</th><th class="num">Deductions</th><th class="num">Sharing base</th><th class="num">Company</th></tr></thead>
        <tbody>
            @forelse ($lines as $l)
                <tr data-doc-booking="{{ $l['reference'] }}">
                    <td>{{ $l['reference'] }}@if ($l['group_code'])<br><span class="note">Group {{ $l['group_code'] }}</span>@endif</td>
                    <td>{{ \Carbon\Carbon::parse($l['service_date'])->format('M j, Y') }}</td>
                    <td class="num">{{ $m($l['collection']) }}</td>
                    <td class="num">{{ $m($l['expense_total']) }}</td>
                    <td class="num">{{ $m($l['deduction_total']) }}</td>
                    <td class="num">{{ $m($l['base']) }}</td>
                    <td class="num">{{ $m($l['company']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No booking with a verified, allocated collection.</td></tr>
            @endforelse
        </tbody>
    </table>

    @php $sources = collect($lines)->flatMap(fn ($l) => collect($l['expenses'])->map(fn ($e) => ['ref' => $l['reference'], 'type' => 'Expense: ' . $e['category'], 'doc' => $e['reference'], 'amount' => $e['amount']])
        ->merge(collect($l['deductions'])->map(fn ($d) => ['ref' => $l['reference'], 'type' => 'Deduction: ' . $d['type'], 'doc' => $d['reference'], 'amount' => $d['amount']]))); @endphp
    @if ($sources->isNotEmpty())
        <h2>Approved expenses and deductions</h2>
        <table class="grid">
            <thead><tr><th>Booking</th><th>Item</th><th>Reference</th><th class="num">Amount</th></tr></thead>
            <tbody>
                @foreach ($sources as $src)
                    <tr><td>{{ $src['ref'] }}</td><td>{{ $src['type'] }}</td><td>{{ $src['doc'] }}</td><td class="num">{{ $m($src['amount']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Personnel commission</h2>
    <table class="grid">
        <thead><tr><th>Name</th><th>Role performed</th><th class="num">Rate</th><th class="num">Jobs</th><th class="num">Amount</th></tr></thead>
        <tbody>
            @forelse ($people as $p)
                <tr data-doc-person="{{ $p['name'] }}|{{ $p['role'] }}">
                    <td>{{ $p['name'] }}{{ $p['substitute'] ? ' (substitute)' : '' }}</td><td>{{ $p['role'] }}</td><td class="num">{{ $rate($p['rate']) }}</td><td class="num">{{ $p['jobs'] }}</td><td class="num">{{ $p['amount']->format() }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No personnel recorded.</td></tr>
            @endforelse
            @foreach (collect($lines)->flatMap(fn ($l) => collect($l['roles'])->filter(fn ($r) => \App\Support\Money::of($r['unallocated'])->isPositive())->map(fn ($r) => ['ref' => $l['reference'], 'label' => $r['label'], 'rate' => $r['rate'], 'amount' => $r['unallocated']])) as $u)
                <tr data-doc-unallocated><td><em>Unallocated (nobody recorded) — {{ $u['ref'] }}</em></td><td>{{ $u['label'] }}</td><td class="num">{{ $rate($u['rate']) }}</td><td class="num">—</td><td class="num">{{ $m($u['amount']) }}</td></tr>
            @endforeach
            <tr class="total"><td colspan="4">{{ $payableLabel }}</td><td class="num">{{ $m($statement->personnel_amount) }}</td></tr>
        </tbody>
    </table>

    <h2>Approval</h2>
    <table class="meta">
        <tr><td class="k">Status</td><td>{{ $statement->statusLabel() }}</td></tr>
        @if ($statement->approved_at)
            <tr><td class="k">Approved by</td><td data-doc-approver>{{ $statement->approved_by_name }} on {{ $statement->approved_at->format('F j, Y g:i A') }}</td></tr>
            <tr><td class="k">Snapshot hash</td><td style="font-family: monospace; font-size: 9px;">{{ $statement->approval_hash }}</td></tr>
        @else
            <tr><td class="k">Approved by</td><td>Not approved</td></tr>
        @endif
        @if ($statement->paid_at)
            <tr><td class="k">Marked paid by</td><td>{{ $statement->paid_by_name }} on {{ $statement->paid_at->format('F j, Y g:i A') }}</td></tr>
        @endif
    </table>

    @if ($payouts)
        <h2>Payouts recorded</h2>
        @if ($recordedPayouts->isEmpty())
            @if ($isVoided)
                <p class="note">This statement is voided, so no payment is due and none can be recorded against it.</p>
            @else
                <p class="note">No payouts recorded yet. Remaining payable: {{ $payouts['remaining']->format() }}.</p>
            @endif
        @else
            <table class="grid">
                <thead><tr><th>Payout</th><th>Recipient</th><th>Role(s)</th><th>Date paid</th><th>Method</th><th>Reference</th><th>Recorded by</th><th class="num">Amount</th></tr></thead>
                <tbody>
                    @foreach ($recordedPayouts as $po)
                        <tr data-doc-payout="{{ $po->reference_code }}">
                            <td>{{ $po->reference_code }}</td><td>{{ $po->recipient_name }}</td><td>{{ $po->recipient_roles }}</td>
                            <td>{{ $po->paid_at?->format('M j, Y') }}</td><td>{{ $po->methodLabel() }}</td>
                            <td>{{ $po->external_reference ?: ($po->evidence_path ? 'Evidence on file' : '—') }}</td><td>{{ $po->recorded_by_name }}</td>
                            <td class="num">{{ $m($po->amount) }}</td>
                        </tr>
                    @endforeach
                    @if ($isVoided)
                        <tr class="total"><td colspan="7">Total paid (statement voided; nothing remains payable)</td><td class="num">{{ $payouts['paid']->format() }}</td></tr>
                    @else
                        <tr class="total"><td colspan="7">Total paid / remaining payable</td><td class="num">{{ $payouts['paid']->format() }} / {{ $payouts['remaining']->format() }}</td></tr>
                    @endif
                </tbody>
            </table>
        @endif
        @if ($voidedPayouts->isNotEmpty())
            <p class="note">Voided payouts (kept for the record):
                @foreach ($voidedPayouts as $po){{ $po->reference_code }} — {{ $po->recipient_name }} {{ $m($po->amount) }} ({{ $po->void_reason }}){{ $loop->last ? '.' : '; ' }}@endforeach</p>
        @endif
        @unless ($isVoided)
            <p class="note">Payouts are recorded after the Owner pays each person by cash, GCash or bank transfer. TowMate does not transfer money.</p>
        @endunless
    @endif

    <table class="sign">
        <tr><td><div class="line">Prepared / approved by (Owner)</div></td><td><div class="line">Received by</div></td></tr>
    </table>

    <p class="footer">Generated {{ ($generatedAt ?? now())->format('F j, Y g:i A') }}{{ ! empty($generatedBy) ? ' by ' . $generatedBy : '' }}. Amounts in Philippine pesos.
        {{ $final ? 'Figures are taken from the frozen approval snapshot.' : 'Figures are a draft calculation.' }}</p>
</div>
</body>
</html>
