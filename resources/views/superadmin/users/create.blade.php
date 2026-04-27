@extends('layouts.superadmin')

@section('title', isset($user) ? 'Edit User' : 'Add User')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/user-create.css') }}">
    <style>
        /* ── Phone input with country-code prefix ───── */
        .phone-input-wrap {
            display: flex;
            align-items: center;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
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

        /* ── Back navigation ─────────────────────────── */
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

        /* ── Role chooser ─────────────────────────────── */
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
            border-radius: 14px;
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
            border-radius: 12px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #374151;
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
            border-radius: 999px;
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

        /* ── Back-to-chooser button (inside form) ─────── */
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

        /* ── Section divider titles ──────────────────── */
        .form-section-title {
            margin: 22px 0 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            color: #9ca3af;
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

        {{-- Back to Users list ─────────────────────────── --}}
        <a href="{{ route('superadmin.users.index') }}" class="page-back-nav">
            <i data-lucide="arrow-left"></i>
            Back to Users
        </a>

        <div class="form-wrapper" style="margin-top:0;">
            <div class="form-card">

                {{-- ═══════════════════════════════════
         STEP 1 — Role Chooser (create only)
    ═══════════════════════════════════ --}}
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
                                <div class="rcc-icon-wrap">
                                    <i data-lucide="radio" style="width:26px;height:26px;"></i>
                                </div>
                                <div class="rcc-body">
                                    <strong>Dispatcher</strong>
                                    <span>Admin who manages bookings and dispatches field teams</span>
                                </div>
                                <div class="rcc-arrow">
                                    <i data-lucide="chevron-right" style="width:17px;height:17px;color:#9ca3af;"></i>
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

                {{-- ═══════════════════════════════════
         STEP 2 — The Form
    ═══════════════════════════════════ --}}
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
                                    {{-- Reload after validation error: restore label --}}
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

                        {{-- Hidden role_id — pre-filled from old() on validation-error reload --}}
                        @if ($isEdit)
                            <input type="hidden" name="role_id" value="{{ $user->role_id }}">
                        @else
                            <input type="hidden" name="role_id" id="hiddenRoleId" value="{{ old('role_id', '') }}">
                        @endif

                        {{-- Ajax error banner — only relevant in edit mode (AJAX submit) --}}
                        @if ($isEdit)
                            <div id="ajaxErrorBanner" class="ajax-error-banner" hidden>
                                <span id="ajaxErrorText"></span>
                            </div>
                        @endif

                        {{-- ─── Personal Information ──────────────── --}}
                        <div class="form-section-title">Personal Information</div>

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

                        <div class="form-group">
                            <label>Phone Number <span class="field-optional">Optional</span></label>
                            <div class="phone-input-wrap">
                                <span class="phone-cc">+63</span>
                                <input type="tel" name="phone" id="phoneInput"
                                    value="{{ old('phone', $user->phone ?? '') }}" placeholder="9XXXXXXXXX"
                                    maxlength="11" inputmode="numeric" autocomplete="tel">
                            </div>
                            <small class="field-note">Numbers only · start with 9 or 09 · max 11 digits</small>
                            @error('phone')
                                <small class="error-text">{{ $message }}</small>
                            @enderror
                        </div>

                        {{-- Role display on edit (locked) --}}
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

                        {{-- ─── Password ────────────────────────────── --}}
                        <div class="form-section-title">
                            {{ $isEdit ? 'Change Password' : 'Password' }}
                            @if ($isEdit)
                                <span class="field-optional" style="font-size:0.78rem;margin-left:6px;">Optional — leave
                                    blank to keep current</span>
                            @endif
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Password {!! $isEdit ? '' : '<span class="required-mark">*</span>' !!}</label>
                                <input type="password" name="password" id="passwordInput"
                                    {{ $isEdit ? '' : 'required' }}>
                                @error('password')
                                    <small class="error-text">{{ $message }}</small>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label>Confirm Password {!! $isEdit ? '' : '<span class="required-mark">*</span>' !!}</label>
                                <input type="password" name="password_confirmation" {{ $isEdit ? '' : 'required' }}>
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
                                <li data-rule="special"><span class="requirement-icon">•</span><span>atleast one special
                                        characters</span></li>
                            </ul>
                        </div>

                        {{-- ─── Status ──────────────────────────────── --}}
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

                        {{-- ─── Team Leader: Driver Details ─────────── --}}
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
                                        <label>Middle Name <span class="field-optional">Optional</span></label>
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

                        {{-- ─── Team Leader: Unit Details ───────────── --}}
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
                                    <label>Truck Type <span class="required-mark">*</span></label>
                                    <div class="ttc-cards" id="truckTypeCards">
                                        @forelse ($truckTypes as $truckType)
                                            <div class="ttc-card" data-type="{{ $truckType->name }}"
                                                data-configured="false">
                                                <div class="ttc-card-body">
                                                    <strong class="ttc-card-name">{{ $truckType->name }}</strong>
                                                    <span class="ttc-card-label">Loading…</span>
                                                </div>
                                                <div class="ttc-card-actions">
                                                    <button type="button" class="ttc-card-edit-btn" hidden>Edit</button>
                                                </div>
                                            </div>
                                        @empty
                                            <p class="field-note">No active truck types found. <a
                                                    href="{{ route('superadmin.truck-types.index') }}"
                                                    target="_blank">Add truck types</a> first.</p>
                                        @endforelse
                                    </div>
                                    <a href="{{ route('superadmin.truck-types.index') }}" target="_blank"
                                        class="manage-truck-types-link">+ Manage Truck Types</a>
                                    <input type="hidden" name="unit_truck_class" id="truckTypeHidden"
                                        value="{{ old('unit_truck_class', $user->unit?->truckType?->name ?? '') }}">
                                    <small id="truckTypeSelectionError" class="error-text" hidden>Please select and
                                        configure a truck type.</small>
                                    @error('unit_truck_class')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        {{-- ─── Actions ──────────────────────────────── --}}
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
        {{-- ═══════════════════════════════════
         Truck Type Config Modal
    ═══════════════════════════════════ --}}
        <div id="truckTypeConfigModal" class="ttc-overlay" hidden>
            <div class="ttc-modal">
                <div class="ttc-modal-header">
                    <h3 id="ttcModalTitle">Configure Truck Type</h3>
                    <p>Set pricing and capacity before this type can be used.</p>
                </div>
                <div class="ttc-modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Base Rate (₱) <span class="required-mark">*</span></label>
                            <input type="number" id="ttcBaseRate" min="0" step="0.01" placeholder="e.g. 4500">
                            <small class="ttc-hint">Flat charge for the first 4 km</small>
                        </div>
                        <div class="form-group">
                            <label>Per 4km Rate (₱) <span class="required-mark">*</span></label>
                            <input type="number" id="ttcPerKmRate" min="0" step="0.01" placeholder="e.g. 200">
                            <small class="ttc-hint">Added for every 4 km beyond the base</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Capacity (kg) <span class="required-mark">*</span></label>
                        <input type="number" id="ttcCapacity" min="0" step="1" placeholder="e.g. 3000">
                    </div>
                    <div class="form-group">
                        <label>Description <span class="field-optional">Optional</span></label>
                        <input type="text" id="ttcDescription" placeholder="e.g. For medium-sized vehicles">
                    </div>
                    <div id="ttcError" class="ttc-error" hidden></div>
                </div>
                <div class="ttc-modal-footer">
                    <button type="button" id="ttcCancelBtn" class="btn-cancel">Cancel</button>
                    <button type="button" id="ttcSaveBtn" class="btn-primary-submit">Save Config</button>
                </div>
            </div>
        </div>

    </div>{{-- /create-user-page --}}

@endsection

@push('scripts')
    <script>
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

            // ── Submit block helper ───────────────────────────
            const setSubmitBlocked = (blocked) => {
                if (!createUserSubmit) return;
                createUserSubmit.disabled = blocked;
                createUserSubmit.classList.toggle('is-disabled', blocked);
            };

            // ── Role chooser ──────────────────────────────────
            let selectedIsTL = false;

            // If page reloaded after validation error, detect whether TL was selected
            if (!isEditMode && hiddenRoleId?.value) {
                selectedIsTL = Number(hiddenRoleId.value) === tlRoleId;
            }

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

                if (isTL) {
                    loadAllConfigs();
                    const sel = truckTypeHidden?.value;
                    truckTypeConfigured = sel ? (configCache[sel]?.configured === true) : false;
                    setSubmitBlocked(!truckTypeConfigured);
                } else {
                    setSubmitBlocked(false);
                }

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

            // If page reloaded with old data (validation error), re-load TL configs if needed
            if (!isEditMode && selectedIsTL) {
                loadAllConfigs?.();
            }

            // ── Password requirements ─────────────────────────
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

            // ── Phone: digits only, 9/09 start, max 11 ──────
            const phoneInput = document.getElementById('phoneInput');
            if (phoneInput) {
                const cleanPhone = () => {
                    let v = phoneInput.value.replace(/\D/g, '');
                    // cap: 11 if starts with 09, 10 if starts with 9 (and not 09)
                    if (v.startsWith('09')) v = v.slice(0, 11);
                    else v = v.slice(0, 10);
                    if (phoneInput.value !== v) phoneInput.value = v;
                };

                phoneInput.addEventListener('input', cleanPhone);

                phoneInput.addEventListener('keydown', (e) => {
                    const allowed = [
                        'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                        'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End',
                    ];
                    if (allowed.includes(e.key)) return;
                    if (!/^\d$/.test(e.key)) e.preventDefault();
                });

                // Paste: strip non-digits
                phoneInput.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,
                        '');
                    const cur = phoneInput.value;
                    const next = (cur + pasted).replace(/\D/g, '');
                    phoneInput.value = next.startsWith('09') ? next.slice(0, 11) : next.slice(0, 10);
                });
            }

            // ── Truck Type card selector ──────────────────────
            let truckTypeConfigured = false;
            let currentTruckTypeName = '';
            let configsLoaded = false;

            const configCache = {};
            const typeCardMap = {};

            const truckTypeHidden = document.getElementById('truckTypeHidden');
            const truckTypeCards = document.getElementById('truckTypeCards');
            const truckTypeSelectionError = document.getElementById('truckTypeSelectionError');
            const configModal = document.getElementById('truckTypeConfigModal');
            const ttcModalTitle = document.getElementById('ttcModalTitle');
            const ttcBaseRate = document.getElementById('ttcBaseRate');
            const ttcPerKmRate = document.getElementById('ttcPerKmRate');
            const ttcCapacity = document.getElementById('ttcCapacity');
            const ttcDescription = document.getElementById('ttcDescription');
            const ttcError = document.getElementById('ttcError');
            const ttcSaveBtn = document.getElementById('ttcSaveBtn');
            const ttcCancelBtn = document.getElementById('ttcCancelBtn');
            const csrfToken = document.querySelector('input[name="_token"]')?.value;

            const fmt = v => v != null ?
                `₱${Number(v).toLocaleString('en-PH', { minimumFractionDigits: 0 })}` :
                '—';

            const renderCard = (name) => {
                const card = typeCardMap[name];
                const config = configCache[name];
                if (!card) return;
                const label = card.querySelector('.ttc-card-label');
                const editBtn = card.querySelector('.ttc-card-edit-btn');
                if (!config || !config.configured) {
                    label.textContent = 'Tap to configure';
                    label.className = 'ttc-card-label';
                    card.dataset.configured = 'false';
                    if (editBtn) editBtn.hidden = true;
                } else {
                    label.textContent =
                        `${fmt(config.base_rate)} base · ${fmt(config.per_km_rate)}/4km · ${config.capacity ?? '—'} kg`;
                    label.className = 'ttc-card-label is-configured';
                    card.dataset.configured = 'true';
                    if (editBtn) editBtn.hidden = false;
                }
                if (truckTypeHidden?.value === name) highlightCard(name);
            };

            const highlightCard = (name) => {
                Object.values(typeCardMap).forEach(c => c.classList.remove('selected'));
                typeCardMap[name]?.classList.add('selected');
            };

            const selectCard = (name) => {
                highlightCard(name);
                if (truckTypeHidden) truckTypeHidden.value = name;
                if (truckTypeSelectionError) truckTypeSelectionError.hidden = true;
                truckTypeConfigured = configCache[name]?.configured === true;
                if (sectionUnitDetails && !sectionUnitDetails.hidden) {
                    setSubmitBlocked(!truckTypeConfigured);
                }
            };

            const openConfigModal = (name, existing = null) => {
                currentTruckTypeName = name;
                if (ttcModalTitle) ttcModalTitle.textContent = `Configure ${name}`;
                if (ttcBaseRate) ttcBaseRate.value = existing?.base_rate ?? '';
                if (ttcPerKmRate) ttcPerKmRate.value = existing?.per_km_rate ?? '';
                if (ttcCapacity) ttcCapacity.value = existing?.capacity ?? '';
                if (ttcDescription) ttcDescription.value = existing?.description ?? '';
                if (ttcError) ttcError.hidden = true;
                if (configModal) configModal.hidden = false;
            };

            const allTruckTypeNames = @json($truckTypes->pluck('name'));

            const loadAllConfigs = async () => {
                if (configsLoaded) return;
                configsLoaded = true;
                await Promise.all(allTruckTypeNames.map(async (name) => {
                    const label = typeCardMap[name]?.querySelector('.ttc-card-label');
                    if (label) label.textContent = 'Loading…';
                    try {
                        const res = await fetch(
                            `/superadmin/truck-type-config/${encodeURIComponent(name)}`, {
                                headers: {
                                    'Accept': 'application/json'
                                },
                            });
                        configCache[name] = await res.json();
                    } catch {
                        configCache[name] = {
                            configured: false
                        };
                    }
                    renderCard(name);
                }));
                const sel = truckTypeHidden?.value;
                if (sel) {
                    truckTypeConfigured = configCache[sel]?.configured === true;
                    setSubmitBlocked(!truckTypeConfigured);
                }
            };

            truckTypeCards?.querySelectorAll('.ttc-card').forEach(card => {
                const name = card.dataset.type;
                typeCardMap[name] = card;

                card.addEventListener('click', (e) => {
                    if (e.target.closest('.ttc-card-edit-btn')) return;
                    card.dataset.configured === 'true' ? selectCard(name) : openConfigModal(name,
                        null);
                });

                card.querySelector('.ttc-card-edit-btn')?.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openConfigModal(name, configCache[name]);
                });
            });

            if (isTLEditMode) {
                loadAllConfigs();
                setSubmitBlocked(false);
            }

            ttcCancelBtn?.addEventListener('click', () => {
                if (configModal) configModal.hidden = true;
                if (ttcError) ttcError.hidden = true;
                if (!truckTypeHidden?.value) {
                    truckTypeConfigured = false;
                    setSubmitBlocked(true);
                }
            });

            ttcSaveBtn?.addEventListener('click', async () => {
                const base_rate = ttcBaseRate?.value.trim() ?? '';
                const per_km_rate = ttcPerKmRate?.value.trim() ?? '';
                const capacity = ttcCapacity?.value.trim() ?? '';

                if (!base_rate || !per_km_rate || !capacity) {
                    if (ttcError) {
                        ttcError.textContent = 'Base Rate, Per 4km Rate, and Capacity are required.';
                        ttcError.hidden = false;
                    }
                    return;
                }

                if (ttcSaveBtn) ttcSaveBtn.disabled = true;
                if (ttcError) ttcError.hidden = true;

                try {
                    const res = await fetch(
                        `/superadmin/truck-type-config/${encodeURIComponent(currentTruckTypeName)}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify({
                                base_rate,
                                per_km_rate,
                                capacity,
                                description: ttcDescription?.value.trim() || null
                            }),
                        });
                    const data = await res.json();

                    if (data.success) {
                        configCache[currentTruckTypeName] = {
                            configured: true,
                            base_rate: parseFloat(base_rate),
                            per_km_rate: parseFloat(per_km_rate),
                            capacity: parseFloat(capacity),
                            description: ttcDescription?.value.trim() || null,
                        };
                        renderCard(currentTruckTypeName);
                        selectCard(currentTruckTypeName);
                        if (configModal) configModal.hidden = true;
                    } else {
                        if (ttcError) {
                            ttcError.textContent = 'Failed to save config. Please try again.';
                            ttcError.hidden = false;
                        }
                    }
                } catch {
                    if (ttcError) {
                        ttcError.textContent = 'Network error. Please try again.';
                        ttcError.hidden = false;
                    }
                } finally {
                    if (ttcSaveBtn) ttcSaveBtn.disabled = false;
                }
            });

            // ── Form submit guard (create mode) ──────────────
            form?.addEventListener('submit', (e) => {
                if (isEditMode) return;
                if (selectedIsTL && !truckTypeHidden?.value) {
                    e.preventDefault();
                    if (truckTypeSelectionError) truckTypeSelectionError.hidden = false;
                    truckTypeCards?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            });

            // ── AJAX submit (edit mode only) ──────────────────
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
                            sessionStorage.setItem('sa_flash_success', data.message ||
                                'User updated successfully.');
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
