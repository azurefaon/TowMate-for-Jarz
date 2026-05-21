@extends('admin-dashboard.layouts.app')

@section('title', 'Active Jobs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/jobs.css') }}">
@endpush

@section('content')
    <div class="jobs-page" data-csrf="{{ csrf_token() }}">

        <div class="jobs-page-header">
            <div>
                <h1 class="jobs-page-title">Active Jobs</h1>
                <p class="jobs-page-sub">Ongoing towing tasks being handled by field crews</p>
            </div>
            <span class="jobs-count-pill">{{ $stats['total'] }} active now</span>
        </div>

        <div class="jobs-list-wrap">
            @forelse ($jobs as $job)
                @php
                    $customer    = optional($job->customer)->full_name ?? optional($job->customer)->name ?? 'Customer unavailable';
                    $custPhone   = optional($job->customer)->phone ?? '';
                    $custEmail   = optional($job->customer)->email ?? '';
                    $unit        = optional($job->unit)->name ?? 'Unassigned';
                    $teamLeader  = optional(optional($job->unit)->teamLeader)->full_name
                        ?? optional(optional($job->unit)->teamLeader)->name
                        ?? optional($job->assignedTeamLeader)->name
                        ?? 'Unassigned';
                    $driver      = $job->driver_name
                        ?? optional(optional($job->unit)->driver)->full_name
                        ?? optional(optional($job->unit)->driver)->name
                        ?? 'No member assigned';
                    $towType     = optional($job->truckType)->name ?? 'General Tow';
                    $pickup      = $job->pickup_address ?? 'Pickup pending';
                    $dropoff     = $job->dropoff_address ?? 'Drop-off pending';
                    $statusSlug  = str_replace('_', '-', $job->status);
                    $finalTotal  = $job->final_total ? number_format((float) $job->final_total, 2) : '';
                    $vehicleImgs = array_values(array_map(fn($p) => asset('storage/' . $p), $job->vehicle_image_paths ?? []));
                @endphp
                <div class="job-row js-open-job-row"
                    data-job-id="{{ $job->job_code }}"
                    data-booking-id="{{ $job->id }}"
                    data-confirm-url="{{ route('admin.jobs.confirm-payment', $job) }}"
                    data-customer="{{ $customer }}"
                    data-phone="{{ $custPhone }}"
                    data-email="{{ $custEmail }}"
                    data-service="{{ $towType }}"
                    data-status="{{ $job->status }}"
                    data-unit="{{ $unit }}"
                    data-teamleader="{{ $teamLeader }}"
                    data-driver="{{ $driver }}"
                    data-total="{{ $finalTotal }}"
                    data-created="{{ $job->created_at->diffForHumans() }}"
                    data-pickup="{{ $pickup }}"
                    data-dropoff="{{ $dropoff }}"
                    data-vehicle-images="{{ e(json_encode($vehicleImgs)) }}"
                    data-payment-method="{{ $job->payment_method ?? '' }}"
                    data-payment-proof="{{ e(json_encode($job->payment_proof_path ? array_values(array_map(fn($p) => asset('storage/' . $p), (array) $job->payment_proof_path)) : [])) }}"
                    data-payment-submitted-at="{{ $job->payment_submitted_at ? $job->payment_submitted_at->format('M d, Y g:i A') : '' }}">

                    <span class="job-row-dot dot-{{ $statusSlug }}"></span>

                    <div class="job-row-body">
                        <div class="job-row-top">
                            <span class="job-row-code">{{ $job->job_code }}</span>
                            <span class="job-row-badge status-badge status-{{ $statusSlug }}">
                                {{ ucwords(str_replace('_', ' ', $job->status)) }}
                            </span>
                        </div>
                        <div class="job-row-route">
                            <span>{{ \Illuminate\Support\Str::limit($pickup, 42) }}</span>
                            <span class="job-row-arrow">→</span>
                            <span>{{ \Illuminate\Support\Str::limit($dropoff, 42) }}</span>
                        </div>
                    </div>

                    <div class="job-row-aside">
                        <span class="job-row-customer">{{ $customer }}</span>
                        <span class="job-row-unit">
                            <i data-lucide="truck" style="width:12px;height:12px;"></i>
                            {{ $unit }}
                        </span>
                    </div>

                    <span class="job-row-time">{{ $job->created_at->diffForHumans() }}</span>
                    <i data-lucide="chevron-right" class="job-row-chevron"></i>
                </div>
            @empty
                <div class="jobs-empty">
                    <i data-lucide="truck"></i>
                    <p>No active jobs right now</p>
                    <span>Jobs will appear here once a team leader starts a towing task.</span>
                </div>
            @endforelse
        </div>

        <div class="jobs-pagination">
            {{ $jobs->onEachSide(1)->links() }}
        </div>

        {{-- Job Detail Modal --}}
        <div class="job-modal" id="jobModal">
            <div class="modal-overlay"></div>
            <div class="modal-content">

                <div class="modal-header">
                    <div class="modal-header-left">
                        <p class="modal-eyebrow">Active Job</p>
                        <h2 class="modal-title" id="modalTitle">—</h2>
                    </div>
                    <div class="modal-header-right">
                        <span class="modal-status-pill" id="modalStatusPill"></span>
                        <button type="button" class="close-modal" aria-label="Close">×</button>
                    </div>
                </div>

                <div class="modal-body">

                    {{-- Route --}}
                    <div class="modal-section">
                        <div class="modal-section-title">Route</div>
                        <div class="modal-route-box">
                            <div class="modal-route-row">
                                <span class="route-dot route-from"></span>
                                <span class="route-addr" id="job-pickup">—</span>
                            </div>
                            <div class="route-line"></div>
                            <div class="modal-route-row">
                                <span class="route-dot route-to"></span>
                                <span class="route-addr" id="job-dropoff">—</span>
                            </div>
                        </div>
                    </div>

                    {{-- Customer --}}
                    <div class="modal-section">
                        <div class="modal-section-title">Customer</div>
                        <div class="detail-grid">
                            <div class="detail-item full-width">
                                <span class="detail-label">Name</span>
                                <span class="detail-value" id="job-customer">—</span>
                            </div>
                            <div class="detail-item" id="job-phone-wrap">
                                <span class="detail-label">Phone</span>
                                <span class="detail-value" id="job-phone">—</span>
                            </div>
                            <div class="detail-item" id="job-email-wrap">
                                <span class="detail-label">Email</span>
                                <span class="detail-value" id="job-email">—</span>
                            </div>
                        </div>
                    </div>

                    {{-- Vehicle images from customer --}}
                    <div class="modal-section" id="vehicleImagesSection" style="display:none;">
                        <div class="modal-section-title">Customer's Vehicle</div>
                        <div id="vehicleImagesContainer" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
                    </div>

                    {{-- Total price --}}
                    <div class="modal-section" id="totalSection" style="display:none;">
                        <div class="modal-section-title">Total Price</div>
                        <div class="total-price-box">
                            <span class="total-price-label">Final Total</span>
                            <span class="total-price-value" id="job-total">—</span>
                        </div>
                    </div>

                    {{-- Assigned Unit --}}
                    <div class="modal-section">
                        <div class="modal-section-title">Assigned Unit</div>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Unit</span>
                                <span class="detail-value" id="job-unit">—</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Tow Type</span>
                                <span class="detail-value" id="job-service">—</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Team Leader</span>
                                <span class="detail-value" id="job-teamleader">—</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Member Driver</span>
                                <span class="detail-value" id="job-driver">—</span>
                            </div>
                        </div>
                    </div>

                    {{-- Meta --}}
                    <div class="modal-section">
                        <div class="detail-grid">
                            <div class="detail-item full-width">
                                <span class="detail-label">Job Started</span>
                                <span class="detail-value" id="job-time">—</span>
                            </div>
                        </div>
                    </div>

                    {{-- Payment --}}
                    <div class="payment-section" id="paymentSection" style="display:none;">
                        <div class="modal-section-title">
                            <i data-lucide="credit-card" style="width:14px;height:14px;"></i>
                            Payment Submission
                        </div>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Method</span>
                                <span class="detail-value" id="job-payment-method">—</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Submitted At</span>
                                <span class="detail-value" id="job-payment-submitted-at">—</span>
                            </div>
                        </div>
                        <div class="payment-proof-area" id="paymentProofArea" style="display:none;">
                            <span class="detail-label">Payment Proof</span>
                            <div id="job-payment-proof-container"
                                style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;"></div>
                        </div>
                    </div>

                </div>

                <div class="modal-actions">
                    <a href="{{ route('admin.dispatch') }}" class="btn btn-secondary">Open Dispatch Queue</a>
                    <button type="button" id="confirmPaymentBtn" class="btn btn-confirm-payment" style="display:none;">
                        <i data-lucide="check-circle"></i>
                        <span>Confirm Payment</span>
                    </button>
                    <button type="button" class="btn btn-primary close-modal-btn">
                        <i data-lucide="check"></i>
                        Close
                    </button>
                </div>

            </div>
        </div>

    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/jobs.js') }}"></script>
@endpush
