@extends('layouts.superadmin')

@section('title', isset($user) ? 'Edit User' : 'Add User')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/user-create.css') }}">
    <style>
        .phone-input-wrap {
            display: flex;
            align-items: center;
            border: 1px solid #e5e7eb;
            /* border-radius: 10px; */
            background: #f9fafb;
            overflow: hidden;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .phone-input-wrap:focus-within {
            border-color: #9ca3af;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.15);
        }

        .phone-cc {
            padding: 10px 10px 10px 14px;
            font-size: 14px;
            font-weight: 700;
            color: #6b7280;
            border-right: 1px solid #e5e7eb;
            user-select: none;
            pointer-events: none;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .phone-input-wrap input[type="tel"] {
            flex: 1;
            min-width: 0;
            padding: 10px 14px;
            border: none !important;
            outline: none;
            background: transparent !important;
            font-size: 14px;
            color: #111827;
            box-shadow: none !important;
        }

        .phone-input-wrap input[type="tel"]::placeholder {
            color: #9ca3af;
        }

        /* Suppress Edge's built-in password reveal button */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none !important;
        }

        .pw-wrap {
            position: relative;
        }

        .pw-wrap input[type="password"],
        .pw-wrap input[type="text"] {
            padding-right: 56px;
        }

        .pw-toggle {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            padding: 0 12px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 12px;
            color: #555;
            font-family: sans-serif;
            user-select: none;
        }

        .pw-toggle:hover {
            color: #000;
        }

        .page-back-nav {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin: 0 0 18px;
            padding: 0;
            background: none;
            border: none;
            color: #6b7280;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            font-family: inherit;
            transition: color 0.13s;
        }

        .page-back-nav:hover {
            color: #111827;
        }

        .page-back-nav i {
            width: 15px;
            height: 15px;
            flex-shrink: 0;
        }

        .role-choice-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin: 0 0 8px;
        }

        .role-choice-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 20px 16px;
            /* border-radius: 14px; */
            border: 1.5px solid #e5e7eb;
            background: #fff;
            cursor: pointer;
            text-align: left;
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
            font-family: inherit;
        }

        .role-choice-card:hover:not(:disabled) {
            border-color: #111827;
            background: #f9fafb;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
        }

        .role-choice-card:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .rcc-icon-wrap {
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            /* border-radius: 12px; */
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #000000;
        }

        .rcc-body {
            flex: 1;
            min-width: 0;
        }

        .rcc-body strong {
            display: block;
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 3px;
        }

        .rcc-body>span:not(.rcc-badge) {
            display: block;
            font-size: 12.5px;
            color: #6b7280;
            line-height: 1.4;
        }

        .rcc-badge {
            display: inline-flex;
            align-items: center;
            margin-top: 6px;
            padding: 3px 8px;
            /* border-radius: 999px; */
            font-size: 11px;
            font-weight: 700;
        }

        .rcc-badge--slots {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .rcc-badge--limit {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .rcc-arrow {
            flex-shrink: 0;
        }

        .back-chooser-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: none;
            padding: 0;
            margin: 0 0 16px;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            font-family: inherit;
        }

        .back-chooser-btn:hover {
            color: #111827;
        }

        .form-section-title {
            margin: 22px 0 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 11.5px;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            color: #000000;
        }

        @media (max-width: 580px) {
            .role-choice-grid {
                grid-template-columns: 1fr;
            }
        }

        .truck-class-picker {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 4px;
        }

        .tcp-card {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 14px;
            background: #f7f7f7;
            cursor: pointer;
            text-align: left;
            font-family: sans-serif;
            width: 100%;
            transition: background 0.12s;
        }

        .tcp-card:hover {
            background: #efefef;
        }

        .tcp-card.selected {
            background: #111827;
        }

        .tcp-card-left {
            flex: 1;
            min-width: 0;
        }

        .tcp-card-name {
            display: block;
            font-family: sans-serif;
            font-size: 13.5px;
            color: #000;
            margin-bottom: 4px;
        }

        .tcp-card.selected .tcp-card-name {
            color: #fff;
        }

        .tcp-card-note {
            display: block;
            font-family: sans-serif;
            font-size: 11.5px;
            color: #555;
            margin-top: 3px;
            line-height: 1.4;
        }

        .tcp-card.selected .tcp-card-note {
            color: #d1d5db;
        }

        .tcp-card-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 3px;
            flex-shrink: 0;
            font-family: sans-serif;
        }

        .tcp-meta-row {
            display: flex;
            gap: 6px;
            align-items: center;
            font-family: sans-serif;
            font-size: 11.5px;
            color: #000;
        }

        .tcp-card.selected .tcp-meta-row {
            color: #fff;
        }

        .tcp-meta-label {
            font-family: sans-serif;
            font-size: 10.5px;
            color: #777;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .tcp-card.selected .tcp-meta-label {
            color: #9ca3af;
        }

        /* ── Truck Class Modal ─────────────────────────────── */
        #truckClassModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            font-family: sans-serif;
            overflow-y: auto;
        }

        .tc-modal-box {
            background: #fff;
            margin: 40px auto 40px;
            max-width: 900px;
            width: 96%;
            border: 1px solid #000;
            padding: 28px;
        }

        .tc-modal-inner {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 0;
            align-items: start;
        }

        .tc-modal-inner>div:first-child {
            padding-right: 24px;
        }

        .tc-modal-inner>div:last-child {
            padding-left: 24px;
        }

        .tc-col-divider {
            width: 1px;
            background: #000;
            align-self: stretch;
            margin: 0;
        }

        @media (max-width: 680px) {
            .tc-modal-inner {
                grid-template-columns: 1fr;
            }

            .tc-col-divider {
                display: none;
            }
        }

        .tc-modal-heading {
            font-family: sans-serif;
            font-size: 15px;
            color: #000;
            margin: 0 0 18px;
        }

        .tc-divider {
            border: none;
            border-top: 1px solid #000;
            margin: 16px 0;
        }

        .tc-pagination {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .tc-page-btn {
            background: #fff;
            border: 1px solid #000;
            padding: 3px 9px;
            font-family: sans-serif;
            font-size: 12px;
            color: #000;
            cursor: pointer;
        }

        .tc-page-btn.active {
            background: #000;
            color: #fff;
        }

        .tc-page-btn:disabled {
            opacity: 0.4;
            cursor: default;
        }

        #tcConfirmModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            font-family: sans-serif;
        }

        .tc-confirm-box {
            background: #fff;
            border: 1px solid #000;
            padding: 24px 28px;
            margin: 160px auto 0;
            max-width: 380px;
            width: 92%;
        }

        .tc-confirm-msg {
            font-family: sans-serif;
            font-size: 13.5px;
            color: #000;
            margin: 0 0 20px;
        }

        .tc-confirm-actions {
            display: flex;
            gap: 10px;
        }

        .tc-form-label {
            display: block;
            font-family: sans-serif;
            font-size: 13.5px;
            color: #000;
            margin-bottom: 5px;
        }

        .tc-form-input,
        .tc-form-textarea {
            display: block;
            width: 100%;
            border: 1px solid #000;
            padding: 7px 10px;
            font-family: sans-serif;
            font-size: 13.5px;
            color: #000;
            background: #fff;
            box-sizing: border-box;
            outline: none;
        }

        .tc-form-textarea {
            resize: vertical;
            min-height: 68px;
        }

        .tc-desc-note {
            display: block;
            font-family: sans-serif;
            font-size: 12px;
            color: #000;
            margin-top: 4px;
        }

        .tc-field-wrap {
            margin-bottom: 14px;
        }

        .tc-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }

        .tc-actions {
            display: flex;
            gap: 10px;
            margin-top: 22px;
        }

        .tc-btn {
            padding: 7px 20px;
            font-family: sans-serif;
            font-size: 13.5px;
            cursor: pointer;
            border: 1px solid #000;
        }

        .tc-btn-primary {
            background: #000;
            color: #fff;
        }

        .tc-btn-secondary {
            background: #fff;
            color: #000;
        }

        .tc-error-box {
            border: 1px solid #000;
            padding: 8px 10px;
            font-family: sans-serif;
            font-size: 13px;
            color: #000;
            margin-bottom: 14px;
            display: none;
        }

        .tc-existing-label {
            font-family: sans-serif;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #000;
            margin: 0 0 10px;
        }

        .tc-table {
            width: 100%;
            border-collapse: collapse;
            font-family: sans-serif;
            font-size: 12.5px;
            color: #000;
        }

        .tc-table th,
        .tc-table td {
            border: 1px solid #000;
            padding: 5px 8px;
            text-align: left;
            color: #000;
        }

        .tc-edit-row-btn {
            background: none;
            border: 1px solid #000;
            padding: 2px 8px;
            font-family: sans-serif;
            font-size: 12px;
            color: #000;
            cursor: pointer;
            margin-right: 4px;
        }

        .tc-remove-row-btn {
            background: none;
            border: 1px solid #000;
            padding: 2px 8px;
            font-family: sans-serif;
            font-size: 12px;
            color: #000;
            cursor: pointer;
        }

        .tc-empty-note {
            font-family: sans-serif;
            font-size: 13px;
            color: #000;
            margin: 0;
        }

        .tc-open-modal-btn {
            background: none;
            border: 1px solid #000;
            padding: 5px 13px;
            font-family: sans-serif;
            font-size: 12.5px;
            color: #000;
            cursor: pointer;
            margin-bottom: 10px;
        }

        .tc-form-section-title {
            font-family: sans-serif;
            font-size: 12px;
            color: #000;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin: 0 0 14px;
        }
    </style>
@endpush

@php
    $isEdit = isset($user);
    $isTLEdit = $isEdit && ($user->role->name ?? '') === 'Team Leader';
    $driverParts = $isTLEdit ? split_full_name($user->unit?->driver_name ?? '') : [];
    $tlRoleId = $teamLeaderCapacity['role_id'] ?? '';
    $dispRole = $roles->firstWhere('name', 'Admin') ?? $roles->reject(fn($r) => $r->name === 'Team Leader')->first();
    $dispRoleId = $dispRole?->id ?? '';
    // If Laravel redirected back with validation errors, old('role_id') is set — skip the chooser
    $hasOldRoleId = !$isEdit && old('role_id') !== null;
    $showChooser = !$isEdit && !$hasOldRoleId;
    $showFormOnLoad = $isEdit || $hasOldRoleId;
@endphp

@section('content')
    <div class="create-user-page">

        {{-- Back to Users list ───────────────────────────
        <a href="{{ route('superadmin.users.index') }}" class="page-back-nav">
            <i data-lucide="arrow-left"></i>
            Back to Users
        </a> --}}

        <div class="form-wrapper" style="margin-top:0;">
            <div class="form-card">

                @if (!$isEdit)
                    <div id="roleChooser" @if (!$showChooser) hidden @endif>
                        <div class="form-header" style="margin-bottom:20px;">
                            <h2>New User</h2>
                            <p>Which type of account would you like to create?</p>
                        </div>

                        <div class="teamleader-capacity-card" style="margin-bottom:20px;">
                            <div>
                                <strong>Team Leader Slots</strong>
                                <p>{{ $teamLeaderCapacity['count'] ?? 0 }} of {{ $teamLeaderCapacity['limit'] ?? 10 }} slots
                                    used</p>
                            </div>
                            <span class="teamleader-capacity-badge">
                                {{ ($teamLeaderCapacity['count'] ?? 0) . ' / ' . ($teamLeaderCapacity['limit'] ?? 10) }}
                            </span>
                        </div>

                        <div class="role-choice-grid">
                            <button type="button" class="role-choice-card" id="chooseDispatcher"
                                data-role-id="{{ $dispRoleId }}" data-role-label="Dispatcher (Admin)">

                                <div class="rcc-body">
                                    <strong>Dispatcher</strong>
                                    <span>Admin who manages bookings and dispatches field teams</span>
                                </div>
                            </button>

                            <button type="button" class="role-choice-card" id="chooseTeamLeader"
                                data-role-id="{{ $tlRoleId }}" data-role-label="Team Leader"
                                @disabled(!empty($teamLeaderCapacity['reached']))>
                                <div class="rcc-icon-wrap">
                                    <i data-lucide="hard-hat" style="width:26px;height:26px;"></i>
                                </div>
                                <div class="rcc-body">
                                    <strong>Team Leader</strong>
                                    <span>Field crew leader with an assigned tow unit and driver</span>
                                    @if (!empty($teamLeaderCapacity['reached']))
                                        <span class="rcc-badge rcc-badge--limit">Limit reached</span>
                                    @else
                                        <span class="rcc-badge rcc-badge--slots">
                                            {{ $teamLeaderCapacity['remaining'] ?? 0 }}
                                            slot{{ ($teamLeaderCapacity['remaining'] ?? 0) !== 1 ? 's' : '' }} available
                                        </span>
                                    @endif
                                </div>
                                <div class="rcc-arrow">
                                    <i data-lucide="chevron-right" style="width:17px;height:17px;color:#9ca3af;"></i>
                                </div>
                            </button>
                        </div>
                    </div>
                @endif

                <div id="formSection" @if (!$showFormOnLoad) hidden @endif>

                    {{-- Back to chooser (create mode only) --}}
                    @if (!$isEdit)
                        <button type="button" id="backToChooser" class="back-chooser-btn">
                            <i data-lucide="arrow-left" style="width:14px;height:14px;"></i>
                            Choose different role
                        </button>
                    @endif

                    <div class="form-header">
                        <h2>{{ $isEdit ? 'Edit User' : 'Register User' }}</h2>
                        @if ($isEdit)
                            <p>Editing <strong>{{ $user->name }}</strong></p>
                        @else
                            <p id="formRoleHeadline">
                                @if ($hasOldRoleId)
                                    @php
                                        $restoredLabel =
                                            (string) old('role_id') === (string) $tlRoleId
                                                ? 'Team Leader'
                                                : 'Dispatcher (Admin)';
                                    @endphp
                                    Creating a {{ $restoredLabel }} account
                                @else
                                    Fill in the details below
                                @endif
                            </p>
                        @endif
                    </div>

                    @if ($isEdit)
                        <div class="teamleader-capacity-card" style="margin-bottom:20px;">
                            <div>
                                <strong>Team Leader Slots</strong>
                                <p>{{ $teamLeaderCapacity['count'] ?? 0 }} of {{ $teamLeaderCapacity['limit'] ?? 10 }}
                                    slots used</p>
                            </div>
                            <span class="teamleader-capacity-badge">
                                {{ ($teamLeaderCapacity['count'] ?? 0) . ' / ' . ($teamLeaderCapacity['limit'] ?? 10) }}
                            </span>
                        </div>
                    @endif

                    <form method="POST"
                        action="{{ $isEdit ? route('superadmin.users.update', $user->id) : route('superadmin.users.store') }}"
                        class="create-user-form" data-is-edit="{{ $isEdit ? 'true' : 'false' }}"
                        data-is-tl-edit="{{ $isTLEdit ? 'true' : 'false' }}" data-tl-role-id="{{ $tlRoleId }}">
                        @csrf
                        @if ($isEdit)
                            @method('PUT')
                        @endif

                        @if ($isEdit)
                            <input type="hidden" name="role_id" value="{{ $user->role_id }}">
                        @else
                            <input type="hidden" name="role_id" id="hiddenRoleId" value="{{ old('role_id', '') }}">
                        @endif

                        @if ($isEdit)
                            <div id="ajaxErrorBanner" class="ajax-error-banner" hidden>
                                <span id="ajaxErrorText"></span>
                            </div>
                        @endif

                        <div class="form-section-title">Teamleader Information</div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name <span class="required-mark">*</span></label>
                                <input type="text" name="first_name"
                                    value="{{ old('first_name', $user->first_name ?? '') }}" placeholder="First name"
                                    required>
                                @error('first_name')
                                    <small class="error-text">{{ $message }}</small>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label>Middle Name <span class="field-optional">Optional</span></label>
                                <input type="text" name="middle_name"
                                    value="{{ old('middle_name', $user->middle_name ?? '') }}" placeholder="Middle name">
                                @error('middle_name')
                                    <small class="error-text">{{ $message }}</small>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label>Last Name <span class="required-mark">*</span></label>
                                <input type="text" name="last_name"
                                    value="{{ old('last_name', $user->last_name ?? '') }}" placeholder="Last name"
                                    required>
                                @error('last_name')
                                    <small class="error-text">{{ $message }}</small>
                                @enderror
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Email Address <span class="required-mark">*</span></label>
                            <input type="email" name="email" value="{{ old('email', $user->email ?? '') }}"
                                placeholder="name@gmail.com" required>
                            @error('email')
                                <small class="error-text">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Password {!! $isEdit ? '' : '<span class="required-mark">*</span>' !!}</label>
                                <div class="pw-wrap">
                                    <input type="password" name="password" id="passwordInput" autocomplete="new-password"
                                        {{ $isEdit ? '' : 'required' }}>
                                    <button type="button" class="pw-toggle" onclick="togglePw('passwordInput', this)"
                                        tabindex="-1">Show</button>
                                </div>
                                @error('password')
                                    <small class="error-text">{{ $message }}</small>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label>Confirm Password {!! $isEdit ? '' : '<span class="required-mark">*</span>' !!}</label>
                                <div class="pw-wrap">
                                    <input type="password" name="password_confirmation" id="passwordConfirmInput"
                                        autocomplete="new-password" {{ $isEdit ? '' : 'required' }}>
                                    <button type="button" class="pw-toggle"
                                        onclick="togglePw('passwordConfirmInput', this)" tabindex="-1">Show</button>
                                </div>
                                @error('password_confirmation')
                                    <small class="error-text">{{ $message }}</small>
                                @enderror
                            </div>
                        </div>

                        <div id="passwordRequirements" class="password-requirements" hidden>
                            <p>Password Requirements:</p>
                            <ul>
                                <li data-rule="length"><span class="requirement-icon">•</span><span>Minimum 12
                                        characters</span></li>
                                <li data-rule="uppercase"><span class="requirement-icon">•</span><span>atleast one
                                        uppercase letters</span></li>
                                <li data-rule="lowercase"><span class="requirement-icon">•</span><span>atleast one
                                        lowercase letters</span></li>
                                <li data-rule="number"><span class="requirement-icon">•</span><span>atleast one
                                        number</span></li>
                                <li data-rule="special"><span class="requirement-icon">•</span><span>atleast one
                                        special
                                        characters</span></li>
                            </ul>
                        </div>

                        <div class="form-group">
                            <label>Phone Number
                                <span id="phoneRequiredMark" class="required-mark"
                                    @if (!($isTLEdit || ($hasOldRoleId && (string) old('role_id') === (string) $tlRoleId))) hidden @endif>*</span>
                                <span id="phoneOptionalMark" class="field-optional"
                                    @if ($isTLEdit || ($hasOldRoleId && (string) old('role_id') === (string) $tlRoleId)) hidden @endif>Optional</span>
                            </label>
                            <div class="phone-input-wrap">
                                <span class="phone-cc">+63</span>
                                <input type="tel" name="phone" id="phoneInput"
                                    value="{{ old('phone', $user->phone ?? '') }}" placeholder="9XXXXXXXXX"
                                    maxlength="11" inputmode="numeric" autocomplete="tel"
                                    @if ($isTLEdit || ($hasOldRoleId && (string) old('role_id') === (string) $tlRoleId)) required @endif>
                            </div>
                            {{-- <small class="field-note">Numbers only · start with 9 or 09 · max 11 digits</small> --}}
                            @error('phone')
                                <small class="error-text">{{ $message }}</small>
                            @enderror
                        </div>

                        @if ($isEdit)
                            <div class="form-group">
                                <label>Role</label>
                                <div class="locked-field">
                                    <span>{{ $user->role->name ?? '—' }}</span>
                                    <span class="locked-badge">Locked</span>
                                </div>
                                <small class="field-note">Role cannot be changed after user creation.</small>
                            </div>
                        @endif

                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="active"
                                    {{ old('status', $user->status ?? 'active') === 'active' ? 'selected' : '' }}>
                                    Active</option>
                                <option value="inactive"
                                    {{ old('status', $user->status ?? '') === 'inactive' ? 'selected' : '' }}>
                                    Inactive</option>
                            </select>
                        </div>

                        @php
                            $showTLSections =
                                $isTLEdit || ($hasOldRoleId && (string) old('role_id') === (string) $tlRoleId);
                        @endphp
                        <div id="sectionDriverDetails" class="role-section-box"
                            @if (!$showTLSections) hidden @endif>
                            <div class="role-section-header"><span>Driver Details</span></div>
                            <div class="role-section-body">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>First Name <span class="required-mark">*</span></label>
                                        <input type="text" name="driver_first_name"
                                            value="{{ old('driver_first_name', $driverParts['first_name'] ?? '') }}"
                                            placeholder="Driver first name"
                                            @if ($showTLSections) required @endif>
                                        @error('driver_first_name')
                                            <small class="error-text">{{ $message }}</small>
                                        @enderror
                                    </div>
                                    <div class="form-group">
                                        <label>Middle Name <span class="field-optional">optional</span></label>
                                        <input type="text" name="driver_middle_name"
                                            value="{{ old('driver_middle_name', $driverParts['middle_name'] ?? '') }}"
                                            placeholder="Driver middle name">
                                    </div>
                                    <div class="form-group">
                                        <label>Last Name <span class="required-mark">*</span></label>
                                        <input type="text" name="driver_last_name"
                                            value="{{ old('driver_last_name', $driverParts['last_name'] ?? '') }}"
                                            placeholder="Driver last name"
                                            @if ($showTLSections) required @endif>
                                        @error('driver_last_name')
                                            <small class="error-text">{{ $message }}</small>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="sectionUnitDetails" class="role-section-box"
                            @if (!$showTLSections) hidden @endif>
                            <div class="role-section-header"><span>Unit Details</span></div>
                            <div class="role-section-body">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Unit Name <span class="required-mark">*</span></label>
                                        <input type="text" name="unit_name"
                                            value="{{ old('unit_name', $user->unit?->name ?? '') }}"
                                            placeholder="e.g. UNIT 1" @if ($showTLSections) required @endif>
                                        @error('unit_name')
                                            <small class="error-text">{{ $message }}</small>
                                        @enderror
                                    </div>
                                    <div class="form-group">
                                        <label>Plate Number <span class="required-mark">*</span></label>
                                        <input type="text" name="unit_plate_number"
                                            value="{{ old('unit_plate_number', $user->unit?->plate_number ?? '') }}"
                                            placeholder="e.g. ABC 1234"
                                            @if ($showTLSections && !$isEdit) required @endif>
                                        @error('unit_plate_number')
                                            <small class="error-text">{{ $message }}</small>
                                        @enderror
                                    </div>
                                </div>

                                <div class="form-group">
                                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:10px;">
                                        <label style="margin:0;">Truck Type <span class="required-mark">*</span></label>
                                        <button type="button" class="tc-open-modal-btn" id="openTruckClassModal">Add
                                            Truck Class</button>
                                    </div>
                                    @php
                                        $currentTruckId = $isTLEdit ? $user->unit?->truck_type_id ?? '' : '';
                                        $selectedTruckId = old(
                                            'unit_truck_id',
                                            old('unit_truck_class', $currentTruckId),
                                        );
                                    @endphp
                                    @if ($truckTypes->isEmpty())
                                        <p style="font-family:sans-serif;font-size:13px;color:#000;margin:0;">No active
                                            truck types yet. Add one using the button above.</p>
                                    @else
                                        <div class="truck-class-picker" id="truckClassPicker">
                                            @foreach ($truckTypes as $tt)
                                                <button type="button"
                                                    class="tcp-card {{ (string) $selectedTruckId === (string) $tt->id ? 'selected' : '' }}"
                                                    data-id="{{ $tt->id }}">
                                                    <div class="tcp-card-left">
                                                        <span class="tcp-card-name">{{ $tt->name }}</span>
                                                        @if ($tt->description)
                                                            <span class="tcp-card-note">{{ $tt->description }}</span>
                                                        @endif
                                                    </div>
                                                    <div class="tcp-card-meta">
                                                        <div class="tcp-meta-row">
                                                            <span class="tcp-meta-label">Base Rate</span>
                                                            <span>{{ number_format($tt->base_rate, 2) }}</span>
                                                        </div>
                                                        <div class="tcp-meta-row">
                                                            <span class="tcp-meta-label">Per KM</span>
                                                            <span>{{ number_format($tt->per_km_rate, 2) }}</span>
                                                        </div>
                                                        <div class="tcp-meta-row">
                                                            <span class="tcp-meta-label">Kilo</span>
                                                            <span>{{ $tt->max_tonnage !== null ? number_format($tt->max_tonnage, 2) : '—' }}</span>
                                                        </div>
                                                    </div>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                    <input type="hidden" name="unit_truck_id" id="truckClassHidden"
                                        value="{{ $selectedTruckId }}">
                                    <small id="truckClassError" class="error-text" hidden>Please select a truck
                                        type.</small>
                                    @error('unit_truck_id')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                    @error('unit_truck_class')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="{{ route('superadmin.users.index') }}" class="btn-cancel">Cancel</a>
                            <button type="submit" class="btn-primary-submit" id="createUserSubmit">
                                {{ $isEdit ? 'Update User' : 'Register User' }}
                            </button>
                        </div>

                    </form>
                </div>{{-- /formSection --}}

            </div>{{-- /form-card --}}
        </div>{{-- /form-wrapper --}}

    </div>

    {{-- ── Truck Class Modal ──────────────────────────────────────── --}}
    <div id="truckClassModal">
        <div class="tc-modal-box">
            <p class="tc-modal-heading" id="tcModalTitle">Add Truck Class</p>

            <div class="tc-modal-inner">

                {{-- LEFT: Form --}}
                <div>
                    <p class="tc-form-section-title" id="tcFormSectionTitle">New Truck Class</p>

                    <div id="tcErrorBox" class="tc-error-box"></div>

                    <form id="tcModalForm" novalidate>
                        <input type="hidden" id="tcEditId" value="">

                        <div class="tc-field-wrap">
                            <label class="tc-form-label" for="tcName">Truck Class Name *</label>
                            <input type="text" id="tcName" class="tc-form-input" maxlength="100">
                        </div>

                        <div class="tc-row-2">
                            <div>
                                <label class="tc-form-label" for="tcBaseRate">Base Rate *</label>
                                <input type="number" id="tcBaseRate" class="tc-form-input" min="0"
                                    step="0.01">
                            </div>
                            <div>
                                <label class="tc-form-label" for="tcPerKm">Per KM *</label>
                                <input type="number" id="tcPerKm" class="tc-form-input" min="0"
                                    step="0.01">
                            </div>
                        </div>

                        <div class="tc-field-wrap">
                            <label class="tc-form-label" for="tcKilo">Kilo *</label>
                            <input type="number" id="tcKilo" class="tc-form-input" min="0" step="0.01">
                        </div>

                        <div class="tc-field-wrap">
                            <label class="tc-form-label" for="tcDescription">Description <span
                                    style="color:#000;">optional</span></label>
                            <textarea id="tcDescription" class="tc-form-textarea" maxlength="100"></textarea>
                            <span class="tc-desc-note" id="tcDescCounter">0 / 100</span>
                        </div>

                        <div class="tc-actions">
                            <button type="submit" class="tc-btn tc-btn-primary" id="tcSubmitBtn">Add</button>
                            <button type="button" class="tc-btn tc-btn-secondary" id="tcCancelBtn">Cancel</button>
                        </div>
                    </form>
                </div>

                <div class="tc-col-divider"></div>

                {{-- RIGHT: Existing list --}}
                <div id="tcExistingSection">
                    <p class="tc-existing-label">Existing Truck Classes</p>
                    <div id="tcExistingList">
                        <p class="tc-empty-note">Loading...</p>
                    </div>
                    <div class="tc-pagination" id="tcPagination"></div>
                </div>

            </div>
        </div>
    </div>

    {{-- ── Remove Confirm Modal ───────────────────────────────────── --}}
    <div id="tcConfirmModal">
        <div class="tc-confirm-box">
            <p class="tc-confirm-msg" id="tcConfirmMsg">Remove this truck class?</p>
            <div class="tc-confirm-actions">
                <button type="button" class="tc-btn tc-btn-primary" id="tcConfirmOk">Remove</button>
                <button type="button" class="tc-btn tc-btn-secondary" id="tcConfirmCancel">Cancel</button>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        // ── Password show/hide (global — needed by inline onclick) ──────────
        function togglePw(inputId, btn) {
            var input = document.getElementById(inputId);
            if (!input) return;
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.textContent = isHidden ? 'Hide' : 'Show';
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') lucide.createIcons();

            const form = document.querySelector('.create-user-form');
            const isEditMode = form?.dataset.isEdit === 'true';
            const isTLEditMode = form?.dataset.isTlEdit === 'true';
            const tlRoleId = Number(form?.dataset.tlRoleId || 0);

            const roleChooser = document.getElementById('roleChooser');
            const formSection = document.getElementById('formSection');
            const backToChooser = document.getElementById('backToChooser');
            const hiddenRoleId = document.getElementById('hiddenRoleId');
            const formRoleHeadline = document.getElementById('formRoleHeadline');
            const createUserSubmit = document.getElementById('createUserSubmit');

            const sectionDriverDetails = document.getElementById('sectionDriverDetails');
            const sectionUnitDetails = document.getElementById('sectionUnitDetails');

            const phoneInput = document.getElementById('phoneInput');
            const phoneRequiredMark = document.getElementById('phoneRequiredMark');
            const phoneOptionalMark = document.getElementById('phoneOptionalMark');

            const truckClassHidden = document.getElementById('truckClassHidden');
            const truckClassPicker = document.getElementById('truckClassPicker');
            const truckClassError = document.getElementById('truckClassError');
            const csrfToken = document.querySelector('input[name="_token"]')?.value;

            let selectedIsTL = false;
            if (!isEditMode && hiddenRoleId?.value) {
                selectedIsTL = Number(hiddenRoleId.value) === tlRoleId;
            }

            // ── Truck class picker ──────────────────────────────────────────
            const selectClass = (id) => {
                truckClassPicker?.querySelectorAll('.tcp-card').forEach(c => {
                    c.classList.toggle('selected', String(c.dataset.id) === String(id));
                });
                if (truckClassHidden) truckClassHidden.value = id;
                if (truckClassError) truckClassError.hidden = true;
            };

            truckClassPicker?.querySelectorAll('.tcp-card').forEach(card => {
                card.addEventListener('click', () => selectClass(card.dataset.id));
            });

            // Pre-select in edit mode
            if (isTLEditMode && truckClassHidden?.value) {
                selectClass(truckClassHidden.value);
            }

            // ── Phone required toggle ───────────────────────────────────────
            const setPhoneRequired = (required) => {
                if (!phoneInput) return;
                required ? phoneInput.setAttribute('required', '') : phoneInput.removeAttribute('required');
                if (phoneRequiredMark) phoneRequiredMark.hidden = !required;
                if (phoneOptionalMark) phoneOptionalMark.hidden = required;
            };

            // ── Role chooser ────────────────────────────────────────────────
            const showForm = (roleId, roleLabel, isTL) => {
                selectedIsTL = isTL;
                if (hiddenRoleId) hiddenRoleId.value = roleId;
                if (formRoleHeadline) formRoleHeadline.textContent = `Creating a ${roleLabel} account`;
                if (roleChooser) roleChooser.hidden = true;
                if (formSection) formSection.hidden = false;

                if (sectionDriverDetails) sectionDriverDetails.hidden = !isTL;
                if (sectionUnitDetails) sectionUnitDetails.hidden = !isTL;

                ['driver_first_name', 'driver_last_name', 'unit_name', 'unit_plate_number'].forEach(n => {
                    const el = form?.querySelector(`[name="${n}"]`);
                    if (!el) return;
                    isTL ? el.setAttribute('required', '') : el.removeAttribute('required');
                });

                setPhoneRequired(isTL);

                if (typeof lucide !== 'undefined') lucide.createIcons();
            };

            document.getElementById('chooseDispatcher')?.addEventListener('click', function() {
                showForm(this.dataset.roleId, this.dataset.roleLabel, false);
            });

            document.getElementById('chooseTeamLeader')?.addEventListener('click', function() {
                if (this.disabled) return;
                showForm(this.dataset.roleId, this.dataset.roleLabel, true);
            });

            backToChooser?.addEventListener('click', () => {
                if (formSection) formSection.hidden = true;
                if (roleChooser) roleChooser.hidden = false;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            });

            // Restore TL state after validation-error redirect
            if (!isEditMode && hiddenRoleId?.value) {
                const isTL = Number(hiddenRoleId.value) === tlRoleId;
                if (isTL) {
                    setPhoneRequired(true);
                    if (truckClassHidden?.value) selectClass(truckClassHidden.value);
                }
            }

            // ── Form submit guard (create mode) ────────────────────────────
            form?.addEventListener('submit', (e) => {
                if (isEditMode) return;
                if (selectedIsTL && !truckClassHidden?.value) {
                    e.preventDefault();
                    if (truckClassError) truckClassError.hidden = false;
                    truckClassPicker?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            });

            // ── Password requirements ───────────────────────────────────────
            const passwordInput = document.getElementById('passwordInput');
            const requirementsBox = document.getElementById('passwordRequirements');

            const pwRules = {
                length: v => v.length >= 12,
                uppercase: v => /[A-Z]/.test(v),
                lowercase: v => /[a-z]/.test(v),
                number: v => /\d/.test(v),
                special: v => /[^A-Za-z0-9]/.test(v),
            };

            const syncPwRequirements = () => {
                if (!passwordInput || !requirementsBox) return;
                const value = passwordInput.value || '';
                Object.entries(pwRules).forEach(([ruleName, validator]) => {
                    const item = requirementsBox.querySelector(`[data-rule="${ruleName}"]`);
                    const icon = item?.querySelector('.requirement-icon');
                    const passed = validator(value);
                    item?.classList.toggle('met', passed);
                    if (icon) icon.textContent = passed ? '✓' : '•';
                });
            };

            if (passwordInput && requirementsBox) {
                passwordInput.addEventListener('focus', () => {
                    requirementsBox.hidden = false;
                    syncPwRequirements();
                });
                passwordInput.addEventListener('input', () => {
                    requirementsBox.hidden = false;
                    syncPwRequirements();
                });
                passwordInput.addEventListener('blur', () => {
                    if (!passwordInput.value.trim()) requirementsBox.hidden = true;
                });
            }

            // ── Phone sanitization ──────────────────────────────────────────
            if (phoneInput) {
                const cleanPhone = () => {
                    let v = phoneInput.value.replace(/\D/g, '');
                    if (v.startsWith('09')) v = v.slice(0, 11);
                    else v = v.slice(0, 10);
                    if (phoneInput.value !== v) phoneInput.value = v;
                };

                phoneInput.addEventListener('input', cleanPhone);
                phoneInput.addEventListener('keydown', (e) => {
                    const allowed = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                        'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'
                    ];
                    if (allowed.includes(e.key)) return;
                    if (!/^\d$/.test(e.key)) e.preventDefault();
                });
                phoneInput.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,
                        '');
                    const cur = phoneInput.value;
                    const next = (cur + pasted).replace(/\D/g, '');
                    phoneInput.value = next.startsWith('09') ? next.slice(0, 11) : next.slice(0, 10);
                });
            }

            // ── AJAX submit (edit mode) ─────────────────────────────────────
            if (isEditMode && form) {
                const ajaxErrorBanner = document.getElementById('ajaxErrorBanner');
                const ajaxErrorText = document.getElementById('ajaxErrorText');

                const showBannerError = (msg) => {
                    if (ajaxErrorText) ajaxErrorText.textContent = msg;
                    if (ajaxErrorBanner) ajaxErrorBanner.hidden = false;
                    ajaxErrorBanner?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                };

                const clearFieldErrors = () => form.querySelectorAll('.ajax-field-error').forEach(el => el
                    .remove());

                const showFieldErrors = (errors) => {
                    clearFieldErrors();
                    Object.entries(errors).forEach(([field, messages]) => {
                        const input = form.querySelector(`[name="${field}"]`);
                        if (!input) return;
                        const err = document.createElement('small');
                        err.className = 'error-text ajax-field-error';
                        err.textContent = Array.isArray(messages) ? messages[0] : messages;
                        input.closest('.form-group')?.appendChild(err);
                    });
                };

                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    if (ajaxErrorBanner) ajaxErrorBanner.hidden = true;
                    clearFieldErrors();
                    if (createUserSubmit) {
                        createUserSubmit.disabled = true;
                        createUserSubmit.classList.add('is-disabled');
                    }

                    try {
                        const res = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: new FormData(form),
                        });
                        const data = await res.json();

                        if (res.ok && data.success) {
                            sessionStorage.setItem('sa_flash_success', data.message || '');
                            window.location.href = '{{ route('superadmin.users.index') }}';
                        } else if (data.errors) {
                            showFieldErrors(data.errors);
                            showBannerError('Please fix the errors below and try again.');
                        } else {
                            showBannerError(data.message || 'Update failed. Please try again.');
                        }
                    } catch {
                        showBannerError('Network error. Please check your connection and try again.');
                    } finally {
                        if (createUserSubmit) {
                            createUserSubmit.disabled = false;
                            createUserSubmit.classList.remove('is-disabled');
                        }
                    }
                });
            }
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tcModal = document.getElementById('truckClassModal');
            if (!tcModal) return;

            const tcStoreUrl = '{{ route('superadmin.truck-types.store') }}';
            const tcIndexUrl = '{{ route('superadmin.truck-types.index') }}';
            const tcBaseUrl = '{{ url('superadmin/truck-types') }}';
            const csrfTk = document.querySelector('input[name="_token"]')?.value;

            const tcModalTitle = document.getElementById('tcModalTitle');
            const tcFormSectionTitle = document.getElementById('tcFormSectionTitle');
            const tcExistingList = document.getElementById('tcExistingList');
            const tcErrorBox = document.getElementById('tcErrorBox');
            const tcForm = document.getElementById('tcModalForm');
            const tcEditId = document.getElementById('tcEditId');
            const tcName = document.getElementById('tcName');
            const tcBaseRate = document.getElementById('tcBaseRate');
            const tcPerKm = document.getElementById('tcPerKm');
            const tcKilo = document.getElementById('tcKilo');
            const tcDescription = document.getElementById('tcDescription');
            const tcDescCounter = document.getElementById('tcDescCounter');
            const tcSubmitBtn = document.getElementById('tcSubmitBtn');
            const tcCancelBtn = document.getElementById('tcCancelBtn');

            const escHtml = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const escAttr = s => String(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;');

            const resetForm = () => {
                tcEditId.value = '';
                tcName.value = '';
                tcBaseRate.value = '';
                tcPerKm.value = '';
                tcKilo.value = '';
                tcDescription.value = '';
                tcDescCounter.textContent = '0 / 100';
                tcErrorBox.style.display = 'none';
                tcErrorBox.textContent = '';
                tcSubmitBtn.textContent = 'Add';
                tcModalTitle.textContent = 'Add Truck Class';
                tcFormSectionTitle.textContent = 'New Truck Class';
            };

            const PER_PAGE = 5;
            let tcAllTypes = [];
            let tcCurrentPage = 1;
            const tcPagination = document.getElementById('tcPagination');

            const fillEditForm = (btn) => {
                tcEditId.value = btn.dataset.id;
                tcName.value = btn.dataset.name;
                tcBaseRate.value = btn.dataset.base;
                tcPerKm.value = btn.dataset.perkm;
                tcKilo.value = btn.dataset.kilo;
                tcDescription.value = btn.dataset.desc;
                const dLen = btn.dataset.desc.length;
                tcDescCounter.textContent = dLen + ' / 100' + (dLen >= 100 ? ' (max)' : '');
                tcSubmitBtn.textContent = 'Edit';
                tcModalTitle.textContent = 'Edit Truck Class';
                tcFormSectionTitle.textContent = 'Edit Truck Class';
                tcErrorBox.style.display = 'none';
                tcName.focus();
            };

            const renderPage = (page) => {
                tcCurrentPage = page;
                const start = (page - 1) * PER_PAGE;
                const slice = tcAllTypes.slice(start, start + PER_PAGE);

                const tbl = document.createElement('table');
                tbl.className = 'tc-table';
                tbl.innerHTML =
                    '<thead><tr><th>Name</th><th>Base Rate</th><th>Per KM</th><th>Kilo</th><th></th></tr></thead><tbody></tbody>';
                const tbody = tbl.querySelector('tbody');
                slice.forEach(t => {
                    const tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td>' + escHtml(t.name) + '</td>' +
                        '<td>' + (t.base_rate ?? '-') + '</td>' +
                        '<td>' + (t.per_km_rate ?? '-') + '</td>' +
                        '<td>' + (t.max_tonnage ?? '-') + '</td>' +
                        '<td style="white-space:nowrap;">' +
                        '<button type="button" class="tc-edit-row-btn"' +
                        ' data-id="' + t.id + '"' +
                        ' data-name="' + escAttr(t.name) + '"' +
                        ' data-base="' + (t.base_rate ?? '') + '"' +
                        ' data-perkm="' + (t.per_km_rate ?? '') + '"' +
                        ' data-kilo="' + (t.max_tonnage ?? '') + '"' +
                        ' data-desc="' + escAttr(t.description ?? '') + '">Edit</button>' +
                        '<button type="button" class="tc-remove-row-btn" data-id="' + t.id +
                        '" data-name="' + escAttr(t.name) + '">Remove</button>' +
                        '</td>';
                    tbody.appendChild(tr);
                });
                tbl.querySelectorAll('.tc-edit-row-btn').forEach(btn => {
                    btn.addEventListener('click', () => fillEditForm(btn));
                });
                tbl.querySelectorAll('.tc-remove-row-btn').forEach(btn => {
                    btn.addEventListener('click', () => removeTruckClass(btn.dataset.id, btn.dataset
                        .name));
                });
                tcExistingList.innerHTML = '';
                tcExistingList.appendChild(tbl);

                const totalPages = Math.ceil(tcAllTypes.length / PER_PAGE);
                tcPagination.innerHTML = '';
                if (totalPages > 1) {
                    const prevBtn = document.createElement('button');
                    prevBtn.type = 'button';
                    prevBtn.className = 'tc-page-btn';
                    prevBtn.textContent = 'Prev';
                    prevBtn.disabled = page === 1;
                    prevBtn.addEventListener('click', () => renderPage(page - 1));
                    tcPagination.appendChild(prevBtn);

                    for (let i = 1; i <= totalPages; i++) {
                        const pb = document.createElement('button');
                        pb.type = 'button';
                        pb.className = 'tc-page-btn' + (i === page ? ' active' : '');
                        pb.textContent = i;
                        pb.addEventListener('click', () => renderPage(i));
                        tcPagination.appendChild(pb);
                    }

                    const nextBtn = document.createElement('button');
                    nextBtn.type = 'button';
                    nextBtn.className = 'tc-page-btn';
                    nextBtn.textContent = 'Next';
                    nextBtn.disabled = page === totalPages;
                    nextBtn.addEventListener('click', () => renderPage(page + 1));
                    tcPagination.appendChild(nextBtn);
                }
            };

            const tcConfirmModal = document.getElementById('tcConfirmModal');
            const tcConfirmMsg = document.getElementById('tcConfirmMsg');
            const tcConfirmOk = document.getElementById('tcConfirmOk');
            const tcConfirmCancel = document.getElementById('tcConfirmCancel');

            let tcConfirmResolve = null;
            const openConfirm = (msg) => new Promise(resolve => {
                tcConfirmMsg.textContent = msg;
                tcConfirmModal.style.display = 'block';
                tcConfirmResolve = resolve;
            });

            tcConfirmOk?.addEventListener('click', () => {
                tcConfirmModal.style.display = 'none';
                if (tcConfirmResolve) {
                    tcConfirmResolve(true);
                    tcConfirmResolve = null;
                }
            });
            tcConfirmCancel?.addEventListener('click', () => {
                tcConfirmModal.style.display = 'none';
                if (tcConfirmResolve) {
                    tcConfirmResolve(false);
                    tcConfirmResolve = null;
                }
            });

            const removeTruckClass = async (id, name) => {
                const confirmed = await openConfirm('Remove truck class "' + name +
                    '"? This cannot be undone.');
                if (!confirmed) return;
                tcErrorBox.style.display = 'none';
                try {
                    const res = await fetch(tcBaseUrl + '/' + id, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfTk,
                        },
                    });
                    const data = await res.json();
                    if (res.ok && data.success) {
                        tcAllTypes = tcAllTypes.filter(t => String(t.id) !== String(id));
                        // If current page is now empty and not first, go back one
                        const totalPages = Math.ceil(tcAllTypes.length / PER_PAGE);
                        const goTo = tcCurrentPage > totalPages && totalPages > 0 ? totalPages :
                            tcCurrentPage || 1;
                        if (tcAllTypes.length === 0) {
                            tcExistingList.innerHTML = '<p class="tc-empty-note">No truck classes yet.</p>';
                            if (tcPagination) tcPagination.innerHTML = '';
                        } else {
                            renderPage(goTo);
                        }
                        // If we were editing this item, reset the form
                        if (tcEditId.value === String(id)) resetForm();
                    } else {
                        tcErrorBox.textContent = data.message || 'Could not remove truck class.';
                        tcErrorBox.style.display = 'block';
                    }
                } catch {
                    tcErrorBox.textContent = 'Network error. Please try again.';
                    tcErrorBox.style.display = 'block';
                }
            };

            const loadExisting = async () => {
                tcExistingList.innerHTML = '<p class="tc-empty-note">Loading...</p>';
                if (tcPagination) tcPagination.innerHTML = '';
                try {
                    const res = await fetch(tcIndexUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfTk
                        }
                    });
                    const types = await res.json();
                    if (!Array.isArray(types) || !types.length) {
                        tcExistingList.innerHTML = '<p class="tc-empty-note">No truck classes yet.</p>';
                        tcAllTypes = [];
                        return;
                    }
                    tcAllTypes = types;
                    renderPage(1);
                } catch {
                    tcExistingList.innerHTML = '<p class="tc-empty-note">Failed to load.</p>';
                }
            };

            tcDescription?.addEventListener('input', () => {
                const len = tcDescription.value.length;
                tcDescCounter.textContent = len + ' / 100' + (len >= 100 ? ' (max)' : '');
            });

            document.getElementById('openTruckClassModal')?.addEventListener('click', () => {
                resetForm();
                tcModal.style.display = 'block';
                loadExisting();
            });

            tcCancelBtn?.addEventListener('click', () => {
                tcModal.style.display = 'none';
                resetForm();
            });

            tcModal.addEventListener('click', e => {
                if (e.target === tcModal) {
                    tcModal.style.display = 'none';
                    resetForm();
                }
            });

            tcForm?.addEventListener('submit', async e => {
                e.preventDefault();
                tcErrorBox.style.display = 'none';
                tcErrorBox.textContent = '';

                const id = tcEditId.value;
                const payload = {
                    name: tcName.value.trim(),
                    base_rate: tcBaseRate.value,
                    per_km_rate: tcPerKm.value,
                    max_tonnage: tcKilo.value || null,
                    description: tcDescription.value.trim() || null,
                };

                if (!payload.name || !payload.base_rate || !payload.per_km_rate) {
                    tcErrorBox.textContent = 'Truck Class Name, Base Rate, and Per KM are required.';
                    tcErrorBox.style.display = 'block';
                    return;
                }

                tcSubmitBtn.disabled = true;
                try {
                    const url = id ? (tcBaseUrl + '/' + id) : tcStoreUrl;
                    const method = id ? 'PUT' : 'POST';
                    const res = await fetch(url, {
                        method,
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfTk,
                        },
                        body: JSON.stringify(payload),
                    });
                    const data = await res.json();
                    if (res.ok && data.success) {
                        resetForm();
                        loadExisting();
                    } else if (data.errors) {
                        tcErrorBox.textContent = Object.values(data.errors).flat().join(' ');
                        tcErrorBox.style.display = 'block';
                    } else {
                        tcErrorBox.textContent = data.message || 'Something went wrong.';
                        tcErrorBox.style.display = 'block';
                    }
                } catch {
                    tcErrorBox.textContent = 'Network error. Please try again.';
                    tcErrorBox.style.display = 'block';
                } finally {
                    tcSubmitBtn.disabled = false;
                }
            });
        });
    </script>
@endpush
