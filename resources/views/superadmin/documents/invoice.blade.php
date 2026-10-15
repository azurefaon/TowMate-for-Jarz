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
        $available = (bool) $doc['reliable'];

        // Phone / unavailable reading view: stored figures only. Charges are listed only when they reconcile.
        $facts = [
            ['Status', $doc['status_label'] . ' · Version ' . $doc['version'] . ' of ' . $doc['versions']],
            ['Invoice number', $doc['number']],
            ['Issued', $doc['issued_at']],
            ['Billed to', $doc['customer']['name']],
            ['Booking', $doc['booking_ref']],
        ];
        if ($available) {
            foreach ($doc['lines'] as $line) {
                $facts[] = [$line['title'], $line['amount']];
            }
            foreach ($doc['rows'] as $row) {
                $facts[] = [$row['label'], $row['value']];
            }
        }
        $facts[] = ['Total', $doc['total']];
        if ($doc['note']) {
            $facts[] = ['Note', $doc['note']];
        }
    @endphp
    <div class="dv-page">
        <div class="dv-top">
            <div class="dv-title">
                <h1>Invoice</h1>
                <span class="dv-badge" style="background: {{ $doc['status_bg'] }}">{{ $doc['status_label'] }}</span>
                <span class="dv-badge is-ink">Owner · Read-only</span>
                @if ($doc['versions'] > 1)
                    <span class="dv-version">Version {{ $doc['version'] }} of {{ $doc['versions'] }}</span>
                @endif
            </div>
            <a class="dv-back" href="{{ route('superadmin.bookings.index', ['booking' => $booking->job_code]) }}">← Back to Booking</a>
        </div>

        <div class="dv-grid">
            <div class="dv-stage">
                @include('superadmin.documents._stage', [
                    'noun' => 'invoice',
                    'available' => $available,
                    'official' => (bool) $officialPdfUrl,
                    'unavailable' => 'The stored records for this invoice do not reconcile into an itemised breakdown, so its charges are not shown. The stored total is listed below.',
                    'facts' => $facts,
                ])
            </div>

            <aside class="dv-aside" aria-label="Invoice details">
                @if ($officialPdfUrl)
                    @include('superadmin.documents._pdf_actions', ['pdfUrl' => $officialPdfUrl])
                @endif

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
@if ($officialPdfUrl)
    @push('scripts')
        <script src="{{ asset('superadmin/js/documents.js') }}?v={{ filemtime(public_path('superadmin/js/documents.js')) }}" defer></script>
    @endpush
@endif
@endsection
