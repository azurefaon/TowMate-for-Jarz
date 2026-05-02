@extends('layouts.superadmin')

@section('title', 'Vehicle Types Management')

@push('styles')
    <style>
        .vehicle-types-page {
            padding: 20px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .page-header h1 {
            margin: 0 0 5px;
            font-size: 1.8rem;
        }

        .page-header p {
            margin: 0;
            color: #64748b;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
        }

        .stat-card span {
            display: block;
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 8px;
        }

        .stat-card strong {
            display: block;
            font-size: 2rem;
            color: #111827;
        }

        .table-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
        }

        .modern-table th {
            background: #f8fafc;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #111827;
            border-bottom: 1px solid #e5e7eb;
        }

        .modern-table td {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
        }

        .modern-table tbody tr:hover {
            background: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-2_wheeler {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-4_wheeler {
            background: #dcfce7;
            color: #166534;
        }

        .badge-heavy_vehicle {
            background: #fef3c7;
            color: #92400e;
        }

        .status-pill {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pill.active {
            background: #dcfce7;
            color: #166534;
        }

        .status-pill.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-primary {
            background: #111827;
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: #1f2937;
        }

        .btn-sm {
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            background: #fff;
            cursor: pointer;
            font-size: 0.85rem;
            margin-right: 5px;
        }

        .btn-sm:hover {
            background: #f8fafc;
        }

        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-content {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-content h2 {
            margin: 0 0 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #111827;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.95rem;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group select[multiple] {
            min-height: 120px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 25px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .text-center {
            text-align: center;
        }
    </style>
@endpush

@section('content')
    <div class="vehicle-types-page">
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-error">{{ session('error') }}</div>
        @endif

        <div class="page-header">
            <div>
                <h1>Vehicle Types Management</h1>
                <p>Manage customer vehicle types and link them to compatible tow trucks</p>
            </div>
            <button type="button" class="btn-primary" onclick="openAddModal()">
                <i data-lucide="plus-circle"></i>
                <span>Add Vehicle Type</span>
            </button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <span>Total Types</span>
                <strong>{{ $stats['total'] }}</strong>
            </div>
            <div class="stat-card">
                <span>Active</span>
                <strong>{{ $stats['active'] }}</strong>
            </div>
            <div class="stat-card">
                <span>2-Wheeler</span>
                <strong>{{ $stats['2_wheeler'] }}</strong>
            </div>
            <div class="stat-card">
                <span>4-Wheeler</span>
                <strong>{{ $stats['4_wheeler'] }}</strong>
            </div>
            <div class="stat-card">
                <span>Heavy Vehicle</span>
                <strong>{{ $stats['heavy_vehicle'] }}</strong>
            </div>
        </div>

        <div class="table-card">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Vehicle Type</th>
                        <th>Category</th>
                        <th>Compatible Tow Trucks</th>
                        <th>Bookings</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vehicleTypes as $type)
                        <tr>
                            <td>
                                <div>
                                    <strong>{{ $type->name }}</strong><br>
                                    <small style="color: #64748b;">{{ $type->description ?: 'No description' }}</small>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-{{ $type->category }}">{{ $type->category_label }}</span>
                            </td>
                            <td>{{ $type->truck_types_count }} truck(s)</td>
                            <td>{{ $type->bookings_count }} booking(s)</td>
                            <td>
                                <span class="status-pill {{ $type->status }}">{{ ucfirst($type->status) }}</span>
                            </td>
                            <td>
                                <button class="btn-sm" onclick='editVehicleType(@json($type))'>Edit</button>
                                <form method="POST" action="{{ route('superadmin.vehicle-types.toggle', $type->id) }}"
                                    style="display:inline;">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-sm">
                                        {{ $type->status === 'active' ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center" style="padding: 40px;">No vehicle types found</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div style="padding: 20px;">
                {{ $vehicleTypes->links() }}
            </div>
        </div>

        <!-- Add Modal -->
        <div id="addModal" class="modal" style="display:none;">
            <div class="modal-content">
                <h2>Add Vehicle Type</h2>
                <form method="POST" action="{{ route('superadmin.vehicle-types.store') }}">
                    @csrf
                    <div class="form-group">
                        <label>Vehicle Name *</label>
                        <input type="text" name="name" required placeholder="Sedan, Motorcycle, SUV">
                    </div>
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category" required>
                            <option value="">Select category</option>
                            <option value="2_wheeler">2-Wheeler</option>
                            <option value="4_wheeler">4-Wheeler</option>
                            <option value="heavy_vehicle">Heavy Vehicle</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" placeholder="Brief description of this vehicle type"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Compatible Tow Trucks (Hold Ctrl/Cmd to select multiple)</label>
                        <select name="truck_types[]" multiple>
                            @foreach ($truckTypes as $truck)
                                <option value="{{ $truck->id }}">{{ $truck->name }} -
                                    ₱{{ number_format($truck->base_rate, 2) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-sm" onclick="closeAddModal()">Cancel</button>
                        <button type="submit" class="btn-primary">Save Vehicle Type</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="modal" style="display:none;">
            <div class="modal-content">
                <h2>Edit Vehicle Type</h2>
                <form method="POST" id="editForm">
                    @csrf
                    @method('PUT')
                    <div class="form-group">
                        <label>Vehicle Name *</label>
                        <input type="text" name="name" id="editName" required>
                    </div>
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category" id="editCategory" required>
                            <option value="2_wheeler">2-Wheeler</option>
                            <option value="4_wheeler">4-Wheeler</option>
                            <option value="heavy_vehicle">Heavy Vehicle</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="editDescription"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" id="editDisplayOrder" min="0">
                    </div>
                    <div class="form-group">
                        <label>Compatible Tow Trucks</label>
                        <select name="truck_types[]" id="editTruckTypes" multiple>
                            @foreach ($truckTypes as $truck)
                                <option value="{{ $truck->id }}">{{ $truck->name }} -
                                    ₱{{ number_format($truck->base_rate, 2) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-sm" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="btn-primary">Update Vehicle Type</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }

        function editVehicleType(type) {
            document.getElementById('editForm').action = `/superadmin/vehicle-types/${type.id}`;
            document.getElementById('editName').value = type.name;
            document.getElementById('editCategory').value = type.category;
            document.getElementById('editDescription').value = type.description || '';
            document.getElementById('editDisplayOrder').value = type.display_order;

            // Load truck types for this vehicle
            fetch(`/api/vehicle-types/${type.id}/truck-types`)
                .then(response => response.json())
                .then(data => {
                    const select = document.getElementById('editTruckTypes');
                    Array.from(select.options).forEach(option => {
                        option.selected = data.truckTypeIds.includes(parseInt(option.value));
                    });
                });

            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Close modals on backdrop click
        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) closeAddModal();
        });

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });

        // Initialize Lucide icons
        lucide.createIcons();
    </script>
@endsection
