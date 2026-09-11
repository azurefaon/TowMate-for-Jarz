@extends('layouts.system-admin')

@section('title', 'Archived Users')
@section('noGlobalFlash', 'true')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/system-admin-users.css') }}?v={{ filemtime(public_path('admin/css/system-admin-users.css')) }}">
@endpush

@section('content')
    <div class="user-management-page archived-page">
        <div class="page-top">
            <div></div>

            <div class="page-actions">
                <a href="{{ route('system-admin.users.index') }}" class="btn-reset">
                    Back to Users
                </a>
            </div>
        </div>

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

        <div class="table-card">
            <div class="table-toolbar-row">
                <form method="GET" class="filters" id="archivedFilterForm">
                    <div class="search-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="10.5" cy="10.5" r="6.2" />
                            <line x1="15.3" y1="15.3" x2="20" y2="20" />
                        </svg>
                        <input type="text" name="search" value="{{ request('search') }}"
                            placeholder="Search archived users..." class="search-input" id="archivedSearchInput" autocomplete="off">
                    </div>

                    @php
                        $roleLabel = 'All Roles';
                        foreach ($roles as $role) {
                            if ((string) request('role') === (string) $role->id) {
                                $roleLabel = $role->name === 'Admin' ? 'Dispatcher' : $role->name;
                            }
                        }
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
                                        @include('system-admin.users.partials.role-icon', ['roleSlug' => $roleOptionSlug])
                                    </span>
                                    <span>{{ $role->name === 'Admin' ? 'Dispatcher' : $role->name }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <input type="hidden" name="role" value="{{ request('role') }}">
                    </div>
                </form>
            </div>

            <div id="archivedTableContainer">
                @include('system-admin.users.partials.archived-table')
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
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const actionDialog = document.getElementById('actionDialog');
            const actionDialogTitle = document.getElementById('actionDialogTitle');
            const actionDialogMessage = document.getElementById('actionDialogMessage');
            const actionDialogReasonWrap = document.getElementById('actionDialogReasonWrap');
            const actionDialogReason = document.getElementById('actionDialogReason');
            const actionDialogCancel = document.getElementById('actionDialogCancel');
            const actionDialogConfirm = document.getElementById('actionDialogConfirm');

            const filterForm = document.getElementById('archivedFilterForm');
            const searchInput = document.getElementById('archivedSearchInput');
            const tableContainer = document.getElementById('archivedTableContainer');
            const archivedUrl = "{{ route('system-admin.users.archived') }}";

            let pendingAction = null;
            let dialogRequiresReason = false;
            let debounceTimer;
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

            function closeAllUaMenus(except = null) {
                document.querySelectorAll('.u-menu.is-open').forEach(menu => {
                    if (menu === except) return;
                    menu.classList.remove('is-open');
                    menu.querySelector('.u-menu-trigger')?.setAttribute('aria-expanded', 'false');
                });
            }

            function positionUaMenu(trigger, dropdown) {
                const rect = trigger.getBoundingClientRect();
                const dropdownWidth = dropdown.offsetWidth || 200;
                const dropdownHeight = dropdown.offsetHeight || 160;

                let left = rect.right - dropdownWidth;
                left = Math.max(8, Math.min(left, window.innerWidth - dropdownWidth - 8));

                let top = rect.bottom + 4;
                if (top + dropdownHeight > window.innerHeight) {
                    top = rect.top - dropdownHeight - 4;
                }
                top = Math.max(8, top);

                dropdown.style.left = `${left}px`;
                dropdown.style.top = `${top}px`;
            }

            function bindUaMenus() {
                document.querySelectorAll('.u-menu-trigger').forEach(trigger => {
                    if (trigger.dataset.menuBound === 'true') return;
                    trigger.dataset.menuBound = 'true';

                    trigger.addEventListener('click', event => {
                        event.stopPropagation();

                        const menu = trigger.closest('.u-menu');
                        const dropdown = menu?.querySelector('.u-menu-dropdown');
                        if (!menu || !dropdown) return;

                        const willOpen = !menu.classList.contains('is-open');
                        closeAllUaMenus(willOpen ? menu : null);

                        if (willOpen) positionUaMenu(trigger, dropdown);

                        menu.classList.toggle('is-open', willOpen);
                        trigger.setAttribute('aria-expanded', String(willOpen));
                    });
                });
            }

            document.addEventListener('click', () => closeAllUaMenus());
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') closeAllUaMenus();
            });
            document.addEventListener('scroll', () => closeAllUaMenus(), true);

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

            function requestFilteredTable(pushHistory = true) {
                if (!filterForm || !tableContainer) return;

                const params = new URLSearchParams(new FormData(filterForm));
                [...params.keys()].forEach(key => {
                    if (params.get(key) === '') params.delete(key);
                });

                fetchAbortController?.abort();
                fetchAbortController = new AbortController();

                fetch(`${archivedUrl}?${params.toString()}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: fetchAbortController.signal,
                }).then(response => response.text())
                  .then(html => {
                      tableContainer.innerHTML = html;
                      bindConfirmActions();
                      bindUaMenus();

                      if (pushHistory) {
                          const newUrl = params.toString() ? `${archivedUrl}?${params.toString()}` : archivedUrl;
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
            bindUaMenus();

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
        });
    </script>
@endpush
