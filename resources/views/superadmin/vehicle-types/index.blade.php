@extends('layouts.superadmin')

@section('title', 'Vehicle Types')

@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="{{ asset('admin/css/vehicle-types.css') }}?v={{ filemtime(public_path('admin/css/vehicle-types.css')) }}">
@endpush

@section('content')
    <div class="vc-page" data-base-url="{{ url('/superadmin/vehicle-types') }}">
        <div class="page-top">
            <div>
                <h1>Vehicle Types</h1>
                <p>Manage the vehicle types customers can select when booking.</p>
            </div>
        </div>

        @if (session('success'))
            <div class="type-feedback type-feedback--success" id="vcSuccessAlert">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="type-feedback type-feedback--error" id="vcErrorAlert">{{ session('error') }}</div>
        @endif

        @include('superadmin.fleet._tabs')

        <form method="GET" id="vcFilterForm" class="vc-toolbar">
            <div class="vc-toolbar-left">
                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" name="search" id="vcSearch" value="{{ request('search') }}" placeholder="Search vehicle types...">
                </div>
            </div>

            <div class="vc-toolbar-right">
                <select name="category" id="vcCategoryFilter" data-custom>
                    <option value="">All Categories</option>
                    <option value="2_wheeler" {{ request('category') === '2_wheeler' ? 'selected' : '' }}>2-Wheeler</option>
                    <option value="4_wheeler" {{ request('category') === '4_wheeler' ? 'selected' : '' }}>4-Wheeler</option>
                    <option value="heavy_vehicle" {{ request('category') === 'heavy_vehicle' ? 'selected' : '' }}>Heavy Vehicle</option>
                </select>

                <select name="status" id="vcStatusFilter" data-custom>
                    <option value="">All Statuses</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>

                @if (request()->hasAny(['search', 'category', 'status']))
                    <a href="{{ route('superadmin.vehicle-types.index') }}" class="vc-filter-reset">Reset</a>
                @endif

                <button type="button" class="vc-add-btn" id="vcAddBtn">
                    <i data-lucide="plus"></i>
                    Add Vehicle Type
                </button>
            </div>
        </form>

        <div class="table-card">
            <div class="table-scroll">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Category</th>
                            <th>Truck Type</th>
                            <th>Status</th>
                            <th class="u-actions-col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($vehicleTypes as $type)
                            <tr>
                                <td data-label="Vehicle">
                                    <span class="cell-main">{{ $type->name }}</span>
                                    @if ($type->description)
                                        <span class="cell-sub">{{ $type->description }}</span>
                                    @endif
                                </td>

                                <td data-label="Category">
                                    <span class="cell-main">{{ $type->category_label }}</span>
                                </td>

                                <td data-label="Truck Type">
                                    @if ($type->truckTypes->isNotEmpty())
                                        <span class="cell-main">{{ $type->truckTypes->pluck('name')->join(', ') }}</span>
                                    @else
                                        <span class="not-assigned">None assigned</span>
                                    @endif
                                </td>

                                <td data-label="Status">
                                    <span class="status-text status-{{ $type->status }}">{{ ucfirst($type->status) }}</span>
                                </td>

                                <td data-label="Actions" class="u-actions-col">
                                    <div class="u-menu">
                                        <button type="button" class="u-menu-trigger" aria-haspopup="menu"
                                            aria-expanded="false" aria-label="Actions for {{ $type->name }}">
                                            <i data-lucide="more-vertical"></i>
                                        </button>

                                        <div class="u-menu-dropdown" role="menu">
                                            <button type="button" class="u-menu-item js-vc-edit" role="menuitem"
                                                data-id="{{ $type->id }}"
                                                data-name="{{ $type->name }}"
                                                data-category="{{ $type->category }}"
                                                data-weight="{{ $type->weight_kg }}"
                                                data-description="{{ $type->description }}"
                                                data-truck-ids="{{ $type->truckTypes->pluck('id')->join(',') }}">
                                                <i data-lucide="pencil"></i>
                                                <span>Edit Vehicle Type</span>
                                            </button>

                                            <form method="POST" class="u-menu-form"
                                                action="{{ route('superadmin.vehicle-types.toggle', $type->id) }}">
                                                @csrf
                                                @method('PATCH')
                                                @if ($type->status === 'active')
                                                    <button type="submit" class="u-menu-item u-menu-item--danger" role="menuitem">
                                                        <i data-lucide="ban"></i>
                                                        <span>Disable</span>
                                                    </button>
                                                @else
                                                    <button type="submit" class="u-menu-item u-menu-item--positive" role="menuitem">
                                                        <i data-lucide="check"></i>
                                                        <span>Enable</span>
                                                    </button>
                                                @endif
                                            </form>

                                            @if ($type->bookings_count === 0)
                                                <div class="u-menu-divider"></div>
                                                <button type="button" class="u-menu-item u-menu-item--danger js-vc-delete" role="menuitem"
                                                    data-id="{{ $type->id }}"
                                                    data-name="{{ $type->name }}">
                                                    <i data-lucide="trash-2"></i>
                                                    <span>Delete</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="empty-row">
                                        @if (request()->hasAny(['search', 'category', 'status']))
                                            <i data-lucide="search-x" class="empty-state-icon"></i>
                                            <span class="empty-row-title">No vehicle types found</span>
                                            <span class="empty-row-hint">Try adjusting your search or filters.</span>
                                        @else
                                            <i data-lucide="package-open" class="empty-state-icon"></i>
                                            <span class="empty-row-title">No vehicle types yet</span>
                                            <span class="empty-row-hint">Add a vehicle type to make it available for customer booking.</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrapper">
                {{ $vehicleTypes->links('vendor.pagination.custom') }}
            </div>
        </div>

    <div class="vc-modal" id="addModal">
        <div class="vc-modal-card">
            <div class="vc-modal-header">
                <div>
                    <h2>Add Vehicle Type</h2>
                    <p>Create a vehicle option customers can select when booking.</p>
                </div>
                <button type="button" class="vc-modal-close" data-close-modal="addModal" aria-label="Close">
                    <i data-lucide="x"></i>
                </button>
            </div>

            <form method="POST" action="{{ route('superadmin.vehicle-types.store') }}" class="vc-modal-form">
                @csrf
                <div class="vc-form-group">
                    <label for="addVcName">Vehicle name<span class="vc-required" aria-hidden="true">*</span></label>
                    <input type="text" name="name" id="addVcName" required placeholder="Sedan, Motorcycle, Van...">
                </div>

                <div class="vc-form-row">
                    <div class="vc-form-group">
                        <label for="addVcCategory">Category<span class="vc-required" aria-hidden="true">*</span></label>
                        <select name="category" id="addVcCategory" required>
                            <option value="">Select category</option>
                            <option value="2_wheeler">2-Wheeler</option>
                            <option value="4_wheeler">4-Wheeler</option>
                            <option value="heavy_vehicle">Heavy Vehicle</option>
                        </select>
                    </div>
                    <div class="vc-form-group">
                        <label for="addVcWeight">Weight (kg)<span class="vc-required" aria-hidden="true">*</span></label>
                        <input type="number" name="weight_kg" id="addVcWeight" required min="0" step="1" placeholder="e.g. 4500">
                    </div>
                </div>

                <div class="vc-form-group">
                    <label for="addVcDescription">Description <span>(optional)</span></label>
                    <textarea name="description" id="addVcDescription" placeholder="Short note about this vehicle type"></textarea>
                </div>

                <div class="vc-form-group vc-compat-section">
                    <label>Compatible Truck Types</label>
                    <span class="vc-form-hint">Only Truck Types compatible with the entered weight can be selected.</span>
                    <div class="vc-trucks-grid" id="addVcTrucks">
                        @foreach ($truckTypes as $truck)
                            <label class="vc-truck-check" data-class="{{ $truck->class }}">
                                <span class="vc-check-content">
                                    <input type="checkbox" name="truck_types[]" value="{{ $truck->id }}"
                                        class="vc-truck-check-input add-truck-check" data-class="{{ $truck->class }}"
                                        data-capacity="{{ $truck->max_tonnage }}">
                                    <span class="vc-check-label">{{ $truck->name }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="vc-modal-footer">
                    <button type="button" class="vc-btn-cancel" data-close-modal="addModal">Cancel</button>
                    <button type="submit" class="vc-btn-save">
                        <i data-lucide="plus"></i>
                        Add Vehicle Type
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="vc-modal" id="editModal">
        <div class="vc-modal-card">
            <div class="vc-modal-header">
                <div>
                    <h2>Edit Vehicle Type</h2>
                    <p>Update vehicle details and Truck Type assignments.</p>
                </div>
                <button type="button" class="vc-modal-close" data-close-modal="editModal" aria-label="Close">
                    <i data-lucide="x"></i>
                </button>
            </div>

            <form method="POST" id="editVcForm" class="vc-modal-form">
                @csrf
                @method('PUT')
                <div class="vc-form-group">
                    <label for="editVcName">Vehicle name<span class="vc-required" aria-hidden="true">*</span></label>
                    <input type="text" name="name" id="editVcName" required>
                </div>

                <div class="vc-form-row">
                    <div class="vc-form-group">
                        <label for="editVcCategory">Category<span class="vc-required" aria-hidden="true">*</span></label>
                        <select name="category" id="editVcCategory" required>
                            <option value="2_wheeler">2-Wheeler</option>
                            <option value="4_wheeler">4-Wheeler</option>
                            <option value="heavy_vehicle">Heavy Vehicle</option>
                        </select>
                    </div>
                    <div class="vc-form-group">
                        <label for="editVcWeight">Weight (kg)<span class="vc-required" aria-hidden="true">*</span></label>
                        <input type="number" name="weight_kg" id="editVcWeight" required min="0" step="1">
                    </div>
                </div>

                <div class="vc-form-group">
                    <label for="editVcDescription">Description <span>(optional)</span></label>
                    <textarea name="description" id="editVcDescription"></textarea>
                </div>

                <div class="vc-form-group vc-compat-section">
                    <label>Compatible Truck Types</label>
                    <span class="vc-form-hint">Only Truck Types compatible with the entered weight can be selected.</span>
                    <div class="vc-trucks-grid" id="editVcTrucks">
                        @foreach ($truckTypes as $truck)
                            <label class="vc-truck-check" data-class="{{ $truck->class }}">
                                <span class="vc-check-content">
                                    <input type="checkbox" name="truck_types[]" value="{{ $truck->id }}"
                                        class="vc-truck-check-input edit-truck-check" data-class="{{ $truck->class }}"
                                        data-capacity="{{ $truck->max_tonnage }}">
                                    <span class="vc-check-label">{{ $truck->name }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="vc-modal-footer">
                    <button type="button" class="vc-btn-cancel" data-close-modal="editModal">Cancel</button>
                    <button type="submit" class="vc-btn-save">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div class="vc-modal vc-confirm-modal" id="deleteModal">
        <div class="vc-modal-card">
            <p class="vc-confirm-title">Delete Vehicle Type</p>
            <p class="vc-confirm-text" id="deleteVcText"></p>

            <form method="POST" id="deleteVcForm" class="vc-modal-form">
                @csrf
                @method('DELETE')
                <div class="vc-confirm-actions">
                    <button type="button" class="vc-btn-cancel" data-close-modal="deleteModal">Cancel</button>
                    <button type="submit" class="vc-btn-save vc-btn-save--danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('admin/js/vehicle-types.js') }}?v={{ filemtime(public_path('admin/js/vehicle-types.js')) }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            ['vcSuccessAlert', 'vcErrorAlert'].forEach((id) => {
                const alertEl = document.getElementById(id);
                if (!alertEl) return;
                setTimeout(() => {
                    alertEl.classList.add('fade-out');
                    setTimeout(() => alertEl.remove(), 300);
                }, 3500);
            });
        });
    </script>
@endpush
