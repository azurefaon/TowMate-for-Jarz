@extends('layouts.superadmin')

@section('title', 'Tow Units')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/unit-truck.css') }}">
@endpush

@section('content')
    <div class="units-page" data-base-url="{{ url('/superadmin/units') }}">
        <div class="page-top">
            <div>
                <h1>Tow Fleet Units</h1>
                <p>Track the towing units that are available, dispatched, or under maintenance.</p>
            </div>

            <div class="header-actions">
                <a href="{{ route('superadmin.truck-types.index') }}" class="btn-secondary">
                    <i data-lucide="truck"></i>
                    <span>Manage Tow Truck Types</span>
                </a>

                <button type="button" class="btn-primary-add" data-open-modal="addUnitModal">
                    <i data-lucide="plus-circle"></i>
                    <span>Add Unit</span>
                </button>
            </div>
        </div>

        <div class="fleet-summary">
            <div class="summary-card total-card">
                <span>Total Units</span>
                <strong>{{ $stats['total'] }}</strong>
                <small>Registered towing units</small>
            </div>

            <div class="summary-card available-card">
                <span>Available</span>
                <strong>{{ $stats['available'] }}</strong>
                <small>Ready for dispatch</small>
            </div>

            <div class="summary-card on-job-card">
                <span>On Job</span>
                <strong>{{ $stats['on_job'] }}</strong>
                <small>Currently handling tow requests</small>
            </div>

            <div class="summary-card maintenance-card">
                <span>Maintenance</span>
                <strong>{{ $stats['maintenance'] }}</strong>
                <small>Temporarily unavailable</small>
            </div>
        </div>

        <div class="table-card">
            <div class="table-header">
                <div>
                    <h3>Fleet units</h3>
                    <p>Keep plate records, tow truck assignment, and maintenance notes organized.</p>
                </div>

                <div class="table-controls">
                    <label class="search-box">
                        <i data-lucide="search"></i>
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
                            <th>Tow Type</th>
                            <th>Team Leader</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody id="unitsTable">
                        @forelse ($units as $unit)
                            <tr data-status="{{ $unit->status }}">
                                <td data-label="Unit">
                                    <div class="unit-cell">
                                        <div class="unit-avatar">
                                            {{ strtoupper(substr($unit->name, 0, 2)) }}
                                        </div>
                                        <div>
                                            <span class="unit-name">{{ $unit->name }}</span>
                                            <small
                                                class="unit-subtext">{{ $unit->driver->full_name ?? ($unit->driver->name ?? 'No driver assigned') }}</small>
                                        </div>
                                    </div>
                                </td>

                                <td data-label="Plate">
                                    <span class="plate-badge">{{ strtoupper($unit->plate_number) }}</span>
                                </td>

                                <td data-label="Tow Type">
                                    <span class="truck-badge">{{ $unit->truckType->name ?? 'Unassigned' }}</span>
                                </td>

                                <td data-label="Team Leader">
                                    @if ($unit->teamLeader)
                                        {{ $unit->teamLeader->full_name ?? $unit->teamLeader->name }}
                                    @else
                                        <span class="not-assigned">Not assigned</span>
                                    @endif
                                </td>

                                <td data-label="Status">
                                    <div class="status-stack">
                                        <span
                                            class="status-pill {{ $unit->status }}">{{ ucwords(str_replace('_', ' ', $unit->status)) }}</span>
                                        @if ($unit->status === 'maintenance' && $unit->issue_note)
                                            <small>{{ $unit->issue_note }}</small>
                                        @elseif ($unit->status === 'on_job')
                                            <small>Currently dispatched</small>
                                        @endif
                                    </div>
                                </td>

                                <td data-label="Action">
                                    <button type="button" class="action-btn edit-btn js-edit-unit"
                                        data-id="{{ $unit->id }}" data-name="{{ $unit->name }}"
                                        data-plate="{{ strtoupper($unit->plate_number) }}"
                                        data-status="{{ $unit->status }}" data-issue="{{ $unit->issue_note }}"
                                        data-truck="{{ $unit->truck_type_id }}">
                                        <i data-lucide="pencil"></i>
                                        <span>Edit</span>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i data-lucide="truck"></i>
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
                        <h2>Add Tow Unit</h2>
                        <p>Register a towing unit with its truck class and initial readiness status.</p>
                    </div>
                    <button type="button" class="modal-close" data-close-modal="addUnitModal">✕</button>
                </div>

                <form method="POST" action="{{ route('superadmin.units.store') }}">
                    @csrf

                    <div class="form-group">
                        <label for="addUnitName">Unit Name</label>
                        <input id="addUnitName" type="text" name="name" placeholder="Enter unit name" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="addUnitPlate">Plate Number</label>
                            <input id="addUnitPlate" type="text" name="plate_number" placeholder="ABC 1234" required>
                        </div>

                        <div class="form-group">
                            <label for="addUnitTruckType">Tow Truck Type</label>
                            <select name="truck_type_id" id="addUnitTruckType" required>
                                <option value="">Select truck type</option>
                                @foreach ($truckTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="addUnitStatus">Initial Status</label>
                        <select name="status" id="addUnitStatus">
                            <option value="available">Available</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>

                    <div class="form-group" id="addIssueWrapper">
                        <label for="addUnitIssue">Maintenance Note</label>
                        <textarea id="addUnitIssue" name="issue_note" placeholder="Brief note if the unit is under maintenance"></textarea>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-light" data-close-modal="addUnitModal">Cancel</button>
                        <button type="submit" class="btn-dark">Save Unit</button>
                    </div>
                </form>
            </div>
        </div>

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
                            <label for="editTruckType">Tow Truck Type</label>
                            <select name="truck_type_id" id="editTruckType" required>
                                @foreach ($truckTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </div>
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
