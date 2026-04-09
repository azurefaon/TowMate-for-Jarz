@extends('layouts.superadmin')

@section('title', 'Archived Users')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/users.css') }}">
@endpush

@section('content')
    <div class="user-management-page archived-page">
        <div class="page-top">
            <div>
                <h1>Archived Users</h1>
                <p>Restore removed accounts or review archived team members.</p>
            </div>

            <div class="page-actions">
                <a href="{{ route('superadmin.users.index') }}" class="btn-secondary-link">
                    <i data-lucide="arrow-left"></i>
                    Back to Active Users
                </a>
            </div>
        </div>

        <div class="overview-grid compact-grid">
            <div class="overview-card">
                <span>Archived Accounts</span>
                <strong>{{ $stats['archived'] }}</strong>
                <small>Currently stored in archive</small>
            </div>
            <div class="overview-card muted-card">
                <span>Active Accounts</span>
                <strong>{{ $stats['active'] }}</strong>
                <small>Available for login</small>
            </div>
        </div>

        <div class="table-card">
            <div class="table-header soft-header">
                <form method="GET" class="filters">
                    <div class="search-container">
                        <i data-lucide="search" class="search-icon"></i>
                        <input type="text" name="search" value="{{ request('search') }}"
                            placeholder="Search archived users..." class="search-input">
                    </div>

                    <select name="role" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Roles</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" {{ request('role') == $role->id ? 'selected' : '' }}>
                                {{ $role->name }}
                            </option>
                        @endforeach
                    </select>
                </form>

                <span class="table-count">{{ $archivedUsers->total() }} archived users</span>
            </div>

            <div class="table-scroll">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Archived</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($archivedUsers as $user)
                            <tr>
                                <td data-label="User">
                                    <div class="user-info">
                                        <div class="avatar user-avatar-neutral">
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        </div>
                                        <div class="user-text">
                                            <span class="user-name">{{ $user->name }}</span>
                                            <small>{{ $user->email }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Role">{{ $user->role->name ?? 'N/A' }}</td>
                                <td data-label="Status">
                                    <span class="status-badge archived">Archived</span>
                                </td>
                                <td data-label="Archived">
                                    {{ optional($user->archived_at)->format('M d, Y h:i A') ?? '—' }}
                                </td>
                                <td data-label="Actions">
                                    <div class="action-group">
                                        <form method="POST" action="{{ route('superadmin.users.restore', $user->id) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="action-btn restore-btn">
                                                <i data-lucide="rotate-ccw"></i>
                                                Restore
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state small-empty">
                                        <i data-lucide="archive"></i>
                                        <h3>No archived users</h3>
                                        <p>Removed accounts will appear here for easy restore.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrapper">
                {{ $archivedUsers->appends(request()->query())->links('vendor.pagination.custom') }}
            </div>
        </div>
    </div>
@endsection
