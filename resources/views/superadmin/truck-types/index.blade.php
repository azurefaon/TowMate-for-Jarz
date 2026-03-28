@extends('layouts.superadmin')

@section('content')

<div class="page-top">

    <div>
        <h1>Truck Type Management</h1>
        <p>Super Admin Panel - Manage truck types and pricing</p>
    </div>

    <div class="header-actions">

        <a href="{{ route('superadmin.unit-truck.index') }}" class="btn-back">
            <i data-lucide="arrow-left"></i>
            <span>Back</span>
        </a>

        <button onclick="openAddModal()" class="btn-primary-add">
            <i data-lucide="plus-circle"></i>
            <span>Add Truck Type</span>
        </button>

    </div>

</div>

<div class="table-card">
    <table class="modern-table">
        <thead>
            <tr>
                <th>Truck Type</th>
                <th>Base Rate</th>
                <th>Per KM Rate</th>
                <th>Max Tonnage</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse($truckTypes as $type)
            <tr>
                <td>
                    <strong>{{ $type->name }}</strong><br>
                    <small>{{ $type->description }}</small>
                </td>
                <td>₱{{ number_format($type->base_rate,2) }}</td>
                <td>₱{{ number_format($type->per_km_rate,2) }}</td>
                <td>{{ $type->max_tonnage }} tons</td>
                <td>
                    <span class="status-pill {{ $type->status }}">
                        {{ ucfirst($type->status) }}
                    </span>
                </td>
                <td class="table-actions">
                    <div class="action-buttons">

                        <button
                            class="edit-btn"
                            data-id="{{ $type->id }}"
                            data-name="{{ $type->name }}"
                            data-base="{{ $type->base_rate }}"
                            data-km="{{ $type->per_km_rate }}"
                            data-tonnage="{{ $type->max_tonnage }}"
                            data-description="{{ $type->description }}"
                            onclick="openEditModal(this)">
                            <i data-lucide="pencil"></i>
                            <span>Edit</span>
                        </button>

                        @if($type->status === 'active')
                        <button onclick="openDisableModal" ({{ $type->id }}, '{{ $type->name }}' )" class="btn-danger">
                            <i data-lucide="ban"></i>
                            <span>Disable</span>
                        </button>
                        @else
                        <form method="POST" action="{{ route('superadmin.truck-types.toggle',$type->id) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn-success">
                                <i data-lucide="check-circle"></i>
                                <span>Enable</span>
                            </button>
                        </form>
                        @endif

                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6">No truck types found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ADD MODAL --}}
<div id="addModal" class="modal">
    <div class="modal-card">

        <div class="modal-header">
            <div>
                <h2>Add New Truck Type</h2>
                <p>Create a new truck type with pricing information. All fields are required except description.</p>
            </div>
            <button class="modal-close" onclick="closeAddModal()">✕</button>
        </div>

        <form method="POST" action="{{ route('superadmin.truck-types.store') }}">
            @csrf

            <div class="form-group">
                <label>Truck Type Name *</label>
                <input type="text" name="name" placeholder="e.g., Small Pickup, Large Truck" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Base Rate (₱) *</label>
                    <input type="number" step="0.01" name="base_rate" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label>Per KM Rate (₱) *</label>
                    <input type="number" step="0.01" name="per_km_rate" placeholder="0.00" required>
                </div>
            </div>

            <div class="form-group">
                <label>Max Tonnage (tons) *</label>
                <input type="number" step="0.01" name="max_tonnage" placeholder="0.0" required>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" placeholder="Add any additional details about this truck type..."></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeAddModal()" class="btn-light">Cancel</button>
                <button type="submit" class="btn-dark">Add Truck Type</button>
            </div>
        </form>

    </div>
</div>

{{-- EDIT MODAL --}}
<div id="editModal" class="modal">
    <div class="modal-card">

        <div class="modal-header">
            <div>
                <h2>Edit Truck Type</h2>
                <p>Update pricing and details for this truck type. Changes will affect future bookings.</p>
            </div>
            <button class="modal-close" onclick="closeEditModal()">✕</button>
        </div>

        <form method="POST" id="editForm">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label>Truck Type Name *</label>
                <input type="text" name="name" id="editName" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Base Rate (₱) *</label>
                    <input type="number" step="0.01" name="base_rate" id="editBase" required>
                </div>

                <div class="form-group">
                    <label>Per KM Rate (₱) *</label>
                    <input type="number" step="0.01" name="per_km_rate" id="editKm" required>
                </div>
            </div>

            <div class="form-group">
                <label>Max Tonnage (tons) *</label>
                <input type="number" step="0.01" name="max_tonnage" id="editTonnage" required>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="editDescription"></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeEditModal()" class="btn-light">
                    Cancel
                </button>
                <button type="submit" class="btn-dark">
                    Update Truck Type
                </button>
            </div>
        </form>

    </div>
</div>

{{-- DISABLE CONFIRM MODAL --}}
<div id="disableModal" class="modal">
    <div class="modal-content">
        <h3>Disable Truck Type?</h3>
        <p id="disableText"></p>

        <form method="POST" id="disableForm">
            @csrf
            @method('PATCH')

            <div class="modal-actions">
                <button type="button" onclick="closeDisableModal()" class="btn-cancel">Cancel</button>
                <button class="btn-danger">
                    <i data-lucide="ban"></i>
                    <span>Disable</span>
                </button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
    document.addEventListener("DOMContentLoaded", function() {
        lucide.createIcons();
    });

    function openAddModal() {
        document.getElementById('addModal').style.display = 'flex';
    }

    function closeAddModal() {
        document.getElementById('addModal').style.display = 'none';
    }

    function openEditModal(button) {
        let id = button.dataset.id;

        document.getElementById('editName').value = button.dataset.name;
        document.getElementById('editBase').value = button.dataset.base;
        document.getElementById('editKm').value = button.dataset.km;
        document.getElementById('editTonnage').value = button.dataset.tonnage;
        document.getElementById('editDescription').value = button.dataset.description;

        document.getElementById('editForm').action =
            `/superadmin/truck-types/${id}`;

        document.getElementById('editModal').style.display = 'flex';

        lucide.createIcons();
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    function openDisableModal(id, name) {
        document.getElementById('disableText').innerText =
            `Are you sure you want to disable "${name}"? This truck type will no longer be available for bookings.`;

        let url = "{{ route('superadmin.truck-types.toggle', ':id') }}";
        url = url.replace(':id', id);

        document.getElementById('disableForm').action = `/superadmin/truck-types/${id}/toggle`;

        document.getElementById('disableModal').style.display = 'flex';

        lucide.createIcons();
    }

    function closeDisableModal() {
        document.getElementById('disableModal').style.display = 'none';
    }
</script>
@endpush