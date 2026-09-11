@extends('layouts.system-admin')

@section('title', 'System Settings')
@section('subtitle', 'Technical limits and mobile application distribution')

@section('content')
    @if (session('apk_success'))
        <div class="sa-flash sa-flash-success">{{ session('apk_success') }}</div>
    @endif

    <div class="sa-panel">
        <div class="sa-panel-head">
            <h3>Account &amp; Retention</h3>
            <p>How long removed or inactive accounts are kept before automatic action.</p>
        </div>
        <div class="sa-panel-body">
            <form method="POST" action="{{ route('system-admin.settings.update') }}">
                @csrf
                <div class="sa-form-grid">
                    <div class="sa-form-group">
                        <label for="deleted_retention_days">Deleted account retention (days)</label>
                        <input type="number" id="deleted_retention_days" name="deleted_retention_days" min="1" max="365" value="{{ old('deleted_retention_days', $settings['deleted_retention_days'] ?? 30) }}" required>
                        <span class="sa-form-help">Accounts queued for deletion are permanently purged after this many days.</span>
                        @error('deleted_retention_days') <span class="sa-form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="sa-form-group">
                        <label for="customer_inactivity_lock_days">Customer inactivity lock (days)</label>
                        <input type="number" id="customer_inactivity_lock_days" name="customer_inactivity_lock_days" min="1" max="365" value="{{ old('customer_inactivity_lock_days', $settings['customer_inactivity_lock_days'] ?? 90) }}" required>
                        <span class="sa-form-help">Customer accounts with no login for this many days are automatically locked.</span>
                        @error('customer_inactivity_lock_days') <span class="sa-form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="sa-form-section">
                    <p class="sa-form-section-title">System Limits</p>
                    <div class="sa-form-grid">
                        <div class="sa-form-group">
                            <label for="max_team_leaders">Team Leader account limit</label>
                            <input type="number" id="max_team_leaders" name="max_team_leaders" min="1" max="500" value="{{ old('max_team_leaders', $settings['max_team_leaders'] ?? 10) }}" required>
                            <span class="sa-form-help">Maximum number of active Team Leader accounts that can exist at once.</span>
                            @error('max_team_leaders') <span class="sa-form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>

                <div class="sa-form-actions">
                    <button type="submit" class="sa-btn sa-btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>

    <div class="sa-panel">
        <div class="sa-panel-head">
            <h3>Mobile Application</h3>
            <p>The Android package served from the staff login page.</p>
        </div>
        <div class="sa-panel-body">
            <p class="sa-form-help">
                Current file: <strong>{{ $apkExists ? 'towmate.apk' : 'Not uploaded' }}</strong>
                @if ($apkExists)
                    ({{ $apkSizeMb }} MB, updated {{ $apkUpdatedAt->format('M d, Y g:i A') }})
                @endif
            </p>

            <form method="POST" action="{{ route('system-admin.settings.upload-apk') }}" enctype="multipart/form-data">
                @csrf
                <div class="sa-form-grid">
                    <div class="sa-form-group">
                        <label for="apk_file">Replace APK</label>
                        <input type="file" id="apk_file" name="apk_file" accept=".apk" required>
                        @error('apk_file') <span class="sa-form-error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="sa-form-actions">
                    <button type="submit" class="sa-btn sa-btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
@endsection
