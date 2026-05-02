@extends('admin-dashboard.layouts.app')

@section('title', 'Dispatch')

@push('styles')
    <style>
        .quotation-review-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(240px, 0.75fr);
            gap: 12px;
            margin: 12px 0;
            align-items: start;
        }

        #actionModal {
            overflow-y: auto;
            padding: 10px;
            transition: opacity 0.25s ease;
        }

        #actionModal .modal-card {
            width: min(1100px, 96vw);
            max-width: 1100px;
            max-height: calc(100vh - 40px);
            overflow-x: hidden;
            overflow-y: auto;
            padding: 20px;
        }

        .review-surface {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            padding: 12px;
        }

        .review-form-horizontal {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px 12px;
            align-items: start;
        }

        .review-form-horizontal .full-span {
            grid-column: 1 / -1;
        }

        .review-surface h4 {
            margin: 0 0 8px;
            font-size: 0.96rem;
            color: #0f172a;
        }

        #modalTitle {
            margin-bottom: 4px;
        }

        #modalText {
            margin-bottom: 8px;
            /* font-size: 0.9rem; */
        }

        #actionModal .modal-icon {
            display: none !important;
        }

        .review-summary-list {
            display: grid;
            gap: 8px;
        }

        .review-summary-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 0.89rem;
            color: #334155;
        }

        .review-summary-row strong {
            color: #0f172a;
            text-align: right;
        }

        .review-summary-row.total {
            margin-top: 8px;
            padding-top: 12px;
            border-top: 1px solid #cbd5e1;
            font-size: 1rem;
        }

        .computed-total {
            padding: 10px 12px;
            background: linear-gradient(135deg, #fef3c7, #fff7ed);
            border: 1px solid #fcd34d;
            font-size: 1.08rem;
            color: #92400e;
        }

        .computed-total.compact {
            font-size: 1rem;
            padding: 10px 12px;
        }

        .review-input-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .review-field-input,
        .review-field-select {
            width: 100%;
            min-height: 40px;
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            background: #fff;
            color: #0f172a;
            font-size: 0.92rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .review-field-select {
            appearance: none;
            background-image: linear-gradient(45deg, transparent 50%, #64748b 50%), linear-gradient(135deg, #64748b 50%, transparent 50%);
            background-position: calc(100% - 18px) calc(50% - 3px), calc(100% - 12px) calc(50% - 3px);
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
            padding-right: 36px;
        }

        .review-field-input:focus,
        .review-field-select:focus {
            outline: none;
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.18);
        }

        .review-field-input[readonly],
        .review-field-input[disabled] {
            background: #f8fafc;
            color: #475569;
            cursor: not-allowed;
        }

        .review-field-input.is-locked {
            background: #f8fafc;
            border-style: dashed;
            border-color: #cbd5e1;
            color: #64748b;
        }

        .unit-select-shell {
            border: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #fffdf5, #f8fafc);
            padding: 8px;
        }

        .unit-select-shell .review-field-select {
            border-color: #d1d5db;
            background-color: transparent;
        }

        .review-field-input.is-invalid,
        .review-field-select.is-invalid {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.14);
        }

        .inline-field-error {
            display: none;
            margin-top: 6px;
            font-size: 0.82rem;
            color: #b91c1c;
        }

        .inline-field-error.show {
            display: block;
        }

        .quote-validation-summary {
            display: none !important;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .section-header p {
            margin: 6px 0 0;
            color: #64748b;
        }

        .queue-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 16px 0 14px;
        }

        .queue-filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #dbe2ea;
            background: #fff;
            color: #0f172a;
            padding: 10px 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .queue-tab-count {
            min-width: 20px;
            height: 20px;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
            background: #dc2626;
            color: #fff;
            font-size: 11px;
            line-height: 1;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.25);
        }

        .queue-tab-count.has-count {
            display: inline-flex;
        }

        .queue-filter-btn.is-active {
            background: #facc15;
            color: #070504;
        }

        .incoming-card.is-hidden {
            display: none;
        }

        .incoming-card--scheduled {
            border: 1px solid #facc15;
            background: #fffef0;
        }

        .incoming-card--scheduled .incoming-route strong {
            color: #111;
        }

        .incoming-card--scheduled-confirmed {
            border: 2px solid #facc15;
            background: #fffce0;
        }

        .incoming-panel {
            display: none;
        }

        .status-badge.scheduled_confirmed {
            background: #facc15;
            color: #111;
            border: 1px solid #d4a017;
        }

        .btn-accept[disabled] {
            opacity: 0.72;
            cursor: not-allowed;
            filter: saturate(0.85);
        }

        .queue-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            font-size: 12px;
        }

        .queue-chip.book-now {
            background: #dcfce7;
            color: #166534;
        }

        .queue-chip.scheduled {
            background: #e0f2fe;
            color: #075985;
        }

        .queue-chip.delayed {
            background: #fee2e2;
            color: #991b1b;
        }

        .queue-chip.due-now {
            background: #fee2e2;
            color: #991b1b;
        }

        .queue-chip.negotiation {
            background: #fef3c7;
            color: #92400e;
        }

        .queue-chip.returned {
            background: #fee2e2;
            color: #991b1b;
        }

        .queue-chip.active {
            background: #dcfce7;
            color: #166534;
        }

        .queue-chip.ready_completion {
            /* background: #f0fdf4; */
            color: #000000;
            /* border: 1px solid #bbf7d0; */
        }

        .status-badge.payment-pending {
            /* background: #fef3c7; */
            color: #000000;
            /* border-color: #f5d565; */
        }

        .status-badge.confirmed {
            /* background: #e0f2fe; */
            color: #075985;
            /* border-color: #7dd3fc; */
        }

        .status-badge.returned {
            /* background: #fee2e2; */
            color: #991b1b;
            /* border-color: #fca5a5; */
        }

        .dp-tl-section {
            margin-top: 36px;
            padding-bottom: 40px;
        }

        .dp-tl-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .dp-tl-title {
            margin: 0 0 4px;
            font-size: 1rem;
            color: #0f172a;
        }

        .dp-tl-sub {
            margin: 0;
            font-size: .82rem;
            color: #000000;
        }

        .dp-tl-link {
            font-size: .82rem;
            color: #6366f1;
            text-decoration: none;
            white-space: nowrap;
            align-self: center;
        }

        .dp-tl-link:hover {
            text-decoration: underline;
        }

        .dp-tl-empty {
            padding: 24px;
            text-align: center;
            color: #000000;
            font-size: .88rem;
            background: #fff;
            border: 1px solid #e5e7eb;
        }

        .group-booking-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 7px 14px;
            border-bottom: none;
            font-size: 0.78rem;
            color: #000000;
        }

        .group-vehicle-indicator {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #475569;
            padding: 3px 0 8px;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 8px;
        }

        .incoming-card--group-child {
            border-top: none;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }

        .incoming-card--group-child+.incoming-card--group-child {
            margin-top: 2px;
        }

        .dp-tl-table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .04);
        }

        .dp-tl-table {
            width: 100%;
            border-collapse: collapse;
        }

        .dp-tl-table thead tr {
            background: #f8fafc;
            border-bottom: 1.5px solid #e5e7eb;
        }

        .dp-tl-table th {
            padding: 11px 14px;
            text-align: left;
            font-size: .72rem;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: .07em;
            white-space: nowrap;
        }

        .dp-tl-table td {
            padding: 12px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            font-size: .88rem;
            color: #374151;
        }

        .dp-tl-table tbody tr:last-child td {
            border-bottom: none;
        }

        .dp-tl-table tbody tr:hover {
            background: #fafbff;
        }

        /* Name cell */
        .dp-name-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dp-avatar {
            width: 34px;
            height: 34px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            font-size: .8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .dp-name {
            font-size: .88rem;
            color: #0f172a;
        }

        .dp-presence {
            font-size: .72rem;
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 1px;
        }

        .dp-presence::before {
            content: '';
            width: 6px;
            height: 6px;
            display: inline-block;
        }

        .dp-presence--online {
            color: #16a34a;
        }

        .dp-presence--online::before {
            background: #22c55e;
        }

        .dp-presence--offline {
            color: #000000;
        }

        .dp-presence--offline::before {
            background: #cbd5e1;
        }

        /* Zone */
        .dp-zone-badge {
            display: inline-block;
            padding: 3px 9px;
            font-size: .72rem;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .dp-zone-none {
            font-size: .78rem;
            color: #000000;
        }

        /* Status badge */
        .dp-status-badge {
            display: inline-block;
            padding: 4px 10px;
            font-size: .72rem;
            white-space: nowrap;
        }

        .dp-badge--avail {
            background: #f0fdf4;
            color: #15803d;
        }

        .dp-badge--off {
            background: #fef2f2;
            color: #b91c1c;
        }

        .dp-badge--tow {
            background: #f5f3ff;
            color: #6d28d9;
        }

        .dp-badge--busy {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .dp-badge--idle {
            background: #fffbeb;
            color: #b45309;
        }

        /* Remove unit button */
        .dp-remove-btn {
            display: inline-block;
            margin-top: 4px;
            padding: 3px 9px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #b91c1c;
            font-size: .72rem;
            cursor: pointer;
            transition: background .15s;
        }

        .dp-remove-btn:hover {
            background: #fee2e2;
        }

        .dp-remove-btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        /* Controls */
        .dp-ctrl-cell {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .dp-select {
            padding: 6px 10px;
            border: 1px solid #e5e7eb;
            font-size: .82rem;
            background: #f8fafc;
            color: #374151;
            cursor: pointer;
            outline: none;
            min-width: 140px;
            transition: border-color .18s;
        }

        .dp-select:focus {
            border-color: #6366f1;
            background: #fff;
        }

        .dp-select:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .dp-saving {
            font-size: .72rem;
            min-width: 36px;
        }

        /* Return Reason Styles */
        .rr-panel {
            margin-top: 12px;
            padding: 12px;
            background: linear-gradient(135deg, #fef3c7, #fef9e7);
            border: 1px solid #fbbf24;
        }

        .rr-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .rr-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .rr-badge--critical {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .rr-badge--high {
            background: #fed7aa;
            color: #9a3412;
            border: 1px solid #fb923c;
        }

        .rr-badge--medium {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fbbf24;
        }

        .rr-label {
            font-size: 13px;
            color: #92400e;
        }

        .rr-note {
            font-size: 13px;
            color: #78350f;
            margin: 6px 0;
            line-height: 1.5;
        }

        .rr-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .rr-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            border: 1px solid #d97706;
            background: #fff;
            color: #92400e;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s;
        }

        .rr-action-btn:hover {
            background: #fffbeb;
            border-color: #b45309;
        }

        .rr-action-btn:active {
            transform: scale(0.98);
        }

        /* Service Fee Modal */
        .sf-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 1300;
        }

        .sf-modal-backdrop.show {
            display: flex;
        }

        .sf-modal-card {
            width: min(480px, 100%);
            background: #fff;
            padding: 24px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        }

        .sf-modal-card h3 {
            margin: 0 0 8px;
            font-size: 1.1rem;
            color: #0f172a;
        }

        .sf-modal-card p {
            margin: 0 0 16px;
            color: #475569;
            font-size: 0.9rem;
        }

        .sf-modal-card label {
            display: block;
            margin: 12px 0 6px;
            font-size: 0.88rem;
            color: #334155;
        }

        .sf-modal-card input,
        .sf-modal-card textarea,
        .sf-modal-card select {
            width: 100%;
            border: 1px solid #cbd5e1;
            padding: 10px 12px;
            font: inherit;
        }

        .sf-modal-card textarea {
            min-height: 80px;
            resize: vertical;
        }

        .sf-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .sf-btn {
            padding: 10px 20px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.15s;
        }

        .sf-btn--cancel {
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #475569;
        }

        .sf-btn--cancel:hover {
            background: #f8fafc;
        }

        .sf-btn--primary {
            border: none;
            background: #f59e0b;
            color: #fff;
        }

        .sf-btn--primary:hover {
            background: #d97706;
        }

        .sf-btn--primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>
@endpush

@section('content')

    <div class="dashboard-container">

        {{-- Pending Quotations Card - Above Dispatch Queue --}}
        @include('admin-dashboard.pages._quotations-card')

        {{-- Quotation View/Edit Modal --}}
        @include('admin-dashboard.pages._quotation-modal')

        <div class="incoming-section">

            <div class="section-header">
                <div>
                    <h3>Booking Queue</h3>
                </div>
                <div class="view-controls">
                    <span class="count" id="requestCount">{{ $queueCounts['book-now'] ?? $incomingRequests->count() }}</span>
                </div>
            </div>

            <div class="queue-tabs" id="dispatchQueueTabs">

                <button type="button" class="queue-filter-btn" data-filter="returned">
                    <span>Returned</span>
                    <span class="queue-tab-count {{ ($queueCounts['returned'] ?? 0) > 0 ? 'has-count' : '' }}"
                        data-count-for="returned">
                        {{ $queueCounts['returned'] ?? 0 }}
                    </span>
                </button>

                <button type="button" class="queue-filter-btn is-active" data-filter="active">
                    <span>Active Bookings</span>
                    <span class="queue-tab-count {{ ($queueCounts['active'] ?? 0) > 0 ? 'has-count' : '' }}"
                        data-count-for="active">
                        {{ $queueCounts['active'] ?? 0 }}
                    </span>
                </button>
                <button type="button" class="queue-filter-btn" data-filter="ready_completion">
                    <span>Ready for Completion</span>
                    <span class="queue-tab-count {{ ($queueCounts['ready_completion'] ?? 0) > 0 ? 'has-count' : '' }}"
                        data-count-for="ready_completion">
                        {{ $queueCounts['ready_completion'] ?? 0 }}
                    </span>
                </button>
                <button type="button" class="queue-filter-btn" data-filter="all">
                    <span>All</span>
                    <span class="queue-tab-count {{ ($queueCounts['all'] ?? 0) > 0 ? 'has-count' : '' }}"
                        data-count-for="all">
                        {{ $queueCounts['all'] ?? 0 }}
                    </span>
                </button>
                <button type="button" class="queue-filter-btn" data-filter="book-now">
                    <span>Book Now</span>
                    <span class="queue-tab-count {{ ($queueCounts['book-now'] ?? 0) > 0 ? 'has-count' : '' }}"
                        data-count-for="book-now">
                        {{ $queueCounts['book-now'] ?? 0 }}
                    </span>
                </button>
                <button type="button" class="queue-filter-btn" data-filter="scheduled">
                    <span>Scheduled</span>
                    <span class="queue-tab-count {{ ($queueCounts['scheduled'] ?? 0) > 0 ? 'has-count' : '' }}"
                        data-count-for="scheduled">
                        {{ $queueCounts['scheduled'] ?? 0 }}
                    </span>
                </button>
            </div>

            <div class="incoming-list" id="incomingList" data-default-filter="active"
                data-assign-url-template="{{ url('/admin-dashboard/booking/__BOOKING__/assign') }}">

                @forelse($groupedIncoming as $groupCode => $groupBookings)
                    @php $isMultiGroup = $groupBookings->count() > 1; @endphp
                    @if ($isMultiGroup)
                        <div class="group-booking-header">
                            <strong>Multi vehicle booking {{ $groupBookings->count() }} vehicles</strong>
                            <span>{{ $groupBookings->first()->customer->full_name ?? 'Guest' }} &middot; Ref:
                                {{ $groupCode }}</span>
                        </div>
                    @endif
                    @foreach ($groupBookings as $vIdx => $booking)
                        @php
                            $isReadyCompletion = in_array($booking->status, [
                                'waiting_verification',
                                'payment_pending',
                                'payment_submitted',
                            ]);
                            $isReturned = $booking->needs_reassignment;
                            $queueBucket = $isReadyCompletion
                                ? 'ready_completion'
                                : ($isReturned
                                    ? 'returned'
                                    : 'active');
                            $timingLabel = $isReadyCompletion
                                ? 'Ready for Completion'
                                : ($isReturned
                                    ? 'Returned'
                                    : 'Active Booking');
                            $statusBadgeClass = $isReadyCompletion
                                ? 'payment-pending'
                                : ($isReturned
                                    ? 'returned'
                                    : 'confirmed');
                            $statusBadgeLabel = $isReadyCompletion
                                ? match ($booking->status) {
                                    'waiting_verification' => 'Awaiting Verification',
                                    'payment_pending' => 'Payment Pending',
                                    'payment_submitted' => 'Payment Submitted',
                                    default => ucfirst($booking->status),
                                }
                                : ($isReturned
                                    ? 'Needs Reassignment'
                                    : ucfirst($booking->status));

                            // Extra data for Complete Job modal
                            $cj_vehicleImgUrl = '';
                            if ($booking->vehicle_image_path) {
                                $cj_paths = json_decode($booking->vehicle_image_path, true);
                                if (is_array($cj_paths) && !empty($cj_paths)) {
                                    $cj_vehicleImgUrl = \Illuminate\Support\Facades\Storage::disk('public')->url(
                                        $cj_paths[0],
                                    );
                                }
                            }
                            $cj_paymongoRef = $booking->paymongo_intent_id ?? ($booking->paymongo_link_id ?? '');
                            $cj_paymentStatusLabel = match ($booking->status) {
                                'payment_pending' => 'Pending',
                                'payment_submitted' => 'Proof Submitted',
                                'waiting_verification' => 'Awaiting Verification',
                                default => ucfirst(str_replace('_', ' ', $booking->status)),
                            };
                            $cj_paymentMethodLabel = match ($booking->payment_method ?? '') {
                                'gcash' => 'GCash',
                                'bank' => 'Bank Transfer',
                                'cash' => 'Cash',
                                'cheque' => 'Cheque',
                                default => '—',
                            };
                        @endphp
                        <div class="incoming-card {{ $booking->is_scheduled && !$booking->is_dispatch_delayed ? 'incoming-card--scheduled' : '' }} {{ $isMultiGroup ? 'incoming-card--group-child' : '' }}"
                            data-id="{{ $booking->job_code }}" data-status="{{ $booking->status }}"
                            data-queue="{{ $queueBucket }}" data-group-code="{{ $groupCode }}"
                            data-service-mode="{{ $booking->service_mode }}"
                            data-scheduled-for="{{ optional($booking->scheduled_for)->toIso8601String() }}"
                            data-current-price="{{ $booking->final_total }}"
                            data-current-additional="{{ $booking->additional_fee }}"
                            data-base-rate="{{ $booking->base_rate }}"
                            data-distance-fee="{{ $booking->distance_fee_amount }}"
                            data-distance-km="{{ $booking->distance_km }}" data-per-km-rate="{{ $booking->per_km_rate }}"
                            data-customer-type="{{ ucfirst($booking->customer_type ?? (optional($booking->customer)->customer_type ?? 'regular')) }}"
                            data-truck-type="{{ e($booking->truckType->name ?? 'Unknown') }}"
                            data-dispatch-zone="{{ e($booking->dispatch_zone_label ?? 'General Dispatch Zone') }}"
                            data-recommended-unit="{{ $booking->recommended_unit_id }}"
                            data-recommended-summary="{{ e($booking->recommended_unit_summary ?? '') }}"
                            data-assigned-unit="{{ $booking->assigned_unit_id }}"
                            data-customer-note="{{ e($booking->customer_response_note ?? '') }}"
                            data-counter-offer="{{ $booking->counter_offer_amount }}"
                            data-dispatcher-note="{{ e($booking->remarks ?? ($booking->dispatcher_note ?? '')) }}"
                            data-return-reason="{{ e($booking->return_reason ?? '') }}"
                            data-returned-by="{{ e($booking->returnedByTeamLeader->full_name ?? ($booking->returnedByTeamLeader->name ?? '')) }}"
                            data-returned-at="{{ optional($booking->returned_at)->toIso8601String() }}"
                            data-created-at="{{ $booking->created_at->toISOString() }}"
                            data-customer-name="{{ e($booking->customer->full_name ?? 'Guest') }}"
                            data-customer-phone="{{ e($booking->customer->phone ?? 'N/A') }}"
                            data-customer-email="{{ e($booking->customer->email ?? '—') }}"
                            data-pickup="{{ e($booking->pickup_address ?? '') }}"
                            data-dropoff="{{ e($booking->dropoff_address ?? '') }}"
                            data-unit-name="{{ e($booking->unit->name ?? '—') }}"
                            data-unit-plate="{{ e($booking->unit->plate_number ?? '—') }}"
                            data-tl-name="{{ e($booking->unit->teamLeader->full_name ?? ($booking->unit->teamLeader->name ?? '—')) }}"
                            data-tl-phone="{{ e($booking->unit->teamLeader->phone ?? '—') }}"
                            data-driver-name="{{ e($booking->unit->driver->full_name ?? ($booking->unit->driver->name ?? ($booking->unit->driver_name ?? '—'))) }}"
                            data-final-total="{{ $booking->final_total ?? 0 }}"
                            data-job-code="{{ e($booking->job_code ?? '—') }}"
                            data-payment-method="{{ e($booking->payment_method ?? '') }}"
                            data-payment-method-label="{{ e($cj_paymentMethodLabel) }}"
                            data-payment-status-label="{{ e($cj_paymentStatusLabel) }}"
                            data-payment-proof-url="{{ e(json_encode($booking->payment_proof_path ? array_values(array_map(fn($p) => \Illuminate\Support\Facades\Storage::disk('public')->url($p), (array) $booking->payment_proof_path)) : [])) }}"
                            data-paymongo-ref="{{ e($cj_paymongoRef) }}"
                            data-discount-percentage="{{ $booking->discount_percentage ?? 0 }}"
                            data-discount-reason="{{ e($booking->discount_reason ?? '') }}"
                            data-computed-total="{{ $booking->computed_total ?? 0 }}"
                            data-distance-fee-amount="{{ $booking->distance_fee_amount ?? 0 }}"
                            data-vehicle-image-url="{{ e($cj_vehicleImgUrl) }}"
                            data-truck-type-base-rate="{{ $booking->unit->truckType->base_rate ?? ($booking->base_rate ?? 0) }}">

                            <div class="incoming-left">

                                @if ($isMultiGroup)
                                    <div class="group-vehicle-indicator">Vehicle {{ $vIdx + 1 }} of
                                        {{ $groupBookings->count() }} &mdash;
                                        {{ $booking->truckType->name ?? 'Tow Truck' }}</div>
                                @endif

                                <div class="incoming-route">
                                    <strong>{{ $booking->pickup_address ?? 'Unknown Pickup' }}</strong>
                                    <span class="arrow">→</span>
                                    <span>{{ $booking->dropoff_address ?? 'Unknown Dropoff' }}</span>
                                </div>

                                {{-- <div class="incoming-details">
                                    <span><strong>ETA:</strong>
                                        @if ($booking->eta_minutes)
                                            {{ round($booking->eta_minutes) }} min
                                        @elseif ($booking->quotation && $booking->quotation->eta_minutes)
                                            {{ round($booking->quotation->eta_minutes) }} min
                                        @else
                                            <span style="color:#b91c1c">No ETA</span>
                                        @endif
                                    </span>
                                </div> --}}

                                <div class="incoming-details">
                                    <span><strong>Customer:</strong> {{ $booking->customer->full_name ?? 'Guest' }}</span>
                                    <span><strong>Phone:</strong> {{ $booking->customer->phone ?? 'N/A' }}</span>
                                    <span><strong>Vehicle:</strong> {{ $booking->truckType->name ?? 'Unknown' }}</span>
                                    <span><strong>Reference:</strong> {{ $booking->job_code }}</span>
                                </div>

                                <div class="incoming-meta">
                                    <span class="time">
                                        {{ $booking->created_at->diffForHumans() }}
                                    </span>
                                    <span class="queue-chip {{ $queueBucket }}">
                                        {{ $timingLabel }}
                                    </span>
                                    <span class="status-badge {{ $statusBadgeClass }}">
                                        {{ $statusBadgeLabel }}
                                    </span>
                                </div>

                                <div class="incoming-details" style="margin-top: 10px;">
                                    <span><strong>Dispatch Timing:</strong> {{ $booking->schedule_window_label }}</span>
                                </div>

                                <div class="incoming-details" style="margin-top: 10px;">
                                    <span><strong>Dispatch Zone:</strong>
                                        {{ $booking->dispatch_zone_label ?? 'General Dispatch Zone' }}</span>
                                    <span><strong>Recommended unit:</strong>
                                        {{ $booking->recommended_unit_label ?? 'Dispatcher will choose the best ready unit.' }}</span>
                                </div>

                                @if ($booking->needs_reassignment)
                                    <div class="incoming-details" style="margin-top: 10px;">
                                        <span><strong>Returned by:</strong>
                                            {{ $booking->returnedByTeamLeader->full_name ?? ($booking->returnedByTeamLeader->name ?? 'Team Leader') }}</span>
                                        <span><strong>Reason:</strong>
                                            {{ $booking->return_reason ?? 'Needs reassignment.' }}</span>
                                    </div>

                                    @if (isset($booking->return_reason_parsed))
                                        @php
                                            $rrp = $booking->return_reason_parsed;
                                            $isUnreachable = ($rrp['code'] ?? null) === 'customer_unreachable';
                                        @endphp
                                        @if (!$isUnreachable)
                                            <div class="rr-panel">
                                                <div class="rr-header">
                                                    <span
                                                        class="rr-badge {{ $rrp['badge_class'] ?? 'rr-badge--medium' }}">
                                                        {{ strtoupper($rrp['priority'] ?? 'medium') }} PRIORITY
                                                    </span>
                                                    <span class="rr-label">{{ $rrp['label'] ?? 'Returned' }}</span>
                                                </div>
                                                @if (filled($rrp['note'] ?? null))
                                                    <div class="rr-note">{{ $rrp['note'] }}</div>
                                                @endif
                                                @if (!empty($rrp['actions']))
                                                    <div class="rr-actions">
                                                        @foreach ($rrp['actions'] as $action)
                                                            <button type="button" class="rr-action-btn"
                                                                data-action="{{ $action }}"
                                                                data-booking-id="{{ $booking->job_code }}"
                                                                data-customer-id="{{ $booking->customer_id }}">
                                                                <span>{{ $returnReasonHandler->getActionIcon($action) }}</span>
                                                                <span>{{ $returnReasonHandler->getActionLabel($action) }}</span>
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    @endif
                                @endif

                            </div>

                            @if ($booking->status === 'reviewed')
                                <div class="incoming-details" style="margin-top: 10px;">
                                    <span><strong>Counter-offer:</strong>
                                        {{ $booking->counter_offer_amount ? '₱' . number_format((float) $booking->counter_offer_amount, 2) : 'Not provided' }}</span>
                                    <span><strong>Customer note:</strong>
                                        {{ $booking->customer_response_note ?? 'Customer requested a quotation adjustment.' }}</span>
                                </div>
                            @endif

                            @php
                                $rrp = $booking->return_reason_parsed ?? null;
                                $isCustomerUnreachable =
                                    isset($rrp) && ($rrp['code'] ?? null) === 'customer_unreachable';
                            @endphp

                            <div class="incoming-actions">
                                @if ($isReadyCompletion)
                                    <button type="button" class="btn-complete-job"
                                        data-booking-code="{{ $booking->job_code }}"
                                        data-customer="{{ e($booking->customer->full_name ?? 'Customer') }}"
                                        data-ref="{{ $booking->job_code }}"
                                        data-amount="₱{{ number_format((float) ($booking->final_total ?? 0), 2) }}"
                                        data-status="{{ $booking->status }}"
                                        data-confirm-url="{{ route('admin.jobs.confirm-payment', $booking) }}">
                                        Complete Job
                                    </button>
                                @elseif ($booking->needs_reassignment || $booking->needs_assignment)
                                    <button type="button" class="btn-accept" data-id="{{ $booking->job_code }}"
                                        data-action="accept">
                                        {{ $booking->needs_reassignment ? 'Reassign Task' : 'Start Job' }}
                                    </button>
                                    <button type="button" class="btn-reject" data-id="{{ $booking->job_code }}"
                                        data-action="reject">
                                        Cancel Booking
                                    </button>
                                @else
                                    <span style="font-size: 0.85rem; color: #64748b;">
                                        Booking is active and assigned to team leader
                                    </span>
                                @endif
                            </div>

                        </div>
                    @endforeach
                @empty
                    <div class="empty-state" id="emptyState">
                        <p>No bookings in this queue right now.</p>
                    </div>
                @endforelse

                @foreach ($allQuotations->where('status', 'pending') as $pq)
                    <div class="incoming-card" data-queue="pending-quotations" data-quotation-id="{{ $pq->id }}">
                        <div class="incoming-left">
                            <div class="incoming-route">
                                <strong>{{ $pq->pickup_address ?? 'Unknown Pickup' }}</strong>
                                <span class="arrow">→</span>
                                <span>{{ $pq->dropoff_address ?? 'Unknown Dropoff' }}</span>
                            </div>
                            <div class="incoming-details">
                                <span><strong>Customer:</strong> {{ $pq->customer->full_name ?? 'Guest' }}</span>
                                <span><strong>Phone:</strong> {{ $pq->customer->phone ?? 'N/A' }}</span>
                                <span><strong>Reference:</strong> {{ $pq->quotation_number ?? 'N/A' }}</span>
                            </div>
                            <div class="incoming-details">
                                <span><strong>Distance:</strong> {{ number_format((float) $pq->distance_km, 2) }}
                                    km</span>
                                <span><strong>Estimated Price:</strong>
                                    ₱{{ number_format((float) $pq->estimated_price, 2) }}</span>
                            </div>
                            <div class="incoming-meta">
                                <span class="time">{{ $pq->created_at->diffForHumans() }}</span>
                                <span class="queue-chip pending-quotations">Pending Quotation</span>
                                <span class="status-badge quoted">Not Sent</span>
                            </div>
                        </div>
                        <div class="incoming-actions">
                            <button type="button" class="btn-accept pq-send-btn"
                                data-quotation-id="{{ $pq->id }}">
                                Update &amp; Send
                            </button>
                            <button type="button" class="btn-reject pq-cancel-btn"
                                data-quotation-id="{{ $pq->id }}">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endforeach

            </div>

        </div>

        {{-- ── Book Now Queue Panel ──────────────────────────────────────────── --}}
        <div id="bookNowPanel" class="incoming-panel" style="display:none;">
            @if ($groupedBookNow->isEmpty())
                <div class="empty-state">
                    <p>No book-now requests in queue.</p>
                </div>
            @else
                @foreach ($groupedBookNow as $bnGroupCode => $bnGroupBookings)
                    @php
                        $bnPrimary = $bnGroupBookings->first();
                        $bnCount = $bnGroupBookings->count();
                        $bnTotal = $bnGroupBookings->sum('final_total');
                    @endphp
                    <div class="incoming-card" data-queue="book-now"
                        data-id="{{ $bnPrimary->job_code ?? $bnPrimary->id }}" data-status="{{ $bnPrimary->status }}">
                        <div class="incoming-left">
                            <div class="incoming-header">
                                <span class="queue-chip book-now">Book
                                    Now{{ $bnCount > 1 ? ' · ' . $bnCount . ' vehicles' : '' }}</span>
                                <span
                                    class="status-badge {{ $bnPrimary->status }}">{{ ucfirst(str_replace('_', ' ', $bnPrimary->status)) }}</span>
                                <span
                                    style="font-size:0.78rem;color:#64748b;">{{ $bnPrimary->created_at->diffForHumans() }}</span>
                            </div>
                            <div class="incoming-route">
                                <strong>{{ $bnPrimary->pickup_address }}</strong>
                                <span class="arrow">→</span>
                                <span>{{ $bnPrimary->dropoff_address }}</span>
                            </div>
                            <div class="incoming-details">
                                <span><strong>Customer:</strong> {{ $bnPrimary->customer->full_name ?? 'Guest' }}</span>
                                <span><strong>Phone:</strong> {{ $bnPrimary->customer->phone ?? 'N/A' }}</span>
                                <span><strong>Ref:</strong> {{ $bnGroupCode }}</span>
                            </div>
                            @if ($bnCount > 1)
                                <div class="incoming-details"
                                    style="margin-top:6px;border-left:3px solid #dcfce7;padding-left:8px;">
                                    @foreach ($bnGroupBookings as $bnIdx => $bnVehicle)
                                        <span>Vehicle {{ $bnIdx + 1 }}:
                                            {{ $bnVehicle->truckType->name ?? 'Tow Truck' }} &middot;
                                            &#8369;{{ number_format((float) ($bnVehicle->final_total ?? 0), 2) }}</span>
                                    @endforeach
                                </div>
                            @else
                                <div class="incoming-details">
                                    <span><strong>Truck:</strong> {{ $bnPrimary->truckType->name ?? '—' }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="incoming-right">
                            <div class="incoming-price">&#8369;{{ number_format((float) $bnTotal, 2) }}</div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- ── Scheduled Queue Panel ────────────────────────────────────────── --}}
        <div id="scheduledPanel" class="incoming-panel" style="display:none;">
            @if ($groupedScheduled->isEmpty())
                <div class="empty-state">
                    <p>No scheduled bookings in queue.</p>
                </div>
            @else
                @foreach ($groupedScheduled as $schGroupCode => $schGroupBookings)
                    @php
                        $schPrimary = $schGroupBookings->first();
                        $schCount = $schGroupBookings->count();
                        $schTotal = $schGroupBookings->sum('final_total');
                        $isConfirmed = $schPrimary->status === 'scheduled_confirmed';
                        $expiresAt = $schPrimary->scheduled_expires_at;
                        $expiresLabel = $expiresAt ? $expiresAt->diffForHumans() : '—';
                        $scheduledFor = $schPrimary->scheduled_for;
                    @endphp
                    <div class="incoming-card {{ $isConfirmed ? 'incoming-card--scheduled-confirmed' : 'incoming-card--scheduled' }}"
                        data-queue="scheduled" data-id="{{ $schPrimary->job_code ?? $schPrimary->id }}"
                        data-status="{{ $schPrimary->status }}">
                        <div class="incoming-left">
                            <div class="incoming-header">
                                <span
                                    class="queue-chip scheduled">{{ $isConfirmed ? 'Confirmed Schedule' : 'Scheduled' }}{{ $schCount > 1 ? ' · ' . $schCount . ' vehicles' : '' }}</span>
                                <span
                                    class="status-badge {{ $schPrimary->status }}">{{ ucfirst(str_replace('_', ' ', $schPrimary->status)) }}</span>
                            </div>
                            <div class="incoming-route">
                                <strong>{{ $schPrimary->pickup_address }}</strong>
                                <span class="arrow">→</span>
                                <span>{{ $schPrimary->dropoff_address }}</span>
                            </div>
                            <div class="incoming-details">
                                <span><strong>Customer:</strong> {{ $schPrimary->customer->full_name ?? 'Guest' }}</span>
                                <span><strong>Phone:</strong> {{ $schPrimary->customer->phone ?? 'N/A' }}</span>
                                <span><strong>Ref:</strong> {{ $schGroupCode }}</span>
                            </div>
                            @if ($schCount > 1)
                                <div class="incoming-details"
                                    style="margin-top:6px;border-left:3px solid #e0f2fe;padding-left:8px;">
                                    @foreach ($schGroupBookings as $schIdx => $schVehicle)
                                        <span>Vehicle {{ $schIdx + 1 }}:
                                            {{ $schVehicle->truckType->name ?? 'Tow Truck' }}</span>
                                    @endforeach
                                </div>
                            @else
                                <div class="incoming-details">
                                    <span><strong>Truck:</strong> {{ $schPrimary->truckType->name ?? '—' }}</span>
                                </div>
                            @endif
                            @if ($scheduledFor)
                                <div class="incoming-details"
                                    style="margin-top:6px;background:#facc1511;padding:6px 8px;border-left:3px solid #facc15;">
                                    <span><strong>Scheduled:</strong> {{ $scheduledFor->format('D, M j, Y') }} at
                                        {{ $scheduledFor->format('g:i A') }}</span>
                                    <span><strong>Expires:</strong> {{ $expiresLabel }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="incoming-right">
                            <div class="incoming-price">&#8369;{{ number_format((float) $schTotal, 2) }}</div>
                            @if ($isConfirmed)
                                <div style="margin-top:8px;">
                                    <button type="button" class="btn-accept"
                                        data-id="{{ $schPrimary->job_code ?? $schPrimary->id }}" data-action="accept"
                                        title="Assign a unit to this scheduled booking">
                                        Dispatch Now
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        @include('admin-dashboard.pages._quotations-section')

        <div id="actionModal" class="hidden" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="modal-card">

                <div class="modal-icon" id="modalIcon"></div>

                <h3 id="modalTitle">Confirm Action</h3>
                <p id="modalText">Are you sure?</p>

                {{-- Start Job panel: shown only for confirmed bookings --}}
                <div id="confirmedBookingPanel" style="display:none; margin-bottom:14px;">
                    <div style="background:#ffffff; border:1px solid #bfd3c6; padding:16px;">
                        <div
                            style="font-size:.72rem; text-transform:uppercase; letter-spacing:.07em; color:#15803d; margin-bottom:12px;">
                            Booking to Assign</div>
                        <div
                            style="display:grid; grid-template-columns:1fr 1fr; gap:10px 20px; font-size:.87rem; color:#374151;">
                            <div>
                                <div style="color:#3b3b3b; font-size:.72rem; text-transform:uppercase; margin-bottom:3px;">
                                    Customer</div>
                                <div id="cfCustomerName" color:#0f172a;">—</div>
                            </div>
                            <div>
                                <div style="color:#3b3b3b; font-size:.72rem; text-transform:uppercase; margin-bottom:3px;">
                                    Phone</div>
                                <div id="cfCustomerPhone" color:#0f172a;">—</div>
                            </div>
                            <div style="grid-column:1/-1;">
                                <div style="color:#3b3b3b; font-size:.72rem; text-transform:uppercase; margin-bottom:3px;">
                                    Route</div>
                                <div id="cfRoute" color:#0f172a; line-height:1.4;">—</div>
                            </div>
                            <div>
                                <div style="color:#3b3b3b; font-size:.72rem; text-transform:uppercase; margin-bottom:3px;">
                                    Vehicle Type</div>
                                <div id="cfTruckType" color:#0f172a;">—</div>
                            </div>
                            <div>
                                <div style="color:#3b3b3b; font-size:.72rem; text-transform:uppercase; margin-bottom:3px;">
                                    Distance</div>
                                <div id="cfDistance" color:#0f172a;">—</div>
                            </div>
                        </div>
                        <div
                            style="margin-top:14px; padding:10px 14px; background:#fff; border:1px solid #000000; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:.85rem; color:#000000;">Agreed Total (Price
                                Locked)</span>
                            <span id="cfAgreedTotal" style="font-size:1.1rem; color:#000000;">—</span>
                        </div>

                        {{-- Assigned unit card --}}
                        <div id="cfUnitBox" style="display:none; margin-top:12px; background:#ffffff; padding:14px 16px;">
                            <div
                                style="font-size:.65rem; text-transform:uppercase; letter-spacing:.08em; color:#000000; margin-bottom:10px;">
                                Assigned Unit</div>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px 16px;">
                                <div>
                                    <div
                                        style="font-size:.62rem; text-transform:uppercase; color:#000000; margin-bottom:2px;">
                                        Unit</div>
                                    <div id="cfUnitName" style="font-size:.92rem;  color:#000000;">—</div>
                                </div>
                                <div>
                                    <div
                                        style="font-size:.62rem;  text-transform:uppercase; color:#000000; margin-bottom:2px;">
                                        Type</div>
                                    <div id="cfUnitType" style="font-size:.88rem;  color:#000000;">—</div>
                                </div>
                                <div style="grid-column:1/-1;">
                                    <div
                                        style="font-size:.62rem;  text-transform:uppercase; color:#000000; margin-bottom:2px;">
                                        Team Leader</div>
                                    <div id="cfUnitTl" style="font-size:.88rem;  color:#000000;">—</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="quotationReviewGrid" class="quotation-review-grid">
                    <div class="review-surface">
                        <h4>Review Form</h4>

                        <div class="review-form-horizontal">
                            <div class="modal-input modal-field">
                                <label for="distanceInput" class="field-label">Distance (km)</label>
                                <input type="number" id="distanceInput" class="review-field-input" min="0.01"
                                    step="0.01" placeholder="0.00" required>
                                <small class="inline-field-error" id="distanceInputError"></small>
                            </div>

                            <div class="modal-input modal-field">
                                <label for="distanceFeeInput" class="field-label">Distance Fee</label>
                                <input type="text" id="distanceFeeInput" class="review-field-input" readonly>
                                <small class="inline-field-error" id="distanceFeeInputError"></small>
                            </div>

                            <div id="priceWrapper" class="modal-input modal-field">
                                <label for="priceInput" class="field-label">Additional Fee</label>
                                <input type="text" id="priceInput" class="review-field-input" inputmode="decimal"
                                    placeholder="0.00" autocomplete="off" />
                                <small class="field-help" id="priceHelper">Leave blank if no dispatcher adjustment is
                                    needed.</small>
                                <small class="inline-field-error" id="priceInputError"></small>
                            </div>

                            <div id="dispatchZoneWrapper" class="modal-input modal-field full-span">
                                <label for="dispatchZoneDisplay" class="field-label">Dispatch Zone</label>
                                <input type="text" id="dispatchZoneDisplay" class="review-field-input is-locked"
                                    readonly>
                                <small class="field-help">Automatically detected from customer's pickup address</small>
                            </div>

                            <div class="modal-input modal-field full-span">
                                <label class="field-label">Final Total</label>
                                <div class="computed-total" id="finalTotalPreview">₱0.00</div>
                            </div>
                        </div>
                    </div>

                    <div class="review-surface">
                        <h4>Summary Card</h4>
                        <div class="review-summary-list">
                            <div class="review-summary-row"><span>Distance</span><strong id="summaryDistance">0.00
                                    km</strong></div>
                            <div class="review-summary-row"><span>Base Rate (Unit)</span><strong
                                    id="summaryBase">TBD</strong>
                            </div>
                            <div class="review-summary-row"><span>Distance Fee (per-4km)</span><strong
                                    id="summaryDistanceFee">₱0.00</strong></div>
                            <div class="review-summary-row"><span>Additional Fee</span><strong
                                    id="summaryAdditional">₱0.00</strong></div>
                            <div class="review-summary-row total"><span>Final Total</span><strong
                                    id="summaryTotal">₱0.00</strong></div>
                        </div>
                    </div>
                </div>

                {{-- Unit selector — shown outside the pricing grid for all accept actions --}}
                <div id="unitWrapper" class="modal-input modal-field" style="display:none; margin-top:14px;">
                    <label for="unitSelect" class="field-label">Available Unit</label>
                    <div class="unit-select-shell">
                        <select id="unitSelect" class="review-field-select" required>
                            <option value="">Select available unit</option>
                            @forelse ($availableUnits as $unit)
                                <option value="{{ $unit['id'] }}" data-selectable="1"
                                    data-team-leader="{{ e($unit['team_leader_name']) }}"
                                    data-driver="{{ e($unit['driver_name']) }}"
                                    data-zones="{{ e(implode(', ', $unit['coverage_zones'] ?? [])) }}"
                                    data-summary="{{ e($unit['status_summary']) }}"
                                    data-base-rate="{{ $unit['base_rate'] ?? 0 }}">
                                    {{ $unit['label'] }} · {{ $unit['team_leader_name'] }}
                                </option>
                            @empty
                                <option value="" disabled>No online ready units available</option>
                            @endforelse
                        </select>
                    </div>
                    <small class="field-help" id="unitHelper">Only units with online available team leaders are shown
                        here.</small>
                    <small class="inline-field-error" id="unitSelectError"></small>
                </div>

                {{-- Dispatcher note — shown for all accept actions --}}
                <div id="dispatcherNoteWrapper" class="modal-input modal-field" style="display:none; margin-top:12px;">
                    <label for="dispatcherNoteInput" class="field-label">Notes (optional)</label>
                    <textarea id="dispatcherNoteInput" class="review-field-input" rows="2"
                        placeholder="Add any dispatcher notes or instructions for the team leader..."></textarea>
                </div>

                <div id="negotiationHint" class="modal-input modal-field" style="display:none;">
                    <label class="field-label">Latest customer request</label>
                    <small id="negotiationHintText"></small>
                </div>

                <div id="rejectReasonWrapper" class="modal-input modal-field">
                    <label for="rejectReasonInput" class="field-label">Rejection reason</label>
                    <input type="text" id="rejectReasonInput" placeholder="Enter rejection reason..." />
                </div>

                <div id="quoteValidationSummary" class="quote-validation-summary" aria-live="polite"></div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" id="cancelModalBtn">
                        Cancel
                    </button>

                    <button type="button" class="btn-primary" id="confirmActionBtn" disabled>
                        Confirm
                    </button>
                </div>

            </div>
        </div>

        <div id="dpConfirmModal"
            style="display:none;position:fixed;inset:0;z-index:10000;align-items:center;justify-content:center;background:rgba(15,23,42,.45);backdrop-filter:blur(2px);"
            aria-modal="true" role="dialog" hidden>
            <div
                style="background:#fff;padding:28px 28px 24px;max-width:400px;width:90%;box-shadow:0 24px 60px rgba(0,0,0,.18);">
                <div
                    style="width:44px;height:44px;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                    <svg width="22" height="22" fill="none" stroke="#dc2626" stroke-width="2"
                        viewBox="0 0 24 24">
                        <polyline points="3 6 5 6 21 6" />
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                        <path d="M10 11v6" />
                        <path d="M14 11v6" />
                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                    </svg>
                </div>
                <h3 style="margin:0 0 6px;font-size:1rem;color:#0f172a;">Remove Unit</h3>
                <p id="dpConfirmBody" style="margin:0 0 22px;font-size:.88rem;color:#64748b;line-height:1.5;">Remove the
                    assigned unit from this team leader?</p>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button id="dpConfirmCancel" type="button"
                        style="padding:9px 18px;border:1px solid #e5e7eb;background:#fff;color:#374151;font-size:.88rem;cursor:pointer;">Cancel</button>
                    <button id="dpConfirmOk" type="button"
                        style="padding:9px 18px;border:none;background:#dc2626;color:#fff;font-size:.88rem;cursor:pointer;">Remove</button>
                </div>
            </div>
        </div>

        <div id="completeJobModal"
            style="display:none;position:fixed;inset:0;z-index:10000;align-items:flex-start;justify-content:center;background:rgba(15,23,42,.5);backdrop-filter:blur(4px);padding:20px 16px;overflow-y:auto;"
            aria-modal="true" role="dialog" hidden>
            <div
                style="background:#fff;width:100%;max-width:680px;margin:auto;box-shadow:0 32px 80px rgba(0,0,0,.2);overflow:hidden;display:flex;flex-direction:column;border:1px solid #e2e8f0;">

                <div style="background:#fff;padding:20px 28px 16px;position:relative;">
                    <button id="completeJobClose" type="button" aria-label="Close"
                        style="position:absolute;top:14px;right:16px;width:28px;height:28px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:1rem;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;">&#x2715;</button>
                    <div
                        style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;">
                        <img src="{{ asset('customer/image/accridetedlogo.png') }}" alt="MMDA Accredited"
                            style="height:80px;width:auto;object-fit:contain;">
                        <div style="text-align:center;flex:1;">
                            <div
                                style="font-size:1.5rem;color:#0f172a;letter-spacing:.06em;text-transform:uppercase;line-height:1;">
                                TowMate</div>
                            <div
                                style="font-size:.65rem;color:#000000;letter-spacing:.12em;text-transform:uppercase;margin-top:4px;">
                                Towing Management System</div>
                        </div>
                        <img src="{{ asset('customer/image/TowingLogo.png') }}" alt="Jarz Towing"
                            style="height:80px;width:auto;object-fit:contain;">
                    </div>
                    <div
                        style="border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;padding:10px 0;text-align:center;">
                        <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.14em;color:#b45309;">
                            Job Completion Record</div>
                        <div id="cjRefBadge"
                            style="font-size:.8rem;color:#475569;font-family:monospace;letter-spacing:.06em;margin-top:3px;">
                        </div>
                    </div>
                </div>

                {{-- ── PRICE SUMMARY BAND ── --}}
                <div
                    style="background:linear-gradient(90deg,#fef9c3 0%,#fef3c7 100%);padding:16px 28px;border-bottom:1px solid #fde68a;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div>
                            <div
                                style="font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:#92400e;margin-bottom:3px;">
                                Total Amount Collected</div>
                            <div id="cjTotalBig"
                                style="font-size:2rem;color:#0f172a;letter-spacing:-.02em;line-height:1;">—
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 28px;text-align:right;">
                            <div style="font-size:.75rem;color:#78716c;white-space:nowrap;">Base Rate</div>
                            <div id="cjBaseRate" style="font-size:.75rem;color:#1c1917;">—</div>
                            <div style="font-size:.75rem;color:#78716c;white-space:nowrap;">Distance Fee</div>
                            <div id="cjDistanceFee" style="font-size:.75rem;color:#1c1917;">—</div>
                            <div style="font-size:.75rem;color:#78716c;white-space:nowrap;">Additional Fee</div>
                            <div id="cjAdditionalFee" style="font-size:.75rem;color:#1c1917;">—</div>
                            <div id="cjDiscountRow"
                                style="font-size:.75rem;color:#b45309;white-space:nowrap;display:none;">Discount</div>
                            <div id="cjDiscount" style="font-size:.75rem;color:#b45309;display:none;">—
                            </div>
                        </div>
                    </div>
                </div>

                <div style="padding:20px 28px;display:flex;flex-direction:column;gap:18px;background:#fafafa;">

                    <div>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                            <div
                                style="font-size:.62rem;text-transform:uppercase;letter-spacing:.12em;color:#000000;white-space:nowrap;">
                                Customer Information</div>
                            <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                        </div>
                        <div style="border:1px solid #e2e8f0;overflow:hidden;background:#fff;">
                            <div style="display:grid;grid-template-columns:1fr 1fr;">
                                <div
                                    style="padding:10px 14px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Full Name</div>
                                    <div id="cjCustomerName" style="font-size:.85rem;color:#0f172a;">—
                                    </div>
                                </div>
                                <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Customer Type</div>
                                    <div id="cjCustomerType" style="font-size:.85rem;color:#0f172a;">—
                                    </div>
                                </div>
                                <div
                                    style="padding:10px 14px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Phone</div>
                                    <div id="cjCustomerPhone" style="font-size:.85rem;color:#0f172a;">—
                                    </div>
                                </div>
                                <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Email</div>
                                    <div id="cjCustomerEmail" style="font-size:.8rem;color:#0f172a;word-break:break-all;">
                                        —</div>
                                </div>
                                <div style="padding:10px 14px;border-right:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Pickup</div>
                                    <div id="cjPickup" style="font-size:.8rem;color:#0f172a;line-height:1.4;">—</div>
                                </div>
                                <div style="padding:10px 14px;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Drop-off</div>
                                    <div id="cjDropoff" style="font-size:.8rem;color:#0f172a;line-height:1.4;">—</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <div
                                style="font-size:.62rem;text-transform:uppercase;letter-spacing:.12em;color:#000000;white-space:nowrap;">
                                Payment Information</div>
                        </div>
                        <div style="border:1px solid #e2e8f0;overflow:hidden;background:#fff;">
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;">
                                <div style="padding:10px 14px;border-right:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Mode</div>
                                    <div id="cjPaymentMode" style="font-size:.88rem;color:#0f172a;">—
                                    </div>
                                </div>
                                <div style="padding:10px 14px;border-right:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Status</div>
                                    <div id="cjPaymentStatus" style="font-size:.88rem;color:#0f172a;">—
                                    </div>
                                </div>
                                <div style="padding:10px 14px;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Reference #</div>
                                    <div id="cjPaymongoRef" style="font-size:.78rem;color:#0f172a;word-break:break-all;">—
                                    </div>
                                </div>
                            </div>
                            <div id="cjProofRow" style="display:none;border-top:1px solid #f1f5f9;padding:12px 14px;">
                                <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:8px;">
                                    Proof of Payment</div>
                                <div id="cjProofImagesContainer" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
                                <p style="margin:6px 0 0;font-size:.7rem;color:#000000;text-align:center;">Click an image
                                    to open full size</p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                            <div style="font-size:.62rem;text-transform:uppercase;color:#000000;white-space:nowrap;">
                                Unit &amp; Team Details</div>
                            <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                        </div>
                        <div style="border:1px solid #e2e8f0;overflow:hidden;background:#fff;">
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;">
                                <div
                                    style="padding:10px 14px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Unit Name</div>
                                    <div id="cjUnitName" style="font-size:.85rem;color:#0f172a;">—</div>
                                </div>
                                <div
                                    style="padding:10px 14px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Plate No.</div>
                                    <div id="cjUnitPlate" style="font-size:.85rem;color:#0f172a;">—</div>
                                </div>
                                <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Truck Type</div>
                                    <div id="cjTruckType" style="font-size:.85rem;color:#0f172a;">—</div>
                                </div>
                                <div style="padding:10px 14px;border-right:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Base Rate (Type)</div>
                                    <div id="cjTruckBaseRate" style="font-size:.85rem;color:#0f172a;">—
                                    </div>
                                </div>
                                <div style="padding:10px 14px;border-right:1px solid #f1f5f9;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Team Leader</div>
                                    <div id="cjTlName" style="font-size:.85rem;color:#0f172a;">—</div>
                                </div>
                                <div style="padding:10px 14px;">
                                    <div style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                        Driver</div>
                                    <div id="cjDriverName" style="font-size:.85rem;color:#0f172a;">—</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Vehicle Photo (optional) --}}
                    <div id="cjVehiclePhotoWrap" style="display:none;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                            <div
                                style="font-size:.62rem;text-transform:uppercase;letter-spacing:.12em;color:#000000;white-space:nowrap;">
                                Vehicle Photo</div>
                            <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                        </div>
                        <a id="cjVehicleLink" href="#" target="_blank" rel="noopener noreferrer"
                            style="display:block;overflow:hidden;border:1px solid #e2e8f0;max-height:180px;background:#f8fafc;">
                            <img id="cjVehicleImg" src="" alt="Vehicle"
                                style="width:100%;max-height:180px;object-fit:cover;display:block;">
                        </a>
                    </div>

                </div>

                <div
                    style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 28px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                    <div style="font-size:.65rem;color:#000000;line-height:1.5;">
                        Generated by TowMate &middot; {{ date('M d, Y') }}<br>
                        <span>Receipt will be emailed to customer on completion.</span>
                    </div>
                    <div style="display:flex;gap:10px;flex-shrink:0;">
                        <button id="completeJobCancel" type="button"
                            style="padding:9px 18px;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:.87rem;cursor:pointer;">
                            Cancel
                        </button>
                        <button id="completeJobOk" type="button"
                            style="padding:9px 22px;border:none;background:#facc15;color:#0f172a;font-size:.87rem;cursor:pointer;display:flex;align-items:center;gap:6px;">
                            Mark as Completed
                        </button>
                    </div>
                </div>

            </div>
        </div>

        {{-- <div class="dp-tl-section">
            <div class="dp-tl-header">
                <div>
                    <h3 class="dp-tl-title">Team Leaders</h3>
                </div>
                <a href="{{ route('admin.drivers') }}" class="dp-tl-link">Full view &rarr;</a>
            </div>

            @php
                $dpStatuses = $teamLeaderStatuses ?? collect();
                $dpTLs = \App\Models\User::visibleToOperations()
                    ->where('role_id', 3)
                    ->with(['unit'])
                    ->get()
                    ->filter(fn($tl) => $tl->unit !== null && $tl->unit->status === 'available');
            @endphp

            @if ($dpTLs->isEmpty())
                <div class="dp-tl-empty">No team leaders with assigned units found.</div>
            @else
                <div class="dp-tl-table-wrap">
                    <table class="dp-tl-table">
                        <thead>
                            <tr>
                                <th>Team Leader</th>
                                <th>Unit</th>
                                <th>Zone</th>
                                <th>Status</th>
                                <th>Override Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dpTLs as $dtl)
                                @php
                                    $ds = $dpStatuses->get($dtl->id) ?? [];
                                    $dOnline = ($ds['presence'] ?? 'offline') === 'online';
                                    $dHasUnit = !empty($ds['unit_name']) && $ds['unit_name'] !== 'No assigned unit';
                                    $dWorkload = $ds['workload'] ?? 'unavailable';
                                    $dUnitSt = $ds['unit_status'] ?? null;
                                    $dZone = $ds['zone_name'] ?? ($dtl->unit?->zone?->name ?? null);

                                    // Offline forces Not Available
                                    if (!$dOnline) {
                                        $dBadgeLabel = 'Not Available';
                                        $dBadgeCls = 'dp-badge--off';
                                        $dForced = true;
                                        $dSelVal = 'unavailable';
                                        $dUnitLocked = true;
                                    } elseif ($dUnitSt === 'on_tow') {
                                        $dBadgeLabel = 'On Tow';
                                        $dBadgeCls = 'dp-badge--tow';
                                        $dForced = false;
                                        $dSelVal = 'on_tow';
                                        $dUnitLocked = true;
                                    } elseif ($dWorkload === 'busy') {
                                        $dBadgeLabel = 'Deployed';
                                        $dBadgeCls = 'dp-badge--busy';
                                        $dForced = false;
                                        $dSelVal = 'on_job';
                                        $dUnitLocked = true;
                                    } elseif ($dUnitSt === 'unavailable') {
                                        $dBadgeLabel = 'Not Available';
                                        $dBadgeCls = 'dp-badge--off';
                                        $dForced = false;
                                        $dSelVal = 'unavailable';
                                        $dUnitLocked = true;
                                    } elseif ($dWorkload === 'idle') {
                                        $dBadgeLabel = 'Idle';
                                        $dBadgeCls = 'dp-badge--idle';
                                        $dForced = true;
                                        $dSelVal = '';
                                        $dUnitLocked = false;
                                    } else {
                                        $dBadgeLabel = 'Available';
                                        $dBadgeCls = 'dp-badge--avail';
                                        $dForced = false;
                                        $dSelVal = 'available';
                                        $dUnitLocked = false;
                                    }

                                    $dParts = explode(' ', trim($dtl->name));
                                    $dInitials = strtoupper(
                                        substr($dParts[0], 0, 1) .
                                            (count($dParts) > 1 ? substr(end($dParts), 0, 1) : ''),
                                    );
                                @endphp
                                <tr class="dp-tl-row" data-tlid="{{ $dtl->id }}">
                                    <td>
                                        <div class="dp-name-cell">
                                            <div class="dp-avatar">{{ $dInitials }}</div>
                                            <div>
                                                <div class="dp-name">{{ $dtl->name }}</div>
                                                <div
                                                    class="dp-presence dp-presence--{{ $dOnline ? 'online' : 'offline' }}">
                                                    {{ $dOnline ? 'Online' : 'Offline' }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="dp-unit-cell" id="dpUnit-{{ $dtl->id }}">
                                        {{ $dHasUnit ? $ds['unit_name'] ?? ($dtl->unit?->name ?? '—') : $dtl->unit?->name ?? '—' }}
                                    </td>
                                    <td>
                                        @if ($dZone)
                                            <span class="dp-zone-badge">{{ $dZone }}</span>
                                        @else
                                            <span class="dp-zone-none">No zone</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="dp-status-badge {{ $dBadgeCls }}">{{ $dBadgeLabel }}</span>
                                    </td>
                                    <td>
                                        <div class="dp-ctrl-cell">
                                            <select class="dp-select dp-status-sel" data-tlid="{{ $dtl->id }}"
                                                data-current="{{ $dSelVal }}"
                                                data-online="{{ $dOnline ? '1' : '0' }}"
                                                {{ $dForced ? 'disabled' : '' }}>
                                                <option value="available"
                                                    {{ $dSelVal === 'available' ? 'selected' : '' }}>Available</option>
                                                <option value="unavailable"
                                                    {{ $dSelVal === 'unavailable' ? 'selected' : '' }}>Not Available
                                                </option>
                                                <option value="on_tow" {{ $dSelVal === 'on_tow' ? 'selected' : '' }}>On
                                                    Tow</option>
                                                <option value="on_job" {{ $dSelVal === 'on_job' ? 'selected' : '' }}>On
                                                    Job</option>
                                            </select>
                                            <span class="dp-saving" id="dpStSaving-{{ $dtl->id }}"></span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div> --}}

    </div>{{-- /.dashboard-container --}}

    {{-- Service Fee Modal --}}
    <div class="sf-modal-backdrop" id="serviceFeeModal" aria-hidden="true">
        <div class="sf-modal-card">
            <h3>💰 Apply Service Fee</h3>
            <p>Apply a cancellation service fee to this booking.</p>

            <label for="serviceFeeAmount">Service Fee Amount (₱)</label>
            <input type="number" id="serviceFeeAmount" min="0" step="0.01" placeholder="0.00" required>

            <label for="serviceFeeReason">Reason</label>
            <textarea id="serviceFeeReason" placeholder="Customer cancelled after unit was dispatched..." required></textarea>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn--cancel" id="cancelServiceFeeBtn">Cancel</button>
                <button type="button" class="sf-btn sf-btn--primary" id="confirmServiceFeeBtn">Apply Fee</button>
            </div>
        </div>
    </div>

    {{-- Customer Risk Modal --}}
    <div class="sf-modal-backdrop" id="customerRiskModal" aria-hidden="true">
        <div class="sf-modal-card">
            <h3>🚩 Mark Customer Risk</h3>
            <p>Flag this customer for future reference.</p>

            <label for="riskLevel">Risk Level</label>
            <select id="riskLevel" required>
                <option value="">Select risk level</option>
                <option value="low">Low - Minor issue</option>
                <option value="medium">Medium - Repeated issues</option>
                <option value="high">High - Serious concern</option>
                <option value="blacklist">Blacklist - Do not serve</option>
            </select>

            <label for="riskReason">Reason</label>
            <textarea id="riskReason" placeholder="Customer cancelled multiple times after dispatch..." required></textarea>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn--cancel" id="cancelRiskBtn">Cancel</button>
                <button type="button" class="sf-btn sf-btn--primary" id="confirmRiskBtn">Mark Customer</button>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/dispatch.js') }}?v={{ filemtime(public_path('dispatcher/js/dispatch.js')) }}">
    </script>
    <script>
        (function() {
            var CSRF = document.querySelector('meta[name="csrf-token"]').content;

            function setSaving(id, on) {
                var el = document.getElementById(id);
                if (el) {
                    el.textContent = on ? 'Saving…' : '';
                    el.style.color = '#000000';
                }
            }

            function showMsg(id, msg, ok) {
                var el = document.getElementById(id);
                if (!el) return;
                el.textContent = msg;
                el.style.color = ok ? '#16a34a' : '#dc2626';
                setTimeout(function() {
                    el.textContent = '';
                }, 3500);
            }

            /* Enforce offline = disabled on load */
            document.querySelectorAll('.dp-status-sel').forEach(function(sel) {
                if (sel.dataset.online === '0') {
                    sel.disabled = true;
                    sel.value = 'unavailable';
                }
            });

            /* Status override — same endpoint as Team Leaders module */
            document.querySelectorAll('.dp-status-sel').forEach(function(sel) {
                sel.addEventListener('change', function() {
                    var tlid = sel.dataset.tlid;
                    var value = sel.value;
                    setSaving('dpStSaving-' + tlid, true);
                    sel.disabled = true;

                    var fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('_method', 'PATCH');
                    fd.append('unit_status', value);

                    fetch('/admin-dashboard/drivers/' + tlid + '/override', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json'
                            },
                            body: fd
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            setSaving('dpStSaving-' + tlid, false);
                            sel.disabled = (sel.dataset.online === '0');
                            if (data.errors) {
                                var msg = typeof data.errors === 'string' ? data.errors : Object
                                    .values(data.errors)[0];
                                showMsg('dpStSaving-' + tlid, msg, false);
                                sel.value = sel.dataset.current;
                                return;
                            }
                            sel.dataset.current = value;

                            // Lock or unlock the unit select based on new status
                            var unitSel = sel.closest('tr').querySelector('.dp-unit-sel');
                            if (unitSel) {
                                unitSel.disabled = ['unavailable', 'on_tow', 'on_job'].indexOf(
                                    value) !== -1;
                            }

                            // If unit was released, hide the row (TL no longer has a unit)
                            if (data.unit_released) {
                                var row = sel.closest('tr');
                                if (row) {
                                    row.style.transition = 'opacity .3s';
                                    row.style.opacity = '0';
                                    setTimeout(function() {
                                        row.remove();
                                    }, 320);
                                }
                                return;
                            }

                            /* Sync the status badge in the same row */
                            var row = sel.closest('tr');
                            var badge = row ? row.querySelector('.dp-status-badge') : null;
                            var map = {
                                available: ['Available', 'dp-badge--avail'],
                                unavailable: ['Not Available', 'dp-badge--off'],
                                on_tow: ['On Tow', 'dp-badge--tow'],
                                on_job: ['Deployed', 'dp-badge--busy'],
                            };
                            if (badge && map[value]) {
                                badge.textContent = map[value][0];
                                badge.className = 'dp-status-badge ' + map[value][1];
                            }
                            showMsg('dpStSaving-' + tlid, 'Saved', true);
                        })
                        .catch(function() {
                            setSaving('dpStSaving-' + tlid, false);
                            sel.disabled = (sel.dataset.online === '0');
                            sel.value = sel.dataset.current;
                            showMsg('dpStSaving-' + tlid, 'Error', false);
                        });
                });
            });

            /* ── Confirm modal ── */
            var _dpConfirmResolve = null;
            var dpConfirmModal = document.getElementById('dpConfirmModal');
            var dpConfirmOk = document.getElementById('dpConfirmOk');
            var dpConfirmCancel = document.getElementById('dpConfirmCancel');
            var dpConfirmBody = document.getElementById('dpConfirmBody');

            function dpShowConfirm(message) {
                return new Promise(function(resolve) {
                    _dpConfirmResolve = resolve;
                    dpConfirmBody.textContent = message;
                    dpConfirmModal.hidden = false;
                    dpConfirmModal.style.display = 'flex';
                    dpConfirmOk.focus();
                });
            }

            function dpCloseConfirm(result) {
                dpConfirmModal.hidden = true;
                dpConfirmModal.style.display = 'none';
                if (_dpConfirmResolve) {
                    _dpConfirmResolve(result);
                    _dpConfirmResolve = null;
                }
            }
            dpConfirmOk.addEventListener('click', function() {
                dpCloseConfirm(true);
            });
            dpConfirmCancel.addEventListener('click', function() {
                dpCloseConfirm(false);
            });
            dpConfirmModal.addEventListener('click', function(e) {
                if (e.target === dpConfirmModal) dpCloseConfirm(false);
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && !dpConfirmModal.hidden) dpCloseConfirm(false);
            });

            /* Remove unit — same endpoint as Team Leaders module */
            document.querySelectorAll('.dp-remove-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var tlid = btn.dataset.tlid;
                    dpShowConfirm(
                            'Remove the assigned unit from this team leader? They will be set to Idle.')
                        .then(function(confirmed) {
                            if (!confirmed) return;
                            btn.disabled = true;

                            var fd = new FormData();
                            fd.append('_token', CSRF);

                            fetch('/admin-dashboard/drivers/' + tlid + '/remove-unit', {
                                    method: 'POST',
                                    headers: {
                                        'Accept': 'application/json'
                                    },
                                    body: fd
                                })
                                .then(function(r) {
                                    return r.json();
                                })
                                .then(function(data) {
                                    if (data.errors) {
                                        showMsg('dpUnSaving-' + tlid, typeof data.errors ===
                                            'string' ? data.errors : Object.values(data
                                                .errors)[0], false);
                                        btn.disabled = false;
                                        return;
                                    }
                                    var row = btn.closest('tr');
                                    if (row) {
                                        row.style.transition = 'opacity .3s';
                                        row.style.opacity = '0';
                                        setTimeout(function() {
                                            row.remove();
                                        }, 320);
                                    }
                                })
                                .catch(function() {
                                    btn.disabled = false;
                                    showMsg('dpUnSaving-' + tlid, 'Error', false);
                                });
                        });
                });
            });

            /* Unit assignment — same endpoint as Team Leaders module */
            document.querySelectorAll('.dp-unit-sel').forEach(function(sel) {
                sel.addEventListener('change', function() {
                    var tlid = sel.dataset.tlid;
                    var unitId = sel.value;
                    if (!unitId) return;
                    setSaving('dpUnSaving-' + tlid, true);
                    sel.disabled = true;

                    var fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('unit_id', unitId);

                    fetch('/admin-dashboard/drivers/' + tlid + '/assign-unit', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json'
                            },
                            body: fd
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            setSaving('dpUnSaving-' + tlid, false);
                            sel.disabled = false;
                            if (data.errors) {
                                var msg = typeof data.errors === 'string' ? data.errors : Object
                                    .values(data.errors)[0];
                                showMsg('dpUnSaving-' + tlid, msg, false);
                                sel.value = '';
                                return;
                            }
                            /* Update unit name cell */
                            var unitCell = document.getElementById('dpUnit-' + tlid);
                            if (unitCell && data.assigned_unit && data.assigned_unit.name) {
                                unitCell.textContent = data.assigned_unit.name;
                            }
                            showMsg('dpUnSaving-' + tlid, 'Saved', true);
                        })
                        .catch(function() {
                            setSaving('dpUnSaving-' + tlid, false);
                            sel.disabled = false;
                            showMsg('dpUnSaving-' + tlid, 'Error', false);
                        });
                });
            });
        })();
    </script>

    <script>
        // Return Reason Action Handlers
        (function() {
            var CSRF = document.querySelector('meta[name="csrf-token"]').content;
            var serviceFeeModal = document.getElementById('serviceFeeModal');
            var customerRiskModal = document.getElementById('customerRiskModal');
            var currentBookingId = null;
            var currentCustomerId = null;

            // Handle return reason action buttons
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.rr-action-btn');
                if (!btn) return;

                e.preventDefault();
                e.stopPropagation();

                var action = btn.dataset.action;
                currentBookingId = btn.dataset.bookingId;
                currentCustomerId = btn.dataset.customerId;

                switch (action) {
                    case 'apply_service_fee':
                        openServiceFeeModal();
                        break;
                    case 'mark_customer_risk':
                        openCustomerRiskModal();
                        break;
                    case 'reassign_correct_unit':
                    case 'reassign_urgently':
                    case 'reassign':
                        // Trigger the existing reassign flow by dispatching a synthetic click event
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var acceptBtn = card.querySelector('.btn-accept');
                            if (acceptBtn && !acceptBtn.disabled) {
                                var clickEvent = new MouseEvent('click', {
                                    bubbles: true,
                                    cancelable: true,
                                    view: window
                                });
                                acceptBtn.dispatchEvent(clickEvent);
                            }
                        }
                        break;
                    case 'mark_unit_maintenance':
                        var card = btn.closest('.incoming-card');
                        var unitId = card ? card.dataset.assignedUnit : null;
                        if (!unitId) {
                            alert('No unit assigned to this booking');
                            return;
                        }
                        if (confirm(
                                'Mark this unit for maintenance? It will be set to unavailable and require maintenance review.'
                            )) {
                            fetch('/admin-dashboard/units/' + unitId + '/maintenance', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': CSRF,
                                        'Accept': 'application/json',
                                    },
                                    body: JSON.stringify({
                                        reason: 'Vehicle issue reported during booking ' +
                                            currentBookingId,
                                        booking_id: currentBookingId
                                    })
                                })
                                .then(function(r) {
                                    return r.json();
                                })
                                .then(function(data) {
                                    if (data.success) {
                                        alert('Unit marked for maintenance successfully');
                                        location.reload();
                                    } else {
                                        alert(data.message || 'Failed to mark unit for maintenance');
                                    }
                                })
                                .catch(function() {
                                    alert('Error marking unit for maintenance');
                                });
                        }
                        break;
                    case 'assign_different_unit':
                        // Open reassign modal and filter out the problematic unit
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var acceptBtn = card.querySelector('.btn-accept');
                            if (acceptBtn && !acceptBtn.disabled) {
                                var currentUnit = card.dataset.assignedUnit;
                                acceptBtn.click();
                                // After modal opens, disable the current unit in the dropdown
                                setTimeout(function() {
                                    var unitSelect = document.getElementById('unitSelect');
                                    if (unitSelect && currentUnit) {
                                        var option = unitSelect.querySelector('option[value="' +
                                            currentUnit + '"]');
                                        if (option) {
                                            option.disabled = true;
                                            option.textContent += ' (Unavailable - Vehicle Issue)';
                                        }
                                        // Pre-fill dispatcher note
                                        var noteField = document.getElementById('dispatcherNoteInput');
                                        if (noteField) {
                                            noteField.value =
                                                'Reassigning to different unit due to vehicle issue with previous unit.';
                                        }
                                    }
                                }, 100);
                            }
                        }
                        break;
                    case 'requote_booking':
                        // Open reassign modal with note about vehicle mismatch
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var acceptBtn = card.querySelector('.btn-accept');
                            if (acceptBtn && !acceptBtn.disabled) {
                                acceptBtn.click();
                                // Pre-fill dispatcher note
                                setTimeout(function() {
                                    var noteField = document.getElementById('dispatcherNoteInput');
                                    if (noteField) {
                                        noteField.value =
                                            'Re-quoting due to vehicle information mismatch. Please verify vehicle details with customer before dispatch.';
                                    }
                                }, 100);
                            }
                        }
                        break;
                    case 'contact_team_leader':
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var tlName = card.dataset.returnedBy || 'Team Leader';
                            alert('Contact Team Leader: ' + tlName +
                                '\n\nPlease follow up to understand the situation and determine the best course of action.'
                            );
                        }
                        break;
                    case 'contact_customer':
                    case 'attempt_contact':
                    case 'contact_for_access':
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var phone = card.querySelector('.incoming-details span:nth-child(2)');
                            if (phone) {
                                alert('Contact customer at: ' + phone.textContent.replace('Phone:', '').trim());
                            }
                        }
                        break;
                    case 'cancel_booking':
                    case 'cancel_with_reason':
                    case 'cancel_if_unresolved':
                        // Trigger the existing reject flow by dispatching a synthetic click event
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var rejectBtn = card.querySelector('.btn-reject');
                            if (rejectBtn && !rejectBtn.disabled) {
                                var clickEvent = new MouseEvent('click', {
                                    bubbles: true,
                                    cancelable: true,
                                    view: window
                                });
                                rejectBtn.dispatchEvent(clickEvent);
                            }
                        }
                        break;
                    default:
                        console.warn('Unhandled return reason action:', action);
                        alert('Action "' + action +
                            '" is not yet implemented. Please use the standard reassign or cancel buttons.');
                }
            });

            // Service Fee Modal
            function openServiceFeeModal() {
                serviceFeeModal.classList.add('show');
                serviceFeeModal.setAttribute('aria-hidden', 'false');
                document.getElementById('serviceFeeAmount').value = '';
                document.getElementById('serviceFeeReason').value = '';
                setTimeout(function() {
                    document.getElementById('serviceFeeAmount').focus();
                }, 100);
            }

            function closeServiceFeeModal() {
                serviceFeeModal.classList.remove('show');
                serviceFeeModal.setAttribute('aria-hidden', 'true');
            }

            document.getElementById('cancelServiceFeeBtn').addEventListener('click', closeServiceFeeModal);
            serviceFeeModal.addEventListener('click', function(e) {
                if (e.target === serviceFeeModal) closeServiceFeeModal();
            });

            document.getElementById('confirmServiceFeeBtn').addEventListener('click', function() {
                var amount = document.getElementById('serviceFeeAmount').value;
                var reason = document.getElementById('serviceFeeReason').value;

                if (!amount || !reason) {
                    alert('Please fill in all fields');
                    return;
                }

                this.disabled = true;
                this.textContent = 'Applying...';

                fetch('/admin-dashboard/booking/' + currentBookingId + '/service-fee', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': CSRF,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            service_fee_amount: amount,
                            service_fee_reason: reason,
                        })
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            alert(data.message);
                            closeServiceFeeModal();
                            location.reload();
                        } else {
                            alert(data.message || 'Failed to apply service fee');
                        }
                    })
                    .catch(function() {
                        alert('Error applying service fee');
                    })
                    .finally(function() {
                        document.getElementById('confirmServiceFeeBtn').disabled = false;
                        document.getElementById('confirmServiceFeeBtn').textContent = 'Apply Fee';
                    });
            });

            // Customer Risk Modal
            function openCustomerRiskModal() {
                customerRiskModal.classList.add('show');
                customerRiskModal.setAttribute('aria-hidden', 'false');
                document.getElementById('riskLevel').value = '';
                document.getElementById('riskReason').value = '';
                setTimeout(function() {
                    document.getElementById('riskLevel').focus();
                }, 100);
            }

            function closeCustomerRiskModal() {
                customerRiskModal.classList.remove('show');
                customerRiskModal.setAttribute('aria-hidden', 'true');
            }

            document.getElementById('cancelRiskBtn').addEventListener('click', closeCustomerRiskModal);
            customerRiskModal.addEventListener('click', function(e) {
                if (e.target === customerRiskModal) closeCustomerRiskModal();
            });

            document.getElementById('confirmRiskBtn').addEventListener('click', function() {
                var level = document.getElementById('riskLevel').value;
                var reason = document.getElementById('riskReason').value;

                if (!level || !reason) {
                    alert('Please fill in all fields');
                    return;
                }

                this.disabled = true;
                this.textContent = 'Marking...';

                fetch('/admin-dashboard/booking/' + currentBookingId + '/mark-risk', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': CSRF,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            risk_level: level,
                            risk_reason: reason,
                        })
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            alert(data.message);
                            closeCustomerRiskModal();
                            location.reload();
                        } else {
                            alert(data.message || 'Failed to mark customer risk');
                        }
                    })
                    .catch(function() {
                        alert('Error marking customer risk');
                    })
                    .finally(function() {
                        document.getElementById('confirmRiskBtn').disabled = false;
                        document.getElementById('confirmRiskBtn').textContent = 'Mark Customer';
                    });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeServiceFeeModal();
                    closeCustomerRiskModal();
                }
            });
        })();
    </script>

    <script>
        /* ── Complete Job modal ── */
        (function() {
            var CSRF = document.querySelector('meta[name="csrf-token"]').content;
            var modal = document.getElementById('completeJobModal');
            var okBtn = document.getElementById('completeJobOk');
            var cancel = document.getElementById('completeJobCancel');
            var closeX = document.getElementById('completeJobClose');

            var _pendingUrl = null;
            var _pendingCard = null;

            function fmt(n) {
                var v = parseFloat(n) || 0;
                return '₱' + v.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function setText(id, val) {
                var el = document.getElementById(id);
                if (el) el.textContent = (val !== undefined && val !== null && val !== '') ? val : '—';
            }

            function show(id) {
                var el = document.getElementById(id);
                if (el) el.style.display = '';
            }

            function hide(id) {
                var el = document.getElementById(id);
                if (el) el.style.display = 'none';
            }

            function openModal(btn) {
                var card = btn.closest('.incoming-card');
                var ds = card ? card.dataset : {};

                // ── Price summary ──
                var finalTotal = parseFloat(ds.finalTotal || ds.currentPrice || 0);
                var baseRate = parseFloat(ds.baseRate || ds.truckTypeBaseRate || 0);
                var distanceFee = parseFloat(ds.distanceFeeAmount || ds.distanceFee || 0);
                var additionalFee = parseFloat(ds.currentAdditional || 0);
                var discountPct = parseFloat(ds.discountPercentage || 0);

                setText('cjTotalBig', fmt(finalTotal));
                setText('cjBaseRate', fmt(baseRate));
                setText('cjDistanceFee', fmt(distanceFee) + (ds.distanceKm ? ' (' + parseFloat(ds.distanceKm).toFixed(
                    1) + ' km)' : ''));
                setText('cjAdditionalFee', additionalFee > 0 ? fmt(additionalFee) : '—');

                var discountRow = document.getElementById('cjDiscountRow');
                var discountVal = document.getElementById('cjDiscount');
                if (discountPct > 0) {
                    setText('cjDiscount', '-' + discountPct + '%' + (ds.discountReason ? ' · ' + ds.discountReason :
                        ''));
                    if (discountRow) discountRow.style.display = 'block';
                    if (discountVal) discountVal.style.display = 'block';
                } else {
                    if (discountRow) discountRow.style.display = 'none';
                    if (discountVal) discountVal.style.display = 'none';
                }

                setText('cjRefBadge', '#' + (ds.jobCode || btn.dataset.ref || '—'));

                setText('cjCustomerName', ds.customerName || btn.dataset.customer);
                setText('cjCustomerType', ds.customerType);
                setText('cjCustomerPhone', ds.customerPhone);
                setText('cjCustomerEmail', ds.customerEmail);
                setText('cjPickup', ds.pickup);
                setText('cjDropoff', ds.dropoff);

                setText('cjPaymentMode', ds.paymentMethodLabel || '—');
                setText('cjPaymentStatus', ds.paymentStatusLabel || '—');
                setText('cjPaymongoRef', ds.paymongoRef || '—');

                var proofUrls = [];
                try {
                    proofUrls = JSON.parse(ds.paymentProofUrl || '[]');
                } catch (e) {
                    proofUrls = [];
                }
                if (!Array.isArray(proofUrls)) proofUrls = proofUrls ? [proofUrls] : [];
                var proofContainer = document.getElementById('cjProofImagesContainer');
                if (proofContainer) {
                    proofContainer.innerHTML = '';
                    proofUrls.forEach(function(url) {
                        var a = document.createElement('a');
                        a.href = url;
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                        a.style.cssText =
                            'flex:1 1 calc(50% - 4px);min-width:100px;overflow:hidden;border:1px solid #e2e8f0;background:#f8fafc;display:block;';
                        var img = document.createElement('img');
                        img.src = url;
                        img.alt = 'Payment proof';
                        img.style.cssText = 'width:100%;max-height:180px;object-fit:contain;display:block;';
                        a.appendChild(img);
                        proofContainer.appendChild(a);
                    });
                }
                if (proofUrls.length > 0) {
                    show('cjProofRow');
                } else {
                    hide('cjProofRow');
                }

                setText('cjUnitName', ds.unitName);
                setText('cjUnitPlate', ds.unitPlate);
                setText('cjTruckType', ds.truckType);
                setText('cjTruckBaseRate', ds.truckTypeBaseRate ? fmt(ds.truckTypeBaseRate) : '—');
                setText('cjTlName', ds.tlName);
                setText('cjDriverName', ds.driverName);

                var vehicleUrl = ds.vehicleImageUrl || '';
                if (vehicleUrl) {
                    document.getElementById('cjVehicleImg').src = vehicleUrl;
                    document.getElementById('cjVehicleLink').href = vehicleUrl;
                    show('cjVehiclePhotoWrap');
                } else {
                    hide('cjVehiclePhotoWrap');
                }

                _pendingUrl = btn.dataset.confirmUrl;
                _pendingCard = card;

                modal.hidden = false;
                modal.style.display = 'flex';
                okBtn.disabled = false;
                okBtn.innerHTML =
                    'Mark as Completed';
                okBtn.focus();
            }

            function closeModal() {
                modal.hidden = true;
                modal.style.display = 'none';
                _pendingUrl = null;
                _pendingCard = null;
            }

            function showToast(msg, ok) {
                var t = document.createElement('div');
                t.textContent = msg;
                t.style.cssText =
                    'position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 20px;font-size:13px;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.15);animation:tl-slide-in .25s ease;background:' +
                    (ok ? '#09090b' : '#dc2626');
                document.body.appendChild(t);
                setTimeout(function() {
                    t.remove();
                }, 4000);
            }

            /* Delegate click from any .btn-complete-job button */
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.btn-complete-job');
                if (btn) openModal(btn);
            });

            cancel.addEventListener('click', closeModal);
            if (closeX) closeX.addEventListener('click', closeModal);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && !modal.hidden) closeModal();
            });

            okBtn.addEventListener('click', function() {
                if (!_pendingUrl) return;
                okBtn.disabled = true;
                okBtn.innerHTML = 'Completing…';

                fetch(_pendingUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': CSRF,
                        },
                        body: JSON.stringify({}),
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (!data.success) throw new Error(data.message || 'Failed to complete job.');

                        showToast(data.message || 'Job completed. Receipt sent to customer.', true);
                        closeModal();

                        if (_pendingCard) {
                            _pendingCard.style.transition = 'opacity .35s';
                            _pendingCard.style.opacity = '0';
                            setTimeout(function() {
                                if (_pendingCard) _pendingCard.remove();
                                if (typeof window.applyDispatchQueueFilter === 'function') {
                                    window.applyDispatchQueueFilter(
                                        document.querySelector('.queue-filter-btn.is-active')
                                        ?.dataset.filter || 'ready_completion'
                                    );
                                }
                            }, 360);
                        }
                    })
                    .catch(function(err) {
                        showToast(err.message || 'Something went wrong.', false);
                        okBtn.disabled = false;
                        okBtn.innerHTML =
                            '<svg width="14" height="14" fill="none" stroke="#fff" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Mark as Completed';
                    });
            });
        })();
    </script>
@endpush
