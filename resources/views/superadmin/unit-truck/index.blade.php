@extends('layouts.superadmin')

@section('title', 'Trucks')

@push('styles')
    {{-- Shared fleet page styles (header, table, menus, modals, form fields). --}}
    <link rel="stylesheet" href="{{ asset('superadmin/css/truck-types.css') }}?v={{ filemtime(public_path('superadmin/css/truck-types.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/trucks.css') }}?v={{ filemtime(public_path('superadmin/css/trucks.css')) }}">
@endpush

@php
    $formErrorCount = collect(['plate_number', 'truck_type_id'])->filter(fn ($field) => $errors->has($field))->count();
    $reopenModal = $formErrorCount > 0 ? old('_modal', 'add') : null;
    $editUnit = $reopenModal === 'edit' ? \App\Models\Unit::whereNull('archived_at')->find((int) old('_edit_id')) : null;
    $formAction = $editUnit ? route('superadmin.units.update', $editUnit->id) : route('superadmin.units.store');

    // A truck can keep a truck type that has since been disabled; keep it selectable when editing.
    $oldTypeId = (int) old('truck_type_id');
    $extraType = $oldTypeId && ! $truckTypes->contains('id', $oldTypeId) ? \App\Models\TruckType::find($oldTypeId) : null;

    $typeLabel = fn ($type) => $type->name . ($type->class ? ' · ' . ucfirst($type->class) . ' class' : '');
    $plateLabel = fn ($plate) => preg_replace('/^([A-Z]+)(\d+)$/', '$1 $2', strtoupper((string) $plate));
    $total = $trucks->total();
@endphp

@section('content')
    <div class="tt-page tk-page" id="trucksPage"
        data-index-url="{{ route('superadmin.unit-truck.index') }}"
        data-store-url="{{ route('superadmin.units.store') }}"
        data-update-url="{{ route('superadmin.units.update', '__ID__') }}"
        data-toggle-url="{{ route('superadmin.units.toggle', '__ID__') }}"
        data-archive-url="{{ route('superadmin.units.archive', '__ID__') }}"
        @if ($reopenModal) data-reopen="{{ $reopenModal }}" @endif>

        <header class="tt-header">
            <div>
                <h1>Trucks</h1>
                <p>Every truck in the fleet, its team and whether it can take a job.</p>
            </div>
            <div class="tk-header-actions">
                <a href="{{ route('superadmin.units.archived') }}" class="tt-btn tk-btn-lg">
                    <i data-lucide="archive" aria-hidden="true"></i>
                    Archived trucks ({{ number_format($archivedCount) }})
                </a>
                <button type="button" class="tt-btn tt-btn--primary" data-open-add>
                    <i data-lucide="plus" aria-hidden="true"></i>
                    Add truck
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

        <form class="tt-filters" id="truckFilters" method="GET" action="{{ route('superadmin.unit-truck.index') }}" role="search">
            <label class="tt-search">
                <span class="tt-sr">Search trucks</span>
                <i data-lucide="search" aria-hidden="true"></i>
                <input type="search" name="search" value="{{ $filters['search'] }}"
                    placeholder="Search unit name, plate or team leader" autocomplete="off">
            </label>

            <label class="tt-select tk-select">
                <span class="tt-sr">Status</span>
                <select name="status">
                    <option value="" @selected($filters['status'] === '')>All statuses ({{ $statusTotal }})</option>
                    @foreach ($readiness as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }} ({{ $statusCounts[$value] ?? 0 }})</option>
                    @endforeach
                </select>
                <i data-lucide="chevron-down" aria-hidden="true"></i>
            </label>

            <noscript><button type="submit" class="tt-btn">Apply</button></noscript>
        </form>

        <div class="tt-card" id="truckResults" aria-live="polite" data-total="{{ $total }}">
            {{-- Status counts for the filter menu, refreshed with each result swap. --}}
            <template data-status-options>
                <option value="">All statuses ({{ $statusTotal }})</option>
                @foreach ($readiness as $value => $label)
                    <option value="{{ $value }}">{{ $label }} ({{ $statusCounts[$value] ?? 0 }})</option>
                @endforeach
            </template>

            @if ($trucks->count() === 0)
                <div class="tt-state">
                    @if ($hasFilters)
                        <span class="tt-state-icon"><i data-lucide="search-x" aria-hidden="true"></i></span>
                        <p class="tt-state-title">No trucks match these filters</p>
                        <p class="tt-state-text">Try a different unit name, plate, team leader, or status.</p>
                        <a href="{{ route('superadmin.unit-truck.index') }}" class="tt-btn" data-clear-filters>Clear filters</a>
                    @else
                        <span class="tt-state-icon"><i data-lucide="truck" aria-hidden="true"></i></span>
                        <p class="tt-state-title">No trucks yet</p>
                        <p class="tt-state-text">Add a truck so dispatchers can assign a team to it.</p>
                        <button type="button" class="tt-btn tt-btn--primary" data-open-add>
                            <i data-lucide="plus" aria-hidden="true"></i>
                            Add truck
                        </button>
                    @endif
                </div>
            @else
                <div class="tt-table-wrap">
                    <table class="tt-table tk-table">
                        <caption class="tt-sr">Trucks, their teams, and dispatch readiness</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="tk-col-truck">Truck</th>
                                <th scope="col" class="tk-col-plate">Plate</th>
                                <th scope="col" class="tk-col-type">Truck type</th>
                                <th scope="col" class="tk-col-team">Team</th>
                                <th scope="col" class="tk-col-status">Status</th>
                                <th scope="col" class="tt-col-actions"><span class="tt-sr">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($trucks as $row)
                                @php
                                    $unit = $row['unit'];
                                    $type = $unit->truckType;
                                    $currentJob = in_array($row['state'], ['on_job', 'reserved'], true) ? $row['detail'] : '';
                                @endphp
                                <tr>
                                    <td class="tk-col-truck">
                                        <button type="button" class="tk-link" data-view="{{ $unit->id }}"
                                            aria-haspopup="dialog">{{ $unit->name }}</button>
                                    </td>
                                    <td class="tk-col-plate">{{ $plateLabel($unit->plate_number) }}</td>
                                    <td class="tk-col-type">
                                        @if ($type)
                                            <span class="tt-main">{{ $type->name }}</span>
                                            <span class="tt-sub">{{ $type->class ? ucfirst($type->class) . ' class' : 'Class not set' }}</span>
                                        @else
                                            <span class="tt-muted">Not set</span>
                                        @endif
                                    </td>
                                    <td class="tk-col-team">
                                        @if ($row['leader'])
                                            <span class="tt-main">{{ $row['leader'] }}</span>
                                        @else
                                            <span class="tt-main tk-warn">No team leader</span>
                                        @endif
                                        <span class="tt-sub">{{ $row['driver'] ? 'Driver: ' . $row['driver'] : 'No driver' }} · {{ count($row['crew']) ? 'Crew: ' . count($row['crew']) : 'No crew' }}</span>
                                    </td>
                                    <td class="tk-col-status">
                                        <span class="tt-badge tk-badge is-{{ $row['state'] }}">{{ $row['label'] }}</span>
                                        @if ($row['detail'])
                                            <span class="tk-reason">{{ $row['detail'] }}</span>
                                        @endif
                                        @if ($row['also'])
                                            <span class="tk-reason">{{ $row['also'] }}</span>
                                        @endif
                                    </td>
                                    <td class="tt-col-actions">
                                        <div class="tt-actions">
                                            <button type="button" class="tt-btn tt-btn--sm" data-edit
                                                data-id="{{ $unit->id }}"
                                                data-name="{{ $unit->name }}"
                                                data-plate="{{ $plateLabel($unit->plate_number) }}"
                                                data-type-id="{{ $unit->truck_type_id }}"
                                                data-type-label="{{ $type ? $typeLabel($type) . ($type->status !== 'active' ? ' (inactive)' : '') : '' }}"
                                                aria-label="Edit {{ $unit->name }}">Edit</button>

                                            <div class="tt-more">
                                                <button type="button" class="tt-icon-btn" data-menu-toggle
                                                    aria-haspopup="menu" aria-expanded="false"
                                                    aria-controls="tkMenu{{ $unit->id }}"
                                                    aria-label="More actions for {{ $unit->name }}">
                                                    <i data-lucide="ellipsis" aria-hidden="true"></i>
                                                </button>
                                                <div class="tt-menu" id="tkMenu{{ $unit->id }}" role="menu" hidden>
                                                    @if ($unit->status === 'maintenance')
                                                        <button type="button" role="menuitem" class="tt-menu-item"
                                                            data-action="service" data-id="{{ $unit->id }}" data-name="{{ $unit->name }}">
                                                            <i data-lucide="circle-check" aria-hidden="true"></i>
                                                            <span>Return to service</span>
                                                        </button>
                                                    @else
                                                        <button type="button" role="menuitem" class="tt-menu-item"
                                                            data-action="maintenance" data-id="{{ $unit->id }}" data-name="{{ $unit->name }}"
                                                            data-current="{{ $currentJob }}"
                                                            @if ($row['locked_reason']) aria-disabled="true" aria-describedby="tkMaintWhy{{ $unit->id }}" @endif>
                                                            <i data-lucide="wrench" aria-hidden="true"></i>
                                                            <span>Move to maintenance</span>
                                                        </button>
                                                        @if ($row['locked_reason'])
                                                            <p class="tt-menu-why" id="tkMaintWhy{{ $unit->id }}">{{ $row['locked_reason'] }}</p>
                                                        @endif
                                                    @endif

                                                    <button type="button" role="menuitem" class="tt-menu-item"
                                                        data-action="archive" data-id="{{ $unit->id }}" data-name="{{ $unit->name }}"
                                                        data-current="{{ $currentJob }}"
                                                        @if ($row['locked_reason']) aria-disabled="true" aria-describedby="tkArchiveWhy{{ $unit->id }}" @endif>
                                                        <i data-lucide="archive" aria-hidden="true"></i>
                                                        <span>Archive</span>
                                                    </button>
                                                    @if ($row['locked_reason'])
                                                        <p class="tt-menu-why" id="tkArchiveWhy{{ $unit->id }}">{{ $row['locked_reason'] }}</p>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Read-only details for the View dialog. --}}
                                        <template id="tkView{{ $unit->id }}" data-title="{{ $unit->name }}"
                                            data-subtitle="{{ $plateLabel($unit->plate_number) }}{{ $type ? ' · ' . $type->name : '' }}">
                                            <section class="tt-section">
                                                <h3>Status</h3>
                                                <span class="tt-badge tk-badge is-{{ $row['state'] }}">{{ $row['label'] }}</span>
                                                @if ($row['detail'])
                                                    <p class="tk-reason">{{ $row['detail'] }}</p>
                                                @endif
                                                @if ($row['also'])
                                                    <p class="tk-reason">{{ $row['also'] }}</p>
                                                @endif
                                            </section>
                                            <section class="tt-section">
                                                <h3>Truck</h3>
                                                <dl class="tk-facts">
                                                    <div><dt>Plate</dt><dd>{{ $plateLabel($unit->plate_number) }}</dd></div>
                                                    <div><dt>Truck type</dt><dd>{{ $type->name ?? 'Not set' }}</dd></div>
                                                    <div><dt>Class</dt><dd>{{ $type && $type->class ? ucfirst($type->class) : 'Not set' }}</dd></div>
                                                    @if ($unit->dispatcher_note)
                                                        <div><dt>Dispatcher note</dt><dd>{{ $unit->dispatcher_note }}</dd></div>
                                                    @endif
                                                </dl>
                                            </section>
                                            <section class="tt-section">
                                                <h3>Team</h3>
                                                <p class="tt-help">Dispatchers assign and change the team.</p>
                                                <dl class="tk-facts">
                                                    <div>
                                                        <dt>Team leader</dt>
                                                        <dd>
                                                            @if ($row['leader'])
                                                                {{ $row['leader'] }}
                                                                <span class="tk-fact-note">{{ $row['leader_inactive'] ? "Account inactive · can't sign in" : ($row['leader_duty'] === 'unavailable' ? 'Off duty' : 'On duty') }} · {{ $row['leader_presence'] === 'online' ? 'App online' : 'App offline' }}</span>
                                                            @else
                                                                <span class="tk-warn">No team leader</span>
                                                                @if ($row['leader_record'])
                                                                    <span class="tk-fact-note">{{ $row['leader_record'] }} is no longer an active team leader.</span>
                                                                @endif
                                                            @endif
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt>Driver</dt>
                                                        <dd>
                                                            @if ($row['driver'])
                                                                {{ $row['driver'] }}
                                                                <span class="tk-fact-note">{{ $row['driver_inactive'] ? 'Inactive' : ($row['driver_duty'] === 'unavailable' ? 'Off duty' : 'On duty') }}</span>
                                                            @else
                                                                <span class="tk-warn">No driver</span>
                                                            @endif
                                                        </dd>
                                                    </div>
                                                    @if ($row['driver_2'])
                                                        <div><dt>Second driver</dt><dd>{{ $row['driver_2'] }}</dd></div>
                                                    @endif
                                                    <div>
                                                        <dt>Crew</dt>
                                                        <dd>{{ count($row['crew']) ? implode(', ', $row['crew']) : 'No crew' }}</dd>
                                                    </div>
                                                    @if ($row['unlinked'])
                                                        <div>
                                                            <dt>Unlinked</dt>
                                                            <dd>
                                                                {{ implode(', ', $row['unlinked']) }}
                                                                <span class="tk-fact-note">Not linked to a personnel record. Kept for history; dispatchers can't assign or borrow these names.</span>
                                                            </dd>
                                                        </div>
                                                    @endif
                                                </dl>
                                            </section>
                                        </template>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <footer class="tt-footer">
                    <p class="tt-count">Showing {{ number_format($trucks->firstItem()) }}–{{ number_format($trucks->lastItem()) }} of {{ number_format($total) }} {{ $total === 1 ? 'truck' : 'trucks' }}</p>

                    @php
                        $current = $trucks->currentPage();
                        $last = $trucks->lastPage();
                        $pages = collect([1, $current - 1, $current, $current + 1, $last])
                            ->filter(fn ($p) => $p >= 1 && $p <= $last)
                            ->unique()
                            ->sort()
                            ->values();
                    @endphp
                    <nav class="tt-pages" aria-label="Pagination">
                        @if ($trucks->onFirstPage())
                            <span class="tt-page-btn is-disabled" aria-hidden="true"><i data-lucide="chevron-left"></i></span>
                        @else
                            <a class="tt-page-btn" href="{{ $trucks->previousPageUrl() }}" data-page-link aria-label="Previous page"><i data-lucide="chevron-left" aria-hidden="true"></i></a>
                        @endif

                        @foreach ($pages as $i => $page)
                            @if ($i > 0 && $page - $pages[$i - 1] > 1)
                                <span class="tt-page-gap" aria-hidden="true">…</span>
                            @endif
                            @if ($page === $current)
                                <span class="tt-page-btn is-current" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="tt-page-btn" href="{{ $trucks->url($page) }}" data-page-link aria-label="Page {{ $page }}">{{ $page }}</a>
                            @endif
                        @endforeach

                        @if ($trucks->hasMorePages())
                            <a class="tt-page-btn" href="{{ $trucks->nextPageUrl() }}" data-page-link aria-label="Next page"><i data-lucide="chevron-right" aria-hidden="true"></i></a>
                        @else
                            <span class="tt-page-btn is-disabled" aria-hidden="true"><i data-lucide="chevron-right"></i></span>
                        @endif
                    </nav>
                </footer>
            @endif
        </div>
    </div>

    {{-- Add / edit truck --}}
    <div class="tt-modal" id="truckModal" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog tk-dialog--form" role="dialog" aria-modal="true" aria-labelledby="truckModalTitle" aria-describedby="truckModalDesc">
            <form method="POST" action="{{ $formAction }}" id="truckForm" novalidate>
                @csrf
                <input type="hidden" name="_method" value="PUT" @disabled(! $editUnit)>
                <input type="hidden" name="_modal" value="{{ $editUnit ? 'edit' : 'add' }}">
                <input type="hidden" name="_edit_id" value="{{ $editUnit?->id }}">

                <header class="tt-dialog-head">
                    <div>
                        <h2 id="truckModalTitle">{{ $editUnit ? 'Edit truck' : 'Add truck' }}</h2>
                        <p id="truckModalDesc">{{ $editUnit ? $editUnit->name : 'The unit name is assigned automatically.' }}</p>
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

                    <div class="tt-field tk-name-field" data-unit-name-field @if ($editUnit) hidden @endif>
                        <span class="tk-label" id="tkUnitNameLabel">Unit name</span>
                        <div class="tk-readonly" role="textbox" aria-readonly="true" aria-labelledby="tkUnitNameLabel">{{ $nextUnitName }}</div>
                    </div>

                    <div class="tt-grid">
                        <div class="tt-field @error('plate_number') has-error @enderror">
                            <label for="tkPlate">Plate number <span class="tt-req" aria-hidden="true">*</span></label>
                            <input type="text" id="tkPlate" name="plate_number" maxlength="10" required autocomplete="off"
                                value="{{ old('plate_number') }}" placeholder="e.g. ABC 1234"
                                aria-describedby="tkPlateHelp @error('plate_number') tkPlateError @enderror"
                                @error('plate_number') aria-invalid="true" @enderror>
                            @error('plate_number')
                                <p class="tt-error" id="tkPlateError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                            @enderror
                            <p class="tk-hint" id="tkPlateHelp">3 letters and 3–4 digits, or 2 letters and 5 digits.</p>
                        </div>

                        <div class="tt-field @error('truck_type_id') has-error @enderror">
                            <label for="tkType">Truck type <span class="tt-req" aria-hidden="true">*</span></label>
                            <div class="tt-select tk-field-select">
                                <select id="tkType" name="truck_type_id" required
                                    @error('truck_type_id') aria-invalid="true" aria-describedby="tkTypeError" @enderror>
                                    <option value="">Choose a truck type</option>
                                    @foreach ($truckTypes as $type)
                                        <option value="{{ $type->id }}" @selected($oldTypeId === $type->id)>{{ $typeLabel($type) }}</option>
                                    @endforeach
                                    @if ($extraType)
                                        <option value="{{ $extraType->id }}" selected data-extra>{{ $typeLabel($extraType) }} (inactive)</option>
                                    @endif
                                </select>
                                <i data-lucide="chevron-down" aria-hidden="true"></i>
                            </div>
                            @error('truck_type_id')
                                <p class="tt-error" id="tkTypeError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <footer class="tt-dialog-foot">
                    <button type="button" class="tt-btn" data-close-modal>Cancel</button>
                    <button type="submit" class="tt-btn tt-btn--primary" data-submit-label>{{ $editUnit ? 'Save' : 'Add' }}</button>
                </footer>
            </form>
        </div>
    </div>

    {{-- View truck (read-only) --}}
    <div class="tt-modal" id="truckView" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog tk-dialog--view" role="dialog" aria-modal="true" aria-labelledby="tkViewTitle" aria-describedby="tkViewSubtitle">
            <header class="tt-dialog-head">
                <div>
                    <h2 id="tkViewTitle"></h2>
                    <p id="tkViewSubtitle"></p>
                </div>
                <button type="button" class="tt-icon-btn tt-dialog-close" data-close-modal aria-label="Close">
                    <i data-lucide="x" aria-hidden="true"></i>
                </button>
            </header>
            <div class="tt-dialog-body" data-view-body></div>
            <footer class="tt-dialog-foot">
                <button type="button" class="tt-btn" data-close-modal>Close</button>
                <button type="button" class="tt-btn tt-btn--primary" data-view-edit>Edit</button>
            </footer>
        </div>
    </div>

    {{-- Confirm maintenance / archive --}}
    <div class="tt-modal" id="truckConfirm" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog tt-dialog--confirm" role="alertdialog" aria-modal="true" aria-labelledby="tkConfirmTitle" aria-describedby="tkConfirmText">
            <form method="POST" id="truckConfirmForm">
                @csrf
                <input type="hidden" name="_method" value="PATCH">
                <div class="tt-dialog-head">
                    <div>
                        <h2 id="tkConfirmTitle"></h2>
                        <p id="tkConfirmText"></p>
                    </div>
                </div>
                <footer class="tt-dialog-foot">
                    <button type="button" class="tt-btn" data-close-modal>Cancel</button>
                    <button type="submit" class="tt-btn tt-btn--primary" data-confirm-submit></button>
                </footer>
            </form>
        </div>
    </div>

    {{-- Explains why an action is unavailable --}}
    <div class="tt-modal" id="truckBlocked" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog tt-dialog--confirm" role="alertdialog" aria-modal="true" aria-labelledby="tkBlockedTitle" aria-describedby="tkBlockedText">
            <div class="tt-dialog-head">
                <div>
                    <h2 id="tkBlockedTitle"></h2>
                    <p id="tkBlockedText"></p>
                </div>
            </div>
            <footer class="tt-dialog-foot">
                <button type="button" class="tt-btn tt-btn--primary" data-close-modal>OK</button>
            </footer>
        </div>
    </div>

    {{-- Return to service needs no confirmation: it only makes the truck dispatchable again. --}}
    <form method="POST" id="truckServiceForm" hidden>
        @csrf
        @method('PATCH')
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/trucks.js') }}?v={{ filemtime(public_path('superadmin/js/trucks.js')) }}" defer></script>
@endpush
