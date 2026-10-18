@extends('admin-dashboard.layouts.app')

@section('title', 'Units Overview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/available-units.css') }}">
    <style>
        .units-inline-alert {
            margin: 0 0 16px;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
            font-weight: 600;
        }

        .units-inline-alert.error {
            border-color: #fecaca;
            background: #fef2f2;
            color: #991b1b;
        }

        .units-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(8px);
            z-index: 1600;
        }

        .units-modal.is-open {
            display: flex;
        }

        body.modal-open {
            overflow: hidden;
        }

        .units-modal-card {
            width: min(680px, 100%);
            background: #fff;
            border-radius: 24px;
            padding: 0;
            border: 1px solid rgba(226, 232, 240, 0.95);
            box-shadow: 0 28px 70px rgba(15, 23, 42, 0.22);
            overflow: hidden;
        }

        .units-modal-header,
        .units-modal-actions,
        .units-form-row {
            display: flex;
            gap: 12px;
        }

        .units-modal-header {
            justify-content: space-between;
            align-items: flex-start;
            padding: 20px 22px;
            background: linear-gradient(135deg, #fff8db 0%, #ffffff 65%);
            border-bottom: 1px solid #edf2f7;
        }

        .units-modal-header h3 {
            margin: 8px 0 4px;
            font-size: 1.3rem;
            color: #0f172a;
        }

        .units-modal-header p {
            margin: 0;
            color: #64748b;
        }

        .units-modal-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #111827;
            color: #fff;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .units-modal-close {
            border: 0;
            background: #ffffff;
            width: 38px;
            height: 38px;
            border-radius: 999px;
            cursor: pointer;
            font-size: 18px;
            color: #475569;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }

        .units-modal-body {
            padding: 18px 22px 22px;
            display: grid;
            gap: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        }

        .units-modal-note {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 12px 14px;
            border-radius: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #475569;
        }

        .units-modal-note strong {
            display: block;
            color: #0f172a;
            margin-bottom: 2px;
        }

        .units-modal-note i {
            color: #d97706;
            flex-shrink: 0;
        }

        .units-form-row {
            flex-wrap: wrap;
        }

        .units-form-row .form-group {
            flex: 1 1 220px;
        }

        .units-modal-card .form-group {
            display: grid;
            gap: 6px;
        }

        .units-modal-card label {
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #475569;
        }

        .units-modal-card input,
        .units-modal-card select,
        .units-modal-card textarea {
            width: 100%;
            border: 1px solid #dbe3ee;
            border-radius: 14px;
            padding: 12px 14px;
            background: #fff;
            color: #0f172a;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.03);
        }

        .units-modal-card textarea {
            min-height: 92px;
            resize: vertical;
        }

        .units-modal-card input:focus,
        .units-modal-card select:focus,
        .units-modal-card textarea:focus {
            border-color: #facc15;
            box-shadow: 0 0 0 4px rgba(250, 204, 21, 0.16);
            transform: translateY(-1px);
        }

        .units-field-helper {
            font-size: 0.82rem;
            color: #64748b;
        }

        .units-modal-actions {
            justify-content: flex-end;
            margin-top: 6px;
            flex-wrap: wrap;
        }

        .units-hero-actions {
            flex-wrap: wrap;
        }

        @media (max-width: 640px) {

            .units-modal-header,
            .units-modal-body {
                padding-left: 16px;
                padding-right: 16px;
            }
        }
    </style>
@endpush

@section('content')
    <div class="units-page">
        @if (session('success'))
            <div class="units-inline-alert">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="units-inline-alert error">{{ session('error') }}</div>
        @endif

        <section class="units-hero">
            <div>
                <h1 class="units-title">Fleet Overview</h1>
                <p class="units-subtitle">Monitor unit status, team leader assignments, and override availability in real
                    time.</p>
                @if (!empty($search))
                    <span class="units-filter-pill">Filtered by: {{ $search }}</span>
                @endif
            </div>

            <div class="units-hero-actions">
                <a href="{{ route('admin.zones.index') }}" class="units-link-btn secondary">
                    <i data-lucide="map"></i>
                    <span>Manage Zones</span>
                </a>
                <a href="{{ route('admin.dispatch') }}" class="units-link-btn secondary">
                    <i data-lucide="radio"></i>
                    <span>Dispatch Queue</span>
                </a>
                <a href="{{ route('admin.drivers') }}" class="units-link-btn secondary">
                    <i data-lucide="users"></i>
                    <span>Team Leaders</span>
                </a>
            </div>
        </section>

        <section class="units-table-card">
            <div class="units-table-header">
                <div>
                    <h2>Units &amp; Leaders</h2>
                    <p>Click any unit card to view details or override its status.</p>
                </div>

                <label class="search-box" for="unitSearch">
                    <i data-lucide="search"></i>
                    <input type="text" id="unitSearch" placeholder="Search units..." value="{{ $search ?? '' }}"
                        autocomplete="off">
                </label>
            </div>

            <div class="units-fleet-grid" id="fleetGrid">
                @forelse ($units as $unit)
                    @php
                        $tl = $unit->teamLeader;
                        $drv = $unit->driver;
                        $statusClass = match ($unit->status) {
                            'available' => 'available',
                            'on_job' => 'on-job',
                            'maintenance' => 'maintenance',
                            default => 'maintenance',
                        };
                        $statusLabel = match ($unit->status) {
                            'available' => 'Available',
                            'on_job' => 'In Transit',
                            'maintenance' => 'Not Available',
                            default => ucfirst(str_replace('_', ' ', $unit->status)),
                        };
                    @endphp
                    <div class="unit-fleet-card" data-name="{{ strtolower($unit->name) }}"
                        data-plate="{{ strtolower($unit->plate_number) }}"
                        data-type="{{ strtolower(optional($unit->truckType)->name ?? '') }}"
                        data-status="{{ strtolower($statusLabel) }}"
                        data-tl="{{ strtolower(optional($tl)->full_name ?? '') }}"
                        data-driver="{{ strtolower(optional($drv)->full_name ?? '') }}"
                        data-modal-id="{{ $unit->id }}" data-modal-name="{{ $unit->name }}"
                        data-modal-plate="{{ $unit->plate_number }}"
                        data-modal-type="{{ optional($unit->truckType)->name ?? 'N/A' }}"
                        data-modal-status="{{ $unit->status }}" data-modal-status-label="{{ $statusLabel }}"
                        data-modal-tl-name="{{ optional($tl)->full_name ?? 'Unassigned' }}"
                        data-modal-tl-role="{{ optional(optional($tl)->role)->name ?? '' }}"
                        data-modal-tl-email="{{ optional($tl)->email ?? '' }}"
                        data-modal-tl-phone="{{ optional($tl)->phone ?? '' }}"
                        data-modal-driver-name="{{ optional($drv)->full_name ?? 'No driver assigned' }}"
                        data-modal-driver-role="{{ optional(optional($drv)->role)->name ?? '' }}"
                        data-modal-driver-email="{{ optional($drv)->email ?? '' }}"
                        data-modal-driver-phone="{{ optional($drv)->phone ?? '' }}"
                        data-modal-issue-note="{{ $unit->issue_note ?? '' }}">
                        <div class="unit-card-top">
                            <div class="unit-tag {{ $statusClass }}">
                                {{ strtoupper(substr($unit->name, 0, 3)) }}
                            </div>
                            <div class="unit-card-meta">
                                <span
                                    class="unit-type-label">{{ strtoupper(optional($unit->truckType)->name ?? 'Unknown') }}</span>
                                <strong class="unit-card-name">{{ $unit->name }}</strong>
                            </div>
                            <span class="unit-status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
                        </div>
                        <div class="unit-card-people">
                            <div class="unit-person">
                                <span class="person-label">Team Leader</span>
                                <span class="person-name">{{ optional($tl)->full_name ?? 'Unassigned' }}</span>
                            </div>
                            <div class="unit-person">
                                <span class="person-label">Driver</span>
                                <span class="person-name">{{ optional($drv)->full_name ?? 'No driver assigned' }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="fleet-empty-state">
                        <i data-lucide="truck"></i>
                        <h3>No units found</h3>
                        <p>Adjust the search to find units.</p>
                    </div>
                @endforelse
            </div>

            <div class="pagination-wrapper">
                {{ $units->appends(request()->query())->links() }}
            </div>
        </section>

        {{-- Unit Detail Modal --}}
        <div id="unitDetailModal" class="units-modal" aria-hidden="true">
            <div class="ud-modal-card">
                <div class="ud-modal-header">
                    <div>
                        <span class="units-modal-badge">
                            <i data-lucide="truck"></i>
                            Unit Details
                        </span>
                        <h3 id="udTitle"></h3>
                        <p id="udSubtitle"></p>
                    </div>
                    <button type="button" class="ud-close-btn" id="udClose2">&#x2715;</button>
                </div>

                <div class="ud-modal-body">
                    <div class="ud-meta-row">
                        <div class="ud-meta-item">
                            <span class="ud-meta-label">Unit Identifier</span>
                            <span class="ud-meta-value" id="udPlate"></span>
                        </div>
                        <div class="ud-meta-item">
                            <span class="ud-meta-label">Asset Class</span>
                            <span class="ud-meta-value" id="udType"></span>
                        </div>
                        <div class="ud-meta-item">
                            <span class="ud-meta-label">Current Status</span>
                            <span id="udStatusBadge" class="ud-status-pill"></span>
                        </div>
                    </div>

                    <div class="ud-section">
                        <h4><i data-lucide="shield-check"></i> Team Leader</h4>
                        <div class="ud-person-card" id="udTlCard">
                            <strong id="udTlName"></strong>
                            <span class="ud-role" id="udTlRole"></span>
                            <div class="ud-contact">
                                <i data-lucide="mail"></i>
                                <span id="udTlEmail"></span>
                            </div>
                            <div class="ud-contact">
                                <i data-lucide="phone"></i>
                                <span id="udTlPhone"></span>
                            </div>
                        </div>
                    </div>

                    <div class="ud-section">
                        <h4><i data-lucide="user"></i> Assigned Driver</h4>
                        <div class="ud-person-card" id="udDriverCard">
                            <strong id="udDriverName"></strong>
                            <span class="ud-role" id="udDriverRole"></span>
                            <div class="ud-contact">
                                <i data-lucide="mail"></i>
                                <span id="udDriverEmail"></span>
                            </div>
                            <div class="ud-contact">
                                <i data-lucide="phone"></i>
                                <span id="udDriverPhone"></span>
                            </div>
                        </div>
                    </div>

                    <div class="ud-override-section" id="udOverrideSection">
                        <div class="ud-override-header">
                            <div class="ud-override-title">
                                <i data-lucide="triangle-alert"></i>
                                Status Override
                            </div>
                            <span class="ud-restricted-badge">Restricted Access</span>
                        </div>
                        <p>Manually override the unit's operational status. This will alert the assigned Team Leader and
                            remove the unit from the active dispatch queue immediately.</p>
                        <div class="ud-override-form">
                            <label for="udOverrideState">Select New State</label>
                            <select id="udOverrideState">
                                <option value="maintenance">Not Available (Unreachable / Maintenance)</option>
                            </select>
                            <label for="udOverrideReason">Reason for Override (Optional)</label>
                            <textarea id="udOverrideReason" placeholder="Provide brief context for the manual status change..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="ud-modal-footer">
                    <button type="button" class="ud-btn-close" id="udCloseBtn">Close Window</button>
                    <button type="button" class="ud-btn-save" id="udSaveBtn">
                        <i data-lucide="save"></i>
                        Save Override
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/available-units.js') }}"></script>
@endpush
