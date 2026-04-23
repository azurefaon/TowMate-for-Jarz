@extends('admin-dashboard.layouts.app')

@section('title', 'Team Leaders')

@section('content')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/drivers.css') }}">

    @php
        $leaderStates = collect($teamLeaderStatuses ?? []);
        $totalLeaders = $teamLeaders->count();
        $onlineCount = $onlineTeamLeadersCount ?? $leaderStates->where('presence', 'online')->count();
        $offlineCount = $offlineTeamLeadersCount ?? $leaderStates->where('presence', 'offline')->count();
        $busyCount = $leaderStates->where('workload', 'busy')->count();
        $defaultFilter = $onlineCount > 0 ? 'online' : 'all';

        $readyQueue = $teamLeaders
            ->filter(function ($tl) use ($teamLeaderStatuses) {
                $s = $teamLeaderStatuses->get($tl->id) ?? [];
                return ($s['presence'] ?? 'offline') === 'online' && ($s['workload'] ?? '') === 'available';
            })
            ->values();

        $standbyQueue = $teamLeaders
            ->filter(function ($tl) use ($teamLeaderStatuses) {
                $s = $teamLeaderStatuses->get($tl->id) ?? [];
                return ($s['presence'] ?? 'offline') === 'online' && ($s['workload'] ?? '') === 'idle';
            })
            ->values();

        $activeQueue = $teamLeaders
            ->filter(function ($tl) use ($teamLeaderStatuses) {
                $s = $teamLeaderStatuses->get($tl->id) ?? [];
                return ($s['workload'] ?? '') === 'busy' || ($s['unit_status'] ?? '') === 'on_job';
            })
            ->values();

        $unavailableQueue = $teamLeaders
            ->filter(function ($tl) use ($teamLeaderStatuses) {
                $s = $teamLeaderStatuses->get($tl->id) ?? [];
                return ($s['presence'] ?? 'offline') !== 'online' || ($s['workload'] ?? '') === 'unavailable';
            })
            ->values();

        $zoneSummary = collect();
        foreach ($teamLeaders as $tl) {
            $s = $teamLeaderStatuses->get($tl->id) ?? [];
            $zn = $s['zone_name'] ?? ($tl->unit?->zone?->name ?? null);
            if (!$zn) {
                continue;
            }
            if (!$zoneSummary->has($zn)) {
                $zoneSummary->put($zn, ['avail' => 0, 'busy' => 0, 'unavail' => 0]);
            }
            $entry = $zoneSummary->get($zn);
            $wl = $s['workload'] ?? 'unavailable';
            $pr = $s['presence'] ?? 'offline';
            if ($wl === 'busy') {
                $entry['busy']++;
            } elseif ($pr === 'online') {
                $entry['avail']++;
            } else {
                $entry['unavail']++;
            }
            $zoneSummary->put($zn, $entry);
        }

        $zoneList = $teamLeaders
            ->map(function ($tl) use ($teamLeaderStatuses) {
                $s = $teamLeaderStatuses->get($tl->id) ?? [];
                return $s['zone_name'] ?? ($tl->unit?->zone?->name ?? null);
            })
            ->filter()
            ->unique()
            ->values();
    @endphp

    {{-- Confirm modal --}}
    <div id="tlConfirmModal" style="display:none;position:fixed;inset:0;z-index:10000;align-items:center;justify-content:center;background:rgba(15,23,42,.45);backdrop-filter:blur(2px);" aria-modal="true" role="dialog" aria-labelledby="tlConfirmTitle">
        <div style="background:#fff;border-radius:18px;padding:28px 28px 24px;max-width:400px;width:90%;box-shadow:0 24px 60px rgba(0,0,0,.18);">
            <div style="width:44px;height:44px;border-radius:12px;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                <svg width="22" height="22" fill="none" stroke="#dc2626" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </div>
            <h3 id="tlConfirmTitle" style="margin:0 0 6px;font-size:1rem;font-weight:700;color:#0f172a;">Remove Unit</h3>
            <p id="tlConfirmBody" style="margin:0 0 22px;font-size:.88rem;color:#64748b;line-height:1.5;">Are you sure you want to remove the assigned unit from this team leader?</p>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button id="tlConfirmCancel" type="button" style="padding:9px 18px;border-radius:9px;border:1px solid #e5e7eb;background:#fff;color:#374151;font-size:.88rem;font-weight:600;cursor:pointer;">Cancel</button>
                <button id="tlConfirmOk" type="button" style="padding:9px 18px;border-radius:9px;border:none;background:#dc2626;color:#fff;font-size:.88rem;font-weight:600;cursor:pointer;">Remove</button>
            </div>
        </div>
    </div>

    <div id="tlToast" class="tl-toast" role="alert" aria-live="polite">
        <span id="tlToastMsg"></span>
    </div>

    <div class="drivers-container">

        @if (session('success'))
            <div class="drivers-feedback drivers-feedback--success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="drivers-feedback drivers-feedback--error">{{ $errors->first() }}</div>
        @endif

        <div class="drivers-header">
            <div>
                <p class="drivers-eyebrow">Dispatcher Panel</p>
                <h1 class="drivers-title">Team Leaders</h1>
            </div>
            <span class="drivers-total">{{ $totalLeaders }} total</span>
        </div>

        {{-- Stat strip --}}
        <div class="tl-stat-strip">
            <div class="tl-stat-card">
                <div class="tl-stat-icon tl-stat-icon--total">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                </div>
                <div>
                    <div class="tl-stat-val">{{ $totalLeaders }}</div>
                    <div class="tl-stat-lbl">Total</div>
                </div>
            </div>
            <div class="tl-stat-card">
                <div class="tl-stat-icon tl-stat-icon--online">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
                    </svg>
                </div>
                <div>
                    <div class="tl-stat-val">{{ $onlineCount }}</div>
                    <div class="tl-stat-lbl">Online</div>
                </div>
            </div>
            <div class="tl-stat-card">
                <div class="tl-stat-icon tl-stat-icon--busy">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <rect x="1" y="3" width="15" height="13" rx="2" />
                        <path d="M16 8h4l3 3v5h-7V8z" />
                        <circle cx="5.5" cy="18.5" r="2.5" />
                        <circle cx="18.5" cy="18.5" r="2.5" />
                    </svg>
                </div>
                <div>
                    <div class="tl-stat-val">{{ $busyCount }}</div>
                    <div class="tl-stat-lbl">Deployed</div>
                </div>
            </div>
            <div class="tl-stat-card">
                <div class="tl-stat-icon tl-stat-icon--offline">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="4.93" y1="4.93" x2="19.07" y2="19.07" />
                    </svg>
                </div>
                <div>
                    <div class="tl-stat-val">{{ $offlineCount }}</div>
                    <div class="tl-stat-lbl">Offline</div>
                </div>
            </div>
        </div>

        <div class="tl-page-layout">

            {{-- LEFT: Table --}}
            <div>
                <div class="tl-toolbar">
                    <div class="tl-toolbar-left">
                        <label class="tl-search">
                            <svg width="14" height="14" fill="none" stroke="#94a3b8" stroke-width="2"
                                viewBox="0 0 24 24">
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg>
                            <input type="text" id="tlSearch" placeholder="Search team leader…" autocomplete="off">
                        </label>
                        <div role="group" aria-label="Filter">
                            <button type="button" class="filter-btn {{ $defaultFilter === 'all' ? 'is-active' : '' }}"
                                data-filter="all">All</button>
                            <button type="button" class="filter-btn {{ $defaultFilter === 'online' ? 'is-active' : '' }}"
                                data-filter="online">Online</button>
                            <button type="button" class="filter-btn" data-filter="offline">Offline</button>
                        </div>
                        @if ($zoneList->isNotEmpty())
                            <select id="zoneFilterSelect" class="tl-zone-filter" aria-label="Filter by zone">
                                <option value="">All Zones</option>
                                @foreach ($zoneList as $zn)
                                    <option value="{{ $zn }}">{{ $zn }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                </div>

                <div class="tl-table-card">
                    <table class="tl-table">
                        <thead>
                            <tr>
                                <th>Team Leader</th>
                                <th>Zone</th>
                                <th>Status</th>
                                <th>Assigned Unit</th>
                                <th>Driver</th>
                                <th>Updated By</th>
                            </tr>
                        </thead>
                        <tbody id="tlTableBody">
                            @forelse($teamLeaders as $teamLeader)
                                @php
                                    $s = $teamLeaderStatuses->get($teamLeader->id) ?? [];
                                    $isOnline = ($s['presence'] ?? 'offline') === 'online';
                                    $hasUnit = !empty($s['unit_name']) && $s['unit_name'] !== 'No assigned unit';
                                    $workload = $s['workload'] ?? 'unavailable';
                                    $unitStatus = $s['unit_status'] ?? null;
                                    $zoneName = $s['zone_name'] ?? ($teamLeader->unit?->zone?->name ?? null);
                                    $zoneConfirmed = $s['zone_confirmed'] ?? false;

                                    // Offline always forces Not Available
                                    if (!$isOnline) {
                                        $statusLabel = 'Not Available';
                                        $pillCls     = 'tl-pill--not-avail';
                                        $rowStatus   = 'not-avail';
                                        $subtext     = 'Offline — not reachable';
                                        $forcedOff   = true;
                                    } elseif ($unitStatus === 'on_tow') {
                                        $statusLabel = 'On Tow';
                                        $pillCls     = 'tl-pill--on-tow';
                                        $rowStatus   = 'on-tow';
                                        $subtext     = 'Unit is towing a vehicle';
                                        $forcedOff   = false;
                                    } elseif ($workload === 'idle') {
                                        $statusLabel = 'Idle';
                                        $pillCls     = 'tl-pill--idle';
                                        $rowStatus   = 'idle';
                                        $subtext     = 'Online — waiting for unit';
                                        $forcedOff   = false;
                                    } elseif ($workload === 'busy') {
                                        $statusLabel = 'Deployed';
                                        $pillCls     = 'tl-pill--deployed';
                                        $rowStatus   = 'deployed';
                                        $subtext     = 'Currently on a job';
                                        $forcedOff   = false;
                                    } elseif ($workload === 'unavailable') {
                                        $statusLabel = 'Not Available';
                                        $pillCls     = 'tl-pill--not-avail';
                                        $rowStatus   = 'not-avail';
                                        $subtext     = 'Marked not available';
                                        $forcedOff   = false;
                                    } else {
                                        $statusLabel = 'Available';
                                        $pillCls     = 'tl-pill--available';
                                        $rowStatus   = 'available';
                                        $subtext     = 'Ready for assignment';
                                        $forcedOff   = false;
                                    }

                                    // Effective unit_status value for the select
                                    // Standby = online but no unit, so no dispatcher_status to show
                                    $selectValue = $forcedOff
                                        ? 'unavailable'
                                        : $unitStatus ?? ($workload === 'standby' ? '' : 'available');

                                    // Status select disabled when: offline OR idle (no unit to set status on)
                                    $statusSelectDisabled = !$isOnline || $workload === 'idle';

                                    // Unit select locked when: offline, unavailable, on_tow, on_job
                                    $unitSelectLocked =
                                        !$isOnline || in_array($selectValue, ['unavailable', 'on_tow', 'on_job']);

                                    // Show remove button only when TL has a unit and status allows it
                                    $canRemoveUnit = $hasUnit && $isOnline && !in_array($selectValue, ['on_tow', 'on_job']);

                                    $parts = explode(' ', trim($teamLeader->name));
                                    $initials = strtoupper(
                                        substr($parts[0], 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''),
                                    );
                                @endphp
                                <tr data-driver-id="{{ $teamLeader->id }}"
                                    data-presence="{{ $s['presence'] ?? 'offline' }}"
                                    data-workload="{{ $workload }}"
                                    data-zone="{{ $zoneName ?? '' }}"
                                    data-name="{{ strtolower($teamLeader->name) }}"
                                    data-status="{{ $rowStatus }}">
                                    <td>
                                        <div class="tl-name-cell">
                                            <div class="tl-avatar">{{ $initials }}</div>
                                            <div>
                                                <div class="tl-name">{{ $teamLeader->name }}</div>
                                                <div
                                                    class="tl-presence tl-presence--{{ $isOnline ? 'online' : 'offline' }}">
                                                    {{ $isOnline ? 'Online' : 'Offline' }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="tl-zone-badge {{ $zoneName ? '' : 'tl-zone-badge--none' }}">
                                            {{ $zoneName ?? 'No Zone' }}
                                        </span>
                                        @if ($zoneName)
                                            <span class="tl-zone-confirm"
                                                title="{{ $zoneConfirmed ? 'Confirmed' : 'Unconfirmed' }}">
                                                {{ $zoneConfirmed ? '✓' : '!' }}
                                            </span>
                                        @endif
                                    </td>

                                    <td>
                                        <span class="tl-status-pill {{ $pillCls }}">{{ $statusLabel }}</span>
                                        <div class="tl-subtext" id="statusSub-{{ $teamLeader->id }}">{{ $subtext }}</div>
                                        <div style="margin-top:6px;display:flex;align-items:center;gap:7px;">
                                            <select class="tl-select tl-status-select"
                                                data-tlid="{{ $teamLeader->id }}"
                                                data-current="{{ $selectValue }}"
                                                data-online="{{ $isOnline ? '1' : '0' }}"
                                                {{ $statusSelectDisabled ? 'disabled' : '' }}
                                                aria-label="Override status for {{ $teamLeader->name }}">
                                                <option value="available"   {{ $selectValue === 'available'   ? 'selected':'' }}>Available</option>
                                                <option value="unavailable" {{ $selectValue === 'unavailable' ? 'selected':'' }}>Not Available</option>
                                                <option value="on_tow"      {{ $selectValue === 'on_tow'      ? 'selected':'' }}>On Tow</option>
                                                <option value="on_job"      {{ $selectValue === 'on_job'      ? 'selected':'' }}>On Job</option>
                                            </select>
                                            <span class="tl-saving" id="statusSaving-{{ $teamLeader->id }}">Saving</span>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="tl-unit-cell">
                                            <div class="tl-unit-row">
                                                <select class="tl-select tl-unit-select"
                                                    data-tlid="{{ $teamLeader->id }}"
                                                    data-status="{{ $selectValue }}"
                                                    {{ $unitSelectLocked ? 'disabled' : '' }}
                                                    aria-label="Assign unit to {{ $teamLeader->name }}">
                                                    <option value="">— Assign unit —</option>
                                                    @foreach ($assignableUnits as $unit)
                                                        <option value="{{ $unit->id }}"
                                                            {{ ($teamLeader->unit?->id ?? null) == $unit->id ? 'selected' : '' }}>
                                                            {{ $unit->name }} ({{ $unit->plate_number }}){{ $unit->status !== 'available' ? ' [' . $unit->status . ']' : '' }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <span class="tl-saving" id="unitSaving-{{ $teamLeader->id }}">Saving</span>
                                            </div>
                                            @if ($unitSelectLocked && $isOnline)
                                                <div class="tl-subtext">Locked while {{ $statusLabel }}</div>
                                            @endif
                                            @if ($canRemoveUnit)
                                                <div>
                                                    <button type="button" class="tl-remove-unit-btn" data-tlid="{{ $teamLeader->id }}">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                        Remove unit
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                    </td>

                                    <td>
                                        <span class="tl-driver-name" id="driverName-{{ $teamLeader->id }}">
                                            {{ $s['driver_name'] ?? (optional(optional($teamLeader->unit)->driver)->name ?? '—') }}
                                        </span>
                                    </td>

                                    <td class="tl-meta-cell">
                                        {{ $s['last_updated_by'] ?? '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="tl-empty">No team leaders found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- RIGHT: Sidebar --}}
            <aside class="tl-sidebar">

                @if ($zoneSummary->isNotEmpty())
                    <div class="tl-sidebar-card">
                        <div class="tl-sidebar-card__head">
                            <h3>Zone Overview</h3>
                            <span class="tl-sidebar-count">{{ $zoneSummary->count() }}</span>
                        </div>
                        @foreach ($zoneSummary as $zn => $counts)
                            <div class="tl-zone-row">
                                <span class="tl-zone-row-name">{{ $zn }}</span>
                                <div class="tl-zone-pills">
                                    @if ($counts['avail'] > 0)
                                        <span class="tl-zpill tl-zpill--avail">{{ $counts['avail'] }} avail</span>
                                    @endif
                                    @if ($counts['busy'] > 0)
                                        <span class="tl-zpill tl-zpill--busy">{{ $counts['busy'] }} busy</span>
                                    @endif
                                    @if ($counts['unavail'] > 0)
                                        <span class="tl-zpill tl-zpill--unavail">{{ $counts['unavail'] }} out</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="tl-sidebar-card">
                    <div class="tl-sidebar-card__head">
                        <h3>Online &amp; Ready</h3><span class="tl-sidebar-count">{{ $readyQueue->count() }}</span>
                    </div>
                    <div class="tl-queue-list">
                        @forelse($readyQueue as $ql)
                            @php $qs = $teamLeaderStatuses->get($ql->id) ?? []; @endphp
                            <div class="tl-queue-item">
                                <strong>{{ $ql->name }}</strong>
                                <span>{{ $qs['unit_name'] ?? 'No unit' }}{{ !empty($qs['zone_name']) ? ' · ' . $qs['zone_name'] : '' }}</span>
                            </div>
                        @empty
                            <p class="tl-queue-empty">No leaders ready.</p>
                        @endforelse
                    </div>
                </div>

                <div class="tl-sidebar-card">
                    <div class="tl-sidebar-card__head">
                        <h3>Idle</h3><span class="tl-sidebar-count">{{ $standbyQueue->count() }}</span>
                    </div>
                    <div class="tl-queue-list">
                        @forelse($standbyQueue as $ql)
                            <div class="tl-queue-item">
                                <strong>{{ $ql->name }}</strong>
                                <span>Online — waiting for unit assignment</span>
                            </div>
                        @empty
                            <p class="tl-queue-empty">No leaders idle.</p>
                        @endforelse
                    </div>
                </div>

                <div class="tl-sidebar-card">
                    <div class="tl-sidebar-card__head">
                        <h3>Deployed</h3><span class="tl-sidebar-count">{{ $activeQueue->count() }}</span>
                    </div>
                    <div class="tl-queue-list">
                        @forelse($activeQueue as $ql)
                            @php $qs = $teamLeaderStatuses->get($ql->id) ?? []; @endphp
                            <div class="tl-queue-item">
                                <strong>{{ $ql->name }}</strong>
                                <span>{{ $qs['unit_name'] ?? 'No unit' }} ·
                                    {{ $qs['unit_status_label'] ?? 'On Job' }}{{ !empty($qs['zone_name']) ? ' · ' . $qs['zone_name'] : '' }}</span>
                            </div>
                        @empty
                            <p class="tl-queue-empty">No active crews.</p>
                        @endforelse
                    </div>
                </div>

                <div class="tl-sidebar-card">
                    <div class="tl-sidebar-card__head">
                        <h3>Unavailable</h3><span class="tl-sidebar-count">{{ $unavailableQueue->count() }}</span>
                    </div>
                    <div class="tl-queue-list">
                        @forelse($unavailableQueue as $ql)
                            @php $qs = $teamLeaderStatuses->get($ql->id) ?? []; @endphp
                            <div class="tl-queue-item">
                                <strong>{{ $ql->name }}</strong>
                                <span>{{ $qs['presence_label'] ?? 'Offline' }} ·
                                    {{ $qs['operational_status_label'] ?? 'Not Available' }}</span>
                            </div>
                        @empty
                            <p class="tl-queue-empty">All leaders are ready.</p>
                        @endforelse
                    </div>
                </div>

            </aside>
        </div>
    </div>

    <script>
        (function() {
            const CSRF = document.querySelector('meta[name="csrf-token"]').content;

            const STATUS_SUB = {
                available: 'Ready for assignment',
                unavailable: 'Marked not available',
                on_tow: 'Unit is towing a vehicle',
                on_job: 'Currently on a job',
            };

            /* ── Toast ── */
            let toastTimer;

            function toast(msg, type) {
                const el = document.getElementById('tlToast');
                el.className = 'tl-toast tl-toast--' + (type || 'success');
                document.getElementById('tlToastMsg').textContent = msg;
                el.classList.add('show');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function() {
                    el.classList.remove('show');
                }, 3500);
            }

            function setSaving(id, on) {
                var el = document.getElementById(id);
                if (el) el.classList.toggle('show', on);
            }

            /* ── Status override ── */
            document.querySelectorAll('.tl-status-select').forEach(function(sel) {
                // If offline on load, ensure disabled
                if (sel.dataset.online === '0') {
                    sel.disabled = true;
                    sel.value = 'unavailable';
                }

                sel.addEventListener('change', function() {
                    var tlid = sel.dataset.tlid;
                    var value = sel.value;
                    var subEl = document.getElementById('statusSub-' + tlid);

                    setSaving('statusSaving-' + tlid, true);
                    sel.disabled = true;

                    var fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('_method', 'PATCH');
                    fd.append('unit_status', value);

                    fetch('/admin-dashboard/drivers/' + tlid + '/override', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json'
                            },
                            body: fd
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            setSaving('statusSaving-' + tlid, false);
                            sel.disabled = (sel.dataset.online === '0');

                            if (data.errors) {
                                toast(typeof data.errors === 'string' ? data.errors : Object.values(
                                    data.errors)[0], 'error');
                                sel.value = sel.dataset.current;
                                return;
                            }

                            sel.dataset.current = value;
                            if (subEl) subEl.textContent = STATUS_SUB[value] || '';

                            // Lock or unlock the unit select based on new status
                            var unitSel = document.querySelector('.tl-unit-select[data-tlid="' +
                                tlid + '"]');
                            if (unitSel) {
                                var locked = ['unavailable', 'on_tow', 'on_job'].indexOf(value) !==
                                    -1;
                                unitSel.disabled = locked;
                                unitSel.dataset.status = value;
                            }

                            // If unit was released (unavailable), reset the unit dropdown
                            if (data.unit_released) {
                                var unitSel = document.querySelector('.tl-unit-select[data-tlid="' +
                                    tlid + '"]');
                                if (unitSel) unitSel.value = '';
                                var driverEl = document.getElementById('driverName-' + tlid);
                                if (driverEl) driverEl.textContent = '\u2014';
                                // Update the row's data-presence to reflect no unit
                                var row = sel.closest('tr');
                                if (row) row.dataset.workload = 'unavailable';
                                toast('Status set to Not Available — unit released.');
                            } else {
                                toast('Status saved.');
                            }
                        })
                        .catch(function() {
                            setSaving('statusSaving-' + tlid, false);
                            sel.disabled = (sel.dataset.online === '0');
                            sel.value = sel.dataset.current;
                            toast('Failed to save status.', 'error');
                        });
                });
            });

            /* ── Assign unit ── */
            document.querySelectorAll('.tl-unit-select').forEach(function(sel) {
                sel.addEventListener('change', function() {
                    var tlid = sel.dataset.tlid;
                    var unitId = sel.value;
                    if (!unitId) return;

                    setSaving('unitSaving-' + tlid, true);
                    sel.disabled = true;

                    var fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('unit_id', unitId);

                    fetch('/admin-dashboard/drivers/' + tlid + '/assign-unit', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json'
                            },
                            body: fd
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            setSaving('unitSaving-' + tlid, false);
                            sel.disabled = false;

                            if (data.errors) {
                                toast(typeof data.errors === 'string' ? data.errors : Object.values(
                                    data.errors)[0], 'error');
                                sel.value = '';
                                return;
                            }
                            var driverEl = document.getElementById('driverName-' + tlid);
                            if (driverEl && data.assigned_unit && data.assigned_unit.driver_name) {
                                driverEl.textContent = data.assigned_unit.driver_name;
                            }
                            toast(data.message || 'Unit assigned.');
                        })
                        .catch(function() {
                            setSaving('unitSaving-' + tlid, false);
                            sel.disabled = false;
                            toast('Failed to assign unit.', 'error');
                        });
                });
            });

            /* ── Confirm modal ── */
            var _confirmResolve = null;
            var confirmModal    = document.getElementById('tlConfirmModal');
            var confirmOk       = document.getElementById('tlConfirmOk');
            var confirmCancel   = document.getElementById('tlConfirmCancel');
            var confirmBody     = document.getElementById('tlConfirmBody');

            function showConfirm(message) {
                return new Promise(function(resolve) {
                    _confirmResolve = resolve;
                    confirmBody.textContent = message;
                    confirmModal.hidden = false;
                    confirmModal.style.display = 'flex';
                    confirmOk.focus();
                });
            }
            function closeConfirm(result) {
                confirmModal.hidden = true;
                confirmModal.style.display = 'none';
                if (_confirmResolve) { _confirmResolve(result); _confirmResolve = null; }
            }
            confirmOk.addEventListener('click',     function() { closeConfirm(true);  });
            confirmCancel.addEventListener('click', function() { closeConfirm(false); });
            confirmModal.addEventListener('click',  function(e) { if (e.target === confirmModal) closeConfirm(false); });
            document.addEventListener('keydown',    function(e) { if (e.key === 'Escape') closeConfirm(false); });

            /* ── Remove unit ── */
            document.querySelectorAll('.tl-remove-unit-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var tlid = btn.dataset.tlid;
                    showConfirm('Remove the assigned unit from this team leader? They will be set to Idle.').then(function(confirmed) {
                        if (!confirmed) return;

                        btn.disabled = true;

                        var fd = new FormData();
                        fd.append('_token', CSRF);

                        fetch('/admin-dashboard/drivers/' + tlid + '/remove-unit', {
                            method: 'POST', headers: { 'Accept': 'application/json' }, body: fd
                        })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.errors) {
                                toast(typeof data.errors === 'string' ? data.errors : Object.values(data.errors)[0], 'error');
                                btn.disabled = false;
                                return;
                            }
                            var unitSel = document.querySelector('.tl-unit-select[data-tlid="' + tlid + '"]');
                            if (unitSel) { unitSel.value = ''; unitSel.disabled = false; }
                            var driverEl = document.getElementById('driverName-' + tlid);
                            if (driverEl) driverEl.textContent = '—';
                            btn.remove();
                            var statusSel = document.querySelector('.tl-status-select[data-tlid="' + tlid + '"]');
                            if (statusSel) { statusSel.disabled = true; }
                            var subEl = document.getElementById('statusSub-' + tlid);
                            if (subEl) subEl.textContent = 'Online — waiting for unit';
                            toast(data.message || 'Unit removed.');
                        })
                        .catch(function () {
                            btn.disabled = false;
                            toast('Failed to remove unit.', 'error');
                        });
                    });
                });
            });

            /* ── Filters ── */
            document.querySelectorAll('[data-filter]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('[data-filter]').forEach(function(b) {
                        b.classList.remove('is-active');
                    });
                    btn.classList.add('is-active');
                    applyFilters();
                });
            });

            var zoneFilter = document.getElementById('zoneFilterSelect');
            var searchInput = document.getElementById('tlSearch');
            if (zoneFilter) zoneFilter.addEventListener('change', applyFilters);
            if (searchInput) searchInput.addEventListener('input', applyFilters);

            function applyFilters() {
                var activeBtn = document.querySelector('[data-filter].is-active');
                var presence = activeBtn ? activeBtn.dataset.filter : 'all';
                var zone = zoneFilter ? zoneFilter.value.toLowerCase() : '';
                var query = searchInput ? searchInput.value.toLowerCase().trim() : '';

                document.querySelectorAll('#tlTableBody tr[data-driver-id]').forEach(function(row) {
                    var ok = (presence === 'all' || row.dataset.presence === presence) &&
                        (!zone || (row.dataset.zone || '').toLowerCase() === zone) &&
                        (!query || (row.dataset.name || '').toLowerCase().includes(query));
                    row.classList.toggle('is-hidden', !ok);
                });
            }

            applyFilters();

            setTimeout(function() {
                document.querySelectorAll('.drivers-feedback').forEach(function(el) {
                    el.style.transition = 'opacity .4s';
                    el.style.opacity = '0';
                    setTimeout(function() {
                        el.remove();
                    }, 400);
                });
            }, 4000);
        })();
    </script>
@endsection
