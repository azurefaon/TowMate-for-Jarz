@extends('admin-dashboard.layouts.app')

@section('title', 'Units & leaders')

@section('content')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/drivers.css') }}">

    @php
        $unitCards = $allUnits->map(function ($unit) use ($teamLeaderStatuses) {
            $tlId = $unit->team_leader_id;
            $tlStatus = $tlId ? $teamLeaderStatuses->get($tlId) ?? [] : [];
            $isOnline = $tlId && ($tlStatus['presence'] ?? 'offline') === 'online';

            $raw = $unit->status;
            $disp = $unit->dispatcher_status;

            if (!$tlId) {
                $eff = 'no_tl';
            } elseif ($raw === 'on_job' || in_array($disp, ['on_job', 'on_tow'])) {
                $eff = 'on_job';
            } elseif ($disp === 'unavailable') {
                $eff = 'not_avail';
            } elseif ($disp === 'available') {
                $eff = 'available';
            } elseif (!$isOnline) {
                $eff = 'not_avail';
            } elseif (in_array($raw, ['offline', 'disabled'])) {
                $eff = 'offline';
            } else {
                $eff = 'available';
            }

            $locked = in_array($eff, ['on_job', 'no_tl']);

            $unit->eff_status = $eff;
            $unit->disp_locked = $locked;
            $unit->tl_online = $isOnline;
            $unit->tl_full = $unit->teamLeader?->full_name ?? ($unit->teamLeader?->name ?? null);
            $unit->driver_full = $unit->driver?->full_name ?? ($unit->driver?->name ?? ($unit->driver_name ?? null));
            $unit->zone_label = $tlStatus['zone_name'] ?? ($unit->zone?->name ?? null);

            return $unit;
        });

        $tabCounts = [
            'all' => $unitCards->count(),
            'available' => $unitCards->where('eff_status', 'available')->count(),
            'on_job' => $unitCards->where('eff_status', 'on_job')->count(),
            'offline' => $unitCards->where('eff_status', 'offline')->count(),
            'not_avail' => $unitCards->whereIn('eff_status', ['not_avail', 'no_tl'])->count(),
        ];

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

        $zoneList = $unitCards->map(fn($u) => $u->zone_label)->filter()->unique()->values();

        $readyQueue = $teamLeaders
            ->filter(function ($tl) use ($teamLeaderStatuses) {
                $s = $teamLeaderStatuses->get($tl->id) ?? [];
                return ($s['presence'] ?? 'offline') === 'online' && ($s['workload'] ?? '') === 'available';
            })
            ->values();
    @endphp

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

        <div class="fleet-header">
            <span class="fleet-total">{{ $unitCards->count() }} units</span>
        </div>

        <div class="fleet-body">
            <div>
                <div class="fleet-toolbar">
                    <div class="fleet-tabs" role="tablist" aria-label="Filter units">
                        <button class="ftab is-active" data-tab="all" role="tab">All <span
                                class="ftab-count">{{ $tabCounts['all'] }}</span></button>
                        <button class="ftab" data-tab="available" role="tab">Available <span
                                class="ftab-count">{{ $tabCounts['available'] }}</span></button>
                        <button class="ftab" data-tab="on_job" role="tab">On Job <span
                                class="ftab-count">{{ $tabCounts['on_job'] }}</span></button>
                        <button class="ftab" data-tab="offline" role="tab">Offline <span
                                class="ftab-count">{{ $tabCounts['offline'] }}</span></button>
                        <button class="ftab" data-tab="not_avail" role="tab">Not Available <span
                                class="ftab-count">{{ $tabCounts['not_avail'] }}</span></button>
                    </div>
                    <div class="fleet-search-wrap">
                        <input type="text" id="fleetSearch" class="fleet-search"
                            placeholder="Search unit, leader or driver…" autocomplete="off">
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
                            $tabKey = in_array($unit->eff_status, ['not_avail', 'no_tl'])
                                ? 'not_avail'
                                : $unit->eff_status;

                            $tlInitials = '';
                            if ($unit->tl_full) {
                                $p = explode(' ', trim($unit->tl_full));
                                $tlInitials = strtoupper(
                                    substr($p[0], 0, 1) . (count($p) > 1 ? substr(end($p), 0, 1) : ''),
                                );
                            }

                            [$statusLabel, $badgeCls] = match ($unit->eff_status) {
                                'available' => ['Available', 'ubadge--available'],
                                'on_job' => ['On Job', 'ubadge--on-job'],
                                'offline' => ['Offline', 'ubadge--offline'],
                                'not_avail' => ['Not Available', 'ubadge--not-avail'],
                                'no_tl' => ['No Leader', 'ubadge--no-tl'],
                                default => ['Unknown', 'ubadge--offline'],
                            };

                            $lockMsg = match ($unit->eff_status) {
                                'on_job' => 'Locked — unit is on a job',
                                'no_tl' => 'No team leader assigned',
                                default => 'Status locked',
                            };

                            $curDisp = $unit->dispatcher_status ?? 'available';
                        @endphp
                        @php
                            $tagClass = match ($unit->eff_status) {
                                'available' => 'available',
                                'on_job' => 'on-job',
                                default => 'offline',
                            };
                        @endphp
                        <div class="unit-card" data-tab="{{ $tabKey }}" data-status="{{ $unit->eff_status }}"
                            data-zone="{{ strtolower($unit->zone_label ?? '') }}"
                            data-name="{{ strtolower(($unit->name ?? '') . ' ' . ($unit->tl_full ?? '') . ' ' . ($unit->driver_full ?? '') . ' ' . ($unit->plate_number ?? '')) }}"
                            data-modal-id="{{ $unit->id }}" data-modal-tl-id="{{ $unit->team_leader_id ?? '' }}"
                            data-modal-name="{{ $unit->name }}" data-modal-plate="{{ $unit->plate_number ?? '—' }}"
                            data-modal-type="{{ optional($unit->truckType)->name ?? 'N/A' }}"
                            data-modal-eff-status="{{ $unit->eff_status }}" data-modal-status-label="{{ $statusLabel }}"
                            data-modal-tl-name="{{ $unit->tl_full ?? 'Unassigned' }}"
                            data-modal-tl-role="{{ optional(optional($unit->teamLeader)->role)->name ?? '' }}"
                            data-modal-tl-email="{{ optional($unit->teamLeader)->email ?? '' }}"
                            data-modal-tl-phone="{{ optional($unit->teamLeader)->phone ?? 'null' }}"
                            data-modal-driver-name="{{ $unit->driver_full ?? 'No driver assigned' }}"
                            data-modal-driver-role="{{ optional(optional($unit->driver)->role)->name ?? '' }}"
                            data-modal-driver-email="{{ optional($unit->driver)->email ?? '' }}"
                            data-modal-driver-phone="{{ optional($unit->driver)->phone ?? '' }}">
                            <div class="ucard-top">
                                <div class="unit-tag {{ $tagClass }}">
                                    {{ strtoupper(substr($unit->name, 0, 3)) }}
                                </div>
                                <div class="ucard-identity">
                                    <span
                                        class="unit-type-label">{{ strtoupper(optional($unit->truckType)->name ?? 'Unknown') }}</span>
                                    <strong class="ucard-name">{{ $unit->name }}</strong>
                                </div>
                                <span class="ubadge {{ $badgeCls }}">{{ $statusLabel }}</span>
                            </div>

                            <div class="ucard-people">
                                <div class="unit-person">
                                    <span class="person-label">Team Leader</span>
                                    <span class="person-name">{{ $unit->tl_full ?? 'Unassigned' }}</span>
                                </div>
                                <div class="unit-person">
                                    <span class="person-label">Driver</span>
                                    <span class="person-name">{{ $unit->driver_full ?? 'No driver assigned' }}</span>
                                </div>
                            </div>

                        </div>
                    @empty
                        <div class="unit-grid-empty">No units found.</div>
                    @endforelse
                </div>

                <div id="noResultsMsg" class="unit-grid-empty" style="display:none;">
                    No units match your current filter.
                </div>
            </div>

        </div>

        {{-- Unit Detail Modal --}}
        <div id="unitDetailModal" class="ud-overlay" aria-hidden="true">
            <div class="ud-modal-card">
                <div class="ud-modal-header">
                    <div>
                        <span class="ud-modal-badge"> Unit Details </span>
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
                        <div class="ud-person-card" id="udTlCard">
                            <div class="ud-person-block">
                                <span class="ud-person-block-label">Team Leader</span>
                                <strong id="udTlName"></strong>
                                <span class="ud-role" id="udTlRole"></span>
                                <div class="ud-contact">
                                    <svg width="13" height="13" fill="none" stroke="currentColor"
                                        stroke-width="2" viewBox="0 0 24 24">
                                        <path
                                            d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                                        <polyline points="22,6 12,13 2,6" />
                                    </svg>
                                    <span id="udTlEmail"></span>
                                </div>
                                <div class="ud-contact">
                                    <svg width="13" height="13" fill="none" stroke="currentColor"
                                        stroke-width="2" viewBox="0 0 24 24">
                                        <path
                                            d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.96a16 16 0 0 0 6.13 6.13l.96-.96a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                                    </svg>
                                    <span id="udTlPhone"></span>
                                </div>
                            </div>
                            <div class="ud-person-divider"></div>
                            <div class="ud-person-block" id="udDriverCard">
                                <span class="ud-person-block-label">Driver</span>
                                <strong id="udDriverName"></strong>
                                <span class="ud-role" id="udDriverRole"></span>
                                <div class="ud-contact">
                                    {{-- <svg width="13" height="13" fill="none" stroke="currentColor"
                                        stroke-width="2" viewBox="0 0 24 24">
                                        <path
                                            d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                                        <polyline points="22,6 12,13 2,6" />
                                    </svg> --}}
                                    <span id="udDriverEmail"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ud-override-section" id="udOverrideSection">
                        <div class="ud-override-header">
                            <div class="ud-override-title">
                                {{-- <svg width="14" height="14" fill="none" stroke="currentColor"
                                    stroke-width="2" viewBox="0 0 24 24">
                                    <path
                                        d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                    <line x1="12" y1="9" x2="12" y2="13" />
                                    <line x1="12" y1="17" x2="12.01" y2="17" />
                                </svg> --}}

                            </div>
                            <span class="ud-restricted-badge">Restricted Access</span>
                        </div>
                        <p>Manually override the unit's operational status. This will alert the assigned Team Leader and
                            remove the unit from the active dispatch queue immediately.</p>
                        <div class="ud-override-form">
                            <label for="udOverrideState">change status</label>
                            <select id="udOverrideState">
                                <option value="available">Available</option>
                                <option value="unavailable">Not Available (Unreachable / Maintenance)</option>
                                <option value="on_tow">On Tow</option>
                                <option value="on_job">On Job</option>
                            </select>
                            <label for="udOverrideReason">Reason for Override (Optional)</label>
                            <textarea id="udOverrideReason" placeholder="Provide brief context for the manual status change..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="ud-modal-footer">
                    <button type="button" class="ud-btn-close" id="udCloseBtn">Close Window</button>
                    <button type="button" class="ud-btn-save" id="udSaveBtn">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                            <polyline points="17 21 17 13 7 13 7 21" />
                            <polyline points="7 3 7 8 15 8" />
                        </svg>
                        Save Override
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const CSRF = document.querySelector('meta[name="csrf-token"]').content;

            let toastTimer;

            function toast(msg, type) {
                const el = document.getElementById('fleetToast');
                el.className = 'fleet-toast fleet-toast--' + (type || 'success');
                el.querySelector('.toast-msg').textContent = msg;
                el.classList.add('show');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(() => el.classList.remove('show'), 3500);
            }

            document.querySelectorAll('.ftab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.ftab').forEach(t => t.classList.remove('is-active'));
                    tab.classList.add('is-active');
                    applyFilters();
                });
            });

            /* Search + Zone filter */
            const searchEl = document.getElementById('fleetSearch');
            const zoneFilterEl = document.getElementById('fleetZoneFilter');
            if (searchEl) searchEl.addEventListener('input', applyFilters);
            if (zoneFilterEl) zoneFilterEl.addEventListener('change', applyFilters);

            function applyFilters() {
                const activeTab = (document.querySelector('.ftab.is-active') || {}).dataset?.tab ?? 'all';
                const query = (searchEl?.value ?? '').toLowerCase().trim();
                const zone = (zoneFilterEl?.value ?? '').toLowerCase();

                let visible = 0;
                document.querySelectorAll('#unitGrid .unit-card').forEach(function(card) {
                    const tabOk = activeTab === 'all' || card.dataset.tab === activeTab;
                    const nameOk = !query || card.dataset.name.includes(query);
                    const zoneOk = !zone || card.dataset.zone.includes(zone);
                    const show = tabOk && nameOk && zoneOk;
                    card.style.display = show ? '' : 'none';
                    if (show) visible++;
                });

                const noMsg = document.getElementById('noResultsMsg');
                if (noMsg) noMsg.style.display = visible === 0 ? '' : 'none';
            }

            applyFilters();

            /* ── Unit Detail Modal ── */
            const modal = document.getElementById('unitDetailModal');
            const udTitle = document.getElementById('udTitle');
            const udSubtitle = document.getElementById('udSubtitle');
            const udPlate = document.getElementById('udPlate');
            const udType = document.getElementById('udType');
            const udStatusBadge = document.getElementById('udStatusBadge');
            const udTlName = document.getElementById('udTlName');
            const udTlRole = document.getElementById('udTlRole');
            const udTlEmail = document.getElementById('udTlEmail');
            const udTlPhone = document.getElementById('udTlPhone');
            const udDriverName = document.getElementById('udDriverName');
            const udDriverRole = document.getElementById('udDriverRole');
            const udDriverEmail = document.getElementById('udDriverEmail');
            const udDriverPhone = document.getElementById('udDriverPhone');
            const udOverrideSection = document.getElementById('udOverrideSection');
            const udOverrideReason = document.getElementById('udOverrideReason');
            const udSaveBtn = document.getElementById('udSaveBtn');
            const udCloseBtn = document.getElementById('udCloseBtn');
            const udClose2 = document.getElementById('udClose2');

            let currentTlId = null;
            let currentUnitId = null;

            const statusBadgeMap = {
                available: {
                    cls: 'ud-status-pill--available',
                    label: 'Available'
                },
                on_job: {
                    cls: 'ud-status-pill--on-job',
                    label: 'On Job'
                },
                offline: {
                    cls: 'ud-status-pill--offline',
                    label: 'Offline'
                },
                not_avail: {
                    cls: 'ud-status-pill--not-avail',
                    label: 'Not Available'
                },
                no_tl: {
                    cls: 'ud-status-pill--offline',
                    label: 'No Leader'
                },
            };

            // function openModal(card) {
            //     currentUnitId = card.dataset.modalId;
            //     currentTlId   = card.dataset.modalTlId;
            //     const effStatus = card.dataset.modalEffStatus || '';

            //     udTitle.textContent    = card.dataset.modalName    || '';
            //     udSubtitle.textContent = card.dataset.modalType    || 'Asset';
            //     udPlate.textContent    = card.dataset.modalPlate   || '—';
            //     udType.textContent     = card.dataset.modalType    || '—';

            //     const sm = statusBadgeMap[effStatus] || { cls: 'ud-status-pill--offline', label: card.dataset.modalStatusLabel || effStatus };
            //     udStatusBadge.className   = 'ud-status-pill ' + sm.cls;
            //     udStatusBadge.textContent = card.dataset.modalStatusLabel || sm.label;

            //     udTlName.textContent  = card.dataset.modalTlName      || 'Unassigned';
            //     udTlRole.textContent  = card.dataset.modalTlRole      || '';
            //     udTlEmail.textContent = card.dataset.modalTlEmail     || '—';
            //     udTlPhone.textContent = card.dataset.modalTlPhone     || '—';

            //     udDriverName.textContent  = card.dataset.modalDriverName  || 'No driver assigned';
            //     udDriverRole.textContent  = card.dataset.modalDriverRole  || '';
            //     udDriverEmail.textContent = card.dataset.modalDriverEmail || '—';
            //     udDriverPhone.textContent = card.dataset.modalDriverPhone || '—';

            //     /* Show override for any unit that has a TL */
            //     const canOverride = !!currentTlId;
            //     if (udOverrideSection) udOverrideSection.style.display = canOverride ? '' : 'none';
            //     if (udOverrideReason) udOverrideReason.value = '';
            //     const udOverrideState = document.getElementById('udOverrideState');
            //     if (udOverrideState) udOverrideState.value = 'available';
            //     resetSaveBtn();

            //     modal.classList.add('is-open');
            //     modal.setAttribute('aria-hidden', 'false');
            //     document.body.style.overflow = 'hidden';
            // }

            function openModal(card) {
                currentUnitId = card.dataset.modalId;
                currentTlId = card.dataset.modalTlId;
                const effStatus = card.dataset.modalEffStatus || '';

                if (udTitle) udTitle.textContent = card.dataset.modalName || '';
                if (udSubtitle) udSubtitle.textContent = card.dataset.modalType || 'Asset';
                if (udPlate) udPlate.textContent = card.dataset.modalPlate || '—';
                if (udType) udType.textContent = card.dataset.modalType || '—';

                const sm = statusBadgeMap[effStatus] || {
                    cls: 'ud-status-pill--offline',
                    label: card.dataset.modalStatusLabel || effStatus
                };

                if (udStatusBadge) {
                    udStatusBadge.className = 'ud-status-pill ' + sm.cls;
                    udStatusBadge.textContent = card.dataset.modalStatusLabel || sm.label;
                }

                if (udTlName) udTlName.textContent = card.dataset.modalTlName || 'Unassigned';
                if (udTlRole) udTlRole.textContent = card.dataset.modalTlRole || '';
                if (udTlEmail) udTlEmail.textContent = card.dataset.modalTlEmail || '—';
                if (udTlPhone) {
                    const phone = card.dataset.modalTlPhone;
                    udTlPhone.textContent =
                        phone && phone !== 'null' ? phone : 'No phone number';
                }

                if (udDriverName) udDriverName.textContent = card.dataset.modalDriverName || 'No driver assigned';

                const canOverride = !!currentTlId;
                if (udOverrideSection) udOverrideSection.style.display = canOverride ? '' : 'none';
                if (udOverrideReason) udOverrideReason.value = '';

                const udOverrideState = document.getElementById('udOverrideState');
                if (udOverrideState) udOverrideState.value = 'available';

                resetSaveBtn();

                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function closeModal() {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                currentTlId = null;
                currentUnitId = null;
            }

            function resetSaveBtn() {
                if (!udSaveBtn) return;
                udSaveBtn.disabled = false;
                // udSaveBtn.innerHTML =
                //     '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Override';
            }

            document.querySelectorAll('.unit-card').forEach(function(card) {
                card.style.cursor = 'pointer';
                card.addEventListener('click', function() {
                    openModal(card);
                });
            });

            udCloseBtn?.addEventListener('click', closeModal);
            udClose2?.addEventListener('click', closeModal);
            modal?.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeModal();
            });

            /* ── Status override AJAX ── */
            udSaveBtn?.addEventListener('click', async function() {
                if (!currentTlId) return;

                udSaveBtn.disabled = true;
                udSaveBtn.textContent = 'Saving…';

                const note = udOverrideReason?.value.trim() || '';
                const selectedStatus = document.getElementById('udOverrideState')?.value || 'unavailable';
                const fd = new FormData();
                fd.append('_token', CSRF);
                fd.append('_method', 'PATCH');
                fd.append('unit_status', selectedStatus);
                if (note) fd.append('dispatcher_note', note);

                const statusMap = {
                    available: {
                        tab: 'available',
                        badge: 'ubadge--available',
                        label: 'Available',
                        tag: 'available'
                    },
                    unavailable: {
                        tab: 'not_avail',
                        badge: 'ubadge--not-avail',
                        label: 'Not Available',
                        tag: 'offline'
                    },
                    on_tow: {
                        tab: 'on_job',
                        badge: 'ubadge--on-job',
                        label: 'On Tow',
                        tag: 'on-job'
                    },
                    on_job: {
                        tab: 'on_job',
                        badge: 'ubadge--on-job',
                        label: 'On Job',
                        tag: 'on-job'
                    },
                };

                try {
                    const res = await fetch('/admin-dashboard/drivers/' + currentTlId + '/override', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json'
                        },
                        body: fd,
                    });
                    const data = await res.json();

                    if (data.errors) {
                        toast(typeof data.errors === 'string' ? data.errors : Object.values(data.errors)[0],
                            'error');
                        resetSaveBtn();
                        return;
                    }

                    /* Update card badge in place */
                    const sm = statusMap[selectedStatus] || statusMap.unavailable;
                    const card = document.querySelector('.unit-card[data-modal-id="' + currentUnitId +
                        '"]');
                    if (card) {
                        card.dataset.tab = sm.tab;
                        card.dataset.status = selectedStatus === 'unavailable' ? 'not_avail' :
                            selectedStatus;
                        card.dataset.modalEffStatus = selectedStatus === 'unavailable' ? 'not_avail' :
                            selectedStatus;
                        const badge = card.querySelector('.ubadge');
                        if (badge) {
                            badge.className = 'ubadge ' + sm.badge;
                            badge.textContent = sm.label;
                        }
                        const tag = card.querySelector('.unit-tag');
                        if (tag) tag.className = 'unit-tag ' + sm.tag;
                    }

                    closeModal();
                    toast('Status overridden to ' + sm.label + '.');
                    applyFilters();
                } catch {
                    toast('Network error. Please try again.', 'error');
                    resetSaveBtn();
                }
            });

            /* ── Auto-dismiss feedback banners ── */
            setTimeout(function() {
                document.querySelectorAll('.fleet-feedback').forEach(function(el) {
                    el.style.transition = 'opacity .4s';
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 400);
                });
            }, 4000);
        })();
    </script>
@endsection
