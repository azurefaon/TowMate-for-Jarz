@extends('layouts.superadmin')

@section('title', 'Truck Types')

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
                <h1>Truck Types</h1>
                <p>{{ $stats['active'] }} active · {{ $stats['inactive'] }} inactive · {{ $stats['units'] }} unit(s) linked</p>
            </div>

            <button type="button" class="btn-primary-add" data-open-modal="addModal">
                Add Truck Type
            </button>
        </div>

        @include('superadmin.fleet._tabs')

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
                                    ₱{{ number_format($type->per_km_rate, 0) }}/km
                                    · ₱{{ number_format($type->base_rate, 0) }} base
                                    @if ($type->max_tonnage)· {{ number_format((float) $type->max_tonnage, 0) }} kg cap.@endif
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
                        <h2>Add Truck Type</h2>
                        <p>Set a name and pricing for this towing truck.</p>
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
                        <label for="newTruckTypeTonnage">Max Weight (kg)</label>
                        <input id="newTruckTypeTonnage" type="number" step="0.01" name="max_tonnage"
                            placeholder="4500">
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
                        <h2>Edit Truck Type</h2>
                        <p>Update the name, pricing, or capacity.</p>
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
                        <label for="editTonnage">Max Weight (kg)</label>
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
                <h3 id="disableTitle">Disable Truck Type?</h3>
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
                <h3 id="deleteTitle">Delete Truck Type?</h3>
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
@endpush
