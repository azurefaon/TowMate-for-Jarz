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


        {{-- SUMMARY CARDS --}}
        <div class="fleet-summary">

            <div class="summary-card">
                <div class="summary-icon blue">
                    <i data-lucide="truck"></i>
                </div>

                <div class="summary-left">
                    <p>Total Units</p>
                    <h3>{{ $units->count() }}</h3>
                </div>

            </div>


            <div class="summary-card">
                <div class="summary-icon green">
                    <i data-lucide="check"></i>
                </div>
                <div class="summary-left">
                    <p>Active</p>
                    <h3 class="green">{{ $units->where('status', 'available')->count() }}</h3>
                </div>

            </div>


            <div class="summary-card">
                <div class="summary-icon blue">
                    <i data-lucide="settings"></i>
                </div>
                <div class="summary-left">
                    <p>Maintenance</p>
                    <h3 class="blue">{{ $units->where('status', 'maintenance')->count() }}</h3>
                </div>

            </div>


            <div class="summary-card">
                <div class="summary-icon gray">
                    <i data-lucide="ban"></i>
                </div>
                <div class="summary-left">
                    <p>Inactive</p>
                    <h3 class="gray">{{ $units->where('status', 'inactive')->count() }}</h3>
                </div>

            </div>

        </div>


        <div class="table-card">

            {{-- TABLE HEADER --}}
            <div class="table-header">

                <h3>Fleet Units</h3>

                <div class="table-controls">

                    <div class="search-box">
                        <i data-lucide="search"></i>
                        <input type="text" id="unitSearch" placeholder="Search units...">
                    </div>

                    <select id="statusFilter" class="status-filter">
                        <option value="all">All Status</option>
                        <option value="available">Available</option>
                        <option value="on_job">On Job</option>
                        <option value="maintenance">Maintenance</option>
                    </select>

                </div>

            </div>


            <table class="modern-table">

                <thead>
                    <tr>
                        <th>Unit Name</th>
                        <th>Plate Number</th>
                        <th>Truck Type</th>
                        <th>Team Leader</th>
                        <th>Driver</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody id="unitsTable">

                    @foreach ($units as $unit)
                        <tr data-status="{{ $unit->status }}">

                            {{-- UNIT NAME --}}
                            <td class="unit-cell">

                                <div class="unit-avatar">
                                    {{ strtoupper(substr($unit->name, 0, 2)) }}
                                </div>

                                <span class="unit-name">{{ $unit->name }}</span>

                            </td>


                            {{-- PLATE --}}
                            <td>
                                <span class="plate-badge">
                                    {{ $unit->plate_number }}
                                </span>
                            </td>


                            {{-- TRUCK TYPE --}}
                            <td class="truck-cell">
                                <span class="truck-badge">
                                    {{ $unit->truckType->name }}
                                </span>
                            </td>


                            {{-- TEAM LEADER --}}
                            <td class="user-cell">

                                @if ($unit->teamLeader)
                                    <div class="avatar blue">
                                        {{ strtoupper(substr($unit->teamLeader->full_name, 0, 2)) }}
                                    </div>

                                    {{ $unit->teamLeader->full_name }}
                                @else
                                    <span class="not-assigned">Not Assigned</span>
                                @endif

                            </td>


                            {{-- STATUS --}}
                            <td>

                                <span class="status-pill {{ $unit->status }}">
                                    {{ ucfirst($unit->status) }}
                                </span>

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
@endsection

@push('scripts')
    <script>
        document.getElementById('unitSearch').addEventListener('keyup', function() {

            let search = this.value.toLowerCase();
            let rows = document.querySelectorAll('#unitsTable tr');

            rows.forEach(row => {

                let text = row.innerText.toLowerCase();

                if (text.includes(search)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }

            });

        });
    </script>
@endpush

@push('scripts')
    <script>
        const searchInput = document.getElementById('unitSearch');
        const statusFilter = document.getElementById('statusFilter');
        const rows = document.querySelectorAll('#unitsTable tr');

        function filterUnits() {

            let searchValue = searchInput.value.toLowerCase();
            let statusValue = statusFilter.value;

            rows.forEach(row => {

                let text = row.innerText.toLowerCase();
                let rowStatus = row.dataset.status;

                let matchSearch = text.includes(searchValue);
                let matchStatus = statusValue === 'all' || rowStatus === statusValue;

                if (matchSearch && matchStatus) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }

            });

        }

        searchInput.addEventListener('keyup', filterUnits);
        statusFilter.addEventListener('change', filterUnits);
    </script>
@endpush
