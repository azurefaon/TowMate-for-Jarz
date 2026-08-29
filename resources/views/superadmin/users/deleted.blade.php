@extends('layouts.superadmin')

@section('title', 'Users Pending Deletion')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/users.css') }}?v={{ filemtime(public_path('admin/css/users.css')) }}">
@endpush

@section('content')
    <div class="user-management-page archived-page">
        <div class="page-top">
            <div>
                <h1>Users Pending Deletion</h1>
            </div>

            <div class="page-actions">
                <a href="{{ route('superadmin.users.index') }}" class="btn-reset btn-back">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 5 L5 12 L11 19" />
                        <path d="M5.5 12 L19 12" />
                    </svg>
                    Back
                </a>
            </div>
        </div>

        <div class="table-card">
            <div class="table-header">
                <form method="GET" class="filters" id="deletedFilterForm">
                    <div class="search-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="10.5" cy="10.5" r="6.2" />
                            <line x1="15.3" y1="15.3" x2="20" y2="20" />
                        </svg>
                        <input type="text" name="search" value="{{ request('search') }}"
                            placeholder="Search deleted users..." class="search-input" id="deletedSearchInput" autocomplete="off">
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
                                        @include('superadmin.users.partials.role-icon', ['roleSlug' => $roleOptionSlug])
                                    </span>
                                    <span>{{ $role->name === 'Admin' ? 'Dispatcher' : $role->name }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <input type="hidden" name="role" value="{{ request('role') }}">
                    </div>
                </form>
            </div>

            <div id="deletedTableContainer">
                @include('superadmin.users.partials.deleted-table')
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

            const filterForm = document.getElementById('deletedFilterForm');
            const searchInput = document.getElementById('deletedSearchInput');
            const tableContainer = document.getElementById('deletedTableContainer');
            const deletedUrl = "{{ route('superadmin.users.deleted') }}";

            let debounceTimer;
            let fetchAbortController = null;

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

            function bindConfirmActions() {
                document.querySelectorAll('.js-confirm-delete').forEach(form => {
                    if (form.dataset.confirmBound === 'true') return;
                    form.dataset.confirmBound = 'true';

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
            }

            // ── Custom dropdown (Role) ───────────────────────────────────
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

            // ── Live search: debounced AJAX, table swap only ─────────────
            function requestFilteredTable(pushHistory = true) {
                if (!filterForm || !tableContainer) return;

                const params = new URLSearchParams(new FormData(filterForm));
                [...params.keys()].forEach(key => {
                    if (params.get(key) === '') params.delete(key);
                });

                fetchAbortController?.abort();
                fetchAbortController = new AbortController();

                fetch(`${deletedUrl}?${params.toString()}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: fetchAbortController.signal,
                }).then(response => response.text())
                  .then(html => {
                      tableContainer.innerHTML = html;
                      bindConfirmActions();

                      if (pushHistory) {
                          const newUrl = params.toString() ? `${deletedUrl}?${params.toString()}` : deletedUrl;
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
