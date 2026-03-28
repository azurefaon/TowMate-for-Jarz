<link rel="stylesheet" href="{{ asset('admin/css/user-create.css') }}">

@extends('layouts.superadmin')

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

            <form method="POST" action="{{ route('superadmin.users.store') }}">
                @csrf

                {{-- FULL NAME --}}
                <div class="form-group">
                    <label>Full Name *</label>

                    <div class="input-with-icon">
                        <i data-lucide="user"></i>
                        <input type="text"
                            name="name"
                            value="{{ old('name') }}"
                            placeholder="enter your name"
                            required>
                    </div>

                    @error('name')
                    <small class="error-text">{{ $message }}</small>
                    @enderror
                </div>

                {{-- EMAIL --}}
                <div class="form-group">
                    <label>Email Address *</label>

                    <div class="input-with-icon">
                        <i data-lucide="mail"></i>
                        <input type="email"
                            name="email"
                            value="{{ old('email') }}"
                            placeholder="john@example.com"
                            required>
                    </div>

                    @error('email')
                    <small class="error-text">{{ $message }}</small>
                    @enderror
                </div>

                {{-- PASSWORD ROW --}}
                <div class="form-row">

                    <div class="form-group">
                        <label>Password *</label>

                        <div class="input-with-icon">
                            <i data-lucide="lock"></i>
                            <input type="password" name="password" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password *</label>

                        <div class="input-with-icon">
                            <i data-lucide="lock"></i>
                            <input type="password" name="password_confirmation" required>
                        </div>
                    </div>

                </div>

                {{-- ROLE & STATUS --}}
                <div class="form-row">

                    <div class="form-group">
                        <label>Role *</label>

                        <select name="role_id" required>
                            <option value="">Select role</option>

                            @foreach($roles as $role)
                            <option value="{{ $role->id }}">
                                {{ $role->name }}
                            </option>
                            @endforeach

                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status</label>

                        <select name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                </div>

                {{-- BUTTONS --}}
                <div class="form-actions">

                    <a href="{{ route('superadmin.users.index') }}"
                        class="btn-cancel">
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
    lucide.createIcons();
</script>
@endpush