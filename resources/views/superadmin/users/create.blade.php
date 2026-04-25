@extends('layouts.superadmin')

@section('title', 'Add User')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/user-create.css') }}">
@endpush

@php
    $isEdit = isset($user);
    $isTLEdit = $isEdit && ($user->role->name ?? '') === 'Team Leader';
    $driverParts = $isTLEdit ? split_full_name($user->unit?->driver_name ?? '') : [];
@endphp

@section('content')

    <div class="create-user-page">

        <div class="form-wrapper">

            <div class="form-card">

                <div class="form-header">
                    <h2>{{ $isEdit ? 'Edit User' : 'Add User' }}</h2>
                    <p>{{ $isEdit ? 'Update user details' : 'Register new employee or admin' }}</p>
                </div>

                <div class="teamleader-capacity-card">
                    <div>
                        <strong>Team Leader Capacity</strong>
                        <p>The current Team Leader slot usage updates from Super Admin settings.</p>
                    </div>
                    <span
                        class="teamleader-capacity-badge">{{ ($teamLeaderCapacity['count'] ?? 0) . ' / ' . ($teamLeaderCapacity['limit'] ?? 10) }}</span>
                </div>

                <p id="teamLeaderCapacityHint"
                    class="teamleader-capacity-hint {{ !empty($teamLeaderCapacity['reached']) ? 'limit-reached' : '' }}">
                    @if (!empty($teamLeaderCapacity['reached']))
                        Team Leader limit reached. Increase the maximum in System Settings before creating another Team
                        Leader account.
                    @else
                        {{-- You can still add {{ $teamLeaderCapacity['remaining'] ?? 0 }} more Team Leader account(s). --}}
                    @endif
                </p>

                <form method="POST"
                    action="{{ $isEdit ? route('superadmin.users.update', $user->id) : route('superadmin.users.store') }}"
                    class="create-user-form" data-is-edit="{{ $isEdit ? 'true' : 'false' }}"
                    data-is-tl-edit="{{ $isTLEdit ? 'true' : 'false' }}">
                    @csrf

                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <div id="ajaxErrorBanner" class="ajax-error-banner" hidden>
                        
                        <span id="ajaxErrorText"></span>
                    </div>

                    <div id="sectionTeamLeaderDetails" class="role-section-box" hidden>
                        <div class="role-section-header">
                            
                            <span>Team Leader Details</span>
                        </div>
                    </div>

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
                            placeholder="john@gmail.com" required>
                        @error('email')
                            <small class="error-text">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label>Phone Number <span class="field-optional">Optional</span></label>
                        <input type="text" name="phone" value="{{ old('phone', $user->phone ?? '') }}"
                            placeholder="09XX XXX XXXX">
                        <small class="field-note">Philippine mobile number (e.g. 09171234567 or +639171234567)</small>
                        @error('phone')
                            <small class="error-text">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="form-row">

                        <div class="form-group">
                            <label>Password <span class="required-mark">*</span></label>

                            <input type="password" name="password" {{ $isEdit ? '' : 'required' }}>
                            @error('password')
                                <small class="error-text">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>
                                {{-- Confirm Password {{ $isEdit ? '' : '<span class="required-mark">*</span>' }} --}}
                                Confirm Password {!! $isEdit ? '' : '<span class="required-mark">*</span>' !!}
                            </label>

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
                                    characters</span>
                            </li>
                            <li data-rule="uppercase"><span class="requirement-icon">•</span><span>Include uppercase
                                    letters</span></li>
                            <li data-rule="lowercase"><span class="requirement-icon">•</span><span>Include lowercase
                                    letters</span></li>
                            <li data-rule="number"><span class="requirement-icon">•</span><span>Include numbers</span>
                            </li>
                            <li data-rule="special"><span class="requirement-icon">•</span><span>Include special
                                    characters</span></li>
                        </ul>
                    </div>

                    <div class="form-row">

                        <div class="form-group">
                            <label>Role <span class="required-mark">*</span></label>

                            @if ($isEdit)
                                <input type="hidden" name="role_id" value="{{ $user->role_id }}">
                                <div class="locked-field">
                                    
                                    <span>{{ $user->role->name ?? '—' }}</span>
                                    <span class="locked-badge"> Locked</span>
                                </div>
                                <small class="field-note">Role cannot be changed after user creation.</small>
                            @else
                                <select name="role_id" id="roleSelect" required
                                    data-teamleader-role="{{ $teamLeaderCapacity['role_id'] ?? '' }}"
                                    data-teamleader-limit="{{ $teamLeaderCapacity['limit'] ?? 10 }}"
                                    data-teamleader-count="{{ $teamLeaderCapacity['count'] ?? 0 }}">
                                    <option value="">Select role</option>

                                    @foreach ($roles as $role)
                                        @php
                                            $teamLeaderLimitReached =
                                                (int) ($role->id ?? 0) ===
                                                    (int) ($teamLeaderCapacity['role_id'] ?? 0) &&
                                                !empty($teamLeaderCapacity['reached']);
                                        @endphp
                                        <option value="{{ $role->id }}"
                                            {{ (string) old('role_id') === (string) $role->id ? 'selected' : '' }}
                                            @disabled($teamLeaderLimitReached)>
                                            {{ $role->name }}{{ $teamLeaderLimitReached ? ' (Limit reached)' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('role_id')
                                    <small class="error-text">{{ $message }}</small>
                                @enderror
                            @endif
                        </div>

                        <div class="form-group">
                            <label>Status</label>

                            <select name="status">
                                <option value="active"
                                    {{ old('status', $user->status ?? 'active') === 'active' ? 'selected' : '' }}>
                                    Active
                                </option>
                                <option value="inactive"
                                    {{ old('status', $user->status ?? '') === 'inactive' ? 'selected' : '' }}>Inactive
                                </option>
                            </select>
                        </div>

                    </div>

                    <div id="sectionDriverDetails" class="role-section-box"
                        @if (!$isTLEdit) hidden @endif>
                        <div class="role-section-header">
                            
                            <span>Driver Details</span>
                        </div>
                        <div class="role-section-body">

                            <div class="form-row">
                                <div class="form-group">
                                    <label>First Name <span class="required-mark">*</span></label>
                                    <input type="text" name="driver_first_name"
                                            value="{{ old('driver_first_name', $driverParts['first_name'] ?? '') }}"
                                            placeholder="Driver first name">
                                    @error('driver_first_name')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="form-group">
                                    <label>Middle Name <span class="field-optional">Optional</span></label>
                                    <input type="text" name="driver_middle_name"
                                            value="{{ old('driver_middle_name', $driverParts['middle_name'] ?? '') }}"
                                            placeholder="Driver middle name">
                                    @error('driver_middle_name')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="form-group">
                                    <label>Last Name <span class="required-mark">*</span></label>
                                    <input type="text" name="driver_last_name"
                                            value="{{ old('driver_last_name', $driverParts['last_name'] ?? '') }}"
                                            placeholder="Driver last name">
                                    @error('driver_last_name')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                            </div>

                        </div>
                    </div>

                    <div id="sectionUnitDetails" class="role-section-box" @if (!$isTLEdit) hidden @endif>
                        <div class="role-section-header">
                            
                            <span>Unit Details</span>
                        </div>
                        <div class="role-section-body">

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Unit Name <span class="required-mark">*</span></label>
                                    <input type="text" name="unit_name"
                                            value="{{ old('unit_name', $user->unit?->name ?? '') }}"
                                            placeholder="e.g. UNIT 1">
                                    @error('unit_name')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="form-group">
                                    <label>Plate Number <span class="required-mark">*</span></label>
                                    <input type="text" name="unit_plate_number"
                                            value="{{ old('unit_plate_number', $user->unit?->plate_number ?? '') }}"
                                            placeholder="e.g. ABC 1234" {{ $isEdit ? 'readonly' : '' }}>
                                    @error('unit_plate_number')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Truck Type <span class="required-mark">*</span></label>
                                <div class="ttc-cards" id="truckTypeCards" style="display:flex;flex-direction:column;gap:8px;">
                                    @forelse ($truckTypes as $truckType)
                                        <div class="ttc-card" data-type="{{ $truckType->name }}" data-configured="false">
                                            <div class="ttc-card-body">
                                                <strong class="ttc-card-name">{{ $truckType->name }}</strong>
                                                <span class="ttc-card-label">Loading…</span>
                                            </div>
                                            <div class="ttc-card-actions">
                                                <button type="button" class="ttc-card-edit-btn" title="Edit pricing" hidden>Edit</button>
                                                <div class="ttc-card-tick" hidden>&#10003;</div>
                                            </div>
                                        </div>
                                    @empty
                                        <p class="field-note">No active truck types found. <a href="{{ route('superadmin.truck-types.index') }}" target="_blank">Add truck types</a> in the Truck Types page first.</p>
                                    @endforelse
                                </div>
                                <a href="{{ route('superadmin.truck-types.index') }}" target="_blank" class="manage-truck-types-link">+ Manage Truck Types</a>
                                <input type="hidden" name="unit_truck_class" id="truckTypeHidden"
                                    value="{{ old('unit_truck_class', $user->unit?->truckType?->name ?? '') }}">
                                <small id="truckTypeSelectionError" class="error-text" hidden>
                                    Please select and configure a truck type.
                                </small>
                                @error('unit_truck_class')
                                    <small class="error-text">{{ $message }}</small>
                                @enderror
                            </div>

                        </div>
                    </div>

                    <div class="form-actions">

                        <a href="{{ route('superadmin.users.index') }}" class="btn-cancel">
                            Cancel
                        </a>

                        <button type="submit" class="btn-primary-submit" id="createUserSubmit">
                            
                            {{ $isEdit ? 'Update User' : 'Register User' }}
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

    {{-- TRUCK TYPE CONFIG MODAL --}}
    <div id="truckTypeConfigModal" class="ttc-overlay" hidden>
        <div class="ttc-modal">
            <div class="ttc-modal-header">
                <div class="ttc-modal-icon"></div>
                <div>
                    <h3 id="ttcModalTitle">Configure Truck Type</h3>
                    <p>Set pricing and capacity before this type can be used.</p>
                </div>
            </div>
            <div class="ttc-modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Base Rate (₱) <span class="required-mark">*</span></label>
                        <input type="number" id="ttcBaseRate" min="0" step="0.01"
                                placeholder="e.g. 1000">
                        </div>
                        <small class="ttc-hint">Flat charge for up to 4 km</small>
                    </div>
                    <div class="form-group">
                        <label>Per KM Rate (₱) <span class="required-mark">*</span></label>
                        <input type="number" id="ttcPerKmRate" min="0" step="0.01"
                                placeholder="e.g. 200">
                        </div>
                        <small class="ttc-hint">Added per km beyond 4 km</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>Capacity (kg) <span class="required-mark">*</span></label>
                    <input type="number" id="ttcCapacity" min="0" step="1" placeholder="e.g. 3000">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description <span class="field-optional">Optional</span></label>
                    <input type="text" id="ttcDescription" placeholder="e.g. For medium-sized vehicles">
                    </div>
                </div>
                <div id="ttcError" class="ttc-error" hidden></div>
            </div>
            <div class="ttc-modal-footer">
                <button type="button" id="ttcCancelBtn" class="btn-cancel">Cancel</button>
                <button type="button" id="ttcSaveBtn" class="btn-primary-submit">
                     Save Config
                </button>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();

            const passwordInput = document.querySelector('input[name="password"]');
            const requirementsBox = document.getElementById('passwordRequirements');
            const roleSelect = document.getElementById('roleSelect');
            const createUserSubmit = document.getElementById('createUserSubmit');
            const teamLeaderCapacityHint = document.getElementById('teamLeaderCapacityHint');
            const sectionTLDetails = document.getElementById('sectionTeamLeaderDetails');
            const sectionDriverDetails = document.getElementById('sectionDriverDetails');
            const sectionUnitDetails = document.getElementById('sectionUnitDetails');

            if (!passwordInput || !requirementsBox) return;

            // ── Password requirements ────────────────────────
            const rules = {
                length: v => v.length >= 12,
                uppercase: v => /[A-Z]/.test(v),
                lowercase: v => /[a-z]/.test(v),
                number: v => /\d/.test(v),
                special: v => /[^A-Za-z0-9]/.test(v),
            };
            const syncRequirements = (input, box) => {
                const value = input.value || '';
                Object.entries(rules).forEach(([ruleName, validator]) => {
                    const item = box.querySelector(`[data-rule="${ruleName}"]`);
                    const icon = item?.querySelector('.requirement-icon');
                    const passed = validator(value);
                    item?.classList.toggle('met', passed);
                    if (icon) icon.textContent = passed ? '✓' : '•';
                });
            };
            const wirePasswordRequirements = (input, box) => {
                input.addEventListener('focus', () => {
                    box.hidden = false;
                    syncRequirements(input, box);
                });
                input.addEventListener('input', () => {
                    box.hidden = false;
                    syncRequirements(input, box);
                });
                input.addEventListener('blur', () => {
                    if (input.value.trim() === '') box.hidden = true;
                });
            };
            wirePasswordRequirements(passwordInput, requirementsBox);

            // ── Team leader capacity ─────────────────────────
            const syncTeamLeaderCapacity = () => {
                if (!roleSelect || !createUserSubmit || !teamLeaderCapacityHint) return;
                const tlRoleId = Number(roleSelect.dataset.teamleaderRole || 0);
                const tlLimit = Number(roleSelect.dataset.teamleaderLimit || 0);
                const tlCount = Number(roleSelect.dataset.teamleaderCount || 0);
                const isTL = Number(roleSelect.value || 0) === tlRoleId;
                const limitReached = tlCount >= tlLimit;

                if (isTL && limitReached) {
                    createUserSubmit.disabled = true;
                    createUserSubmit.classList.add('is-disabled');
                    teamLeaderCapacityHint.textContent =
                        'Team Leader limit reached. Increase the maximum in System Settings before creating another Team Leader account.';
                    teamLeaderCapacityHint.classList.add('limit-reached');
                } else {
                    createUserSubmit.disabled = false;
                    createUserSubmit.classList.remove('is-disabled');
                    if (isTL) teamLeaderCapacityHint.textContent =
                        `Team Leader slots remaining: ${Math.max(tlLimit - tlCount, 0)}.`;
                }
            };

            const setSubmitBlocked = (blocked) => {
                if (!createUserSubmit) return;
                createUserSubmit.disabled = blocked;
                createUserSubmit.classList.toggle('is-disabled', blocked);
            };

            // ── Truck Type card selector ─────────────────────
            let truckTypeConfigured = false;
            let currentTruckTypeName = '';
            let configsLoaded = false;

            const configCache = {}; // name → { configured, base_rate, per_km_rate, capacity, description }
            const typeCardMap = {}; // name → card DOM element

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

            const fmt = (v) => v != null ?
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
                        `${fmt(config.base_rate)} base · ${fmt(config.per_km_rate)}/km · ${config.capacity ?? '—'} kg`;
                    label.className = 'ttc-card-label is-configured';
                    card.dataset.configured = 'true';
                    if (editBtn) editBtn.hidden = false;
                }

                if (truckTypeHidden?.value === name) highlightCard(name);
            };

            const highlightCard = (name) => {
                Object.values(typeCardMap).forEach(c => {
                    c.classList.remove('selected');
                    const tick = c.querySelector('.ttc-card-tick');
                    if (tick) tick.hidden = true;
                });
                const card = typeCardMap[name];
                if (!card) return;
                card.classList.add('selected');
                const tick = card.querySelector('.ttc-card-tick');
                if (tick) tick.hidden = false;
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
                ttcModalTitle.textContent = `Configure ${name} Truck Type`;
                ttcBaseRate.value = existing?.base_rate ?? '';
                ttcPerKmRate.value = existing?.per_km_rate ?? '';
                ttcCapacity.value = existing?.capacity ?? '';
                ttcDescription.value = existing?.description ?? '';
                ttcError.hidden = true;
                configModal.hidden = false;
                lucide.createIcons();
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
                                }
                            });
                        configCache[name] = await res.json();
                    } catch {
                        configCache[name] = {
                            configured: false
                        };
                    }
                    renderCard(name);
                }));

                const selected = truckTypeHidden?.value;
                if (selected) {
                    truckTypeConfigured = configCache[selected]?.configured === true;
                    setSubmitBlocked(!truckTypeConfigured);
                }
            };

            // Wire up cards
            truckTypeCards?.querySelectorAll('.ttc-card').forEach(card => {
                const name = card.dataset.type;
                typeCardMap[name] = card;

                card.addEventListener('click', (e) => {
                    if (e.target.closest('.ttc-card-edit-btn')) return;
                    if (card.dataset.configured === 'true') {
                        selectCard(name);
                    } else {
                        openConfigModal(name, null);
                    }
                });

                card.querySelector('.ttc-card-edit-btn')?.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openConfigModal(name, configCache[name]);
                });
            });

            // Modal cancel
            ttcCancelBtn?.addEventListener('click', () => {
                configModal.hidden = true;
                ttcError.hidden = true;
                if (!truckTypeHidden?.value) {
                    truckTypeConfigured = false;
                    setSubmitBlocked(true);
                }
            });

            // Modal save
            ttcSaveBtn?.addEventListener('click', async () => {
                const base_rate = ttcBaseRate.value.trim();
                const per_km_rate = ttcPerKmRate.value.trim();
                const capacity = ttcCapacity.value.trim();

                if (!base_rate || !per_km_rate || !capacity) {
                    ttcError.textContent = 'Base Rate, Per KM Rate, and Capacity are required.';
                    ttcError.hidden = false;
                    return;
                }

                ttcSaveBtn.disabled = true;
                ttcError.hidden = true;

                try {
                    const res = await fetch(
                        `/superadmin/truck-type-config/${encodeURIComponent(currentTruckTypeName)}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({
                                base_rate,
                                per_km_rate,
                                capacity,
                                description: ttcDescription.value.trim() || null,
                            }),
                        }
                    );
                    const data = await res.json();

                    if (data.success) {
                        configCache[currentTruckTypeName] = {
                            configured: true,
                            base_rate: parseFloat(base_rate),
                            per_km_rate: parseFloat(per_km_rate),
                            capacity: parseFloat(capacity),
                            description: ttcDescription.value.trim() || null,
                        };
                        renderCard(currentTruckTypeName);
                        selectCard(currentTruckTypeName);
                        configModal.hidden = true;
                    } else {
                        ttcError.textContent = 'Failed to save config. Please try again.';
                        ttcError.hidden = false;
                    }
                } catch {
                    ttcError.textContent = 'Network error. Please try again.';
                    ttcError.hidden = false;
                } finally {
                    ttcSaveBtn.disabled = false;
                }
            });

            // ── Role sections sync (create mode only) ───────
            const form = document.querySelector('.create-user-form');
            const isEditMode = form?.dataset.isEdit === 'true';
            const isTLEditMode = form?.dataset.isTlEdit === 'true';

            const syncRoleSections = () => {
                if (isEditMode) return; // Sections already set by Blade on edit
                if (!roleSelect) return;
                const tlRoleId = Number(roleSelect.dataset.teamleaderRole || 0);
                const isTL = Number(roleSelect.value || 0) === tlRoleId;

                if (sectionTLDetails) sectionTLDetails.hidden = !isTL;
                if (sectionDriverDetails) sectionDriverDetails.hidden = !isTL;
                if (sectionUnitDetails) sectionUnitDetails.hidden = !isTL;

                ['driver_first_name', 'driver_last_name', 'unit_name', 'unit_plate_number'].forEach(n => {
                    const el = document.querySelector(`[name="${n}"]`);
                    if (!el) return;
                    isTL ? el.setAttribute('required', '') : el.removeAttribute('required');
                });

                if (isTL) {
                    loadAllConfigs();
                    const selected = truckTypeHidden?.value;
                    truckTypeConfigured = selected ? (configCache[selected]?.configured === true) : false;
                    setSubmitBlocked(!truckTypeConfigured);
                } else {
                    setSubmitBlocked(false);
                }
            };

            // On edit mode: lock cards so type can't be changed, only ⚙️ allowed
            if (isEditMode) {
                truckTypeCards?.querySelectorAll('.ttc-card').forEach(card => {
                    card.classList.add('locked');
                });
            }

            // On edit+TL: auto-load configs and pre-select card
            if (isTLEditMode) {
                loadAllConfigs();
                setSubmitBlocked(false); // type already set, don't block
            }

            // Guard: ensure truck type chosen on create submit
            form?.addEventListener('submit', (e) => {
                if (isEditMode) return; // AJAX handles edit
                const tlRoleId = Number(roleSelect?.dataset.teamleaderRole || 0);
                const isTL = Number(roleSelect?.value || 0) === tlRoleId;
                if (isTL && !truckTypeHidden?.value) {
                    e.preventDefault();
                    if (truckTypeSelectionError) truckTypeSelectionError.hidden = false;
                    truckTypeCards?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            });

            // ── AJAX submit on edit ──────────────────────────
            if (isEditMode && form) {
                const ajaxErrorBanner = document.getElementById('ajaxErrorBanner');
                const ajaxErrorText = document.getElementById('ajaxErrorText');

                const showBannerError = (msg) => {
                    if (ajaxErrorText) ajaxErrorText.textContent = msg;
                    if (ajaxErrorBanner) {
                        ajaxErrorBanner.hidden = false;
                        lucide.createIcons();
                    }
                    ajaxErrorBanner?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                };

                const clearFieldErrors = () => {
                    form.querySelectorAll('.ajax-field-error').forEach(el => el.remove());
                };

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

                    const submitBtn = form.querySelector('#createUserSubmit');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.classList.add('is-disabled');
                    }

                    try {
                        const formData = new FormData(form);
                        const res = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: formData,
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
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.classList.remove('is-disabled');
                        }
                    }
                });
            }

            roleSelect?.addEventListener('change', () => {
                syncTeamLeaderCapacity();
                syncRoleSections();
            });

            syncTeamLeaderCapacity();
            syncRoleSections();
        });
    </script>
@endpush



