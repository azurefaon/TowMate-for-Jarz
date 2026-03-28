@extends('layouts.superadmin')

@section('content')
    <div class="user-management-page">

        <div class="page-top">
            <div>
                <h1>User Management</h1>
                <p>Manage system accounts and permissions</p>
            </div>
        </div>

        <div class="table-card">

            {{-- TABLE HEADER FILTER BAR --}}
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

                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>
                            Active
                        </option>

                        <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>
                            Inactive
                        </option>

                    </select>

                    <button type="submit" class="btn-filter">
                        Filter
                    </button>

                    <a href="{{ route('superadmin.users.index') }}" class="btn-reset">
                        Reset
                    </a>

                </form>

                {{-- RIGHT SIDE ACTIONS --}}
                <div class="table-actions-right">

                    <span class="table-count">
                        Showing {{ $users->count() }} of {{ $users->total() }} users
                    </span>

                    <a href="{{ route('superadmin.users.create') }}" class="btn-primary-add">

                        <i data-lucide="user-plus"></i>
                        Add User

                    </a>

                </div>

            </div>

            <div class="table-card">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($users as $user)
                            <tr>

                                <td>
                                    <div class="user-info">

                                        <div class="avatar"
                                            style="background: {{ ['#6ea8ff', '#2ecc71', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#ec4899'][crc32($user->name) % 7] }}">
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        </div>

                                        <div class="user-text">
                                            <span class="user-name">{{ $user->name }}</span>
                                        </div>

                                    </div>
                                </td>

                                <td>{{ $user->email }}</td>
                                <td>{{ $user->role->name ?? 'N/A' }}</td>

                                <td>
                                    <label class="switch">
                                        <input type="checkbox"
                                            onchange="document.getElementById('toggle-{{ $user->id }}').submit();"
                                            {{ $user->status == 'active' ? 'checked' : '' }}>
                                        <span class="slider"></span>
                                    </label>
                                </td>

                                <td>{{ $user->created_at->format('F d, Y') }}</td>

                                <td>
                                    <button class="edit-btn" data-id="{{ $user->id }}" data-name="{{ $user->name }}"
                                        data-email="{{ $user->email }}" data-role="{{ $user->role_id }}"
                                        data-status="{{ $user->status }}">
                                        Edit
                                    </button>

                                    <form id="toggle-{{ $user->id }}" method="POST"
                                        action="{{ route('superadmin.users.toggle', $user->id) }}">
                                        @csrf
                                        @method('PATCH')
                                    </form>
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">No users found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            {{ $users->appends(request()->query())->links('vendor.pagination.custom') }}

        </div>

        {{-- EDIT MODAL --}}
        <div id="editModal" class="modal">

            <div class="modal-card">

                <div class="modal-header">
                    <h3>Edit User</h3>
                    <button class="modal-close" onclick="closeEditModal()">✕</button>
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

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="btn-save">Save Changes</button>
                    </div>

                </form>

            </div>

        </div>
    @endsection


    @push('scripts')
        <script>
            document.addEventListener("DOMContentLoaded", function() {

                const modal = document.getElementById('editModal');
                const form = document.getElementById('editForm');
                let currentUserId = null;

                document.querySelectorAll('.edit-btn').forEach(btn => {
                    btn.addEventListener('click', function() {

                        currentUserId = this.dataset.id;

                        editName.value = this.dataset.name;
                        editEmail.value = this.dataset.email;
                        editRole.value = this.dataset.role;
                        editStatus.value = this.dataset.status;

                        modal.style.display = 'flex';
                    });
                });

                window.onclick = function(e) {
                    if (e.target === modal) {
                        modal.style.display = 'none';
                    }
                };

                document.addEventListener("keydown", function(e) {
                    if (e.key === "Escape") {
                        modal.style.display = 'none';
                    }
                });

                form.addEventListener('submit', function(e) {

                    e.preventDefault();

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
                        .then(() => location.reload());

                });

            });

            function closeEditModal() {
                document.getElementById('editModal').style.display = 'none';
            }
        </script>
    @endpush
