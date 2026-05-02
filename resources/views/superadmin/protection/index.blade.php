@extends('layouts.superadmin')

@section('title', 'Archive & Backup Center')

@push('styles')
    <style>
        .protect-shell {
            display: grid;
            gap: 18px;
        }

        .protect-hero,
        .protect-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.05);
        }

        .protect-grid,
        .protect-datasets {
            display: grid;
            gap: 16px;
        }

        .protect-grid {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .protect-datasets {
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }

        .protect-pill {
            display: inline-flex;
            padding: 5px 10px;
            border-radius: 999px;
            background: #fef3c7;
            color: #92400e;
            font-size: 12px;
            font-weight: 700;
        }

        .protect-box {
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 16px;
            background: #fcfcfd;
        }

        .protect-box h3,
        .protect-hero h1 {
            margin-top: 0;
        }

        .protect-table {
            width: 100%;
            border-collapse: collapse;
        }

        .protect-table th,
        .protect-table td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid #e5e7eb;
        }

        .protect-button,
        .protect-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            padding: 10px 12px;
            text-decoration: none;
            font-weight: 600;
        }

        .protect-button {
            width: 100%;
            border: 0;
            cursor: pointer;
            background: #111827;
            color: #fff;
        }

        .protect-link {
            background: #f9fafb;
            border: 1px solid #d1d5db;
            color: #111827;
        }
    </style>
@endpush

@section('content')
    <div class="protect-shell">
        <section class="protect-hero">
            <span class="protect-pill">Sensitive Data Control</span>
            <h1>Archive & Backup Center</h1>
            <p>Create encrypted table backups, review archive-ready data, and jump directly into the existing archived-user
                flow.</p>
        </section>

        <section class="protect-grid">
            <div class="protect-box">
                <small>Archived Users</small>
                <h2>{{ $archiveSummary['archived_users'] }}</h2>
            </div>
            <div class="protect-box">
                <small>Completed Bookings</small>
                <h2>{{ $archiveSummary['completed_bookings'] }}</h2>
            </div>
            <div class="protect-box">
                <small>Cancelled Bookings</small>
                <h2>{{ $archiveSummary['cancelled_bookings'] }}</h2>
            </div>
            <div class="protect-box">
                <small>User Retention Rule</small>
                <h2>1 Year</h2>
                <p>Archived users become eligible for permanent deletion after 1 year.</p>
            </div>
        </section>

        <section class="protect-card">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
                <div>
                    <h3>Archive shortcuts</h3>
                    <p>Use these for retention and recovery workflows.</p>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="protect-link" href="{{ route('superadmin.users.archived') }}">Open Archived Users</a>
                    <a class="protect-link" href="{{ route('superadmin.bookings.index') }}">Review Bookings</a>
                    <a class="protect-link" href="{{ route('superadmin.audit.logs') }}">Audit Logs</a>
                </div>
            </div>
        </section>

        <section class="protect-datasets">
            @foreach ($datasets as $key => $dataset)
                <div class="protect-box">
                    <h3>{{ $dataset['label'] }}</h3>
                    <p>{{ $dataset['description'] }}</p>
                    <p><strong>{{ $dataset['count'] }}</strong> records available</p>

                    <form method="POST" action="{{ route('superadmin.backups.store') }}">
                        @csrf
                        <input type="hidden" name="dataset" value="{{ $key }}">
                        <button type="submit" class="protect-button">Create Encrypted Backup</button>
                    </form>
                </div>
            @endforeach
        </section>

        <section class="protect-card">
            <h3>Backup History</h3>
            <table class="protect-table">
                <thead>
                    <tr>
                        <th>Dataset</th>
                        <th>File</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($backups as $backup)
                        <tr>
                            <td>{{ str($backup['dataset'])->replace('_', ' ')->title() }}</td>
                            <td>{{ $backup['file_name'] }}</td>
                            <td>{{ $backup['size_kb'] }} KB</td>
                            <td>{{ $backup['last_modified'] }}</td>
                            <td>
                                <a class="protect-link"
                                    href="{{ route('superadmin.backups.download', ['file' => $backup['path']]) }}">Download</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">No backups created yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
@endsection
