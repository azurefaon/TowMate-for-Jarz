@extends('layouts.superadmin')

@section('title', 'Invoice ' . $doc['number'])

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('superadmin/css/documents.css') }}?v={{ filemtime(public_path('superadmin/css/documents.css')) }}">
    @include('documents.partials.doc-styles')
@endpush

@section('content')
    @php
        $badges = ['current' => ['Current', '#166534'], 'replaced' => ['Replaced', '#6D28D9'], 'voided' => ['Voided', '#B91C1C'], 'issued' => ['Issued', '#525252']];
    @endphp
    <div class="dv-page">
        <div class="dv-top">
            <div class="dv-title">
                <h1>Invoice</h1>
                <span class="dv-badge" style="background: {{ $doc['status_bg'] }}">{{ $doc['status_label'] }}</span>
                <span class="dv-badge is-ink">Owner · Read-only</span>
            </div>
            <a class="dv-back" href="{{ route('superadmin.bookings.index', ['booking' => $booking->job_code]) }}">← Back to Booking</a>
        </div>

        <div class="dv-grid">
            <div class="dv-stage">
                @if ($pdfReady)
                    <p class="dv-source">Issued document — the original invoice PDF exactly as it was issued.</p>
                    <iframe class="dv-embed" src="{{ $viewUrl }}" title="Original invoice PDF {{ $doc['number'] }}"></iframe>
                @else
                    <p class="dv-source is-warning">No issued PDF is on file for this invoice. The preview below is rendered from the invoice records and is not the issued document.</p>
                    <div class="dv-paper">
                        @include('documents.partials.invoice-sheet', ['doc' => $doc])
                    </div>
                @endif

                @include('superadmin.documents._summary', ['facts' => array_values(array_filter([
                    ['Status', $doc['status_label'] . ' · Version ' . $doc['version'] . ' of ' . $doc['versions']],
                    ['Invoice number', $doc['number']],
                    ['Issued', $doc['issued_at']],
                    ['Billed to', $doc['customer']['name']],
                    ['Booking', $doc['booking_ref']],
                    ['Total (stored)', $doc['total']],
                    ['Document', $pdfReady ? 'Original issued PDF' : 'No issued PDF on file'],
                    $doc['note'] ? ['Note', $doc['note']] : null,
                ])), 'viewUrl' => $pdfReady ? $viewUrl : null])
            </div>

            <aside class="dv-aside" aria-label="Invoice details">
                <div class="dv-block">
                    <div class="dv-label">DOCUMENT</div>
                    <dl class="dv-facts">
                        <dt>Version</dt><dd>{{ $doc['version'] }} of {{ $doc['versions'] }}</dd>
                        <dt>Issued</dt><dd>{{ $doc['issued_at'] }}</dd>
                        <dt>Total</dt><dd>{{ $doc['total'] }}</dd>
                        @if ($doc['replaces'])<dt>Replaces</dt><dd class="dv-mono">{{ $doc['replaces'] }}</dd>@endif
                        @if ($doc['replaced_by'])<dt>Replaced by</dt><dd class="dv-mono">{{ $doc['replaced_by'] }}</dd>@endif
                        @if ($doc['void_reason'])<dt>Reason</dt><dd>{{ $doc['void_reason'] }}</dd>@endif
                        <dt>Source</dt><dd>{{ $pdfReady ? 'Original issued PDF' : 'Rendered from records' }}</dd>
                    </dl>
                </div>

                <div class="dv-block">
                    <div class="dv-label">PDF</div>
                    <div class="dv-actions">
                        @if ($pdfReady)
                            <a class="dv-btn is-primary" href="{{ $viewUrl }}" target="_blank" rel="noopener noreferrer">View PDF</a>
                            <a class="dv-btn" href="{{ $viewUrl }}?download=1">Download PDF</a>
                        @else
                            <span class="dv-btn is-disabled" role="note">PDF not available</span>
                        @endif
                    </div>
                </div>

                @include('superadmin.documents._email', ['email' => $doc['email']])

                <div class="dv-block">
                    <div class="dv-label">INVOICE HISTORY</div>
                    <ul class="dv-list">
                        @foreach ($history as $entry)
                            @php($badge = $badges[$entry['state']])
                            <li class="dv-item {{ $entry['id'] === $viewing ? 'is-viewing' : '' }}">
                                <div class="dv-item-row">
                                    <span class="dv-mono">{{ $entry['number'] }}</span>
                                    <span class="dv-badge dv-small-badge" style="background: {{ $badge[1] }}">{{ $badge[0] }}</span>
                                </div>
                                <div class="dv-item-sub">
                                    <span>{{ $entry['issued_date'] }} · {{ $entry['amount'] }}{{ $entry['id'] === $viewing ? ' · Viewing' : '' }}</span>
                                    @if ($entry['id'] !== $viewing)
                                        <a href="{{ $entry['url'] }}">View</a>
                                    @endif
                                </div>
                                @if ($entry['void_reason'])
                                    <div class="dv-reason">Reason: {{ $entry['void_reason'] }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if ($receiptUrl)
                    <div class="dv-block">
                        <div class="dv-label">RELATED</div>
                        <div class="dv-item-sub" style="margin-top: 10px"><span>Payment receipt</span><a href="{{ $receiptUrl }}">View receipt</a></div>
                    </div>
                @endif
            </aside>
        </div>
    </div>
@endsection
