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

        <div class="ul-toolbar" role="search">
            <div class="ul-filter-group ul-filter-group--search">
                <label for="ulSearch">Search</label>
                <input type="text" id="ulSearch" placeholder="Search unit or personnel">
            </div>
            <div class="ul-filter-group">
                <label for="ulAvailability">Availability</label>
                <select id="ulAvailability">
                    <option value="all">All</option>
                    <option value="available">Available</option>
                    <option value="not_available">Unavailable</option>
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
            <button type="button" class="ul-clear" id="ulClearFilters" hidden>Clear</button>
        </div>

        <p class="ul-count" id="ulCount" aria-live="polite">Showing <span id="ulShownCount">{{ $rows->count() }}</span> of {{ $rows->count() }} units</p>

        <div class="jobs-table-wrap ul-table-wrap">
            <table class="jobs-table ul-table">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th>Truck Type</th>
                        <th>Team</th>
                        <th>Availability</th>
                        <th>Current Job</th>
                        <th class="ul-col-chevron"><span class="sr-only">Expand</span></th>
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

                        $reasons = collect($row['reasons'] ?? []);
                        if ($reasons->contains('maintenance')) {
                            [$statusKey, $statusLabel] = ['maintenance', 'Maintenance'];
                        } elseif ($reasons->contains('busy')) {
                            [$statusKey, $statusLabel] = ['busy', 'Busy'];
                        } elseif ($reasons->contains('reserved')) {
                            [$statusKey, $statusLabel] = ['reserved', 'Reserved'];
                        } elseif ($row['available']) {
                            [$statusKey, $statusLabel] = ['available', 'Available'];
                        } else {
                            [$statusKey, $statusLabel] = ['unavailable', 'Unavailable'];
                        }

                        $isLocked = (bool) ($row['reservation'] || $row['active_booking']);
                        $tlName = $tl?->full_name ?? $tl?->name;
                        $hasDriver = (bool) ($unit->driver_name || $unit->driver_id);
                        $driverDisplayName = $unit->driver?->full_name ?? $unit->driver?->name ?? $unit->driver_name;
                        $crewCount = collect([$unit->crew_member_1_name, $unit->crew_member_2_name])
                            ->filter(fn ($name) => filled($name))
                            ->count();
                        $tlOffDuty = $tl && $row['team_leader_duty'] === 'unavailable';
                        $tlOffline = $tl && $row['presence'] === 'offline';
                        $driverOffDuty = $hasDriver && $row['driver_duty'] === 'unavailable';
                        $tlQualifier = ($tlOffDuty ? ' · Off duty' : '') . ($tlOffline ? ' · Offline' : '');

                        $jobBooking = $row['reservation'] ?? $row['active_booking'];
                        $jobStatus = $jobBooking ? str($jobBooking->status)->replace('_', ' ')->title() : null;
                    @endphp
                    <tr class="ul-row"
                        id="ul-row-{{ $unit->id }}"
                        data-unit-id="{{ $unit->id }}"
                        data-unit-name="{{ $unit->name }}"
                        data-locked="{{ $isLocked ? '1' : '0' }}"
                        data-availability="{{ $row['available'] ? 'available' : 'not_available' }}"
                        data-presence="{{ $row['presence'] ?? 'offline' }}"
                        data-truck-type="{{ $truckClass }}"
                        data-search="{{ $searchBlob }}">
                        <td>
                            <div class="ul-primary">{{ $unit->name }}</div>
                            <div class="ul-secondary">{{ $unit->plate_number ?? '—' }}</div>
                        </td>
                        <td>
                            <span class="ul-text">{{ $truckLabel }}</span>
                        </td>
                        <td>
                            @if ($tl)
                                <div class="ul-primary">{{ $tlName }}@if ($tlQualifier !== '')<span class="ul-qualifier">{{ $tlQualifier }}</span>@endif</div>
                            @else
                                <div class="ul-primary ul-missing">No Team Leader</div>
                            @endif
                            <div class="ul-secondary">
                                @if ($hasDriver)
                                    Driver: {{ $driverDisplayName }}@if ($driverOffDuty) · Off duty @endif
                                @else
                                    <span class="ul-missing">No driver</span>
                                @endif
                                · {{ $crewCount > 0 ? $crewCount . ' crew' : 'No crew' }}
                            </div>
                        </td>
                        <td>
                            <span class="ul-status ul-status--{{ $statusKey }}">{{ $statusLabel }}</span>
                        </td>
                        <td>
                            @if ($jobBooking)
                                <div class="ul-primary">{{ $jobBooking->booking_code }}</div>
                                <div class="ul-secondary">{{ $jobStatus }}</div>
                            @else
                                <span class="ul-none" aria-hidden="true">—</span><span class="sr-only">No current job</span>
                            @endif
                        </td>
                        <td class="ul-col-chevron">
                            <button type="button"
                                    class="ul-expand"
                                    data-action="ul-toggle"
                                    data-unit-id="{{ $unit->id }}"
                                    aria-expanded="false"
                                    aria-controls="ul-detail-{{ $unit->id }}"
                                    aria-label="Show team for {{ $unit->name }}">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"></path></svg>
                            </button>
                        </td>
                    </tr>
                    <tr class="ul-detail-row" id="ul-detail-{{ $unit->id }}" data-unit-id="{{ $unit->id }}" hidden>
                        <td colspan="6">
                            <div class="ul-detail">
                                <div class="ul-detail-view">
                                    <div class="ul-detail-cols">
                                        <section class="ul-detail-col" aria-label="Assigned team">
                                            <h3 class="ul-detail-heading">Assigned Team</h3>
                                            <dl class="ul-pairs">
                                                <div class="ul-pair">
                                                    <dt>Team Leader</dt>
                                                    @if ($tl)
                                                        <dd class="ul-pair-value">{{ $tlName }}</dd>
                                                        @if ($row['team_leader_home_unit'])
                                                            <dd class="ul-pair-note">Borrowed from {{ $row['team_leader_home_unit'] }}</dd>
                                                        @endif
                                                    @else
                                                        <dd class="ul-pair-value ul-missing">Not assigned</dd>
                                                    @endif
                                                </div>
                                                <div class="ul-pair">
                                                    <dt>Driver</dt>
                                                    @if ($hasDriver)
                                                        <dd class="ul-pair-value">{{ $driverDisplayName }}</dd>
                                                        @if ($unit->slotIsLegacy('driver_1'))
                                                            <dd class="ul-pair-note">Not in Personnel - cannot be reassigned</dd>
                                                        @endif
                                                        @if ($row['driver_loan'])
                                                            <dd class="ul-pair-note">Borrowed from {{ $row['driver_loan']->fromUnit?->name }}</dd>
                                                        @endif
                                                    @else
                                                        <dd class="ul-pair-value ul-missing">Not assigned</dd>
                                                    @endif
                                                </div>
                                                <div class="ul-pair">
                                                    <dt>Crew 1</dt>
                                                    @if ($unit->crew_member_1_name)
                                                        <dd class="ul-pair-value">{{ $unit->crew_member_1_name }}</dd>
                                                        @if ($unit->slotIsLegacy('crew_member_1'))
                                                            <dd class="ul-pair-note">Not in Personnel - cannot be reassigned</dd>
                                                        @endif
                                                        @if ($row['crew_1_loan'])
                                                            <dd class="ul-pair-note">Borrowed from {{ $row['crew_1_loan']->fromUnit?->name }}</dd>
                                                        @endif
                                                    @else
                                                        <dd class="ul-pair-value ul-pair-value--neutral">Not assigned</dd>
                                                    @endif
                                                </div>
                                                <div class="ul-pair">
                                                    <dt>Crew 2</dt>
                                                    @if ($unit->crew_member_2_name)
                                                        <dd class="ul-pair-value">{{ $unit->crew_member_2_name }}</dd>
                                                        @if ($unit->slotIsLegacy('crew_member_2'))
                                                            <dd class="ul-pair-note">Not in Personnel - cannot be reassigned</dd>
                                                        @endif
                                                        @if ($row['crew_2_loan'])
                                                            <dd class="ul-pair-note">Borrowed from {{ $row['crew_2_loan']->fromUnit?->name }}</dd>
                                                        @endif
                                                    @else
                                                        <dd class="ul-pair-value ul-pair-value--neutral">Not assigned</dd>
                                                    @endif
                                                </div>
                                            </dl>
                                        </section>
                                        <section class="ul-detail-col" aria-label="Operations">
                                            <h3 class="ul-detail-heading">Operations</h3>
                                            <dl class="ul-ops">
                                                <dt>Presence</dt>
                                                <dd>{{ $row['presence'] ? ucfirst($row['presence']) : '—' }}</dd>
                                                <dt>Leader duty</dt>
                                                <dd>
                                                    @if ($tl)
                                                        {{ ucfirst($row['team_leader_duty']) }}
                                                        @unless ($row['workload'] === 'busy')
                                                            <button type="button" class="ul-text-btn" data-action="toggle-tl-duty" data-tl-id="{{ $tl->id }}" data-current="{{ $row['team_leader_duty'] }}">{{ $row['team_leader_duty'] === 'available' ? 'Set unavailable' : 'Set available' }}</button>
                                                        @endunless
                                                    @else
                                                        —
                                                    @endif
                                                </dd>
                                                <dt>Driver duty</dt>
                                                <dd>{{ $hasDriver ? ucfirst($row['driver_duty']) : '—' }}</dd>
                                            </dl>
                                        </section>
                                    </div>

                                    <div class="ul-detail-actions">
                                        @if ($row['reservation'])
                                            <span class="ul-lock-msg">Locked while reserved for {{ $row['reservation']->booking_code }}. Team changes are disabled.</span>
                                        @elseif ($row['active_booking'])
                                            <span class="ul-lock-msg">Locked while on job {{ $row['active_booking']->booking_code }}. Team changes are disabled.</span>
                                        @else
                                            <button type="button" class="ul-btn ul-btn--primary" data-action="ul-edit" data-unit-id="{{ $unit->id }}">Manage team</button>
                                        @endif
                                        <span class="ul-spacer"></span>
                                        @if ($row['reservation'])
                                            <a href="{{ route('admin.dispatch') }}" class="ul-btn">View booking</a>
                                        @elseif ($row['active_booking'])
                                            <a href="{{ route('admin.jobs') }}" class="ul-btn">View job</a>
                                        @elseif ($tl)
                                            <button type="button" class="ul-btn" data-action="open-transfer" data-unit-id="{{ $unit->id }}" title="Move this unit's current team to another eligible unit.">Transfer team</button>
                                        @endif
                                    </div>
                                </div>

                                @unless ($isLocked)
                                    <div class="ul-detail-edit" hidden>
                                        <table class="ul-edit-table">
                                            <caption>Manage team — {{ $unit->name }}</caption>
                                            <thead>
                                                <tr>
                                                    <th scope="col">Role</th>
                                                    <th scope="col">Assigned</th>
                                                    <th scope="col" class="ul-edit-action">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <th scope="row">Team Leader</th>
                                                    <td>
                                                        @if ($tl)
                                                            <span class="ul-edit-name">{{ $tlName }}</span>
                                                            @if ($row['team_leader_home_unit'])
                                                                <span class="ul-edit-note">· Borrowed from {{ $row['team_leader_home_unit'] }}</span>
                                                            @endif
                                                        @else
                                                            <span class="ul-missing">Not assigned</span>
                                                        @endif
                                                    </td>
                                                    <td class="ul-edit-action">
                                                        @if ($tl)
                                                            @if ($row['team_leader_home_unit'])
                                                                <button type="button" class="ul-btn ul-btn--sm" data-action="return-team-leader" data-unit-id="{{ $unit->id }}" data-person-name="{{ $tlName }}" data-home-unit-name="{{ $row['team_leader_home_unit'] }}">Return</button>
                                                            @else
                                                                <button type="button" class="ul-btn ul-btn--sm" data-action="remove-team-leader" data-unit-id="{{ $unit->id }}" data-person-name="{{ $tlName }}" data-unit-name="{{ $unit->name }}">Remove</button>
                                                            @endif
                                                        @else
                                                            <button type="button" class="ul-btn ul-btn--sm" data-action="open-assign" data-role="team_leader" data-unit-id="{{ $unit->id }}" data-slot="team_leader">Assign</button>
                                                        @endif
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th scope="row">Driver</th>
                                                    <td>
                                                        @if ($hasDriver)
                                                            <span class="ul-edit-name">{{ $driverDisplayName }}</span>
                                                            @if ($unit->slotIsLegacy('driver_1'))
                                                                <span class="ul-edit-note">· Not in Personnel - cannot be reassigned</span>
                                                            @endif
                                                            @if ($row['driver_loan'])
                                                                <span class="ul-edit-note">· Borrowed from {{ $row['driver_loan']->fromUnit?->name }}</span>
                                                            @endif
                                                        @else
                                                            <span class="ul-missing">Not assigned</span>
                                                        @endif
                                                    </td>
                                                    <td class="ul-edit-action">
                                                        @if ($hasDriver)
                                                            @if ($row['driver_loan'])
                                                                <button type="button" class="ul-btn ul-btn--sm" data-action="return-slot" data-loan-id="{{ $row['driver_loan']->id }}" data-slot="driver_1" data-person-name="{{ $driverDisplayName }}" data-home-unit-name="{{ $row['driver_loan']->fromUnit?->name }}">Return</button>
                                                            @elseif (! $unit->driver_id)
                                                                <button type="button" class="ul-btn ul-btn--sm" data-action="remove-slot" data-unit-id="{{ $unit->id }}" data-slot="driver_1" data-person-name="{{ $driverDisplayName }}" data-unit-name="{{ $unit->name }}">Remove</button>
                                                            @endif
                                                        @else
                                                            <button type="button" class="ul-btn ul-btn--sm" data-action="open-assign" data-role="driver" data-unit-id="{{ $unit->id }}" data-slot="driver_1">Assign</button>
                                                        @endif
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th scope="row">Crew 1</th>
                                                    <td>
                                                        @if ($unit->crew_member_1_name)
                                                            <span class="ul-edit-name">{{ $unit->crew_member_1_name }}</span>
                                                            @if ($unit->slotIsLegacy('crew_member_1'))
                                                                <span class="ul-edit-note">· Not in Personnel - cannot be reassigned</span>
                                                            @endif
                                                            @if ($row['crew_1_loan'])
                                                                <span class="ul-edit-note">· Borrowed from {{ $row['crew_1_loan']->fromUnit?->name }}</span>
                                                            @endif
                                                        @else
                                                            <span class="ul-edit-neutral">Not assigned</span>
                                                        @endif
                                                    </td>
                                                    <td class="ul-edit-action">
                                                        @if ($unit->crew_member_1_name)
                                                            @if ($row['crew_1_loan'])
                                                                <button type="button" class="ul-btn ul-btn--sm" data-action="return-slot" data-loan-id="{{ $row['crew_1_loan']->id }}" data-slot="crew_member_1" data-person-name="{{ $unit->crew_member_1_name }}" data-home-unit-name="{{ $row['crew_1_loan']->fromUnit?->name }}">Return</button>
                                                            @else
                                                                <button type="button" class="ul-btn ul-btn--sm" data-action="remove-slot" data-unit-id="{{ $unit->id }}" data-slot="crew_member_1" data-person-name="{{ $unit->crew_member_1_name }}" data-unit-name="{{ $unit->name }}">Remove</button>
                                                            @endif
                                                        @else
                                                            <button type="button" class="ul-btn ul-btn--sm" data-action="open-assign" data-role="crew" data-unit-id="{{ $unit->id }}" data-slot="crew_member_1">Assign</button>
                                                        @endif
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th scope="row">Crew 2</th>
                                                    <td>
                                                        @if ($unit->crew_member_2_name)
                                                            <span class="ul-edit-name">{{ $unit->crew_member_2_name }}</span>
                                                            @if ($unit->slotIsLegacy('crew_member_2'))
                                                                <span class="ul-edit-note">· Not in Personnel - cannot be reassigned</span>
                                                            @endif
                                                            @if ($row['crew_2_loan'])
                                                                <span class="ul-edit-note">· Borrowed from {{ $row['crew_2_loan']->fromUnit?->name }}</span>
                                                            @endif
                                                        @else
                                                            <span class="ul-edit-neutral">Not assigned</span>
                                                        @endif
                                                    </td>
                                                    <td class="ul-edit-action">
                                                        @if ($unit->crew_member_2_name)
                                                            @if ($row['crew_2_loan'])
                                                                <button type="button" class="ul-btn ul-btn--sm" data-action="return-slot" data-loan-id="{{ $row['crew_2_loan']->id }}" data-slot="crew_member_2" data-person-name="{{ $unit->crew_member_2_name }}" data-home-unit-name="{{ $row['crew_2_loan']->fromUnit?->name }}">Return</button>
                                                            @else
                                                                <button type="button" class="ul-btn ul-btn--sm" data-action="remove-slot" data-unit-id="{{ $unit->id }}" data-slot="crew_member_2" data-person-name="{{ $unit->crew_member_2_name }}" data-unit-name="{{ $unit->name }}">Remove</button>
                                                            @endif
                                                        @else
                                                            <button type="button" class="ul-btn ul-btn--sm" data-action="open-assign" data-role="crew" data-unit-id="{{ $unit->id }}" data-slot="crew_member_2">Assign</button>
                                                        @endif
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <div class="ul-detail-actions ul-detail-actions--edit">
                                            <span class="ul-lock-msg">Each change is confirmed, then saved right away.</span>
                                            <span class="ul-spacer"></span>
                                            <button type="button" class="ul-btn ul-btn--primary" data-action="ul-edit-done" data-unit-id="{{ $unit->id }}">Done</button>
                                        </div>
                                    </div>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="jobs-empty">
                                <p>No units found.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                <tr class="ul-filter-empty" id="ulFilterEmpty" hidden>
                    <td colspan="6">
                        <div class="jobs-empty">
                            <p>No units match these filters.</p>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

    </div>

    <div class="ul-modal-backdrop" id="ulAssignBackdrop">
        <div class="ul-modal-card ul-modal-card--wide" role="dialog" aria-modal="true" aria-labelledby="ulAssignTitle">
            <div class="ul-modal-head">
                <h3 id="ulAssignTitle">Search Team Leader</h3>
                <button type="button" class="ul-modal-close" id="ulAssignClose" aria-label="Close">&times;</button>
            </div>
            <div class="ul-modal-body" id="ulAssignList">
                <p class="ul-modal-text">Loading…</p>
            </div>
        </div>
    </div>

    <div class="ul-modal-backdrop" id="ulTransferBackdrop">
        <div class="ul-modal-card" role="dialog" aria-modal="true" aria-labelledby="ulTransferTitle">
            <div class="ul-modal-head">
                <h3 id="ulTransferTitle">Transfer Team</h3>
                <button type="button" class="ul-modal-close" id="ulTransferClose" aria-label="Close">&times;</button>
            </div>
            <div class="ul-modal-body">
                <p class="ul-modal-text">Move this unit's current team to another eligible unit.</p>
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

    <div class="ul-modal-backdrop" id="ulConfirmBackdrop">
        <div class="ul-modal-card ul-modal-card--sm" role="dialog" aria-modal="true" aria-labelledby="ulConfirmTitle">
            <div class="ul-modal-head">
                <h3 id="ulConfirmTitle">Confirm</h3>
                <button type="button" class="ul-modal-close" id="ulConfirmClose" aria-label="Close">&times;</button>
            </div>
            <div class="ul-modal-body">
                <p class="ul-confirm-body" id="ulConfirmBody"></p>
                <div class="ul-modal-actions">
                    <button type="button" class="ul-btn" id="ulConfirmCancel">Cancel</button>
                    <button type="button" class="ul-btn ul-btn--primary" id="ulConfirmOk">Confirm</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/drivers.js') }}"></script>
@endpush
