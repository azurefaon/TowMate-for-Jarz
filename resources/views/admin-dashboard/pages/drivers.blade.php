@extends('admin-dashboard.layouts.app')

@section('title', 'Units & Leaders')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/jobs.css') }}">
    <link rel="stylesheet" href="{{ asset('dispatcher/css/drivers.css') }}">
@endpush

@section('content')
    <div class="ul-page" data-csrf="{{ csrf_token() }}">

        <div class="ul-header">
            <h1 class="ul-title">Units &amp; Leaders</h1>
            <p class="ul-subtitle">Manage daily team readiness and unit availability.</p>
        </div>

        @if (session('success'))
            <div class="ul-feedback ul-feedback--success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="ul-feedback ul-feedback--error">{{ $errors->first() }}</div>
        @endif

        <div class="ul-toolbar">
            <div class="ul-filter-group">
                <label for="ulAvailability">Availability</label>
                <select id="ulAvailability">
                    <option value="all">All</option>
                    <option value="available">Available</option>
                    <option value="not_available">Not Available</option>
                </select>
            </div>
            <div class="ul-filter-group">
                <label for="ulPresence">Presence</label>
                <select id="ulPresence">
                    <option value="all">All</option>
                    <option value="online">Online</option>
                    <option value="offline">Offline</option>
                </select>
            </div>
            <div class="ul-filter-group">
                <label for="ulTruckType">Truck Type</label>
                <select id="ulTruckType">
                    <option value="all">All</option>
                    <option value="light">Light</option>
                    <option value="medium">Medium</option>
                    <option value="heavy">Heavy</option>
                </select>
            </div>
            <div class="ul-filter-group ul-filter-group--search">
                <label for="ulSearch">Search</label>
                <input type="text" id="ulSearch" placeholder="Search unit or personnel">
            </div>
        </div>

        <div class="jobs-table-wrap">
            <table class="jobs-table ul-table">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th>Truck Type</th>
                        <th>Team Leader</th>
                        <th>Status</th>
                        <th>Current Assignment</th>
                        <th class="ul-col-chevron"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    @php
                        $unit = $row['unit'];
                        $tl = $unit->teamLeader;
                        $searchBlob = strtolower(implode(' ', array_filter([
                            $unit->name, $unit->plate_number,
                            $tl?->full_name ?? $tl?->name,
                            $unit->driver_name, $unit->crew_member_1_name, $unit->crew_member_2_name,
                        ])));
                        $truckClass = strtolower($unit->truckType->class ?? '');
                        $truckLabel = $unit->truckType->class ? ucfirst($unit->truckType->class) . ' Duty' : ($unit->truckType->name ?? '—');

                        $reasonLabels = [
                            'maintenance' => 'Under maintenance',
                            'no_team_leader' => 'No Team Leader',
                            'team_leader_duty_unavailable' => 'Team Leader Duty Unavailable',
                            'no_driver' => 'No Driver',
                            'driver_duty_unavailable' => 'Driver Duty Unavailable',
                            'busy' => 'Busy',
                            'reserved' => 'Reserved',
                        ];
                        $reasonText = collect($row['reasons'] ?? [])
                            ->reject(fn ($r) => $r === 'archived')
                            ->map(fn ($r) => $reasonLabels[$r] ?? null)
                            ->filter()
                            ->first();

                        // Reserved/Active-Job units have a locked roster — no Assign,
                        // Borrow, Return, or Transfer Team, regardless of which slot.
                        // Server-side guards in UnitTeamAssignmentService are the real
                        // enforcement; this only keeps the UI from offering an action
                        // that would just be rejected.
                        $isLocked = (bool) ($row['reservation'] || $row['active_booking']);
                    @endphp
                    <tr class="ul-row"
                        tabindex="0"
                        role="button"
                        aria-haspopup="dialog"
                        data-unit-id="{{ $unit->id }}"
                        data-unit-name="{{ $unit->name }}"
                        data-locked="{{ $isLocked ? '1' : '0' }}"
                        data-availability="{{ $row['available'] ? 'available' : 'not_available' }}"
                        data-presence="{{ $row['presence'] ?? 'offline' }}"
                        data-truck-type="{{ $truckClass }}"
                        data-search="{{ $searchBlob }}">
                        <td>
                            <div class="jobs-cell-primary jobs-booking-code">{{ $unit->name }}</div>
                            <div class="jobs-cell-secondary">{{ $unit->plate_number ?? '—' }}</div>
                        </td>
                        <td>
                            <span class="jobs-cell-primary">{{ $truckLabel }}</span>
                        </td>
                        <td>
                            @if ($tl)
                                <span class="jobs-cell-primary">{{ $tl->full_name ?? $tl->name }}</span>
                            @else
                                <span class="ul-person-empty">Not assigned</span>
                            @endif
                        </td>
                        <td>
                            <span class="ul-status-value ul-availability--{{ $row['available'] ? 'available' : 'not-available' }}">
                                {{ $row['available'] ? 'Available' : 'Not Available' }}
                            </span>
                            @if (! $row['available'] && $reasonText)
                                <div class="ul-status-reason">{{ $reasonText }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($row['reservation'])
                                <div class="jobs-cell-primary">{{ $row['reservation']->booking_code }}</div>
                                <div class="jobs-cell-secondary">Reserved</div>
                            @elseif ($row['active_booking'])
                                <div class="jobs-cell-primary">{{ $row['active_booking']->booking_code }}</div>
                                <div class="jobs-cell-secondary">{{ str($row['active_booking']->status)->replace('_', ' ')->title() }}</div>
                            @else
                                <span class="jobs-cell-secondary">—</span>
                            @endif
                        </td>
                        <td class="ul-col-chevron"><span class="ul-row-chevron" aria-hidden="true">&rsaquo;</span></td>
                    </tr>
                    {{-- Unit Details drawer content — server-rendered once, cloned
                         into #ulDrawerBody on row click. Keeps this page free of
                         any new read endpoint: all data already computed above. --}}
                    <template id="ul-drawer-{{ $unit->id }}">
                        <div class="ul-drawer-unit-header">
                            <h2 class="ul-drawer-title">{{ $unit->name }}</h2>
                            <p class="ul-drawer-meta">{{ $truckLabel }}</p>
                            <p class="ul-drawer-meta ul-drawer-meta--muted">{{ $unit->plate_number ?? '—' }}</p>
                        </div>

                        <div class="ul-drawer-section">
                            <h3 class="ul-drawer-section-title">Operational Status</h3>
                            <div class="ul-status-row">
                                <span class="ul-status-label">Presence</span>
                                <span class="ul-status-right">
                                    @if ($row['presence'])
                                        <span class="ul-status-value ul-presence--{{ $row['presence'] }}">{{ ucfirst($row['presence']) }}</span>
                                    @else
                                        <span class="ul-status-value ul-status-value--muted">—</span>
                                    @endif
                                </span>
                            </div>
                            <div class="ul-status-row">
                                <span class="ul-status-label">Duty</span>
                                <span class="ul-status-right">
                                    @if ($tl)
                                        <span class="ul-status-value ul-duty--{{ $row['team_leader_duty'] }}">{{ ucfirst($row['team_leader_duty']) }}</span>
                                        @if ($row['workload'] === 'busy')
                                            <span class="ul-status-locked">Locked while on job</span>
                                        @else
                                            <button type="button" class="ul-drawer-btn-secondary ul-drawer-btn-secondary--sm" data-action="toggle-tl-duty" data-tl-id="{{ $tl->id }}" data-current="{{ $row['team_leader_duty'] }}">{{ $row['team_leader_duty'] === 'available' ? 'Set unavailable' : 'Set available' }}</button>
                                        @endif
                                    @else
                                        <span class="ul-status-value ul-status-value--muted">—</span>
                                    @endif
                                </span>
                            </div>
                            <div class="ul-status-row">
                                <span class="ul-status-label">Workload</span>
                                <span class="ul-status-right">
                                    @if ($row['workload'])
                                        <span class="ul-status-value ul-workload--{{ $row['workload'] }}">{{ ucfirst($row['workload']) }}</span>
                                    @else
                                        <span class="ul-status-value ul-status-value--muted">—</span>
                                    @endif
                                </span>
                            </div>
                            <div class="ul-status-row">
                                <span class="ul-status-label">Availability</span>
                                <span class="ul-status-right">
                                    <span class="ul-status-value ul-availability--{{ $row['available'] ? 'available' : 'not-available' }}">
                                        {{ $row['available'] ? 'Available' : 'Not Available' }}
                                    </span>
                                </span>
                            </div>
                            @if (! $row['available'] && $reasonText)
                                <div class="ul-status-reason">{{ $reasonText }}</div>
                            @endif
                        </div>

                        <div class="ul-drawer-section">
                            <h3 class="ul-drawer-section-title">Assigned Team</h3>
                            <div class="ul-person-row">
                                <span class="ul-person-label">Team Leader</span>
                                <div class="ul-person-line">
                                    @if ($tl)
                                        <div class="ul-person-info">
                                            <span class="ul-person-name">{{ $tl->full_name ?? $tl->name }}</span>
                                            @if ($row['team_leader_home_unit'])
                                                <span class="ul-person-note">Borrowed from {{ $row['team_leader_home_unit'] }}</span>
                                            @endif
                                        </div>
                                        @unless ($isLocked)
                                            @if ($row['team_leader_home_unit'])
                                                <button type="button" class="ul-drawer-btn-secondary" data-action="return-team-leader" data-unit-id="{{ $unit->id }}">Return</button>
                                            @else
                                                <button type="button" class="ul-drawer-btn-secondary" data-action="remove-team-leader" data-unit-id="{{ $unit->id }}">Remove</button>
                                            @endif
                                        @endunless
                                    @else
                                        <span class="ul-person-empty">Not assigned</span>
                                        @unless ($isLocked)
                                            <button type="button" class="ul-drawer-btn-secondary" data-action="open-assign" data-role="team_leader" data-unit-id="{{ $unit->id }}" data-slot="team_leader">Assign</button>
                                        @endunless
                                    @endif
                                </div>
                            </div>
                            <div class="ul-person-row">
                                <span class="ul-person-label">Driver</span>
                                <div class="ul-person-line">
                                    @if ($unit->driver_name || $unit->driver_id)
                                        <div class="ul-person-info">
                                            <span class="ul-person-name">{{ $unit->driver?->full_name ?? $unit->driver?->name ?? $unit->driver_name }}</span>
                                            @if ($row['driver_loan'])
                                                <span class="ul-person-note">Borrowed from {{ $row['driver_loan']->fromUnit?->name }}</span>
                                            @endif
                                        </div>
                                        @unless ($isLocked)
                                            @if ($row['driver_loan'])
                                                <button type="button" class="ul-drawer-btn-secondary" data-action="return-slot" data-loan-id="{{ $row['driver_loan']->id }}">Return</button>
                                            @elseif (! $unit->driver_id)
                                                <button type="button" class="ul-drawer-btn-secondary" data-action="remove-slot" data-unit-id="{{ $unit->id }}" data-slot="driver_1">Remove</button>
                                            @endif
                                        @endunless
                                    @else
                                        <span class="ul-person-empty">Not assigned</span>
                                        @unless ($isLocked)
                                            <button type="button" class="ul-drawer-btn-secondary" data-action="open-assign" data-role="driver" data-unit-id="{{ $unit->id }}" data-slot="driver_1">Assign</button>
                                        @endunless
                                    @endif
                                </div>
                            </div>
                            <div class="ul-person-row">
                                <span class="ul-person-label">Crew 1</span>
                                <div class="ul-person-line">
                                    @if ($unit->crew_member_1_name)
                                        <div class="ul-person-info">
                                            <span class="ul-person-name">{{ $unit->crew_member_1_name }}</span>
                                            @if ($row['crew_1_loan'])
                                                <span class="ul-person-note">Borrowed from {{ $row['crew_1_loan']->fromUnit?->name }}</span>
                                            @endif
                                        </div>
                                        @unless ($isLocked)
                                            @if ($row['crew_1_loan'])
                                                <button type="button" class="ul-drawer-btn-secondary" data-action="return-slot" data-loan-id="{{ $row['crew_1_loan']->id }}">Return</button>
                                            @else
                                                <button type="button" class="ul-drawer-btn-secondary" data-action="remove-slot" data-unit-id="{{ $unit->id }}" data-slot="crew_member_1">Remove</button>
                                            @endif
                                        @endunless
                                    @else
                                        <span class="ul-person-empty">Not assigned</span>
                                        @unless ($isLocked)
                                            <button type="button" class="ul-drawer-btn-secondary" data-action="open-assign" data-role="crew" data-unit-id="{{ $unit->id }}" data-slot="crew_member_1">Assign</button>
                                        @endunless
                                    @endif
                                </div>
                            </div>
                            <div class="ul-person-row">
                                <span class="ul-person-label">Crew 2</span>
                                <div class="ul-person-line">
                                    @if ($unit->crew_member_2_name)
                                        <div class="ul-person-info">
                                            <span class="ul-person-name">{{ $unit->crew_member_2_name }}</span>
                                            @if ($row['crew_2_loan'])
                                                <span class="ul-person-note">Borrowed from {{ $row['crew_2_loan']->fromUnit?->name }}</span>
                                            @endif
                                        </div>
                                        @unless ($isLocked)
                                            @if ($row['crew_2_loan'])
                                                <button type="button" class="ul-drawer-btn-secondary" data-action="return-slot" data-loan-id="{{ $row['crew_2_loan']->id }}">Return</button>
                                            @else
                                                <button type="button" class="ul-drawer-btn-secondary" data-action="remove-slot" data-unit-id="{{ $unit->id }}" data-slot="crew_member_2">Remove</button>
                                            @endif
                                        @endunless
                                    @else
                                        <span class="ul-person-empty">Not assigned</span>
                                        @unless ($isLocked)
                                            <button type="button" class="ul-drawer-btn-secondary" data-action="open-assign" data-role="crew" data-unit-id="{{ $unit->id }}" data-slot="crew_member_2">Assign</button>
                                        @endunless
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="ul-drawer-section">
                            <h3 class="ul-drawer-section-title">Current Assignment</h3>
                            @if ($row['reservation'])
                                <div class="jobs-cell-primary">{{ $row['reservation']->booking_code }}</div>
                                <div class="jobs-cell-secondary">Reserved</div>
                            @elseif ($row['active_booking'])
                                <div class="jobs-cell-primary">{{ $row['active_booking']->booking_code }}</div>
                                <div class="jobs-cell-secondary">{{ str($row['active_booking']->status)->replace('_', ' ')->title() }}</div>
                            @else
                                <span class="jobs-cell-secondary">No current assignment</span>
                            @endif
                        </div>

                        <div class="ul-drawer-section ul-drawer-actions">
                            @if ($row['reservation'])
                                <a href="{{ route('admin.dispatch') }}" class="ul-drawer-btn-secondary">View booking</a>
                            @elseif ($row['active_booking'])
                                <a href="{{ route('admin.jobs') }}" class="ul-drawer-btn-secondary">View job</a>
                            @elseif ($tl)
                                <h3 class="ul-drawer-section-title">Team Actions</h3>
                                <p class="ul-drawer-helper">Move this unit's current team to another eligible unit.</p>
                                <button type="button" class="ul-drawer-btn-primary" data-action="open-transfer" data-unit-id="{{ $unit->id }}">Transfer team</button>
                            @else
                                <span class="jobs-cell-secondary">—</span>
                            @endif
                        </div>
                    </template>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="jobs-empty">
                                <p>No units found.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

    </div>

    {{-- Unit Details drawer (right-side) --}}
    <div class="ul-drawer-backdrop" id="ulDrawerBackdrop">
        <aside class="ul-drawer" id="ulDrawer" role="dialog" aria-modal="true" aria-label="Unit details">
            <button type="button" class="ul-drawer-close" id="ulDrawerClose" aria-label="Close">&times;</button>
            <div class="ul-drawer-body" id="ulDrawerBody"></div>
        </aside>
    </div>

    {{-- Assign dialog — reused for Team Leader / Driver / Crew --}}
    <div class="ul-modal-backdrop" id="ulAssignBackdrop">
        <div class="ul-modal-card">
            <div class="ul-modal-head">
                <h3 id="ulAssignTitle">Search Team Leader</h3>
                <button type="button" class="ul-modal-close" id="ulAssignClose" aria-label="Close">&times;</button>
            </div>
            <div class="ul-modal-body" id="ulAssignList">
                <p class="jobs-cell-secondary">Loading…</p>
            </div>
        </div>
    </div>

    {{-- Transfer Team dialog --}}
    <div class="ul-modal-backdrop" id="ulTransferBackdrop">
        <div class="ul-modal-card">
            <div class="ul-modal-head">
                <h3>Transfer Team</h3>
                <button type="button" class="ul-modal-close" id="ulTransferClose" aria-label="Close">&times;</button>
            </div>
            <div class="ul-modal-body">
                <div class="ul-filter-group">
                    <label for="ulTransferTarget">To</label>
                    <select id="ulTransferTarget"></select>
                </div>
                <div class="ul-modal-actions">
                    <button type="button" class="ul-btn ul-btn--primary" id="ulTransferConfirm">Confirm Transfer</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/drivers.js') }}"></script>
@endpush
