@extends('layouts.superadmin')

@section('title', 'Truck Classes')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/truck-types.css') }}">
@endpush

@section('content')
    <div class="truck-types-page" data-base-url="{{ url('/superadmin/truck-types') }}">
        @if (session('success'))
            <div class="type-feedback type-feedback--success" id="successAlert">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="type-feedback type-feedback--error" id="errorAlert">{{ session('error') }}</div>
        @endif

        <div class="page-top">
            <div>
                <h1>Truck Classes</h1>
                <p>{{ $stats['active'] }} active · {{ $stats['inactive'] }} inactive · {{ $stats['units'] }} unit(s) linked</p>
            </div>

            <button type="button" class="btn-primary-add" data-open-modal="addModal">
                Add Truck Class
            </button>
        </div>

        <div class="table-card">
            <div class="table-header">
                <div>
                    <h3>Fleet towing classes</h3>
                    <p>Use labels like flatbed, wheel-lift, medium duty, or heavy duty.</p>
                </div>

                <div class="table-controls">
                    <div class="table-toolbar">
                        <label class="search-box">
                            <input type="text" id="truckTypeSearch" placeholder="Search towing classes...">
                        </label>

                        <select id="truckTypeStatusFilter" class="status-filter">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="class-cards">
                @forelse ($truckTypes as $type)
                    <div class="class-card"
                         data-card
                         data-status="{{ $type->status }}"
                         data-search="{{ strtolower($type->name . ' ' . ($type->description ?? '')) }}">

                        <div class="class-card-header">
                            <div class="class-card-info">
                                <span class="class-card-name">{{ $type->name }}</span>
                                <span class="class-card-meta">
                                    ₱{{ number_format($type->base_rate, 0) }} base
                                    · ₱{{ number_format($type->per_km_rate, 0) }}/km
                                    @if ($type->max_tonnage)· {{ number_format((float) $type->max_tonnage, 1) }} ton cap.@endif
                                    · {{ $type->units_count ?? 0 }} unit(s)
                                </span>
                                @if ($type->description)
                                    <span class="class-card-desc">{{ $type->description }}</span>
                                @endif
                            </div>

                            <div class="class-card-right">
                                <span class="status-pill {{ $type->status }}">{{ ucfirst($type->status) }}</span>
                                <div class="class-card-actions">
                                    <button type="button" class="card-action js-edit-type"
                                        data-id="{{ $type->id }}"
                                        data-name="{{ $type->name }}"
                                        data-class="{{ $type->class }}"
                                        data-base="{{ $type->base_rate }}"
                                        data-km="{{ $type->per_km_rate }}"
                                        data-tonnage="{{ $type->max_tonnage }}"
                                        data-description="{{ $type->description }}">edit</button>

                                    <span class="action-sep">·</span>

                                    @if ($type->status === 'active')
                                        <button type="button" class="card-action js-disable-type"
                                            data-id="{{ $type->id }}"
                                            data-name="{{ $type->name }}"
                                            data-busy="{{ ($type->units_count ?? 0) > 0 || ($type->active_bookings_count ?? 0) > 0 ? '1' : '0' }}"
                                            data-unit-count="{{ $type->units_count ?? 0 }}"
                                            data-booking-count="{{ $type->active_bookings_count ?? 0 }}">
                                            {{ ($type->units_count ?? 0) > 0 || ($type->active_bookings_count ?? 0) > 0 ? 'busy' : 'disable' }}
                                        </button>
                                    @else
                                        <form method="POST"
                                            action="{{ route('superadmin.truck-types.toggle', $type->id) }}"
                                            style="display:inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="card-action card-action--enable">enable</button>
                                        </form>
                                    @endif

                                    <span class="action-sep">·</span>

                                    <button type="button" class="card-action card-action--danger js-delete-type"
                                        data-id="{{ $type->id }}"
                                        data-name="{{ $type->name }}"
                                        data-units="{{ $type->units_count ?? 0 }}"
                                        data-bookings="{{ $type->active_bookings_count ?? 0 }}">delete</button>
                                </div>
                            </div>
                        </div>

                        <div class="class-card-chips">
                            <div class="vt-cell" data-truck-id="{{ $type->id }}">
                                @foreach ($type->vehicleTypes as $vt)
                                    <span class="vt-pill" id="vt-pill-{{ $type->id }}-{{ $vt->id }}">
                                        {{ $vt->name }}
                                        <button type="button" class="vt-remove"
                                            data-truck="{{ $type->id }}"
                                            data-vehicle="{{ $vt->id }}"
                                            title="Remove">×</button>
                                    </span>
                                @endforeach

                                @php
                                    $linkedIds = $type->vehicleTypes->pluck('id')->all();
                                    $available = $allVehicleTypes->reject(fn($v) => in_array($v->id, $linkedIds));
                                @endphp

                                @if ($available->isNotEmpty())
                                    <select class="vt-add-select" data-truck="{{ $type->id }}">
                                        <option value="">+ add vehicle type</option>
                                        @foreach ($available as $av)
                                            <option value="{{ $av->id }}">{{ $av->name }}</option>
                                        @endforeach
                                    </select>
                                @endif

                                @if ($type->vehicleTypes->isEmpty() && $available->isEmpty())
                                    <span class="vt-empty">no vehicle types configured</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="empty-state" style="padding:40px 20px;">
                        <h3>No tow truck classes yet</h3>
                        <p>Add your first towing class to organize the fleet.</p>
                    </div>
                @endforelse
            </div>

            <div class="pagination-wrapper">
                {{ $truckTypes->onEachSide(1)->links('vendor.pagination.custom') }}
            </div>
        </div>

        <div id="addModal" class="modal">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h2>Add Truck Class</h2>
                        <p>Set a name, duty class, and pricing for this towing truck.</p>
                    </div>
                    <button type="button" class="modal-close" data-close-modal="addModal">✕</button>
                </div>

                <form method="POST" action="{{ route('superadmin.truck-types.store') }}">
                    @csrf

                    <div class="form-group">
                        <label for="newTruckTypeName">Name</label>
                        <input id="newTruckTypeName" type="text" name="name"
                            placeholder="e.g. Flatbed, Wheel-Lift" required>
                    </div>

                    <div class="form-group">
                        <label for="newTruckTypeClass">Duty Class</label>
                        <small class="input-hint">Determines which units the mobile app shows as available</small>
                        <select id="newTruckTypeClass" name="class">
                            <option value="">— not set —</option>
                            <option value="light">Light Duty</option>
                            <option value="medium">Medium Duty</option>
                            <option value="heavy">Heavy Duty</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="newTruckTypeBase">Base Rate</label>
                            <input id="newTruckTypeBase" type="number" step="0.01" name="base_rate"
                                placeholder="1500" required>
                        </div>

                        <div class="form-group">
                            <label for="newTruckTypeKm">Per KM Rate</label>
                            <input id="newTruckTypeKm" type="number" step="0.01" name="per_km_rate"
                                placeholder="200" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="newTruckTypeTonnage">Max Tonnage</label>
                        <input id="newTruckTypeTonnage" type="number" step="0.01" name="max_tonnage"
                            placeholder="4.5">
                    </div>

                    <div class="form-group">
                        <label for="newTruckTypeDescription">Notes</label>
                        <textarea id="newTruckTypeDescription" name="description"
                            placeholder="Optional notes about this class"></textarea>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-light" data-close-modal="addModal">Cancel</button>
                        <button type="submit" class="btn-dark">Save Type</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="editModal" class="modal">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h2>Edit Truck Class</h2>
                        <p>Update pricing, capacity, or duty class.</p>
                    </div>
                    <button type="button" class="modal-close" data-close-modal="editModal">✕</button>
                </div>

                <form method="POST" id="editForm">
                    @csrf
                    @method('PUT')

                    <div class="form-group">
                        <label for="editName">Name</label>
                        <input type="text" name="name" id="editName" required>
                    </div>

                    <div class="form-group">
                        <label for="editClass">Duty Class</label>
                        <small class="input-hint">Determines which units the mobile app shows as available</small>
                        <select name="class" id="editClass">
                            <option value="">— not set —</option>
                            <option value="light">Light Duty</option>
                            <option value="medium">Medium Duty</option>
                            <option value="heavy">Heavy Duty</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="editBase">Base Rate</label>
                            <input type="number" step="0.01" name="base_rate" id="editBase" required>
                        </div>

                        <div class="form-group">
                            <label for="editKm">Per KM Rate</label>
                            <input type="number" step="0.01" name="per_km_rate" id="editKm" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="editTonnage">Max Tonnage</label>
                        <input type="number" step="0.01" name="max_tonnage" id="editTonnage">
                    </div>

                    <div class="form-group">
                        <label for="editDescription">Description</label>
                        <textarea name="description" id="editDescription"></textarea>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-light" data-close-modal="editModal">Cancel</button>
                        <button type="submit" class="btn-dark">Update Type</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="disableModal" class="modal">
            <div class="modal-content">
                <h3 id="disableTitle">Disable Truck Class?</h3>
                <p id="disableText">This type will no longer appear for new towing unit setups.</p>

                <form method="POST" id="disableForm">
                    @csrf
                    @method('PATCH')

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" data-close-modal="disableModal">Close</button>
                        <button type="submit" class="btn-danger" id="disableSubmitBtn">Disable</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="modal">
            <div class="modal-content delete-modal-content">
                <h3 id="deleteTitle">Delete Truck Class?</h3>
                <p id="deleteText">Are you sure you want to delete this truck type?</p>

                <form method="POST" id="deleteForm">
                    @csrf
                    @method('DELETE')

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" data-close-modal="deleteModal">Cancel</button>
                        <button type="submit" class="btn-danger" id="deleteSubmitBtn">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('admin/js/truck-types.js') }}" defer></script>
    <script>
        const _csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

        document.addEventListener('click', async function (e) {
            const btn = e.target.closest('.vt-remove');
            if (!btn) return;
            const truckId   = btn.dataset.truck;
            const vehicleId = btn.dataset.vehicle;
            const pill      = document.getElementById(`vt-pill-${truckId}-${vehicleId}`);
            btn.disabled = true;
            const resp = await fetch(`/superadmin/truck-types/${truckId}/vehicle-types/${vehicleId}/detach`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': _csrf, 'Accept': 'application/json' },
            });
            if ((await resp.json()).success) {
                pill?.remove();
            } else {
                btn.disabled = false;
            }
        });

        document.addEventListener('change', async function (e) {
            const sel = e.target.closest('.vt-add-select');
            if (!sel || !sel.value) return;
            const truckId   = sel.dataset.truck;
            const vehicleId = sel.value;
            sel.disabled = true;
            const resp = await fetch(`/superadmin/truck-types/${truckId}/vehicle-types/${vehicleId}/attach`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': _csrf, 'Accept': 'application/json' },
            });
            if ((await resp.json()).success) {
                location.reload();
            } else {
                sel.value    = '';
                sel.disabled = false;
            }
        });
    </script>
@endpush

@push('styles')
    <style>
        .vt-cell { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .vt-pill {
            display: inline-flex; align-items: center; gap: 4px;
            background: #f3f4f6; border: 1px solid #e5e7eb;
            border-radius: 6px; padding: 2px 8px; font-size: 12px; color: #374151;
        }
        .vt-remove {
            background: none; border: none; cursor: pointer;
            color: #9ca3af; font-size: 14px; line-height: 1; padding: 0 2px;
        }
        .vt-remove:hover { color: #ef4444; }
        .vt-add-select {
            font-size: 12px; border: 1px solid #e5e7eb; border-radius: 6px;
            padding: 2px 6px; background: #fff; color: #374151; cursor: pointer;
        }
    </style>
@endpush
