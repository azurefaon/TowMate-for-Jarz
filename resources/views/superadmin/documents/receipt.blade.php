@extends('layouts.superadmin')

@section('title', 'Payment Receipt ' . $doc['number'])

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('superadmin/css/documents.css') }}?v={{ filemtime(public_path('superadmin/css/documents.css')) }}">
    @include('documents.partials.doc-styles')
@endpush

@section('content')
    @php
        $available = (bool) $doc['previewable'];

        // Phone / unavailable reading view: stored figures only. The amount is shown only when the related invoice confirms it.
        $facts = [
            ['Receipt number', $doc['number']],
            ['Issued', $doc['issued_at']],
            ['Received from', $doc['customer']['name']],
        ];
        if ($available) {
            $facts[] = [ucfirst(strtolower($doc['amount_label'])), $doc['amount']];
            foreach ($doc['rows'] as $row) {
                $facts[] = [$row['label'], $row['value']];
            }
        } else {
            $facts[] = ['Payment method', $doc['method'] ?? 'Not available'];
            $facts[] = ['Verification', $doc['verification']];
            $facts[] = ['Booking', $doc['booking_ref']];
        }
    @endphp
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
                    'available' => $available,
                    'official' => (bool) $officialPdfUrl,
                    'unavailable' => 'The related invoice could not be found, so the amount cannot be confirmed. The stored receipt details are listed below.',
                    'facts' => $facts,
                ])
            </div>

            <aside class="dv-aside" aria-label="Receipt details">
                @if ($officialPdfUrl)
                    @include('superadmin.documents._pdf_actions', ['pdfUrl' => $officialPdfUrl])
                @endif

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
@if ($officialPdfUrl)
    @push('scripts')
        <script src="{{ asset('superadmin/js/documents.js') }}?v={{ filemtime(public_path('superadmin/js/documents.js')) }}" defer></script>
    @endpush
@endif
@endsection
