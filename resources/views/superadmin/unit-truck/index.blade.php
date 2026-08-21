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
            <div class="page-actions" style="display:flex;gap:10px;">
                <a href="{{ route('superadmin.units.archived') }}" class="btn-light">
                    Archived Units{{ isset($archivedCount) && $archivedCount > 0 ? " ({$archivedCount})" : '' }}
                </a>
                <button type="button" class="btn-dark" data-open-modal="addUnitModal">Add Truck</button>
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
                            <th>Truck Class</th>
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
                                    @if ($leaderName)
                                        <span class="cell-main">{{ $leaderName }}</span>
                                        @if ($leaderInvalid)
                                            <br>
                                            <small class="unit-warning" title="This person's account is not an active Team Leader — reassign or unassign via Edit.">
                                                ⚠ not a Team Leader
                                            </small>
                                        @endif
                                    @else
                                        <span class="not-assigned">—</span>
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

                                <td data-label="Truck Class">
                                    @if ($unit->truckType)
                                        <span class="truck-badge">{{ $unit->truckType->name }}</span>
                                        @if ($unit->truckType->class)
                                            <small class="unit-subtext">{{ $unit->truckType->class }}</small>
                                        @endif
                                    @else
                                        <span class="not-assigned">—</span>
                                    @endif
                                </td>

                                <td data-label="Base Rate">
                                    @if ($unit->truckType && $unit->truckType->base_rate)
                                        <span class="rate-val">₱{{ number_format($unit->truckType->base_rate, 2) }}</span>
                                    @else
                                        <span class="not-assigned">—</span>
                                    @endif
                                </td>

                                <td data-label="Per KM">
                                    @if ($unit->truckType && $unit->truckType->per_km_rate)
                                        <span
                                            class="rate-val">₱{{ number_format($unit->truckType->per_km_rate, 2) }}</span>
                                    @else
                                        <span class="not-assigned">—</span>
                                    @endif
                                </td>

                                <td data-label="Max Load">
                                    @if ($unit->truckType && $unit->truckType->max_tonnage)
                                        <span class="rate-val">{{ $unit->truckType->max_tonnage }} t</span>
                                    @else
                                        <span class="not-assigned">—</span>
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
                                        <span class="not-assigned">—</span>
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
                                            data-id="{{ $unit->id }}" data-name="{{ $unit->name }}"
                                            data-plate="{{ $unit->plate_number }}" data-status="{{ $unit->status }}"
                                            data-issue="{{ e($unit->issue_note) }}"
                                            data-truck="{{ $unit->truck_type_id }}"
                                            data-leader-id="{{ $unit->team_leader_id }}"
                                            data-driver-id="{{ $unit->driver_id }}"
                                            data-driver-name="{{ $unit->driver_name }}"
                                            data-driver2-name="{{ $unit->driver_2_name }}"
                                            data-crew1-name="{{ $unit->crew_member_1_name }}"
                                            data-crew2-name="{{ $unit->crew_member_2_name }}">
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
                            <input type="text" name="plate_number" id="addPlate" required>
                        </div>
                        <div class="form-group">
                            <label for="addTruckType">Truck Class</label>
                            <select name="truck_type_id" id="addTruckType" required>
                                <option value="">— Select —</option>
                                @foreach ($truckTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="addLeaderId">Team Leader</label>
                        <select name="team_leader_id" id="addLeaderId">
                            <option value="">— Unassigned —</option>
                            @foreach ($teamLeaders as $leader)
                                <option value="{{ $leader->id }}" @if ($leader->unit_count > 0) disabled @endif>
                                    {{ $leader->full_name ?: $leader->name }}{{ $leader->unit_count > 0 ? ' (already assigned to a unit)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="addDriverName">Driver Name (Driver 1)</label>
                        <select name="driver_name" id="addDriverName">
                            <option value="">— Unassigned —</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="addDriver2Name">Driver 2 (optional)</label>
                        <input type="text" name="driver_2_name" id="addDriver2Name"
                            placeholder="Optional second driver">
                    </div>

                    <div class="form-group">
                        <label for="addCrew1Name">Crew Member 1 (optional)</label>
                        <input type="text" name="crew_member_1_name" id="addCrew1Name" placeholder="Optional">
                    </div>

                    <div class="form-group">
                        <label for="addCrew2Name">Crew Member 2 (optional)</label>
                        <input type="text" name="crew_member_2_name" id="addCrew2Name" placeholder="Optional">
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
                        <p>Update the unit record, plate, truck class, and maintenance state.</p>
                    </div>
                    <button type="button" class="modal-close" data-close-modal="editUnitModal">✕</button>
                </div>

                <form method="POST" id="editUnitForm">
                    @csrf
                    @method('PUT')

                    <div class="form-group">
                        <label for="editName">Unit Name</label>
                        <input type="text" name="name" id="editName" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="editPlate">Plate Number</label>
                            <input type="text" name="plate_number" id="editPlate" required>
                        </div>
                        <div class="form-group">
                            <label for="editTruckType">Truck Class</label>
                            <select name="truck_type_id" id="editTruckType" required>
                                @foreach ($truckTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="editLeaderId">Team Leader</label>
                        <select name="team_leader_id" id="editLeaderId">
                            <option value="">— Unassigned —</option>
                            @foreach ($teamLeaders as $leader)
                                <option value="{{ $leader->id }}" data-name="{{ $leader->full_name ?: $leader->name }}">
                                    {{ $leader->full_name ?: $leader->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="editDriverName">Driver Name (Driver 1)</label>
                        <select name="driver_name" id="editDriverName">
                            <option value="">— Unassigned —</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="editDriver2Name">Driver 2 (optional)</label>
                        <input type="text" name="driver_2_name" id="editDriver2Name"
                            placeholder="Optional second driver">
                    </div>

                    <div class="form-group">
                        <label for="editCrew1Name">Crew Member 1 (optional)</label>
                        <input type="text" name="crew_member_1_name" id="editCrew1Name" placeholder="Optional">
                    </div>

                    <div class="form-group">
                        <label for="editCrew2Name">Crew Member 2 (optional)</label>
                        <input type="text" name="crew_member_2_name" id="editCrew2Name" placeholder="Optional">
                    </div>

                    <div class="form-group">
                        <label for="editStatus">Status</label>
                        <select name="status" id="editStatus">
                            <option value="available">Available</option>
                            <option value="on_job">On Job</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>

                    <div class="form-group" id="editIssueWrapper">
                        <label for="editIssue">Maintenance Note</label>
                        <textarea name="issue_note" id="editIssue" placeholder="Add maintenance details if needed"></textarea>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-light" data-close-modal="editUnitModal">Cancel</button>
                        <button type="submit" class="btn-dark">Update Unit</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Borrow Crew Modal --}}
        <div id="borrowCrewModal" class="modal">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h2>Borrow Crew</h2>
                        <p>Temporarily move a driver or crew member from another unit into <strong
                                id="borrowToLabel"></strong>.</p>
                    </div>
                    <button type="button" class="modal-close" data-close-modal="borrowCrewModal">✕</button>
                </div>

                <form method="POST" id="borrowCrewForm">
                    @csrf
                    <input type="hidden" name="to_slot" id="borrowToSlot">

                    <div class="form-group">
                        <label for="borrowFromUnit">Borrow from unit</label>
                        <select name="from_unit_id" id="borrowFromUnit" required>
                            <option value="">— Select unit —</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="borrowFromSlot">Person to borrow</label>
                        <select name="from_slot" id="borrowFromSlot" required>
                            <option value="">— Select unit first —</option>
                        </select>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-light" data-close-modal="borrowCrewModal">Cancel</button>
                        <button type="submit" class="btn-dark">Borrow</button>
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
    <script id="crewUnitsData" type="application/json">{!! json_encode($crewUnitsData) !!}</script>
    <script id="teamLeaderStagedData" type="application/json">{!! json_encode($teamLeaderStagedData) !!}</script>
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

            const baseUrl = page.dataset.baseUrl;
            const crewUnits = JSON.parse(document.getElementById('crewUnitsData').textContent || '[]');
            const teamLeaderStagedData = JSON.parse(document.getElementById('teamLeaderStagedData').textContent ||
                '[]');

            // ── Driver 1 dropdown: only ever offers "Unassigned" + the selected Team Leader's staged driver ──
            const rebuildDriverOptions = (selectId, leaderId, preserveValue) => {
                const select = document.getElementById(selectId);
                if (!select) return;
                const staged = leaderId ? teamLeaderStagedData.find(l => l.id === leaderId) : null;
                const stagedName = staged?.driver_name || '';

                select.innerHTML = '';
                const unassigned = document.createElement('option');
                unassigned.value = '';
                unassigned.textContent = '— Unassigned —';
                select.appendChild(unassigned);

                if (stagedName) {
                    const opt = document.createElement('option');
                    opt.value = stagedName;
                    opt.textContent = stagedName;
                    select.appendChild(opt);
                }

                // Preserve an existing value (e.g. when opening Edit on a unit whose
                // driver doesn't match its team leader's staged name) so it isn't lost.
                if (preserveValue && preserveValue !== stagedName) {
                    const opt = document.createElement('option');
                    opt.value = preserveValue;
                    opt.textContent = preserveValue;
                    select.appendChild(opt);
                }

                select.value = preserveValue || stagedName || '';
            };

            // Auto-fill Crew Member 1 / 2 (still free text) + rebuild the Driver 1 dropdown
            // whenever the Team Leader selection changes.
            const wireStagedAutofill = (leaderSelectId, driverSelectId, crew1Id, crew2Id) => {
                const leaderSelect = document.getElementById(leaderSelectId);
                const crew1Input = document.getElementById(crew1Id);
                const crew2Input = document.getElementById(crew2Id);
                if (!leaderSelect) return;

                leaderSelect.addEventListener('change', () => {
                    const leaderId = parseInt(leaderSelect.value, 10) || null;
                    rebuildDriverOptions(driverSelectId, leaderId, null);

                    if (!leaderId) return;
                    const staged = teamLeaderStagedData.find(l => l.id === leaderId);
                    if (!staged) return;

                    if (crew1Input && !crew1Input.value && staged.crew_member_1_name) {
                        crew1Input.value = staged.crew_member_1_name;
                    }
                    if (crew2Input && !crew2Input.value && staged.crew_member_2_name) {
                        crew2Input.value = staged.crew_member_2_name;
                    }
                });
            };

            wireStagedAutofill('addLeaderId', 'addDriverName', 'addCrew1Name', 'addCrew2Name');
            wireStagedAutofill('editLeaderId', 'editDriverName', 'editCrew1Name', 'editCrew2Name');

            // When opening Edit, rebuild Driver 1's options around this unit's current
            // team leader + driver name (independent of the click handler in unit-truck.js).
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('.js-edit-unit');
                if (!btn) return;
                const leaderId = parseInt(btn.dataset.leaderId, 10) || null;
                rebuildDriverOptions('editDriverName', leaderId, btn.dataset.driverName || null);

                // Label any Team Leader who currently leads a DIFFERENT unit, so picking
                // them here makes clear they'll be moved off that unit.
                const editingUnitId = parseInt(btn.dataset.id, 10) || null;
                document.querySelectorAll('#editLeaderId option[data-name]').forEach(opt => {
                    const staged = teamLeaderStagedData.find(l => l.id === parseInt(opt.value, 10));
                    const baseName = opt.dataset.name;
                    if (staged && staged.current_unit_id && staged.current_unit_id !== editingUnitId) {
                        opt.textContent = `${baseName} (currently leading ${staged.current_unit_name})`;
                    } else {
                        opt.textContent = baseName;
                    }
                });
            });

            const slotColumn = {
                driver_1: 'driver_name',
                driver_2: 'driver_2_name',
                crew_member_1: 'crew_member_1_name',
                crew_member_2: 'crew_member_2_name',
            };
            const slotLabel = {
                driver_1: 'Driver 1',
                driver_2: 'Driver 2',
                crew_member_1: 'Crew Member 1',
                crew_member_2: 'Crew Member 2',
            };
            const slotType = (slot) => slot.startsWith('driver_') ? 'driver' : 'crew_member';

            const borrowModal = document.getElementById('borrowCrewModal');
            const borrowForm = document.getElementById('borrowCrewForm');
            const borrowToLabel = document.getElementById('borrowToLabel');
            const borrowToSlot = document.getElementById('borrowToSlot');
            const borrowFromUnit = document.getElementById('borrowFromUnit');
            const borrowFromSlot = document.getElementById('borrowFromSlot');

            const showModal = (m) => {
                if (m) m.style.display = 'flex';
            };
            const hideModal = (m) => {
                if (m) m.style.display = 'none';
            };

            let currentToUnitId = null;
            let currentToSlot = null;

            const populateFromUnits = () => {
                const type = slotType(currentToSlot);
                borrowFromUnit.innerHTML = '<option value="">— Select unit —</option>';

                crewUnits
                    .filter(u => u.id !== currentToUnitId)
                    .filter(u => {
                        return Object.keys(slotColumn).some(slot => slotType(slot) === type && u[slotColumn[
                            slot]]);
                    })
                    .forEach(u => {
                        const opt = document.createElement('option');
                        opt.value = u.id;
                        opt.textContent = u.name;
                        borrowFromUnit.appendChild(opt);
                    });

                borrowFromSlot.innerHTML = '<option value="">— Select unit first —</option>';
            };

            const populateFromSlots = () => {
                const type = slotType(currentToSlot);
                const unitId = parseInt(borrowFromUnit.value, 10);
                borrowFromSlot.innerHTML = '<option value="">— Select person —</option>';
                if (!unitId) return;

                const unit = crewUnits.find(u => u.id === unitId);
                if (!unit) return;

                Object.keys(slotColumn)
                    .filter(slot => slotType(slot) === type && unit[slotColumn[slot]])
                    .forEach(slot => {
                        const opt = document.createElement('option');
                        opt.value = slot;
                        opt.textContent = `${unit[slotColumn[slot]]} (${slotLabel[slot]})`;
                        borrowFromSlot.appendChild(opt);
                    });
            };

            borrowFromUnit.addEventListener('change', populateFromSlots);

            document.querySelectorAll('.js-borrow-crew').forEach(btn => {
                btn.addEventListener('click', () => {
                    currentToUnitId = parseInt(btn.dataset.toUnit, 10);
                    currentToSlot = btn.dataset.toSlot;

                    borrowToLabel.textContent =
                        `${btn.dataset.toUnitName} — ${btn.dataset.toSlotLabel}`;
                    borrowToSlot.value = currentToSlot;
                    borrowForm.action = `${baseUrl}/${currentToUnitId}/borrow-crew`;

                    populateFromUnits();
                    showModal(borrowModal);
                });
            });

            document.querySelectorAll('[data-close-modal="borrowCrewModal"]').forEach(btn => {
                btn.addEventListener('click', () => hideModal(borrowModal));
            });
            borrowModal?.addEventListener('click', (e) => {
                if (e.target === borrowModal) hideModal(borrowModal);
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
