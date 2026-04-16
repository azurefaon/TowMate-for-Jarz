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

        @if ($errors->any())
            <div class="units-inline-alert error">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="units-hero">
            <div>
                <h1 class="units-title">Units Overview</h1>
                <p class="units-subtitle">View every unit and switch its dispatch availability on or off in real time.</p>
                @if (!empty($search))
                    <span class="units-filter-pill">Filtered by: {{ $search }}</span>
                @endif
            </div>

            <div class="units-hero-actions">
                <button type="button" class="units-link-btn" id="openAddUnitModalBtn">
                    <i data-lucide="plus-circle"></i>
                    <span>Add Unit</span>
                </button>
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

        <section class="units-stats-grid">
            <article class="units-stat-card">
                <span>Available Units</span>
                <strong>{{ $stats['available'] }}</strong>
            </article>
            <article class="units-stat-card warning">
                <span>Not Available</span>
                <strong>{{ $stats['not_available'] }}</strong>
            </article>
            <article class="units-stat-card info">
                <span>Ready Team Leaders</span>
                <strong>{{ $stats['ready_team_leaders'] }}</strong>
            </article>
            <article class="units-stat-card subtle">
                <span>Truck Types</span>
                <strong>{{ $stats['truck_types'] }}</strong>
            </article>
        </section>

        <section class="units-table-card">
            <div class="units-table-header">
                <div>
                    <h2>Unit availability</h2>
                    <p>Search by unit name, plate number, truck type, team leader, or current status.</p>
                </div>

                <label class="search-box" for="unitSearch">
                    <i data-lucide="search"></i>
                    <input type="text" id="unitSearch" placeholder="Search units..." value="{{ $search ?? '' }}"
                        autocomplete="off">
                </label>
            </div>

            <div class="table-shell">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Plate</th>
                            <th>Truck Type</th>
                            <th>Team Leader</th>
                            <th>Member Driver</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="unitsTable">
                        @forelse ($units as $unit)
                            @php
                                $statusLabel = match ($unit->status) {
                                    'available' => 'Available',
                                    'maintenance' => 'Not Available',
                                    'on_job' => 'On Job',
                                    default => ucfirst(str_replace('_', ' ', $unit->status)),
                                };

                                $statusClass =
                                    $unit->status === 'maintenance'
                                        ? 'maintenance'
                                        : ($unit->status === 'on_job'
                                            ? 'on-job'
                                            : 'available');
                            @endphp
                            <tr data-name="{{ strtolower($unit->name) }}"
                                data-plate="{{ strtolower($unit->plate_number) }}"
                                data-type="{{ strtolower(optional($unit->truckType)->name ?? '') }}"
                                data-teamleader="{{ strtolower(optional($unit->teamLeader)->full_name ?? (optional($unit->teamLeader)->name ?? '')) }}"
                                data-status="{{ strtolower($statusLabel) }}">
                                <td>
                                    <div class="unit-cell">
                                        <div class="unit-avatar">{{ strtoupper(substr($unit->name, 0, 2)) }}</div>
                                        <div>
                                            <strong class="unit-name">{{ $unit->name }}</strong>
                                            <span class="unit-subtext">Updated
                                                {{ $unit->updated_at->diffForHumans() }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge plate">{{ $unit->plate_number }}</span>
                                </td>
                                <td>
                                    <span class="badge truck">{{ optional($unit->truckType)->name ?? 'N/A' }}</span>
                                </td>
                                <td>
                                    <span
                                        class="table-main-text">{{ optional($unit->teamLeader)->full_name ?? (optional($unit->teamLeader)->name ?? 'Unassigned') }}</span>
                                </td>
                                <td>
                                    <span
                                        class="table-main-text">{{ optional($unit->driver)->full_name ?? (optional($unit->driver)->name ?? 'No member assigned') }}</span>
                                </td>
                                <td>
                                    <div class="availability-cell">
                                        <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>

                                        @if ($unit->status === 'on_job')
                                            <span class="availability-note">Locked while this unit is handling a job.</span>
                                        @else
                                            <form method="POST"
                                                action="{{ route('admin.available-units.toggle', $unit) }}"
                                                class="availability-form">
                                                @csrf
                                                @method('PATCH')

                                                <label class="availability-switch">
                                                    <input type="checkbox" class="availability-toggle"
                                                        aria-label="Toggle availability for {{ $unit->name }}"
                                                        @checked($unit->status === 'available')>
                                                    <span class="availability-slider"></span>
                                                    <span class="availability-switch-text">
                                                        {{ $unit->status === 'available' ? 'On' : 'Off' }}
                                                    </span>
                                                </label>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="table-empty-cell">
                                    <div class="empty-state">
                                        <i data-lucide="truck"></i>
                                        <h3>No units found</h3>
                                        <p>Add a unit or adjust the search to manage dispatch availability.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrapper">
                {{ $units->appends(request()->query())->links() }}
            </div>
        </section>

        <div id="addUnitModal" class="units-modal {{ $errors->any() ? 'is-open' : '' }}"
            aria-hidden="{{ $errors->any() ? 'false' : 'true' }}">
            <div class="units-modal-card">
                <div class="units-modal-header">
                    <div>
                        <span class="units-modal-badge">
                            <i data-lucide="sparkles"></i>
                            Dispatcher Tools
                        </span>
                        <h3>Add New Unit</h3>
                    </div>
                    <button type="button" class="units-modal-close" id="closeAddUnitModalBtn">✕</button>
                </div>

                <div class="units-modal-body">
                    <div class="units-modal-note">
                        <i data-lucide="truck"></i>
                        <div>
                            <strong>Quick setup</strong>
                            <span>New units added here will be available in the dispatcher workflow right away.</span>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.available-units.store') }}">
                        @csrf

                        <div class="form-group">
                            <label for="unitName">Unit Name</label>
                            <input id="unitName" type="text" name="name" value="{{ old('name') }}"
                                placeholder="Example: Unit 07" required>
                            <span class="units-field-helper">Use a short unit label that dispatchers can spot
                                quickly.</span>
                        </div>

                        <div class="units-form-row">
                            <div class="form-group">
                                <label for="plateNumber">Plate Number</label>
                                <input id="plateNumber" type="text" name="plate_number"
                                    value="{{ old('plate_number') }}" placeholder="Example: ABC 1234" required>
                            </div>

                            <div class="form-group">
                                <label for="truckTypeId">Tow Truck Type</label>
                                <select id="truckTypeId" name="truck_type_id" required>
                                    <option value="">Select truck type</option>
                                    @foreach ($truckTypes as $truckType)
                                        <option value="{{ $truckType->id }}" @selected(old('truck_type_id') == $truckType->id)>
                                            {{ $truckType->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="units-form-row">
                            <div class="form-group">
                                <label for="unitStatus">Status</label>
                                <select id="unitStatus" name="status">
                                    <option value="available" @selected(old('status', 'available') === 'available')>Available</option>
                                    <option value="maintenance" @selected(old('status') === 'maintenance')>Not Available</option>
                                </select>
                                <span class="units-field-helper">Choose whether this unit is ready now or temporarily
                                    unavailable for dispatch.</span>
                            </div>

                            <div class="form-group" id="issueNoteGroup" style="display:none;">
                                <label for="issueNote">Maintenance Note</label>
                                <textarea id="issueNote" name="issue_note" placeholder="Add a short maintenance note if needed">{{ old('issue_note') }}</textarea>
                            </div>
                        </div>

                        <div class="units-modal-actions">
                            <button type="button" class="units-link-btn secondary"
                                id="cancelAddUnitModalBtn">Cancel</button>
                            <button type="submit" class="units-link-btn">
                                <i data-lucide="save"></i>
                                <span>Save Unit</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/available-units.js') }}"></script>
@endpush
