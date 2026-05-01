@extends('layouts.superadmin')

@section('title', 'User Management')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/users.css') }}">
@endpush

@section('content')
    <div class="user-management-page">

        {{-- Session flash (normal form POST redirects) --}}
        @if (session('success'))
            <div class="sa-flash-banner sa-flash-success" id="saFlashBanner">
                <span>{{ session('success') }}</span>
                <button type="button" onclick="this.closest('.sa-flash-banner').remove()" class="sa-flash-close">×</button>
            </div>
        @elseif (session('error'))
            <div class="sa-flash-banner sa-flash-error" id="saFlashBanner">
                <span>{{ session('error') }}</span>
                <button type="button" onclick="this.closest('.sa-flash-banner').remove()" class="sa-flash-close">×</button>
            </div>
        @endif

        <div class="page-top">
            <div>
                <h1>User Management</h1>
            </div>

            <div class="page-actions">
                <a href="{{ route('superadmin.users.create') }}" class="btn-primary-add">
                    {{-- <i data-lucide="user-plus"></i> --}}
                    Add User
                </a>
            </div>
        </div>

        @php
            $tlRole = $roles->firstWhere('name', 'Team Leader');
            $dispRole = $roles->firstWhere('name', 'Admin');
            $activeTab = request()->filled('role') ? (int) request('role') : null;
        @endphp
        <div class="user-view-switch">
            <a href="{{ route('superadmin.users.index') }}"
                class="user-view-link {{ $activeTab === null ? 'active' : '' }}">
                {{-- <i data-lucide="users"></i> --}}
                All Users
            </a>
            @if ($tlRole)
                <a href="{{ route('superadmin.users.index', ['role' => $tlRole->id]) }}"
                    class="user-view-link {{ $activeTab === (int) $tlRole->id ? 'active' : '' }}">
                    {{-- <i data-lucide="hard-hat"></i> --}}
                    Team Leaders
                </a>
            @endif
            @if ($dispRole)
                <a href="{{ route('superadmin.users.index', ['role' => $dispRole->id]) }}"
                    class="user-view-link {{ $activeTab === (int) $dispRole->id ? 'active' : '' }}">
                    {{-- <i data-lucide="radio"></i> --}}
                    Dispatchers
                </a>
            @endif
            <a href="{{ route('superadmin.users.archived') }}" class="user-view-link">
                {{-- <i data-lucide="archive"></i> --}}
                Archived Users
            </a>
        </div>

        @if (($passwordRequests ?? collect())->isNotEmpty())
            <div class="request-review-card">
                <div class="request-review-head">
                    <div>
                        <h2>Account Access Requests</h2>
                        <p>These requests were sent from the login page by managed users who need account access support.
                        </p>
                    </div>
                </div>

                <div class="request-review-grid">
                    @foreach ($passwordRequests as $requestUser)
                        <div class="request-item">
                            <strong>{{ $requestUser->name }}</strong>
                            <div class="request-meta">{{ $requestUser->email }} • {{ $requestUser->role->name ?? 'User' }}
                            </div>
                            <div class="request-time">Requested
                                {{ optional($requestUser->password_requested_at)?->diffForHumans() ?? 'just now' }}</div>
                            <p class="request-note">
                                {{ $requestUser->password_request_note ?: 'No note was added to this request.' }}</p>

                            <div class="request-actions">
                                <form method="POST"
                                    action="{{ route('superadmin.users.password-request.set-password', $requestUser) }}"
                                    class="request-password-form js-confirm-action"
                                    data-confirm-title="Set default password?"
                                    data-confirm-message="This will save the password you entered for {{ $requestUser->name }} and mark the request as handled."
                                    data-confirm-button="Save Password">
                                    @csrf
                                    @method('PATCH')

                                    <div class="request-password-grid">
                                        <label class="request-field">
                                            <span>Default Password</span>
                                            <input type="password" name="password" required minlength="8"
                                                placeholder="Enter password">
                                        </label>

                                        <label class="request-field">
                                            <span>Confirm Password</span>
                                            <input type="password" name="password_confirmation" required minlength="8"
                                                placeholder="Confirm password">
                                        </label>
                                    </div>

                                    <p class="request-field-help">Set a temporary default password, then ask the user to
                                        change it after login.</p>
                                    <button type="submit" class="request-btn primary">Set Default Password</button>
                                </form>

                                <form method="POST"
                                    action="{{ route('superadmin.users.password-request.resolve', $requestUser) }}"
                                    class="js-confirm-action" data-confirm-title="Mark request handled?"
                                    data-confirm-message="This will clear the access request for {{ $requestUser->name }}."
                                    data-confirm-button="Mark Handled">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="request-btn secondary">Mark Handled</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="table-card">
            <div class="table-header">
                <form method="GET" class="filters">
                    <div class="search-container">
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
                                        <div class="avatar">
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        </div>

                                        <div class="user-text">
                                            <span class="user-name">{{ $user->name }}</span>
                                            <small>{{ $user->email }}</small>
                                            @if ($user->password_request_status === 'pending')
                                                <span class="request-pill">Password request pending</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                <td data-label="Role">
                                    <span class="role-badge">{{ $user->role->name ?? 'N/A' }}</span>
                                </td>

                                <td data-label="Status">
                                    @php
                                        $dispatcherOnline =
                                            (int) $user->role_id === 2 &&
                                            \Illuminate\Support\Facades\Cache::has('dispatcher:presence:' . $user->id);
                                    @endphp
                                    <span class="status-badge {{ $user->status }}">{{ ucfirst($user->status) }}</span>
                                    @if ($user->id === auth()->id())
                                        <small class="self-tag">You</small>
                                    @elseif ($dispatcherOnline)
                                        <small class="self-tag">Online</small>
                                    @endif
                                </td>

                                <td data-label="Joined">{{ $user->created_at->format('M d, Y') }}</td>
                                <td data-label="Updated">{{ $user->updated_at->diffForHumans() }}</td>

                                <td data-label="Actions">
                                    <div class="action-group">
                                        <a href="{{ route('superadmin.users.edit', $user->id) }}"
                                            class="action-btn edit-btn">Edit</a>

                                        @if ($user->id !== auth()->id())
                                            {{-- Active / Inactive toggle --}}
                                            <form method="POST"
                                                action="{{ route('superadmin.users.toggle', $user->id) }}"
                                                style="display:inline;">
                                                @csrf
                                                @method('PATCH')
                                                @if ($user->status === 'active')
                                                    <button type="submit" class="action-btn deactivate-btn"
                                                        {{ $dispatcherOnline ? 'disabled' : '' }}
                                                        title="{{ $dispatcherOnline ? 'Dispatcher is online' : 'Set user inactive' }}">Inactive</button>
                                                @else
                                                    <button type="submit" class="action-btn activate-btn"
                                                        title="Set user active">Active</button>
                                                @endif
                                            </form>

                                            {{-- Archive / Remove --}}
                                            <form method="POST" action="{{ route('superadmin.users.archive', $user) }}"
                                                class="js-confirm-action" data-confirm-title="Move user to archive?"
                                                data-confirm-message="{{ $user->name }} will be moved to the archive panel."
                                                data-confirm-button="Move to Archive" style="display:inline;">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="action-btn archive-btn"
                                                    {{ $dispatcherOnline ? 'disabled' : '' }}
                                                    title="{{ $dispatcherOnline ? 'Dispatcher is online' : 'Move user to archive' }}">Remove</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
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

        <div id="actionDialog" class="sa-dialog-backdrop">
            <div class="sa-dialog-card">
                <h3 id="actionDialogTitle">Confirm Action</h3>
                <p id="actionDialogMessage">Please confirm this action.</p>
                <div class="sa-dialog-actions">
                    <button type="button" class="sa-dialog-btn cancel" id="actionDialogCancel">Cancel</button>
                    <button type="button" class="sa-dialog-btn confirm" id="actionDialogConfirm">OK</button>
                </div>
            </div>
        </div>

        <div id="noticeDialog" class="sa-dialog-backdrop">
            <div class="sa-dialog-card">
                <h3 id="noticeDialogTitle">Notice</h3>
                <p id="noticeDialogMessage">Update saved.</p>
                <div class="sa-dialog-actions">
                    <button type="button" class="sa-dialog-btn confirm" id="noticeDialogOk">OK</button>
                </div>
            </div>
        </div>

        {{-- <div id="editModal" class="modal">
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
                        <label>New Password (optional)</label>
                        <input type="password" name="password">
                    </div>

                    <div class="form-group">
                        <label>Driver First Name</label>
                        <input type="text" name="driver_first_name" id="editDriverFirst">
                    </div>

                    <div class="form-group">
                        <label>Driver Middle Name</label>
                        <input type="text" name="driver_middle_name" id="editDriverMiddle">
                    </div>

                    <div class="form-group">
                        <label>Driver Last Name</label>
                        <input type="text" name="driver_last_name" id="editDriverLast">
                    </div>

                    <div class="form-group">
                        <label>Unit Name</label>
                        <input type="text" name="unit_name" id="editUnitName">
                    </div>

                    <div class="form-group">
                        <label>Role</label>
                        <select name="role_id" id="editRole" disabled>
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

                    <p class="form-helper-text">Role is locked after user creation. Only name, email, and status can be
                        updated here.</p>

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="btn-save">Save Changes</button>
                    </div>
                </form>
            </div>
        </div> --}}
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') lucide.createIcons();

            // Show flash from AJAX edit redirect (sessionStorage)
            const storedFlash = sessionStorage.getItem('sa_flash_success');
            if (storedFlash) {
                sessionStorage.removeItem('sa_flash_success');
                const banner = document.createElement('div');
                banner.className = 'sa-flash-banner sa-flash-success';
                banner.innerHTML =
                    `<span>${storedFlash}</span><button type="button" onclick="this.closest('.sa-flash-banner').remove()" class="sa-flash-close">×</button>`;
                document.querySelector('.user-management-page')?.prepend(banner);
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }

            // Auto-hide flash banners after 5 s
            document.querySelectorAll('.sa-flash-banner').forEach(el => {
                setTimeout(() => el.style.transition = 'opacity 0.4s', 4600);
                setTimeout(() => {
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 420);
                }, 5000);
            });

            const filterForm = document.querySelector('.filters');

            const actionDialog = document.getElementById('actionDialog');
            const actionDialogTitle = document.getElementById('actionDialogTitle');
            const actionDialogMessage = document.getElementById('actionDialogMessage');
            const actionDialogCancel = document.getElementById('actionDialogCancel');
            const actionDialogConfirm = document.getElementById('actionDialogConfirm');

            const noticeDialog = document.getElementById('noticeDialog');
            const noticeDialogTitle = document.getElementById('noticeDialogTitle');
            const noticeDialogMessage = document.getElementById('noticeDialogMessage');
            const noticeDialogOk = document.getElementById('noticeDialogOk');

            let debounceTimer;
            let pendingAction = null;
            let pendingNoticeAction = null;

            function openActionDialog(title, message, confirmText = 'OK', onConfirm = null) {
                actionDialogTitle.textContent = title;
                actionDialogMessage.textContent = message;
                actionDialogConfirm.textContent = confirmText;
                pendingAction = onConfirm;
                actionDialog.classList.add('is-open');
            }

            function closeActionDialog() {
                actionDialog.classList.remove('is-open');
                pendingAction = null;
            }

            function openNoticeDialog(message, title = 'Notice', onClose = null) {
                noticeDialogTitle.textContent = title;
                noticeDialogMessage.textContent = message;
                pendingNoticeAction = onClose;
                noticeDialog.classList.add('is-open');
            }

            function closeNoticeDialog() {
                noticeDialog.classList.remove('is-open');

                if (typeof pendingNoticeAction === 'function') {
                    const callback = pendingNoticeAction;
                    pendingNoticeAction = null;
                    callback();
                } else {
                    pendingNoticeAction = null;
                }
            }

            if (filterForm) {
                filterForm.querySelectorAll('input, select').forEach(input => {
                    input.addEventListener('input', () => {
                        clearTimeout(debounceTimer);
                        debounceTimer = setTimeout(() => filterForm.submit(), 250);
                    });

                    input.addEventListener('change', () => filterForm.submit());
                });
            }

            // document.querySelectorAll('.edit-btn').forEach(btn => {
            //     btn.addEventListener('click', function() {

            //         document.getElementById('editDriverFirst').value = this.dataset.driver_first ||
            //             '';
            //         document.getElementById('editDriverMiddle').value = this.dataset
            //             .driver_middle || '';
            //         document.getElementById('editDriverLast').value = this.dataset.driver_last ||
            //             '';

            //         document.getElementById('editUnitName').value = this.dataset.unit || '';

            //         currentUserId = this.dataset.id;
            //         originalStatus = this.dataset.status;

            //         editName.value = this.dataset.name;
            //         editEmail.value = this.dataset.email;
            //         editRole.value = this.dataset.role;
            //         editStatus.value = this.dataset.status;

            //         modal.style.display = 'flex';
            //     });
            // });

            document.querySelectorAll('.js-confirm-action').forEach(formElement => {
                formElement.addEventListener('submit', function(event) {
                    event.preventDefault();

                    openActionDialog(
                        this.dataset.confirmTitle || 'Confirm Action',
                        this.dataset.confirmMessage || 'Please confirm this action.',
                        this.dataset.confirmButton || 'OK',
                        () => this.submit()
                    );
                });
            });

            actionDialogCancel?.addEventListener('click', closeActionDialog);
            actionDialogConfirm?.addEventListener('click', () => {
                const callback = pendingAction;
                closeActionDialog();

                if (typeof callback === 'function') {
                    callback();
                }
            });

            noticeDialogOk?.addEventListener('click', closeNoticeDialog);

        });
    </script>
@endpush
