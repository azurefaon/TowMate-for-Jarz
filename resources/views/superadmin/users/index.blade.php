@extends('layouts.superadmin')

@section('title', 'User Management')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/users.css') }}">
    <style>
        .user-management-page .modal-card {
            max-width: 900px;
            /* lalaki na */
            width: 95%;
        }

        .user-management-page .modal-form {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        /* full width fields */
        .user-management-page .modal-form .form-helper-text,
        .user-management-page .modal-actions {
            grid-column: span 2;
        }

        .sa-dialog-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 16px;
        }

        .sa-dialog-backdrop.is-open {
            display: flex;
        }

        .sa-dialog-card {
            width: min(460px, 100%);
            background: #fff;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.18);
        }

        .sa-dialog-card h3 {
            margin: 0 0 8px;
            color: #0f172a;
        }

        .sa-dialog-card p {
            margin: 0;
            color: #475569;
            line-height: 1.5;
        }

        .sa-dialog-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .sa-dialog-btn {
            border: 0;
            border-radius: 12px;
            padding: 10px 16px;
            font-weight: 600;
            cursor: pointer;
        }

        .sa-dialog-btn.cancel {
            background: #e2e8f0;
            color: #0f172a;
        }

        .sa-dialog-btn.confirm {
            background: linear-gradient(135deg, #111827, #1f2937);
            color: #fff;
        }

        .user-view-switch {
            display: inline-flex;
            gap: 8px;
            padding: 6px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            margin: 14px 0 0;
            flex-wrap: wrap;
        }

        .user-view-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            color: #475569;
        }

        .user-view-link.active {
            background: #111827;
            color: #fff;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.15);
        }

        .request-review-card {
            margin: 20px 0 22px;
            padding: 18px;
            border-radius: 18px;
            border: 1px solid #fde68a;
            background: linear-gradient(180deg, #fffdf4 0%, #ffffff 100%);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
        }

        .request-review-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .request-review-head h2 {
            margin: 0 0 4px;
            color: #0f172a;
            font-size: 1.05rem;
        }

        .request-review-head p {
            margin: 0;
            color: #475569;
        }

        .request-review-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
        }

        .request-item {
            padding: 14px;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            background: #fff;
        }

        .request-item strong {
            display: block;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .request-meta,
        .request-note,
        .request-time {
            color: #475569;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .request-note {
            margin-top: 8px;
        }

        .request-time {
            display: inline-flex;
            margin-top: 8px;
            padding: 5px 8px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .request-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .request-actions form {
            margin: 0;
        }

        .request-password-form {
            width: 100%;
            display: grid;
            gap: 8px;
            margin-top: 2px;
        }

        .request-password-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .request-field {
            display: grid;
            gap: 4px;
        }

        .request-field span {
            font-size: 0.74rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .request-field input {
            width: 100%;
            min-height: 40px;
            border-radius: 10px;
            border: 1px solid #dbe3ef;
            background: #fff;
            padding: 10px 12px;
            font-size: 0.9rem;
            color: #0f172a;
        }

        .request-field-help {
            color: #64748b;
            font-size: 0.78rem;
            margin: 0;
        }

        .request-btn {
            border: 0;
            border-radius: 10px;
            padding: 8px 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .request-btn.primary {
            background: #111827;
            color: #fff;
        }

        .request-btn.secondary {
            background: #f8fafc;
            color: #0f172a;
            border: 1px solid #e2e8f0;
        }

        .request-pill {
            display: inline-flex;
            align-items: center;
            margin-top: 6px;
            padding: 4px 8px;
            border-radius: 999px;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fdba74;
            font-size: 0.74rem;
            font-weight: 700;
        }
    </style>
@endpush

@section('content')
    <div class="user-management-page">
        <div class="page-top">
            <div>
                <h1>User Management</h1>
            </div>

            <div class="page-actions">
                <a href="{{ route('superadmin.users.create') }}" class="btn-primary-add">
                    <i data-lucide="user-plus"></i>
                    Add User
                </a>
            </div>
        </div>

        @php
            $tlRole    = $roles->firstWhere('name', 'Team Leader');
            $dispRole  = $roles->firstWhere('name', 'Admin');
            $activeTab = request()->filled('role') ? (int) request('role') : null;
        @endphp
        <div class="user-view-switch">
            <a href="{{ route('superadmin.users.index') }}"
               class="user-view-link {{ $activeTab === null ? 'active' : '' }}">
                <i data-lucide="users"></i>
                All Users
            </a>
            @if ($tlRole)
                <a href="{{ route('superadmin.users.index', ['role' => $tlRole->id]) }}"
                   class="user-view-link {{ $activeTab === (int) $tlRole->id ? 'active' : '' }}">
                    <i data-lucide="hard-hat"></i>
                    Team Leaders
                </a>
            @endif
            @if ($dispRole)
                <a href="{{ route('superadmin.users.index', ['role' => $dispRole->id]) }}"
                   class="user-view-link {{ $activeTab === (int) $dispRole->id ? 'active' : '' }}">
                    <i data-lucide="radio"></i>
                    Dispatchers
                </a>
            @endif
            <a href="{{ route('superadmin.users.archived') }}" class="user-view-link">
                <i data-lucide="archive"></i>
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
                                    <div class="status-stack">
                                        <span class="status-badge {{ $user->status }}">{{ ucfirst($user->status) }}</span>

                                        @if ($dispatcherOnline)
                                            <small class="self-tag">Dispatcher online</small>
                                        @endif

                                        @if ($user->id !== auth()->id())
                                            <label class="switch"
                                                title="{{ $dispatcherOnline ? 'This dispatcher is online and cannot be changed right now.' : 'Toggle user status' }}">
                                                <input type="checkbox"
                                                    onchange="document.getElementById('toggle-{{ $user->id }}').submit();"
                                                    {{ $user->status == 'active' ? 'checked' : '' }}
                                                    {{ $dispatcherOnline ? 'disabled' : '' }}>
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
                                        <a href="{{ route('superadmin.users.edit', $user->id) }}" class="action-btn">
                                            <i data-lucide="pencil"></i>
                                            Edit
                                        </a>

                                        @if ($user->id !== auth()->id())
                                            <form method="POST" action="{{ route('superadmin.users.archive', $user) }}"
                                                class="js-confirm-action" data-confirm-title="Move user to archive?"
                                                data-confirm-message="{{ $user->name }} will be moved to the archive panel."
                                                data-confirm-button="Move to Archive">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="action-btn archive-btn"
                                                    {{ $dispatcherOnline ? 'disabled' : '' }}
                                                    title="{{ $dispatcherOnline ? 'This dispatcher is online and cannot be removed right now.' : 'Move user to archive' }}">
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
