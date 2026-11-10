<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Owner Finance — Client Review Summary</title>
    <style>
        body { margin: 0; font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; color: #111; font-size: 10.5px; background: #fff; }
        .sheet { padding: 22px 28px; max-width: 900px; margin: 0 auto; }
        .brand td { vertical-align: middle; }
        .brand img { width: 52px; height: 52px; }
        .brand .name { font-size: 17px; font-weight: bold; margin: 0; letter-spacing: 1px; }
        h1 { font-size: 15px; margin: 12px 0 4px; }
        .note { color: #555; font-size: 9.5px; margin: 2px 0 10px; }
        .policy { border: 1px solid #b45309; color: #92400e; padding: 6px 8px; margin-bottom: 10px; }
        .demo { border: 2px solid #b91c1c; color: #b91c1c; padding: 6px 8px; margin-bottom: 10px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        .item { margin-bottom: 10px; page-break-inside: avoid; }
        .item th, .item td { border: 1px solid #c4c4c4; padding: 4px 6px; text-align: left; vertical-align: top; }
        .item th { width: 165px; background: #f4f4f4; font-weight: bold; }
        .item .head td { background: #facc15; font-weight: bold; }
        .summary td { padding: 3px 6px; border-bottom: 1px solid #e3e3e3; }
        .footer { margin-top: 12px; color: #777; font-size: 8.5px; }
        .toolbar { background: #111; padding: 10px 16px; display: flex; gap: 10px; font-family: Arial, sans-serif; }
        .toolbar a, .toolbar button { color: #111; background: #fff; border: 0; padding: 6px 12px; border-radius: 4px; font-size: 13px; text-decoration: none; cursor: pointer; }
        @media print { .toolbar { display: none; } .sheet { padding: 0; } @page { size: A4; margin: 14mm; } }
    </style>
</head>
<body>
@unless ($forPdf)
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <a href="{{ route('superadmin.finance.client-review.pdf') }}">Download PDF</a>
        <a href="{{ route('superadmin.finance.client-review.index') }}">Back to Client Review</a>
    </div>
@endunless
<div class="sheet">
    <table class="brand"><tr>
        @if (! empty($settings['document_logo_url']))<td style="width:62px"><img src="{{ $settings['document_logo_url'] }}" alt="Company logo"></td>@endif
        <td><p class="name">{{ $settings['company_name'] ?? 'TowMate' }}</p></td>
    </tr></table>
    <h1>OWNER FINANCE — CLIENT REVIEW SUMMARY</h1>
    <p class="note">Decisions the client must make before commission statements can be approved for real, plus requested revisions and remarks. Recording an answer does not change any rule; a programmer implements it.</p>
    @if (\App\Services\Finance\Demo\FinanceDemoGuard::enabled())
        <div class="demo">DEMONSTRATION DATABASE — sample records only. Not real financial records.</div>
    @endif
    <div class="policy">Commission policy in use: v{{ $policy?->version }} — {{ $policy?->name }}.
        @if ($policy && $policy->base_definition === \App\Services\Finance\Demo\FinanceDemoScenarios::STAND_IN) DEMONSTRATION stand-in, not a client confirmation.
        @elseif ($policy && $policy->status === 'active' && $policy->isConfirmed()) Active and client-confirmed.
        @else Draft / Pending Client Confirmation. @endif</div>

    <table class="summary">
        @foreach (\App\Models\Finance\ClientReviewItem::STATUSES as $key => $label)
            <tr><td>{{ $label }}</td><td style="text-align:right" data-crs-count="{{ $key }}">{{ $items->where('status', $key)->count() }}</td></tr>
        @endforeach
    </table>
    <br>
    @foreach ($items as $item)
        <table class="item" data-crs-item="{{ $item->reference_code }}">
            <tr class="head"><td colspan="2">{{ $item->reference_code }} — {{ $item->title }} ({{ $item->kindLabel() }}; {{ $item->statusLabel() }})</td></tr>
            @if ($item->question)<tr><th>Decision needed</th><td>{{ $item->question }}</td></tr>@endif
            @if ($item->current_behaviour)<tr><th>Current behaviour</th><td>{{ $item->current_behaviour }}</td></tr>@endif
            @if ($item->blocks)<tr><th>Blocked until answered</th><td>{{ $item->blocks }}</td></tr>@endif
            <tr><th>Client response</th><td>{{ $item->client_response ?: '—' }}</td></tr>
            @if ($item->requested_revision)<tr><th>Requested revision</th><td>{{ $item->requested_revision }}</td></tr>@endif
            @if ($item->owner_remarks)<tr><th>Owner remarks</th><td>{{ $item->owner_remarks }}</td></tr>@endif
        </table>
    @endforeach
    <p class="footer">Generated {{ ($generatedAt ?? now())->format('F j, Y g:i A') }}{{ ! empty($generatedBy) ? ' by ' . $generatedBy : '' }}.</p>
</div>
</body>
</html>
