{{-- Customer PDF styles (quotation, invoice, receipt). White A4, table layout and pt units for Dompdf. --}}
<style>
    @page { margin: 54pt 42pt 92pt 42pt; }
    body { margin: 0; background: #FFFFFF; color: #171717; font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.4; }
    table { border-collapse: collapse; }
    .pdf-bar { position: fixed; top: -22pt; left: 0; right: 0; height: 4pt; background: #FACC15; }
    .pdf-footer { position: fixed; bottom: -64pt; left: 0; right: 0; padding-top: 9pt; border-top: 0.75pt solid #737373; font-size: 9.5pt; font-weight: bold; color: #171717; }
    .pdf-head { width: 100%; }
    .pdf-head td { vertical-align: top; padding: 0; }
    .pdf-logo { width: 46pt; height: auto; }
    .pdf-company { font-size: 12.5pt; font-weight: bold; line-height: 1.3; color: #171717; }
    .pdf-company-sub { margin-top: 2pt; font-size: 9.25pt; color: #404040; line-height: 1.5; }
    .pdf-title { font-size: 25pt; font-weight: bold; letter-spacing: 0.06em; line-height: 1.1; color: #171717; text-align: right; }
    .pdf-meta { width: 100%; margin-top: 20pt; }
    .pdf-meta > tbody > tr > td { vertical-align: top; padding: 0; }
    .pdf-label { font-size: 8.5pt; font-weight: bold; letter-spacing: 0.12em; color: #404040; }
    .pdf-name { margin-top: 8pt; font-size: 12pt; font-weight: bold; color: #171717; word-wrap: break-word; }
    .pdf-contact { margin-top: 4pt; font-size: 9.75pt; line-height: 1.6; color: #404040; word-wrap: break-word; }
    .pdf-kv { width: 100%; }
    .pdf-kv td { padding: 6pt 0; border-bottom: 0.75pt solid #D4D4D4; font-size: 10pt; vertical-align: top; }
    .pdf-kv td.k { color: #404040; }
    .pdf-kv td.v { text-align: right; font-weight: bold; color: #171717; word-wrap: break-word; }
    .pdf-section { margin-top: 18pt; font-size: 8.5pt; font-weight: bold; letter-spacing: 0.12em; color: #171717; }
    .pdf-rows { width: 100%; margin-top: 7pt; border-top: 1.75pt solid #171717; }
    .pdf-rows td { padding: 6pt 0; border-bottom: 0.75pt solid #D4D4D4; font-size: 10pt; vertical-align: top; }
    .pdf-rows td.k { width: 120pt; color: #404040; padding-right: 10pt; }
    .pdf-rows td.v { color: #171717; word-wrap: break-word; }
    .pdf-rows td.b { font-weight: bold; }
    .pdf-lines { width: 100%; margin-top: 24pt; }
    .pdf-lines th { padding: 0 0 7pt; border-bottom: 1.75pt solid #171717; font-size: 8.5pt; font-weight: bold; letter-spacing: 0.1em; color: #171717; text-align: right; }
    .pdf-lines th.l, .pdf-lines td.l { text-align: left; }
    .pdf-lines td { padding: 9pt 0; border-bottom: 0.75pt solid #D4D4D4; vertical-align: top; font-size: 10pt; text-align: right; color: #171717; }
    .pdf-lines tr { page-break-inside: avoid; }
    .pdf-item-title { font-size: 10.5pt; font-weight: bold; color: #171717; word-wrap: break-word; }
    .pdf-item-detail { margin-top: 3pt; font-size: 9.5pt; line-height: 1.55; color: #404040; word-wrap: break-word; }
    .pdf-route { margin-top: 18pt; font-size: 9.75pt; color: #404040; }
    .pdf-route strong { color: #171717; }
    .pdf-totals { width: 100%; margin-top: 16pt; page-break-inside: avoid; }
    .pdf-totals > tbody > tr > td { padding: 0; vertical-align: top; }
    .pdf-sum { width: 100%; }
    .pdf-sum td { padding: 6pt 0; border-bottom: 0.75pt solid #D4D4D4; font-size: 10pt; color: #404040; }
    .pdf-sum td.n { text-align: right; font-weight: bold; color: #171717; }
    .pdf-total { width: 100%; margin-top: 8pt; background: #FACC15; }
    .pdf-total td { padding: 11pt 12pt; color: #171717; vertical-align: middle; }
    .pdf-total .tl { width: 98pt; font-size: 8.25pt; font-weight: bold; letter-spacing: 0.06em; white-space: nowrap; }
    .pdf-total .ta { text-align: right; font-size: 18pt; font-weight: bold; }
    .pdf-total.muted { background: #E5E5E5; }
    .pdf-amount { width: 100%; margin-top: 26pt; background: #FACC15; page-break-inside: avoid; }
    .pdf-amount.muted { background: #E5E5E5; }
    .pdf-amount td { padding: 15pt 18pt; color: #171717; vertical-align: middle; }
    .pdf-amount .al { font-size: 9.25pt; font-weight: bold; letter-spacing: 0.12em; }
    .pdf-amount .av { text-align: right; font-size: 27pt; font-weight: bold; line-height: 1.1; }
    .pdf-note { margin-top: 14pt; font-size: 9pt; color: #404040; }
    .pdf-terms { margin-top: 20pt; font-size: 10pt; color: #171717; }
</style>
