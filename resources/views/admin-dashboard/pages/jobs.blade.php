@extends('admin-dashboard.layouts.app')

@section('title', 'Active Jobs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/jobs.css') }}">
@endpush

@section('content')
    <div class="jobs-page" data-csrf="{{ csrf_token() }}">

        <div class="jobs-header">
            <h1 class="jobs-title">Active Jobs</h1>
            <p class="jobs-subtitle">Monitor active towing jobs, assignments, and job progress.</p>
        </div>

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
                        <th>Updated</th>
                        <th class="jobs-col-chevron" aria-label="Expand"></th>
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
                        $unitName   = $job->display_unit_name ?? 'Unassigned';
                        $teamLeaderName = optional($job->assignedTeamLeader)->full_name
                            ?? optional($job->assignedTeamLeader)->name
                            ?? 'Unassigned';
                        $driverName = $job->driver_name
                            ?: optional($job->unit)->driver?->full_name
                            ?: optional($job->unit)->driver?->name
                            ?: optional($job->unit)->driver_name
                            ?: 'No driver recorded';
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
                    <tr class="jobs-row js-open-job-row" tabindex="0" aria-expanded="false"
                        aria-label="Open {{ $job->booking_code }}, {{ $customer }}"
                        data-bucket="{{ $bucket }}"
                        data-status="{{ $job->status }}"
                        data-booking-code="{{ $job->booking_code }}"
                        data-confirm-url="{{ route('admin.jobs.confirm-payment', $job) }}"
                        data-reassign-options-url="{{ route('admin.jobs.reassign-options', $job) }}"
                        data-reassign-url="{{ route('admin.jobs.reassign', $job) }}"
                        data-cancel-url="{{ route('admin.jobs.cancel', $job) }}"
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
                        <td class="jobs-cell-secondary">{{ $job->updated_at?->diffForHumans() }}</td>
                        <td class="jobs-chevron-cell" aria-hidden="true">
                            <svg class="jobs-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"></path></svg>
                        </td>
                    </tr>
                    <tr class="jobs-detail-row" data-detail-for="{{ $job->booking_code }}" hidden>
                        <td colspan="6"></td>
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

        {{-- Inline job detail. The single template below is cloned by jobs.js into
             the open row's sibling .jobs-detail-row (one open at a time), so its
             ids are unique in the live DOM. Facts live in ONE place each: the
             booking code, customer name, status, unit/team and amount are NOT
             repeated here (only the route is, short in the row / full here). --}}
        <template id="jobsDetailTemplate">
            <div class="jobs-detail">
                <div class="jobs-detail-cols">
                    <div class="jobs-detail-col">
                        <section class="jobs-detail-sec">
                            <h3 class="jobs-detail-title">Customer</h3>
                            <dl class="jobs-detail-list">
                                <div class="jobs-detail-item">
                                    <dt>Phone</dt>
                                    <dd id="job-detail-phone">—</dd>
                                </div>
                                <div class="jobs-detail-item">
                                    <dt>Email</dt>
                                    <dd id="job-detail-email">—</dd>
                                </div>
                            </dl>
                        </section>

                        <section class="jobs-detail-sec">
                            <h3 class="jobs-detail-title">Route</h3>
                            <dl class="jobs-detail-list">
                                <div class="jobs-detail-item">
                                    <dt>Pickup</dt>
                                    <dd id="job-detail-pickup">—</dd>
                                </div>
                                <div class="jobs-detail-item">
                                    <dt>Drop-off</dt>
                                    <dd id="job-detail-dropoff">—</dd>
                                </div>
                                <div class="jobs-detail-item" id="job-detail-distance-wrap" style="display:none;">
                                    <dt>Distance</dt>
                                    <dd id="job-detail-distance">—</dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <div class="jobs-detail-col">
                        <section class="jobs-detail-sec">
                            <h3 class="jobs-detail-title" id="job-detail-unit-title">Team / Service</h3>
                            <dl class="jobs-detail-list">
                                <div class="jobs-detail-item">
                                    <dt>Unit</dt>
                                    <dd id="job-detail-unit">—</dd>
                                </div>
                                <div class="jobs-detail-item">
                                    <dt>Team Leader</dt>
                                    <dd id="job-detail-teamleader">—</dd>
                                </div>
                                <div class="jobs-detail-item">
                                    <dt>Driver</dt>
                                    <dd id="job-detail-driver">—</dd>
                                </div>
                                <div class="jobs-detail-item">
                                    <dt>Truck type</dt>
                                    <dd id="job-detail-service">—</dd>
                                </div>
                                <div class="jobs-detail-item" id="job-detail-completed-wrap" style="display:none;">
                                    <dt>Service completed</dt>
                                    <dd id="job-detail-completed-at">—</dd>
                                </div>
                                {{-- Shown once, only while there is no payment section (see jobs.js). --}}
                                <div class="jobs-detail-item" id="job-detail-agreed-wrap" style="display:none;">
                                    <dt>Agreed amount</dt>
                                    <dd id="job-detail-agreed">—</dd>
                                </div>
                            </dl>
                        </section>
                    </div>
                </div>

                <section class="jobs-detail-sec" id="job-detail-vehicles-section" style="display:none;">
                    <h3 class="jobs-detail-title">Vehicles in This Request</h3>
                    <table class="jobs-detail-table">
                        <thead>
                            <tr>
                                <th>Booking</th>
                                <th>Unit</th>
                                <th>Team Leader</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="job-detail-vehicles-grid"></tbody>
                    </table>
                </section>

                <section class="jobs-detail-sec" id="job-detail-vehicle-photos-section" style="display:none;">
                    <h3 class="jobs-detail-title">Vehicle Photos</h3>
                    <div class="jobs-photo-grid" id="job-detail-vehicle-photos-grid"></div>
                </section>

                <section class="jobs-detail-sec" id="job-detail-payment-section" style="display:none;">
                    <h3 class="jobs-detail-title">Payment</h3>
                    <dl class="jobs-detail-list jobs-detail-list--row">
                        <div class="jobs-detail-item" id="job-detail-amount-due-wrap">
                            <dt>Agreed amount</dt>
                            <dd id="job-detail-amount-due">—</dd>
                        </div>
                        <div class="jobs-detail-item" id="job-detail-amount-submitted-wrap">
                            <dt id="job-detail-amount-submitted-label">Amount Submitted</dt>
                            <dd id="job-detail-amount-submitted">—</dd>
                        </div>
                        <div class="jobs-detail-item" id="job-detail-difference-wrap">
                            <dt id="job-detail-difference-label">Difference</dt>
                            <dd id="job-detail-difference">—</dd>
                        </div>
                        <div class="jobs-detail-item" id="job-detail-amount-paid-wrap">
                            <dt>Agreed amount (paid in full)</dt>
                            <dd id="job-detail-amount-paid">—</dd>
                        </div>
                        <div class="jobs-detail-item">
                            <dt>Payment method</dt>
                            <dd id="job-detail-payment-method">—</dd>
                        </div>
                        <div class="jobs-detail-item">
                            <dt>Submitted at</dt>
                            <dd id="job-detail-submitted-at">—</dd>
                        </div>
                    </dl>
                </section>

                <section class="jobs-detail-sec" id="job-detail-proof-section" style="display:none;">
                    <h3 class="jobs-detail-title">Payment Proof</h3>
                    <a id="job-detail-proof-link" href="#" target="_blank" rel="noopener noreferrer" class="jobs-proof-box">
                        <img id="job-detail-proof-img" src="" alt="Payment proof">
                        <span>View full size</span>
                    </a>
                    <p class="jobs-cash-note" id="job-detail-cash-note" style="display:none;">Cash received on-site — no proof image required.</p>
                </section>

                <section class="jobs-detail-sec" id="job-detail-signature-section" style="display:none;">
                    <h3 class="jobs-detail-title">Customer Acknowledgment</h3>
                    <div class="jobs-signature-box">
                        <img id="job-detail-signature-img" src="" alt="Customer signature">
                        <span class="jobs-signature-caption" id="job-detail-signature-caption">—</span>
                    </div>
                </section>

                <div class="jobs-detail-actions">
                    <button type="button" id="job-detail-reassign-btn" class="jobs-action-btn" style="display:none;">Reassign task</button>
                    <button type="button" id="job-detail-cancel-btn" class="jobs-action-btn" style="display:none;">Cancel booking</button>
                    <button type="button" id="job-detail-confirm-btn" class="jobs-action-btn jobs-action-btn--primary" style="display:none;"><span>Confirm payment</span></button>
                </div>
            </div>
        </template>

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

        {{-- Cancel booking modal: offered only for the statuses the server
             allows (JobsController::DISPATCHER_CANCELLABLE_STATUSES). --}}
        <div id="jcCancelModal" class="rtn-modal-overlay" style="display:none;" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="jcCancelTitle">
            <div class="rtn-modal-card">
                <div class="rtn-modal-header">
                    <div>
                        <span class="rtn-modal-title" id="jcCancelTitle">Cancel this active booking?</span>
                        <span class="rtn-modal-subtitle" id="jcCancelBookingCode">—</span>
                    </div>
                    <button type="button" class="rtn-modal-close" id="jcCancelCloseBtn" aria-label="Close">&times;</button>
                </div>

                <div class="rtn-modal-body rtn-modal-body--single">
                    <div class="rtn-modal-list-col rtn-modal-list-col--full">
                        <p class="jobs-cell-secondary">
                            This will stop the active job and remove it from Active Jobs. This action cannot be undone.
                        </p>
                        <div class="rtn-modal-section-title" style="margin-top:16px;">Cancellation reason <span style="color:#b91c1c;">*</span></div>
                        <textarea id="jcCancelReason" class="rtn-search-input" rows="3" maxlength="1000" placeholder="Why is this booking being cancelled?"></textarea>
                    </div>
                </div>

                <div class="rtn-modal-footer">
                    <span class="rtn-modal-footer-note" id="jcCancelError"></span>
                    <div class="rtn-modal-footer-actions">
                        <button type="button" class="rtn-btn-secondary" id="jcCancelDismissBtn">Cancel</button>
                        <button type="button" class="rtn-btn-primary" id="jcCancelConfirmBtn" disabled>Confirm cancellation</button>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/jobs.js') }}"></script>
@endpush
