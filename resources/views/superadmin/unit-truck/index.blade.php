@extends('layouts.superadmin')

@section('content')
    <div class="page-container">

        <div class="page-top">
            <div>
                <h1>Unit Overview</h1>
                <p>Monitor towing fleet availability and assignments</p>
            </div>

            <div class="header-actions">
                <a href="{{ route('superadmin.truck-types.index') }}" class="btn-secondary">
                    <i data-lucide="truck"></i>
                    <span>Manage Truck Types >></span>
                </a>
            </div>
        </div>

        <div class="fleet-summary">

            <div class="summary-card blue">
                <div class="summary-icon blue"></div>
                <div class="summary-left">
                    <p>Total Units</p>
                    <h3>{{ $units->count() }}</h3>
                </div>
            </div>

            <div class="summary-card green">
                <div class="summary-icon green"></div>
                <div class="summary-left">
                    <p>Available</p>
                    <h3 class="green">{{ $units->where('status', 'available')->count() }}</h3>
                </div>
            </div>

            <div class="summary-card orange">
                <div class="summary-icon blue"></div>
                <div class="summary-left">
                    <p>Maintenance</p>
                    <h3 class="blue">{{ $units->where('status', 'maintenance')->count() }}</h3>
                </div>
            </div>

        </div>

        <div class="table-card">

            <div class="table-header">
                <h3>Fleet Units</h3>

                <div class="table-controls">
                    <div class="search-box">
                        <i data-lucide="search"></i>
                        <input type="text" id="unitSearch" placeholder="Search units...">
                    </div>

                    <select id="statusFilter" class="status-filter">
                        <option value="all">All</option>
                        <option value="available">Available</option>
                        <option value="on_job">On Job</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>
            </div>

            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th>Plate</th>
                        <th>Truck</th>
                        <th>Team Leader</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody id="unitsTable">
                    @foreach ($units as $unit)
                        <tr data-status="{{ $unit->status }}">

                            <td class="unit-cell">
                                <div class="unit-avatar">
                                    {{ strtoupper(substr($unit->name, 0, 2)) }}
                                </div>
                                <span class="unit-name">{{ $unit->name }}</span>
                            </td>

                            <td>
                                <span class="plate-badge">{{ $unit->plate_number }}</span>
                            </td>

                            <td>
                                <span class="truck-badge">{{ $unit->truckType->name }}</span>
                            </td>

                            <td>
                                @if ($unit->teamLeader)
                                    {{ $unit->teamLeader->full_name }}
                                @else
                                    <span class="not-assigned">Not Assigned</span>
                                @endif
                            </td>

                            <td>
                                <span class="status-pill {{ $unit->status }}">
                                    {{ ucfirst($unit->status) }}
                                </span>
                            </td>

                            <td>
                                <button class="edit-btn" data-id="{{ $unit->id }}" data-name="{{ $unit->name }}"
                                    data-plate="{{ $unit->plate_number }}" data-status="{{ $unit->status }}"
                                    data-issue="{{ $unit->issue_note }}" data-truck="{{ $unit->truck_type_id }}"
                                    onclick="openEditModal(this)">
                                    Edit
                                </button>
                            </td>

                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="pagination-wrapper">
                {{ $units->links() }}
            </div>

        </div>

    </div>

    <div id="editModal" class="modal">
        <div class="modal-card">

            <div class="modal-header">
                <h2>Edit Unit</h2>
                <button onclick="closeEditModal()">✕</button>
            </div>

            <form method="POST" id="editForm">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label>Unit Name</label>
                    <input type="text" name="name" id="editName">
                </div>

                <div class="form-group">
                    <label>Plate Number</label>
                    <input type="text" name="plate_number" id="editPlate">
                </div>

                <div class="form-group">
                    <label>Truck Type</label>
                    <select name="truck_type_id" id="editTruckType">
                        @foreach ($truckTypes as $type)
                            <option value="{{ $type->id }}">
                                {{ $type->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="editStatus">
                        <option value="available">Available</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>

                <div class="form-group" id="issueWrapper">
                    <label>Issue Note</label>
                    <textarea name="issue_note" id="editIssue"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeEditModal()">Cancel</button>
                    <button type="submit">Save</button>
                </div>

            </form>

        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function openEditModal(btn) {
            let id = btn.dataset.id;

            document.getElementById('editName').value = btn.dataset.name;
            document.getElementById('editPlate').value = btn.dataset.plate;
            document.getElementById('editStatus').value = btn.dataset.status;
            document.getElementById('editIssue').value = btn.dataset.issue || '';
            document.getElementById('editTruckType').value = btn.dataset.truck;

            document.getElementById('editForm').action = `/superadmin/unit-truck/${id}`;

            document.getElementById('editModal').style.display = 'flex';
            toggleIssue();
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        const statusSelect = document.getElementById('editStatus');
        const issueWrapper = document.getElementById('issueWrapper');

        function toggleIssue() {
            issueWrapper.style.display =
                statusSelect.value === 'maintenance' ? 'block' : 'none';
        }

        statusSelect.addEventListener('change', toggleIssue);

        const searchInput = document.getElementById('unitSearch');
        const statusFilter = document.getElementById('statusFilter');

        searchInput.addEventListener('keyup', filterUnits);
        statusFilter.addEventListener('change', filterUnits);

        function filterUnits() {
            let search = searchInput.value.toLowerCase();
            let status = statusFilter.value;

            document.querySelectorAll('#unitsTable tr').forEach(row => {
                let text = row.innerText.toLowerCase();
                let rowStatus = row.dataset.status;

                row.style.display =
                    text.includes(search) &&
                    (status === 'all' || status === rowStatus) ?
                    '' : 'none';
            });
        }
    </script>
@endpush
