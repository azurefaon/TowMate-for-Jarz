@extends('layouts.system-admin')

@section('title', 'Change Password')
@section('subtitle', 'Update your account password.')

@section('content')
    <div class="sa-panel">
        <div class="sa-panel-body">
            <form method="POST" action="{{ route('system-admin.profile.password.update') }}">
                @csrf
                @method('PUT')

                <div class="sa-form-grid">
                    <div class="sa-form-group">
                        <label for="current_password">Current Password</label>
                        <div class="sa-pw-wrap">
                            <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                            <button type="button" class="sa-pw-toggle" data-target="current_password">Show</button>
                        </div>
                        @error('current_password') <span class="sa-form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="sa-form-section">
                    <div class="sa-form-grid">
                        <div class="sa-form-group">
                            <label for="password">New Password</label>
                            <div class="sa-pw-wrap">
                                <input type="password" id="password" name="password" autocomplete="new-password" required>
                                <button type="button" class="sa-pw-toggle" data-target="password">Show</button>
                            </div>
                            @error('password') <span class="sa-form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="sa-form-group">
                            <label for="password_confirmation">Confirm New Password</label>
                            <div class="sa-pw-wrap">
                                <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                                <button type="button" class="sa-pw-toggle" data-target="password_confirmation">Show</button>
                            </div>
                        </div>
                    </div>
                    <p class="sa-form-help">Minimum 12 characters, with uppercase, lowercase, a number and a symbol.</p>
                </div>

                <div class="sa-form-actions">
                    <a href="{{ route('system-admin.profile.edit') }}" class="sa-btn">Cancel</a>
                    <button type="submit" class="sa-btn sa-btn-primary">Update Password</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('.sa-pw-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.target);
                if (!input) return;
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                btn.textContent = isHidden ? 'Hide' : 'Show';
            });
        });
    </script>
@endpush
