@extends('layouts.system-admin')

@section('title', 'Profile Settings')
@section('subtitle', 'Manage your personal account information.')

@section('content')
    <div class="sa-panel">
        <div class="sa-panel-body">
            <form method="POST" action="{{ route('system-admin.profile.update') }}" enctype="multipart/form-data">
                @csrf
                @method('PATCH')

                <p class="sa-form-section-title">Profile Photo</p>
                <div class="sa-profile-photo-row">
                    <span class="sa-profile-photo-preview" id="saProfilePhotoPreview">
                        @if ($user->profile_image)
                            <img src="{{ Storage::url($user->profile_image) }}" alt="">
                        @else
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><circle cx="12" cy="8" r="3.4" /><path d="M5.5 20 L6.8 14.8 L17.2 14.8 L18.5 20" /></svg>
                        @endif
                    </span>
                    <div>
                        <label for="profile_image" class="sa-btn">Change Photo</label>
                        <input type="file" id="profile_image" name="profile_image" accept="image/png,image/jpeg,image/webp" style="display:none;">
                        <p class="sa-form-help">JPG, PNG or WEBP. Max 2MB.</p>
                        @error('profile_image') <span class="sa-form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="sa-form-section">
                    <p class="sa-form-section-title">Personal Information</p>
                    <div class="sa-form-grid">
                        <div class="sa-form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="{{ old('first_name', $user->first_name) }}" required>
                            @error('first_name') <span class="sa-form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="sa-form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" value="{{ old('middle_name', $user->middle_name) }}">
                            @error('middle_name') <span class="sa-form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="sa-form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="{{ old('last_name', $user->last_name) }}" required>
                            @error('last_name') <span class="sa-form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>

                <div class="sa-form-section">
                    <p class="sa-form-section-title">Account</p>
                    <div class="sa-form-grid">
                        <div class="sa-form-group">
                            <label>Email Address</label>
                            <div class="sa-readonly-field">{{ $user->email }}</div>
                            <span class="sa-form-help">Email address cannot be changed here.</span>
                        </div>
                    </div>
                </div>

                <div class="sa-form-actions">
                    <a href="{{ route('system-admin.dashboard') }}" class="sa-btn">Cancel</a>
                    <button type="submit" class="sa-btn sa-btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.getElementById('profile_image')?.addEventListener('change', function() {
            const file = this.files?.[0];
            if (!file) return;

            const preview = document.getElementById('saProfilePhotoPreview');
            const reader = new FileReader();
            reader.onload = e => {
                preview.innerHTML = `<img src="${e.target.result}" alt="">`;
            };
            reader.readAsDataURL(file);
        });
    </script>
@endpush
