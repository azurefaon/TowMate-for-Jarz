{{-- Shown only when a stored OFFICIAL MODERN PDF exists for this document. Streams the stored file; nothing is generated here. --}}
<div class="dv-block">
    <div class="dv-label">PDF</div>
    <div class="dv-actions">
        <a class="dv-btn is-primary" href="{{ $pdfUrl }}" target="_blank" rel="noopener noreferrer">View PDF</a>
        <a class="dv-btn" href="{{ $pdfUrl }}?download=1">Download PDF</a>
        <button type="button" class="dv-btn" data-print-pdf="{{ $pdfUrl }}">Print PDF</button>
    </div>
</div>
