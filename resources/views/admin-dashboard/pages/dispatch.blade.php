@extends('admin-dashboard.layouts.app')

@section('title', 'Dispatch')

@section('content')

    <div class="dashboard-container">

        <div class="incoming-section">

            <div class="section-header">
                <div>
                    <h3>Incoming & Negotiation Requests</h3>
                </div>
                <div class="view-controls">
                    <div class="view-toggle">
                        <button class="view-btn active" data-view="list" title="List View">
                            <i data-lucide="list"></i>
                        </button>
                        <button class="view-btn" data-view="grid" title="Grid View">
                            <i data-lucide="grid-3x3"></i>
                        </button>
                    </div>
                    <span class="count" id="requestCount">{{ $incomingRequests->count() }}</span>
                </div>
            </div>

            <div class="incoming-list" id="incomingList"
                data-assign-url-template="{{ url('/admin-dashboard/booking/__BOOKING__/assign') }}">

                @forelse($incomingRequests as $booking)
                    <div class="incoming-card" data-id="{{ $booking->job_code }}" data-status="{{ $booking->status }}"
                        data-current-price="{{ $booking->final_total }}"
                        data-customer-note="{{ e($booking->customer_response_note ?? '') }}"
                        data-counter-offer="{{ $booking->counter_offer_amount }}"
                        data-dispatcher-note="{{ e($booking->dispatcher_note ?? '') }}"
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
                                <span class="status-badge pending">
                                    {{ $booking->status === 'reviewed' ? 'Negotiation Request' : 'Requested' }}
                                </span>
                            </div>

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
                                data-action="accept">{{ $booking->status === 'reviewed' ? 'Update Quote' : 'Review & Quote' }}</button>
                            <button type="button" class="btn-reject" data-id="{{ $booking->job_code }}"
                                data-action="reject">Reject</button>
                        </div>

                    </div>

                @empty
                    <div class="empty-state" id="emptyState">
                        <p>No incoming requests</p>
                    </div>
                @endforelse

            </div>

        </div>

        <div id="actionModal" class="hidden" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="modal-card">

                <div class="modal-icon" id="modalIcon"></div>

                <h3 id="modalTitle">Confirm Action</h3>
                <p id="modalText">Are you sure?</p>

                <div id="priceWrapper" class="modal-input modal-field">
                    <label for="priceInput" class="field-label">Quoted price</label>
                    <div class="currency-input">
                        <span class="currency-prefix">₱</span>
                        <input type="text" id="priceInput" inputmode="decimal" placeholder="0.00" autocomplete="off" />
                    </div>
                    <small class="field-help" id="priceHelper"></small>
                </div>

                <div id="dispatcherNoteWrapper" class="modal-input modal-field">
                    <label for="dispatcherNoteInput" class="field-label">Short message to customer</label>
                    <textarea id="dispatcherNoteInput" rows="3" placeholder="Add a short note for the customer (optional)..."></textarea>
                </div>

                <div id="negotiationHint" class="modal-input modal-field" style="display:none;">
                    <label class="field-label">Latest customer request</label>
                    <small id="negotiationHintText"></small>
                </div>

                <div id="rejectReasonWrapper" class="modal-input modal-field">
                    <label for="rejectReasonInput" class="field-label">Rejection reason</label>
                    <input type="text" id="rejectReasonInput" placeholder="Enter rejection reason..." />
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" id="cancelModalBtn">
                        Cancel
                    </button>

                    <button type="button" class="btn-primary" id="confirmActionBtn">
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
