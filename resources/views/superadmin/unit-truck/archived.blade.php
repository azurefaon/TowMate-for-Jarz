@extends('layouts.superadmin')

@section('title', 'Archived Units')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/unit-truck.css') }}">
@endpush

@section('content')
    <div class="units-page archived-page">
        <div class="page-top">
            <div>
                <h1>Archived Units</h1>
                <p>Units moved out of the active fleet. Restore them back into service or delete them permanently.</p>
            </div>
            <a href="{{ route('superadmin.unit-truck.index') }}" class="btn-light">Back to Truck</a>
        </div>

        @if (session('success'))
            <div class="type-feedback type-feedback--success" id="unitsSuccessAlert">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="type-feedback type-feedback--error" id="unitsErrorAlert">{{ session('error') }}</div>
        @endif

        <div class="table-card">
            <div class="table-header">
                <form method="GET" class="table-controls">
                    <label class="search-box">
                        <input type="text" name="search" value="{{ request('search') }}"
                            placeholder="Search by unit or plate...">
                    </label>
                    <button type="submit" class="btn-light">Search</button>
                </form>

                <span class="table-count">{{ $archivedUnits->total() }} archived units</span>
            </div>

            <div class="table-scroll">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Plate</th>
                            <th>Team Leader</th>
                            <th>Truck Type</th>
                            <th>Archived</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($archivedUnits as $unit)
                            <tr>
                                <td data-label="Unit">
                                    <span class="unit-name">{{ $unit->name }}</span>
                                </td>

                                <td data-label="Plate">
                                    <span class="plate-badge">{{ strtoupper($unit->plate_number) }}</span>
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
                                            <br>
                                            <small class="unit-warning" title="This person's account is not an active Team Leader.">
                                                ⚠ not a Team Leader
                                            </small>
                                        @endif
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Truck Type">
                                    @if ($unit->truckType)
                                        <span class="truck-badge">{{ $unit->truckType->name }}</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Archived">
                                    {{ optional($unit->archived_at)->format('M d, Y h:i A') ?? '-' }}
                                </td>

                                <td data-label="Action">
                                    <div class="row-actions">
                                        <form method="POST" action="{{ route('superadmin.units.restore', $unit->id) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="action-btn restore-btn">Restore</button>
                                        </form>

                                        <form method="POST"
                                            action="{{ route('superadmin.units.force-delete', $unit->id) }}"
                                            class="js-confirm-delete"
                                            data-confirm-title="Delete this unit permanently?"
                                            data-confirm-message="This cannot be undone. Units with booking history cannot be deleted."
                                            data-confirm-button="Delete Permanently">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="action-btn archive-btn">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <h3>No archived units</h3>
                                        <p>Units you archive from the Truck tab will appear here.</p>
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide success/error banners after 3 seconds
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
