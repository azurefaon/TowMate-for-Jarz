@extends('admin-dashboard.layouts.app')

@section('title', 'Dispatch')

@push('styles')
    <style>
        .quotation-review-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(240px, 0.75fr);
            gap: 10px;
            margin: 10px 0;
            align-items: start;
        }

        #actionModal {
            overflow-y: auto;
            padding: 10px;
        }

        #actionModal .modal-card {
            width: min(1000px, 96vw);
            max-width: 1000px;
            max-height: calc(100vh - 20px);
            overflow-x: hidden;
            overflow-y: auto;
            padding: 16px;
            scrollbar-gutter: stable;
        }

        .review-surface {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
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
            font-size: 0.9rem;
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
            border-radius: 12px;
            background: linear-gradient(135deg, #fef3c7, #fff7ed);
            border: 1px solid #fcd34d;
            font-size: 1.08rem;
            font-weight: 700;
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
            border-radius: 12px;
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
            border-radius: 14px;
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
            border-radius: 999px;
            padding: 10px 14px;
            font-weight: 600;
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
            border-radius: 999px;
            background: #dc2626;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.25);
        }

        .queue-tab-count.has-count {
            display: inline-flex;
        }

        .queue-filter-btn.is-active {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-color: #f59e0b;
            color: #92400e;
        }

        .incoming-card.is-hidden {
            display: none;
        }

        .incoming-card--scheduled {
            border: 1px solid #bae6fd;
            background: linear-gradient(135deg, #f8fdff, #f0f9ff);
            box-shadow: 0 8px 22px rgba(14, 116, 144, 0.08);
        }

        .incoming-card--scheduled .incoming-route strong {
            color: #0c4a6e;
        }

        .status-badge.scheduled {
            background: #e0f2fe;
            color: #075985;
            border: 1px solid #7dd3fc;
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
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
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

        @media (max-width: 860px) {

            .review-input-grid,
            .review-form-horizontal {
                grid-template-columns: 1fr;
            }

            .quotation-review-grid {
                grid-template-columns: 1fr;
            }

            #actionModal .modal-card {
                overflow-y: auto;
            }
        }
    </style>
@endpush

@section('content')

    <div class="dashboard-container">

        <div class="incoming-section">

            <div class="section-header">
                <div>
                    <h3>Dispatcher Booking Queue</h3>
                    <p>Immediate requests, scheduled work, and returned tasks are separated so reassignment stays fast and
                        clear.</p>
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
                <button type="button" class="queue-filter-btn is-active" data-filter="book-now">
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
                <button type="button" class="queue-filter-btn" data-filter="delayed">
                    <span>Delayed</span>
                    <span class="queue-tab-count {{ ($queueCounts['delayed'] ?? 0) > 0 ? 'has-count' : '' }}"
                        data-count-for="delayed">
                        {{ $queueCounts['delayed'] ?? 0 }}
                    </span>
                </button>
                <button type="button" class="queue-filter-btn" data-filter="all">
                    <span>All</span>
                    <span class="queue-tab-count {{ ($queueCounts['all'] ?? 0) > 0 ? 'has-count' : '' }}"
                        data-count-for="all">
                        {{ $queueCounts['all'] ?? 0 }}
                    </span>
                </button>
            </div>

            <div class="incoming-list" id="incomingList" data-default-filter="book-now"
                data-assign-url-template="{{ url('/admin-dashboard/booking/__BOOKING__/assign') }}">

                @forelse($incomingRequests as $booking)
                    @php
                        $queueBucket =
                            $booking->queue_bucket ??
                            ($booking->needs_reassignment
                                ? 'returned'
                                : ($booking->is_dispatch_delayed
                                    ? 'delayed'
                                    : ($booking->status === 'reviewed'
                                        ? 'negotiation'
                                        : ($booking->is_scheduled && !$booking->is_due_for_dispatch
                                            ? 'scheduled'
                                            : 'book-now'))));
                        $timingTone = $booking->needs_reassignment
                            ? 'returned'
                            : ($booking->is_dispatch_delayed
                                ? 'delayed'
                                : ($booking->status === 'reviewed'
                                    ? 'negotiation'
                                    : ($booking->is_due_for_dispatch
                                        ? 'due-now'
                                        : ($booking->is_scheduled
                                            ? 'scheduled'
                                            : 'book-now'))));
                        $timingLabel = $booking->needs_reassignment
                            ? 'Returned'
                            : ($booking->is_dispatch_delayed
                                ? 'Delayed'
                                : ($booking->status === 'reviewed'
                                    ? 'Negotiation'
                                    : ($booking->is_due_for_dispatch
                                        ? 'Due Now'
                                        : $booking->service_mode_label)));
                        $statusBadgeClass = $booking->needs_reassignment
                            ? 'returned'
                            : ($booking->is_dispatch_delayed
                                ? 'returned'
                                : ($booking->status === 'reviewed'
                                    ? 'pending'
                                    : ($booking->is_scheduled
                                        ? 'scheduled'
                                        : 'pending')));
                        $statusBadgeLabel = $booking->needs_reassignment
                            ? 'Needs Reassignment'
                            : ($booking->is_dispatch_delayed
                                ? 'Delayed Queue'
                                : ($booking->status === 'reviewed'
                                    ? 'Negotiation Request'
                                    : ($booking->is_scheduled
                                        ? 'Scheduled Booking'
                                        : 'Requested')));
                        $disableReviewAction =
                            $booking->is_scheduled &&
                            !$booking->is_due_for_dispatch &&
                            !$booking->is_dispatch_delayed &&
                            !$booking->needs_reassignment &&
                            $booking->status !== 'reviewed';
                    @endphp
                    <div class="incoming-card {{ $booking->is_scheduled && !$booking->is_dispatch_delayed ? 'incoming-card--scheduled' : '' }}"
                        data-id="{{ $booking->job_code }}" data-status="{{ $booking->status }}"
                        data-queue="{{ $queueBucket }}" data-service-mode="{{ $booking->service_mode }}"
                        data-scheduled-for="{{ optional($booking->scheduled_for)->toIso8601String() }}"
                        data-current-price="{{ $booking->final_total }}"
                        data-current-additional="{{ $booking->additional_fee }}"
                        data-base-rate="{{ $booking->base_rate }}" data-distance-fee="{{ $booking->distance_fee_amount }}"
                        data-distance-km="{{ $booking->distance_km }}" data-per-km-rate="{{ $booking->per_km_rate }}"
                        data-customer-type="{{ ucfirst($booking->customer_type ?? (optional($booking->customer)->customer_type ?? 'regular')) }}"
                        data-truck-type="{{ e($booking->truckType->name ?? 'Unknown') }}"
                        data-dispatch-zone="{{ e($booking->dispatch_zone_label ?? 'General Dispatch Zone') }}"
                        data-recommended-unit="{{ $booking->recommended_unit_id }}"
                        data-recommended-summary="{{ e($booking->recommended_unit_summary ?? '') }}"
                        data-discount="{{ $booking->discount_amount }}"
                        data-discount-rate="{{ $booking->discount_percentage }}"
                        data-assigned-unit="{{ $booking->assigned_unit_id }}"
                        data-customer-note="{{ e($booking->customer_response_note ?? '') }}"
                        data-counter-offer="{{ $booking->counter_offer_amount }}"
                        data-dispatcher-note="{{ e($booking->remarks ?? ($booking->dispatcher_note ?? '')) }}"
                        data-return-reason="{{ e($booking->return_reason ?? '') }}"
                        data-returned-by="{{ e($booking->returnedByTeamLeader->full_name ?? ($booking->returnedByTeamLeader->name ?? '')) }}"
                        data-returned-at="{{ optional($booking->returned_at)->toIso8601String() }}"
                        data-created-at="{{ $booking->created_at->toISOString() }}">

                        <div class="incoming-left">

                            <div class="incoming-route">
                                <strong>{{ $booking->pickup_address ?? 'Unknown Pickup' }}</strong>
                                <span class="arrow">→</span>
                                <span>{{ $booking->dropoff_address ?? 'Unknown Dropoff' }}</span>
                            </div>

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
                                <span class="queue-chip {{ $timingTone }}">
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

                        <div class="incoming-actions">
                            <button type="button" class="btn-accept" data-id="{{ $booking->job_code }}"
                                data-action="accept" {{ $disableReviewAction ? 'disabled' : '' }}>
                                {{ $disableReviewAction
                                    ? 'Await Scheduled Time'
                                    : ($booking->needs_reassignment
                                        ? 'Reassign Task'
                                        : ($booking->status === 'reviewed'
                                            ? 'Update Quote'
                                            : 'Review & Quote')) }}
                            </button>
                            <button type="button" class="btn-reject" data-id="{{ $booking->job_code }}"
                                data-action="reject">{{ $booking->needs_reassignment ? 'Cancel Booking' : 'Reject' }}</button>
                        </div>

                    </div>

                @empty
                    <div class="empty-state" id="emptyState">
                        <p>No bookings in this queue right now.</p>
                    </div>
                @endforelse

            </div>

        </div>

        <div id="actionModal" class="hidden" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="modal-card">

                <div class="modal-icon" id="modalIcon"></div>

                <h3 id="modalTitle">Confirm Action</h3>
                <p id="modalText">Are you sure?</p>

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

                            <div id="discountDisplayWrapper" class="modal-input modal-field">
                                <label for="discountPercentInput" class="field-label">Discount (%)</label>
                                <input type="number" id="discountPercentInput" class="review-field-input"
                                    min="0" max="100" step="0.01" placeholder="0.00" required>
                                <small class="field-help" id="discountLabel">Regular customers keep the discount field
                                    locked. It opens only for PWD or Senior bookings.</small>
                                <small class="inline-field-error" id="discountPercentInputError"></small>
                            </div>

                            <div class="modal-input modal-field">
                                <label class="field-label">Discount Amount</label>
                                <div class="computed-total compact" id="discountBadge">- ₱0.00</div>
                            </div>

                            <div id="priceWrapper" class="modal-input modal-field">
                                <label for="priceInput" class="field-label">Additional Fee</label>
                                <input type="text" id="priceInput" class="review-field-input" inputmode="decimal"
                                    placeholder="0.00" autocomplete="off" />
                                <small class="field-help" id="priceHelper">Leave blank if no dispatcher adjustment is
                                    needed.</small>
                                <small class="inline-field-error" id="priceInputError"></small>
                            </div>

                            <div id="unitWrapper" class="modal-input modal-field">
                                <label for="unitSelect" class="field-label">Available Unit</label>
                                <div class="unit-select-shell">
                                    <select id="unitSelect" class="review-field-select" required>
                                        <option value="">Select available unit</option>
                                        @forelse ($availableUnits as $unit)
                                            <option value="{{ $unit['id'] }}" data-selectable="1"
                                                data-team-leader="{{ e($unit['team_leader_name']) }}"
                                                data-driver="{{ e($unit['driver_name']) }}"
                                                data-zones="{{ e(implode(', ', $unit['coverage_zones'] ?? [])) }}"
                                                data-summary="{{ e($unit['status_summary']) }}">
                                                {{ $unit['label'] }} · {{ $unit['team_leader_name'] }}
                                            </option>
                                        @empty
                                            <option value="" disabled>No online ready units available</option>
                                        @endforelse
                                    </select>
                                </div>
                                <small class="field-help" id="unitHelper">Only units with online available team leaders
                                    are shown here.</small>
                                <small class="inline-field-error" id="unitSelectError"></small>
                            </div>

                            <div id="dispatcherNoteWrapper" class="modal-input modal-field full-span">
                                <label for="dispatcherNoteInput" class="field-label">Notes</label>
                                <textarea id="dispatcherNoteInput" rows="2"
                                    placeholder="Add the reason for any increase or pricing adjustment..."></textarea>
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
                            <div class="review-summary-row"><span>Truck Type</span><strong
                                    id="summaryTruckType">—</strong></div>
                            <div class="review-summary-row"><span>Distance</span><strong id="summaryDistanceKm">0.00
                                    km</strong></div>
                            <div class="review-summary-row"><span>Base Rate</span><strong
                                    id="summaryBaseRate">₱0.00</strong></div>
                            <div class="review-summary-row"><span>Per KM Rate</span><strong
                                    id="summaryPerKmRate">₱0.00</strong></div>
                            <div class="review-summary-row"><span>Customer Type</span><strong
                                    id="summaryCustomerType">Regular</strong></div>
                            <div class="review-summary-row"><span>Base Fee</span><strong
                                    id="summaryBaseFee">₱0.00</strong></div>
                            <div class="review-summary-row"><span>Distance Fee</span><strong
                                    id="summaryDistanceFee">₱0.00</strong></div>
                            <div class="review-summary-row"><span>Discount</span><strong id="summaryDiscount">-
                                    ₱0.00</strong></div>
                            <div class="review-summary-row"><span>Additional Fee</span><strong
                                    id="summaryAdditionalFee">₱0.00</strong></div>
                            <div class="review-summary-row total"><span>Final Total</span><strong
                                    id="summaryFinalTotal">₱0.00</strong></div>
                        </div>
                    </div>
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

    </div>

@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/dispatch.js') }}?v={{ filemtime(public_path('dispatcher/js/dispatch.js')) }}">
    </script>
@endpush
