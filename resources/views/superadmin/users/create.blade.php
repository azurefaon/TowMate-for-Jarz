@extends('layouts.superadmin')

@section('title', 'Add User')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/user-create.css') }}">
    <style>
        .teamleader-capacity-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            margin: 0 0 18px;
            border-radius: 16px;
            background: linear-gradient(180deg, #fffdf4 0%, #ffffff 100%);
            border: 1px solid #fde68a;
        }

        .teamleader-capacity-card strong {
            display: block;
            color: #111827;
            margin-bottom: 4px;
        }

        .teamleader-capacity-card p {
            margin: 0;
            color: #475569;
            font-size: 0.92rem;
        }

        .teamleader-capacity-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 74px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #111827;
            color: #fff;
            font-weight: 700;
        }

        .teamleader-capacity-hint {
            margin: 10px 0 0;
            color: #475569;
            font-size: 0.9rem;
        }

        .teamleader-capacity-hint.limit-reached {
            color: #b45309;
            font-weight: 700;
        }

        .btn-primary-submit.is-disabled {
            opacity: 0.6;
            pointer-events: none;
        }
    </style>
@endpush

@php
    $isEdit = isset($user);
@endphp

@section('content')

    <div class="create-user-page">

        <div class="form-wrapper">

            <div class="form-card">

                <div class="form-header">
                    <div class="form-icon">
                        <i data-lucide="user-plus"></i>
                    </div>
                    <div>
                        <h2>Add User</h2>
                        <p>Register new employee or admin</p>
                    </div>
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
                    class="create-user-form">
                    @csrf

                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <div id="sectionTeamLeaderDetails" class="role-section-box" hidden>
                        <div class="role-section-header">
                            <i data-lucide="shield-user"></i>
                            <span>Team Leader Details</span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name <span class="required-mark">*</span></label>
                            <div class="input-with-icon">
                                <i data-lucide="user"></i>
                                <input type="text" name="first_name" value="{{ old('first_name') }}"
                                    placeholder="First name" required>
                            </div>
                            @error('first_name')
                                <small class="error-text">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>Middle Name <span class="field-optional">Optional</span></label>
                            <div class="input-with-icon">
                                <i data-lucide="user"></i>
                                <input type="text" name="middle_name" value="{{ old('middle_name') }}"
                                    placeholder="Middle name">
                            </div>
                            @error('middle_name')
                                <small class="error-text">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>Last Name <span class="required-mark">*</span></label>
                            <div class="input-with-icon">
                                <i data-lucide="user"></i>
                                <input type="text" name="last_name" value="{{ old('last_name') }}"
                                    placeholder="Last name" required>
                            </div>
                            @error('last_name')
                                <small class="error-text">{{ $message }}</small>
                            @enderror
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Address <span class="required-mark">*</span></label>

                        <div class="input-with-icon">
                            <i data-lucide="mail"></i>
                            <input type="email" name="email" value="{{ old('email') }}" placeholder="john@gmail.com"
                                required>
                        </div>

                        @error('email')
                            <small class="error-text">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="form-row">

                        <div class="form-group">
                            <label>Password <span class="required-mark">*</span></label>

                            <div class="input-with-icon">
                                <i data-lucide="lock"></i>
                                <input type="password" name="password" required>
                            </div>
                            @error('password')
                                <small class="error-text">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>Confirm Password <span class="required-mark">*</span></label>

                            <div class="input-with-icon">
                                <i data-lucide="lock"></i>
                                <input type="password" name="password_confirmation" required>
                            </div>
                            @error('password_confirmation')
                                <small class="error-text">{{ $message }}</small>
                            @enderror
                        </div>

                    </div>

                    <div id="passwordRequirements" class="password-requirements" hidden>
                        <p>Password Requirements:</p>
                        <ul>
                            <li data-rule="length"><span class="requirement-icon">•</span><span>Minimum 12 characters</span>
                            </li>
                            <li data-rule="uppercase"><span class="requirement-icon">•</span><span>Include uppercase
                                    letters</span></li>
                            <li data-rule="lowercase"><span class="requirement-icon">•</span><span>Include lowercase
                                    letters</span></li>
                            <li data-rule="number"><span class="requirement-icon">•</span><span>Include numbers</span></li>
                            <li data-rule="special"><span class="requirement-icon">•</span><span>Include special
                                    characters</span></li>
                        </ul>
                    </div>

                    <div class="form-row">

                        <div class="form-group">
                            <label>Role <span class="required-mark">*</span></label>

                            <select name="role_id" id="roleSelect" required
                                data-teamleader-role="{{ $teamLeaderCapacity['role_id'] ?? '' }}"
                                data-teamleader-limit="{{ $teamLeaderCapacity['limit'] ?? 10 }}"
                                data-teamleader-count="{{ $teamLeaderCapacity['count'] ?? 0 }}">
                                <option value="">Select role</option>

                                @foreach ($roles as $role)
                                    @php
                                        $teamLeaderLimitReached =
                                            (int) ($role->id ?? 0) === (int) ($teamLeaderCapacity['role_id'] ?? 0) &&
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
                        </div>

                        <div class="form-group">
                            <label>Status</label>

                            <select name="status">
                                <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Active
                                </option>
                                <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Inactive
                                </option>
                            </select>
                        </div>

                    </div>

                    <div id="sectionDriverDetails" class="role-section-box" hidden>
                        <div class="role-section-header">
                            <i data-lucide="user-check"></i>
                            <span>Driver Details</span>
                        </div>
                        <div class="role-section-body">

                            <div class="form-row">
                                <div class="form-group">
                                    <label>First Name <span class="required-mark">*</span></label>
                                    <div class="input-with-icon">
                                        <i data-lucide="user"></i>
                                        <input type="text" name="driver_first_name"
                                            value="{{ old('driver_first_name') }}" placeholder="Driver first name">
                                    </div>
                                    @error('driver_first_name')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="form-group">
                                    <label>Middle Name <span class="field-optional">Optional</span></label>
                                    <div class="input-with-icon">
                                        <i data-lucide="user"></i>
                                        <input type="text" name="driver_middle_name"
                                            value="{{ old('driver_middle_name') }}" placeholder="Driver middle name">
                                    </div>
                                    @error('driver_middle_name')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="form-group">
                                    <label>Last Name <span class="required-mark">*</span></label>
                                    <div class="input-with-icon">
                                        <i data-lucide="user"></i>
                                        <input type="text" name="driver_last_name"
                                            value="{{ old('driver_last_name') }}" placeholder="Driver last name">
                                    </div>
                                    @error('driver_last_name')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                            </div>

                        </div>
                    </div>

                    <div id="sectionUnitDetails" class="role-section-box" hidden>
                        <div class="role-section-header">
                            <i data-lucide="truck"></i>
                            <span>Unit Details</span>
                        </div>
                        <div class="role-section-body">

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Unit Name <span class="required-mark">*</span></label>
                                    <div class="input-with-icon">
                                        <i data-lucide="package"></i>
                                        <input type="text" name="unit_name" value="{{ old('unit_name') }}"
                                            placeholder="e.g. UNIT 1">
                                    </div>
                                    @error('unit_name')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="form-group">
                                    <label>Plate Number <span class="required-mark">*</span></label>
                                    <div class="input-with-icon">
                                        <i data-lucide="credit-card"></i>
                                        <input type="text" name="unit_plate_number"
                                            value="{{ old('unit_plate_number') }}" placeholder="e.g. ABC 1234">
                                    </div>
                                    @error('unit_plate_number')
                                        <small class="error-text">{{ $message }}</small>
                                    @enderror
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Truck Type <span class="required-mark">*</span></label>
                                <select name="unit_truck_class">
                                    <option value="">Select truck type</option>
                                    <option value="Heavy" {{ old('unit_truck_class') === 'Heavy' ? 'selected' : '' }}>
                                        Heavy</option>
                                    <option value="Medium" {{ old('unit_truck_class') === 'Medium' ? 'selected' : '' }}>
                                        Medium</option>
                                    <option value="Light" {{ old('unit_truck_class') === 'Light' ? 'selected' : '' }}>
                                        Light</option>
                                </select>
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
                            <i data-lucide="user-plus"></i>
                            Register User
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
                <div class="ttc-modal-icon"><i data-lucide="settings"></i></div>
                <div>
                    <h3 id="ttcModalTitle">Configure Truck Type</h3>
                    <p>Set pricing and capacity before this type can be used.</p>
                </div>
            </div>
            <div class="ttc-modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Base Rate (₱) <span class="required-mark">*</span></label>
                        <div class="input-with-icon">
                            <i data-lucide="banknote"></i>
                            <input type="number" id="ttcBaseRate" min="0" step="0.01"
                                placeholder="e.g. 1000">
                        </div>
                        <small class="ttc-hint">Flat charge for up to 4 km</small>
                    </div>
                    <div class="form-group">
                        <label>Per KM Rate (₱) <span class="required-mark">*</span></label>
                        <div class="input-with-icon">
                            <i data-lucide="banknote"></i>
                            <input type="number" id="ttcPerKmRate" min="0" step="0.01"
                                placeholder="e.g. 200">
                        </div>
                        <small class="ttc-hint">Added per km beyond 4 km</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>Capacity (kg) <span class="required-mark">*</span></label>
                    <div class="input-with-icon">
                        <i data-lucide="package"></i>
                        <input type="number" id="ttcCapacity" min="0" step="1" placeholder="e.g. 3000">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description <span class="field-optional">Optional</span></label>
                    <div class="input-with-icon">
                        <i data-lucide="file-text"></i>
                        <input type="text" id="ttcDescription" placeholder="e.g. For medium-sized vehicles">
                    </div>
                </div>
                <div id="ttcError" class="ttc-error" hidden></div>
            </div>
            <div class="ttc-modal-footer">
                <button type="button" id="ttcCancelBtn" class="btn-cancel">Cancel</button>
                <button type="button" id="ttcSaveBtn" class="btn-primary-submit">
                    <i data-lucide="save"></i> Save Config
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
                    if (isTL) {
                        teamLeaderCapacityHint.textContent =
                            `Team Leader slots remaining: ${Math.max(tlLimit - tlCount, 0)}.`;
                    }
                }
            };

            let truckTypeConfigured = false;
            let currentTruckTypeName = '';

            const truckTypeSelect = document.querySelector('[name="unit_truck_class"]');
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

            const setSubmitBlocked = (blocked) => {
                if (!createUserSubmit) return;
                createUserSubmit.disabled = blocked;
                createUserSubmit.classList.toggle('is-disabled', blocked);
            };

            const openConfigModal = (name) => {
                currentTruckTypeName = name;
                ttcModalTitle.textContent = `Configure ${name} Truck Type`;
                ttcBaseRate.value = ttcPerKmRate.value = ttcCapacity.value = ttcDescription.value = '';
                ttcError.hidden = true;
                configModal.hidden = false;
                lucide.createIcons();
            };

            const checkTruckTypeConfig = async (name) => {
                if (!name) {
                    truckTypeConfigured = false;
                    return;
                }
                try {
                    const res = await fetch(`/superadmin/truck-type-config/${encodeURIComponent(name)}`, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const data = await res.json();
                    truckTypeConfigured = data.configured === true;
                    if (!truckTypeConfigured) openConfigModal(name);
                } catch {
                    truckTypeConfigured = false;
                }
                if (sectionUnitDetails && !sectionUnitDetails.hidden) {
                    setSubmitBlocked(!truckTypeConfigured);
                }
            };

            truckTypeSelect?.addEventListener('change', () => {
                truckTypeConfigured = false;
                const name = truckTypeSelect.value;
                if (name) checkTruckTypeConfig(name);
            });

            ttcCancelBtn?.addEventListener('click', () => {
                configModal.hidden = true;
                if (truckTypeSelect) truckTypeSelect.value = '';
                truckTypeConfigured = false;
                setSubmitBlocked(true);
            });

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
                        });
                    const data = await res.json();

                    if (data.success) {
                        truckTypeConfigured = true;
                        configModal.hidden = true;
                        setSubmitBlocked(false);
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

            const syncRoleSections = () => {
                if (!roleSelect) return;
                const tlRoleId = Number(roleSelect.dataset.teamleaderRole || 0);
                const isTL = Number(roleSelect.value || 0) === tlRoleId;

                if (sectionTLDetails) sectionTLDetails.hidden = !isTL;
                if (sectionDriverDetails) sectionDriverDetails.hidden = !isTL;
                if (sectionUnitDetails) sectionUnitDetails.hidden = !isTL;

                const conditionalRequired = [
                    'driver_first_name', 'driver_last_name',
                    'unit_name', 'unit_plate_number', 'unit_truck_class',
                ];
                conditionalRequired.forEach(name => {
                    const el = document.querySelector(`[name="${name}"]`);
                    if (!el) return;
                    isTL ? el.setAttribute('required', '') : el.removeAttribute('required');
                });

                if (isTL && !truckTypeConfigured) setSubmitBlocked(true);
            };

            roleSelect?.addEventListener('change', () => {
                syncTeamLeaderCapacity();
                syncRoleSections();
            });

            syncTeamLeaderCapacity();
            syncRoleSections();
        });
    </script>
@endpush
