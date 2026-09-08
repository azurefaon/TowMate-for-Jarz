@extends('layouts.superadmin')

@section('title', 'Archived Units')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/unit-truck.css') }}?v={{ filemtime(public_path('admin/css/unit-truck.css')) }}">
@endpush

@section('content')
    <div class="units-page archived-page" data-base-url="{{ url('/superadmin/units') }}">
        <div class="page-top">
            <div>
                <h1>Archived Units</h1>
                <p>View trucks removed from the active fleet. Restore them when needed or permanently remove archived records.</p>
            </div>
            <a href="{{ route('superadmin.unit-truck.index') }}" class="u-back-btn">
                <i data-lucide="arrow-left"></i>
                Back to Trucks
            </a>
        </div>

        @if (session('success'))
            <div class="type-feedback type-feedback--success" id="unitsSuccessAlert">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="type-feedback type-feedback--error" id="unitsErrorAlert">{{ session('error') }}</div>
        @endif

        <div class="u-toolbar">
            <form method="GET" class="u-toolbar-left">
                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by unit or plate...">
                </div>
                <button type="submit" class="u-search-btn">Search</button>
            </form>

            <div class="u-toolbar-right">
                <span class="u-archived-count">
                    <span class="u-archived-count-label">Archived trucks</span>
                    <span class="u-archived-count-value">{{ $archivedUnits->total() }} record{{ $archivedUnits->total() === 1 ? '' : 's' }}</span>
                </span>
            </div>
        </div>

        <div class="table-card">
            <div class="table-scroll">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Plate</th>
                            <th>Truck Type</th>
                            <th>Team Leader</th>
                            <th>Archived Date</th>
                            <th class="u-actions-col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($archivedUnits as $unit)
                            <tr>
                                <td data-label="Unit">
                                    <span class="cell-main">{{ $unit->name }}</span>
                                </td>

                                <td data-label="Plate">
                                    <span class="cell-main">{{ strtoupper($unit->plate_number) }}</span>
                                </td>

                                <td data-label="Truck Type">
                                    @if ($unit->truckType)
                                        <span class="cell-main">{{ $unit->truckType->name }}</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Team Leader">
                                    @php
                                        $leaderName = $unit->teamLeader?->full_name ?? $unit->teamLeader?->name;
                                        $leaderInvalid = $unit->teamLeader && (
                                            ($unit->teamLeader->role?->name !== 'Team Leader')
                                            || $unit->teamLeader->archived_at
                                        );
                                    @endphp
                                    @if ($leaderName)
                                        <span class="cell-main">{{ $leaderName }}</span>
                                        @if ($leaderInvalid)
                                            <span class="cell-sub" title="This person's account is not an active Team Leader.">&#9888; not a Team Leader</span>
                                        @endif
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Archived Date">
                                    @if ($unit->archived_at)
                                        <span class="cell-main">{{ $unit->archived_at->format('M j, Y') }}</span>
                                        <span class="cell-sub">{{ $unit->archived_at->format('g:i A') }}</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Actions" class="u-actions-col">
                                    <div class="u-menu">
                                        <button type="button" class="u-menu-trigger" aria-haspopup="menu"
                                            aria-expanded="false" aria-label="Actions for {{ $unit->name }}">
                                            <i data-lucide="more-vertical"></i>
                                        </button>

                                        <div class="u-menu-dropdown" role="menu">
                                            <form method="POST" class="u-menu-form"
                                                action="{{ route('superadmin.units.restore', $unit->id) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="u-menu-item u-menu-item--positive" role="menuitem">
                                                    <i data-lucide="archive-restore"></i>
                                                    <span>Restore Unit</span>
                                                </button>
                                            </form>

                                            <div class="u-menu-divider"></div>

                                            <form method="POST" class="u-menu-form js-confirm-delete"
                                                action="{{ route('superadmin.units.force-delete', $unit->id) }}"
                                                data-confirm-title="Delete this archived truck permanently?"
                                                data-confirm-message="This action cannot be undone. Units with booking history cannot be deleted."
                                                data-confirm-button="Delete Permanently">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="u-menu-item u-menu-item--danger" role="menuitem">
                                                    <i data-lucide="trash-2"></i>
                                                    <span>Delete Permanently</span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i data-lucide="archive" class="empty-state-icon"></i>
                                        <h3>No archived trucks</h3>
                                        <p>Trucks archived from the active fleet will appear here.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrapper">
                {{ $archivedUnits->appends(request()->query())->links('vendor.pagination.custom') }}
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
    <script src="{{ asset('admin/js/unit-truck.js') }}?v={{ filemtime(public_path('admin/js/unit-truck.js')) }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            ['unitsSuccessAlert', 'unitsErrorAlert'].forEach((id) => {
                const alertEl = document.getElementById(id);
                if (!alertEl) return;
                setTimeout(() => {
                    alertEl.classList.add('fade-out');
                    setTimeout(() => alertEl.remove(), 300);
                }, 3000);
            });

            const deleteDialog = document.getElementById('deleteDialog');
            const deleteDialogTitle = document.getElementById('deleteDialogTitle');
            const deleteDialogMessage = document.getElementById('deleteDialogMessage');
            const deleteDialogCancel = document.getElementById('deleteDialogCancel');
            const deleteDialogConfirm = document.getElementById('deleteDialogConfirm');
            let pendingDelete = null;

            function openDeleteDialog(title, message, confirmText, onConfirm) {
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
