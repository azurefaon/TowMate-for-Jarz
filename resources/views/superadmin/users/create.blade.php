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
                        You can still add {{ $teamLeaderCapacity['remaining'] ?? 0 }} more Team Leader account(s).
                    @endif
                </p>

                <form method="POST" action="{{ route('superadmin.users.store') }}" class="create-user-form">
                    @csrf

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

                    {{-- EMAIL --}}
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

                    {{-- PASSWORD ROW --}}
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

                    {{-- ROLE & STATUS --}}
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

                    {{-- BUTTONS --}}
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

            if (!passwordInput || !requirementsBox) {
                return;
            }

            const rules = {
                length: value => value.length >= 12,
                uppercase: value => /[A-Z]/.test(value),
                lowercase: value => /[a-z]/.test(value),
                number: value => /\d/.test(value),
                special: value => /[^A-Za-z0-9]/.test(value),
            };

            const syncRequirements = () => {
                const value = passwordInput.value || '';

                Object.entries(rules).forEach(([ruleName, validator]) => {
                    const item = requirementsBox.querySelector(`[data-rule="${ruleName}"]`);
                    const icon = item?.querySelector('.requirement-icon');
                    const passed = validator(value);

                    item?.classList.toggle('met', passed);

                    if (icon) {
                        icon.textContent = passed ? '✓' : '•';
                    }
                });
            };

            const showRequirements = () => {
                requirementsBox.hidden = false;
                syncRequirements();
            };

            const hideRequirements = () => {
                if (passwordInput.value.trim() === '') {
                    requirementsBox.hidden = true;
                }
            };

            const syncTeamLeaderCapacity = () => {
                if (!roleSelect || !createUserSubmit || !teamLeaderCapacityHint) {
                    return;
                }

                const teamLeaderRoleId = Number(roleSelect.dataset.teamleaderRole || 0);
                const teamLeaderLimit = Number(roleSelect.dataset.teamleaderLimit || 0);
                const teamLeaderCount = Number(roleSelect.dataset.teamleaderCount || 0);
                const isTeamLeaderSelected = Number(roleSelect.value || 0) === teamLeaderRoleId;
                const limitReached = teamLeaderCount >= teamLeaderLimit;

                if (isTeamLeaderSelected && limitReached) {
                    createUserSubmit.disabled = true;
                    createUserSubmit.classList.add('is-disabled');
                    teamLeaderCapacityHint.textContent =
                        'Team Leader limit reached. Increase the maximum in System Settings before creating another Team Leader account.';
                    teamLeaderCapacityHint.classList.add('limit-reached');
                } else {
                    createUserSubmit.disabled = false;
                    createUserSubmit.classList.remove('is-disabled');

                    if (isTeamLeaderSelected) {
                        teamLeaderCapacityHint.textContent =
                            `Team Leader slots remaining: ${Math.max(teamLeaderLimit - teamLeaderCount, 0)}.`;
                    }
                }
            };

            passwordInput.addEventListener('focus', showRequirements);
            passwordInput.addEventListener('input', showRequirements);
            passwordInput.addEventListener('blur', hideRequirements);
            roleSelect?.addEventListener('change', syncTeamLeaderCapacity);
            syncTeamLeaderCapacity();
        });
    </script>
@endpush
