@extends('layouts.superadmin')

@section('title', 'Add User')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/user-create.css') }}">
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

                            <select name="role_id" required>
                                <option value="">Select role</option>

                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}"
                                        {{ (string) old('role_id') === (string) $role->id ? 'selected' : '' }}>
                                        {{ $role->name }}
                                    </option>
                                @endforeach

                            </select>
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

                        <button type="submit" class="btn-primary-submit">
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

            passwordInput.addEventListener('focus', showRequirements);
            passwordInput.addEventListener('input', showRequirements);
            passwordInput.addEventListener('blur', hideRequirements);
        });
    </script>
@endpush
