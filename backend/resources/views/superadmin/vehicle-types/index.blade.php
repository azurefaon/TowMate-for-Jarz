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

.vc-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0,1fr));
    gap: 12px;
}
.vc-stat {
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:16px;
}
.vc-stat span  { display:block; font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.07em; }
.vc-stat strong { display:block; font-size:1.7rem; color:#111; margin-top:6px; font-weight:500; }

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
    padding: 11px 16px;
    text-align: left;
    font-size: 0.73rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    border-bottom: 1px solid #e5e7eb;
    font-weight: 400;
}
.vc-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 0.88rem;
    color: #374151;
    vertical-align: middle;
}
.vc-table tbody tr:last-child td { border-bottom: none; }
.vc-table tbody tr:hover td { background: #fafafa; }

.vc-name { font-size: 0.9rem; color: #111; }
.vc-desc { font-size: 0.78rem; color: #9ca3af; margin-top: 2px; }

.vc-category {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
}
.vc-category--2_wheeler    { background:#dbeafe; color:#1e40af; }
.vc-category--4_wheeler    { background:#dcfce7; color:#166534; }
.vc-category--heavy_vehicle { background:#fef3c7; color:#92400e; }

.vc-classes { display:flex; flex-wrap:wrap; gap:5px; }
.vc-class-tag {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 5px;
    font-size: 0.73rem;
    background: #f3f4f6;
    color: #4b5563;
    border: 1px solid #e5e7eb;
}

.vc-status {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
}
.vc-status.active   { background:#dcfce7; color:#166534; }
.vc-status.inactive { background:#f3f4f6; color:#6b7280; }

.vc-actions { display:flex; align-items:center; gap:6px; }
.vc-action-sep { color:#d1d5db; font-size:0.75rem; user-select:none; }
.vc-action {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.8rem;
    color: #6b7280;
    padding: 0;
    transition: color 0.12s;
}
.vc-action:hover { color: #111; }
.vc-action--danger { color: #dc2626; }
.vc-action--danger:hover { color: #b91c1c; }
.vc-action--enable { color: #16a34a; }
.vc-action--enable:hover { color: #15803d; }

.vc-empty {
    text-align: center;
    padding: 48px 20px;
    color: #9ca3af;
    font-size: 0.9rem;
}

.vc-pagination { padding: 16px 20px; }

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

@media (max-width: 900px) { .vc-stats { grid-template-columns: repeat(3,1fr); } }
@media (max-width: 640px) {
    .vc-stats { grid-template-columns: repeat(2,1fr); }
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

    <div class="vc-stats">
        <div class="vc-stat">
            <span>Total</span>
            <strong>{{ $stats['total'] }}</strong>
        </div>
        <div class="vc-stat">
            <span>Active</span>
            <strong>{{ $stats['active'] }}</strong>
        </div>
        <div class="vc-stat">
            <span>2-Wheeler</span>
            <strong>{{ $stats['2_wheeler'] }}</strong>
        </div>
        <div class="vc-stat">
            <span>4-Wheeler</span>
            <strong>{{ $stats['4_wheeler'] }}</strong>
        </div>
        <div class="vc-stat">
            <span>Heavy</span>
            <strong>{{ $stats['heavy_vehicle'] }}</strong>
        </div>
    </div>

    <div class="vc-card">
        <div class="vc-toolbar">
            <div class="vc-toolbar-left">
                <h3>All vehicle types</h3>
                <p>Assign each vehicle to one or more tow truck classes.</p>
            </div>
            <div class="vc-toolbar-right">
                <div class="vc-search">
                    <input type="text" id="vcSearch" placeholder="Search vehicle types...">
                </div>
                <select id="vcCategoryFilter" class="vc-filter">
                    <option value="all">All categories</option>
                    <option value="2_wheeler">2-Wheeler</option>
                    <option value="4_wheeler">4-Wheeler</option>
                    <option value="heavy_vehicle">Heavy Vehicle</option>
                </select>
                <select id="vcStatusFilter" class="vc-filter">
                    <option value="all">All status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <button type="button" class="vc-btn-add" id="vcAddBtn">Add Vehicle Type</button>
            </div>
        </div>

        <table class="vc-table">
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Category</th>
                    <th>Tow Classes</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="vcTableBody">
                @forelse ($vehicleTypes as $type)
                    <tr data-name="{{ strtolower($type->name) }}"
                        data-category="{{ $type->category }}"
                        data-status="{{ $type->status }}">
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
                                <span style="color:#9ca3af;font-size:0.78rem;">none assigned</span>
                            @endif
                        </td>
                        <td>
                            <span class="vc-status {{ $type->status }}">{{ ucfirst($type->status) }}</span>
                        </td>
                        <td>
                            <div class="vc-actions">
                                <button type="button" class="vc-action js-vc-edit"
                                    data-id="{{ $type->id }}"
                                    data-name="{{ $type->name }}"
                                    data-category="{{ $type->category }}"
                                    data-description="{{ $type->description }}"
                                    data-display-order="{{ $type->display_order }}"
                                    data-truck-ids="{{ $type->truckTypes->pluck('id')->join(',') }}">edit</button>

                                <span class="vc-action-sep">·</span>

                                <form method="POST"
                                    action="{{ route('superadmin.vehicle-types.toggle', $type->id) }}"
                                    style="display:inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="vc-action {{ $type->status === 'active' ? '' : 'vc-action--enable' }}">
                                        {{ $type->status === 'active' ? 'disable' : 'enable' }}
                                    </button>
                                </form>

                                @if ($type->bookings_count === 0)
                                    <span class="vc-action-sep">·</span>
                                    <button type="button" class="vc-action vc-action--danger js-vc-delete"
                                        data-id="{{ $type->id }}"
                                        data-name="{{ $type->name }}">delete</button>
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
            {{ $vehicleTypes->links() }}
        </div>
    </div>

</div>

{{-- Add Modal --}}
<div class="vc-modal" id="addModal">
    <div class="vc-modal-card">
        <div class="vc-modal-header">
            <div>
                <h2>Add Vehicle Type</h2>
                <p>Define a new vehicle type and link it to tow truck classes.</p>
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
                    <label>Display order</label>
                    <input type="number" name="display_order" value="0" min="0">
                </div>
            </div>
            <div class="vc-form-group">
                <label>Description <span style="color:#9ca3af">(optional)</span></label>
                <textarea name="description" placeholder="Short note about this vehicle type"></textarea>
            </div>
            <div class="vc-form-group">
                <label>Compatible tow classes</label>
                <span class="vc-form-hint">Select which truck classes can tow this vehicle.</span>
                <div class="vc-trucks-grid" style="margin-top:8px">
                    @foreach ($truckTypes as $truck)
                        <label class="vc-truck-check">
                            <input type="checkbox" name="truck_types[]" value="{{ $truck->id }}">
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
                    <label>Display order</label>
                    <input type="number" name="display_order" id="editVcOrder" min="0">
                </div>
            </div>
            <div class="vc-form-group">
                <label>Description <span style="color:#9ca3af">(optional)</span></label>
                <textarea name="description" id="editVcDescription"></textarea>
            </div>
            <div class="vc-form-group">
                <label>Compatible tow classes</label>
                <div class="vc-trucks-grid" id="editVcTrucks" style="margin-top:8px">
                    @foreach ($truckTypes as $truck)
                        <label class="vc-truck-check">
                            <input type="checkbox" name="truck_types[]" value="{{ $truck->id }}"
                                class="edit-truck-check">
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

    // Add
    document.getElementById('vcAddBtn')?.addEventListener('click', () => openModal('addModal'));

    // Edit
    document.querySelectorAll('.js-vc-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            const truckIds = btn.dataset.truckIds ? btn.dataset.truckIds.split(',').map(Number).filter(Boolean) : [];
            document.getElementById('editVcForm').action = `${baseUrl}/${btn.dataset.id}`;
            document.getElementById('editVcName').value = btn.dataset.name || '';
            document.getElementById('editVcCategory').value = btn.dataset.category || '';
            document.getElementById('editVcDescription').value = btn.dataset.description || '';
            document.getElementById('editVcOrder').value = btn.dataset.displayOrder || 0;
            document.querySelectorAll('.edit-truck-check').forEach(cb => {
                cb.checked = truckIds.includes(parseInt(cb.value));
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

    // Filter
    const search   = document.getElementById('vcSearch');
    const catFilter = document.getElementById('vcCategoryFilter');
    const stFilter  = document.getElementById('vcStatusFilter');

    const filterRows = () => {
        const q   = (search?.value || '').trim().toLowerCase();
        const cat = catFilter?.value || 'all';
        const st  = stFilter?.value  || 'all';
        document.querySelectorAll('#vcTableBody tr[data-name]').forEach(row => {
            const matchName = (row.dataset.name || '').includes(q);
            const matchCat  = cat === 'all' || row.dataset.category === cat;
            const matchSt   = st  === 'all' || row.dataset.status   === st;
            row.style.display = matchName && matchCat && matchSt ? '' : 'none';
        });
    };

    search?.addEventListener('input', filterRows);
    catFilter?.addEventListener('change', filterRows);
    stFilter?.addEventListener('change', filterRows);

    // Auto-hide flash
    ['vcSuccess','vcError'].forEach(id => {
        const el = document.getElementById(id);
        if (el) setTimeout(() => el.remove(), 3500);
    });
});
</script>
@endsection
