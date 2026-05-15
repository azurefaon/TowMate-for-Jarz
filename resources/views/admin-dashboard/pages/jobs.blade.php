@extends('admin-dashboard.layouts.app')

@section('title', 'Active Jobs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/jobs.css') }}">
@endpush

@section('content')
    <div class="jobs-page" data-csrf="{{ csrf_token() }}">

        <div class="jobs-section-head">
            <div></div>
            <span class="jobs-section-pill">{{ $stats['total'] }} active now</span>
        </div>

        <div class="jobs-grid">
            @forelse ($jobs as $job)
                <article class="job-card" data-job-id="{{ $job->job_code }}" data-booking-id="{{ $job->id }}"
                    data-confirm-url="{{ route('admin.jobs.confirm-payment', $job) }}"
                    data-customer="{{ optional($job->customer)->full_name ?? (optional($job->customer)->name ?? 'Customer unavailable') }}"
                    data-service="{{ optional($job->truckType)->name ?? 'General Tow' }}" data-status="{{ $job->status }}"
                    data-unit="{{ optional($job->unit)->name ?? 'Unassigned' }}"
                    data-teamleader="{{ optional(optional($job->unit)->teamLeader)->full_name ?? (optional(optional($job->unit)->teamLeader)->name ?? (optional($job->assignedTeamLeader)->name ?? 'Unassigned')) }}"
                    data-driver="{{ $job->driver_name ?? (optional(optional($job->unit)->driver)->full_name ?? (optional(optional($job->unit)->driver)->name ?? 'No member assigned')) }}"
                    data-created="{{ $job->created_at->diffForHumans() }}"
                    data-pickup="{{ $job->pickup_address ?? 'Pickup location pending' }}"
                    data-dropoff="{{ $job->dropoff_address ?? 'Drop-off location pending' }}"
                    data-payment-method="{{ $job->payment_method ?? '' }}"
                    data-payment-proof="{{ e(json_encode($job->payment_proof_path ? array_values(array_map(fn($p) => asset('storage/' . $p), (array) $job->payment_proof_path)) : [])) }}"
                    data-payment-submitted-at="{{ $job->payment_submitted_at ? $job->payment_submitted_at->format('M d, Y g:i A') : '' }}">

                    <div class="job-header">
                        <span class="job-id">Job {{ $job->job_code }}</span>
                        <span class="status-badge status-{{ str_replace('_', '-', $job->status) }}">
                            {{ ucwords(str_replace('_', ' ', $job->status)) }}
                        </span>
                    </div>

                    <div class="job-body">
                        <div class="job-route">
                            <strong>{{ \Illuminate\Support\Str::limit($job->pickup_address ?? 'Pickup pending', 35) }}</strong>
                            <span>to</span>
                            <span>{{ \Illuminate\Support\Str::limit($job->dropoff_address ?? 'Drop-off pending', 32) }}</span>
                        </div>

                        <div class="job-meta-grid">
                            <div class="job-meta-item">
                                <span class="job-label">Customer</span>
                                <span
                                    class="job-value">{{ optional($job->customer)->full_name ?? (optional($job->customer)->name ?? 'Unknown') }}</span>
                            </div>
                            <div class="job-meta-item">
                                <span class="job-label">Tow Type</span>
                                <span class="job-value">{{ optional($job->truckType)->name ?? 'General Tow' }}</span>
                            </div>
                            <div class="job-meta-item">
                                <span class="job-label">Unit</span>
                                <span class="job-value">{{ optional($job->unit)->name ?? 'Unassigned' }}</span>
                            </div>
                            <div class="job-meta-item">
                                <span class="job-label">Team Leader</span>
                                <span
                                    class="job-value">{{ optional(optional($job->unit)->teamLeader)->full_name ?? (optional(optional($job->unit)->teamLeader)->name ?? (optional($job->assignedTeamLeader)->name ?? 'Unassigned')) }}</span>
                            </div>
                            <div class="job-meta-item full-width">
                                <span class="job-label">Member Driver</span>
                                <span
                                    class="job-value">{{ $job->driver_name ?? (optional(optional($job->unit)->driver)->full_name ?? (optional(optional($job->unit)->driver)->name ?? 'No member assigned')) }}</span>
                            </div>
                        </div>

                        @if ($job->status === 'payment_submitted')
                            <div class="job-payment-alert">
                                <i data-lucide="credit-card"></i>
                                <span>Payment proof submitted - awaiting confirmation</span>
                            </div>
                        @endif

                        <button type="button" class="job-view-btn js-open-job-modal">
                            <i data-lucide="eye"></i>
                            <span>View details</span>
                        </button>
                    </div>
                </article>
            @empty
                <div class="empty-state jobs-empty-state">
                    <h3>No active jobs</h3>
                    <p>Team Leader taken and active towing jobs will appear here as soon as the field crew starts work.</p>
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
                    <div>
                        <h2 class="modal-title" id="modalTitle">Job Details</h2>
                        <p class="modal-subtitle">Dispatcher view of the assigned towing job.</p>
                    </div>
                    <button type="button" class="close-modal" aria-label="Close">×</button>
                </div>

                <div class="modal-body">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Customer</span>
                            <span class="detail-value" id="job-customer">—</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Tow Type</span>
                            <span class="detail-value" id="job-service">—</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status</span>
                            <span class="detail-value" id="job-status">—</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Unit</span>
                            <span class="detail-value" id="job-unit">—</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Team Leader</span>
                            <span class="detail-value" id="job-teamleader">—</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Member Driver</span>
                            <span class="detail-value" id="job-driver">—</span>
                        </div>
                        <div class="detail-item full-width">
                            <span class="detail-label">Pickup</span>
                            <span class="detail-value" id="job-pickup">—</span>
                        </div>
                        <div class="detail-item full-width">
                            <span class="detail-label">Drop-off</span>
                            <span class="detail-value" id="job-dropoff">—</span>
                        </div>
                        <div class="detail-item full-width">
                            <span class="detail-label">Created</span>
                            <span class="detail-value" id="job-time">—</span>
                        </div>
                    </div>

                    {{-- Payment section — shown for payment_pending and payment_submitted --}}
                    <div class="payment-section" id="paymentSection" style="display:none;">
                        <div class="payment-section-title">
                            <i data-lucide="credit-card"></i>
                            <span>Payment Submission</span>
                        </div>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Payment Method</span>
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
