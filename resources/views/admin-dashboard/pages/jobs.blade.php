@extends('admin-dashboard.layouts.app')

@section('title', 'Active Jobs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/jobs.css') }}">
@endpush

@section('content')
    <div class="jobs-page" data-csrf="{{ csrf_token() }}">

        <div class="jobs-tabs" id="jobsTabs">
            <button type="button" class="rb-tab is-active" data-tab="all">All <span class="rb-tab-count">{{ $stats['total'] }}</span></button>
            <button type="button" class="rb-tab" data-tab="assigned">Assigned <span class="rb-tab-count">{{ $stats['assigned'] }}</span></button>
            <button type="button" class="rb-tab" data-tab="en-route">En Route <span class="rb-tab-count">{{ $stats['en_route'] }}</span></button>
            <button type="button" class="rb-tab" data-tab="in-service">In Service <span class="rb-tab-count">{{ $stats['in_service'] }}</span></button>
            <button type="button" class="rb-tab" data-tab="awaiting-verification">Awaiting Verification <span class="rb-tab-count">{{ $stats['awaiting_verification'] }}</span></button>
        </div>

        <div class="jobs-toolbar">
            <div class="jobs-filter-group">
                <label for="jobsSearch">Search</label>
                <input type="text" id="jobsSearch" placeholder="Search booking, customer, unit or team leader">
            </div>
        </div>

        <div class="jobs-table-wrap">
            <table class="jobs-table">
                <thead>
                    <tr>
                        <th>Booking / Customer</th>
                        <th>Status</th>
                        <th>Unit / Team</th>
                        <th>Route</th>
                        <th>Payment</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($jobs as $job)
                    @php
                        $isAwaiting = in_array($job->status, ['waiting_verification', 'payment_pending', 'payment_submitted']);

                        $bucket = match (true) {
                            in_array($job->status, ['assigned', 'accepted'], true) => 'assigned',
                            in_array($job->status, ['on_the_way', 'arrived_pickup'], true) => 'en-route',
                            in_array($job->status, ['in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'], true) => 'in-service',
                            $job->status === 'waiting_verification' => 'awaiting-verification',
                            default => 'in-service',
                        };

                        $statusLabelFor = fn($status) => match ($status) {
                            'assigned' => 'Assigned',
                            'accepted' => 'Accepted',
                            'on_the_way' => 'On the Way',
                            'arrived_pickup' => 'Arrived at Pickup',
                            'in_progress' => 'In Progress',
                            'loading_vehicle' => 'Loading Vehicle',
                            'on_job' => 'In Transit',
                            'arrived_dropoff' => 'Arrived at Drop-off',
                            'waiting_verification' => 'Awaiting Verification',
                            default => ucwords(str_replace('_', ' ', $status)),
                        };
                        $statusLabel = $statusLabelFor($job->status);

                        $siblingBookings = $job->sibling_bookings ?? collect([$job]);
                        $siblingCount = $siblingBookings->count();
                        $groupOperationalDoneCount = $siblingBookings->filter(fn($sib) => in_array($sib->status, ['arrived_dropoff', 'waiting_verification', 'completed'], true))->count();
                        $groupVehicles = $siblingCount > 1
                            ? $siblingBookings->map(fn($sib) => [
                                'booking_code' => $sib->booking_code,
                                'unit' => optional($sib->unit)->name ?? 'Unassigned',
                                'team_leader' => optional($sib->assignedTeamLeader)->full_name
                                    ?? optional($sib->assignedTeamLeader)->name
                                    ?? 'Unassigned',
                                'status' => $statusLabelFor($sib->status),
                            ])->values()->all()
                            : [];

                        $customer   = optional($job->customer)->full_name ?? optional($job->customer)->name ?? 'Customer unavailable';
                        $custPhone  = optional($job->customer)->phone ?? '';
                        $custEmail  = optional($job->customer)->email ?? '';
                        $unitName   = optional($job->unit)->name ?? 'Unassigned';
                        $teamLeaderName = optional($job->assignedTeamLeader)->full_name
                            ?? optional($job->assignedTeamLeader)->name
                            ?? 'Unassigned';
                        $driverName = $job->driver_name
                            ?: optional($job->unit)->driver?->full_name
                            ?: optional($job->unit)->driver?->name
                            ?: optional($job->unit)->driver_name
                            ?: 'No member recorded';
                        $pickup     = $job->pickup_address ?? 'Pickup pending';
                        $dropoff    = $job->dropoff_address ?? 'Drop-off pending';

                        $serviceCompletedAt = $job->completion_requested_at ?? ($isAwaiting ? $job->updated_at : null);
                        $paymentSubmittedAt = $job->payment_submitted_at ?? ($job->payment_method ? $job->updated_at : null);

                        $statusSecondary = ($bucket === 'awaiting-verification' && $serviceCompletedAt)
                            ? 'Completed ' . $serviceCompletedAt->diffForHumans()
                            : null;

                        $paymentReady = $isAwaiting && filled($job->payment_method);
                        $paymentMethodLabel = match ($job->payment_method) {
                            'gcash' => 'GCash',
                            'bank_transfer' => 'Bank Transfer',
                            'cash' => 'Cash',
                            default => null,
                        };
                        $displayTotal = $job->group_total ?? $job->final_total;
                        $amountSubmitted = $job->payment_method === 'cash'
                            ? $job->cash_received
                            : $displayTotal;
                        $finalTotal = $displayTotal ? number_format((float) $displayTotal, 2) : '';
                        $proofPath = $siblingBookings->pluck('payment_proof_path')->first(fn($p) => filled($p));
                        $signaturePath = $siblingBookings->pluck('customer_signature_path')->first(fn($p) => filled($p));
                        $proofUrl = protected_file_url($proofPath) ?? '';
                        $signatureUrl = protected_file_url($signaturePath) ?? '';
                        $vehicleImages = $siblingBookings
                            ->flatMap(fn($sib) => collect($sib->vehicle_image_paths)->map(fn($path) => [
                                'url' => protected_file_url($path),
                                'booking_code' => $sib->booking_code,
                            ]))
                            ->filter(fn($img) => filled($img['url']))
                            ->values()
                            ->all();
                    @endphp
                    <tr class="jobs-row js-open-job-row" tabindex="0"
                        aria-label="Open {{ $job->booking_code }}, {{ $customer }}"
                        data-bucket="{{ $bucket }}"
                        data-status="{{ $job->status }}"
                        data-booking-code="{{ $job->booking_code }}"
                        data-confirm-url="{{ route('admin.jobs.confirm-payment', $job) }}"
                        data-reassign-options-url="{{ route('admin.jobs.reassign-options', $job) }}"
                        data-reassign-url="{{ route('admin.jobs.reassign', $job) }}"
                        data-customer="{{ $customer }}"
                        data-phone="{{ $custPhone }}"
                        data-email="{{ $custEmail }}"
                        data-service="{{ optional($job->truckType)->name ?? 'General Tow' }}"
                        data-status-label="{{ $statusLabel }}"
                        data-bucket-class="{{ $bucket }}"
                        data-unit="{{ $unitName }}"
                        data-teamleader="{{ $teamLeaderName }}"
                        data-driver="{{ $driverName }}"
                        data-pickup="{{ $pickup }}"
                        data-dropoff="{{ $dropoff }}"
                        data-distance-km="{{ $job->distance_km ?? '' }}"
                        data-total="{{ $finalTotal }}"
                        data-service-completed-at="{{ $serviceCompletedAt?->format('M d, Y g:i A') }}"
                        data-payment-ready="{{ $paymentReady ? '1' : '0' }}"
                        data-payment-method="{{ $paymentMethodLabel ?? '' }}"
                        data-amount-submitted="{{ $amountSubmitted ? number_format((float) $amountSubmitted, 2) : '' }}"
                        data-payment-submitted-at="{{ $paymentSubmittedAt?->format('M d, Y g:i A') }}"
                        data-proof-url="{{ $proofUrl }}"
                        data-signature-url="{{ $signatureUrl }}"
                        data-cash-received="{{ $job->cash_received ? number_format((float) $job->cash_received, 2) : '' }}"
                        data-group-vehicles="{{ json_encode($groupVehicles) }}"
                        data-vehicle-images="{{ json_encode($vehicleImages) }}">
                        <td>
                            <div class="jobs-cell-primary jobs-booking-code">{{ $job->booking_code }}@if ($siblingCount > 1)<span class="jobs-cell-secondary"> (+{{ $siblingCount - 1 }})</span>@endif</div>
                            <div class="jobs-cell-secondary">{{ $customer }}</div>
                            @if ($siblingCount > 1)
                                <div class="jobs-cell-secondary">{{ $groupOperationalDoneCount }} of {{ $siblingCount }} vehicles completed</div>
                            @endif
                        </td>
                        <td>
                            <span class="jobs-status-text {{ $bucket === 'awaiting-verification' ? 'jobs-status-text--emphasis' : '' }}">{{ $statusLabel }}</span>
                            @if ($statusSecondary)
                                <div class="jobs-cell-secondary">{{ $statusSecondary }}</div>
                            @endif
                        </td>
                        <td>
                            <div class="jobs-cell-primary">{{ $unitName }}</div>
                            <div class="jobs-cell-secondary">{{ $teamLeaderName }}</div>
                        </td>
                        <td class="jobs-route-cell" title="{{ $pickup }} → {{ $dropoff }}">
                            <div class="jobs-route-line">{{ $pickup }}</div>
                            <div class="jobs-route-line jobs-route-line--drop">→ {{ $dropoff }}</div>
                        </td>
                        <td>
                            @if ($isAwaiting)
                                @if ($paymentReady)
                                    <div class="jobs-cell-primary">{{ $paymentMethodLabel }}</div>
                                    <div class="jobs-cell-secondary">₱{{ $amountSubmitted ? number_format((float) $amountSubmitted, 2) : '0.00' }}</div>
                                @else
                                    <span class="jobs-cell-secondary">Payment not yet submitted</span>
                                @endif
                            @else
                                <span class="jobs-cell-secondary">—</span>
                            @endif
                        </td>
                        <td class="jobs-cell-secondary">{{ $job->updated_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="jobs-empty">
                                <i data-lucide="truck"></i>
                                <p>No active jobs right now</p>
                                <span>Jobs will appear here once a team leader starts a towing task.</span>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="jobs-pagination">
            {{ $jobs->onEachSide(1)->links() }}
        </div>

        <div class="jobs-drawer-backdrop" id="jobsDrawerBackdrop"></div>
        <div class="jobs-drawer" id="jobsDrawer">
            <div class="jobs-drawer-head">
                <div>
                    <p class="jobs-drawer-eyebrow" id="drawerBookingCode">—</p>
                    <span class="jobs-drawer-status" id="drawerStatus"></span>
                </div>
                <button type="button" class="jobs-drawer-close" id="jobsDrawerClose" aria-label="Close">×</button>
            </div>

            <div class="jobs-drawer-body">
                <div class="jobs-drawer-section">
                    <div class="jobs-drawer-section-title">Customer</div>
                    <div class="jobs-drawer-grid">
                        <div class="jobs-drawer-item full-width">
                            <span class="jobs-drawer-label">Name</span>
                            <span class="jobs-drawer-value" id="drawer-customer">—</span>
                        </div>
                        <div class="jobs-drawer-item">
                            <span class="jobs-drawer-label">Phone</span>
                            <span class="jobs-drawer-value" id="drawer-phone">—</span>
                        </div>
                        <div class="jobs-drawer-item">
                            <span class="jobs-drawer-label">Email</span>
                            <span class="jobs-drawer-value" id="drawer-email">—</span>
                        </div>
                    </div>
                </div>

                <div class="jobs-drawer-section">
                    <div class="jobs-drawer-section-title">Route</div>
                    <div class="rb-route">
                        <div class="rb-route-row">
                            <span class="rb-route-dot rb-pick"></span>
                            <span class="rb-route-addr" id="drawer-pickup">—</span>
                        </div>
                        <div class="rb-route-row">
                            <span class="rb-route-dot rb-drop"></span>
                            <span class="rb-route-addr" id="drawer-dropoff">—</span>
                        </div>
                        <div class="rb-route-meta" id="drawer-distance-wrap" style="display:none;">
                            <span>Distance</span>
                            <span id="drawer-distance">—</span>
                        </div>
                    </div>
                </div>

                <div class="jobs-drawer-section">
                    <div class="jobs-drawer-section-title">Truck Type</div>
                    <div class="jobs-drawer-grid">
                        <div class="jobs-drawer-item">
                            <span class="jobs-drawer-value" id="drawer-service">—</span>
                        </div>
                        <div class="jobs-drawer-item" id="drawer-completed-wrap" style="display:none;">
                            <span class="jobs-drawer-label">Service Completed</span>
                            <span class="jobs-drawer-value" id="drawer-completed-at">—</span>
                        </div>
                    </div>
                </div>

                <div class="jobs-drawer-section">
                    <div class="jobs-drawer-section-title" id="drawer-unit-title">Assigned Unit</div>
                    <div class="jobs-drawer-grid">
                        <div class="jobs-drawer-item">
                            <span class="jobs-drawer-label">Unit</span>
                            <span class="jobs-drawer-value" id="drawer-unit">—</span>
                        </div>
                        <div class="jobs-drawer-item">
                            <span class="jobs-drawer-label">Team Leader</span>
                            <span class="jobs-drawer-value" id="drawer-teamleader">—</span>
                        </div>
                        <div class="jobs-drawer-item full-width">
                            <span class="jobs-drawer-label">Member Driver</span>
                            <span class="jobs-drawer-value" id="drawer-driver">—</span>
                        </div>
                    </div>
                </div>

                <div class="jobs-drawer-section" id="drawer-vehicles-section" style="display:none;">
                    <div class="jobs-drawer-section-title">Vehicles in This Request</div>
                    <div class="jobs-drawer-grid" id="drawer-vehicles-grid"></div>
                </div>

                <div class="jobs-drawer-section" id="drawer-vehicle-photos-section" style="display:none;">
                    <div class="jobs-drawer-section-title">Vehicle Photos</div>
                    <div class="jobs-photo-grid" id="drawer-vehicle-photos-grid"></div>
                </div>

                <div class="jobs-drawer-section" id="drawer-payment-section" style="display:none;">
                    <div class="jobs-drawer-section-title">Payment Summary</div>
                    <div class="jobs-drawer-grid">
                        <div class="jobs-drawer-item" id="drawer-amount-due-wrap">
                            <span class="jobs-drawer-label">Amount Due</span>
                            <span class="jobs-drawer-value" id="drawer-amount-due">—</span>
                        </div>
                        <div class="jobs-drawer-item" id="drawer-amount-submitted-wrap">
                            <span class="jobs-drawer-label" id="drawer-amount-submitted-label">Amount Submitted</span>
                            <span class="jobs-drawer-value" id="drawer-amount-submitted">—</span>
                        </div>
                        <div class="jobs-drawer-item" id="drawer-difference-wrap">
                            <span class="jobs-drawer-label" id="drawer-difference-label">Difference</span>
                            <span class="jobs-drawer-value" id="drawer-difference">—</span>
                        </div>
                        <div class="jobs-drawer-item" id="drawer-amount-paid-wrap">
                            <span class="jobs-drawer-label">Amount Paid</span>
                            <span class="jobs-drawer-value" id="drawer-amount-paid">—</span>
                        </div>
                        <div class="jobs-drawer-item">
                            <span class="jobs-drawer-label">Payment Method</span>
                            <span class="jobs-drawer-value" id="drawer-payment-method">—</span>
                        </div>
                        <div class="jobs-drawer-item">
                            <span class="jobs-drawer-label">Submitted At</span>
                            <span class="jobs-drawer-value" id="drawer-submitted-at">—</span>
                        </div>
                    </div>
                </div>

                <div class="jobs-drawer-section" id="drawer-proof-section" style="display:none;">
                    <div class="jobs-drawer-section-title">Payment Proof</div>
                    <a id="drawer-proof-link" href="#" target="_blank" rel="noopener noreferrer" class="jobs-proof-box">
                        <img id="drawer-proof-img" src="" alt="Payment proof">
                        <span>View full size</span>
                    </a>
                    <p class="jobs-cash-note" id="drawer-cash-note" style="display:none;">Cash received on-site — no proof image required.</p>
                </div>

                <div class="jobs-drawer-section" id="drawer-signature-section" style="display:none;">
                    <div class="jobs-drawer-section-title">Customer Acknowledgment</div>
                    <div class="jobs-signature-box">
                        <img id="drawer-signature-img" src="" alt="Customer signature">
                        <span class="jobs-signature-caption" id="drawer-signature-caption">—</span>
                    </div>
                </div>
            </div>

            <div class="jobs-drawer-foot">
                <button type="button" id="drawerReassignBtn" class="btn rtn-btn-secondary" style="display:none;">
                    <i data-lucide="repeat"></i>
                    <span>Reassign Task</span>
                </button>
                <button type="button" id="drawerConfirmPaymentBtn" class="btn btn-confirm-payment" style="display:none;">
                    <i data-lucide="check-circle"></i>
                    <span>Confirm Payment</span>
                </button>
            </div>
        </div>

        {{-- Reassign Task modal: only ever offered while status is exactly
             'assigned' — before the Team Leader has accepted, nothing
             customer-facing has happened yet and there's nothing to unwind. --}}
        <div id="jrReassignModal" class="rtn-modal-overlay" style="display:none;" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="rtn-modal-card">
                <div class="rtn-modal-header">
                    <div>
                        <span class="rtn-modal-title">Reassign Task</span>
                        <span class="rtn-modal-subtitle" id="jrModalBookingCode">—</span>
                    </div>
                    <button type="button" class="rtn-modal-close" id="jrModalCloseBtn">&times;</button>
                </div>

                <div class="rtn-modal-summary">
                    <div class="rtn-modal-summary-row">
                        <span class="rtn-modal-summary-label">Current Unit</span>
                        <span class="rtn-modal-summary-value" id="jrCurrentUnit">—</span>
                    </div>
                    <div class="rtn-modal-summary-row">
                        <span class="rtn-modal-summary-label">Current Team Leader</span>
                        <span class="rtn-modal-summary-value" id="jrCurrentTl">—</span>
                    </div>
                </div>

                <div class="rtn-modal-body rtn-modal-body--single">
                    <div class="rtn-modal-list-col rtn-modal-list-col--full">
                        <div class="rtn-modal-section-title">New Unit / Team Leader</div>
                        <select id="jrUnitSelect" class="rtn-search-input">
                            <option value="">Loading options…</option>
                        </select>
                        <div class="rtn-modal-empty" id="jrEmptyState" style="display:none;">No other ready unit is currently available for this vehicle type.</div>

                        <div class="rtn-modal-section-title" style="margin-top:16px;">Reason <span style="color:#b91c1c;">*</span></div>
                        <select id="jrReasonSelect" class="rtn-search-input">
                            <option value="">Select a reason…</option>
                            @foreach (\App\Http\Controllers\Admin\JobsController::REASSIGN_REASONS as $reason)
                                <option value="{{ $reason }}">{{ $reason }}</option>
                            @endforeach
                        </select>

                        <div class="rtn-modal-section-title" style="margin-top:16px;">
                            Notes <span id="jrNotesRequiredHint" style="display:none; color:#b91c1c;">(required for "Other")</span>
                        </div>
                        <textarea id="jrNotesInput" class="rtn-search-input" rows="3" placeholder="Optional notes…"></textarea>

                        <p class="jobs-cell-secondary" style="margin-top:14px;">
                            The current Team Leader will immediately lose access to this task. The booking itself stays active and the customer will not be notified again.
                        </p>
                    </div>
                </div>

                <div class="rtn-modal-footer">
                    <span class="rtn-modal-footer-note" id="jrModalError"></span>
                    <div class="rtn-modal-footer-actions">
                        <button type="button" class="rtn-btn-secondary" id="jrModalCancelBtn">Cancel</button>
                        <button type="button" class="rtn-btn-primary" id="jrModalConfirmBtn" disabled>Confirm Reassignment</button>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/jobs.js') }}"></script>
@endpush
