@extends('layouts.superadmin')

@section('title', 'Units Overview')

@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="{{ asset('admin/css/unit-truck.css') }}?v={{ filemtime(public_path('admin/css/unit-truck.css')) }}">
@endpush

@section('content')
    <div class="units-page" data-base-url="{{ url('/superadmin/units') }}">

        <div class="page-top">
            <div>
                <h1>Units Overview</h1>
                <p>Track towing units, Truck Type rates, and dispatcher-managed team assignments.</p>
            </div>
        </div>

        @if (session('success'))
            <div class="type-feedback type-feedback--success" id="unitsSuccessAlert">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="type-feedback type-feedback--error" id="unitsErrorAlert">{{ session('error') }}</div>
        @endif

        @include('superadmin.fleet._tabs')

        <div class="u-toolbar">
            <div class="u-toolbar-left">
                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" id="unitSearch" placeholder="Search by unit, plate, or leader...">
                </div>

                <select id="statusFilter" data-custom>
                    <option value="all">All Status</option>
                    <option value="available">Available</option>
                    <option value="on_job">On Job</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>

            <div class="u-toolbar-right">
                <a href="{{ route('superadmin.units.archived') }}" class="u-archived-btn">
                    <i data-lucide="archive"></i>
                    Archived Units{{ isset($archivedCount) && $archivedCount > 0 ? " ({$archivedCount})" : '' }}
                </a>
                <button type="button" class="tt-add-btn" data-open-modal="addUnitModal">
                    <i data-lucide="plus"></i>
                    Add Truck
                </button>
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
                            <th>Team</th>
                            <th class="align-right">Base Rate</th>
                            <th class="align-right">Per km</th>
                            <th class="align-right">Max Load</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th class="u-actions-col"></th>
                        </tr>
                    </thead>

                    <tbody id="unitsTable">
                        @forelse ($units as $unit)
                            <tr data-status="{{ $unit->status }}">

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

                                <td data-label="Team">
                                    @include('superadmin.unit-truck._team_column', ['unit' => $unit])
                                </td>

                                <td data-label="Base Rate" class="align-right">
                                    @if ($unit->truckType && $unit->truckType->base_rate)
                                        <span class="cell-main">₱{{ number_format($unit->truckType->base_rate, 2) }}</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Per km" class="align-right">
                                    @if ($unit->truckType && $unit->truckType->per_km_rate)
                                        <span class="cell-main">₱{{ number_format($unit->truckType->per_km_rate, 2) }}</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Max Load" class="align-right">
                                    @if ($unit->truckType && $unit->truckType->max_tonnage)
                                        <span class="cell-main">{{ $unit->truckType->max_tonnage }} kg</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Notes">
                                    @if ($unit->issue_note)
                                        <span class="note-text" title="{{ $unit->issue_note }}">{{ $unit->issue_note }}</span>
                                    @elseif ($unit->dispatcher_note)
                                        <span class="note-text" title="{{ $unit->dispatcher_note }}">{{ $unit->dispatcher_note }}</span>
                                    @else
                                        <span class="not-assigned">-</span>
                                    @endif
                                </td>

                                <td data-label="Status">
                                    <span class="status-text status-{{ $unit->status }}">{{ ucwords(str_replace('_', ' ', $unit->status)) }}</span>
                                </td>

                                <td data-label="Actions" class="u-actions-col">
                                    <div class="u-menu">
                                        <button type="button" class="u-menu-trigger" aria-haspopup="menu"
                                            aria-expanded="false" aria-label="Actions for {{ $unit->name }}">
                                            <i data-lucide="more-vertical"></i>
                                        </button>

                                        <div class="u-menu-dropdown" role="menu">
                                            <button type="button" class="u-menu-item js-edit-unit" role="menuitem"
                                                data-id="{{ $unit->id }}"
                                                data-plate="{{ $unit->plate_number }}"
                                                data-truck="{{ $unit->truck_type_id }}">
                                                <i data-lucide="pencil"></i>
                                                <span>Edit Unit</span>
                                            </button>

                                            @if ($unit->status === 'maintenance')
                                                <form method="POST" class="u-menu-form"
                                                    action="{{ route('superadmin.units.toggle', $unit->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="u-menu-item u-menu-item--positive" role="menuitem">
                                                        <i data-lucide="check"></i>
                                                        <span>Enable Unit</span>
                                                    </button>
                                                </form>
                                            @else
                                                <form method="POST" class="u-menu-form"
                                                    action="{{ route('superadmin.units.toggle', $unit->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="u-menu-item u-menu-item--danger" role="menuitem"
                                                        {{ $unit->status === 'on_job' ? 'disabled' : '' }}
                                                        title="{{ $unit->status === 'on_job' ? 'This truck cannot be disabled while it is assigned to an active job.' : '' }}">
                                                        <i data-lucide="ban"></i>
                                                        <span>Disable Unit</span>
                                                    </button>
                                                </form>
                                            @endif

                                            <div class="u-menu-divider"></div>

                                            <form method="POST" class="u-menu-form js-confirm-delete"
                                                action="{{ route('superadmin.units.archive', $unit->id) }}"
                                                data-confirm-title="Move this unit to archive?"
                                                data-confirm-message="{{ $unit->name }} will be moved out of the active fleet. You can restore it later from Archived Units."
                                                data-confirm-button="Move to Archive">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="u-menu-item" role="menuitem"
                                                    {{ $unit->status === 'on_job' ? 'disabled' : '' }}
                                                    title="{{ $unit->status === 'on_job' ? 'Cannot archive a unit that is on a job' : '' }}">
                                                    <i data-lucide="archive"></i>
                                                    <span>Archive Unit</span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="10">
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

        <div id="addUnitModal" class="modal">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h2>Add Truck</h2>
                        <p>Enter the basic information for this truck. It will be named automatically.</p>
                    </div>
                    <button type="button" class="modal-close" data-close-modal="addUnitModal" aria-label="Close Add Truck">
                        <i data-lucide="x"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('superadmin.units.store') }}">
                    @csrf

                    <div class="form-group">
                        <label>Unit Name</label>
                        <p class="field-note">This truck will be named <strong>{{ $nextUnitName }}</strong>
                            automatically.</p>
                        <div class="u-readonly-field">{{ $nextUnitName }}</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="addPlate">Plate Number</label>
                            <input type="text" name="plate_number" id="addPlate" maxlength="8" placeholder="ABC 1234" required>
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
                        <button type="submit" class="btn-dark">
                            <i data-lucide="plus"></i>
                            Add Truck
                        </button>
                    </div>
                </form>
            </div>
        </div>

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
    <script src="{{ asset('admin/js/unit-truck.js') }}?v={{ filemtime(public_path('admin/js/unit-truck.js')) }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const page = document.querySelector('.units-page');
            if (!page) return;

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
