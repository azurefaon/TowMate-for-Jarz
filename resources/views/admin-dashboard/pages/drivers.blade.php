@extends('admin-dashboard.layouts.app')

@section('title', 'Fleet — Units')

@section('content')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/drivers.css') }}">

    @php
        /* ── Compute effective status per unit ── */
        $unitCards = $allUnits->map(function ($unit) use ($teamLeaderStatuses) {
            $tlId     = $unit->team_leader_id;
            $tlStatus = $tlId ? ($teamLeaderStatuses->get($tlId) ?? []) : [];
            $isOnline = $tlId && ($tlStatus['presence'] ?? 'offline') === 'online';

            $raw  = $unit->status;
            $disp = $unit->dispatcher_status;

            if (! $tlId) {
                $eff = 'no_tl';
            } elseif (! $isOnline) {
                $eff = 'not_avail';
            } elseif ($raw === 'on_job' || in_array($disp, ['on_job', 'on_tow'])) {
                $eff = 'on_job';
            } elseif ($disp === 'unavailable') {
                $eff = 'not_avail';
            } elseif (in_array($raw, ['offline', 'disabled'])) {
                $eff = 'offline';
            } else {
                $eff = 'available';
            }

            /* Locked = dispatcher cannot override:
               - available  → team leader is managing this
               - on_job     → unit is actively working
               - no_tl      → no leader to route override to */
            $locked = in_array($eff, ['available', 'on_job', 'no_tl']);

            $unit->eff_status  = $eff;
            $unit->disp_locked = $locked;
            $unit->tl_online   = $isOnline;
            $unit->tl_full     = $unit->teamLeader?->full_name ?? $unit->teamLeader?->name ?? null;
            $unit->driver_full = $unit->driver?->full_name ?? $unit->driver?->name ?? ($unit->driver_name ?? null);
            $unit->zone_label  = $tlStatus['zone_name'] ?? $unit->zone?->name ?? null;

            return $unit;
        });

        /* ── Tab counts ── */
        $tabCounts = [
            'all'       => $unitCards->count(),
            'available' => $unitCards->where('eff_status', 'available')->count(),
            'on_job'    => $unitCards->where('eff_status', 'on_job')->count(),
            'offline'   => $unitCards->where('eff_status', 'offline')->count(),
            'not_avail' => $unitCards->whereIn('eff_status', ['not_avail', 'no_tl'])->count(),
        ];

        /* ── Zone summary (from team leaders, for sidebar) ── */
        $zoneSummary = collect();
        foreach ($teamLeaders as $tl) {
            $s  = $teamLeaderStatuses->get($tl->id) ?? [];
            $zn = $s['zone_name'] ?? ($tl->unit?->zone?->name ?? null);
            if (! $zn) continue;
            if (! $zoneSummary->has($zn)) $zoneSummary->put($zn, ['avail' => 0, 'busy' => 0, 'unavail' => 0]);
            $entry = $zoneSummary->get($zn);
            $wl = $s['workload'] ?? 'unavailable';
            $pr = $s['presence'] ?? 'offline';
            if ($wl === 'busy')      $entry['busy']++;
            elseif ($pr === 'online') $entry['avail']++;
            else                     $entry['unavail']++;
            $zoneSummary->put($zn, $entry);
        }

        /* ── Zone list for dropdown (from units) ── */
        $zoneList = $unitCards
            ->map(fn($u) => $u->zone_label)
            ->filter()
            ->unique()
            ->values();

        /* ── Ready queue (online TLs with unit, available) ── */
        $readyQueue = $teamLeaders->filter(function ($tl) use ($teamLeaderStatuses) {
            $s = $teamLeaderStatuses->get($tl->id) ?? [];
            return ($s['presence'] ?? 'offline') === 'online' && ($s['workload'] ?? '') === 'available';
        })->values();
    @endphp

    {{-- Toast --}}
    <div id="fleetToast" class="fleet-toast" role="alert" aria-live="polite">
        <span class="toast-msg"></span>
    </div>

    <div class="fleet-page">

        @if (session('success'))
            <div class="fleet-feedback fleet-feedback--success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="fleet-feedback fleet-feedback--error">{{ $errors->first() }}</div>
        @endif

        {{-- Header --}}
        <div class="fleet-header">
            <div>
                <p class="fleet-eyebrow">Dispatcher · Fleet</p>
                <h1 class="fleet-title">Units Overview</h1>
            </div>
            <span class="fleet-total">{{ $unitCards->count() }} units</span>
        </div>

        {{-- Stats strip --}}
        <div class="fleet-stats">
            <div class="fstat fstat--total">
                <div class="fstat-num">{{ $tabCounts['all'] }}</div>
                <div class="fstat-lbl">Total</div>
            </div>
            <div class="fstat fstat--available">
                <div class="fstat-num">{{ $tabCounts['available'] }}</div>
                <div class="fstat-lbl">Available</div>
            </div>
            <div class="fstat fstat--on-job">
                <div class="fstat-num">{{ $tabCounts['on_job'] }}</div>
                <div class="fstat-lbl">On Job</div>
            </div>
            <div class="fstat fstat--offline">
                <div class="fstat-num">{{ $tabCounts['offline'] }}</div>
                <div class="fstat-lbl">Offline</div>
            </div>
            <div class="fstat fstat--not-avail">
                <div class="fstat-num">{{ $tabCounts['not_avail'] }}</div>
                <div class="fstat-lbl">Not Available</div>
            </div>
        </div>

        <div class="fleet-body">

            {{-- Main: tabs + cards --}}
            <div>
                <div class="fleet-toolbar">
                    <div class="fleet-tabs" role="tablist" aria-label="Filter units">
                        <button class="ftab is-active" data-tab="all"       role="tab">All       <span class="ftab-count">{{ $tabCounts['all'] }}</span></button>
                        <button class="ftab"           data-tab="available" role="tab">Available <span class="ftab-count">{{ $tabCounts['available'] }}</span></button>
                        <button class="ftab"           data-tab="on_job"    role="tab">On Job    <span class="ftab-count">{{ $tabCounts['on_job'] }}</span></button>
                        <button class="ftab"           data-tab="offline"   role="tab">Offline   <span class="ftab-count">{{ $tabCounts['offline'] }}</span></button>
                        <button class="ftab"           data-tab="not_avail" role="tab">Not Available <span class="ftab-count">{{ $tabCounts['not_avail'] }}</span></button>
                    </div>
                    <div class="fleet-search-wrap">
                        <input type="text" id="fleetSearch" class="fleet-search" placeholder="Search unit, leader or driver…" autocomplete="off">
                        @if ($zoneList->isNotEmpty())
                            <select id="fleetZoneFilter" class="fleet-zone-filter" aria-label="Filter by zone">
                                <option value="">All Zones</option>
                                @foreach ($zoneList as $zn)
                                    <option value="{{ strtolower($zn) }}">{{ $zn }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                </div>

                <div class="unit-grid" id="unitGrid">
                    @forelse($unitCards as $unit)
                        @php
                            /* Tab key — not_avail and no_tl both go under not_avail tab */
                            $tabKey = in_array($unit->eff_status, ['not_avail', 'no_tl']) ? 'not_avail' : $unit->eff_status;

                            /* TL initials */
                            $tlInitials = '';
                            if ($unit->tl_full) {
                                $p = explode(' ', trim($unit->tl_full));
                                $tlInitials = strtoupper(substr($p[0], 0, 1) . (count($p) > 1 ? substr(end($p), 0, 1) : ''));
                            }

                            /* Badge */
                            [$statusLabel, $badgeCls] = match ($unit->eff_status) {
                                'available' => ['Available',     'ubadge--available'],
                                'on_job'    => ['On Job',        'ubadge--on-job'],
                                'offline'   => ['Offline',       'ubadge--offline'],
                                'not_avail' => ['Not Available', 'ubadge--not-avail'],
                                'no_tl'     => ['No Leader',     'ubadge--no-tl'],
                                default     => ['Unknown',       'ubadge--offline'],
                            };

                            /* Lock message */
                            $lockMsg = match ($unit->eff_status) {
                                'available' => 'Managed by team leader',
                                'on_job'    => 'Locked — unit is on a job',
                                'no_tl'     => 'No team leader assigned',
                                default     => 'Status locked',
                            };

                            /* Current dispatcher_status value for the select */
                            $curDisp = $unit->dispatcher_status ?? 'available';
                        @endphp
                        <div class="unit-card"
                             data-tab="{{ $tabKey }}"
                             data-status="{{ $unit->eff_status }}"
                             data-zone="{{ strtolower($unit->zone_label ?? '') }}"
                             data-name="{{ strtolower(($unit->name ?? '') . ' ' . ($unit->tl_full ?? '') . ' ' . ($unit->driver_full ?? '') . ' ' . ($unit->plate_number ?? '')) }}">

                            {{-- Top: TL avatar (top-left) + unit name + badge --}}
                            <div class="ucard-top">
                                <div class="ucard-tl-avatar-wrap">
                                    @if ($unit->tl_full)
                                        <div class="ucard-avatar-lg">{{ $tlInitials }}</div>
                                        <span class="ucard-tl-presence {{ $unit->tl_online ? 'tl-dot--online' : 'tl-dot--offline' }}"></span>
                                    @else
                                        <div class="ucard-avatar-lg ucard-avatar-lg--empty">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                        </div>
                                    @endif
                                </div>
                                <div class="ucard-identity">
                                    <span class="ucard-name">{{ $unit->name }}</span>
                                    <span class="ucard-plate">{{ $unit->plate_number ?? '—' }}</span>
                                </div>
                                <span class="ubadge {{ $badgeCls }}">{{ $statusLabel }}</span>
                            </div>

                            {{-- Type + Zone tags --}}
                            @if ($unit->truckType || $unit->zone_label)
                                <div class="ucard-meta">
                                    @if ($unit->truckType)
                                        <span class="ucard-type-tag">{{ $unit->truckType->name }}</span>
                                    @endif
                                    @if ($unit->zone_label)
                                        <span class="ucard-zone-tag">{{ $unit->zone_label }}</span>
                                    @endif
                                </div>
                            @endif

                            {{-- Detail rows --}}
                            <div class="ucard-details">
                                <div class="ucard-row">
                                    <span class="ucard-lbl">Leader</span>
                                    <span class="ucard-val">
                                        @if ($unit->tl_full)
                                            {{ $unit->tl_full }}
                                        @else
                                            <span class="ucard-none">Not assigned</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="ucard-row">
                                    <span class="ucard-lbl">Driver</span>
                                    <span class="ucard-val">
                                        @if ($unit->driver_full)
                                            {{ $unit->driver_full }}
                                        @else
                                            <span class="ucard-none">Not assigned</span>
                                        @endif
                                    </span>
                                </div>
                            </div>

                            {{-- Override OR locked note --}}
                            @if (! $unit->disp_locked && $unit->team_leader_id)
                                <div class="ucard-override">
                                    <span class="ucard-override-label">Override Status</span>
                                    <div class="ucard-override-row">
                                        <select class="ucard-override-select"
                                                data-unit-id="{{ $unit->id }}"
                                                data-tl-id="{{ $unit->team_leader_id }}"
                                                data-current="{{ $curDisp }}"
                                                aria-label="Override status for {{ $unit->name }}">
                                            <option value="available"   {{ $curDisp === 'available'   ? 'selected' : '' }}>Set Available</option>
                                            <option value="unavailable" {{ $curDisp === 'unavailable' ? 'selected' : '' }}>Set Not Available</option>
                                            <option value="on_tow"      {{ $curDisp === 'on_tow'      ? 'selected' : '' }}>Set On Tow</option>
                                        </select>
                                        <span class="ucard-saving" id="saving-{{ $unit->id }}">Saving…</span>
                                    </div>
                                </div>
                            @else
                                <div class="ucard-locked-note">
                                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                    </svg>
                                    {{ $lockMsg }}
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="unit-grid-empty">No units found. Add units via Super Admin → Units.</div>
                    @endforelse
                </div>

                <div id="noResultsMsg" class="unit-grid-empty" style="display:none;">
                    No units match your current filter.
                </div>
            </div>

            {{-- Sidebar --}}
            <aside class="fleet-sidebar">

                @if ($zoneSummary->isNotEmpty())
                    <div class="fsidebar-card">
                        <div class="fsidebar-head">
                            <span class="fsidebar-title">Zone Overview</span>
                            <span class="fsidebar-count">{{ $zoneSummary->count() }}</span>
                        </div>
                        @foreach ($zoneSummary as $zn => $counts)
                            <div class="fzone-row">
                                <span class="fzone-name">{{ $zn }}</span>
                                <div class="fzone-pills">
                                    @if ($counts['avail'] > 0)
                                        <span class="fzpill fzpill--avail" title="Available">{{ $counts['avail'] }}</span>
                                    @endif
                                    @if ($counts['busy'] > 0)
                                        <span class="fzpill fzpill--busy"  title="On Job">{{ $counts['busy'] }}</span>
                                    @endif
                                    @if ($counts['unavail'] > 0)
                                        <span class="fzpill fzpill--out"   title="Unavailable">{{ $counts['unavail'] }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="fsidebar-card">
                    <div class="fsidebar-head">
                        <span class="fsidebar-title">Ready for Dispatch</span>
                        <span class="fsidebar-count">{{ $readyQueue->count() }}</span>
                    </div>
                    <div class="fsidebar-list">
                        @forelse($readyQueue as $ql)
                            @php $qs = $teamLeaderStatuses->get($ql->id) ?? []; @endphp
                            <div class="fsidebar-item">
                                <strong>{{ $ql->name }}</strong>
                                <span>
                                    {{ $qs['unit_name'] ?? 'No unit' }}
                                    {{ ! empty($qs['zone_name']) ? ' · ' . $qs['zone_name'] : '' }}
                                </span>
                            </div>
                        @empty
                            <p class="fsidebar-empty">No units ready for dispatch.</p>
                        @endforelse
                    </div>
                </div>

                {{-- Quick counts sidebar card --}}
                <div class="fsidebar-card">
                    <div class="fsidebar-head">
                        <span class="fsidebar-title">Status Breakdown</span>
                    </div>
                    <div class="fzone-row">
                        <span class="fzone-name">Available</span>
                        <span class="fzpill fzpill--avail">{{ $tabCounts['available'] }}</span>
                    </div>
                    <div class="fzone-row">
                        <span class="fzone-name">On Job</span>
                        <span class="fzpill fzpill--busy">{{ $tabCounts['on_job'] }}</span>
                    </div>
                    <div class="fzone-row">
                        <span class="fzone-name">Offline</span>
                        <span class="fzpill fzpill--out">{{ $tabCounts['offline'] }}</span>
                    </div>
                    <div class="fzone-row">
                        <span class="fzone-name">Not Available</span>
                        <span class="fzpill fzpill--out">{{ $tabCounts['not_avail'] }}</span>
                    </div>
                </div>

            </aside>
        </div>
    </div>

    <script>
        (function () {
            const CSRF = document.querySelector('meta[name="csrf-token"]').content;

            /* ── Toast ── */
            let toastTimer;
            function toast(msg, type) {
                const el = document.getElementById('fleetToast');
                el.className = 'fleet-toast fleet-toast--' + (type || 'success');
                el.querySelector('.toast-msg').textContent = msg;
                el.classList.add('show');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(() => el.classList.remove('show'), 3500);
            }

            /* ── Status override selects ── */
            document.querySelectorAll('.ucard-override-select').forEach(function (sel) {
                sel.addEventListener('change', function () {
                    const tlId   = sel.dataset.tlId;
                    const unitId = sel.dataset.unitId;
                    const value  = sel.value;
                    const savEl  = document.getElementById('saving-' + unitId);

                    sel.disabled = true;
                    if (savEl) savEl.style.opacity = '1';

                    const fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('_method', 'PATCH');
                    fd.append('unit_status', value);

                    fetch('/admin-dashboard/drivers/' + tlId + '/override', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: fd,
                    })
                    .then(r => r.json())
                    .then(function (data) {
                        sel.disabled = false;
                        if (savEl) savEl.style.opacity = '0';

                        if (data.errors) {
                            toast(typeof data.errors === 'string'
                                ? data.errors
                                : Object.values(data.errors)[0], 'error');
                            sel.value = sel.dataset.current;
                            return;
                        }

                        sel.dataset.current = value;

                        /* Update card badge text */
                        const card = sel.closest('.unit-card');
                        if (card && data.status && data.status.label) {
                            const badge = card.querySelector('.ubadge');
                            if (badge) badge.textContent = data.status.label;
                        }

                        /* If unit was released (unavailable), move it to not_avail tab */
                        if (data.unit_released && card) {
                            card.dataset.tab    = 'not_avail';
                            card.dataset.status = 'not_avail';
                        }

                        toast('Status updated.');
                        applyFilters();
                    })
                    .catch(function () {
                        sel.disabled = false;
                        if (savEl) savEl.style.opacity = '0';
                        sel.value = sel.dataset.current;
                        toast('Failed to update status.', 'error');
                    });
                });
            });

            /* ── Tabs ── */
            document.querySelectorAll('.ftab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    document.querySelectorAll('.ftab').forEach(t => t.classList.remove('is-active'));
                    tab.classList.add('is-active');
                    applyFilters();
                });
            });

            /* ── Search + Zone filter ── */
            const searchEl     = document.getElementById('fleetSearch');
            const zoneFilterEl = document.getElementById('fleetZoneFilter');
            if (searchEl)     searchEl.addEventListener('input',  applyFilters);
            if (zoneFilterEl) zoneFilterEl.addEventListener('change', applyFilters);

            function applyFilters() {
                const activeTab = (document.querySelector('.ftab.is-active') || {}).dataset?.tab ?? 'all';
                const query     = (searchEl?.value ?? '').toLowerCase().trim();
                const zone      = (zoneFilterEl?.value ?? '').toLowerCase();

                let visible = 0;
                document.querySelectorAll('#unitGrid .unit-card').forEach(function (card) {
                    const tabOk  = activeTab === 'all' || card.dataset.tab === activeTab;
                    const nameOk = !query || card.dataset.name.includes(query);
                    const zoneOk = !zone  || card.dataset.zone.includes(zone);
                    const show   = tabOk && nameOk && zoneOk;
                    card.style.display = show ? '' : 'none';
                    if (show) visible++;
                });

                const noMsg = document.getElementById('noResultsMsg');
                if (noMsg) noMsg.style.display = visible === 0 ? '' : 'none';
            }

            applyFilters();

            /* ── Auto-dismiss feedback banners ── */
            setTimeout(function () {
                document.querySelectorAll('.fleet-feedback').forEach(function (el) {
                    el.style.transition = 'opacity .4s';
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 400);
                });
            }, 4000);
        })();
    </script>
@endsection
