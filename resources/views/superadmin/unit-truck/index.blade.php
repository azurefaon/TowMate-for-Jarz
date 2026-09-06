@extends('layouts.superadmin')

@section('title', 'Units Overview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/unit-truck.css') }}">
@endpush

@section('content')
    <div class="units-page" data-base-url="{{ url('/superadmin/units') }}">

        <div class="page-top">
            <div>
                <h1>Units Overview</h1>
                <p>Track towing units, team leaders, drivers, truck class rates, and dispatcher-managed availability.</p>
            </div>
        </div>

        @if (session('success'))
            <div class="type-feedback type-feedback--success" id="unitsSuccessAlert">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="type-feedback type-feedback--error" id="unitsErrorAlert">{{ session('error') }}</div>
        @endif

        @include('superadmin.fleet._tabs')

        <div class="table-card">

            <div class="table-header">
                <div class="table-controls">
                    <label class="search-box">
                        <input type="text" id="unitSearch" placeholder="Search by unit, plate, or leader...">
                    </label>
                    <select id="statusFilter" class="status-filter">
                        <option value="all">All Status</option>
                        <option value="available">Available</option>
                        <option value="on_job">On Job</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>

                <div class="table-controls" style="margin-left:auto;">
                    <a href="{{ route('superadmin.units.archived') }}" class="btn-light">
                        Archived Units{{ isset($archivedCount) && $archivedCount > 0 ? " ({$archivedCount})" : '' }}
                    </a>
                    <button type="button" class="btn-dark" data-open-modal="addUnitModal">Add Truck</button>
                </div>
            </div>

            <div class="table-scroll">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Plate</th>
                            <th>Team Leader</th>
                            <th>Driver 1</th>
                            <th>Driver 2</th>
                            <th>Crew Member 1</th>
                            <th>Crew Member 2</th>
                            <th>Truck Type</th>
                            <th>Base Rate</th>
                            <th>Per KM</th>
                            <th>Max Load</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody id="unitsTable">
                        @forelse ($units as $unit)
                            <tr data-status="{{ $unit->status }}">

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
                                    {{-- Team Leader assignment/removal/transfer is managed exclusively in
                                         Dispatcher → Units & Leaders; this view is read-only. --}}
                                    @if ($leaderName)
                                        <span class="cell-main">{{ $leaderName }}</span>
                                        @if ($leaderInvalid)
                                            <br>
                                            <small class="unit-warning" title="This person's account is not an active Team Leader - reassign or unassign via Edit.">
                                                ⚠ not a Team Leader
                                            </small>
                                        @endif
                                    @else
                                        <span class="not-assigned">No Team Leader assigned</span>
                                    @endif
                                </td>

                                <td data-label="Driver 1">
                                    @if ($unit->driver_id)
                                        <span
                                            class="cell-main">{{ $unit->driver?->full_name ?? $unit->driver?->name }}</span>
                                    @else
                                        @include('superadmin.unit-truck._crew_cell', [
                                            'unit' => $unit,
                                            'slotKey' => 'driver_1',
                                            'slotLabel' => 'Driver 1',
                                        ])
                                    @endif
                                </td>

                                <td data-label="Driver 2">
                                    @include('superadmin.unit-truck._crew_cell', [
                                        'unit' => $unit,
                                        'slotKey' => 'driver_2',
                                        'slotLabel' => 'Driver 2',
                                    ])
                                </td>

                                <td data-label="Crew Member 1">
                                    @include('superadmin.unit-truck._crew_cell', [
                                        'unit' => $unit,
                                        'slotKey' => 'crew_member_1',
                                        'slotLabel' => 'Crew Member 1',
                                    ])
                                </td>

                                <td data-label="Crew Member 2">
                                    @include('superadmin.unit-truck._crew_cell', [
                                        'unit' => $unit,
                                        'slotKey' => 'crew_member_2',
                                        'slotLabel' => 'Crew Member 2',
                                    ])
                                </td>

                                <td data-label="Truck Type">
                                    @if ($unit->truckType)
                                        <span class="truck-badge">{{ $unit->truckType->name }}</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Base Rate">
                                    @if ($unit->truckType && $unit->truckType->base_rate)
                                        <span class="rate-val">₱{{ number_format($unit->truckType->base_rate, 2) }}</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Per KM">
                                    @if ($unit->truckType && $unit->truckType->per_km_rate)
                                        <span
                                            class="rate-val">₱{{ number_format($unit->truckType->per_km_rate, 2) }}</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Max Load">
                                    @if ($unit->truckType && $unit->truckType->max_tonnage)
                                        <span class="rate-val">{{ $unit->truckType->max_tonnage }} t</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Notes">
                                    @if ($unit->issue_note)
                                        <span class="note-text"
                                            title="{{ $unit->issue_note }}">{{ $unit->issue_note }}</span>
                                    @elseif ($unit->dispatcher_note)
                                        <span class="note-text"
                                            title="{{ $unit->dispatcher_note }}">{{ $unit->dispatcher_note }}</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Status">
                                    <div class="status-stack">
                                        <span
                                            class="status-pill {{ $unit->status }}">{{ ucwords(str_replace('_', ' ', $unit->status)) }}</span>
                                    </div>
                                </td>

                                <td data-label="Action">
                                    <div class="row-actions">
                                        <button type="button" class="action-btn edit-btn js-edit-unit"
                                            data-id="{{ $unit->id }}"
                                            data-plate="{{ $unit->plate_number }}"
                                            data-truck="{{ $unit->truck_type_id }}">
                                            Edit
                                        </button>
                                        <form action="{{ route('superadmin.units.toggle', $unit->id) }}" method="POST">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="action-btn">
                                                {{ $unit->status === 'maintenance' ? 'Enable' : 'Disable' }}
                                            </button>
                                        </form>
                                        <form action="{{ route('superadmin.units.archive', $unit->id) }}" method="POST"
                                            class="js-confirm-delete"
                                            data-confirm-title="Move this unit to archive?"
                                            data-confirm-message="{{ $unit->name }} will be moved out of the active fleet. You can restore it later from Archived Units."
                                            data-confirm-button="Move to Archive">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="action-btn archive-btn"
                                                {{ $unit->status === 'on_job' ? 'disabled' : '' }}
                                                title="{{ $unit->status === 'on_job' ? 'Cannot archive a unit that is on a job' : 'Move unit to archive' }}">
                                                Archive
                                            </button>
                                        </form>
                                    </div>
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="14">
                                    <div class="empty-state">
                                        <h3>No towing units yet</h3>
                                        <p>Add the first tow unit to start organizing dispatch availability.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrapper">
                {{ $units->onEachSide(1)->links('vendor.pagination.custom') }}
            </div>

        </div>

        {{-- Add Truck Modal --}}
        <div id="addUnitModal" class="modal">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h2>Add Truck</h2>
                    </div>
                    <button type="button" class="modal-close" data-close-modal="addUnitModal">✕</button>
                </div>

                <form method="POST" action="{{ route('superadmin.units.store') }}">
                    @csrf

                    <div class="form-group">
                        <label>Unit Name</label>
                        <p class="field-note">This truck will be named <strong>{{ $nextUnitName }}</strong>
                            automatically.</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="addPlate">Plate Number</label>
                            <input type="text" name="plate_number" id="addPlate" maxlength="8" required>
                        </div>
                        <div class="form-group">
                            <label for="addTruckType">Truck Type</label>
                            <select name="truck_type_id" id="addTruckType" required>
                                <option value="">- Select -</option>
                                @foreach ($truckTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-light" data-close-modal="addUnitModal">Cancel</button>
                        <button type="submit" class="btn-dark">Add Truck</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Edit Unit Modal --}}
        <div id="editUnitModal" class="modal">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h2>Edit Tow Unit</h2>
                        <p>Update the plate number and truck type.</p>
                    </div>
                    <button type="button" class="modal-close" data-close-modal="editUnitModal">✕</button>
                </div>

                <form method="POST" id="editUnitForm">
                    @csrf
                    @method('PUT')

                    <div class="form-row">
                        <div class="form-group">
                            <label for="editPlate">Plate Number</label>
                            <input type="text" name="plate_number" id="editPlate" maxlength="8" required>
                        </div>
                        <div class="form-group">
                            <label for="editTruckType">Truck Type</label>
                            <select name="truck_type_id" id="editTruckType" required>
                                @foreach ($truckTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-light" data-close-modal="editUnitModal">Cancel</button>
                        <button type="submit" class="btn-dark">Update Unit</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="deleteDialog" class="sa-dialog-backdrop">
            <div class="sa-dialog-card">
                <h3 id="deleteDialogTitle">Confirm</h3>
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
    <script src="{{ asset('admin/js/unit-truck.js') }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const page = document.querySelector('.units-page');
            if (!page) return;

            // Auto-hide success/error banners after 3 seconds
            ['unitsSuccessAlert', 'unitsErrorAlert'].forEach((id) => {
                const alertEl = document.getElementById(id);
                if (!alertEl) return;
                setTimeout(() => {
                    alertEl.classList.add('fade-out');
                    setTimeout(() => alertEl.remove(), 300);
                }, 3000);
            });

            // Team Leader assignment and Driver/Crew borrowing are now
            // Dispatcher-only (Units & Leaders) — this page no longer wires
            // up any personnel-write modal/search/borrow JS.

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
                        this.dataset.confirmTitle || 'Confirm',
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
