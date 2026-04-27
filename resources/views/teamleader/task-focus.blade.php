@extends('teamleader.layouts.app')

@section('title', 'Job · ' . $booking->job_code)
@section('page_title', 'Active Job')

@php
    $appUrl = rtrim(config('app.url') ?: request()->getSchemeAndHttpHost(), '/');
    $assetBase = $appUrl . '/teamleader-assets';
    $tasksCssPath = public_path('teamleader-assets/css/tasks.css');

    $distanceKm = (float) ($booking->distance_km ?? 0);
    $kmIncrements = (int) floor($distanceKm / 4);
    $distanceFee = round($kmIncrements * 200.0, 2);
    $baseRate = (float) ($booking->base_rate ?: $booking->truckType?->base_rate ?? 0);
    $additionalFee = (float) ($booking->additional_fee ?? 0);
    $computedTotal = round($baseRate + $distanceFee + $additionalFee, 2);
    $finalTotal = (float) ($booking->final_total ?: $computedTotal);

    $unitDriver =
        $booking->unit?->driver?->full_name ??
        ($booking->unit?->driver?->name ?? ($booking->unit?->driver_name ?? '—'));
    $tlName =
        $booking->assignedTeamLeader?->full_name ??
        ($booking->assignedTeamLeader?->name ?? ($booking->unit?->teamLeader?->full_name ?? '—'));

    $serviceLabel = $booking->service_type === 'book_now' ? 'Book Now' : 'Scheduled';
    $scheduledFor = $booking->scheduled_for ? $booking->scheduled_for->format('M d, Y h:i A') : null;
    $currentStatus = $booking->status;
@endphp

@push('styles')
    @if (is_file($tasksCssPath))
        <style>
            {!! file_get_contents($tasksCssPath) !!}
        </style>
    @endif

    <style>
        /* ── Page wrapper ── */
        .tf-page {
            max-width: 860px;
            margin: 0 auto;
            padding: 0 0 60px;
        }

        /* ── Stepper ── */
        .tf-stepper {
            display: flex;
            align-items: flex-start;
            gap: 0;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e4e4e7;
            padding: 18px 24px;
            margin-bottom: 16px;
            overflow-x: auto;
        }

        .tf-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            flex: 1;
            min-width: 64px;
            position: relative;
        }

        .tf-step__bubble {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #f4f4f5;
            border: 2px solid #3e3e3e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            color: #000000;
            transition: all .2s;
        }

        .tf-step.is-complete .tf-step__bubble {
            background: #16a34a;
            border-color: #16a34a;
            color: #fff;
        }

        .tf-step.is-active .tf-step__bubble {
            background: #facc15;
            border-color: #facc15;
            color: #000000;
        }

        .tf-step__label {
            text-align: center;
        }

        .tf-step__label strong {
            display: block;
            font-size: 11px;
            color: #3f3f46;
        }

        .tf-step__label small {
            display: block;
            font-size: 10px;
            color: #a1a1aa;
        }

        .tf-step.is-active .tf-step__label strong {
            color: #09090b;
        }

        .tf-step.is-complete .tf-step__label strong {
            color: #16a34a;
        }

        .tf-step-line {
            flex: 1;
            height: 2px;
            background: #e4e4e7;
            margin-top: 17px;
            min-width: 16px;
            transition: background .2s;
        }

        .tf-step-line.is-done {
            background: #16a34a;
        }

        /* ── Status banner ── */
        .tf-banner {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e4e4e7;
            padding: 18px 24px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .tf-banner__meta {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .tf-banner__code {
            font-size: 11px;
            font-weight: 700;
            color: #a1a1aa;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .tf-banner__note {
            font-size: 13px;
            color: #52525b;
        }

        .tf-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            border: 1px solid transparent;
        }

        .tf-status-pill.assigned {
            background: #f0fdf4;
            color: #15803d;
            border-color: #86efac;
        }

        .tf-status-pill.on-the-way {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .tf-status-pill.in-progress {
            background: #fefce8;
            color: #a16207;
            border-color: #fde047;
        }

        .tf-status-pill.waiting-verification {
            background: #faf5ff;
            color: #7c3aed;
            border-color: #c4b5fd;
        }

        .tf-status-pill.payment-pending {
            background: #fff7ed;
            color: #c2410c;
            border-color: #fdba74;
        }

        .tf-status-pill.payment-submitted {
            background: #f0fdf4;
            color: #15803d;
            border-color: #86efac;
        }

        .tf-status-pill.completed {
            background: #f0fdf4;
            color: #15803d;
            border-color: #86efac;
        }

        /* ── Info grid ── */
        .tf-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 16px;
        }

        @media (max-width: 600px) {
            .tf-info-grid {
                grid-template-columns: 1fr;
            }
        }

        .tf-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e4e4e7;
            padding: 18px 20px;
        }

        .tf-card--full {
            grid-column: 1 / -1;
        }

        .tf-card__head {
            font-size: 11px;
            font-weight: 700;
            color: #71717a;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin: 0 0 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f4f4f5;
        }

        .tf-card__rows {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .tf-card__rows--horiz {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
        }

        .tf-card__row {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .tf-card__row--full {
            grid-column: 1 / -1;
        }

        .tf-card__label {
            font-size: 11px;
            color: #a1a1aa;
            font-weight: 600;
        }

        .tf-card__value {
            font-size: 13px;
            color: #18181b;
            font-weight: 500;
            word-break: break-word;
        }

        /* Unit badge */
        .tf-unit-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f9fafb;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 12px;
        }

        .tf-unit-badge__name {
            font-size: 14px;
            font-weight: 700;
            color: #09090b;
        }

        .tf-unit-type {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: #e4e4e7;
            color: #3f3f46;
            margin-left: auto;
        }

        /* Pricing */
        .tf-price-rows {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .tf-price-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #3f3f46;
        }

        .tf-price-divider {
            border: none;
            border-top: 1px solid #e4e4e7;
            margin: 6px 0;
        }

        .tf-price-row--total {
            font-size: 15px;
            font-weight: 800;
            color: #09090b;
        }

        .tf-price-note {
            font-size: 11px;
            color: #a1a1aa;
            margin: 6px 0 0;
            text-align: right;
        }

        /* Service badge */
        .tf-svc-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }

        .tf-svc-badge--now {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .tf-svc-badge--sched {
            background: #faf5ff;
            color: #7c3aed;
        }

        .tf-mono {
            font-family: monospace;
            font-size: 13px;
        }

        /* ── Action area ── */
        .tf-action-area {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e4e4e7;
            padding: 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .tf-action-btns {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .tf-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            padding: 13px 20px;
            border: none;
            cursor: pointer;
            width: 100%;
            transition: background .15s, opacity .15s;
        }

        .tf-btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .tf-btn--primary {
            background: #09090b;
            color: #fff;
        }

        .tf-btn--primary:hover:not(:disabled) {
            background: #27272a;
        }

        .tf-btn--ghost {
            background: #f4f4f5;
            color: #3f3f46;
        }

        .tf-btn--ghost:hover:not(:disabled) {
            background: #e4e4e7;
        }

        .tf-btn--success {
            background: #16a34a;
            color: #fff;
        }

        .tf-btn--success:hover:not(:disabled) {
            background: #15803d;
        }

        .tf-btn--danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .tf-feedback {
            font-size: 13px;
            color: #71717a;
            text-align: center;
            margin: 0;
        }

        .tf-feedback.is-error {
            color: #dc2626;
        }

        /* Waiting card */
        .tf-waiting-card {
            background: #faf5ff;
            border: 1px solid #c4b5fd;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            font-size: 13px;
            color: #7c3aed;
        }

        .tf-waiting-card strong {
            display: block;
            font-size: 15px;
            margin-bottom: 4px;
        }

        /* ── Payment form ── */
        .tf-payment-section {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .tf-payment-section h3 {
            margin: 0;
            font-size: 16px;
            color: #09090b;
        }

        .tf-payment-section p {
            margin: 0;
            font-size: 13px;
            color: #52525b;
        }

        .tf-pm-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .tf-pm-label {
            cursor: pointer;
        }

        .tf-pm-label input[type=radio] {
            display: none;
        }

        .tf-pm-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            border: 2px solid #e4e4e7;
            border-radius: 12px;
            padding: 14px 8px;
            font-size: 13px;
            font-weight: 600;
            color: #3f3f46;
            transition: border-color .15s, background .15s;
        }

        .tf-pm-label input:checked+.tf-pm-btn {
            border-color: #09090b;
            background: #f9fafb;
            color: #09090b;
        }

        .tf-pm-icon {
            font-size: 20px;
        }

        .tf-file-drop {
            border: 2px dashed #e4e4e7;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color .15s, background .15s;
            position: relative;
        }

        .tf-file-drop:hover,
        .tf-file-drop.is-hover {
            border-color: #09090b;
            background: #f9fafb;
        }

        .tf-file-drop p {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #18181b;
        }

        .tf-file-drop small {
            font-size: 12px;
            color: #a1a1aa;
        }

        #proofPreview {
            width: 100%;
            max-height: 200px;
            object-fit: contain;
            border-radius: 8px;
            margin-top: 8px;
        }

        /* Payment submitted state */
        .tf-submitted-card {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 14px;
            padding: 24px;
            text-align: center;
        }

        .tf-submitted-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: #16a34a;
            color: #fff;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
        }

        .tf-submitted-card h3 {
            margin: 0 0 6px;
            font-size: 17px;
            color: #14532d;
        }

        .tf-submitted-card p {
            margin: 0;
            font-size: 13px;
            color: #166534;
        }

        .tf-submitted-meta {
            margin-top: 12px;
            font-size: 12px;
            color: #15803d;
        }

        /* ── PayMongo payment card ── */
        .tf-paymongo-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e4e4e7;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .tf-paymongo-header {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .tf-paymongo-icon {
            font-size: 28px;
            line-height: 1;
        }

        .tf-paymongo-amount {
            text-align: center;
            font-size: 28px;
            font-weight: 800;
            color: #09090b;
            background: #f4f4f5;
            border-radius: 12px;
            padding: 14px;
        }

        .tf-pm-tabs {
            display: flex;
            gap: 8px;
        }

        .tf-pm-tab {
            flex: 1;
            padding: 10px 0;
            border: 1.5px solid #e4e4e7;
            border-radius: 10px;
            background: #f4f4f5;
            font-size: 14px;
            font-weight: 700;
            color: #71717a;
            cursor: pointer;
            transition: all .15s;
            letter-spacing: .2px;
        }

        .tf-pm-tab--active {
            background: #09090b;
            color: #fff;
            border-color: #09090b;
        }

        .tf-pm-fields {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .tf-pm-row {
            display: flex;
            gap: 10px;
        }

        .tf-pm-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1;
        }

        .tf-pm-field label {
            font-size: 11px;
            font-weight: 700;
            color: #52525b;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .tf-pm-field input {
            border: 1.5px solid #e4e4e7;
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 16px;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
            outline: none;
            transition: border-color .15s;
            background: #fff;
            width: 100%;
            box-sizing: border-box;
        }

        .tf-pm-field input:focus {
            border-color: #09090b;
        }

        .tf-pm-error {
            font-size: 13px;
            color: #dc2626;
            text-align: center;
            min-height: 18px;
        }

        .tf-pm-gcash-desc {
            font-size: 12px;
            color: #71717a;
            text-align: center;
            margin: 4px 0 0;
        }

        .tf-paymongo-waiting {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            font-size: 13px;
            color: #52525b;
            font-weight: 600;
        }

        .tf-paymongo-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #f59e0b;
            animation: pm-pulse 1.2s ease-in-out infinite;
        }

        @keyframes pm-pulse {

            0%,
            100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: .4;
                transform: scale(.7);
            }
        }

        /* ── Return modal ── */
        .tl-dialog-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .55);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 1200;
        }

        .tl-dialog-backdrop.hidden {
            display: none;
        }

        .tl-dialog-card {
            width: min(520px, 100%);
            background: #fff;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .22);
        }

        .tl-dialog-card h3 {
            margin: 6px 0 8px;
        }

        .tl-dialog-card p {
            margin: 0 0 12px;
            color: #475569;
        }

        .tl-dialog-card select,
        .tl-dialog-card textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 10px 12px;
            font: inherit;
            margin-top: 8px;
        }

        .tl-dialog-card textarea {
            min-height: 100px;
            resize: vertical;
        }

        .tl-dialog-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 14px;
        }

        /* Toast */
        .tl-toast-wrap {
            position: fixed;
            bottom: 24px;
            right: 24px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 9999;
        }

        .tl-toast {
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .12);
            animation: tl-slide-in .25s ease;
        }

        .tl-toast--success {
            background: #09090b;
            color: #fff;
        }

        .tl-toast--error {
            background: #dc2626;
            color: #fff;
        }

        @keyframes tl-slide-in {
            from {
                transform: translateY(16px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .hidden {
            display: none !important;
        }
    </style>
@endpush

@section('content')
    <div class="tf-page" id="focusedTask" data-current-status="{{ $currentStatus }}"
        data-pickup-address="{{ $booking->pickup_address ?? '' }}"
        data-dropoff-address="{{ $booking->dropoff_address ?? '' }}"
        data-proceed-endpoint="{{ route('teamleader.task.proceed', $booking) }}"
        data-start-endpoint="{{ route('teamleader.task.start', $booking) }}"
        data-complete-endpoint="{{ route('teamleader.task.complete', $booking) }}"
        data-return-endpoint="{{ route('teamleader.task.return', $booking) }}"
        data-status-endpoint="{{ route('teamleader.task.status', $booking) }}"
        data-payment-status-endpoint="{{ route('teamleader.task.payment-status', $booking) }}"
        data-dashboard-url="{{ route('teamleader.dashboard') }}" data-tasks-url="{{ route('teamleader.tasks') }}"
        data-paymongo-checkout-url="{{ $booking->paymongo_checkout_url ?? '' }}"
        data-paymongo-public-key="{{ config('services.paymongo.public_key') }}"
        data-payment-method-type="{{ $booking->paymongo_intent_id ? 'card' : ($booking->paymongo_link_id ? 'gcash' : '') }}"
        data-final-total="{{ $finalTotal }}"
        data-gcash-number="{{ $gcashNumber ?? '09426386048' }}"
        data-proof-endpoint="{{ route('teamleader.task.payment', $booking) }}">

        {{-- ── Stepper ── --}}
        <div class="tf-stepper" id="tfStepper">
            <div class="tf-step" data-step="claimed">
                <div class="tf-step__bubble"><span>1</span></div>
                <div class="tf-step__label"><strong>Claimed</strong><small>Reserved to your crew</small></div>
            </div>
            <div class="tf-step-line" data-line="claimed-navigate"></div>
            <div class="tf-step" data-step="navigate">
                <div class="tf-step__bubble"><span>2</span></div>
                <div class="tf-step__label"><strong>Navigate</strong><small>Head to pickup</small></div>
            </div>
            <div class="tf-step-line" data-line="navigate-work"></div>
            <div class="tf-step" data-step="work">
                <div class="tf-step__bubble"><span>3</span></div>
                <div class="tf-step__label"><strong>Start Job</strong><small>Begin towing</small></div>
            </div>
            <div class="tf-step-line" data-line="work-dropoff"></div>
            <div class="tf-step" data-step="dropoff">
                <div class="tf-step__bubble"><span>4</span></div>
                <div class="tf-step__label"><strong>Drop Off</strong><small>Deliver vehicle</small></div>
            </div>
            <div class="tf-step-line" data-line="dropoff-payment"></div>
            <div class="tf-step" data-step="payment">
                <div class="tf-step__bubble"><span>5</span></div>
                <div class="tf-step__label"><strong>Payment</strong><small>Collect &amp; upload proof</small></div>
            </div>
            <div class="tf-step-line" data-line="payment-complete"></div>
            <div class="tf-step" data-step="complete">
                <div class="tf-step__bubble"><span>6</span></div>
                <div class="tf-step__label"><strong>Complete</strong><small>Job closed</small></div>
            </div>
        </div>

        {{-- ── Status banner ── --}}
        <div class="tf-banner">
            <div class="tf-banner__meta">
                <span class="tf-banner__code">Job {{ $booking->job_code }}</span>
                <span class="tf-banner__note" id="focusStatusNote">{{ $task['status_note'] }}</span>
            </div>
            <span class="tf-status-pill {{ str_replace('_', '-', $task['ui_status']) }}" id="focusStatusBadge">
                {{ $task['status_label'] }}
            </span>
        </div>

        {{-- ── Info grid ── --}}
        <div class="tf-info-grid">

            {{-- Customer Information --}}
            <div class="tf-card">
                <p class="tf-card__head">Customer Information</p>
                <div class="tf-card__rows">
                    <div class="tf-card__row">
                        <span class="tf-card__label">Name</span>
                        <span
                            class="tf-card__value">{{ $booking->customer?->full_name ?? ($booking->customer?->name ?? 'Guest') }}</span>
                    </div>
                    <div class="tf-card__row">
                        <span class="tf-card__label">Phone</span>
                        <span class="tf-card__value">{{ $booking->customer?->phone ?? '—' }}</span>
                    </div>
                    <div class="tf-card__row">
                        <span class="tf-card__label">Email</span>
                        <span class="tf-card__value">{{ $booking->customer?->email ?? '—' }}</span>
                    </div>
                </div>
            </div>

            {{-- Assigned Truck --}}
            <div class="tf-card">
                <p class="tf-card__head">Assigned Truck</p>
                <div class="tf-unit-badge">
                    <span class="tf-unit-badge__name">{{ $booking->unit?->name ?? 'Dispatch-assigned unit' }}</span>
                    <span class="tf-unit-type">{{ $booking->truckType?->name ?? 'Standard' }}</span>
                </div>
                <div class="tf-card__rows">
                    <div class="tf-card__row">
                        <span class="tf-card__label">Team Leader</span>
                        <span class="tf-card__value">{{ $tlName }}</span>
                    </div>
                    <div class="tf-card__row">
                        <span class="tf-card__label">Driver</span>
                        <span class="tf-card__value">{{ $unitDriver }}</span>
                    </div>
                    <div class="tf-card__row">
                        <span class="tf-card__label">Plate No.</span>
                        <span class="tf-card__value">{{ $booking->unit?->plate_number ?? '—' }}</span>
                    </div>
                </div>
            </div>

            {{-- Service --}}
            <div class="tf-card">
                <p class="tf-card__head">Service</p>
                <div class="tf-card__rows">
                    <div class="tf-card__row">
                        <span class="tf-card__label">Pickup</span>
                        <span class="tf-card__value">{{ $booking->pickup_address ?? '—' }}</span>
                    </div>
                    <div class="tf-card__row">
                        <span class="tf-card__label">Drop-off</span>
                        <span class="tf-card__value">{{ $booking->dropoff_address ?? '—' }}</span>
                    </div>
                    <div class="tf-card__row">
                        <span class="tf-card__label">Distance</span>
                        <span
                            class="tf-card__value">{{ $distanceKm > 0 ? number_format($distanceKm, 2) . ' km' : '—' }}</span>
                    </div>
                </div>
            </div>

            {{-- Pricing --}}
            <div class="tf-card">
                <p class="tf-card__head">Pricing</p>
                <div class="tf-price-rows">
                    <div class="tf-price-row">
                        <span>Base Rate</span>
                        <span>₱{{ number_format($baseRate, 2) }}</span>
                    </div>
                    <div class="tf-price-row">
                        <span>Distance ({{ number_format($distanceKm, 2) }} km ÷ 4 = {{ $kmIncrements }} × ₱200)</span>
                        <span>₱{{ number_format($distanceFee, 2) }}</span>
                    </div>
                    @if ($additionalFee > 0)
                        <div class="tf-price-row">
                            <span>Additional Fees</span>
                            <span>₱{{ number_format($additionalFee, 2) }}</span>
                        </div>
                    @endif
                    <hr class="tf-price-divider">
                    @if (abs($computedTotal - $finalTotal) > 0.01)
                        <div class="tf-price-row" style="color:#a1a1aa; font-size:12px;">
                            <span>Computed (Base + Distance + Fees)</span>
                            <span>₱{{ number_format($computedTotal, 2) }}</span>
                        </div>
                    @endif
                    <div class="tf-price-row tf-price-row--total">
                        <span>FINAL PRICE</span>
                        <span>₱{{ number_format($finalTotal, 2) }}</span>
                    </div>
                    @if (abs($computedTotal - $finalTotal) > 0.01)
                        <p class="tf-price-note">Adjusted by dispatcher from ₱{{ number_format($computedTotal, 2) }}</p>
                    @else
                        <p class="tf-price-note">Base Rate + Distance Fee{{ $additionalFee > 0 ? ' + Additional' : '' }}
                        </p>
                    @endif
                </div>
            </div>

            {{-- Booking Info --}}
            <div class="tf-card tf-card--full">
                <p class="tf-card__head">Booking Information</p>
                <div class="tf-card__rows tf-card__rows--horiz">
                    <div class="tf-card__row">
                        <span class="tf-card__label">Service Type</span>
                        <span class="tf-card__value">
                            <span
                                class="tf-svc-badge {{ $booking->service_type === 'book_now' ? 'tf-svc-badge--now' : 'tf-svc-badge--sched' }}">
                                {{ $serviceLabel }}
                            </span>
                        </span>
                    </div>
                    <div class="tf-card__row">
                        <span class="tf-card__label">Reference No.</span>
                        <span class="tf-card__value tf-mono">{{ $booking->quotation_number ?? $booking->job_code }}</span>
                    </div>
                    @if ($scheduledFor)
                        <div class="tf-card__row">
                            <span class="tf-card__label">Scheduled For</span>
                            <span class="tf-card__value">{{ $scheduledFor }}</span>
                        </div>
                    @endif
                </div>
            </div>

        </div>{{-- /tf-info-grid --}}

        {{-- ── Action area ── --}}
        <div class="tf-action-area">

            <div class="tf-action-btns" id="focusActionGroup">
                <button type="button" class="tf-btn tf-btn--primary {{ $task['can_proceed'] ? '' : 'hidden' }}"
                    id="proceedBtn">
                    Navigate to Pickup
                </button>
                <button type="button" class="tf-btn tf-btn--ghost {{ $currentStatus === 'on_the_way' ? '' : 'hidden' }}"
                    id="navigateMapsBtn">
                    Open Pickup in Maps
                </button>
                <button type="button" class="tf-btn tf-btn--primary {{ $task['can_start'] ? '' : 'hidden' }}"
                    id="startTowBtn">
                    Arrived — Start Job
                </button>
                <button type="button" class="tf-btn tf-btn--primary {{ $task['can_complete'] ? '' : 'hidden' }}"
                    id="completeTaskBtn">
                    Complete Job — Collect Payment
                </button>
                <button type="button" class="tf-btn tf-btn--ghost {{ $task['can_return'] ? '' : 'hidden' }}"
                    id="returnTaskBtn">
                    Return to Dispatch
                </button>
                <a href="{{ route('teamleader.dashboard') }}" class="tf-btn tf-btn--success hidden"
                    id="backToDashboardBtn">
                    Back to Dashboard
                </a>
            </div>

            {{-- Waiting for customer verification --}}
            <div id="waitingVerificationCard"
                class="tf-waiting-card {{ $currentStatus === 'waiting_verification' ? '' : 'hidden' }}">
                <strong>Waiting for Customer Confirmation</strong>
                A verification link was sent to the customer's email. This page updates automatically once they confirm.
            </div>

            {{-- Payment collection UI --}}
            <div id="paymongoArea" class="{{ in_array($currentStatus, ['payment_pending', 'payment_submitted']) ? '' : 'hidden' }}">
                <div class="tf-paymongo-card">

                    {{-- Amount --}}
                    <div class="tf-paymongo-amount" id="paymongoAmountDisplay">
                        ₱{{ number_format($finalTotal, 2) }}
                    </div>

                    {{-- Method selection (hidden once proof is submitted) --}}
                    <div id="pmMethodArea" class="{{ $currentStatus === 'payment_submitted' ? 'hidden' : '' }}">

                        {{-- Tabs --}}
                        <div class="tf-pm-tabs" id="pmMethodTabs">
                            <button type="button" class="tf-pm-tab tf-pm-tab--active" id="pmTabGcash">GCash</button>
                            <button type="button" class="tf-pm-tab" id="pmTabBank">Bank Transfer</button>
                            <button type="button" class="tf-pm-tab" id="pmTabCash">Cash</button>
                            @if(!empty($allowCheque))
                            <button type="button" class="tf-pm-tab" id="pmTabCheque">Cheque</button>
                            @endif
                        </div>

                        {{-- GCash section --}}
                        <div id="pmGcashSection" class="tf-pm-fields">
                            <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:14px;padding:16px 18px;margin-bottom:14px;text-align:center;">
                                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#15803d;margin-bottom:6px;">Send Payment to GCash</div>
                                <div style="font-size:28px;font-weight:800;color:#09090b;letter-spacing:3px;" id="gcashNumberDisplay">{{ $gcashNumber ?? '09426386048' }}</div>
                                <div style="font-size:12px;color:#52525b;margin-top:4px;">Ask the customer to send the exact amount to this number</div>
                            </div>
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#52525b;margin-bottom:8px;">Upload GCash Screenshot</div>
                            <label style="display:block;border:2px dashed #e4e4e7;border-radius:12px;padding:20px;text-align:center;cursor:pointer;">
                                <input type="file" id="gcashProofInput" accept="image/*" capture="environment" style="display:none;">
                                <div id="gcashProofPlaceholder">
                                    <div style="font-size:24px;margin-bottom:6px;">📷</div>
                                    <div style="font-size:13px;font-weight:600;color:#18181b;">Take or choose a photo</div>
                                    <div style="font-size:11px;color:#a1a1aa;margin-top:2px;">JPG, PNG or WebP · max 5 MB</div>
                                </div>
                                <img id="gcashProofPreview" src="" alt="" style="display:none;width:100%;max-height:180px;object-fit:contain;border-radius:8px;">
                            </label>
                            <p id="gcashProofError" style="font-size:13px;color:#dc2626;text-align:center;min-height:18px;margin:8px 0 4px;"></p>
                            <button type="button" class="tf-btn tf-btn--primary" id="gcashSubmitBtn">Submit GCash Proof</button>
                        </div>

                        {{-- Bank Transfer section --}}
                        <div id="pmBankSection" class="tf-pm-fields hidden">
                            <div style="background:#eff6ff;border:1.5px solid #93c5fd;border-radius:14px;padding:16px 18px;margin-bottom:14px;text-align:center;">
                                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#1d4ed8;margin-bottom:10px;">Transfer to Bank Account</div>
                                <div style="font-size:14px;font-weight:700;color:#1e3a8a;margin-bottom:4px;">{{ $bankName ?? 'BDO Unibank' }}</div>
                                <div style="font-size:12px;color:#3730a3;margin-bottom:6px;">{{ $bankAccountName ?? 'Jarz Towing Services' }}</div>
                                <div style="font-size:26px;font-weight:800;color:#09090b;letter-spacing:2px;">{{ $bankAccountNumber ?? '0012-3456-7890' }}</div>
                                <div style="font-size:12px;color:#52525b;margin-top:6px;">Ask the customer to transfer the exact amount to this account</div>
                            </div>
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#52525b;margin-bottom:8px;">Upload Bank Transfer Receipt</div>
                            <label style="display:block;border:2px dashed #e4e4e7;border-radius:12px;padding:20px;text-align:center;cursor:pointer;">
                                <input type="file" id="bankProofInput" accept="image/*" capture="environment" style="display:none;">
                                <div id="bankProofPlaceholder">
                                    <div style="font-size:24px;margin-bottom:6px;">🏦</div>
                                    <div style="font-size:13px;font-weight:600;color:#18181b;">Take or choose a photo of the receipt</div>
                                    <div style="font-size:11px;color:#a1a1aa;margin-top:2px;">JPG, PNG or WebP · max 5 MB</div>
                                </div>
                                <img id="bankProofPreview" src="" alt="" style="display:none;width:100%;max-height:180px;object-fit:contain;border-radius:8px;">
                            </label>
                            <p id="bankProofError" style="font-size:13px;color:#dc2626;text-align:center;min-height:18px;margin:8px 0 4px;"></p>
                            <button type="button" class="tf-btn tf-btn--primary" id="bankSubmitBtn">Submit Bank Transfer Proof</button>
                        </div>

                        {{-- Cash section --}}
                        <div id="pmCashSection" class="tf-pm-fields hidden">
                            <div style="background:#faf5ff;border:1.5px solid #c4b5fd;border-radius:14px;padding:18px;margin-bottom:14px;text-align:center;">
                                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#7c3aed;margin-bottom:8px;">Collect Cash Payment</div>
                                <div style="font-size:30px;font-weight:900;color:#09090b;margin-bottom:6px;">₱{{ number_format($finalTotal, 2) }}</div>
                                <div style="font-size:12px;color:#52525b;">Count the bills and confirm the customer paid the exact amount.</div>
                            </div>
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#52525b;margin-bottom:8px;">Upload Receipt / Photo (Optional)</div>
                            <label style="display:block;border:2px dashed #e4e4e7;border-radius:12px;padding:20px;text-align:center;cursor:pointer;margin-bottom:12px;">
                                <input type="file" id="cashProofInput" accept="image/*" capture="environment" style="display:none;">
                                <div id="cashProofPlaceholder">
                                    <div style="font-size:24px;margin-bottom:6px;">💵</div>
                                    <div style="font-size:13px;font-weight:600;color:#18181b;">Optional: take a photo as record</div>
                                    <div style="font-size:11px;color:#a1a1aa;margin-top:2px;">JPG, PNG or WebP · max 5 MB</div>
                                </div>
                                <img id="cashProofPreview" src="" alt="" style="display:none;width:100%;max-height:160px;object-fit:contain;border-radius:8px;">
                            </label>
                            <button type="button" class="tf-btn tf-btn--success" id="cashConfirmBtn">Confirm Cash Received</button>
                        </div>

                        @if(!empty($allowCheque))
                        {{-- Cheque section (special cases only) --}}
                        <div id="pmChequeSection" class="tf-pm-fields hidden">
                            <div style="background:#fefce8;border:1.5px solid #fde047;border-radius:14px;padding:16px 18px;margin-bottom:14px;text-align:center;">
                                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#854d0e;margin-bottom:6px;">Cheque Payment</div>
                                <div style="font-size:13px;color:#713f12;">Verify the cheque is made out to <strong>{{ $bankAccountName ?? 'Jarz Towing Services' }}</strong> for the correct amount. Take a clear photo of both sides.</div>
                            </div>
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#52525b;margin-bottom:8px;">Upload Cheque Photo</div>
                            <label style="display:block;border:2px dashed #e4e4e7;border-radius:12px;padding:20px;text-align:center;cursor:pointer;">
                                <input type="file" id="chequeProofInput" accept="image/*" capture="environment" style="display:none;">
                                <div id="chequeProofPlaceholder">
                                    <div style="font-size:24px;margin-bottom:6px;">🗒️</div>
                                    <div style="font-size:13px;font-weight:600;color:#18181b;">Take or choose a photo of the cheque</div>
                                    <div style="font-size:11px;color:#a1a1aa;margin-top:2px;">JPG, PNG or WebP · max 5 MB</div>
                                </div>
                                <img id="chequeProofPreview" src="" alt="" style="display:none;width:100%;max-height:180px;object-fit:contain;border-radius:8px;">
                            </label>
                            <p id="chequeProofError" style="font-size:13px;color:#dc2626;text-align:center;min-height:18px;margin:8px 0 4px;"></p>
                            <button type="button" class="tf-btn tf-btn--primary" id="chequeSubmitBtn">Submit Cheque</button>
                        </div>
                        @endif

                    </div>{{-- /pmMethodArea --}}

                    {{-- Submitted state (shown after proof is uploaded) --}}
                    <div id="paymentSubmittedCard" class="{{ $currentStatus === 'payment_submitted' ? '' : 'hidden' }}">
                        <div class="tf-submitted-card">
                            <div class="tf-submitted-icon">✓</div>
                            <h3>Proof Submitted</h3>
                            <p>The dispatcher will review and confirm your payment shortly. This page updates automatically.</p>
                        </div>
                    </div>

                </div>
            </div>{{-- /paymongoArea --}}

            <p class="tf-feedback" id="focusFeedback">Use the buttons above to keep this job moving.</p>

        </div>{{-- /tf-action-area --}}

    </div>{{-- /tf-page --}}

    {{-- ── Return to Dispatch modal ── --}}
    <div class="tl-dialog-backdrop hidden" id="returnTaskModal" aria-hidden="true">
        <div class="tl-dialog-card">
            <p class="tl-eyebrow">Return to Dispatch</p>
            <h3>Why are you returning this booking?</h3>
            <p>Dispatch will be notified right away so the task can be reassigned quickly.</p>

            <label for="returnReasonPreset">Reason</label>
            <select id="returnReasonPreset">
                <option value="">Select a reason</option>
            </select>
            <small id="returnReasonDescription" style="display:block; margin-top:4px; color:#64748b;"></small>

            <label for="returnReasonNote" style="display:block; margin-top:12px;">
                Additional Details <span id="returnReasonNoteRequired" style="color:#ef4444; display:none;">*</span>
            </label>
            <textarea id="returnReasonNote" placeholder="Add a short explanation so dispatch can act faster..."></textarea>
            <small id="returnReasonNoteHint" style="display:block; margin-top:4px; color:#64748b;"></small>

            <p class="tl-focus-feedback is-error hidden" id="returnReasonError"></p>

            <div class="tl-dialog-actions">
                <button type="button" class="tf-btn tf-btn--ghost" id="cancelReturnBtn">Cancel</button>
                <button type="button" class="tf-btn tf-btn--primary" id="confirmReturnBtn">Confirm Return</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ $assetBase }}/js/task-focus.js?v={{ time() }}"></script>
@endpush
