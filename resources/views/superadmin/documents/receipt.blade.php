@extends('layouts.superadmin')

@section('title', 'Payment Receipt ' . $doc['number'])

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('superadmin/css/documents.css') }}?v={{ filemtime(public_path('superadmin/css/documents.css')) }}">
    @include('documents.partials.doc-styles')
@endpush

@section('content')
    @php($shown = $stage['mode'] === 'original' ? 'Original issued PDF' : 'Preview generated from records')
    <div class="dv-page">
        <div class="dv-top">
            <div class="dv-title">
                <h1>Payment Receipt</h1>
                <span class="dv-badge is-ink">Owner · Read-only</span>
            </div>
            <a class="dv-back" href="{{ route('superadmin.bookings.index', ['booking' => $booking->job_code]) }}">← Back to Booking</a>
        </div>

        <div class="dv-grid">
            <div class="dv-stage">
                @include('superadmin.documents._stage', [
                    'noun' => 'receipt',
                    'facts' => array_values(array_filter([
                        ['Receipt number', $doc['number']],
                        ['Issued', $doc['issued_at']],
                        ['Received from', $doc['customer']['name']],
                        [ucfirst(strtolower($doc['amount_label'])), $doc['amount']],
                        ['Payment method', $doc['method'] ?? 'Not available'],
                        ['Verification', $doc['verification']],
                        ['Booking', $doc['booking_ref']],
                        $doc['invoice_number'] ? ['Invoice', $doc['invoice_number']] : null,
                        ['Original PDF', $pdfReady ? 'On file' : 'Not on file'],
                    ])),
                ])
            </div>

            <aside class="dv-aside" aria-label="Receipt details">
                <div class="dv-block">
                    <div class="dv-label">ORIGINAL ISSUED PDF</div>
                    <p class="dv-hint">The authoritative issued receipt, exactly as it was issued.</p>
                    <div class="dv-actions">
                        @if ($pdfReady)
                            <a class="dv-btn is-primary" href="{{ $viewUrl }}" target="_blank" rel="noopener noreferrer">View Original PDF</a>
                            <a class="dv-btn" href="{{ $viewUrl }}?download=1">Download Original PDF</a>
                        @else
                            <span class="dv-btn is-disabled" role="note">Original PDF not available</span>
                        @endif
                    </div>
                </div>

                <div class="dv-block">
                    <div class="dv-label">DOCUMENT</div>
                    <dl class="dv-facts">
                        <dt>Receipt</dt><dd class="dv-mono">{{ $doc['number'] }}</dd>
                        <dt>Issued</dt><dd>{{ $doc['issued_at'] }}</dd>
                        <dt>Showing</dt>
                        <dd>
                            <span class="dv-only-desktop">{{ $shown }}</span>
                            {{-- Phones show a records summary, never the preview or the PDF itself. --}}
                            <span class="dv-only-mobile">{{ $pdfReady ? 'Summary of stored records; the original issued PDF is on file' : 'Summary of stored records; no original PDF is on file' }}</span>
                        </dd>
                    </dl>
                </div>

                @include('superadmin.documents._email', ['email' => $doc['email']])

                <div class="dv-block">
                    <div class="dv-label">RELATED</div>
                    <ul class="dv-list">
                        <li class="dv-item">
                            <div class="dv-item-sub" style="margin-top: 0"><span>Booking<br><span class="dv-mono">{{ $doc['booking_ref'] }}</span></span><a href="{{ route('superadmin.bookings.index', ['booking' => $booking->job_code]) }}">Open</a></div>
                        </li>
                        @if ($doc['invoice_number'])
                            <li class="dv-item">
                                <div class="dv-item-sub" style="margin-top: 0"><span>Invoice<br><span class="dv-mono">{{ $doc['invoice_number'] }}</span></span>
                                    @if ($invoiceUrl)<a href="{{ $invoiceUrl }}">View invoice</a>@endif
                                </div>
                            </li>
                        @endif
                    </ul>
                </div>
            </aside>
        </div>
    </div>
@endsection
