{{-- Capstone demo documents must never pass for a real financial document. --}}
@if (! empty($doc['demo']))
    <div class="doc-demo-banner" style="margin: 0 0 10pt; padding: 8pt 10pt; border: 2pt solid #B91C1C; color: #B91C1C; background: #FEF2F2; font-weight: 700; font-size: 11pt; text-align: center; letter-spacing: .5pt;">
        TEST / DEMO — NO REAL MONEY. Not a valid {{ $kind ?? 'financial document' }}.
    </div>
@endif
