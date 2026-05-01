@extends('customer.layouts.app')

@section('title', 'Book a Tow')

@section('content')

    @php
        $user = auth()->user();
        $customer =
            $user?->customer ??
            \App\Models\Customer::where('user_id', $user?->id)->orWhere('email', $user?->email)->first();

        $nameParts = split_full_name($customer?->full_name ?? ($user?->name ?? ''));
        $prefillFirst = old('first_name', $nameParts['first_name'] ?? '');
        $prefillMiddle = old('middle_name', $nameParts['middle_name'] ?? '');
        $prefillLast = old('last_name', $nameParts['last_name'] ?? '');
        $prefillPhone = old('phone', $customer?->phone ?? '');
        $prefillEmail = old('email', $customer?->email ?? ($user?->email ?? ''));
    @endphp

    <style>
        /* ── wrapper ── */
        .bk-wrap {
            max-width: 760px;
            margin: 0 auto;
            padding: 0 0 80px;
        }

        /* ── page heading ── */
        .bk-page-heading {
            margin-bottom: 28px;
        }

        .bk-page-heading h1 {
            font-size: 24px;
            font-weight: 800;
            color: #09090b;
            margin: 0 0 4px;
        }

        .bk-page-heading p {
            font-size: 13px;
            color: #71717a;
            margin: 0;
        }

        /* ── section cards ── */
        .bk-section {
            background: #fff;
            border: 1px solid #e4e4e7;
            border-radius: 18px;
            padding: 22px 24px;
            margin-bottom: 14px;
        }

        .bk-section-label {
            font-size: 10px;
            font-weight: 800;
            color: #a1a1aa;
            text-transform: uppercase;
            letter-spacing: .1em;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f4f4f5;
        }

        /* ── form layout ── */
        .bk-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        .bk-row.three {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .bk-row.four {
            grid-template-columns: 1fr 1fr 1fr 1fr;
        }

        .bk-row.full {
            grid-template-columns: 1fr;
        }

        @media (max-width: 580px) {

            .bk-row,
            .bk-row.three,
            .bk-row.four {
                grid-template-columns: 1fr;
            }
        }

        .bk-row:last-child {
            margin-bottom: 0;
        }

        .bk-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .bk-field label {
            font-size: 12px;
            font-weight: 700;
            color: #3f3f46;
            letter-spacing: .01em;
        }

        .bk-field label .req {
            color: #ef4444;
            margin-left: 2px;
        }

        .bk-field input,
        .bk-field select,
        .bk-field textarea {
            width: 100%;
            border: 1px solid #d4d4d8;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            color: #09090b;
            background: #fff;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
            box-sizing: border-box;
        }

        .bk-field input:focus,
        .bk-field select:focus,
        .bk-field textarea:focus {
            border-color: #a1a1aa;
            box-shadow: 0 0 0 3px rgba(161, 161, 170, .14);
        }

        .bk-field textarea {
            resize: vertical;
            min-height: 80px;
        }

        .bk-field .bk-error {
            font-size: 12px;
            color: #ef4444;
            font-weight: 500;
        }

        .bk-field input.is-error,
        .bk-field select.is-error {
            border-color: #ef4444;
        }

        /* ── map ── */
        .bk-map-box {
            border: 1px solid #e4e4e7;
            border-radius: 14px;
            overflow: hidden;
            height: 280px;
            background: #f8fafc;
            margin-bottom: 14px;
        }

        #bkMap {
            width: 100%;
            height: 100%;
        }

        /* autocomplete suggestions */
        .bk-suggestions {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #e4e4e7;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(9, 9, 11, .08);
            z-index: 9999;
            max-height: 200px;
            overflow-y: auto;
        }

        .bk-suggestions button {
            display: block;
            width: 100%;
            text-align: left;
            padding: 10px 14px;
            border: 0;
            background: transparent;
            font-size: 13px;
            color: #3f3f46;
            cursor: pointer;
            line-height: 1.4;
        }

        .bk-suggestions button:hover {
            background: #f4f4f5;
        }

        .bk-input-wrap {
            position: relative;
        }

        /* ── vehicle category cards ── */
        .bk-cat-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-bottom: 0;
        }

        @media (max-width: 580px) {
            .bk-cat-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .bk-cat-card {
            border: 2px solid #e4e4e7;
            border-radius: 12px;
            padding: 10px 6px;
            text-align: center;
            cursor: pointer;
            transition: border-color .15s, background .15s;
            user-select: none;
        }

        .bk-cat-card:hover {
            border-color: #a1a1aa;
        }

        .bk-cat-card.selected {
            border-color: #09090b;
            background: #09090b;
            color: #fff;
        }

        .bk-cat-card .bk-cat-icon {
            font-size: 20px;
            margin-bottom: 4px;
        }

        .bk-cat-card .bk-cat-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .03em;
        }

        /* ── truck type cards ── */
        .bk-truck-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }

        .bk-truck-card {
            border: 2px solid #e4e4e7;
            border-radius: 14px;
            padding: 16px;
            cursor: pointer;
            transition: border-color .15s, box-shadow .15s;
            position: relative;
        }

        .bk-truck-card:hover:not(.unavailable) {
            border-color: #a1a1aa;
            box-shadow: 0 4px 16px rgba(9, 9, 11, .06);
        }

        .bk-truck-card.selected {
            border-color: #09090b;
            box-shadow: 0 4px 16px rgba(9, 9, 11, .1);
        }

        .bk-truck-card.unavailable {
            opacity: .5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .bk-truck-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 999px;
            margin-bottom: 10px;
        }

        .bk-truck-badge.light {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .bk-truck-badge.medium {
            background: #faf5ff;
            color: #7c3aed;
        }

        .bk-truck-badge.heavy {
            background: #fff7ed;
            color: #c2410c;
        }

        .bk-truck-badge.other {
            background: #f4f4f5;
            color: #52525b;
        }

        .bk-truck-name {
            font-size: 15px;
            font-weight: 800;
            color: #09090b;
            margin-bottom: 6px;
            line-height: 1.3;
        }

        .bk-truck-avail {
            font-size: 11px;
            color: #71717a;
            margin-bottom: 10px;
        }

        .bk-truck-avail span {
            font-weight: 700;
            color: #3f3f46;
        }

        .bk-truck-rate-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .bk-truck-rate-chip {
            font-size: 10px;
            font-weight: 700;
            background: #f4f4f5;
            color: #52525b;
            padding: 3px 8px;
            border-radius: 999px;
        }

        .bk-truck-estimate {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #f4f4f5;
            font-size: 13px;
            font-weight: 700;
            color: #09090b;
        }

        .bk-truck-estimate .est-note {
            font-size: 10px;
            font-weight: 500;
            color: #a1a1aa;
            margin-top: 2px;
        }

        .bk-truck-check {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #09090b;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .bk-truck-check svg {
            width: 10px;
            height: 10px;
            stroke: #fff;
            stroke-width: 3;
        }

        .bk-truck-card.selected .bk-truck-check {
            display: flex;
        }

        /* ── price estimate banner ── */
        .bk-price-banner {
            background: #09090b;
            border-radius: 14px;
            padding: 18px 22px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .bk-price-banner.hidden {
            display: none;
        }

        .bk-price-left {
            flex: 1;
            min-width: 0;
        }

        .bk-price-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #71717a;
            margin-bottom: 4px;
        }

        .bk-price-value {
            font-size: 28px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 4px;
        }

        .bk-price-breakdown {
            font-size: 11px;
            color: #a1a1aa;
        }

        .bk-price-note {
            font-size: 11px;
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 8px;
            padding: 8px 12px;
            color: #d4d4d8;
            max-width: 240px;
            line-height: 1.45;
        }

        /* ── submit area ── */
        .bk-submit-row {
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .bk-btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f4f4f5;
            color: #3f3f46;
            font-size: 14px;
            font-weight: 700;
            padding: 12px 20px;
            border-radius: 12px;
            border: 0;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }

        .bk-btn-back:hover {
            background: #e4e4e7;
        }

        .bk-btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #09090b;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            padding: 12px 24px;
            border-radius: 12px;
            border: 0;
            cursor: pointer;
            transition: background .15s;
        }

        .bk-btn-submit:hover {
            background: #27272a;
        }

        .bk-btn-submit:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .bk-btn-submit svg {
            width: 16px;
            height: 16px;
            stroke: #fff;
            stroke-width: 2.5;
        }

        /* status badge */
        .bk-pending-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff7ed;
            color: #c2410c;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            margin-top: 4px;
        }

        /* ── truck class inline selector ── */
        .bk-class-inline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .bk-class-inline>label {
            font-size: 12px;
            font-weight: 700;
            color: #3f3f46;
        }

        .bk-class-select {
            font-size: 13px;
            font-weight: 600;
            border: 1.5px solid #d4d4d8;
            border-radius: 8px;
            padding: 6px 10px;
            color: #09090b;
            background: #fff;
            cursor: pointer;
            min-width: 176px;
        }

        .bk-class-select:focus {
            border-color: #a1a1aa;
            outline: none;
            box-shadow: 0 0 0 3px rgba(161, 161, 170, .14);
        }

        .bk-class-select option:disabled {
            color: #a1a1aa;
        }

        .bk-truck-card.hidden-by-class {
            display: none !important;
        }

        /* ── truck class picker cards ── */
        .bk-class-card-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        @media (max-width: 600px) {
            .bk-class-card-grid {
                grid-template-columns: 1fr;
            }
        }

        .bk-class-card {
            border: 2px solid #e4e4e7;
            border-radius: 16px;
            padding: 20px 16px 16px;
            cursor: pointer;
            text-align: center;
            position: relative;
            transition: border-color .15s, box-shadow .15s, background .15s;
            user-select: none;
        }

        .bk-class-card:hover:not(.unavailable) {
            border-color: #a1a1aa;
            box-shadow: 0 4px 16px rgba(9, 9, 11, .06);
        }

        .bk-class-card.selected {
            border-color: #09090b;
            background: #09090b;
            color: #fff;
            box-shadow: 0 4px 20px rgba(9, 9, 11, .14);
        }

        .bk-class-card.unavailable {
            opacity: .42;
            cursor: not-allowed;
            pointer-events: none;
        }

        .bk-class-card-check {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #fff;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .bk-class-card-check svg {
            width: 10px;
            height: 10px;
            stroke: #09090b;
            stroke-width: 3;
        }

        .bk-class-card.selected .bk-class-card-check {
            display: flex;
        }

        .bk-class-card-icon {
            font-size: 30px;
            line-height: 1;
            margin-bottom: 8px;
        }

        .bk-class-card-name {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 3px;
        }

        .bk-class-card-desc {
            font-size: 11px;
            color: #71717a;
            margin-bottom: 10px;
            line-height: 1.4;
        }

        .bk-class-card.selected .bk-class-card-desc {
            color: #a1a1aa;
        }

        .bk-class-card-avail {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 600;
            color: #52525b;
            margin-bottom: 8px;
        }

        .bk-class-card.selected .bk-class-card-avail {
            color: #d4d4d8;
        }

        .avail-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .avail-dot.avail {
            background: #22c55e;
        }

        .avail-dot.unavail {
            background: #a1a1aa;
        }

        .bk-class-card-rate {
            font-size: 11px;
            font-weight: 700;
            color: #3f3f46;
            background: #f4f4f5;
            border-radius: 6px;
            padding: 3px 8px;
            display: inline-block;
            margin-bottom: 8px;
        }

        .bk-class-card.selected .bk-class-card-rate {
            background: rgba(255, 255, 255, .12);
            color: #e4e4e7;
        }

        .bk-class-card-est {
            font-size: 14px;
            font-weight: 800;
            color: #09090b;
            margin-top: 6px;
        }

        .bk-class-card.selected .bk-class-card-est {
            color: #fff;
        }

        .bk-class-card-est .est-lbl {
            display: block;
            font-size: 10px;
            font-weight: 500;
            color: #a1a1aa;
            margin-top: 2px;
        }

        .bk-class-card.selected .bk-class-card-est .est-lbl {
            color: #71717a;
        }
    </style>

    <div class="bk-wrap">

        {{-- Page heading --}}
        <div class="bk-page-heading">
            <h1>Book a Tow</h1>
            <p>Fill in your trip details and submit — your booking status will be <strong>Pending Review</strong> until a
                dispatcher confirms.</p>
        </div>

        @if ($errors->any())
            <div class="bk-section" style="border-color:#fca5a5;background:#fef2f2;margin-bottom:14px;">
                <div class="bk-section-label" style="border-color:#fecaca;color:#ef4444;">Please fix the errors below</div>
                <ul style="margin:0;padding:0 0 0 16px;font-size:13px;color:#b91c1c;line-height:1.8;">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="bkForm" action="{{ route('customer.book.store') }}" method="POST" enctype="multipart/form-data"
            novalidate>
            @csrf

            {{-- ── SECTION 1: Customer Details ── --}}
            <div class="bk-section">
                <div class="bk-section-label">1 · Your Details</div>

                <div class="bk-row three">
                    <div class="bk-field">
                        <label for="bk_first_name">First Name <span class="req">*</span></label>
                        <input type="text" id="bk_first_name" name="first_name" value="{{ $prefillFirst }}" required
                            maxlength="100" class="{{ $errors->has('first_name') ? 'is-error' : '' }}">
                        @error('first_name')
                            <span class="bk-error">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="bk-field">
                        <label for="bk_middle_name">Middle Name</label>
                        <input type="text" id="bk_middle_name" name="middle_name" value="{{ $prefillMiddle }}"
                            maxlength="100">
                    </div>
                    <div class="bk-field">
                        <label for="bk_last_name">Last Name <span class="req">*</span></label>
                        <input type="text" id="bk_last_name" name="last_name" value="{{ $prefillLast }}" required
                            maxlength="100" class="{{ $errors->has('last_name') ? 'is-error' : '' }}">
                        @error('last_name')
                            <span class="bk-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="bk-row">
                    <div class="bk-field">
                        <label for="bk_phone">Phone Number <span class="req">*</span></label>
                        <input type="tel" id="bk_phone" name="phone" value="{{ $prefillPhone }}"
                            placeholder="09XXXXXXXXX" required class="{{ $errors->has('phone') ? 'is-error' : '' }}">
                        @error('phone')
                            <span class="bk-error">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="bk-field">
                        <label for="bk_email">Email Address</label>
                        <input type="email" id="bk_email" name="email" value="{{ $prefillEmail }}"
                            placeholder="you@example.com" class="{{ $errors->has('email') ? 'is-error' : '' }}">
                        @error('email')
                            <span class="bk-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                {{-- Booking Mode --}}
                <div style="margin-bottom:14px;">
                    <div class="bk-field">
                        <label for="bk_service_type">Booking Mode <span class="req">*</span></label>
                        <select id="bk_service_type" name="service_type" required style="max-width:240px;"
                            class="{{ $errors->has('service_type') ? 'is-error' : '' }}">
                            <option value="book_now"
                                {{ old('service_type', 'book_now') !== 'schedule' ? 'selected' : '' }}>Book Now</option>
                            <option value="schedule" {{ old('service_type') === 'schedule' ? 'selected' : '' }}>Schedule
                                Later</option>
                        </select>
                        @error('service_type')
                            <span class="bk-error">{{ $message }}</span>
                        @enderror
                    </div>
                    <div id="bk_sched_wrap"
                        style="margin-top:12px; display:{{ old('service_type') === 'schedule' ? 'block' : 'none' }};">
                        <div class="bk-row">
                            <div class="bk-field">
                                <label for="bk_sched_date">Preferred Date <span class="req">*</span></label>
                                <input type="date" id="bk_sched_date" name="scheduled_date"
                                    min="{{ now()->toDateString() }}" value="{{ old('scheduled_date') }}"
                                    class="{{ $errors->has('scheduled_date') ? 'is-error' : '' }}">
                                @error('scheduled_date')
                                    <span class="bk-error">{{ $message }}</span>
                                @enderror
                            </div>
                            <div class="bk-field">
                                <label for="bk_sched_time">Preferred Time <span class="req">*</span></label>
                                <input type="time" id="bk_sched_time" name="scheduled_time"
                                    value="{{ old('scheduled_time') }}"
                                    class="{{ $errors->has('scheduled_time') ? 'is-error' : '' }}">
                                @error('scheduled_time')
                                    <span class="bk-error">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Hidden fields --}}
                <input type="hidden" name="confirmation_type" value="system">
                <input type="hidden" name="schedule_fallback_accepted" value="0">
            </div>

            {{-- ── SECTION 2: Trip Location ── --}}
            <div class="bk-section">
                <div class="bk-section-label">2 · Trip Location</div>

                {{-- Map preview --}}
                <div class="bk-map-box">
                    <div id="bkMap"></div>
                </div>

                <div class="bk-row">
                    <div class="bk-field">
                        <label for="bk_pickup">Pickup Location <span class="req">*</span></label>
                        <div class="bk-input-wrap">
                            <input type="text" id="bk_pickup" name="pickup_address"
                                value="{{ old('pickup_address') }}" placeholder="Where should we pick you up?" required
                                autocomplete="off" class="{{ $errors->has('pickup_address') ? 'is-error' : '' }}">
                            <div id="bkPickupSugg" class="bk-suggestions" style="display:none;"></div>
                        </div>
                        <input type="hidden" id="bk_pickup_lat" name="pickup_lat" value="{{ old('pickup_lat') }}">
                        <input type="hidden" id="bk_pickup_lng" name="pickup_lng" value="{{ old('pickup_lng') }}">
                        <input type="hidden" id="bk_pickup_confirmed" name="pickup_confirmed"
                            value="{{ old('pickup_confirmed', 0) }}">
                        @error('pickup_address')
                            <span class="bk-error">{{ $message }}</span>
                        @enderror
                        @error('pickup_lat')
                            <span class="bk-error">Pin the pickup location on the map.</span>
                        @enderror
                    </div>
                    <div class="bk-field">
                        <label for="bk_dropoff">Drop-off Location <span class="req">*</span></label>
                        <div class="bk-input-wrap">
                            <input type="text" id="bk_dropoff" name="dropoff_address"
                                value="{{ old('dropoff_address') }}" placeholder="Where are you headed?" required
                                autocomplete="off" class="{{ $errors->has('dropoff_address') ? 'is-error' : '' }}">
                            <div id="bkDropoffSugg" class="bk-suggestions" style="display:none;"></div>
                        </div>
                        <input type="hidden" id="bk_drop_lat" name="drop_lat" value="{{ old('drop_lat') }}">
                        <input type="hidden" id="bk_drop_lng" name="drop_lng" value="{{ old('drop_lng') }}">
                        <input type="hidden" id="bk_dropoff_confirmed" name="dropoff_confirmed"
                            value="{{ old('dropoff_confirmed', 0) }}">
                        @error('dropoff_address')
                            <span class="bk-error">{{ $message }}</span>
                        @enderror
                        @error('drop_lat')
                            <span class="bk-error">Pin the drop-off location on the map.</span>
                        @enderror
                    </div>
                </div>

                <div class="bk-row full">
                    <div class="bk-field">
                        <label for="bk_pickup_notes">Pickup Notes / Landmark</label>
                        <input type="text" id="bk_pickup_notes" name="pickup_notes"
                            value="{{ old('pickup_notes') }}" placeholder="e.g., Near 7-Eleven on Main St, Gate 2">
                    </div>
                </div>

                {{-- Hidden distance / price holders --}}
                <input type="hidden" id="bk_distance_km" name="distance_km" value="{{ old('distance_km') }}">
                <input type="hidden" id="bk_price_input" name="price" value="{{ old('price') }}">
                <input type="hidden" id="bk_eta_minutes" name="eta_minutes" value="">
                <input type="hidden" id="bk_additional_fee" name="additional_fee" value="0">
            </div>

            {{-- ── SECTION 3: Your Vehicle ── --}}
            <div class="bk-section">
                <div class="bk-section-label">3 · Your Vehicle</div>

                {{-- Category picker --}}
                <div class="bk-field" style="margin-bottom:14px;">
                    <label style="margin-bottom:10px;display:block;">Vehicle Category <span
                            class="req">*</span></label>
                    <input type="hidden" id="bk_vehicle_category" name="vehicle_category"
                        value="{{ old('vehicle_category', '4_wheeler') }}">
                    <div class="bk-cat-grid" id="bkCatGrid">
                        @php
                            $cats = [
                                ['value' => '2_wheeler', 'icon' => '🏍️', 'label' => 'Motorcycle'],
                                ['value' => '3_wheeler', 'icon' => '🛺', 'label' => 'Tricycle'],
                                ['value' => '4_wheeler', 'icon' => '🚗', 'label' => '4-Wheel'],
                                ['value' => 'heavy_vehicle', 'icon' => '🚛', 'label' => 'Heavy'],
                                ['value' => 'other', 'icon' => '🚘', 'label' => 'Other'],
                            ];
                            $selectedCat = old('vehicle_category', '4_wheeler');
                        @endphp
                        @foreach ($cats as $cat)
                            <div class="bk-cat-card {{ $selectedCat === $cat['value'] ? 'selected' : '' }}"
                                data-cat="{{ $cat['value'] }}" role="button" tabindex="0"
                                aria-pressed="{{ $selectedCat === $cat['value'] ? 'true' : 'false' }}">
                                <div class="bk-cat-icon">{{ $cat['icon'] }}</div>
                                <div class="bk-cat-label">{{ $cat['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                    @error('vehicle_category')
                        <span class="bk-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="bk-row">
                    <div class="bk-field">
                        <label for="bk_make">Make</label>
                        <input type="text" id="bk_make" name="vehicle_make" value="{{ old('vehicle_make') }}"
                            placeholder="e.g., Toyota">
                    </div>
                    <div class="bk-field">
                        <label for="bk_model">Model</label>
                        <input type="text" id="bk_model" name="vehicle_model" value="{{ old('vehicle_model') }}"
                            placeholder="e.g., Fortuner">
                    </div>
                </div>

                <div class="bk-row three">
                    <div class="bk-field">
                        <label for="bk_year">Year</label>
                        <input type="number" id="bk_year" name="vehicle_year" value="{{ old('vehicle_year') }}"
                            placeholder="{{ date('Y') }}" min="1900" max="{{ date('Y') + 1 }}">
                    </div>
                    <div class="bk-field">
                        <label for="bk_color">Color</label>
                        <input type="text" id="bk_color" name="vehicle_color" value="{{ old('vehicle_color') }}"
                            placeholder="e.g., White">
                    </div>
                    <div class="bk-field">
                        <label for="bk_plate">Plate Number</label>
                        <input type="text" id="bk_plate" name="vehicle_plate_number"
                            value="{{ old('vehicle_plate_number') }}" placeholder="ABC 1234" maxlength="20">
                    </div>
                </div>

                {{-- Vehicle images --}}
                <div class="bk-field" style="margin-bottom:0;">
                    <label>Vehicle Photos</label>
                    <small style="font-size:11px;color:#a1a1aa;margin-bottom:8px;display:block;">
                        Optional — include your plate number if possible. Max 5 photos (JPG/PNG, 2 MB each).
                    </small>
                    <div id="bk_dropzone"
                        style="border:2px dashed #d4d4d8;border-radius:10px;padding:28px 20px;text-align:center;cursor:pointer;background:#fafafa;transition:.2s;">
                        <div style="font-size:11px;color:#71717a;">
                            Drag &amp; drop or <span
                                style="color:#09090b;font-weight:700;text-decoration:underline;">browse</span> to upload
                        </div>
                    </div>
                    <div id="bk_img_counter"
                        style="display:none;font-size:12px;color:#3f3f46;margin-top:6px;font-weight:600;">
                        <span id="bk_img_count">0</span> of 5 selected
                        <button type="button" id="bk_img_clear"
                            style="margin-left:8px;font-size:11px;color:#71717a;background:none;border:none;cursor:pointer;">Clear</button>
                    </div>
                    <input type="file" id="bk_images_input" name="vehicle_images[]" accept=".jpg,.jpeg,.png" multiple
                        style="display:none;">
                    <div id="bk_img_preview" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;"></div>
                    @error('vehicle_images')
                        <span class="bk-error">{{ $message }}</span>
                    @enderror
                    @foreach ($errors->get('vehicle_images.*') as $msg)
                        <span class="bk-error">{{ $msg }}</span>
                    @endforeach
                </div>
            </div>

            {{-- ── SECTION 4: Truck Class ── --}}
            <div class="bk-section">
                <div class="bk-section-label">4 · Truck Class</div>

                <input type="hidden" id="bk_truck_type_id" name="truck_type_id" value="{{ old('truck_type_id') }}">
                <input type="hidden" id="bk_truck_class_hidden" name="truck_class" value="{{ old('truck_class') }}">
                @error('truck_type_id')
                    <span class="bk-error" style="display:block;margin-bottom:12px;">{{ $message }}</span>
                @enderror

                @php
                    $classConfig = [
                        'light' => ['label' => 'Light Duty', 'icon' => '🚗', 'desc' => 'Cars, SUVs, small vehicles'],
                        'medium' => ['label' => 'Medium Duty', 'icon' => '🚐', 'desc' => 'Vans, pickups, mid-size'],
                        'heavy' => ['label' => 'Heavy Duty', 'icon' => '🚛', 'desc' => 'Trucks, buses, heavy loads'],
                    ];
                    $selectedClass = old('truck_class', '');
                @endphp

                <div class="bk-class-card-grid" id="bkClassGrid">
                    @foreach ($classConfig as $cls => $cfg)
                        @php
                            $info = $classData[$cls] ?? [
                                'available_units' => 0,
                                'base_rate' => 0,
                                'per_km_rate' => 0,
                                'truck_type_id' => null,
                            ];
                            $isAvail = $info['available_units'] > 0;
                        @endphp
                        <div class="bk-class-card {{ $selectedClass === $cls ? 'selected' : '' }} {{ !$isAvail ? 'unavailable' : '' }}"
                            data-class="{{ $cls }}" data-truck-id="{{ $info['truck_type_id'] }}"
                            data-base="{{ (float) $info['base_rate'] }}" data-perkm="{{ (float) $info['per_km_rate'] }}"
                            data-available="{{ $isAvail ? 1 : 0 }}" role="button" tabindex="{{ $isAvail ? 0 : -1 }}"
                            aria-disabled="{{ !$isAvail ? 'true' : 'false' }}">

                            <div class="bk-class-card-check">
                                <svg viewBox="0 0 12 12" fill="none">
                                    <polyline points="2 6 5 9 10 3" />
                                </svg>
                            </div>

                            <div class="bk-class-card-icon">{{ $cfg['icon'] }}</div>
                            <div class="bk-class-card-name">{{ $cfg['label'] }}</div>
                            <div class="bk-class-card-desc">{{ $cfg['desc'] }}</div>

                            <div class="bk-class-card-avail">
                                @if ($isAvail)
                                    <span class="avail-dot avail"></span>
                                    <span>{{ $info['available_units'] }}
                                        unit{{ $info['available_units'] !== 1 ? 's' : '' }} available</span>
                                @else
                                    <span class="avail-dot unavail"></span>
                                    <span>No units available</span>
                                @endif
                            </div>

                            @if ($info['base_rate'] > 0)
                                <div class="bk-class-card-rate">
                                    ₱{{ number_format($info['base_rate'], 0) }} base ·
                                    +₱{{ number_format($info['per_km_rate'], 0) }}/km
                                </div>
                            @endif

                            <div class="bk-class-card-est" id="bk_class_est_{{ $cls }}">
                                <span class="est-val">—</span>
                                <span class="est-lbl">estimated total</span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div id="bk_all_unavailable_msg"
                    style="display:none;text-align:center;padding:16px;color:#71717a;font-size:13px;margin-top:12px;">
                    No truck classes available right now. Switch to <strong>Schedule Later</strong> to reserve.
                </div>
            </div>

            {{-- ── Estimated Price Banner ── --}}
            <div class="bk-price-banner hidden" id="bkPriceBanner">
                <div class="bk-price-left">
                    <div class="bk-price-label">Estimated Total</div>
                    <div class="bk-price-value" id="bkPriceValue">₱0</div>
                    <div class="bk-price-breakdown" id="bkPriceBreakdown"></div>
                </div>
                <div class="bk-price-note">
                    ⚠️ This is an estimate only. The final fare will be confirmed by the dispatcher after reviewing your
                    booking.
                </div>
            </div>

            {{-- ── Notes ── --}}
            <div class="bk-section">
                <div class="bk-section-label">Additional Notes</div>
                <div class="bk-field">
                    <label for="bk_notes">Special instructions (optional)</label>
                    <textarea id="bk_notes" name="notes" rows="3"
                        placeholder="Anything the driver or dispatcher should know...">{{ old('notes') }}</textarea>
                </div>
            </div>

            {{-- ── Submit ── --}}
            <div class="bk-submit-row">
                <a href="{{ route('customer.dashboard') }}" class="bk-btn-back">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"
                        viewBox="0 0 24 24">
                        <polyline points="15 18 9 12 15 6" />
                    </svg>
                    Cancel
                </a>
                <button type="submit" id="bkSubmitBtn" class="bk-btn-submit">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round"
                            stroke-linejoin="round" />
                    </svg>
                    <span>Submit Booking</span>
                </button>
            </div>

            <div style="text-align:right;margin-top:8px;">
                <span class="bk-pending-pill">
                    <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5"
                        viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M12 6v6l4 2" />
                    </svg>
                    After submitting: Status will be <strong>Pending Review</strong>
                </span>
            </div>

        </form>
    </div>

    @push('scripts')
        {{-- Leaflet --}}
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

        <script>
            (function() {
                'use strict';

                // ── Config ──────────────────────────────────────────────────────
                const GEO = {
                    search: @json(route('geo.search')),
                    reverse: @json(route('geo.reverse')),
                    route: @json(route('geo.route')),
                    pricing: @json(route('geo.pricing.preview')),
                    csrf: @json(csrf_token()),
                };

                const classRates = @json($classData);

                // ── State ────────────────────────────────────────────────────────
                let map, pickupMarker, dropoffMarker, routeLayer = null;
                let pickupCoords = null,
                    dropoffCoords = null;
                let currentDistanceKm = 0,
                    currentEtaMinutes = 0;
                let selectedTruckId = null;
                let selectedTruckClass = '';
                let isScheduleMode = (document.getElementById('bk_service_type')?.value ?? 'book_now') === 'schedule';
                let searchTimers = {};

                // ── Map Init ─────────────────────────────────────────────────────
                function initMap() {
                    map = L.map('bkMap', {
                        zoomControl: true
                    }).setView([14.5995, 120.9842], 11);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors',
                        maxZoom: 19,
                    }).addTo(map);

                    const pickupIcon = makeIcon('#22c55e');
                    const dropoffIcon = makeIcon('#3b82f6');

                    pickupMarker = L.marker([14.5995, 120.9842], {
                        icon: pickupIcon,
                        draggable: true,
                        opacity: 0
                    }).addTo(map);
                    dropoffMarker = L.marker([14.5995, 120.9842], {
                        icon: dropoffIcon,
                        draggable: true,
                        opacity: 0
                    }).addTo(map);

                    pickupMarker.on('dragend', () => reverseGeocodeMarker(pickupMarker, 'pickup'));
                    dropoffMarker.on('dragend', () => reverseGeocodeMarker(dropoffMarker, 'dropoff'));

                    // Restore from old() values
                    const pickLat = parseFloat(document.getElementById('bk_pickup_lat').value);
                    const pickLng = parseFloat(document.getElementById('bk_pickup_lng').value);
                    const dropLat = parseFloat(document.getElementById('bk_drop_lat').value);
                    const dropLng = parseFloat(document.getElementById('bk_drop_lng').value);

                    if (!isNaN(pickLat) && !isNaN(pickLng)) placeMarker('pickup', pickLat, pickLng, false);
                    if (!isNaN(dropLat) && !isNaN(dropLng)) placeMarker('dropoff', dropLat, dropLng, false);
                    if (pickupCoords && dropoffCoords) fetchRoute();
                }

                function makeIcon(color) {
                    return L.divIcon({
                        className: '',
                        html: `<svg width="24" height="32" viewBox="0 0 24 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                     <path d="M12 0C5.373 0 0 5.373 0 12c0 9 12 20 12 20S24 21 24 12C24 5.373 18.627 0 12 0z" fill="${color}"/>
                     <circle cx="12" cy="12" r="5" fill="white"/>
                   </svg>`,
                        iconSize: [24, 32],
                        iconAnchor: [12, 32],
                    });
                }

                // ── Place marker ────────────────────────────────────────────────
                function placeMarker(type, lat, lng, center = true) {
                    const marker = type === 'pickup' ? pickupMarker : dropoffMarker;
                    marker.setLatLng([lat, lng]).setOpacity(1);

                    if (type === 'pickup') {
                        pickupCoords = {
                            lat,
                            lng
                        };
                        setHidden('bk_pickup_lat', lat);
                        setHidden('bk_pickup_lng', lng);
                        setHidden('bk_pickup_confirmed', 1);
                    } else {
                        dropoffCoords = {
                            lat,
                            lng
                        };
                        setHidden('bk_drop_lat', lat);
                        setHidden('bk_drop_lng', lng);
                        setHidden('bk_dropoff_confirmed', 1);
                    }

                    if (center) {
                        if (pickupCoords && dropoffCoords) {
                            map.fitBounds([
                                [pickupCoords.lat, pickupCoords.lng],
                                [dropoffCoords.lat, dropoffCoords.lng],
                            ], {
                                padding: [40, 40]
                            });
                        } else {
                            map.setView([lat, lng], 15);
                        }
                    }

                    if (pickupCoords && dropoffCoords) fetchRoute();
                }

                function setHidden(id, value) {
                    const el = document.getElementById(id);
                    if (el) el.value = value;
                }

                // ── Reverse geocode dragged marker ───────────────────────────────
                async function reverseGeocodeMarker(marker, type) {
                    const {
                        lat,
                        lng
                    } = marker.getLatLng();
                    placeMarker(type, lat, lng, false);
                    try {
                        const res = await fetch(`${GEO.reverse}?lat=${lat}&lng=${lng}`);
                        const data = await res.json();
                        if (data.address) {
                            const inputId = type === 'pickup' ? 'bk_pickup' : 'bk_dropoff';
                            document.getElementById(inputId).value = data.address;
                        }
                    } catch (_) {}
                }

                // ── Autocomplete ─────────────────────────────────────────────────
                function attachAutocomplete(inputId, suggId, type) {
                    const input = document.getElementById(inputId);
                    const sugg = document.getElementById(suggId);
                    if (!input || !sugg) return;

                    input.addEventListener('input', () => {
                        clearTimeout(searchTimers[type]);
                        const q = input.value.trim();
                        if (q.length < 3) {
                            sugg.style.display = 'none';
                            return;
                        }

                        searchTimers[type] = setTimeout(async () => {
                            try {
                                const res = await fetch(`${GEO.search}?q=${encodeURIComponent(q)}`);
                                const data = await res.json();
                                const results = data.results ?? data ?? [];
                                if (!results.length) {
                                    sugg.style.display = 'none';
                                    return;
                                }

                                sugg.innerHTML = '';
                                results.slice(0, 6).forEach(r => {
                                    const btn = document.createElement('button');
                                    btn.type = 'button';
                                    btn.textContent = r.display_name ?? r.name ?? JSON
                                        .stringify(r);
                                    btn.addEventListener('click', () => {
                                        const lat = parseFloat(r.lat ?? r.latitude);
                                        const lng = parseFloat(r.lon ?? r.lng ?? r
                                            .longitude);
                                        input.value = r.display_name ?? r.name ?? input
                                            .value;
                                        sugg.style.display = 'none';
                                        if (!isNaN(lat) && !isNaN(lng)) placeMarker(
                                            type, lat, lng);
                                    });
                                    sugg.appendChild(btn);
                                });
                                sugg.style.display = 'block';
                            } catch (_) {
                                sugg.style.display = 'none';
                            }
                        }, 350);
                    });

                    document.addEventListener('click', e => {
                        if (!input.contains(e.target) && !sugg.contains(e.target)) {
                            sugg.style.display = 'none';
                        }
                    });
                }

                // ── Fetch route + update pricing ─────────────────────────────────
                async function fetchRoute() {
                    if (!pickupCoords || !dropoffCoords) return;
                    try {
                        const res = await fetch(GEO.route, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': GEO.csrf,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                pickup_lat: pickupCoords.lat,
                                pickup_lng: pickupCoords.lng,
                                drop_lat: dropoffCoords.lat,
                                drop_lng: dropoffCoords.lng,
                            }),
                        });
                        if (!res.ok) return;
                        const data = await res.json();

                        currentDistanceKm = parseFloat(data.distance_km) || 0;
                        currentEtaMinutes = parseFloat(data.duration_min) || 0;

                        setHidden('bk_distance_km', currentDistanceKm);
                        setHidden('bk_eta_minutes', Math.round(currentEtaMinutes));

                        // Draw route on map
                        if (routeLayer) map.removeLayer(routeLayer);
                        const coords = data.coordinates ?? [];
                        if (coords.length >= 2) {
                            const isFallback = data.is_fallback ?? false;
                            routeLayer = L.polyline(coords.map(c => [c[0], c[1]]), {
                                color: '#09090b',
                                weight: 4,
                                opacity: 0.75,
                                dashArray: isFallback ? '8 6' : null,
                            }).addTo(map);
                            map.fitBounds(routeLayer.getBounds(), {
                                padding: [40, 40]
                            });
                        }

                        updateAllEstimates();
                    } catch (_) {}
                }

                // ── Pricing calculation ──────────────────────────────────────────
                function calcEstimate(cls, distKm) {
                    const rates = classRates[cls];
                    if (!rates || !distKm) return null;
                    const total = rates.base_rate + rates.per_km_rate * distKm;
                    return {
                        base: rates.base_rate,
                        perKm: rates.per_km_rate,
                        distKm: distKm,
                        distFee: rates.per_km_rate * distKm,
                        total: total,
                    };
                }

                function formatMoney(n) {
                    return '₱' + Number(n).toLocaleString('en-PH', {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 0
                    });
                }

                function updateAllEstimates() {
                    const distKm = currentDistanceKm;
                    if (distKm <= 0) return;

                    ['light', 'medium', 'heavy'].forEach(cls => {
                        const est = calcEstimate(cls, distKm);
                        const el = document.getElementById('bk_class_est_' + cls);
                        if (!el || !est) return;
                        el.querySelector('.est-val').textContent = formatMoney(est.total);
                    });

                    if (selectedTruckClass) {
                        const est = calcEstimate(selectedTruckClass, distKm);
                        if (est) showPriceBanner(est);
                    }
                }

                function showPriceBanner(est) {
                    const banner = document.getElementById('bkPriceBanner');
                    const valEl = document.getElementById('bkPriceValue');
                    const breakEl = document.getElementById('bkPriceBreakdown');
                    if (!banner) return;

                    valEl.textContent = formatMoney(est.total);
                    breakEl.textContent =
                        `Base ₱${est.base.toLocaleString()} + ${est.distKm.toFixed(1)} km × ₱${est.perKm.toLocaleString()}/km`;
                    banner.classList.remove('hidden');
                    setHidden('bk_price_input', est.total.toFixed(2));
                }

                // ── Truck class selection ────────────────────────────────────────
                function selectClass(card) {
                    if (card.dataset.available !== '1' && !isScheduleMode) return;
                    document.querySelectorAll('.bk-class-card').forEach(c => c.classList.remove('selected'));
                    card.classList.add('selected');
                    selectedTruckClass = card.dataset.class;
                    selectedTruckId = card.dataset.truckId;
                    setHidden('bk_truck_type_id', selectedTruckId);
                    setHidden('bk_truck_class_hidden', selectedTruckClass);

                    if (currentDistanceKm > 0) {
                        const est = calcEstimate(selectedTruckClass, currentDistanceKm);
                        if (est) showPriceBanner(est);
                    }
                }

                // ── Vehicle category selection ───────────────────────────────────
                function initCategoryPicker() {
                    document.querySelectorAll('.bk-cat-card').forEach(card => {
                        function select() {
                            document.querySelectorAll('.bk-cat-card').forEach(c => {
                                c.classList.remove('selected');
                                c.setAttribute('aria-pressed', 'false');
                            });
                            card.classList.add('selected');
                            card.setAttribute('aria-pressed', 'true');
                            setHidden('bk_vehicle_category', card.dataset.cat);
                        }
                        card.addEventListener('click', select);
                        card.addEventListener('keydown', e => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                select();
                            }
                        });
                    });
                }

                // ── Image upload ─────────────────────────────────────────────────
                function initImageUpload() {
                    const dropzone = document.getElementById('bk_dropzone');
                    const fileInput = document.getElementById('bk_images_input');
                    const counter = document.getElementById('bk_img_counter');
                    const countEl = document.getElementById('bk_img_count');
                    const clearBtn = document.getElementById('bk_img_clear');
                    const preview = document.getElementById('bk_img_preview');
                    if (!dropzone || !fileInput) return;

                    dropzone.addEventListener('click', () => fileInput.click());
                    dropzone.addEventListener('dragover', e => {
                        e.preventDefault();
                        dropzone.style.borderColor = '#09090b';
                    });
                    dropzone.addEventListener('dragleave', () => {
                        dropzone.style.borderColor = '#d4d4d8';
                    });
                    dropzone.addEventListener('drop', e => {
                        e.preventDefault();
                        dropzone.style.borderColor = '#d4d4d8';
                        fileInput.files = e.dataTransfer.files;
                        handleFiles(fileInput.files);
                    });
                    fileInput.addEventListener('change', () => handleFiles(fileInput.files));

                    function handleFiles(files) {
                        const count = Math.min(files.length, 5);
                        countEl.textContent = count;
                        counter.style.display = count > 0 ? 'block' : 'none';
                        preview.innerHTML = '';
                        for (let i = 0; i < count; i++) {
                            const reader = new FileReader();
                            const file = files[i];
                            reader.onload = e => {
                                const img = document.createElement('img');
                                img.src = e.target.result;
                                img.style.cssText =
                                    'width:60px;height:60px;object-fit:cover;border-radius:8px;border:1px solid #e4e4e7;';
                                preview.appendChild(img);
                            };
                            reader.readAsDataURL(file);
                        }
                    }

                    clearBtn?.addEventListener('click', () => {
                        fileInput.value = '';
                        counter.style.display = 'none';
                        preview.innerHTML = '';
                    });
                }

                // ── Form submit ──────────────────────────────────────────────────
                function initFormSubmit() {
                    const form = document.getElementById('bkForm');
                    const btn = document.getElementById('bkSubmitBtn');
                    if (!form || !btn) return;

                    form.addEventListener('submit', e => {
                        // Validate pickup/dropoff coords
                        const pickLat = document.getElementById('bk_pickup_lat').value;
                        const dropLat = document.getElementById('bk_drop_lat').value;
                        if (!pickLat || !dropLat) {
                            e.preventDefault();
                            alert('Please search and confirm your pickup and drop-off locations on the map.');
                            return;
                        }
                        if (!selectedTruckClass) {
                            e.preventDefault();
                            alert('Please select a truck class (Light, Medium, or Heavy).');
                            return;
                        }
                        btn.disabled = true;
                        btn.querySelector('span').textContent = 'Submitting…';
                    });
                }

                // ── Booking mode toggle ──────────────────────────────────────────
                function initBookingModeToggle() {
                    const svcSel = document.getElementById('bk_service_type');
                    const schedWrap = document.getElementById('bk_sched_wrap');
                    if (!svcSel) return;

                    svcSel.addEventListener('change', () => {
                        isScheduleMode = svcSel.value === 'schedule';
                        if (schedWrap) schedWrap.style.display = isScheduleMode ? 'block' : 'none';
                        updateClassCardAvailability();
                    });
                }

                function updateClassCardAvailability() {
                    const allUnavailMsg = document.getElementById('bk_all_unavailable_msg');
                    let allUnavail = true;
                    document.querySelectorAll('.bk-class-card').forEach(card => {
                        const originallyAvailable = card.dataset.available === '1';
                        if (isScheduleMode) {
                            card.classList.remove('unavailable');
                            card.setAttribute('tabindex', '0');
                            card.setAttribute('aria-disabled', 'false');
                            allUnavail = false;
                        } else {
                            if (!originallyAvailable) {
                                card.classList.add('unavailable');
                                card.setAttribute('tabindex', '-1');
                                card.setAttribute('aria-disabled', 'true');
                            } else {
                                allUnavail = false;
                            }
                        }
                    });
                    if (allUnavailMsg) allUnavailMsg.style.display = (allUnavail && !isScheduleMode) ? 'block' : 'none';
                }

                // ── Truck class picker ───────────────────────────────────────────
                function initClassPicker() {
                    document.querySelectorAll('.bk-class-card').forEach(card => {
                        card.addEventListener('click', () => selectClass(card));
                        card.addEventListener('keydown', e => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                selectClass(card);
                            }
                        });
                    });

                    // Restore pre-selected from old() values
                    const preSelected = document.querySelector('.bk-class-card.selected');
                    if (preSelected) {
                        selectedTruckClass = preSelected.dataset.class;
                        selectedTruckId = preSelected.dataset.truckId;
                    }

                    updateClassCardAvailability();
                }

                // ── Boot ─────────────────────────────────────────────────────────
                document.addEventListener('DOMContentLoaded', () => {
                    initMap();
                    attachAutocomplete('bk_pickup', 'bkPickupSugg', 'pickup');
                    attachAutocomplete('bk_dropoff', 'bkDropoffSugg', 'dropoff');
                    initCategoryPicker();
                    initImageUpload();
                    initFormSubmit();
                    initBookingModeToggle();
                    initClassPicker();
                });

            })();
        </script>
    @endpush

@endsection
