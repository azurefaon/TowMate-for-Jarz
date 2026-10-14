@extends('layouts.superadmin')

@section('title', 'Truck Types & Rates')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/truck-types.css') }}?v={{ filemtime(public_path('superadmin/css/truck-types.css')) }}">
@endpush

@php
    $formFields = ['name', 'class', 'base_rate', 'per_km_rate', 'max_tonnage', 'description'];
    $formErrorCount = collect($formFields)->filter(fn ($field) => $errors->has($field))->count();
    $reopenModal = $formErrorCount > 0 ? old('_modal', 'add') : null;
    $editId = $reopenModal === 'edit' ? (int) old('_edit_id') : null;
    $editType = $editId ? \App\Models\TruckType::find($editId) : null;
    $formAction = $editType ? route('superadmin.truck-types.update', $editType->id) : route('superadmin.truck-types.store');
    $classOptional = $editType !== null && $editType->class === null;
    $total = $truckTypes->total();
    $money = fn ($value) => '₱' . number_format((float) $value, 2);
@endphp

@section('content')
    <div class="tt-page" id="truckTypesPage"
        data-index-url="{{ route('superadmin.truck-types.index') }}"
        data-store-url="{{ route('superadmin.truck-types.store') }}"
        data-update-url="{{ route('superadmin.truck-types.update', '__ID__') }}"
        data-toggle-url="{{ route('superadmin.truck-types.toggle', '__ID__') }}"
        data-destroy-url="{{ route('superadmin.truck-types.destroy', '__ID__') }}"
        @if ($reopenModal) data-reopen="{{ $reopenModal }}" @endif>

        <header class="tt-header">
            <div>
                <h1>Truck Types &amp; Rates</h1>
                <p>Rates and weight limits used to price bookings.</p>
            </div>
            <button type="button" class="tt-btn tt-btn--primary" data-open-add>
                <i data-lucide="plus" aria-hidden="true"></i>
                Add truck type
            </button>
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

        <form class="tt-filters" id="truckTypeFilters" method="GET" action="{{ route('superadmin.truck-types.index') }}" role="search">
            <label class="tt-search">
                <span class="tt-sr">Search truck types</span>
                <i data-lucide="search" aria-hidden="true"></i>
                <input type="search" name="search" value="{{ $filters['search'] }}"
                    placeholder="Search truck type or common use" autocomplete="off">
            </label>

            <label class="tt-select">
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

        <div class="tt-card" id="truckTypeResults" aria-live="polite" data-total="{{ $total }}">
            @if ($truckTypes->count() === 0)
                <div class="tt-state">
                    @if ($hasFilters)
                        <span class="tt-state-icon"><i data-lucide="search-x" aria-hidden="true"></i></span>
                        <p class="tt-state-title">No truck types match these filters</p>
                        <p class="tt-state-text">Try a different name, common use, or status.</p>
                        <a href="{{ route('superadmin.truck-types.index') }}" class="tt-btn" data-clear-filters>Clear filters</a>
                    @else
                        <span class="tt-state-icon"><i data-lucide="truck" aria-hidden="true"></i></span>
                        <p class="tt-state-title">No truck types yet</p>
                        <p class="tt-state-text">Add a truck type to set the rates used to price bookings.</p>
                        <button type="button" class="tt-btn tt-btn--primary" data-open-add>
                            <i data-lucide="plus" aria-hidden="true"></i>
                            Add truck type
                        </button>
                    @endif
                </div>
            @else
                <div class="tt-table-wrap">
                    <table class="tt-table">
                        <caption class="tt-sr">Truck types and rates</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="tt-col-name">Truck type</th>
                                <th scope="col" class="tt-col-class">Class</th>
                                <th scope="col" class="tt-num">Base rate</th>
                                <th scope="col" class="tt-num">Rate per km</th>
                                <th scope="col" class="tt-num">Max weight</th>
                                <th scope="col" class="tt-num tt-col-trucks">Trucks</th>
                                <th scope="col" class="tt-col-status">Status</th>
                                <th scope="col" class="tt-col-actions"><span class="tt-sr">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($truckTypes as $type)
                                @php
                                    $isActive = $type->status === 'active';
                                    $classLabel = $classes[$type->class]['label'] ?? null;
                                    $toggleBlockers = $isActive ? $type->disable_blockers : [];
                                @endphp
                                <tr>
                                    <td class="tt-col-name">
                                        <span class="tt-main">{{ $type->name }}</span>
                                        @if ($type->description)
                                            <span class="tt-sub">{{ $type->description }}</span>
                                        @else
                                            <span class="tt-sub">No common uses listed</span>
                                        @endif
                                    </td>
                                    <td class="tt-col-class">
                                        @if ($classLabel)
                                            {{ $classLabel }}
                                        @else
                                            <span class="tt-muted">Not set</span>
                                        @endif
                                    </td>
                                    <td class="tt-num">{{ $money($type->base_rate) }}</td>
                                    <td class="tt-num">{{ $money($type->per_km_rate) }}</td>
                                    <td class="tt-num">
                                        @if ($type->max_tonnage !== null && (float) $type->max_tonnage > 0)
                                            {{ number_format((float) $type->max_tonnage) }} kg
                                        @else
                                            <span class="tt-muted" aria-label="Not set">—</span>
                                        @endif
                                    </td>
                                    <td class="tt-num tt-col-trucks">{{ number_format($type->trucks_count) }}</td>
                                    <td class="tt-col-status">
                                        <span class="tt-badge {{ $isActive ? 'is-active' : 'is-inactive' }}">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td class="tt-col-actions">
                                        <div class="tt-actions">
                                            <button type="button" class="tt-btn tt-btn--sm" data-edit
                                                data-id="{{ $type->id }}"
                                                data-name="{{ $type->name }}"
                                                data-class="{{ $type->class }}"
                                                data-base="{{ $type->base_rate }}"
                                                data-km="{{ $type->per_km_rate }}"
                                                data-weight="{{ $type->max_tonnage !== null ? (float) $type->max_tonnage : '' }}"
                                                data-description="{{ $type->description }}"
                                                aria-label="Edit {{ $type->name }}">Edit</button>

                                            <div class="tt-more">
                                                <button type="button" class="tt-icon-btn" data-menu-toggle
                                                    aria-haspopup="menu" aria-expanded="false"
                                                    aria-controls="ttMenu{{ $type->id }}"
                                                    aria-label="More actions for {{ $type->name }}">
                                                    <i data-lucide="ellipsis" aria-hidden="true"></i>
                                                </button>
                                                <div class="tt-menu" id="ttMenu{{ $type->id }}" role="menu" hidden>
                                                    @if ($isActive)
                                                        <button type="button" role="menuitem" class="tt-menu-item"
                                                            data-action="disable" data-id="{{ $type->id }}" data-name="{{ $type->name }}"
                                                            @if ($toggleBlockers) aria-disabled="true" aria-describedby="ttDisableWhy{{ $type->id }}" @endif>
                                                            <i data-lucide="circle-pause" aria-hidden="true"></i>
                                                            <span>Disable</span>
                                                        </button>
                                                        @if ($toggleBlockers)
                                                            <p class="tt-menu-why" id="ttDisableWhy{{ $type->id }}" data-guidance="{{ $type->disable_guidance }}">In use by {{ collect($toggleBlockers)->join(', ', ' and ') }}.</p>
                                                        @endif
                                                    @else
                                                        <button type="button" role="menuitem" class="tt-menu-item"
                                                            data-action="enable" data-id="{{ $type->id }}" data-name="{{ $type->name }}">
                                                            <i data-lucide="circle-play" aria-hidden="true"></i>
                                                            <span>Enable</span>
                                                        </button>
                                                    @endif

                                                    <button type="button" role="menuitem" class="tt-menu-item is-danger"
                                                        data-action="delete" data-id="{{ $type->id }}" data-name="{{ $type->name }}"
                                                        @if ($type->delete_blockers) aria-disabled="true" aria-describedby="ttDeleteWhy{{ $type->id }}" @endif>
                                                        <i data-lucide="trash-2" aria-hidden="true"></i>
                                                        <span>Delete</span>
                                                    </button>
                                                    @if ($type->delete_blockers)
                                                        <p class="tt-menu-why" id="ttDeleteWhy{{ $type->id }}">Linked to {{ collect($type->delete_blockers)->join(', ', ' and ') }}.</p>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <footer class="tt-footer">
                    <p class="tt-count">Showing {{ number_format($truckTypes->firstItem()) }}–{{ number_format($truckTypes->lastItem()) }} of {{ number_format($total) }} {{ $total === 1 ? 'truck type' : 'truck types' }}</p>

                    @php
                        $current = $truckTypes->currentPage();
                        $last = $truckTypes->lastPage();
                        $pages = collect([1, $current - 1, $current, $current + 1, $last])
                            ->filter(fn ($p) => $p >= 1 && $p <= $last)
                            ->unique()
                            ->sort()
                            ->values();
                    @endphp
                    <nav class="tt-pages" aria-label="Pagination">
                        @if ($truckTypes->onFirstPage())
                            <span class="tt-page-btn is-disabled" aria-hidden="true"><i data-lucide="chevron-left"></i></span>
                        @else
                            <a class="tt-page-btn" href="{{ $truckTypes->previousPageUrl() }}" data-page-link aria-label="Previous page"><i data-lucide="chevron-left" aria-hidden="true"></i></a>
                        @endif

                        @foreach ($pages as $i => $page)
                            @if ($i > 0 && $page - $pages[$i - 1] > 1)
                                <span class="tt-page-gap" aria-hidden="true">…</span>
                            @endif
                            @if ($page === $current)
                                <span class="tt-page-btn is-current" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="tt-page-btn" href="{{ $truckTypes->url($page) }}" data-page-link aria-label="Page {{ $page }}">{{ $page }}</a>
                            @endif
                        @endforeach

                        @if ($truckTypes->hasMorePages())
                            <a class="tt-page-btn" href="{{ $truckTypes->nextPageUrl() }}" data-page-link aria-label="Next page"><i data-lucide="chevron-right" aria-hidden="true"></i></a>
                        @else
                            <span class="tt-page-btn is-disabled" aria-hidden="true"><i data-lucide="chevron-right"></i></span>
                        @endif
                    </nav>
                </footer>
            @endif
        </div>
    </div>

    {{-- Add / edit truck type --}}
    <div class="tt-modal" id="truckTypeModal" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog tt-dialog--form" role="dialog" aria-modal="true" aria-labelledby="truckTypeModalTitle" aria-describedby="truckTypeModalDesc">
            <form method="POST" action="{{ $formAction }}" id="truckTypeForm" novalidate>
                @csrf
                <input type="hidden" name="_method" value="PUT" @disabled(! $editType)>
                <input type="hidden" name="_modal" value="{{ $editType ? 'edit' : 'add' }}">
                <input type="hidden" name="_edit_id" value="{{ $editType?->id }}">

                <header class="tt-dialog-head">
                    <div>
                        <h2 id="truckTypeModalTitle">{{ $editType ? 'Edit truck type' : 'Add truck type' }}</h2>
                        <p id="truckTypeModalDesc">New rates apply to quotations made after you save. Accepted bookings keep their price.</p>
                    </div>
                    <button type="button" class="tt-icon-btn tt-dialog-close" data-close-modal aria-label="Close">
                        <i data-lucide="x" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="tt-dialog-body">
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

                    <section class="tt-section" aria-labelledby="ttSectionType">
                        <h3 id="ttSectionType">Truck type</h3>

                        <div class="tt-field @error('name') has-error @enderror">
                            <label for="ttName">Name <span class="tt-req" aria-hidden="true">*</span></label>
                            <input type="text" id="ttName" name="name" maxlength="100" required
                                value="{{ old('name') }}" placeholder="e.g. Light Duty"
                                @error('name') aria-invalid="true" aria-describedby="ttNameError" @enderror>
                            @error('name')
                                <p class="tt-error" id="ttNameError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                            @enderror
                        </div>

                        <fieldset class="tt-field @error('class') has-error @enderror"
                            aria-describedby="ttClassHelp @error('class') ttClassError @enderror">
                            <legend>Truck class <span class="tt-req" aria-hidden="true" data-class-req @if ($classOptional) hidden @endif>*</span><span class="tt-optional" data-class-optional @if (! $classOptional) hidden @endif>(optional)</span></legend>
                            <p class="tt-help" id="ttClassHelp">
                                Decides which vehicle weights this truck type can tow.
                                <span data-class-note @if (! $classOptional) hidden @endif>This older truck type has no class yet. Once set, it can be changed but not cleared.</span>
                            </p>
                            <div class="tt-classes">
                                @foreach ($classes as $value => $class)
                                    <label class="tt-class">
                                        <input type="radio" name="class" value="{{ $value }}" @checked(old('class') === $value)>
                                        <span class="tt-class-name">{{ $class['label'] }}</span>
                                        <span class="tt-class-range">{{ $class['range'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('class')
                                <p class="tt-error" id="ttClassError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                            @enderror
                        </fieldset>

                        <div class="tt-field @error('description') has-error @enderror">
                            <label for="ttDescription">Common uses <span class="tt-optional">(optional)</span></label>
                            <input type="text" id="ttDescription" name="description" maxlength="255"
                                value="{{ old('description') }}" placeholder="e.g. Sedans, SUVs, vans"
                                @error('description') aria-invalid="true" aria-describedby="ttDescriptionError" @enderror>
                            @error('description')
                                <p class="tt-error" id="ttDescriptionError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                            @enderror
                        </div>
                    </section>

                    <section class="tt-section" aria-labelledby="ttSectionRates">
                        <h3 id="ttSectionRates">Rates</h3>
                        <div class="tt-grid">
                            @foreach (['base_rate' => ['ttBase', 'Base rate', '1,500.00'], 'per_km_rate' => ['ttKm', 'Rate per km', '200.00']] as $field => [$id, $label, $placeholder])
                                <div class="tt-field @error($field) has-error @enderror">
                                    <label for="{{ $id }}">{{ $label }} <span class="tt-req" aria-hidden="true">*</span></label>
                                    <div class="tt-money">
                                        <span aria-hidden="true">₱</span>
                                        <input type="text" id="{{ $id }}" name="{{ $field }}" inputmode="decimal" required
                                            value="{{ old($field) }}" placeholder="{{ $placeholder }}"
                                            @error($field) aria-invalid="true" aria-describedby="{{ $id }}Error" @enderror>
                                    </div>
                                    @error($field)
                                        <p class="tt-error" id="{{ $id }}Error"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section class="tt-section" aria-labelledby="ttSectionCapacity">
                        <h3 id="ttSectionCapacity">Capacity</h3>
                        <div class="tt-grid">
                            <div class="tt-field @error('max_tonnage') has-error @enderror">
                                <label for="ttWeight">Max weight (kg) <span class="tt-optional">(optional)</span></label>
                                <input type="text" id="ttWeight" name="max_tonnage" inputmode="decimal"
                                    value="{{ old('max_tonnage') }}" placeholder="4,500"
                                    @error('max_tonnage') aria-invalid="true" aria-describedby="ttWeightError" @enderror>
                                @error('max_tonnage')
                                    <p class="tt-error" id="ttWeightError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </section>
                </div>

                <footer class="tt-dialog-foot">
                    <button type="button" class="tt-btn" data-close-modal>Cancel</button>
                    <button type="submit" class="tt-btn tt-btn--primary">Save</button>
                </footer>
            </form>
        </div>
    </div>

    {{-- Confirm enable / disable / delete --}}
    <div class="tt-modal" id="truckTypeConfirm" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog tt-dialog--confirm" role="alertdialog" aria-modal="true" aria-labelledby="ttConfirmTitle" aria-describedby="ttConfirmText">
            <form method="POST" id="truckTypeConfirmForm">
                @csrf
                <input type="hidden" name="_method" value="PATCH">
                <div class="tt-dialog-head">
                    <div>
                        <h2 id="ttConfirmTitle"></h2>
                        <p id="ttConfirmText"></p>
                    </div>
                </div>
                <footer class="tt-dialog-foot">
                    <button type="button" class="tt-btn" data-close-modal>Cancel</button>
                    <button type="submit" class="tt-btn" data-confirm-submit></button>
                </footer>
            </form>
        </div>
    </div>

    {{-- Explains why an action is unavailable --}}
    <div class="tt-modal" id="truckTypeBlocked" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog tt-dialog--confirm" role="alertdialog" aria-modal="true" aria-labelledby="ttBlockedTitle" aria-describedby="ttBlockedText">
            <div class="tt-dialog-head">
                <div>
                    <h2 id="ttBlockedTitle"></h2>
                    <p id="ttBlockedText"></p>
                </div>
            </div>
            <footer class="tt-dialog-foot">
                <button type="button" class="tt-btn tt-btn--primary" data-close-modal>OK</button>
            </footer>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/truck-types.js') }}?v={{ filemtime(public_path('superadmin/js/truck-types.js')) }}" defer></script>
@endpush
