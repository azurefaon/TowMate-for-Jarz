{{--
    PHASE 1 — MOCK / DESIGN PREVIEW ONLY.
    No Booking:: queries, no real statuses, no real endpoints. All data below is
    hardcoded for visual review of the "Awaiting Payment Verification" /
    "Return for Correction" concept before any backend work is scoped.
    See plan: C:\Users\caval\.claude\plans\lets-brainstorm-and-clean-snappy-crystal.md
--}}
@extends('admin-dashboard.layouts.app')

@section('title', 'Active Jobs — Mock Preview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/jobs.css') }}">
    <link rel="stylesheet" href="{{ asset('dispatcher/css/jobs-mock.css') }}">
@endpush

@php
    // Mock roster only — shaped like the real $job view-model so markup below
    // matches jobs.blade.php's real .job-row structure exactly. `bucket` drives
    // tab filtering; `secondary` overrides the normal customer/unit aside line
    // for the two new states per the approved plan (no live availability shown).
    $mockJobs = [
        [
            'code' => 'BK-20260903-0007', 'bucket' => 'on-the-way', 'status' => 'On The Way', 'statusSlug' => 'on-the-way',
            'customer' => 'Rosa Fernandez', 'unit' => 'Unit 07', 'teamLeader' => 'Ana Reyes',
            'pickup' => '12 Maginhawa St, Diliman, Quezon City', 'dropoff' => 'Robinsons Magnolia, New Manila, Quezon City',
            'created' => '18m ago',
        ],
        [
            'code' => 'BK-20260903-0009', 'bucket' => 'in-progress', 'status' => 'In Progress', 'statusSlug' => 'in-progress',
            'customer' => 'Carlos Bautista', 'unit' => 'Unit 11', 'teamLeader' => 'Paolo Cruz',
            'pickup' => 'SM North EDSA, Quezon City', 'dropoff' => 'Fairview Terraces, Quezon City',
            'created' => '32m ago',
        ],
        [
            'code' => 'BK-20260903-0012', 'bucket' => 'awaiting-verification', 'status' => 'Awaiting Payment Verification', 'statusSlug' => 'awaiting-payment-verification',
            'customer' => 'Juan Dela Cruz', 'unit' => 'Unit 03', 'teamLeader' => 'Mark Santos', 'truckClass' => 'Medium Duty',
            'pickup' => '69 Belen, Novaliches, Quezon City', 'dropoff' => 'Quirino Highway, corner Regalado Hwy, Novaliches',
            'created' => '4m ago',
            'paymentMethod' => 'GCash', 'amountDue' => '2,800.00', 'amountSubmitted' => '2,800.00',
            'serviceCompletedAt' => 'Sep 3, 2026 · 1:42 PM', 'paymentSubmittedAt' => 'Sep 3, 2026 · 1:45 PM',
            'hasProof' => true,
        ],
        [
            'code' => 'BK-20260903-0014', 'bucket' => 'awaiting-verification', 'status' => 'Awaiting Payment Verification', 'statusSlug' => 'awaiting-payment-verification',
            'customer' => 'Liza Manalo', 'unit' => 'Unit 05', 'teamLeader' => 'Rey Villanueva', 'truckClass' => 'Medium Duty',
            'pickup' => 'Ayala Malls Cloverleaf, Balintawak, Quezon City', 'dropoff' => 'Trinoma, North Avenue, Quezon City',
            'created' => '11m ago',
            'paymentMethod' => 'Cash', 'amountDue' => '3,150.00', 'amountSubmitted' => '3,150.00',
            'serviceCompletedAt' => 'Sep 3, 2026 · 1:22 PM', 'paymentSubmittedAt' => 'Sep 3, 2026 · 1:24 PM',
            'hasProof' => false,
        ],
        [
            'code' => 'BK-20260903-0015', 'bucket' => 'awaiting-verification', 'status' => 'Awaiting Payment Verification', 'statusSlug' => 'awaiting-payment-verification',
            'customer' => 'Michael Tan', 'unit' => 'Unit 02', 'teamLeader' => 'Jerome Aquino', 'truckClass' => 'Light Duty',
            'pickup' => 'UP Town Center, Diliman, Quezon City', 'dropoff' => 'Katipunan Ave, Loyola Heights, Quezon City',
            'created' => '6m ago',
            'paymentMethod' => 'Bank Transfer', 'amountDue' => '2,450.00', 'amountSubmitted' => '2,450.00',
            'serviceCompletedAt' => 'Sep 3, 2026 · 2:03 PM', 'paymentSubmittedAt' => 'Sep 3, 2026 · 2:06 PM',
            'hasProof' => true,
        ],
        [
            'code' => 'BK-20260903-0010', 'bucket' => 'correction-required', 'status' => 'Correction Required', 'statusSlug' => 'correction-required',
            'customer' => 'Grace Lim', 'unit' => 'Unit 09', 'teamLeader' => 'Noel Ramos', 'truckClass' => 'Heavy Duty',
            'pickup' => 'Commonwealth Ave, Quezon City', 'dropoff' => 'Batasan Hills, Quezon City',
            'created' => '52m ago',
            'paymentMethod' => 'GCash', 'amountDue' => '4,200.00', 'amountSubmitted' => '4,000.00',
            'serviceCompletedAt' => 'Sep 3, 2026 · 12:50 PM', 'paymentSubmittedAt' => 'Sep 3, 2026 · 12:55 PM',
            'hasProof' => true, 'returnReason' => 'Payment amount does not match the final total.',
        ],
        [
            'code' => 'BK-20260903-0016', 'bucket' => 'on-the-way', 'status' => 'On The Way', 'statusSlug' => 'on-the-way',
            'customer' => 'Kevin Ong', 'unit' => 'Unit 06', 'teamLeader' => 'Danilo Cruz',
            'pickup' => 'Eastwood City, Libis, Quezon City', 'dropoff' => 'Marikina Heights, Marikina',
            'created' => '9m ago',
        ],
        [
            'code' => 'BK-20260903-0017', 'bucket' => 'in-progress', 'status' => 'In Progress', 'statusSlug' => 'in-progress',
            'customer' => 'Angela Reyes', 'unit' => 'Unit 01', 'teamLeader' => 'Ben Delos Santos',
            'pickup' => 'Cubao, Quezon City', 'dropoff' => 'Project 8, Quezon City',
            'created' => '27m ago',
        ],
    ];
    $activeOpsCount = collect($mockJobs)->whereIn('bucket', ['on-the-way', 'in-progress'])->count();
    $awaitingCount  = collect($mockJobs)->where('bucket', 'awaiting-verification')->count();
@endphp

@section('content')
    <div class="jobs-page jobs-mock-page" data-csrf="{{ csrf_token() }}">

        <div class="mock-banner">
            <i data-lucide="flask-conical"></i>
            <div>
                <strong>MOCK / DESIGN PREVIEW</strong> — Post-Service Completion &amp; Payment Verification redesign.
                No real bookings, no backend actions. Nothing here can affect live data.
            </div>
        </div>

        <div class="jobs-page-header mock-summary-row">
            <div class="mock-summary-pills">
                <span class="jobs-count-pill">{{ $activeOpsCount }} Active Operations</span>
                <span class="jobs-count-pill pill-awaiting">{{ $awaitingCount }} Awaiting Verification</span>
            </div>
            <p class="mock-summary-note">Awaiting Verification jobs are administrative pending-closure records —
                the towing service is already finished; only the dispatcher's review remains.</p>
        </div>

        <div class="rb-tabs mock-tabs" id="jobsMockTabs">
            <button type="button" class="rb-tab is-active" data-tab="all">
                <span>All Active</span>
                <span class="rb-tab-count">{{ count($mockJobs) }}</span>
            </button>
            <button type="button" class="rb-tab" data-tab="on-the-way">
                <span>On The Way</span>
                <span class="rb-tab-count">{{ collect($mockJobs)->where('bucket', 'on-the-way')->count() }}</span>
            </button>
            <button type="button" class="rb-tab" data-tab="in-progress">
                <span>In Progress</span>
                <span class="rb-tab-count">{{ collect($mockJobs)->where('bucket', 'in-progress')->count() }}</span>
            </button>
            <button type="button" class="rb-tab" data-tab="awaiting-verification">
                <span>Awaiting Verification</span>
                <span class="rb-tab-count">{{ $awaitingCount }}</span>
            </button>
        </div>

        <div class="jobs-list-wrap">
            @foreach ($mockJobs as $job)
                @php
                    $isVerification = in_array($job['bucket'], ['awaiting-verification', 'correction-required']);
                @endphp
                <div class="job-row js-open-job-row-mock" data-bucket="{{ $job['bucket'] }}" data-status-slug="{{ $job['statusSlug'] }}"
                    data-job-id="{{ $job['code'] }}"
                    data-status-label="{{ $job['status'] }}"
                    data-customer="{{ $job['customer'] }}"
                    data-unit="{{ $job['unit'] }}"
                    data-teamleader="{{ $job['teamLeader'] }}"
                    data-truck-class="{{ $job['truckClass'] ?? 'General Towing' }}"
                    data-pickup="{{ $job['pickup'] }}"
                    data-dropoff="{{ $job['dropoff'] }}"
                    data-created="{{ $job['created'] }}"
                    data-payment-method="{{ $job['paymentMethod'] ?? '' }}"
                    data-amount-due="{{ $job['amountDue'] ?? '' }}"
                    data-amount-submitted="{{ $job['amountSubmitted'] ?? '' }}"
                    data-service-completed-at="{{ $job['serviceCompletedAt'] ?? '' }}"
                    data-payment-submitted-at="{{ $job['paymentSubmittedAt'] ?? '' }}"
                    data-has-proof="{{ !empty($job['hasProof']) ? '1' : '' }}"
                    data-return-reason="{{ $job['returnReason'] ?? '' }}">

                    <span class="job-row-dot dot-{{ $job['statusSlug'] }}"></span>

                    <div class="job-row-body">
                        <div class="job-row-top">
                            <span class="job-row-code">{{ $job['code'] }}</span>
                            <span class="job-row-badge status-badge status-{{ $job['statusSlug'] }}">{{ $job['status'] }}</span>
                        </div>
                        @if ($isVerification)
                            <div class="job-row-route mock-secondary-line">
                                <i data-lucide="check-circle-2" style="width:13px;height:13px;"></i>
                                <span>{{ $job['bucket'] === 'correction-required' ? 'Service already completed · Submission needs correction' : 'Service completed · Payment review pending' }}</span>
                            </div>
                        @else
                            <div class="job-row-route">
                                <span>{{ \Illuminate\Support\Str::limit($job['pickup'], 40) }}</span>
                                <span class="job-row-arrow">→</span>
                                <span>{{ \Illuminate\Support\Str::limit($job['dropoff'], 40) }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="job-row-aside">
                        <span class="job-row-customer">{{ $job['customer'] }}</span>
                        <span class="job-row-unit">
                            <i data-lucide="truck" style="width:12px;height:12px;"></i>
                            {{ $job['unit'] }}
                        </span>
                    </div>

                    <span class="job-row-time">{{ $job['created'] }}</span>
                    <i data-lucide="chevron-right" class="job-row-chevron"></i>
                </div>
            @endforeach
        </div>

        {{-- Reference only — where the flow ends, before Booking History. Not interactive. --}}
        <div class="mock-completed-preview">
            <div class="mock-completed-preview-label">For reference — once verification finishes</div>
            <div class="job-row mock-completed-row">
                <span class="job-row-dot dot-completed-preview"></span>
                <div class="job-row-body">
                    <div class="job-row-top">
                        <span class="job-row-code">BK-20260903-0012</span>
                        <span class="job-row-badge status-badge status-completed-preview">Completed</span>
                    </div>
                    <div class="job-row-route mock-secondary-line">
                        <i data-lucide="check-circle-2" style="width:13px;height:13px;"></i>
                        <span>Payment verified · Receipt sent · Moved to Booking History</span>
                    </div>
                </div>
                <div class="job-row-aside">
                    <span class="job-row-customer">Juan Dela Cruz</span>
                </div>
            </div>
        </div>

        {{-- Job Detail Modal (mock) — same DOM/class conventions as the real jobs.blade.php modal --}}
        <div class="job-modal" id="jobModalMock">
            <div class="modal-overlay"></div>
            <div class="modal-content">

                <div class="modal-header">
                    <div class="modal-header-left">
                        <p class="modal-eyebrow">Active Job — Mock</p>
                        <h2 class="modal-title" id="modalTitleMock">—</h2>
                    </div>
                    <div class="modal-header-right">
                        <span class="modal-status-pill" id="modalStatusPillMock"></span>
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
                                <span class="route-addr" id="jobm-pickup">—</span>
                            </div>
                            <div class="route-line"></div>
                            <div class="modal-route-row">
                                <span class="route-dot route-to"></span>
                                <span class="route-addr" id="jobm-dropoff">—</span>
                            </div>
                        </div>
                    </div>

                    {{-- Customer --}}
                    <div class="modal-section">
                        <div class="modal-section-title">Customer</div>
                        <div class="detail-grid">
                            <div class="detail-item full-width">
                                <span class="detail-label">Name</span>
                                <span class="detail-value" id="jobm-customer">—</span>
                            </div>
                        </div>
                    </div>

                    {{-- Unit used at service (historical only — no live availability) --}}
                    <div class="modal-section">
                        <div class="modal-section-title" id="jobm-unit-section-title">Assigned Unit</div>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Unit</span>
                                <span class="detail-value" id="jobm-unit">—</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Tow Type</span>
                                <span class="detail-value" id="jobm-truckclass">—</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Team Leader</span>
                                <span class="detail-value" id="jobm-teamleader">—</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Job Started</span>
                                <span class="detail-value" id="jobm-time">—</span>
                            </div>
                        </div>
                        <p class="mock-historical-note" id="jobm-historical-note" style="display:none;">
                            Historical assignment only — current Unit/Team Leader availability is not queried here.
                            Check <strong>Units &amp; Leaders</strong> for live status.
                        </p>
                    </div>

                    {{-- Payment Summary --}}
                    <div class="payment-section" id="paymentSectionMock" style="display:none;">
                        <div class="modal-section-title">
                            <i data-lucide="credit-card" style="width:14px;height:14px;"></i>
                            Payment Summary
                        </div>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Final Amount Due</span>
                                <span class="detail-value" id="jobm-amount-due">—</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Payment Method</span>
                                <span class="detail-value" id="jobm-payment-method">—</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Amount Submitted</span>
                                <span class="detail-value" id="jobm-amount-submitted">—</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Payment Submitted</span>
                                <span class="detail-value" id="jobm-payment-submitted-at">—</span>
                            </div>
                            <div class="detail-item full-width">
                                <span class="detail-label">Service Completed</span>
                                <span class="detail-value" id="jobm-service-completed-at">—</span>
                            </div>
                        </div>
                    </div>

                    {{-- Payment Proof --}}
                    <div class="modal-section" id="paymentProofSectionMock" style="display:none;">
                        <div class="modal-section-title">Payment Proof</div>
                        <div class="mock-proof-box">
                            <i data-lucide="image"></i>
                            <span id="jobm-proof-label">Mock payment proof image</span>
                        </div>
                    </div>

                    {{-- Customer Signature --}}
                    <div class="modal-section" id="signatureSectionMock" style="display:none;">
                        <div class="modal-section-title">Customer Service Completion Signature</div>
                        <div class="mock-signature-box">
                            <svg viewBox="0 0 220 70" class="mock-signature-svg" aria-label="Mock customer signature">
                                <path d="M10,50 C25,15 35,60 48,35 C58,18 65,55 75,40 C82,30 88,45 98,38 C112,28 120,52 135,32 C148,15 158,48 172,28 C182,15 190,42 205,25"
                                    fill="none" stroke="#334155" stroke-width="2.2" stroke-linecap="round" />
                            </svg>
                            <span class="mock-signature-caption">Signature captured on Team Leader device (mock)</span>
                        </div>
                    </div>

                    {{-- Return reason (only visible on Correction Required) --}}
                    <div class="modal-section" id="returnReasonSectionMock" style="display:none;">
                        <div class="modal-section-title">Return Reason</div>
                        <p class="mock-return-reason" id="jobm-return-reason">—</p>
                    </div>

                </div>

                <div class="modal-actions">
                    <a href="{{ route('admin.dispatch') }}" class="btn btn-secondary">Open Dispatch Queue</a>
                    <button type="button" id="returnForCorrectionBtnMock" class="btn btn-return" style="display:none;">
                        <i data-lucide="undo-2"></i>
                        Return for Correction
                    </button>
                    <button type="button" id="confirmPaymentBtnMock" class="btn btn-confirm-payment" style="display:none;">
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

        {{-- Return for Correction reason modal (mock) --}}
        <div class="rb-view-all-modal-backdrop" id="correctionModalBackdrop">
            <div class="rb-view-all-modal" id="correctionModalBox">
                <div class="rb-view-all-modal-head">
                    <div>
                        <h3 style="margin:0 0 4px;font-size:15px;color:#111111;">Return Submission for Correction</h3>
                        <p style="margin:0;font-size:12.5px;color:#5B6472;line-height:1.5;">
                            Explain what needs to be corrected before the submission can be reviewed again.
                        </p>
                    </div>
                </div>
                <div class="rb-view-all-modal-body">
                    <textarea id="correctionReasonInput" rows="3" placeholder="e.g. Payment amount does not match, payment proof is unclear, incorrect payment proof, missing payment information"
                        style="width:100%;box-sizing:border-box;border:1px solid #E3E6EB;border-radius:8px;padding:10px 12px;font:13px/1.5 'Public Sans',system-ui,sans-serif;color:#111111;resize:vertical;"></textarea>
                    <p class="correction-error" id="correctionReasonError" style="display:none;margin:6px 0 0;font-size:12px;color:#D8402C;">
                        A reason is required to return this submission.
                    </p>
                </div>
                <div class="rb-view-all-modal-foot">
                    <button type="button" class="rb-btn rb-btn-secondary" id="correctionCancelBtn">Cancel</button>
                    <button type="button" class="rb-btn rb-btn-primary rb-btn-danger-fill" id="correctionConfirmBtn">Return Submission</button>
                </div>
            </div>
        </div>

    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/jobs-mock.js') }}"></script>
@endpush
