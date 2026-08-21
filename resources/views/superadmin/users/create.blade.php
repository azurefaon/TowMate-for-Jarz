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

    </style>
@endpush

@php
    $isEdit = isset($user);
    $isTLEdit = $isEdit && ($user->role->name ?? '') === 'Team Leader';
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

                        <div class="form-row">
                            <div class="form-group">
                                <label>Email Address @unless($isEdit)<span class="required-mark">*</span>@endunless</label>
                                @if ($isEdit)
                                    <div class="locked-field">
                                        <span>{{ $user->email }}</span>
                                        <span class="locked-badge">Locked</span>
                                    </div>
                                    <small class="field-note">Email cannot be changed after account creation.</small>
                                @else
                                    <input type="email" name="email" value="{{ old('email', '') }}"
                                        placeholder="name@gmail.com" required>
                                    @error('email')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                @endif
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
                                            value="{{ old('driver_first_name', $user->driver_first_name ?? '') }}"
                                            placeholder="Driver first name"
                                            @if ($showTLSections) required @endif>
                                        @error('driver_first_name')
                                            <small class="error-text">{{ $message }}</small>
                                        @enderror
                                    </div>
                                    <div class="form-group">
                                        <label>Middle Name <span class="field-optional">optional</span></label>
                                        <input type="text" name="driver_middle_name"
                                            value="{{ old('driver_middle_name', $user->driver_middle_name ?? '') }}"
                                            placeholder="Driver middle name">
                                    </div>
                                    <div class="form-group">
                                        <label>Last Name <span class="required-mark">*</span></label>
                                        <input type="text" name="driver_last_name"
                                            value="{{ old('driver_last_name', $user->driver_last_name ?? '') }}"
                                            placeholder="Driver last name"
                                            @if ($showTLSections) required @endif>
                                        @error('driver_last_name')
                                            <small class="error-text">{{ $message }}</small>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="sectionCrewMembers" class="role-section-box"
                            @if (!$showTLSections) hidden @endif>
                            <div class="role-section-header"><span>Crew Members</span></div>
                            <div class="role-section-body">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Crew Member 1 <span class="field-optional">optional</span></label>
                                        <input type="text" name="crew_member_1_name"
                                            value="{{ old('crew_member_1_name', $user->crew_member_1_name ?? '') }}"
                                            placeholder="Pahinante 1 name">
                                    </div>
                                    <div class="form-group">
                                        <label>Crew Member 2 <span class="field-optional">optional</span></label>
                                        <input type="text" name="crew_member_2_name"
                                            value="{{ old('crew_member_2_name', $user->crew_member_2_name ?? '') }}"
                                            placeholder="Pahinante 2 name">
                                    </div>
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
            const tlRoleId = Number(form?.dataset.tlRoleId || 0);

            const roleChooser = document.getElementById('roleChooser');
            const formSection = document.getElementById('formSection');
            const backToChooser = document.getElementById('backToChooser');
            const hiddenRoleId = document.getElementById('hiddenRoleId');
            const formRoleHeadline = document.getElementById('formRoleHeadline');
            const createUserSubmit = document.getElementById('createUserSubmit');

            const sectionDriverDetails = document.getElementById('sectionDriverDetails');
            const sectionCrewMembers = document.getElementById('sectionCrewMembers');

            const phoneInput = document.getElementById('phoneInput');
            const phoneRequiredMark = document.getElementById('phoneRequiredMark');
            const phoneOptionalMark = document.getElementById('phoneOptionalMark');

            const csrfToken = document.querySelector('input[name="_token"]')?.value;

            let selectedIsTL = false;
            if (!isEditMode && hiddenRoleId?.value) {
                selectedIsTL = Number(hiddenRoleId.value) === tlRoleId;
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
                if (sectionCrewMembers) sectionCrewMembers.hidden = !isTL;

                ['driver_first_name', 'driver_last_name'].forEach(n => {
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
                }
            }

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
@endpush
