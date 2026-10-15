{{-- Main panel of the Owner viewers. The original issued PDF is the authoritative document; the preview is generated from stored records, labelled as such, and has no download. --}}
@if ($stage['switchable'])
    <nav class="dv-tabs" aria-label="Document view">
        <a href="{{ $stage['previewUrl'] }}" class="dv-tab {{ $stage['mode'] === 'preview' ? 'is-active' : '' }}" @if ($stage['mode'] === 'preview') aria-current="page" @endif>Modern preview</a>
        <a href="{{ $stage['originalUrl'] }}" class="dv-tab {{ $stage['mode'] === 'original' ? 'is-active' : '' }}" @if ($stage['mode'] === 'original') aria-current="page" @endif>Original issued PDF</a>
    </nav>
@endif

@if ($stage['mode'] === 'original')
    <p class="dv-source">Original issued PDF — shown exactly as it was issued. This is the authoritative {{ $noun }}.</p>
    @if ($stage['explanation'])
        <p class="dv-source is-warning">{{ $stage['explanation'] }}</p>
    @endif
    <iframe class="dv-embed" src="{{ $viewUrl }}" title="Original {{ $noun }} PDF {{ $doc['number'] }}"></iframe>
@else
    <p class="dv-source is-warning">Preview generated from records — not the original issued PDF.</p>
    @unless ($pdfReady)
        <p class="dv-source">No original PDF is on file for this {{ $noun }}, so only this preview is available.</p>
    @endunless
    <div class="dv-paper is-preview" data-preview="{{ $noun }}">
        @include('documents.partials.' . $noun . '-sheet', ['doc' => $doc])
    </div>
    <p class="dv-print-note">The preview is not an issued document and is not printed. Open the original issued PDF instead.</p>
@endif

@include('superadmin.documents._summary', ['facts' => $facts])
