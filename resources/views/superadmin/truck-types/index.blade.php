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

                        <button type="button" class="btn-primary-add" data-open-modal="addModal">
                            Add Truck Type
                        </button>
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

                            <div class="class-card-status">
                                <span class="status-pill {{ $type->status }}">{{ ucfirst($type->status) }}</span>
                            </div>

                            <div class="class-card-actions">
                                <button type="button" class="card-action js-edit-type"
                                    data-id="{{ $type->id }}"
                                    data-name="{{ $type->name }}"
                                    data-base="{{ $type->base_rate }}"
                                    data-km="{{ $type->per_km_rate }}"
                                    data-tonnage="{{ $type->max_tonnage }}"
                                    data-description="{{ $type->description }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M14.2 5.3 L18.7 9.8 L8.5 20 L4.3 20.2 L4.5 16 Z"/><line x1="12.6" y1="6.9" x2="17.1" y2="11.4"/></svg>
                                    Edit
                                </button>

                                @if ($type->status === 'active')
                                    <button type="button" class="card-action js-disable-type"
                                        data-id="{{ $type->id }}"
                                        data-name="{{ $type->name }}"
                                        data-busy="{{ ($type->units_count ?? 0) > 0 || ($type->active_bookings_count ?? 0) > 0 ? '1' : '0' }}"
                                        data-unit-count="{{ $type->units_count ?? 0 }}"
                                        data-booking-count="{{ $type->active_bookings_count ?? 0 }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><circle cx="12" cy="12" r="8.2"/><line x1="6.5" y1="17.5" x2="17.5" y2="6.5"/></svg>
                                        Disable
                                    </button>
                                @else
                                    <form method="POST"
                                        action="{{ route('superadmin.truck-types.toggle', $type->id) }}"
                                        style="display:inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="card-action card-action--enable">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M6 12.5 L10 16.5 L18 7.5"/></svg>
                                            Enable
                                        </button>
                                    </form>
                                @endif

                                <button type="button" class="card-action card-action--danger js-delete-type"
                                    data-id="{{ $type->id }}"
                                    data-name="{{ $type->name }}"
                                    data-units="{{ $type->units_count ?? 0 }}"
                                    data-bookings="{{ $type->active_bookings_count ?? 0 }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M5 7.5 L19 7.5"/><path d="M9.5 7.5 L9.5 5 L14.5 5 L14.5 7.5"/><path d="M7 7.5 L7.8 19 L16.2 19 L17 7.5"/><line x1="10.3" y1="10.8" x2="10.3" y2="15.8"/><line x1="13.7" y1="10.8" x2="13.7" y2="15.8"/></svg>
                                    Delete
                                </button>
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
                        <label for="newTruckTypeName">Type</label>
                        <input id="newTruckTypeName" type="text" name="name"
                            placeholder="e.g. Flatbed, Wheel-Lift" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="newTruckTypeBase">Base Rate</label>
                            <input id="newTruckTypeBase" type="text" inputmode="decimal" name="base_rate"
                                placeholder="1,500" required>
                        </div>

                        <div class="form-group">
                            <label for="newTruckTypeKm">Per KM Rate</label>
                            <input id="newTruckTypeKm" type="text" inputmode="decimal" name="per_km_rate"
                                placeholder="200" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="newTruckTypeTonnage">Max Weight (kg)</label>
                        <input id="newTruckTypeTonnage" type="text" inputmode="decimal" name="max_tonnage"
                            placeholder="4,500">
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
                        <label for="editName">Type</label>
                        <input type="text" name="name" id="editName" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="editBase">Base Rate</label>
                            <input type="text" inputmode="decimal" name="base_rate" id="editBase" required>
                        </div>

                        <div class="form-group">
                            <label for="editKm">Per KM Rate</label>
                            <input type="text" inputmode="decimal" name="per_km_rate" id="editKm" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="editTonnage">Max Weight (kg)</label>
                        <input type="text" inputmode="decimal" name="max_tonnage" id="editTonnage">
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
