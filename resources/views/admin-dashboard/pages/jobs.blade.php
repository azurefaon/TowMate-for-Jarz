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
                        // $isAwaiting gates payment-section display/data only (unchanged
                        // business-adjacent logic) — kept exactly as before. $bucket below
                        // is a separate, purely presentational grouping for the tabs.
                        $isAwaiting = in_array($job->status, ['waiting_verification', 'payment_pending', 'payment_submitted']);

                        // UI filter-group only — does not touch the stored status or the
                        // Team Leader lifecycle. payment_pending/payment_submitted/delayed
                        // are legacy defensive statuses never actually assigned anywhere in
                        // the app today (confirmed by inspection); they fall into the
                        // 'in-service' default here purely so an unrecognized status never
                        // disappears from every tab.
                        $bucket = match (true) {
                            in_array($job->status, ['assigned', 'accepted'], true) => 'assigned',
                            in_array($job->status, ['on_the_way', 'arrived_pickup'], true) => 'en-route',
                            in_array($job->status, ['in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'], true) => 'in-service',
                            $job->status === 'waiting_verification' => 'awaiting-verification',
                            default => 'in-service',
                        };

                        $statusLabel = match ($job->status) {
                            'assigned' => 'Assigned',
                            'accepted' => 'Accepted',
                            'on_the_way' => 'On the Way',
                            'arrived_pickup' => 'Arrived at Pickup',
                            'in_progress' => 'In Progress',
                            'loading_vehicle' => 'Loading Vehicle',
                            'on_job' => 'In Transit',
                            'arrived_dropoff' => 'Arrived at Drop-off',
                            'waiting_verification' => 'Awaiting Verification',
                            default => ucwords(str_replace('_', ' ', $job->status)),
                        };

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

                        // The only status-adjacent secondary line that carries genuinely
                        // unique data (a real timestamp, not filler restating the status).
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
                        $amountSubmitted = $job->payment_method === 'cash'
                            ? $job->cash_received
                            : $job->final_total;
                        $finalTotal = $job->final_total ? number_format((float) $job->final_total, 2) : '';
                        $proofUrl = $job->payment_proof_path ? asset('storage/' . $job->payment_proof_path) : '';
                        $signatureUrl = $job->customer_signature_path ? asset('storage/' . $job->customer_signature_path) : '';
                    @endphp
                    <tr class="jobs-row js-open-job-row" tabindex="0"
                        aria-label="Open {{ $job->booking_code }}, {{ $customer }}"
                        data-bucket="{{ $bucket }}"
                        data-booking-code="{{ $job->booking_code }}"
                        data-confirm-url="{{ route('admin.jobs.confirm-payment', $job) }}"
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
                        data-cash-received="{{ $job->cash_received ? number_format((float) $job->cash_received, 2) : '' }}">
                        <td>
                            <div class="jobs-cell-primary jobs-booking-code">{{ $job->booking_code }}</div>
                            <div class="jobs-cell-secondary">{{ $customer }}</div>
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

        {{-- Right-side drawer --}}
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
                    {{-- Same .rb-route* markup/classes as the approved Dispatch Queue
                         booking drawer (see routeSectionHtml() in booking-drawer.js,
                         shared by Book Now and Scheduled) — reused here via jobs.css
                         for visual consistency, not a divergent copy. --}}
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

                <div class="jobs-drawer-section" id="drawer-payment-section" style="display:none;">
                    <div class="jobs-drawer-section-title">Payment Summary</div>
                    <div class="jobs-drawer-grid">
                        <div class="jobs-drawer-item" id="drawer-amount-due-wrap">
                            <span class="jobs-drawer-label">Amount Due</span>
                            <span class="jobs-drawer-value" id="drawer-amount-due">—</span>
                        </div>
                        <div class="jobs-drawer-item" id="drawer-amount-submitted-wrap">
                            <span class="jobs-drawer-label">Amount Submitted</span>
                            <span class="jobs-drawer-value" id="drawer-amount-submitted">—</span>
                        </div>
                        <div class="jobs-drawer-item" id="drawer-difference-wrap">
                            <span class="jobs-drawer-label">Difference</span>
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
                <button type="button" id="drawerConfirmPaymentBtn" class="btn btn-confirm-payment" style="display:none;">
                    <i data-lucide="check-circle"></i>
                    <span>Confirm Payment</span>
                </button>
            </div>
        </div>

    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/jobs.js') }}"></script>
@endpush
