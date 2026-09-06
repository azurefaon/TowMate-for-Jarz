@extends('layouts.superadmin')

@section('title', 'User Management')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/users.css') }}?v={{ filemtime(public_path('admin/css/users.css')) }}">
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
        </div>

        <div class="table-card">
            <div class="table-toolbar">
                <a href="{{ route('superadmin.users.archived') }}" class="btn-table-action secondary">
                    <span class="btn-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                            <path d="M4 6 L20 6 L20 9 L4 9 Z" />
                            <path d="M5 9 L5 19 L19 19 L19 9" />
                            <line x1="10" y1="13" x2="14" y2="13" />
                        </svg>
                    </span>
                    Archived Users
                </a>
                <a href="{{ route('superadmin.users.deleted') }}" class="btn-table-action secondary">
                    <span class="btn-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                            <path d="M6 8 L18 8 L17 20 L7 20 Z" />
                            <line x1="4.5" y1="8" x2="19.5" y2="8" />
                            <path d="M9.5 8 L9.5 5.5 L14.5 5.5 L14.5 8" />
                            <line x1="10.5" y1="11.5" x2="10.5" y2="16.5" />
                            <line x1="13.5" y1="11.5" x2="13.5" y2="16.5" />
                        </svg>
                    </span>
                    Pending Deletion
                </a>
                <a href="{{ route('superadmin.users.create') }}" class="btn-table-action primary">
                    <span class="btn-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                            <path d="M9 7 L11.6 4.8 L14.2 7 L13.2 10.5 L9.8 10.5 Z" />
                            <path d="M6 17 L7.2 12.5 L16.8 12.5 L18 17" />
                            <line x1="18" y1="6" x2="18" y2="10.5" />
                            <line x1="15.8" y1="8.25" x2="20.2" y2="8.25" />
                        </svg>
                    </span>
                    Add User
                </a>
            </div>

            <div class="table-header">
                <form method="GET" class="filters" id="usersFilterForm">
                    <div class="search-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="10.5" cy="10.5" r="6.2" />
                            <line x1="15.3" y1="15.3" x2="20" y2="20" />
                        </svg>
                        <input type="text" name="search" value="{{ request('search') }}"
                            placeholder="Search by name or email..." class="search-input" id="usersSearchInput" autocomplete="off">
                    </div>

                    @php
                        $roleLabel = 'All Roles';
                        foreach ($roles as $role) {
                            if ((string) request('role') === (string) $role->id) {
                                $roleLabel = $role->name === 'Admin' ? 'Dispatcher' : $role->name;
                            }
                        }

                        $statusOptions = ['' => 'All Status', 'active' => 'Active', 'inactive' => 'Inactive'];
                        $statusLabel = $statusOptions[request('status')] ?? 'All Status';

                        $sortOptions = [
                            '' => 'Newest First',
                            'oldest' => 'Oldest First',
                            'name_asc' => 'Name (A–Z)',
                            'name_desc' => 'Name (Z–A)',
                            'role' => 'Role',
                        ];
                        $sortLabel = $sortOptions[request('sort')] ?? 'Newest First';
                    @endphp

                    <div class="custom-select" data-name="role">
                        <button type="button" class="custom-select-trigger">
                            <span class="custom-select-label">{{ $roleLabel }}</span>
                            <svg class="custom-select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9 L12 15 L18 9" /></svg>
                        </button>
                        <ul class="custom-select-menu">
                            <li data-value="" class="{{ request('role') ? '' : 'is-selected' }}">All Roles</li>
                            @foreach ($roles as $role)
                                @php
                                    $roleOptionSlug = match ($role->name) {
                                        'Admin' => 'dispatcher',
                                        'Customer' => 'customer',
                                        'Team Leader' => 'team-leader',
                                        'Driver' => 'driver',
                                        default => 'default',
                                    };
                                @endphp
                                <li data-value="{{ $role->id }}" class="{{ (string) request('role') === (string) $role->id ? 'is-selected' : '' }}">
                                    <span class="role-option-icon role-{{ $roleOptionSlug }}">
                                        @include('superadmin.users.partials.role-icon', ['roleSlug' => $roleOptionSlug])
                                    </span>
                                    <span>{{ $role->name === 'Admin' ? 'Dispatcher' : $role->name }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <input type="hidden" name="role" value="{{ request('role') }}">
                    </div>

                    <div class="custom-select" data-name="status">
                        <button type="button" class="custom-select-trigger">
                            <span class="custom-select-label">{{ $statusLabel }}</span>
                            <svg class="custom-select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9 L12 15 L18 9" /></svg>
                        </button>
                        <ul class="custom-select-menu">
                            @foreach ($statusOptions as $value => $label)
                                <li data-value="{{ $value }}" class="{{ request('status', '') === $value ? 'is-selected' : '' }}">{{ $label }}</li>
                            @endforeach
                        </ul>
                        <input type="hidden" name="status" value="{{ request('status') }}">
                    </div>

                    <div class="custom-select" data-name="sort">
                        <button type="button" class="custom-select-trigger">
                            <span class="custom-select-label">{{ $sortLabel }}</span>
                            <svg class="custom-select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9 L12 15 L18 9" /></svg>
                        </button>
                        <ul class="custom-select-menu">
                            @foreach ($sortOptions as $value => $label)
                                <li data-value="{{ $value }}" class="{{ request('sort', '') === $value ? 'is-selected' : '' }}">{{ $label }}</li>
                            @endforeach
                        </ul>
                        <input type="hidden" name="sort" value="{{ request('sort') }}">
                    </div>

                    <a href="{{ route('superadmin.users.index') }}" class="btn-reset">Reset</a>
                </form>
            </div>

            <div id="usersTableContainer">
                @include('superadmin.users.partials.table')
            </div>
        </div>

        <div id="actionDialog" class="sa-dialog-backdrop">
            <div class="sa-dialog-card">
                <h3 id="actionDialogTitle">Confirm Action</h3>
                <p id="actionDialogMessage">Please confirm this action.</p>
                <div id="actionDialogReasonWrap" class="sa-dialog-reason-wrap" style="display:none;">
                    <label for="actionDialogReason">Reason <span class="required-mark">*</span></label>
                    <textarea id="actionDialogReason" class="sa-dialog-reason" rows="3" maxlength="1000" placeholder="Why is this being done?"></textarea>
                </div>
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

            const filterForm = document.getElementById('usersFilterForm');
            const searchInput = document.getElementById('usersSearchInput');
            const tableContainer = document.getElementById('usersTableContainer');
            const indexUrl = "{{ route('superadmin.users.index') }}";

            const actionDialog = document.getElementById('actionDialog');
            const actionDialogTitle = document.getElementById('actionDialogTitle');
            const actionDialogMessage = document.getElementById('actionDialogMessage');
            const actionDialogReasonWrap = document.getElementById('actionDialogReasonWrap');
            const actionDialogReason = document.getElementById('actionDialogReason');
            const actionDialogCancel = document.getElementById('actionDialogCancel');
            const actionDialogConfirm = document.getElementById('actionDialogConfirm');

            const noticeDialog = document.getElementById('noticeDialog');
            const noticeDialogTitle = document.getElementById('noticeDialogTitle');
            const noticeDialogMessage = document.getElementById('noticeDialogMessage');
            const noticeDialogOk = document.getElementById('noticeDialogOk');

            let debounceTimer;
            let pendingAction = null;
            let pendingNoticeAction = null;
            let dialogRequiresReason = false;
            let fetchAbortController = null;

            function updateConfirmEnabled() {
                if (!dialogRequiresReason) {
                    actionDialogConfirm.disabled = false;
                    return;
                }
                actionDialogConfirm.disabled = actionDialogReason.value.trim().length === 0;
            }

            actionDialogReason?.addEventListener('input', updateConfirmEnabled);

            function openActionDialog(title, message, confirmText = 'OK', onConfirm = null, variant = null, requireReason = false) {
                actionDialogTitle.textContent = title;
                actionDialogMessage.innerHTML = message;
                actionDialogConfirm.textContent = confirmText;
                actionDialogConfirm.classList.toggle('danger', variant === 'danger');
                pendingAction = onConfirm;
                dialogRequiresReason = requireReason;
                actionDialogReasonWrap.style.display = requireReason ? 'block' : 'none';
                actionDialogReason.value = '';
                updateConfirmEnabled();
                actionDialog.classList.add('is-open');
            }

            function closeActionDialog() {
                actionDialog.classList.remove('is-open');
                pendingAction = null;
                dialogRequiresReason = false;
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

            function bindConfirmActions() {
                document.querySelectorAll('.js-confirm-action').forEach(formElement => {
                    if (formElement.dataset.confirmBound === 'true') return;
                    formElement.dataset.confirmBound = 'true';

                    formElement.addEventListener('submit', function(event) {
                        event.preventDefault();

                        openActionDialog(
                            this.dataset.confirmTitle || 'Confirm Action',
                            this.dataset.confirmMessage || 'Please confirm this action.',
                            this.dataset.confirmButton || 'OK',
                            (reason) => {
                                if (reason) {
                                    const input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = 'reason';
                                    input.value = reason;
                                    this.appendChild(input);
                                }
                                this.submit();
                            },
                            this.dataset.confirmVariant || null,
                            this.dataset.requireReason === 'true'
                        );
                    });
                });
            }

            // ── Custom dropdown (Role / Status / Sort) ──────────────────
            function closeAllCustomSelects(except = null) {
                document.querySelectorAll('.custom-select.is-open').forEach(el => {
                    if (el !== except) el.classList.remove('is-open');
                });
            }

            document.querySelectorAll('.custom-select').forEach(select => {
                const trigger = select.querySelector('.custom-select-trigger');
                const label = select.querySelector('.custom-select-label');
                const hiddenInput = select.querySelector('input[type="hidden"]');
                const options = select.querySelectorAll('.custom-select-menu li');

                trigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = select.classList.contains('is-open');
                    closeAllCustomSelects();
                    select.classList.toggle('is-open', !isOpen);
                });

                options.forEach(option => {
                    option.addEventListener('click', () => {
                        options.forEach(o => o.classList.remove('is-selected'));
                        option.classList.add('is-selected');
                        label.textContent = option.textContent.trim();
                        hiddenInput.value = option.dataset.value;
                        select.classList.remove('is-open');
                        requestFilteredTable();
                    });
                });
            });

            document.addEventListener('click', () => closeAllCustomSelects());
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeAllCustomSelects();
            });

            // ── Live search: debounced AJAX, table swap only (search box keeps focus) ──
            function requestFilteredTable(pushHistory = true) {
                if (!filterForm || !tableContainer) return;

                const params = new URLSearchParams(new FormData(filterForm));
                [...params.keys()].forEach(key => {
                    if (params.get(key) === '') params.delete(key);
                });

                fetchAbortController?.abort();
                fetchAbortController = new AbortController();

                fetch(`${indexUrl}?${params.toString()}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: fetchAbortController.signal,
                }).then(response => response.text())
                  .then(html => {
                      tableContainer.innerHTML = html;
                      bindConfirmActions();

                      if (pushHistory) {
                          const newUrl = params.toString() ? `${indexUrl}?${params.toString()}` : indexUrl;
                          window.history.replaceState({}, '', newUrl);
                      }
                  })
                  .catch(err => {
                      if (err.name !== 'AbortError') console.error('Filter request failed', err);
                  });
            }

            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => requestFilteredTable(), 350);
                });
            }

            bindConfirmActions();

            actionDialogCancel?.addEventListener('click', closeActionDialog);
            actionDialogConfirm?.addEventListener('click', () => {
                if (dialogRequiresReason && actionDialogReason.value.trim().length === 0) {
                    return;
                }

                const callback = pendingAction;
                const reason = actionDialogReason.value.trim();
                closeActionDialog();

                if (typeof callback === 'function') {
                    callback(reason);
                }
            });

            noticeDialogOk?.addEventListener('click', closeNoticeDialog);

        });
    </script>
@endpush
