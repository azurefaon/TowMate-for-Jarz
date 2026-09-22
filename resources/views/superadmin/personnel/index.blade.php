@extends('layouts.superadmin')

@section('title', 'Personnel')

@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="{{ asset('admin/css/personnel.css') }}?v={{ filemtime(public_path('admin/css/personnel.css')) }}">
@endpush

@section('content')
    <div class="personnel-page" id="ppPage"
        data-index-url="{{ route('superadmin.personnel.index') }}"
        data-store-url="{{ route('superadmin.personnel-records.store') }}"
        data-search="{{ $filters['search'] }}"
        data-role="{{ $filters['role'] }}">
        <div class="page-top">
            <div>
                <h1>Personnel</h1>
                <p>Manage team leaders, drivers, and crew members, and their unit assignments.</p>
            </div>
        </div>

        <div class="type-feedback type-feedback--success" id="personnelSuccessAlert" style="display:none;"></div>

        @if (session('success'))
            <div class="type-feedback type-feedback--success" id="personnelSessionAlert">{{ session('success') }}</div>
        @endif

        @include('superadmin.fleet._tabs')

        <form method="GET" id="ppFilterForm" class="pp-toolbar">
            <div class="pp-toolbar-left">
                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" id="ppSearchInput" name="search" value="{{ $filters['search'] }}" placeholder="Search personnel...">
                </div>

                <select name="role" id="ppRoleFilter" data-custom>
                    <option value="">All roles</option>
                    @foreach (\App\Services\PersonnelService::ROLE_FILTERS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['role'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <button type="button" class="pp-add-btn" id="ppAddBtn">Add Personnel</button>
        </form>

        <div class="table-card" id="ppTableCard">
            <div class="table-scroll">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Personnel</th>
                            <th>Unit Assignment</th>
                            <th>Assignment Status</th>
                            <th class="u-actions-col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="ppTableBody">
                        @include('superadmin.personnel._rows', ['personnel' => $personnel])
                    </tbody>
                </table>
            </div>

            <div class="pp-table-footer" id="ppTableFooter">
                @include('superadmin.personnel._footer', ['personnel' => $personnel])
            </div>
        </div>
    </div>

    <div class="pp-modal" id="ppModal">
        <div class="pp-modal-card">
            <div class="pp-modal-header">
                <h2 id="ppModalTitle">Add Personnel</h2>
                <button type="button" class="pp-modal-close" data-close-pp-modal aria-label="Close">&times;</button>
            </div>

            <form method="POST" id="ppForm">
                @csrf
                <input type="hidden" name="_method" id="ppFormMethod" value="POST">

                <div class="pp-form-row">
                    <div class="pp-form-group">
                        <label for="ppFirstName">First Name</label>
                        <input type="text" name="first_name" id="ppFirstName" required>
                    </div>
                    <div class="pp-form-group">
                        <label for="ppLastName">Last Name</label>
                        <input type="text" name="last_name" id="ppLastName" required>
                    </div>
                </div>

                <div class="pp-form-group">
                    <label for="ppMiddleName">Middle Name (optional)</label>
                    <input type="text" name="middle_name" id="ppMiddleName">
                </div>

                <div class="pp-form-group">
                    <label for="ppRole">Role</label>
                    <select name="role" id="ppRole" required>
                        <option value="driver">Driver</option>
                        <option value="crew">Crew Member</option>
                    </select>
                </div>

                <div class="pp-form-group" id="ppHomeUnitGroup">
                    <label for="ppHomeUnit">Regular Unit (optional)</label>
                    <select name="home_unit_id" id="ppHomeUnit">
                        <option value="">Not Assigned</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="pp-modal-footer">
                    <button type="button" class="pp-btn-cancel" data-close-pp-modal>Cancel</button>
                    <button type="submit" class="pp-btn-save">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div class="pp-modal" id="ppManageModal">
        <div class="pp-modal-card pp-modal-card--wide">
            <div class="pp-modal-header">
                <div>
                    <h2 id="pmName">&nbsp;</h2>
                    <p class="pp-modal-subtitle">
                        <span id="pmRoleLabel"></span>
                        <span id="pmReferenceWrap"> &middot; <span id="pmReference"></span></span>
                    </p>
                </div>
                <span class="status-text" id="pmStatusBadge"></span>
                <button type="button" class="pp-modal-close" data-close-pp-modal aria-label="Close">&times;</button>
            </div>

            <form method="POST" id="pmForm">
                @csrf

                <div class="pp-modal-section">
                    <div class="pp-modal-section-head">
                        <h3>Personal Information</h3>
                    </div>

                    <div class="pp-form-row" id="pmEditableInfo">
                        <div class="pp-form-group">
                            <label for="pmFirstName">First Name</label>
                            <input type="text" name="first_name" id="pmFirstName" required>
                        </div>
                        <div class="pp-form-group">
                            <label for="pmLastName">Last Name</label>
                            <input type="text" name="last_name" id="pmLastName" required>
                        </div>
                    </div>
                    <div class="pp-form-group" id="pmMiddleNameGroup">
                        <label for="pmMiddleName">Middle Name (optional)</label>
                        <input type="text" name="middle_name" id="pmMiddleName">
                    </div>
                    <div class="pp-form-group" id="pmRoleGroup">
                        <label for="pmRole">Role</label>
                        <select name="role" id="pmRole">
                            <option value="driver">Driver</option>
                            <option value="crew">Crew Member</option>
                        </select>
                    </div>

                    <div class="pp-readonly-grid" id="pmReadonlyInfo">
                        <div class="pp-readonly-field">
                            <span>First Name</span>
                            <strong id="pmRoFirst"></strong>
                        </div>
                        <div class="pp-readonly-field">
                            <span>Middle Name</span>
                            <strong id="pmRoMiddle"></strong>
                        </div>
                        <div class="pp-readonly-field">
                            <span>Last Name</span>
                            <strong id="pmRoLast"></strong>
                        </div>
                        <div class="pp-readonly-field">
                            <span>Role</span>
                            <strong id="pmRoRole"></strong>
                        </div>
                    </div>
                </div>

                <div class="pp-modal-section">
                    <div class="pp-modal-section-head">
                        <h3>Unit Assignment</h3>
                    </div>

                    <div class="pp-form-group">
                        <label for="pmHomeUnit">Regular Unit</label>
                        <select name="home_unit_id" id="pmHomeUnit">
                            <option value="">Not Assigned</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="pp-static-field">
                        <span>Current Unit</span>
                        <strong id="pmCurrentUnit">Not Assigned</strong>
                    </div>

                    <div class="pp-static-field">
                        <span>Assignment Status</span>
                        <strong class="status-text" id="pmAssignmentStatus"></strong>
                    </div>
                </div>

                <div class="pp-modal-footer pp-modal-footer--split">
                    <button type="button" class="pp-btn-danger" id="pmToggleBtn"></button>

                    <div class="pp-modal-footer-actions">
                        <button type="button" class="pp-btn-cancel" data-close-pp-modal>Cancel</button>
                        <button type="submit" class="pp-btn-save">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="pp-modal pp-confirm-modal" id="ppConfirmModal">
        <div class="pp-modal-card pp-modal-card--confirm">
            <p class="pp-confirm-title" id="ppConfirmTitle">Deactivate Personnel</p>
            <p class="pp-confirm-text" id="ppConfirmText"></p>

            <div class="pp-modal-footer">
                <button type="button" class="pp-btn-cancel" id="ppConfirmCancel">Cancel</button>
                <button type="button" class="pp-btn-save pp-btn-save--danger" id="ppConfirmProceed">Confirm</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('admin/js/personnel.js') }}?v={{ filemtime(public_path('admin/js/personnel.js')) }}" defer></script>
@endpush
