{{-- A4 document styles. Table layout and pt units only: renders identically in Dompdf and in the Owner viewers. --}}
<style>
    @page { margin: 0; }
    .doc-sheet { box-sizing: border-box; width: 100%; background: #FFFFFF; color: #171717; font-family: 'IBM Plex Sans', 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 9.75pt; line-height: 1.4; }
    .doc-sheet table { border-collapse: collapse; }
    .doc-pad { padding: 36pt 42pt 84pt; }
    .doc-topbar { height: 12pt; background: #FACC15; }
    .doc-head { width: 100%; }
    .doc-head td { vertical-align: top; padding: 0; }
    .doc-logo { width: 42pt; height: auto; }
    .doc-company { font-size: 12.75pt; font-weight: bold; line-height: 1.3; color: #171717; }
    .doc-company-sub { font-size: 9.4pt; color: #404040; line-height: 1.35; }
    .doc-title { font-size: 22.5pt; font-weight: bold; letter-spacing: 0.04em; color: #171717; line-height: 1.1; text-align: right; }
    .doc-title-cell { text-align: right; }
    .doc-badge { display: inline-block; padding: 3pt 7.5pt; border-radius: 3pt; font-size: 9.4pt; font-weight: bold; color: #FFFFFF; }
    .doc-bar { margin-top: 11pt; height: 3pt; background: #FACC15; }
    .doc-note { margin-top: 15pt; padding: 9pt 12pt; border: 0.75pt solid #171717; border-radius: 3pt; background: #F5F5F5; font-size: 9.75pt; font-weight: bold; color: #171717; }
    .doc-meta { width: 100%; margin-top: 16pt; }
    .doc-meta > tbody > tr > td { vertical-align: top; padding: 0; }
    .doc-label { font-size: 8.25pt; font-weight: bold; letter-spacing: 0.1em; color: #525252; }
    .doc-name { margin-top: 6pt; font-size: 11.25pt; font-weight: bold; color: #171717; }
    .doc-contact { margin-top: 3pt; font-size: 9.75pt; line-height: 1.6; color: #404040; }
    .doc-kv { width: 225pt; }
    .doc-kv td { padding: 5pt 0; border-bottom: 0.75pt solid #E5E5E5; font-size: 9.75pt; }
    .doc-kv td.k { width: 98pt; color: #525252; }
    .doc-kv td.v { font-weight: bold; color: #171717; }
    .doc-mono { font-family: 'DejaVu Sans Mono', 'IBM Plex Mono', monospace; }
    .doc-items { width: 100%; margin-top: 22pt; }
    .doc-items th { padding: 0 0 7.5pt; border-bottom: 1.5pt solid #171717; font-size: 8.25pt; font-weight: bold; letter-spacing: 0.1em; color: #171717; text-align: right; }
    .doc-items th.l, .doc-items td.l { text-align: left; }
    .doc-items td { padding: 9pt 0; border-bottom: 0.75pt solid #E5E5E5; vertical-align: top; font-size: 10pt; text-align: right; color: #171717; }
    .doc-item-title { font-size: 10.5pt; font-weight: bold; color: #171717; }
    .doc-item-detail { margin-top: 3pt; font-size: 9.4pt; line-height: 1.55; color: #404040; }
    .doc-totals { width: 100%; margin-top: 18pt; }
    .doc-totals > tbody > tr > td { padding: 0; vertical-align: top; }
    .doc-sum { width: 240pt; }
    .doc-sum td { padding: 5pt 0; font-size: 10pt; color: #404040; }
    .doc-sum td.n { text-align: right; font-weight: bold; color: #171717; }
    .doc-total { margin-top: 9pt; width: 240pt; border-radius: 3pt; }
    .doc-total td { padding: 10pt 13pt; }
    .doc-total .tl { font-size: 9.4pt; font-weight: bold; letter-spacing: 0.08em; color: #FFFFFF; }
    .doc-total .ta { text-align: right; font-size: 19.5pt; font-weight: bold; }
    .doc-pay { margin-top: 24pt; padding-top: 9pt; border-top: 0.75pt solid #E5E5E5; font-size: 9.4pt; color: #404040; line-height: 1.6; }
    .doc-pay strong { color: #171717; }
    .doc-amount { margin-top: 18pt; padding: 15pt 24pt; border-radius: 3pt; color: #171717; }
    .doc-amount .al { font-size: 9.4pt; font-weight: bold; letter-spacing: 0.08em; }
    .doc-amount .av { margin-top: 6pt; font-size: 39pt; font-weight: bold; line-height: 1.05; }
    .doc-rows { width: 100%; margin-top: 20pt; }
    .doc-rows th { padding: 0 0 6pt; border-bottom: 1.5pt solid #171717; font-size: 8.25pt; font-weight: bold; letter-spacing: 0.1em; text-align: left; color: #171717; }
    .doc-rows td { padding: 5.5pt 0; border-bottom: 0.75pt solid #E5E5E5; font-size: 10.1pt; vertical-align: top; }
    .doc-rows td.k { width: 142pt; color: #525252; }
    .doc-rows td.v { font-weight: bold; color: #171717; }
    .doc-rows td.muted { font-weight: normal; color: #525252; }
    .doc-footer { position: fixed; left: 42pt; right: 42pt; bottom: 30pt; padding-top: 10pt; border-top: 0.75pt solid #E5E5E5; }
    .doc-footer .f1 { font-size: 9.75pt; font-weight: bold; color: #171717; }
    .doc-footer .f2 { margin-top: 2pt; font-size: 9pt; color: #525252; }
    .doc-signoff { width: 100%; margin-top: 14pt; }
    .doc-signoff td { vertical-align: bottom; padding: 0; }
    .doc-signoff-sig { width: 150pt; text-align: center; }
    .doc-signoff-pay .doc-pay { margin-top: 0; }
    .doc-signature { max-height: 36pt; max-width: 140pt; }
    .doc-signature-blank { height: 30pt; }
    .doc-signature-line { margin-top: 3pt; padding-top: 3pt; border-top: 0.75pt solid #171717; }
    .doc-signature-name { font-size: 9.4pt; font-weight: bold; color: #171717; }
    .doc-signature-role { font-size: 8.5pt; color: #525252; }
    .doc-accreditation { max-width: 40pt; max-height: 34pt; }
    .doc-breakdown-note { margin-top: 12pt; font-size: 9pt; color: #525252; }
</style>
