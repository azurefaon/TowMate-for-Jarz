@extends('layouts.superadmin')

@section('title', 'Tow Truck Types')

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
                <h1>Tow Truck Types</h1>
                <p>Manage only the truck classes used for towing operations, not customer vehicle categories.</p>
            </div>

            <div class="header-actions">
                <a href="{{ route('superadmin.unit-truck.index') }}" class="btn-back">
                    <i data-lucide="arrow-left"></i>
                    <span>Back to Units</span>
                </a>
            </div>
        </div>

        <div class="overview-grid">
            <div class="overview-card">
                <span>Total Types</span>
                <strong>{{ $stats['total'] }}</strong>
                <small>Configured towing categories</small>
            </div>

            <div class="overview-card accent-card">
                <span>Active Types</span>
                <strong>{{ $stats['active'] }}</strong>
                <small>Available for dispatch</small>
            </div>

            <div class="overview-card muted-card">
                <span>Inactive Types</span>
                <strong>{{ $stats['inactive'] }}</strong>
                <small>Hidden from new assignments</small>
            </div>

            <div class="overview-card dark-card">
                <span>Fleet Units</span>
                <strong>{{ $stats['units'] }}</strong>
                <small>Units linked to tow classes</small>
            </div>
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
                            <i data-lucide="search"></i>
                            <input type="text" id="truckTypeSearch" placeholder="Search towing classes...">
                        </label>

                        <select id="truckTypeStatusFilter" class="status-filter">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>

                        <button type="button" class="btn-primary-add" data-open-modal="addModal">
                            {{-- <i data-lucide="plus-circle"></i> --}}
                            <span>Add Tow Truck Type</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-scroll">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Truck Type</th>
                            <th>Pricing</th>
                            <th>Capacity</th>
                            <th>Linked Units</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody id="truckTypesTable">
                        @forelse ($truckTypes as $type)
                            <tr data-status="{{ $type->status }}">
                                <td data-label="Truck Type">
                                    <div class="type-name-wrap">
                                        <strong>{{ $type->name }}</strong>
                                        <small>{{ $type->description ?: 'Dedicated towing class for fleet dispatch.' }}</small>
                                    </div>
                                </td>
                                <td data-label="Pricing">
                                    <div class="price-stack">
                                        <div class="price-line">
                                            <small>Base Rate</small>
                                            <strong>₱{{ number_format($type->base_rate, 2) }}</strong>
                                        </div>
                                        <div class="price-line">
                                            <small>Per KM</small>
                                            <span>₱{{ number_format($type->per_km_rate, 2) }}/km</span>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Capacity">
                                    {{ $type->max_tonnage ? number_format((float) $type->max_tonnage, 1) . ' tons' : 'Not set' }}
                                </td>
                                <td data-label="Linked Units">
                                    <span class="count-pill">{{ $type->units_count ?? 0 }} units</span>
                                </td>
                                <td data-label="Status">
                                    <span class="status-pill {{ $type->status }}">{{ ucfirst($type->status) }}</span>
                                </td>
                                <td data-label="Actions" class="table-actions">
                                    <div class="action-buttons">
                                        <button type="button" class="action-btn edit-btn js-edit-type"
                                            data-id="{{ $type->id }}" data-name="{{ $type->name }}"
                                            data-base="{{ $type->base_rate }}" data-km="{{ $type->per_km_rate }}"
                                            data-tonnage="{{ $type->max_tonnage }}"
                                            data-description="{{ $type->description }}">
                                            <i data-lucide="pencil"></i>
                                            <span>Edit</span>
                                        </button>

                                        @if ($type->status === 'active')
                                            <button type="button" class="action-btn btn-danger js-disable-type"
                                                data-id="{{ $type->id }}" data-name="{{ $type->name }}"
                                                data-busy="{{ ($type->units_count ?? 0) > 0 || ($type->active_bookings_count ?? 0) > 0 ? '1' : '0' }}"
                                                data-unit-count="{{ $type->units_count ?? 0 }}"
                                                data-booking-count="{{ $type->active_bookings_count ?? 0 }}">
                                                <i data-lucide="ban"></i>
                                                <span>{{ ($type->units_count ?? 0) > 0 || ($type->active_bookings_count ?? 0) > 0 ? 'Busy' : 'Disable' }}</span>
                                            </button>
                                        @else
                                            <form method="POST"
                                                action="{{ route('superadmin.truck-types.toggle', $type->id) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="action-btn btn-success">
                                                    <i data-lucide="check-circle"></i>
                                                    <span>Enable</span>
                                                </button>
                                            </form>
                                        @endif

                                        <button type="button" class="action-btn btn-danger js-delete-type"
                                            data-id="{{ $type->id }}" data-name="{{ $type->name }}"
                                            data-units="{{ $type->units_count ?? 0 }}"
                                            data-bookings="{{ $type->active_bookings_count ?? 0 }}">
                                            <i data-lucide="trash-2"></i>
                                            <span>Delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i data-lucide="truck"></i>
                                        <h3>No tow truck types yet</h3>
                                        <p>Add your first towing class to organize the fleet better.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrapper">
                {{ $truckTypes->onEachSide(1)->links('vendor.pagination.custom') }}
            </div>
        </div>

        <div id="addModal" class="modal">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h2>Add Tow Truck Type</h2>
                        <p>Create a towing-only truck class with pricing and load range.</p>
                    </div>
                    <button type="button" class="modal-close" data-close-modal="addModal">✕</button>
                </div>

                <form method="POST" action="{{ route('superadmin.truck-types.store') }}">
                    @csrf

                    <div class="form-group">
                        <label for="newTruckTypeName">Truck Type Name</label>
                        <small class="input-hint">Example: Flatbed, Wheel-Lift, Medium Duty, or Heavy Duty</small>
                        <input id="newTruckTypeName" type="text" name="name"
                            placeholder="Enter towing class like Flatbed" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="newTruckTypeBase">Base Rate</label>
                            <small class="input-hint">Starting rate for the initial distance</small>
                            <input id="newTruckTypeBase" type="number" step="0.01" name="base_rate"
                                placeholder="1500" required>
                        </div>

                        <div class="form-group">
                            <label for="newTruckTypeKm">Per KM Rate</label>
                            <small class="input-hint">Additional cost per kilometer after base distance</small>
                            <input id="newTruckTypeKm" type="number" step="0.01" name="per_km_rate"
                                placeholder="200" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="newTruckTypeTonnage">Max Tonnage</label>
                        <small class="input-hint">Load capacity for this towing truck type</small>
                        <input id="newTruckTypeTonnage" type="number" step="0.01" name="max_tonnage"
                            placeholder="4.5">
                    </div>

                    <div class="form-group">
                        <label for="newTruckTypeDescription">Description</label>
                        <small class="input-hint">Short note about what recovery jobs this truck fits best</small>
                        <textarea id="newTruckTypeDescription" name="description"
                            placeholder="Suitable for compact recovery and city towing"></textarea>
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
                        <h2>Edit Tow Truck Type</h2>
                        <p>Adjust towing price, capacity, or operational notes.</p>
                    </div>
                    <button type="button" class="modal-close" data-close-modal="editModal">✕</button>
                </div>

                <form method="POST" id="editForm">
                    @csrf
                    @method('PUT')

                    <div class="form-group">
                        <label for="editName">Truck Type Name</label>
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
                <div class="danger-icon">
                    <i data-lucide="alert-triangle"></i>
                </div>
                <h3 id="disableTitle">Disable Tow Truck Type?</h3>
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
                <div class="icon-warning">⚠️</div>
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
