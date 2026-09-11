@extends('layouts.system-admin')

@section('title', 'System Maintenance')
@section('subtitle', 'Encrypted data backups and read-only system diagnostics')

@section('content')
    <div class="sa-panel">
        <div class="sa-panel-head">
            <h3>Data Protection</h3>
            <p>Create an encrypted export of a dataset, or download a previous backup.</p>
        </div>
        <div class="sa-panel-body">
            <div class="sa-summary-row">
                <div class="sa-summary-tile">
                    <strong>{{ $archiveSummary['archived_users'] }}</strong>
                    <span>Archived Users</span>
                </div>
                <div class="sa-summary-tile">
                    <strong>{{ $archiveSummary['completed_bookings'] }}</strong>
                    <span>Completed Bookings</span>
                </div>
                <div class="sa-summary-tile">
                    <strong>{{ $archiveSummary['cancelled_bookings'] }}</strong>
                    <span>Cancelled Bookings</span>
                </div>
            </div>

            <form method="POST" action="{{ route('system-admin.maintenance.backups.store') }}">
                @csrf
                <div class="sa-form-grid">
                    <div class="sa-form-group">
                        <label for="dataset">Create a backup</label>
                        <select id="dataset" name="dataset" required>
                            @foreach ($datasets as $key => $dataset)
                                <option value="{{ $key }}">{{ $dataset['label'] }} ({{ $dataset['count'] }} records)</option>
                            @endforeach
                        </select>
                        @error('dataset') <span class="sa-form-error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="sa-form-actions">
                    <button type="submit" class="sa-btn sa-btn-primary">Create Backup</button>
                </div>
            </form>
        </div>

        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Dataset</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($backups as $backup)
                        <tr>
                            <td>{{ $backup['file_name'] }}</td>
                            <td class="sa-muted">{{ $backup['dataset'] }}</td>
                            <td class="sa-muted">{{ $backup['size_kb'] }} KB</td>
                            <td class="sa-muted">{{ $backup['last_modified'] }}</td>
                            <td>
                                <a href="{{ route('system-admin.maintenance.backups.download', ['file' => $backup['path']]) }}" class="sa-action-link">Download</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="sa-table-empty">No backups have been created yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="sa-panel">
        <div class="sa-panel-head">
            <h3>System Information</h3>
            <p>Read-only diagnostics. No credentials or connection details are shown.</p>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <tbody>
                    <tr>
                        <td>Environment</td>
                        <td class="sa-muted">{{ ucfirst($systemInfo['environment']) }}</td>
                    </tr>
                    <tr>
                        <td>Laravel Version</td>
                        <td class="sa-muted">{{ $systemInfo['laravel_version'] }}</td>
                    </tr>
                    <tr>
                        <td>PHP Version</td>
                        <td class="sa-muted">{{ $systemInfo['php_version'] }}</td>
                    </tr>
                    <tr>
                        <td>Database Connection</td>
                        <td class="{{ $systemInfo['database_available'] ? 'sa-text-success' : 'sa-text-danger' }}">{{ $systemInfo['database_available'] ? 'Available' : 'Unavailable' }}</td>
                    </tr>
                    <tr>
                        <td>Storage</td>
                        <td class="{{ $systemInfo['storage_writable'] ? 'sa-text-success' : 'sa-text-danger' }}">{{ $systemInfo['storage_writable'] ? 'Available' : 'Unavailable' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection
