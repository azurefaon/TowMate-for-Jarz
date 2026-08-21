@extends('layouts.superadmin')

@section('title', 'Vehicle Catalog')

@push('styles')
<style>
.vc-page {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.vc-page .feedback {
    padding: 12px 14px;
    border-radius: 10px;
    font-size: 0.88rem;
}
.vc-page .feedback--success { background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; }
.vc-page .feedback--error   { background:#fff7ed; color:#9a3412; border:1px solid #fdba74; }

.vc-page .page-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
}
.vc-page .page-top h1 { margin:0; font-size:1.9rem; color:#111; }
.vc-page .page-top p  { margin:6px 0 0; color:#6b7280; }

.vc-card {
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:16px;
    overflow:hidden;
}

.vc-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    flex-wrap: wrap;
}
.vc-toolbar-left h3 { margin:0; font-size:0.95rem; color:#111; font-weight:500; }
.vc-toolbar-left p  { margin:3px 0 0; font-size:0.8rem; color:#6b7280; }
.vc-toolbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.vc-filter-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.vc-filter-reset {
    font-size: 0.85rem;
    color: #6b7280;
    text-decoration: none;
    white-space: nowrap;
}
.vc-filter-reset:hover { color: #111; text-decoration: underline; }

.vc-search {
    display: flex;
    align-items: center;
    border: 1px solid #dbe2ea;
    border-radius: 10px;
    padding: 0 12px;
    min-height: 40px;
    min-width: 240px;
    background: #fff;
}
.vc-search input {
    border: none;
    outline: none;
    font-size: 13px;
    color: #111;
    width: 100%;
    background: transparent;
}
.vc-search input::placeholder { color: #9ca3af; }

.vc-filter {
    min-height: 40px;
    padding: 0 12px;
    border: 1px solid #dbe2ea;
    border-radius: 10px;
    background: #fff;
    color: #374151;
    font-size: 13px;
    appearance: none;
    cursor: pointer;
}

.vc-btn-add {
    min-height: 40px;
    padding: 0 16px;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, #facc15, #eab308);
    color: #111;
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
    transition: opacity 0.15s;
}
.vc-btn-add:hover { opacity: 0.88; }

.vc-table { width:100%; border-collapse:collapse; }
.vc-table th {
    padding: 13px 18px;
    text-align: left;
    font-size: 0.8rem;
    color: #4b5563;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 1px solid #e5e7eb;
    font-weight: 600;
    background: #f8fafc;
    white-space: nowrap;
}
.vc-table td {
    padding: 16px 18px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.95rem;
    color: #374151;
    vertical-align: middle;
}
.vc-table tbody tr:last-child td { border-bottom: none; }
.vc-table tbody tr:hover td { background: #fafafa; }

.vc-name { font-size: 1rem; font-weight: 500; color: #111; }
.vc-desc { font-size: 0.82rem; color: #9ca3af; margin-top: 3px; }

.vc-category {
    display: inline-flex;
    align-items: center;
    font-size: 0.88rem;
    font-weight: 600;
    color: #1f2937;
}
.vc-category--2_wheeler     { color: #2563eb; }
.vc-category--4_wheeler     { color: #15803d; }
.vc-category--heavy_vehicle { color: #b45309; }

.vc-classes { display:flex; flex-wrap:wrap; gap:6px; }
.vc-class-tag {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.8rem;
    background: #f3f4f6;
    color: #4b5563;
    border: 1px solid #e5e7eb;
}
.vc-none { color: #9ca3af; font-size: 0.85rem; }

.vc-status {
    display: inline-flex;
    align-items: center;
    font-size: 0.88rem;
    font-weight: 600;
    color: #1f2937;
}
.vc-status.active   { color: #15803d; }
.vc-status.inactive { color: #6b7280; }

.vc-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.vc-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 999px;
    cursor: pointer;
    font-size: 0.82rem;
    color: #374151;
    padding: 5px 13px;
    transition: background-color 0.12s ease, border-color 0.12s ease, color 0.12s ease;
}
.vc-action:hover { background: #f3f4f6; border-color: #d1d5db; color: #111; }
.vc-action--danger { color: #dc2626; border-color: #fecaca; }
.vc-action--danger:hover { background: #fef2f2; border-color: #fca5a5; color: #b91c1c; }
.vc-action--enable { color: #16a34a; border-color: #bbf7d0; }
.vc-action--enable:hover { background: #f0fdf4; border-color: #86efac; color: #15803d; }

.vc-empty {
    text-align: center;
    padding: 48px 20px;
    color: #9ca3af;
    font-size: 0.95rem;
}

.vc-pagination { padding: 18px 20px; }
.vc-pagination .pagination-wrapper { display: flex; justify-content: center; gap: 4px; }
.vc-pagination .pagination-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #374151;
    font-size: 0.88rem;
    text-decoration: none;
    transition: all 0.15s ease;
}
.vc-pagination .pagination-btn:hover { background: #f3f4f6; border-color: #d1d5db; color: #111; }
.vc-pagination .pagination-btn.active {
    background: linear-gradient(135deg, #facc15, #eab308);
    border-color: #eab308;
    color: #111;
}
.vc-pagination .pagination-btn.disabled { opacity: 0.5; cursor: not-allowed; }

/* Modal */
.vc-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1200;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(17,17,17,0.42);
}
.vc-modal.is-open { display: flex; }
.vc-modal-card {
    width: 100%;
    max-width: 520px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 22px;
    box-shadow: 0 18px 40px rgba(17,17,17,0.14);
    max-height: 90vh;
    overflow-y: auto;
}
.vc-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 18px;
}
.vc-modal-header h2 { margin:0; font-size:1.05rem; color:#111; font-weight:500; }
.vc-modal-header p  { margin:4px 0 0; font-size:0.8rem; color:#6b7280; }
.vc-modal-close {
    width: 32px; height: 32px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    background: #fff;
    cursor: pointer;
    font-size: 14px;
    color: #6b7280;
    flex-shrink: 0;
}
.vc-modal-close:hover { color: #111; }

.vc-form-group { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
.vc-form-group label { font-size:0.82rem; color:#374151; }
.vc-form-group input,
.vc-form-group select,
.vc-form-group textarea {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 9px 11px;
    font-size: 0.9rem;
    color: #111;
    outline: none;
    transition: border-color 0.12s;
}
.vc-form-group input:focus,
.vc-form-group select:focus,
.vc-form-group textarea:focus { border-color: #111; }
.vc-form-group textarea { min-height: 72px; resize: vertical; }
.vc-form-hint { font-size: 0.75rem; color: #9ca3af; }

.vc-trucks-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}
.vc-truck-check {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border: 1px solid #e5e7eb;
    border-radius: 7px;
    cursor: pointer;
    transition: border-color 0.12s, background 0.12s;
    font-size: 0.82rem;
    color: #374151;
}
.vc-truck-check:has(input:checked) {
    border-color: #111;
    background: #f9fafb;
}
.vc-truck-check input { cursor: pointer; }

.vc-form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

.vc-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid #f3f4f6;
}
.vc-btn-cancel {
    padding: 8px 16px; border-radius: 8px;
    border: 1px solid #e5e7eb; background: #fff;
    color: #374151; font-size: 0.88rem; cursor: pointer;
}
.vc-btn-cancel:hover { background: #f9fafb; }
.vc-btn-save {
    padding: 8px 20px; border-radius: 8px;
    border: none;
    background: linear-gradient(135deg, #facc15, #eab308);
    color: #111; font-size: 0.88rem; cursor: pointer;
}
.vc-btn-save:hover { opacity: 0.88; }

.vc-confirm-modal .vc-modal-card { max-width: 400px; text-align: center; }
.vc-confirm-title { font-size: 1rem; color: #111; margin: 0 0 8px; font-weight: 500; }
.vc-confirm-text  { font-size: 0.88rem; color: #6b7280; line-height: 1.6; margin: 0 0 20px; }
.vc-confirm-actions { display:flex; justify-content:center; gap:10px; }

@media (max-width: 640px) {
    .vc-toolbar { flex-direction:column; align-items:stretch; }
    .vc-search { min-width:unset; }
    .vc-trucks-grid { grid-template-columns: 1fr; }
    .vc-form-row { grid-template-columns: 1fr; }
}
</style>
@endpush

@section('content')
<div class="vc-page" data-base-url="{{ url('/superadmin/vehicle-types') }}">

    @if (session('success'))
        <div class="feedback feedback--success" id="vcSuccess">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="feedback feedback--error" id="vcError">{{ session('error') }}</div>
    @endif

    <div class="page-top">
        <div>
            <h1>Vehicle Catalog</h1>
            <p>Manage the vehicle types customers can select when booking.</p>
        </div>
    </div>

    @include('superadmin.fleet._tabs')

    <div class="vc-card">
        <div class="vc-toolbar">
            <div class="vc-toolbar-left">
                <h3>All vehicle types</h3>
                <p>Assign each vehicle to one or more tow truck classes.</p>
            </div>
            <div class="vc-toolbar-right">
                <form method="GET" id="vcFilterForm" class="vc-filter-form">
                    <div class="vc-search">
                        <input type="text" name="search" id="vcSearch" value="{{ request('search') }}"
                            placeholder="Search vehicle types...">
                    </div>
                    <select name="category" id="vcCategoryFilter" class="vc-filter">
                        <option value="">All categories</option>
                        <option value="2_wheeler" {{ request('category') === '2_wheeler' ? 'selected' : '' }}>2-Wheeler</option>
                        <option value="4_wheeler" {{ request('category') === '4_wheeler' ? 'selected' : '' }}>4-Wheeler</option>
                        <option value="heavy_vehicle" {{ request('category') === 'heavy_vehicle' ? 'selected' : '' }}>Heavy Vehicle</option>
                    </select>
                    <select name="status" id="vcStatusFilter" class="vc-filter">
                        <option value="">All status</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                    @if (request()->hasAny(['search', 'category', 'status']))
                        <a href="{{ route('superadmin.vehicle-types.index') }}" class="vc-filter-reset">Reset</a>
                    @endif
                </form>
                <button type="button" class="vc-btn-add" id="vcAddBtn">Add Vehicle Type</button>
            </div>
        </div>

        <table class="vc-table">
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Category</th>
                    <th>Truck Type</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="vcTableBody">
                @forelse ($vehicleTypes as $type)
                    <tr>
                        <td>
                            <div class="vc-name">{{ $type->name }}</div>
                            @if ($type->description)
                                <div class="vc-desc">{{ $type->description }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="vc-category vc-category--{{ $type->category }}">
                                {{ $type->category_label }}
                            </span>
                        </td>
                        <td>
                            @if ($type->truckTypes->isNotEmpty())
                                <div class="vc-classes">
                                    @foreach ($type->truckTypes as $truck)
                                        <span class="vc-class-tag">{{ $truck->name }}</span>
                                    @endforeach
                                </div>
                            @else
                                <span class="vc-none">none assigned</span>
                            @endif
                        </td>
                        <td>
                            <span class="vc-status {{ $type->status }}">
                                {{ ucfirst($type->status) }}
                            </span>
                        </td>
                        <td>
                            <div class="vc-actions">
                                <button type="button" class="vc-action js-vc-edit"
                                    data-id="{{ $type->id }}"
                                    data-name="{{ $type->name }}"
                                    data-category="{{ $type->category }}"
                                    data-weight="{{ $type->weight_kg }}"
                                    data-description="{{ $type->description }}"
                                    data-truck-ids="{{ $type->truckTypes->pluck('id')->join(',') }}">Edit</button>

                                <form method="POST"
                                    action="{{ route('superadmin.vehicle-types.toggle', $type->id) }}"
                                    style="display:inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="vc-action {{ $type->status === 'active' ? '' : 'vc-action--enable' }}">
                                        {{ $type->status === 'active' ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>

                                @if ($type->bookings_count === 0)
                                    <button type="button" class="vc-action vc-action--danger js-vc-delete"
                                        data-id="{{ $type->id }}"
                                        data-name="{{ $type->name }}">Delete</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="vc-empty">No vehicle types found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="vc-pagination">
            {{ $vehicleTypes->links('vendor.pagination.custom') }}
        </div>
    </div>

</div>

{{-- Add Modal --}}
<div class="vc-modal" id="addModal">
    <div class="vc-modal-card">
        <div class="vc-modal-header">
            <div>
                <h2>Add Vehicle Type</h2>
            </div>
            <button type="button" class="vc-modal-close js-close-modal" data-modal="addModal">✕</button>
        </div>

        <form method="POST" action="{{ route('superadmin.vehicle-types.store') }}">
            @csrf
            <div class="vc-form-group">
                <label>Vehicle name</label>
                <input type="text" name="name" required placeholder="Sedan, Motorcycle, Van...">
            </div>
            <div class="vc-form-row">
                <div class="vc-form-group">
                    <label>Category</label>
                    <select name="category" required>
                        <option value="">Select category</option>
                        <option value="2_wheeler">2-Wheeler</option>
                        <option value="4_wheeler">4-Wheeler</option>
                        <option value="heavy_vehicle">Heavy Vehicle</option>
                    </select>
                </div>
                <div class="vc-form-group">
                    <label>Weight (kg)</label>
                    <input type="number" name="weight_kg" id="addVcWeight" required min="0" step="1"
                        placeholder="e.g. 4500">
                </div>
            </div>
            <div class="vc-form-group">
                <label>Description <span style="color:#9ca3af">(optional)</span></label>
                <textarea name="description" placeholder="Short note about this vehicle type"></textarea>
            </div>
            <div class="vc-form-group">
                <label>Compatible tow classes</label>
                <div class="vc-trucks-grid" id="addVcTrucks" style="margin-top:8px">
                    @foreach ($truckTypes as $truck)
                        <label class="vc-truck-check" data-class="{{ $truck->class }}">
                            <input type="checkbox" name="truck_types[]" value="{{ $truck->id }}" class="add-truck-check"
                                data-capacity="{{ $truck->max_tonnage }}">
                            {{ $truck->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="vc-modal-footer">
                <button type="button" class="vc-btn-cancel js-close-modal" data-modal="addModal">Cancel</button>
                <button type="submit" class="vc-btn-save">Save</button>
            </div>
        </form>
    </div>
</div>

{{-- Edit Modal --}}
<div class="vc-modal" id="editModal">
    <div class="vc-modal-card">
        <div class="vc-modal-header">
            <div>
                <h2>Edit Vehicle Type</h2>
                <p>Update vehicle details and tow class assignments.</p>
            </div>
            <button type="button" class="vc-modal-close js-close-modal" data-modal="editModal">✕</button>
        </div>

        <form method="POST" id="editVcForm">
            @csrf @method('PUT')
            <div class="vc-form-group">
                <label>Vehicle name</label>
                <input type="text" name="name" id="editVcName" required>
            </div>
            <div class="vc-form-row">
                <div class="vc-form-group">
                    <label>Category</label>
                    <select name="category" id="editVcCategory" required>
                        <option value="2_wheeler">2-Wheeler</option>
                        <option value="4_wheeler">4-Wheeler</option>
                        <option value="heavy_vehicle">Heavy Vehicle</option>
                    </select>
                </div>
                <div class="vc-form-group">
                    <label>Weight (kg)</label>
                    <input type="number" name="weight_kg" id="editVcWeight" required min="0" step="1">
                </div>
            </div>
            <div class="vc-form-group">
                <label>Description <span style="color:#9ca3af">(optional)</span></label>
                <textarea name="description" id="editVcDescription"></textarea>
            </div>
            <div class="vc-form-group">
                <label>Compatible tow classes</label>
                <span class="vc-form-hint">Only classes matching the weight above are selectable — click a class to auto-fill its weight.</span>
                <div class="vc-trucks-grid" id="editVcTrucks" style="margin-top:8px">
                    @foreach ($truckTypes as $truck)
                        <label class="vc-truck-check" data-class="{{ $truck->class }}">
                            <input type="checkbox" name="truck_types[]" value="{{ $truck->id }}"
                                class="edit-truck-check" data-capacity="{{ $truck->max_tonnage }}">
                            {{ $truck->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="vc-modal-footer">
                <button type="button" class="vc-btn-cancel js-close-modal" data-modal="editModal">Cancel</button>
                <button type="submit" class="vc-btn-save">Update</button>
            </div>
        </form>
    </div>
</div>

{{-- Delete Confirm Modal --}}
<div class="vc-modal vc-confirm-modal" id="deleteModal">
    <div class="vc-modal-card">
        <p class="vc-confirm-title" id="deleteVcTitle">Delete vehicle type?</p>
        <p class="vc-confirm-text" id="deleteVcText"></p>
        <form method="POST" id="deleteVcForm">
            @csrf @method('DELETE')
            <div class="vc-confirm-actions">
                <button type="button" class="vc-btn-cancel js-close-modal" data-modal="deleteModal">Cancel</button>
                <button type="submit" class="vc-btn-save" style="background:#dc2626;color:#fff;">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const baseUrl = document.querySelector('.vc-page')?.dataset.baseUrl ?? '';

    // Refresh CSRF token before any modal form submission to prevent 419
    async function refreshCsrf() {
        try {
            const res = await fetch('/superadmin/csrf-token', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            if (res.ok) {
                const { token } = await res.json();
                // Update all _token inputs and the meta tag
                document.querySelectorAll('input[name="_token"]').forEach(el => el.value = token);
                const meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) meta.content = token;
            }
        } catch (_) { /* proceed with existing token */ }
    }

    // Intercept modal form submits with a token refresh
    ['addModal', 'editModal', 'deleteModal'].forEach(modalId => {
        const form = document.getElementById(modalId)?.querySelector('form');
        if (!form) return;
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            await refreshCsrf();
            // Use HTMLFormElement.submit() to bypass this listener
            HTMLFormElement.prototype.submit.call(this);
        });
    });

    // Modal helpers
    const openModal  = id => document.getElementById(id)?.classList.add('is-open');
    const closeModal = id => document.getElementById(id)?.classList.remove('is-open');

    document.querySelectorAll('.js-close-modal').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.dataset.modal));
    });
    document.querySelectorAll('.vc-modal').forEach(modal => {
        modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('is-open'); });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.vc-modal.is-open').forEach(m => m.classList.remove('is-open'));
    });

    // Weight ↔ tow-class compatibility (mirrors TruckType::isCompatibleWithWeight)
    const isClassCompatible = (truckClass, weightKg) => {
        if (!truckClass || weightKg === null || Number.isNaN(weightKg)) return true;
        if (truckClass === 'light')  return weightKg <= 4500;
        if (truckClass === 'medium') return weightKg > 4500 && weightKg <= 7500;
        if (truckClass === 'heavy')  return weightKg > 7500;
        return true;
    };

    const filterTrucksGrid = (gridId, weightInputId) => {
        const grid = document.getElementById(gridId);
        const weightInput = document.getElementById(weightInputId);
        if (!grid || !weightInput) return;
        const weightKg = weightInput.value === '' ? null : parseFloat(weightInput.value);

        grid.querySelectorAll('.vc-truck-check').forEach(label => {
            const compatible = isClassCompatible(label.dataset.class, weightKg);
            const checkbox = label.querySelector('input[type="checkbox"]');
            label.style.opacity = compatible ? '1' : '0.4';
            label.style.pointerEvents = compatible ? '' : 'none';
            if (checkbox) {
                checkbox.disabled = !compatible;
                if (!compatible) checkbox.checked = false;
            }
        });
    };

    document.getElementById('addVcWeight')?.addEventListener('input', () => filterTrucksGrid('addVcTrucks', 'addVcWeight'));
    document.getElementById('editVcWeight')?.addEventListener('input', () => filterTrucksGrid('editVcTrucks', 'editVcWeight'));

    // Clicking a truck class checkbox auto-fills Weight (kg) with that class's configured capacity
    const wireClassAutofill = (gridId, weightInputId) => {
        const grid = document.getElementById(gridId);
        if (!grid) return;
        grid.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const weightInput = document.getElementById(weightInputId);

                if (checkbox.checked) {
                    const capacity = checkbox.dataset.capacity;
                    if (capacity && weightInput) {
                        weightInput.value = capacity;
                        filterTrucksGrid(gridId, weightInputId);
                    }
                    return;
                }

                // Unchecked — if no other class is still selected, clear the weight
                // so the other classes become selectable again instead of staying
                // stuck disabled from the previous class's weight.
                const stillChecked = grid.querySelector('input[type="checkbox"]:checked');
                if (!stillChecked && weightInput) {
                    weightInput.value = '';
                    filterTrucksGrid(gridId, weightInputId);
                }
            });
        });
    };

    wireClassAutofill('addVcTrucks', 'addVcWeight');
    wireClassAutofill('editVcTrucks', 'editVcWeight');

    // Add
    document.getElementById('vcAddBtn')?.addEventListener('click', () => {
        filterTrucksGrid('addVcTrucks', 'addVcWeight');
        openModal('addModal');
    });

    // Edit
    document.querySelectorAll('.js-vc-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            const truckIds = btn.dataset.truckIds ? btn.dataset.truckIds.split(',').map(Number).filter(Boolean) : [];
            document.getElementById('editVcForm').action = `${baseUrl}/${btn.dataset.id}`;
            document.getElementById('editVcName').value = btn.dataset.name || '';
            document.getElementById('editVcCategory').value = btn.dataset.category || '';
            document.getElementById('editVcWeight').value = btn.dataset.weight || '';
            document.getElementById('editVcDescription').value = btn.dataset.description || '';
            filterTrucksGrid('editVcTrucks', 'editVcWeight');
            document.querySelectorAll('.edit-truck-check').forEach(cb => {
                if (!cb.disabled) cb.checked = truckIds.includes(parseInt(cb.value));
            });
            openModal('editModal');
        });
    });

    // Delete
    document.querySelectorAll('.js-vc-delete').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('deleteVcText').textContent =
                `Are you sure you want to delete "${btn.dataset.name}"? This cannot be undone.`;
            document.getElementById('deleteVcForm').action = `${baseUrl}/${btn.dataset.id}`;
            openModal('deleteModal');
        });
    });

    // Filter (server-side, so it searches the full dataset — not just the current page)
    const filterForm = document.getElementById('vcFilterForm');
    let filterDebounce;

    document.getElementById('vcSearch')?.addEventListener('input', () => {
        clearTimeout(filterDebounce);
        filterDebounce = setTimeout(() => filterForm.submit(), 400);
    });
    document.getElementById('vcCategoryFilter')?.addEventListener('change', () => filterForm.submit());
    document.getElementById('vcStatusFilter')?.addEventListener('change', () => filterForm.submit());

    // Auto-hide flash
    ['vcSuccess','vcError'].forEach(id => {
        const el = document.getElementById(id);
        if (el) setTimeout(() => el.remove(), 3500);
    });
});
</script>
@endsection
