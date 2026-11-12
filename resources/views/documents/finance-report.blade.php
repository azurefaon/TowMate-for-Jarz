<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Finance Report {{ $r['meta']['from']->format('M j, Y') }} – {{ $r['meta']['to']->format('M j, Y') }}</title>
    <style>
        body { margin: 0; font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; color: #111; font-size: 10px; background: #fff; }
        .sheet { padding: 20px 26px; max-width: 1000px; margin: 0 auto; }
        .brand td { vertical-align: middle; }
        .brand img { width: 52px; height: 52px; }
        .brand .name { font-size: 17px; font-weight: bold; letter-spacing: 1px; margin: 0; }
        .brand .sub { margin: 2px 0 0; color: #555; font-size: 9px; }
        h1 { font-size: 15px; margin: 12px 0 6px; letter-spacing: .5px; }
        table { width: 100%; border-collapse: collapse; }
        .fr-meta { border: 1px solid #ccc; padding: 6px 8px; margin-bottom: 6px; line-height: 1.55; }
        .fr-h { font-size: 11px; margin: 14px 0 5px; padding-bottom: 3px; border-bottom: 1px solid #999; text-transform: uppercase; letter-spacing: .4px; page-break-after: avoid; }
        .fr-sub { margin: 5px 0 2px; }
        .fr-list { margin: 0 0 4px 16px; padding: 0; }
        .fr-sum td { padding: 3px 6px; border-bottom: 1px solid #e3e3e3; }
        .fr-sum tr.fr-key td { font-weight: bold; background: #f4f4f4; }
        .fr-grid { margin-top: 6px; }
        .fr-grid th, .fr-grid td { border: 1px solid #c4c4c4; padding: 3px 5px; text-align: left; vertical-align: top; }
        .fr-grid th { background: #facc15; font-size: 9px; text-transform: uppercase; }
        .fr-grid tr { page-break-inside: avoid; }
        .fr-total td { font-weight: bold; background: #fafafa; }
        .fr-num { text-align: right !important; white-space: nowrap; }
        .fr-dim { color: #666; font-size: 9px; }
        .footer { margin-top: 12px; color: #555; font-size: 8px; }
        .toolbar { background: #111; color: #fff; padding: 10px 16px; display: flex; gap: 10px; font-family: Arial, sans-serif; }
        .toolbar a, .toolbar button { color: #111; background: #fff; border: 0; padding: 6px 12px; border-radius: 4px; font-size: 13px; text-decoration: none; cursor: pointer; }
        @media print { .toolbar { display: none; } .sheet { padding: 0; } @page { size: A4 landscape; margin: 12mm; } }
    </style>
</head>
<body>
@unless ($forPdf)
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <a href="{{ route('superadmin.finance.reports.pdf', $query) }}">Download PDF</a>
        <a href="{{ route('superadmin.finance.reports.xlsx', $query) }}">Download Excel</a>
        <a href="{{ route('superadmin.finance.reports.index', $query) }}">Back to Finance Reports</a>
    </div>
@endunless
<div class="sheet" id="financeReportDocument">
    <table class="brand">
        <tr>
            @if (! empty($settings['document_logo_url']))<td style="width:62px"><img src="{{ $settings['document_logo_url'] }}" alt="Company logo"></td>@endif
            <td><p class="name">{{ $settings['company_name'] ?? 'TowMate' }}</p><p class="sub">{{ $settings['company_address'] ?? '' }} · {{ $settings['company_phone'] ?? '' }}</p></td>
        </tr>
    </table>
    <h1>FINANCE REPORT</h1>
    @if (\App\Services\Finance\Demo\FinanceDemoGuard::enabled())<div style="border:2px solid #b91c1c;color:#b91c1c;padding:5px 8px;margin:6px 0;font-weight:bold" data-doc-demo>DEMONSTRATION DATA — sample records, not real financial records; the commission policy is a demo stand-in.</div>@endif
    @include('documents.partials.finance-report-body', ['r' => $r])
    <p class="footer">Generated {{ ($generatedAt ?? now())->format('F j, Y g:i A') }}{{ ! empty($generatedBy) ? ' by ' . $generatedBy : '' }}. Amounts in Philippine pesos.</p>
</div>
</body>
</html>
