@extends('layouts.superadmin')

@section('title', 'Payment Receipt ' . $doc['number'])

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('superadmin/css/documents.css') }}?v={{ filemtime(public_path('superadmin/css/documents.css')) }}">
    @include('documents.partials.doc-styles')
@endpush

@section('content')
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
                @if ($pdfReady)
                    <p class="dv-source">Issued document — the original receipt PDF exactly as it was issued.</p>
                    <iframe class="dv-embed" src="{{ $viewUrl }}" title="Original receipt PDF {{ $doc['number'] }}"></iframe>
                @else
                    <p class="dv-source is-warning">No issued PDF is on file for this receipt. The preview below is rendered from the payment records and is not the issued document.</p>
                    <div class="dv-paper">
                        @include('documents.partials.receipt-sheet', ['doc' => $doc])
                    </div>
                @endif

                @include('superadmin.documents._summary', ['facts' => array_values(array_filter([
                    ['Receipt number', $doc['number']],
                    ['Issued', $doc['issued_at']],
                    ['Received from', $doc['customer']['name']],
                    [$doc['verified'] ? 'Amount received' : 'Payment not verified', $doc['amount']],
                    ['Payment method', $doc['method'] ?? 'Not available'],
                    ['Verification', $doc['verification']],
                    ['Booking', $doc['booking_ref']],
                    $doc['invoice_number'] ? ['Invoice', $doc['invoice_number']] : null,
                    ['Document', $pdfReady ? 'Original issued PDF' : 'No issued PDF on file'],
                ])), 'viewUrl' => $pdfReady ? $viewUrl : null])
            </div>

            <aside class="dv-aside" aria-label="Receipt details">
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
