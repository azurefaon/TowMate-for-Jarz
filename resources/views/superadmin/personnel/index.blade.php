@extends('layouts.superadmin')

@section('title', 'Personnel')

@push('styles')
    {{-- Shared fleet page styles (header, table, modals, form fields). --}}
    <link rel="stylesheet" href="{{ asset('superadmin/css/truck-types.css') }}?v={{ filemtime(public_path('superadmin/css/truck-types.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/personnel.css') }}?v={{ filemtime(public_path('superadmin/css/personnel.css')) }}">
@endpush

@php
    $formFields = ['first_name', 'middle_name', 'last_name', 'role', 'home_unit_id'];
    $formErrorCount = collect($formFields)->filter(fn ($field) => $errors->has($field))->count();
    // add | record | account — which dialog to reopen after a failed save.
    $reopenModal = $formErrorCount > 0 ? old('_modal', 'add') : null;
    $editId = (int) old('_edit_id');
    $editRecord = $reopenModal === 'record' ? \App\Models\Personnel::find($editId) : null;
    $editAccount = $reopenModal === 'account' ? \App\Models\User::whereIn('role_id', \App\Services\PersonnelService::ACCOUNT_ROLE_IDS)->find($editId) : null;
    $total = $personnel->total();
    $error = fn ($field) => $errors->first($field);
@endphp

@section('content')
    <div class="tt-page pp-page" id="personnelPage"
        data-index-url="{{ route('superadmin.personnel.index') }}"
        data-store-url="{{ route('superadmin.personnel-records.store') }}"
        @if ($reopenModal) data-reopen="{{ $reopenModal }}" data-reopen-id="{{ $editId ?: '' }}" @endif>

        <header class="tt-header">
            <div>
                <h1>Personnel</h1>
                <p>Home unit is where each person normally works. Current unit is where they are today.</p>
            </div>
            <button type="button" class="tt-btn tt-btn--primary" data-open-add aria-haspopup="dialog">
                <i data-lucide="plus" aria-hidden="true"></i>
                Add driver or crew member
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

        <form class="tt-filters" id="personnelFilters" method="GET" action="{{ route('superadmin.personnel.index') }}" role="search">
            <label class="tt-search">
                <span class="tt-sr">Search personnel</span>
                <i data-lucide="search" aria-hidden="true"></i>
                <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search name or ID" autocomplete="off">
            </label>

            <label class="tt-select pp-select">
                <span class="tt-sr">Role</span>
                <select name="role">
                    <option value="">All roles</option>
                    @foreach ($roleFilters as $value => $label)
                        <option value="{{ $value }}" @selected($filters['role'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <i data-lucide="chevron-down" aria-hidden="true"></i>
            </label>

            <label class="tt-select pp-select">
                <span class="tt-sr">Status</span>
                <select name="status">
                    <option value="">All statuses</option>
                    @foreach ($statusFilters as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <i data-lucide="chevron-down" aria-hidden="true"></i>
            </label>

            <noscript><button type="submit" class="tt-btn">Apply</button></noscript>
        </form>

        <div class="tt-card" id="personnelResults" aria-live="polite">
            @if ($personnel->count() === 0)
                <div class="tt-state">
                    @if ($hasFilters)
                        <span class="tt-state-icon"><i data-lucide="search-x" aria-hidden="true"></i></span>
                        <p class="tt-state-title">No personnel match these filters</p>
                        <p class="tt-state-text">Try a different name, ID, role, or status.</p>
                        <a href="{{ route('superadmin.personnel.index') }}" class="tt-btn" data-clear-filters>Clear filters</a>
                    @else
                        <span class="tt-state-icon"><i data-lucide="users" aria-hidden="true"></i></span>
                        <p class="tt-state-title">No personnel yet</p>
                        <p class="tt-state-text">Team leaders and drivers with app accounts appear here automatically. Add drivers and crew members without accounts yourself.</p>
                        <button type="button" class="tt-btn tt-btn--primary" data-open-add>
                            <i data-lucide="plus" aria-hidden="true"></i>
                            Add driver or crew member
                        </button>
                    @endif
                </div>
            @else
                <div class="tt-table-wrap">
                    <table class="tt-table pp-table">
                        <caption class="tt-sr">Personnel, their home and current units</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="pp-col-name">Name</th>
                                <th scope="col">Role</th>
                                <th scope="col">Home unit</th>
                                <th scope="col" class="pp-col-current">Current unit</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="tt-col-actions"><span class="tt-sr">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($personnel as $row)
                                @php
                                    $isAccount = $row['type'] === 'account';
                                    $home = $row['home_unit'];
                                    $current = $row['current_unit'];
                                    $isActive = $row['status'] === 'Active';
                                    $model = $row['model'];
                                @endphp
                                <tr>
                                    <td class="pp-col-name">
                                        <span class="tt-main">{{ $row['full_name'] }}</span>
                                        <span class="tt-sub">
                                            @if ($isAccount)
                                                App account{{ $row['reference'] ? ' · ID ' . $row['reference'] : '' }}
                                            @else
                                                No app account
                                            @endif
                                        </span>
                                    </td>
                                    <td>{{ $row['role_label'] }}</td>
                                    <td>
                                        @if ($home)
                                            {{ $home->name }}
                                        @else
                                            <span class="tt-muted">Not set</span>
                                        @endif
                                    </td>
                                    <td class="pp-col-current">
                                        @if (! $current)
                                            <span class="tt-muted">Not on a unit</span>
                                        @elseif ($home && (int) $home->id === (int) $current->id)
                                            <span class="tt-muted">Same as home unit</span>
                                        @else
                                            <span class="tt-main">{{ $current->name }}</span>
                                            @if ($row['borrowed'])
                                                <span class="tt-sub">Borrowed from {{ $home->name }}</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td>
                                        <span class="tt-badge {{ $isActive ? 'is-active' : 'is-inactive' }}">{{ $row['status'] }}</span>
                                    </td>
                                    <td class="tt-col-actions">
                                        <button type="button" class="tt-btn tt-btn--sm" data-edit aria-haspopup="dialog"
                                            aria-label="Edit {{ $row['full_name'] }}"
                                            data-type="{{ $isAccount ? 'account' : 'record' }}"
                                            data-id="{{ $row['id'] }}"
                                            data-name="{{ $row['full_name'] }}"
                                            data-role-label="{{ $row['role_label'] }}"
                                            data-reference="{{ $row['reference'] }}"
                                            data-team-leader="{{ $isAccount && $row['role_key'] === 'team_leader' ? '1' : '' }}"
                                            data-first-name="{{ $model->first_name }}"
                                            data-middle-name="{{ $model->middle_name }}"
                                            data-last-name="{{ $model->last_name }}"
                                            data-role="{{ $isAccount ? '' : $model->role }}"
                                            data-home-unit-id="{{ $home->id ?? '' }}"
                                            data-current-unit-id="{{ $current->id ?? '' }}"
                                            data-current-unit-name="{{ $current->name ?? '' }}"
                                            data-status="{{ $row['status'] }}"
                                            data-action-url="{{ $isAccount ? route('superadmin.personnel.home-unit', $row['id']) : route('superadmin.personnel-records.update', $row['id']) }}"
                                            @unless ($isAccount) data-toggle-url="{{ route('superadmin.personnel-records.toggle', $row['id']) }}" @endunless>Edit</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <footer class="tt-footer">
                    <p class="tt-count">Showing {{ number_format($personnel->firstItem()) }}–{{ number_format($personnel->lastItem()) }} of {{ number_format($total) }} personnel</p>

                    @php
                        $currentPage = $personnel->currentPage();
                        $last = $personnel->lastPage();
                        $pages = collect([1, $currentPage - 1, $currentPage, $currentPage + 1, $last])
                            ->filter(fn ($p) => $p >= 1 && $p <= $last)
                            ->unique()
                            ->sort()
                            ->values();
                    @endphp
                    <nav class="tt-pages" aria-label="Pagination">
                        @if ($personnel->onFirstPage())
                            <span class="tt-page-btn is-disabled" aria-hidden="true"><i data-lucide="chevron-left"></i></span>
                        @else
                            <a class="tt-page-btn" href="{{ $personnel->previousPageUrl() }}" data-page-link aria-label="Previous page"><i data-lucide="chevron-left" aria-hidden="true"></i></a>
                        @endif

                        @foreach ($pages as $i => $page)
                            @if ($i > 0 && $page - $pages[$i - 1] > 1)
                                <span class="tt-page-gap" aria-hidden="true">…</span>
                            @endif
                            @if ($page === $currentPage)
                                <span class="tt-page-btn is-current" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="tt-page-btn" href="{{ $personnel->url($page) }}" data-page-link aria-label="Page {{ $page }}">{{ $page }}</a>
                            @endif
                        @endforeach

                        @if ($personnel->hasMorePages())
                            <a class="tt-page-btn" href="{{ $personnel->nextPageUrl() }}" data-page-link aria-label="Next page"><i data-lucide="chevron-right" aria-hidden="true"></i></a>
                        @else
                            <span class="tt-page-btn is-disabled" aria-hidden="true"><i data-lucide="chevron-right"></i></span>
                        @endif
                    </nav>
                </footer>
            @endif
        </div>
    </div>

    {{-- Add / edit a driver or crew member without an app account --}}
    <div class="tt-modal" id="recordModal" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog pp-dialog" role="dialog" aria-modal="true" aria-labelledby="ppRecordTitle" aria-describedby="ppRecordDesc">
            <header class="tt-dialog-head">
                <div>
                    <h2 id="ppRecordTitle">{{ $editRecord ? 'Edit ' . $editRecord->full_name : 'Add driver or crew member' }}</h2>
                    <p id="ppRecordDesc">For drivers and crew members who don't have an app account.</p>
                </div>
                <button type="button" class="tt-icon-btn tt-dialog-close" data-close-modal aria-label="Close">
                    <i data-lucide="x" aria-hidden="true"></i>
                </button>
            </header>

            <div class="tt-dialog-body">
                <form method="POST" id="recordForm" novalidate
                    action="{{ $editRecord ? route('superadmin.personnel-records.update', $editRecord->id) : route('superadmin.personnel-records.store') }}">
                    @csrf
                    <input type="hidden" name="_method" value="PUT" @disabled(! $editRecord)>
                    <input type="hidden" name="_modal" value="{{ $editRecord ? 'record' : 'add' }}">
                    <input type="hidden" name="_edit_id" value="{{ $editRecord?->id }}">

                    <div class="tt-form-alert" role="alert" data-form-alert @if (! in_array($reopenModal, ['add', 'record'], true)) hidden @endif>
                        <i data-lucide="circle-alert" aria-hidden="true"></i>
                        <span>
                            @if ($formErrorCount === 1)
                                Check the highlighted field. Everything else you entered is kept.
                            @else
                                Check the {{ $formErrorCount }} highlighted fields. Everything else you entered is kept.
                            @endif
                        </span>
                    </div>

                    <section class="tt-section" aria-labelledby="ppSectionDetails">
                        <h3 id="ppSectionDetails">Details</h3>
                        <div class="tt-grid">
                            @foreach (['first_name' => ['ppFirst', 'First name', true], 'last_name' => ['ppLast', 'Last name', true], 'middle_name' => ['ppMiddle', 'Middle name', false]] as $field => [$id, $label, $required])
                                <div class="tt-field @error($field) has-error @enderror">
                                    <label for="{{ $id }}">{{ $label }}
                                        @if ($required)
                                            <span class="tt-req" aria-hidden="true">*</span>
                                        @else
                                            <span class="tt-optional">(optional)</span>
                                        @endif
                                    </label>
                                    <input type="text" id="{{ $id }}" name="{{ $field }}" maxlength="100" @if ($required) required @endif
                                        value="{{ old($field) }}" autocomplete="off"
                                        @error($field) aria-invalid="true" aria-describedby="{{ $id }}Error" @enderror>
                                    @error($field)
                                        <p class="tt-error" id="{{ $id }}Error"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach

                            <div class="tt-field @error('role') has-error @enderror">
                                <label for="ppRole">Role <span class="tt-req" aria-hidden="true">*</span></label>
                                <div class="tt-select pp-field-select">
                                    <select id="ppRole" name="role" required @error('role') aria-invalid="true" aria-describedby="ppRoleError" @enderror>
                                        <option value="">Choose a role</option>
                                        @foreach (\App\Services\PersonnelService::PERSONNEL_ROLE_LABELS as $value => $label)
                                            <option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <i data-lucide="chevron-down" aria-hidden="true"></i>
                                </div>
                                @error('role')
                                    <p class="tt-error" id="ppRoleError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </section>

                    <section class="tt-section" aria-labelledby="ppSectionHome">
                        <h3 id="ppSectionHome">Home unit</h3>
                        <div class="tt-field @error('home_unit_id') has-error @enderror">
                            <label for="ppHome">Home unit <span class="tt-optional">(optional)</span></label>
                            <div class="tt-select pp-field-select">
                                <select id="ppHome" name="home_unit_id" @error('home_unit_id') aria-invalid="true" aria-describedby="ppHomeError" @enderror>
                                    <option value="">Not set</option>
                                    @foreach ($units as $unit)
                                        <option value="{{ $unit->id }}" @selected((int) old('home_unit_id') === $unit->id)>{{ $unit->name }}</option>
                                    @endforeach
                                </select>
                                <i data-lucide="chevron-down" aria-hidden="true"></i>
                            </div>
                            @error('home_unit_id')
                                <p class="tt-error" id="ppHomeError"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $message }}</p>
                            @enderror
                            <p class="pp-hint">Dispatchers place people on units. The home unit doesn't move anyone.</p>
                        </div>
                    </section>
                </form>

                {{-- Edit only: activation stays apart from the details form. --}}
                <section class="tt-section pp-manage" aria-labelledby="ppSectionStatus" data-record-status @if (! $editRecord) hidden @endif>
                    <h3 id="ppSectionStatus">Status</h3>
                    <div class="pp-manage-row">
                        <div>
                            <span class="tt-badge" data-status-badge></span>
                            <p class="pp-manage-text" data-status-text></p>
                        </div>
                        <button type="button" class="tt-btn tt-btn--sm" data-status-action></button>
                    </div>
                </section>
            </div>

            <footer class="tt-dialog-foot">
                <button type="button" class="tt-btn" data-close-modal>Cancel</button>
                <button type="submit" form="recordForm" class="tt-btn tt-btn--primary" data-submit-label>{{ $editRecord ? 'Save' : 'Add' }}</button>
            </footer>
        </div>
    </div>

    {{-- Edit a person with an app account: only the home unit is managed here. --}}
    <div class="tt-modal" id="accountModal" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog pp-dialog" role="dialog" aria-modal="true" aria-labelledby="ppAccountTitle" aria-describedby="ppAccountDesc">
            <header class="tt-dialog-head">
                <div>
                    <h2 id="ppAccountTitle">{{ $editAccount ? 'Edit ' . $editAccount->full_name : 'Edit' }}</h2>
                    <p id="ppAccountDesc">Name, role and sign-in come from the app account and aren't changed here.</p>
                </div>
                <button type="button" class="tt-icon-btn tt-dialog-close" data-close-modal aria-label="Close">
                    <i data-lucide="x" aria-hidden="true"></i>
                </button>
            </header>

            <div class="tt-dialog-body">
                <form method="POST" id="accountForm" novalidate
                    action="{{ $editAccount ? route('superadmin.personnel.home-unit', $editAccount->id) : '' }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="_modal" value="account">
                    <input type="hidden" name="_edit_id" value="{{ $editAccount?->id }}">

                    <section class="tt-section" aria-labelledby="ppSectionAccount">
                        <h3 id="ppSectionAccount">App account</h3>
                        <dl class="pp-facts">
                            <div><dt>Role</dt><dd data-account-role>{{ $editAccount ? (\App\Services\PersonnelService::ACCOUNT_ROLE_LABELS[(int) $editAccount->role_id] ?? '') : '' }}</dd></div>
                            <div><dt>ID</dt><dd data-account-reference>{{ $editAccount->user_code ?? '' }}</dd></div>
                            <div>
                                <dt>Status</dt>
                                <dd>
                                    <span data-account-status>{{ $editAccount ? ($editAccount->status === 'active' ? 'Active' : 'Inactive') : '' }}</span>
                                    <span class="pp-fact-note">Sign-in status of the account. It isn't changed from Personnel.</span>
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section class="tt-section" aria-labelledby="ppSectionAccountHome">
                        <h3 id="ppSectionAccountHome">Home unit</h3>
                        <div class="tt-field @if ($reopenModal === 'account' && $errors->has('home_unit_id')) has-error @endif">
                            <label for="ppAccountHome">Home unit <span class="tt-optional">(optional)</span></label>
                            <div class="tt-select pp-field-select">
                                <select id="ppAccountHome" name="home_unit_id"
                                    @if ($reopenModal === 'account' && $errors->has('home_unit_id')) aria-invalid="true" aria-describedby="ppAccountHomeError" @endif>
                                    <option value="">Not set</option>
                                    @foreach ($units as $unit)
                                        <option value="{{ $unit->id }}" @selected($reopenModal === 'account' && (int) old('home_unit_id') === $unit->id)>{{ $unit->name }}</option>
                                    @endforeach
                                </select>
                                <i data-lucide="chevron-down" aria-hidden="true"></i>
                            </div>
                            @if ($reopenModal === 'account' && $errors->has('home_unit_id'))
                                <p class="tt-error" id="ppAccountHomeError" role="alert"><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $error('home_unit_id') }}</p>
                            @endif
                            <p class="pp-hint" data-leader-note @unless ($editAccount && (int) $editAccount->role_id === 3) hidden @endunless>
                                For a team leader, the home unit is the unit they lead: saving moves them onto it, and clearing it takes them off it. Not possible during an active job, when that unit already has a team leader, or while they are on another truck.
                            </p>
                            {{-- Filled when the leader is on another truck today; the server re-checks on save. --}}
                            <p class="pp-elsewhere" data-elsewhere-note hidden></p>
                            <p class="pp-hint" data-driver-note @if ($editAccount && (int) $editAccount->role_id === 3) hidden @endif>
                                Dispatchers place drivers on units. The home unit doesn't move anyone.
                            </p>
                        </div>
                    </section>
                </form>
            </div>

            <footer class="tt-dialog-foot">
                <button type="button" class="tt-btn" data-close-modal>Cancel</button>
                <button type="submit" form="accountForm" class="tt-btn tt-btn--primary">Save</button>
            </footer>
        </div>
    </div>

    {{-- Confirm activate / deactivate a personnel record --}}
    <div class="tt-modal" id="personnelConfirm" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog tt-dialog--confirm" role="alertdialog" aria-modal="true" aria-labelledby="ppConfirmTitle" aria-describedby="ppConfirmText">
            <form method="POST" id="personnelConfirmForm">
                @csrf
                @method('PATCH')
                <div class="tt-dialog-head">
                    <div>
                        <h2 id="ppConfirmTitle"></h2>
                        <p id="ppConfirmText"></p>
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
    <script src="{{ asset('superadmin/js/personnel.js') }}?v={{ filemtime(public_path('superadmin/js/personnel.js')) }}" defer></script>
@endpush
