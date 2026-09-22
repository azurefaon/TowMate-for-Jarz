@extends('layouts.superadmin')

@section('title', 'Vehicle Types')

@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="{{ asset('admin/css/vehicle-types.css') }}?v={{ filemtime(public_path('admin/css/vehicle-types.css')) }}">
@endpush

@section('content')
    <div class="vc-page" data-base-url="{{ url('/superadmin/vehicle-types') }}" data-categories-url="{{ url('/superadmin/vehicle-categories') }}">
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
            <div class="search-box">
                <i data-lucide="search"></i>
                <input type="text" name="search" id="vcSearch" value="{{ request('search') }}" placeholder="Search vehicle types...">
            </div>

            <select name="category" id="vcCategoryFilter" data-custom>
                <option value="">All Categories</option>
                @foreach ($vehicleCategories as $cat)
                    <option value="{{ $cat->slug }}" {{ request('category') === $cat->slug ? 'selected' : '' }}>{{ $cat->name }}</option>
                @endforeach
            </select>

            <select name="status" id="vcStatusFilter" data-custom>
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>

            @if (request()->hasAny(['search', 'category', 'status']))
                <a href="{{ route('superadmin.vehicle-types.index') }}" class="vc-filter-reset">Reset</a>
            @endif
        </form>

        <p class="vc-order-hint" id="vcOrderBar">
            <span>Drag vehicles to reorder them within their category.</span>
            <span class="vc-form-hint" id="vcOrderFeedback"></span>
        </p>

        <div class="table-card">
            @if ($hasResults)
                <div class="vc-groups" id="vcGroups">
                    @foreach ($mainGroups as $group)
                        @php($vehicleTotal = collect($group['categories'])->sum(fn ($catGroup) => $catGroup['vehicles']->count()))
                        <details class="vc-group-trucktype" data-truck-type-id="{{ $group['truckType']->id }}" data-accordion-group="vc-main-trucktype" {{ $group['open'] ? 'open' : '' }}>
                            <summary>
                                <span>{{ $group['truckType']->name }}</span>
                                <div class="vc-header-actions">
                                    @if ($group['truckType']->id)
                                        <button type="button" class="vc-mini-btn js-vc-add-category-btn" data-truck-type-name="{{ $group['truckType']->name }}">
                                            <i data-lucide="plus"></i>
                                            Add Category
                                        </button>

                                        <div class="vc-order-mini-toggle">
                                            <button type="button" class="vc-mini-btn js-vc-edit-order-btn">
                                                <i data-lucide="arrow-up-down"></i>
                                                Edit Order
                                            </button>
                                            <div class="vc-order-mini-actions js-vc-order-actions">
                                                <button type="button" class="vc-btn-cancel vc-btn-cancel--sm js-vc-order-cancel">Cancel</button>
                                                <button type="button" class="vc-btn-save vc-btn-save--sm js-vc-order-save">Save Order</button>
                                            </div>
                                        </div>
                                    @endif
                                    <span class="vc-count">{{ $vehicleTotal }} vehicle{{ $vehicleTotal === 1 ? '' : 's' }}</span>
                                </div>
                            </summary>
                            @if (empty($group['categories']))
                                <div class="vc-group-empty">
                                    <span>No categories yet for this truck type.</span>
                                </div>
                            @else
                            <div class="vc-group-categories">
                                @foreach ($group['categories'] as $catGroup)
                                    <details class="vc-group-category" data-accordion-group="vc-main-category-{{ $group['truckType']->id }}" {{ $catGroup['open'] ? 'open' : '' }}>
                                        <summary data-slug="{{ $catGroup['category']->slug }}">
                                            <span>{{ $catGroup['category']->name }}</span>
                                            <div class="vc-header-actions">
                                                @if ($group['truckType']->id && $catGroup['category']->slug)
                                                    <button type="button" class="vc-mini-btn js-vc-add-vehicle-btn"
                                                        data-category-slug="{{ $catGroup['category']->slug }}"
                                                        data-truck-type-id="{{ $group['truckType']->id }}">
                                                        <i data-lucide="plus"></i>
                                                        Add Vehicle Type
                                                    </button>
                                                @endif
                                                <span class="vc-count">{{ $catGroup['vehicles']->count() }}</span>
                                            </div>
                                        </summary>
                                        <div class="vc-vehicle-list"
                                            data-truck-type-id="{{ $group['truckType']->id }}"
                                            data-category="{{ $catGroup['category']->slug }}">
                                            @foreach ($catGroup['vehicles'] as $type)
                                                <div class="vc-vehicle-row" data-id="{{ $type->id }}" data-status="{{ $type->status }}">
                                                    @if ($type->status === 'active')
                                                        <span class="vc-drag-handle" aria-hidden="true"></span>
                                                    @endif
                                                    <div class="vc-vehicle-main">
                                                        <span class="cell-main">{{ $type->name }}</span>
                                                        @if ($type->description)
                                                            <span class="cell-sub">{{ $type->description }}</span>
                                                        @endif
                                                    </div>

                                                    <div class="vc-vehicle-meta">
                                                        @if ($type->status === 'active' && isset($positionByVehicleId[$type->id]))
                                                            <span class="cell-sub">{{ $positionByVehicleId[$type->id] }} of {{ $activeCount }}</span>
                                                        @else
                                                            <span class="not-assigned">Not shown to customers</span>
                                                        @endif
                                                        <span class="status-text status-{{ $type->status }}">{{ ucfirst($type->status) }}</span>
                                                    </div>

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
                                                                data-required-truck-type-id="{{ $type->required_truck_type_id }}">
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
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endforeach
                            </div>
                            @endif
                        </details>
                    @endforeach
                </div>
            @else
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
            @endif
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
                            @foreach ($vehicleCategories as $cat)
                                <option value="{{ $cat->slug }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="vc-form-group">
                        <label for="addVcWeight">Weight (kg) <span>(optional)</span></label>
                        <input type="number" name="weight_kg" id="addVcWeight" min="0" step="1" placeholder="e.g. 4500">
                    </div>
                </div>

                <div class="vc-form-group">
                    <label for="addVcDescription">Description <span>(optional)</span></label>
                    <textarea name="description" id="addVcDescription" placeholder="Short note about this vehicle type"></textarea>
                </div>

                <div class="vc-form-group">
                    <label for="addVcRequiredTruckType">Required Truck Type<span class="vc-required" aria-hidden="true">*</span></label>
                    <span class="vc-form-hint">The one Truck Type every booking with this Vehicle Type must use for pricing and unit eligibility.</span>
                    <select name="required_truck_type_id" id="addVcRequiredTruckType" required>
                        <option value="">Select required truck type</option>
                        @foreach ($truckTypes as $truck)
                            <option value="{{ $truck->id }}">{{ $truck->name }}</option>
                        @endforeach
                    </select>
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
                    <p>Update vehicle details and required truck type.</p>
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
                            @foreach ($vehicleCategories as $cat)
                                <option value="{{ $cat->slug }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="vc-form-group">
                        <label for="editVcWeight">Weight (kg) <span>(optional)</span></label>
                        <input type="number" name="weight_kg" id="editVcWeight" min="0" step="1">
                    </div>
                </div>

                <div class="vc-form-group">
                    <label for="editVcDescription">Description <span>(optional)</span></label>
                    <textarea name="description" id="editVcDescription"></textarea>
                </div>

                <div class="vc-form-group">
                    <label for="editVcRequiredTruckType">Required Truck Type<span class="vc-required" aria-hidden="true">*</span></label>
                    <span class="vc-form-hint">The one Truck Type every booking with this Vehicle Type must use for pricing and unit eligibility.</span>
                    <select name="required_truck_type_id" id="editVcRequiredTruckType" required>
                        <option value="">Select required truck type</option>
                        @foreach ($truckTypes as $truck)
                            <option value="{{ $truck->id }}">{{ $truck->name }}</option>
                        @endforeach
                    </select>
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

    <div class="vc-modal vc-modal--sm" id="addCategoryModal">
        <div class="vc-modal-card">
            <div class="vc-modal-header">
                <div>
                    <h2 id="addCategoryModalTitle">Add Category</h2>
                </div>
                <button type="button" class="vc-modal-close" data-close-modal="addCategoryModal" aria-label="Close">
                    <i data-lucide="x"></i>
                </button>
            </div>

            <div class="vc-form-group">
                <label for="addCategoryName">Category name<span class="vc-required" aria-hidden="true">*</span></label>
                <input type="text" id="addCategoryName" placeholder="e.g. Off-Road">
            </div>
            <span class="vc-form-hint" id="addCategoryFeedback"></span>

            <div class="vc-modal-footer">
                <button type="button" class="vc-btn-cancel" data-close-modal="addCategoryModal">Close</button>
                <button type="button" class="vc-btn-save" id="addCategorySaveBtn">Save</button>
            </div>
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
