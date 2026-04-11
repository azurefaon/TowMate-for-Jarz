@extends('layouts.superadmin')

@section('title', 'User Management')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/users.css') }}">
@endpush

@section('content')
    <div class="user-management-page">
        <div class="page-top">
            <div>
                <h1>User Management</h1>
                <p>Modern SaaS control for account access, roles, and archived members.</p>
            </div>

            <div class="page-actions">
                <a href="{{ route('superadmin.users.archived') }}" class="btn-secondary-link">
                    <i data-lucide="archive"></i>
                    Archive Panel
                    <span class="pill-count">{{ $stats['archived'] ?? 0 }}</span>
                </a>

                <a href="{{ route('superadmin.users.create') }}" class="btn-primary-add">
                    <i data-lucide="user-plus"></i>
                    Add User
                </a>
            </div>
        </div>

        <div class="overview-grid">
            <div class="overview-card accent-card">
                <span>Total Team Members</span>
                <strong>{{ $stats['total'] ?? $users->total() }}</strong>
                <small>All non-superadmin accounts</small>
            </div>

            <div class="overview-card">
                <span>Active</span>
                <strong>{{ $stats['active'] ?? 0 }}</strong>
                <small>Ready to access the platform</small>
            </div>

            <div class="overview-card muted-card">
                <span>Inactive</span>
                <strong>{{ $stats['inactive'] ?? 0 }}</strong>
                <small>Temporarily disabled accounts</small>
            </div>

            <div class="overview-card archive-card">
                <span>Archived</span>
                <strong>{{ $stats['archived'] ?? 0 }}</strong>
                <small>Stored in the archive panel</small>
            </div>
        </div>

        <div class="table-card">
            <div class="table-header">
                <form method="GET" class="filters">
                    <div class="search-container">
                        <i data-lucide="search" class="search-icon"></i>
                        <input type="text" name="search" value="{{ request('search') }}"
                            placeholder="Search by name or email..." class="search-input">
                    </div>

                    <select name="role" class="filter-select">
                        <option value="">All Roles</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" {{ request('role') == $role->id ? 'selected' : '' }}>
                                {{ $role->name }}
                            </option>
                        @endforeach
                    </select>

                    <select name="status" class="filter-select">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>

                    <a href="{{ route('superadmin.users.index') }}" class="btn-reset">Reset</a>
                </form>

                <div class="table-actions-right">
                    <span class="table-count">
                        Showing {{ $users->count() }} of {{ $users->total() }} users
                    </span>
                </div>
            </div>

            <div class="table-scroll">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td data-label="User">
                                    <div class="user-info">
                                        <div class="avatar"
                                            style="background: {{ ['#111111', '#facc15', '#d97706', '#374151', '#b45309', '#1f2937', '#ca8a04'][crc32($user->name) % 7] }}; color: {{ in_array(crc32($user->name) % 7, [1]) ? '#111111' : '#ffffff' }};">
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        </div>

                                        <div class="user-text">
                                            <span class="user-name">{{ $user->name }}</span>
                                            <small>{{ $user->email }}</small>
                                        </div>
                                    </div>
                                </td>

                                <td data-label="Role">
                                    <span class="role-badge">{{ $user->role->name ?? 'N/A' }}</span>
                                </td>

                                <td data-label="Status">
                                    <div class="status-stack">
                                        <span class="status-badge {{ $user->status }}">{{ ucfirst($user->status) }}</span>

                                        @if ($user->id !== auth()->id())
                                            <label class="switch">
                                                <input type="checkbox"
                                                    onchange="document.getElementById('toggle-{{ $user->id }}').submit();"
                                                    {{ $user->status == 'active' ? 'checked' : '' }}>
                                                <span class="slider"></span>
                                            </label>
                                        @else
                                            <small class="self-tag">Current user</small>
                                        @endif
                                    </div>
                                </td>

                                <td data-label="Joined">{{ $user->created_at->format('M d, Y') }}</td>
                                <td data-label="Updated">{{ $user->updated_at->diffForHumans() }}</td>

                                <td data-label="Actions">
                                    <div class="action-group">
                                        <button type="button" class="action-btn edit-btn" data-id="{{ $user->id }}"
                                            data-name="{{ $user->name }}" data-email="{{ $user->email }}"
                                            data-role="{{ $user->role_id }}" data-status="{{ $user->status }}">
                                            <i data-lucide="pencil"></i>
                                            Edit
                                        </button>

                                        @if ($user->id !== auth()->id())
                                            <form method="POST" action="{{ route('superadmin.users.archive', $user) }}"
                                                onsubmit="return confirm('Move {{ addslashes($user->name) }} to the archive panel?')">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="action-btn archive-btn">
                                                    <i data-lucide="archive"></i>
                                                    Remove
                                                </button>
                                            </form>
                                        @endif
                                    </div>

                                    <form id="toggle-{{ $user->id }}" method="POST"
                                        action="{{ route('superadmin.users.toggle', $user->id) }}">
                                        @csrf
                                        @method('PATCH')
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i data-lucide="users"></i>
                                        <h3>No users found</h3>
                                        <p>Try adjusting the search filters or add a new team member.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrapper">
                {{ $users->appends(request()->query())->links('vendor.pagination.custom') }}
            </div>
        </div>

        <div id="editModal" class="modal">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h3>Edit User</h3>
                        <p>Update account details without leaving the page.</p>
                    </div>
                    <button class="modal-close" type="button" onclick="closeEditModal()">✕</button>
                </div>

                <form method="POST" id="editForm" class="modal-form">
                    @csrf

                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" id="editName" required>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="editEmail" required>
                    </div>

                    <div class="form-group">
                        <label>Role</label>
                        <select name="role_id" id="editRole">
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="editStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <p class="form-helper-text">If you change the role or status, ask the user to log out and sign back in
                        so the new access loads correctly.</p>

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="btn-save">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('editModal');
            const form = document.getElementById('editForm');
            const filterForm = document.querySelector('.filters');
            const editName = document.getElementById('editName');
            const editEmail = document.getElementById('editEmail');
            const editRole = document.getElementById('editRole');
            const editStatus = document.getElementById('editStatus');
            let currentUserId = null;
            let originalRole = null;
            let originalStatus = null;
            let debounceTimer;

            if (filterForm) {
                filterForm.querySelectorAll('input, select').forEach(input => {
                    input.addEventListener('input', () => {
                        clearTimeout(debounceTimer);
                        debounceTimer = setTimeout(() => filterForm.submit(), 250);
                    });

                    input.addEventListener('change', () => filterForm.submit());
                });
            }

            document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentUserId = this.dataset.id;
                    originalRole = this.dataset.role;
                    originalStatus = this.dataset.status;
                    editName.value = this.dataset.name;
                    editEmail.value = this.dataset.email;
                    editRole.value = this.dataset.role;
                    editStatus.value = this.dataset.status;
                    modal.style.display = 'flex';
                });
            });

            window.addEventListener('click', function(event) {
                if (event.target === modal) {
                    closeEditModal();
                }
            });

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeEditModal();
                }
            });

            form.addEventListener('submit', function(event) {
                event.preventDefault();

                const roleChanged = String(editRole.value) !== String(originalRole);
                const statusChanged = String(editStatus.value) !== String(originalStatus);

                if ((roleChanged || statusChanged) && !window.confirm(
                        'This team member should log out and sign in again after the access change so the new role loads correctly. Continue saving?'
                    )) {
                    return;
                }

                const data = new FormData(form);

                fetch(`/superadmin/users/${currentUserId}`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                            'X-HTTP-Method-Override': 'PUT',
                            'Accept': 'application/json'
                        },
                        body: data
                    })
                    .then(async response => {
                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            throw new Error(payload?.errors ? Object.values(payload.errors).flat()
                                .join('\n') : 'Unable to update user.');
                        }

                        return payload;
                    })
                    .then(payload => {
                        if (payload?.requires_relogin && payload?.message) {
                            alert(payload.message);
                        }

                        window.location.reload();
                    })
                    .catch(error => alert(error.message));
            });
        });

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
    </script>
@endpush
