@extends('layouts.superadmin')

@section('title', 'Vehicle Types')

@push('styles')
    {{-- Shared fleet page styles (header, table, menus, modals, form fields). --}}
    <link rel="stylesheet" href="{{ asset('superadmin/css/truck-types.css') }}?v={{ filemtime(public_path('superadmin/css/truck-types.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/vehicle-types.css') }}?v={{ filemtime(public_path('superadmin/css/vehicle-types.css')) }}">
@endpush

@php
    $formFields = ['name', 'category', 'weight_kg', 'required_truck_type_id', 'description'];
    $formErrorCount = collect($formFields)->filter(fn ($field) => $errors->has($field))->count();
    $reopenModal = $formErrorCount > 0 ? old('_modal', 'add') : null;
    $editVehicle = $reopenModal === 'edit' ? \App\Models\VehicleType::withCount('bookings')->find((int) old('_edit_id')) : null;
    $formAction = $editVehicle ? route('superadmin.vehicle-types.update', $editVehicle->id) : route('superadmin.vehicle-types.store');

    $truckLabel = fn ($type) => $type->name . ($type->class ? ' · ' . ucfirst($type->class) . ' class' : '');
    $oldTruckId = (int) old('required_truck_type_id');
    $extraTruck = $oldTruckId && ! $truckTypes->contains('id', $oldTruckId) ? \App\Models\TruckType::find($oldTruckId) : null;
    $weightLabel = fn ($kg) => $kg !== null ? number_format((float) $kg) . ' kg' : 'Not set';
    $weightValue = fn ($kg) => $kg !== null ? rtrim(rtrim(number_format((float) $kg, 2, '.', ''), '0'), '.') : '';
@endphp

@section('content')
    <div class="tt-page vt-page" id="vehicleTypesPage"
        data-index-url="{{ route('superadmin.vehicle-types.index') }}"
        data-store-url="{{ route('superadmin.vehicle-types.store') }}"
        data-update-url="{{ route('superadmin.vehicle-types.update', '__ID__') }}"
        data-toggle-url="{{ route('superadmin.vehicle-types.toggle', '__ID__') }}"
        data-destroy-url="{{ route('superadmin.vehicle-types.destroy', '__ID__') }}"
        data-reorder-url="{{ route('superadmin.vehicle-types.reorder') }}"
        data-category-store-url="{{ route('superadmin.vehicle-categories.store') }}"
        data-category-update-url="{{ route('superadmin.vehicle-categories.update', '__ID__') }}"
        @if ($reopenModal) data-reopen="{{ $reopenModal }}" @endif>

        <header class="tt-header">
            <div>
                <h1>Vehicle Types</h1>
                <p>Vehicles customers choose when booking, grouped by the truck type each one needs.</p>
            </div>
            <div class="vt-header-actions">
                <button type="button" class="tt-btn vt-btn-lg" data-open-categories aria-haspopup="dialog">Manage categories</button>
                <button type="button" class="tt-btn tt-btn--primary" data-open-add aria-haspopup="dialog">
                    <i data-lucide="plus" aria-hidden="true"></i>
                    Add vehicle type
                </button>
            </div>
        </header>

        @if (session('success'))
            <div class="tt-flash tt-flash--success" role="status">
                <i data-lucide="circle-check" aria-hidden="true"></i>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div class="tt-flash tt-flash--error" role="alert">
                <i data-lucide="circle-alert" aria-hidden="true"></i>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <form class="tt-filters" id="vehicleFilters" method="GET" action="{{ route('superadmin.vehicle-types.index') }}" role="search">
            <label class="tt-search">
                <span class="tt-sr">Search vehicle types</span>
                <i data-lucide="search" aria-hidden="true"></i>
                <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search vehicle type" autocomplete="off">
            </label>

            <label class="tt-select vt-select">
                <span class="tt-sr">Category</span>
                <select name="category">
                    <option value="">All categories</option>
                    @foreach ($vehicleCategories as $category)
                        <option value="{{ $category->slug }}" @selected($filters['category'] === $category->slug)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <i data-lucide="chevron-down" aria-hidden="true"></i>
            </label>

            <label class="tt-select vt-select">
                <span class="tt-sr">Status</span>
                <select name="status">
                    <option value="" @selected($filters['status'] === '')>All statuses</option>
                    <option value="active" @selected($filters['status'] === 'active')>Active</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
                </select>
                <i data-lucide="chevron-down" aria-hidden="true"></i>
            </label>

            <noscript><button type="submit" class="tt-btn">Apply</button></noscript>
        </form>

        <div id="vehicleResults" class="vt-results" aria-live="polite">
            @if ($shownCount === 0 && ($hasFilters || $totalCount > 0))
                <div class="tt-card tt-state">
                    <span class="tt-state-icon"><i data-lucide="search-x" aria-hidden="true"></i></span>
                    <p class="tt-state-title">No vehicle types match these filters</p>
                    <p class="tt-state-text">Try a different name, category, or status.</p>
                    <a href="{{ route('superadmin.vehicle-types.index') }}" class="tt-btn" data-clear-filters>Clear filters</a>
                </div>
            @else
                <p class="vt-summary">
                    Showing {{ number_format($shownCount) }} of {{ number_format($totalCount) }} {{ $totalCount === 1 ? 'vehicle type' : 'vehicle types' }}.
                    Order is set within each category of a truck type.
                </p>

                @if ($totalCount === 0)
                    <div class="tt-card tt-state vt-first-run">
                        <span class="tt-state-icon"><i data-lucide="car-front" aria-hidden="true"></i></span>
                        <p class="tt-state-title">No vehicle types yet</p>
                        <p class="tt-state-text">Add the vehicles customers can choose when they book a tow.</p>
                        <button type="button" class="tt-btn tt-btn--primary" data-open-add>
                            <i data-lucide="plus" aria-hidden="true"></i>
                            Add vehicle type
                        </button>
                    </div>
                @endif

                @foreach ($mainGroups as $group)
                    @php
                        $truck = $group['truckType'];
                        $groupId = 'vtGroup' . ($truck->id ?? 0);
                        $meta = collect([
                            $truck ? ($truck->class ? ucfirst($truck->class) . ' class' : 'Class not set') : null,
                            $group['count'] . ' ' . ($group['count'] === 1 ? 'vehicle type' : 'vehicle types'),
                        ])->filter()->join(' · ');
                    @endphp
                    <section class="tt-card vt-card" id="{{ $groupId }}" aria-labelledby="{{ $groupId }}Title" data-group
                        data-truck-type-id="{{ $truck->id ?? '' }}">
                        <header class="vt-card-head">
                            <h2 id="{{ $groupId }}Title">
                                {{ $truck->name ?? 'No truck type' }}
                                <span class="vt-card-meta">{{ $meta }}@if ($truck && $truck->status !== 'active') · <span class="vt-warn">Truck type inactive</span>@endif</span>
                            </h2>

                            @if ($group['reorderable'])
                                <div class="vt-order-tools">
                                    <button type="button" class="tt-btn tt-btn--sm" data-edit-order
                                        @if ($hasFilters) aria-disabled="true" aria-describedby="{{ $groupId }}OrderWhy" @endif>
                                        <i data-lucide="arrow-up-down" aria-hidden="true"></i>
                                        Edit order
                                    </button>
                                    @if ($hasFilters)
                                        <span class="tt-sr" id="{{ $groupId }}OrderWhy">Clear search and filters to edit the order, so every vehicle in each category is included.</span>
                                    @endif
                                    <button type="button" class="tt-btn tt-btn--sm" data-order-cancel hidden>Cancel</button>
                                    <button type="button" class="tt-btn tt-btn--sm tt-btn--primary" data-order-save hidden>Save order</button>
                                </div>
                            @endif
                        </header>

                        <p class="vt-order-feedback" data-order-feedback role="status" hidden></p>

                        @if ($group['categories']->isEmpty())
                            <p class="vt-empty-group">No vehicle types use this truck type yet.</p>
                        @else
                            <table class="tt-table vt-table">
                                <caption class="tt-sr">Vehicle types that need {{ $truck->name ?? 'no truck type' }}</caption>
                                <colgroup>
                                    <col class="vt-col-order">
                                    <col class="vt-col-name">
                                    <col class="vt-col-weight">
                                    <col class="vt-col-status">
                                    <col class="vt-col-actions">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th scope="col">Order</th>
                                        <th scope="col">Vehicle type</th>
                                        <th scope="col">Weight</th>
                                        <th scope="col">Status</th>
                                        <th scope="col"><span class="tt-sr">Actions</span></th>
                                    </tr>
                                </thead>
                                @foreach ($group['categories'] as $categoryGroup)
                                    <tbody data-category-group data-category="{{ $categoryGroup['slug'] }}">
                                        <tr class="vt-category-row">
                                            <th scope="rowgroup" colspan="5">
                                                {{ $categoryGroup['name'] }}
                                                <span class="vt-card-meta">{{ $categoryGroup['vehicles']->count() }} {{ $categoryGroup['vehicles']->count() === 1 ? 'vehicle type' : 'vehicle types' }}</span>
                                            </th>
                                        </tr>
                                        @foreach ($categoryGroup['vehicles'] as $vehicle)
                                            @php
                                                $isActive = $vehicle->status === 'active';
                                                $vehicleTruck = $vehicle->requiredTruckType;
                                            @endphp
                                            <tr class="vt-row" data-vehicle-id="{{ $vehicle->id }}" data-status="{{ $vehicle->status }}">
                                                <td class="vt-order">
                                                    @if ($isActive)
                                                        <span data-rank>{{ $vehicle->group_rank }}</span>
                                                    @else
                                                        <span class="tt-muted">Hidden</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <span class="tt-main" data-vehicle-name>{{ $vehicle->name }}</span>
                                                    @if ($vehicle->description)
                                                        <span class="tt-sub">{{ $vehicle->description }}</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <span @class(['tt-muted' => $vehicle->weight_kg === null])>{{ $weightLabel($vehicle->weight_kg) }}</span>
                                                </td>
                                                <td>
                                                    <span class="tt-badge {{ $isActive ? 'is-active' : 'is-inactive' }}">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                                                </td>
                                                <td class="vt-actions">
                                                    <button type="button" class="tt-btn tt-btn--sm" data-edit aria-haspopup="dialog"
                                                        data-id="{{ $vehicle->id }}"
                                                        data-name="{{ $vehicle->name }}"
                                                        data-category="{{ $vehicle->category }}"
                                                        data-category-name="{{ $categoryGroup['name'] }}"
                                                        data-weight="{{ $weightValue($vehicle->weight_kg) }}"
                                                        data-description="{{ $vehicle->description }}"
                                                        data-truck-id="{{ $vehicle->required_truck_type_id }}"
                                                        data-truck-label="{{ $vehicleTruck ? $truckLabel($vehicleTruck) . ($vehicleTruck->status !== 'active' ? ' (inactive)' : '') : '' }}"
                                                        data-truck-name="{{ $vehicleTruck->name ?? '' }}"
                                                        data-status="{{ $vehicle->status }}"
                                                        data-rank="{{ $vehicle->group_rank }}"
                                                        data-bookings="{{ $vehicle->bookings_count }}"
                                                        aria-label="Edit {{ $vehicle->name }}">Edit</button>
                                                    @if ($isActive && $group['reorderable'] && $categoryGroup['slug'] !== null)
                                                        <span class="vt-move" data-move-controls hidden>
                                                            <button type="button" class="tt-icon-btn" data-move="up" aria-label="Move {{ $vehicle->name }} up">
                                                                <i data-lucide="arrow-up" aria-hidden="true"></i>
                                                            </button>
                                                            <button type="button" class="tt-icon-btn" data-move="down" aria-label="Move {{ $vehicle->name }} down">
                                                                <i data-lucide="arrow-down" aria-hidden="true"></i>
                                                            </button>
                                                        </span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                @endforeach
                            </table>
                        @endif
                    </section>
                @endforeach

                <p class="vt-footnote">Inactive vehicle types are hidden from customers and can't be reordered. Enable, disable and delete are inside Edit.</p>
            @endif
        </div>
    </div>

    {{-- Add / edit vehicle type --}}
    <div class="tt-modal" id="vehicleModal" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog vt-dialog--form" role="dialog" aria-modal="true" aria-labelledby="vtModalTitle" aria-describedby="vtModalDesc">
            <header class="tt-dialog-head">
                <div>
                    <h2 id="vtModalTitle">{{ $editVehicle ? 'Edit vehicle type' : 'Add vehicle type' }}</h2>
                    <p id="vtModalDesc">{{ $editVehicle ? $editVehicle->name : 'New vehicle types are shown to customers last in their category.' }}</p>
                </div>
                <button type="button" class="tt-icon-btn tt-dialog-close" data-close-modal aria-label="Close">
                    <i data-lucide="x" aria-hidden="true"></i>
                </button>
            </header>

            <div class="tt-dialog-body">
                <form method="POST" action="{{ $formAction }}" id="vehicleForm" novalidate>
                    @csrf
                    <input type="hidden" name="_method" value="PUT" @disabled(! $editVehicle)>
                    <input type="hidden" name="_modal" value="{{ $editVehicle ? 'edit' : 'add' }}">
                    <input type="hidden" name="_edit_id" value="{{ $editVehicle?->id }}">

                    <div class="tt-form-alert" role="alert" data-form-alert @if ($formErrorCount === 0) hidden @endif>
                        <i data-lucide="circle-alert" aria-hidden="true"></i>
                        <span>
                            @if ($formErrorCount === 1)
                                Check the highlighted field. Everything else you entered is kept.
                            @else
                                Check the {{ $formErrorCount }} highlighted fields. Everything else you entered is kept.
                            @endif
                        </span>
                    </div>

                    <section class="tt-section" aria-labelledby="vtSectionVehicle">
                        <h3 id="vtSectionVehicle">Vehicle</h3>

                        <div class="tt-field @error('name') has-error @enderror">
                            <label for="vtName">Name <span class="tt-req" aria-hidden="true">*</span></label>
                            <input type="text" id="vtName" name="name" maxlength="100" required value="{{ old('name') }}" placeholder="e.g. Sedan"
                                @error('name') aria-invalid="true" aria-describedby="vtNameError" @enderror>
                            @error('name')
                                <p class="tt-error" id="vtNameError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="tt-grid vt-field-gap">
                            <div class="tt-field @error('category') has-error @enderror">
                                <label for="vtCategory">Category <span class="tt-req" aria-hidden="true">*</span></label>
                                <div class="tt-select vt-field-select">
                                    <select id="vtCategory" name="category" required
                                        @error('category') aria-invalid="true" aria-describedby="vtCategoryError" @enderror>
                                        <option value="">Choose a category</option>
                                        @foreach ($vehicleCategories as $category)
                                            <option value="{{ $category->slug }}" @selected(old('category') === $category->slug)>{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                    <i data-lucide="chevron-down" aria-hidden="true"></i>
                                </div>
                                @error('category')
                                    <p class="tt-error" id="vtCategoryError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="tt-field @error('weight_kg') has-error @enderror">
                                <label for="vtWeight">Weight (kg) <span class="tt-optional">(optional)</span></label>
                                <input type="text" id="vtWeight" name="weight_kg" inputmode="decimal" value="{{ old('weight_kg') }}" placeholder="e.g. 1,300"
                                    @error('weight_kg') aria-invalid="true" aria-describedby="vtWeightError" @enderror>
                                @error('weight_kg')
                                    <p class="tt-error" id="vtWeightError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="tt-field @error('description') has-error @enderror">
                            <label for="vtDescription">Description <span class="tt-optional">(optional)</span></label>
                            <input type="text" id="vtDescription" name="description" maxlength="500" value="{{ old('description') }}" placeholder="e.g. Includes crossovers"
                                aria-describedby="vtDescriptionHelp @error('description') vtDescriptionError @enderror"
                                @error('description') aria-invalid="true" @enderror>
                            <p class="vt-hint" id="vtDescriptionHelp">Customers see this under the name when booking.</p>
                            @error('description')
                                <p class="tt-error" id="vtDescriptionError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                            @enderror
                        </div>
                    </section>

                    <section class="tt-section" aria-labelledby="vtSectionBooking">
                        <h3 id="vtSectionBooking">Booking</h3>
                        <div class="tt-field @error('required_truck_type_id') has-error @enderror">
                            <label for="vtTruck">Required truck type <span class="tt-req" aria-hidden="true">*</span></label>
                            <p class="tt-help" id="vtTruckHelp">Bookings for this vehicle use this truck type for pricing and truck matching.</p>
                            <div class="tt-select vt-field-select">
                                <select id="vtTruck" name="required_truck_type_id" required
                                    aria-describedby="vtTruckHelp @error('required_truck_type_id') vtTruckError @enderror"
                                    @error('required_truck_type_id') aria-invalid="true" @enderror>
                                    <option value="">Choose a truck type</option>
                                    @foreach ($truckTypes as $type)
                                        <option value="{{ $type->id }}" @selected($oldTruckId === $type->id)>{{ $truckLabel($type) }}</option>
                                    @endforeach
                                    @if ($extraTruck)
                                        <option value="{{ $extraTruck->id }}" selected data-extra>{{ $truckLabel($extraTruck) }} (inactive)</option>
                                    @endif
                                </select>
                                <i data-lucide="chevron-down" aria-hidden="true"></i>
                            </div>
                            @error('required_truck_type_id')
                                <p class="tt-error" id="vtTruckError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                            @enderror
                        </div>
                    </section>
                </form>

                {{-- Edit only: status and delete stay apart from the details form. --}}
                <section class="tt-section vt-manage" aria-labelledby="vtSectionStatus" data-edit-only @if (! $editVehicle) hidden @endif>
                    <h3 id="vtSectionStatus">Status</h3>
                    <div class="vt-manage-row">
                        <div>
                            <span class="tt-badge" data-status-badge></span>
                            <p class="vt-manage-text" data-status-text></p>
                        </div>
                        <button type="button" class="tt-btn tt-btn--sm" data-status-action></button>
                    </div>
                    <div class="vt-manage-row">
                        <div>
                            <p class="vt-manage-title">Delete vehicle type</p>
                            <p class="vt-manage-text" id="vtDeleteText" data-delete-text>Not possible once bookings use it.</p>
                        </div>
                        <button type="button" class="tt-btn tt-btn--sm vt-btn-danger-outline" data-delete-action aria-describedby="vtDeleteText">Delete</button>
                    </div>
                </section>
            </div>

            <footer class="tt-dialog-foot">
                <button type="button" class="tt-btn" data-close-modal>Cancel</button>
                <button type="submit" form="vehicleForm" class="tt-btn tt-btn--primary" data-submit-label>{{ $editVehicle ? 'Save' : 'Add' }}</button>
            </footer>
        </div>
    </div>

    {{-- Manage categories --}}
    <div class="tt-modal" id="categoriesModal" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog vt-dialog--categories" role="dialog" aria-modal="true" aria-labelledby="vtCategoriesTitle" aria-describedby="vtCategoriesDesc">
            <header class="tt-dialog-head">
                <div>
                    <h2 id="vtCategoriesTitle">Vehicle categories</h2>
                    <p id="vtCategoriesDesc">Categories are shared by all truck types.</p>
                </div>
                <button type="button" class="tt-icon-btn tt-dialog-close" data-close-modal aria-label="Close">
                    <i data-lucide="x" aria-hidden="true"></i>
                </button>
            </header>
            <div class="tt-dialog-body vt-categories-body">
                <ul class="vt-category-list" data-category-list>
                    @foreach ($vehicleCategories as $category)
                        <li class="vt-category-item" data-category-id="{{ $category->id }}">
                            <div class="vt-category-view">
                                <span class="vt-category-name" data-category-name>{{ $category->name }}</span>
                                <span class="vt-card-meta">{{ $category->vehicle_types_count }} {{ $category->vehicle_types_count === 1 ? 'vehicle type' : 'vehicle types' }}</span>
                                <button type="button" class="tt-btn tt-btn--sm" data-category-edit aria-label="Rename {{ $category->name }}">Edit</button>
                            </div>
                            <form class="vt-category-form" data-category-rename hidden novalidate>
                                <label class="tt-sr" for="vtCatRename{{ $category->id }}">New name for {{ $category->name }}</label>
                                <input type="text" id="vtCatRename{{ $category->id }}" name="name" maxlength="100" value="{{ $category->name }}" required
                                    aria-describedby="vtCatRename{{ $category->id }}Error">
                                <button type="button" class="tt-btn tt-btn--sm" data-category-cancel>Cancel</button>
                                <button type="submit" class="tt-btn tt-btn--sm tt-btn--primary">Save</button>
                                <p class="tt-error" id="vtCatRename{{ $category->id }}Error" data-category-error hidden></p>
                            </form>
                        </li>
                    @endforeach
                </ul>

                <form class="tt-field vt-new-category" data-category-add novalidate>
                    <label for="vtNewCategory">New category</label>
                    <div class="vt-category-form">
                        <input type="text" id="vtNewCategory" name="name" maxlength="100" placeholder="e.g. Off-road" required aria-describedby="vtNewCategoryError">
                        <button type="submit" class="tt-btn tt-btn--sm">Add</button>
                    </div>
                    <p class="tt-error" id="vtNewCategoryError" data-category-error hidden></p>
                </form>
                <p class="vt-hint">Categories can be renamed but not removed here, so existing vehicle types and bookings keep theirs.</p>
            </div>
            <footer class="tt-dialog-foot">
                <button type="button" class="tt-btn tt-btn--primary" data-categories-done>Done</button>
            </footer>
        </div>
    </div>

    {{-- Confirm enable / disable / delete --}}
    <div class="tt-modal" id="vehicleConfirm" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog tt-dialog--confirm" role="alertdialog" aria-modal="true" aria-labelledby="vtConfirmTitle" aria-describedby="vtConfirmText">
            <form method="POST" id="vehicleConfirmForm">
                @csrf
                <input type="hidden" name="_method" value="PATCH">
                <div class="tt-dialog-head">
                    <div>
                        <h2 id="vtConfirmTitle"></h2>
                        <p id="vtConfirmText"></p>
                    </div>
                </div>
                <footer class="tt-dialog-foot">
                    <button type="button" class="tt-btn" data-close-modal>Cancel</button>
                    <button type="submit" class="tt-btn tt-btn--primary" data-confirm-submit></button>
                </footer>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/vehicle-types.js') }}?v={{ filemtime(public_path('superadmin/js/vehicle-types.js')) }}" defer></script>
@endpush
