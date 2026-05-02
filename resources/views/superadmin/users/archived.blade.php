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
            </div>
        </div>

        @php
            $tlRole = $roles->firstWhere('name', 'Team Leader');
            $dispRole = $roles->firstWhere('name', 'Admin');
        @endphp
        <div class="user-view-switch">
            <a href="{{ route('superadmin.users.index') }}" class="user-view-link">All Users</a>
            @if ($tlRole)
                <a href="{{ route('superadmin.users.index', ['role' => $tlRole->id]) }}" class="user-view-link">Team
                    Leaders</a>
            @endif
            @if ($dispRole)
                <a href="{{ route('superadmin.users.index', ['role' => $dispRole->id]) }}"
                    class="user-view-link">Dispatchers</a>
            @endif
            <a href="{{ route('superadmin.users.archived') }}" class="user-view-link active">Archived Users</a>
        </div>

        <div class="table-card">
            <div class="table-header soft-header">
                <form method="GET" class="filters">
                    <div class="search-container">
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
                            <th>Retention</th>
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
                                <td data-label="Retention">
                                    @php
                                        $deleteEligibleAt = optional($user->archived_at)?->copy()?->addYear();
                                        $hoursRemaining = $deleteEligibleAt
                                            ? max(0, now()->diffInHours($deleteEligibleAt, false))
                                            : 0;
                                        $daysRemaining = (int) floor($hoursRemaining / 24);
                                        $canPermanentlyDelete =
                                            $deleteEligibleAt && now()->greaterThanOrEqualTo($deleteEligibleAt);
                                    @endphp

                                    @if ($canPermanentlyDelete)
                                        <span class="status-badge inactive">Eligible now</span>
                                        <small>Retention complete</small>
                                    @else
                                        <span class="status-badge pending">
                                            {{ $daysRemaining > 0 ? $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's') . ' left' : 'Less than 1 day left' }}
                                        </span>
                                        <small>Archive retention in progress</small>
                                    @endif
                                </td>
                                <td data-label="Actions">
                                    <div class="action-group" style="display:flex;gap:8px;flex-wrap:wrap;">
                                        <form method="POST" action="{{ route('superadmin.users.restore', $user->id) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="action-btn restore-btn">
                                                {{-- <i data-lucide="rotate-ccw"></i> --}}
                                                Restore
                                            </button>
                                        </form>

                                        @if ($canPermanentlyDelete)
                                            <form method="POST"
                                                action="{{ route('superadmin.users.force-delete', $user->id) }}"
                                                class="js-confirm-delete"
                                                data-confirm-title="Delete archived user permanently?"
                                                data-confirm-message="This action cannot be undone."
                                                data-confirm-button="Delete Permanently">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="action-btn archive-btn">
                                                    {{-- <i data-lucide="trash-2"></i> --}}
                                                    Delete Permanently
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state small-empty">
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
        <div id="deleteDialog" class="sa-dialog-backdrop">
            <div class="sa-dialog-card">
                <h3 id="deleteDialogTitle">Confirm Delete</h3>
                <p id="deleteDialogMessage">This action cannot be undone.</p>
                <div class="sa-dialog-actions">
                    <button type="button" class="sa-dialog-btn cancel" id="deleteDialogCancel">Cancel</button>
                    <button type="button" class="sa-dialog-btn confirm" id="deleteDialogConfirm">OK</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const deleteDialog = document.getElementById('deleteDialog');
            const deleteDialogTitle = document.getElementById('deleteDialogTitle');
            const deleteDialogMessage = document.getElementById('deleteDialogMessage');
            const deleteDialogCancel = document.getElementById('deleteDialogCancel');
            const deleteDialogConfirm = document.getElementById('deleteDialogConfirm');
            let pendingDelete = null;

            function openDeleteDialog(title, message, confirmText = 'OK', onConfirm = null) {
                deleteDialogTitle.textContent = title;
                deleteDialogMessage.textContent = message;
                deleteDialogConfirm.textContent = confirmText;
                pendingDelete = onConfirm;
                deleteDialog.classList.add('is-open');
            }

            function closeDeleteDialog() {
                deleteDialog.classList.remove('is-open');
                pendingDelete = null;
            }

            document.querySelectorAll('.js-confirm-delete').forEach(form => {
                form.addEventListener('submit', function(event) {
                    event.preventDefault();

                    openDeleteDialog(
                        this.dataset.confirmTitle || 'Confirm Delete',
                        this.dataset.confirmMessage || 'This action cannot be undone.',
                        this.dataset.confirmButton || 'OK',
                        () => this.submit()
                    );
                });
            });

            deleteDialogCancel?.addEventListener('click', closeDeleteDialog);
            deleteDialogConfirm?.addEventListener('click', () => {
                const callback = pendingDelete;
                closeDeleteDialog();

                if (typeof callback === 'function') {
                    callback();
                }
            });
        });
    </script>
@endpush
