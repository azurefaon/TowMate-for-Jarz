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
                            <th>Driver</th>
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
                                    @endphp
                                    @if ($leaderName)
                                        <span class="cell-main">{{ $leaderName }}</span>
                                    @else
                                        <span class="not-assigned">—</span>
                                    @endif
                                </td>

                                <td data-label="Driver">
                                    @php
                                        $driverName =
                                            $unit->driver?->full_name ?? ($unit->driver?->name ?? $unit->driver_name);
                                    @endphp
                                    @if ($driverName)
                                        <span class="cell-main">{{ $driverName }}</span>
                                    @else
                                        <span class="not-assigned">—</span>
                                    @endif
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
                                            data-driver-id="{{ $unit->driver_id }}">
                                            Edit
                                        </button>
                                        <form action="{{ route('superadmin.units.toggle', $unit->id) }}" method="POST">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="action-btn">
                                                {{ $unit->status === 'maintenance' ? 'Enable' : 'Disable' }}
                                            </button>
                                        </form>
                                    </div>
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="11">
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
                                <option value="{{ $leader->id }}">
                                    {{ $leader->full_name ?: $leader->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="editDriverId">Driver</label>
                        <select name="driver_id" id="editDriverId">
                            <option value="">— Unassigned —</option>
                            @foreach ($drivers as $driver)
                                <option value="{{ $driver->id }}">
                                    {{ $driver->full_name ?: $driver->name }}
                                </option>
                            @endforeach
                        </select>
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

    </div>
@endsection

@push('scripts')
    <script src="{{ asset('admin/js/unit-truck.js') }}" defer></script>
@endpush
