@extends('layouts.superadmin')

@section('title', 'Truck Types & Rates')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/truck-types.css') }}?v={{ filemtime(public_path('admin/css/truck-types.css')) }}">
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
                <h1>Truck Types &amp; Rates</h1>
                <p>Manage towing classes and rates used for pricing and dispatch.</p>
            </div>
        </div>

        @include('superadmin.fleet._tabs')

        <div class="tt-toolbar">
            <div class="tt-toolbar-left">
                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" id="truckTypeSearch" placeholder="Search towing classes...">
                </div>

                <select id="truckTypeStatusFilter" data-custom>
                    <option value="all">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <button type="button" class="tt-add-btn" data-open-modal="addModal">
                <i data-lucide="plus"></i>
                Add Truck Type
            </button>
        </div>

        <div class="tt-table-card">
            <div class="tt-table-shell">
                <table class="tt-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="align-right">Rate per km</th>
                            <th class="align-right">Base Rate</th>
                            <th class="align-right">Weight Capacity</th>
                            <th>Common Uses</th>
                            <th>Status</th>
                            <th class="tt-actions-col"></th>
                        </tr>
                    </thead>

                    <tbody id="truckTypesBody">
                        @forelse ($truckTypes as $type)
                            <tr data-card data-status="{{ $type->status }}"
                                data-search="{{ strtolower($type->name . ' ' . ($type->description ?? '')) }}">

                                <td>
                                    <span class="cell-main">{{ $type->name }}</span>
                                </td>

                                <td class="align-right">
                                    <span class="cell-main">₱{{ number_format($type->per_km_rate, 2) }}</span>
                                </td>

                                <td class="align-right">
                                    <span class="cell-main">₱{{ number_format($type->base_rate, 2) }}</span>
                                </td>

                                <td class="align-right">
                                    @if ($type->max_tonnage)
                                        <span class="cell-main">{{ number_format((float) $type->max_tonnage, 0) }} kg</span>
                                    @else
                                        <span class="cell-muted">—</span>
                                    @endif
                                </td>

                                <td>
                                    @if ($type->description)
                                        <span class="cell-main">{{ $type->description }}</span>
                                    @else
                                        <span class="cell-muted">—</span>
                                    @endif
                                </td>

                                <td>
                                    <span class="status-text {{ $type->status === 'active' ? 'is-active' : 'is-inactive' }}">
                                        {{ ucfirst($type->status) }}
                                    </span>
                                </td>

                                <td class="tt-actions-col">
                                    <div class="tt-action-group">
                                        <button type="button" class="tt-icon-btn tt-icon-btn--edit js-edit-type"
                                            data-id="{{ $type->id }}"
                                            data-name="{{ $type->name }}"
                                            data-base="{{ $type->base_rate }}"
                                            data-km="{{ $type->per_km_rate }}"
                                            data-tonnage="{{ $type->max_tonnage }}"
                                            data-description="{{ $type->description }}"
                                            aria-label="Edit truck type" data-tooltip="Edit">
                                            <i data-lucide="pencil"></i>
                                        </button>

                                        @if ($type->status === 'active')
                                            <button type="button" class="tt-icon-btn tt-icon-btn--disable js-disable-type"
                                                data-id="{{ $type->id }}"
                                                data-name="{{ $type->name }}"
                                                data-busy="{{ ($type->units_count ?? 0) > 0 || ($type->active_bookings_count ?? 0) > 0 ? '1' : '0' }}"
                                                data-unit-count="{{ $type->units_count ?? 0 }}"
                                                data-booking-count="{{ $type->active_bookings_count ?? 0 }}"
                                                aria-label="Disable truck type" data-tooltip="Disable">
                                                <i data-lucide="ban"></i>
                                            </button>
                                        @else
                                            <form method="POST" class="tt-inline-form"
                                                action="{{ route('superadmin.truck-types.toggle', $type->id) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="tt-icon-btn tt-icon-btn--enable"
                                                    aria-label="Enable truck type" data-tooltip="Enable">
                                                    <i data-lucide="check"></i>
                                                </button>
                                            </form>
                                        @endif

                                        <button type="button" class="tt-icon-btn tt-icon-btn--delete js-delete-type"
                                            data-id="{{ $type->id }}"
                                            data-name="{{ $type->name }}"
                                            data-units="{{ $type->units_count ?? 0 }}"
                                            data-bookings="{{ $type->active_bookings_count ?? 0 }}"
                                            aria-label="Delete truck type" data-tooltip="Delete">
                                            <i data-lucide="trash-2"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="empty-row">
                                    <i data-lucide="inbox" class="empty-row-icon"></i>
                                    <span class="empty-row-title">No tow truck classes yet.</span>
                                    <span class="empty-row-hint">Add your first towing class to organize the fleet.</span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="tt-table-footer">
                <span class="tt-result-count">Showing {{ $truckTypes->count() }} of {{ $truckTypes->total() }} truck types</span>

                <div class="tt-pagination">
                    @if ($truckTypes->hasPages())
                        {{ $truckTypes->onEachSide(1)->links('vendor.pagination.custom') }}
                    @endif
                </div>
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
                        <label for="newTruckTypeDescription">Common Uses</label>
                        <textarea id="newTruckTypeDescription" name="description"
                            placeholder="e.g. 10-wheelers, wing vans, trailer trucks"></textarea>
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
                        <label for="editDescription">Common Uses</label>
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
                        <button type="button" class="btn-light" data-close-modal="disableModal">Close</button>
                        <button type="submit" class="btn-danger" id="disableSubmitBtn">Disable</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="deleteModal" class="modal">
            <div class="modal-content delete-modal-content">
                <h3 id="deleteTitle">Delete Truck Type?</h3>
                <p id="deleteText">Are you sure you want to delete this truck type?</p>

                <form method="POST" id="deleteForm">
                    @csrf
                    @method('DELETE')

                    <div class="modal-actions">
                        <button type="button" class="btn-light" data-close-modal="deleteModal">Cancel</button>
                        <button type="submit" class="btn-danger" id="deleteSubmitBtn">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('admin/js/truck-types.js') }}?v={{ filemtime(public_path('admin/js/truck-types.js')) }}" defer></script>
@endpush
