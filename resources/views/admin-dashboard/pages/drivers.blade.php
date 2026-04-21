@extends('admin-dashboard.layouts.app')

@section('title', 'Team Leaders')

@section('content')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/drivers.css') }}">
    <style>
        /* ── Focus highlight when redirected from dispatcher after sending a quote ── */
        @keyframes tl-focus-pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.55);
            }

            50% {
                box-shadow: 0 0 0 10px rgba(59, 130, 246, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0);
            }
        }

        .tl-card--focus-highlight {
            outline: 2px solid #3b82f6;
            outline-offset: 3px;
            animation: tl-focus-pulse 1s ease-out 3;
        }

        .tl-page-layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 24px;
            align-items: start;
        }

        .tl-sidebar {
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: sticky;
            top: 20px;
        }

        .ops-queue-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.05);
        }

        .ops-queue-card__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .ops-queue-card__head h3 {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
        }

        .ops-queue-count {
            min-width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #fef3c7;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .ops-queue-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 260px;
            overflow-y: auto;
            padding-right: 2px;
        }

        .ops-queue-item {
            padding: 8px 10px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
        }

        .ops-queue-item strong {
            display: block;
            font-size: 0.88rem;
            color: #0f172a;
        }

        .ops-queue-item span {
            display: block;
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 2px;
        }

        .ops-queue-empty {
            margin: 0;
            color: #94a3b8;
            font-size: 0.85rem;
        }

        .driver-ops-box {
            margin-top: 14px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.05);
        }

        .driver-status-note {
            margin: 10px 0 0;
            padding: 10px 12px;
            border-radius: 12px;
            background: #fffbeb;
            color: #92400e;
            font-size: 0.92rem;
        }

        .driver-ops-title {
            margin: 0 0 10px;
            font-weight: 700;
            color: #0f172a;
        }

        .driver-ops-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .driver-ops-label {
            width: 44px;
            flex-shrink: 0;
            font-size: 0.78rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .driver-ops-input,
        .driver-ops-select {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 10px 12px;
            background: #fff;
        }

        .driver-assignment-btn--secondary {
            margin-top: 10px;
            width: 100%;
            background: #111827;
            color: #fff;
        }

        @media (max-width: 900px) {
            .tl-page-layout {
                grid-template-columns: 1fr;
            }

            .tl-sidebar {
                position: static;
                flex-direction: row;
                flex-wrap: wrap;
            }

            .tl-sidebar .ops-queue-card {
                flex: 1 1 240px;
            }
        }
    </style>

    @php
        $leaderStates = collect($teamLeaderStatuses ?? []);
        $totalLeaders = $teamLeaders->count();
        $busyCount = $leaderStates->where('workload', 'busy')->count();
        $offlineCount = $offlineTeamLeadersCount ?? $leaderStates->where('presence', 'offline')->count();
        $onlineCount = $onlineTeamLeadersCount ?? $leaderStates->where('presence', 'online')->count();
        $defaultFilter = $onlineCount > 0 ? 'online' : 'all';
        $busyPercent = $totalLeaders > 0 ? (int) round(($busyCount / $totalLeaders) * 100) : 0;
        $readyQueue = $teamLeaders
            ->filter(function ($teamLeader) use ($teamLeaderStatuses) {
                $state = $teamLeaderStatuses->get($teamLeader->id) ?? [];
                return ($state['presence'] ?? 'offline') === 'online' &&
                    ($state['workload'] ?? 'unavailable') === 'available';
            })
            ->values();
        $activeQueue = $teamLeaders
            ->filter(function ($teamLeader) use ($teamLeaderStatuses) {
                $state = $teamLeaderStatuses->get($teamLeader->id) ?? [];
                return ($state['workload'] ?? 'unavailable') === 'busy' || ($state['unit_status'] ?? null) === 'on_job';
            })
            ->values();
        $unavailableQueue = $teamLeaders
            ->filter(function ($teamLeader) use ($teamLeaderStatuses) {
                $state = $teamLeaderStatuses->get($teamLeader->id) ?? [];
                return ($state['presence'] ?? 'offline') !== 'online' ||
                    ($state['workload'] ?? 'unavailable') === 'unavailable';
            })
            ->values();
    @endphp

    <div class="drivers-container">
        @if (session('success'))
            <div class="drivers-feedback drivers-feedback--success" id="driversFeedbackSuccess">{{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="drivers-feedback drivers-feedback--error">{{ $errors->first() }}</div>
        @endif

        <div class="drivers-header">
            <div>
                <p class="drivers-eyebrow">Dispatcher view</p>
                <h1 class="drivers-title">Team Leaders</h1>
            </div>
            <span class="drivers-total">{{ $totalLeaders }} records</span>
        </div>

        <div class="tl-page-layout">

            {{-- ── Left: filter buttons + team leader cards ── --}}
            <div class="tl-main">

                <div class="drivers-section-head" style="margin-bottom: 14px;">
                    <h2 class="drivers-section-title">Team Leaders</h2>
                    <div class="drivers-filters" role="group" aria-label="Filter team leaders"
                        data-default-filter="{{ $defaultFilter }}">
                        <button type="button" class="filter-btn {{ $defaultFilter === 'all' ? 'is-active' : '' }}"
                            data-filter="all">View All</button>
                        <button type="button" class="filter-btn {{ $defaultFilter === 'online' ? 'is-active' : '' }}"
                            data-filter="online">Online</button>
                        <button type="button" class="filter-btn" data-filter="offline">Offline</button>
                    </div>
                </div>

                <div class="drivers-grid">
                    @forelse($teamLeaders as $teamLeader)
                        @php
                            $leaderState = $teamLeaderStatuses->get($teamLeader->id) ?? [];
                        @endphp
                        <div class="driver-card {{ $defaultFilter === 'online' && ($leaderState['presence'] ?? 'offline') !== 'online' ? 'is-hidden' : '' }}"
                            data-driver-id="{{ $teamLeader->id }}"
                            data-presence="{{ $leaderState['presence'] ?? 'offline' }}"
                            data-workload="{{ $leaderState['workload'] ?? 'unavailable' }}">
                            <div class="driver-header">
                                <div class="driver-profile">
                                    <div class="driver-avatar">
                                        {{ strtoupper(substr($teamLeader->name, 0, 2)) }}
                                    </div>
                                    <div class="driver-info">
                                        <h3>{{ $teamLeader->name }}</h3>
                                        <p class="driver-presence-text">
                                            {{ strtoupper($leaderState['presence_label'] ?? 'Offline') }}</p>
                                    </div>
                                </div>

                                <div class="driver-badges">
                                    <span class="mini-pill mini-pill-unit">
                                        {{ $leaderState['unit_name'] ?? ($teamLeader->unit?->name ?? 'No assigned unit') }}
                                    </span>
                                    <span class="mini-pill mini-pill-driver">
                                        {{ $leaderState['driver_name'] ?? (optional(optional($teamLeader->unit)->driver)->name ?? 'No member driver') }}
                                    </span>
                                </div>
                            </div>

                            <div class="driver-body">
                                {{-- Automatic status badge and subtext --}}
                                @php
                                    $isOnline = ($leaderState['presence'] ?? 'offline') === 'online';
                                    $hasUnit =
                                        !empty($leaderState['unit_name']) &&
                                        $leaderState['unit_name'] !== 'No assigned unit';
                                    if (!$isOnline) {
                                        $status = 'NOT AVAILABLE';
                                        $statusClass = 'not-available';
                                        $statusColor = '#b91c1c';
                                        $subtext = 'Offline or not in the field';
                                    } elseif (!$hasUnit) {
                                        $status = 'STANDBY';
                                        $statusClass = 'standby';
                                        $statusColor = '#64748b';
                                        $subtext = 'Waiting for unit assignment';
                                    } else {
                                        $status = 'AVAILABLE';
                                        $statusClass = 'available';
                                        $statusColor = '#15803D';
                                        $subtext = 'Ready for booking assignment';
                                    }
                                @endphp
                                <div class="driver-status-badge-wrap">
                                    <span class="driver-status-badge {{ $statusClass }}"
                                        style="background: {{ $statusColor }};color:#fff;padding:7px 18px;border-radius:16px;font-weight:600;font-size:1rem;display:inline-block;min-width:110px;text-align:center;">
                                        {{ $status }}
                                    </span>
                                    <div class="driver-status-subtext"
                                        style="font-size:0.92rem;color:#64748b;margin-top:4px;">
                                        {{ $subtext }}
                                    </div>
                                </div>
                                <div class="driver-status-subtext" style="font-size:0.92rem;color:#64748b;margin-top:4px;">
                                    {{ $subtext }}
                                </div>
                            </div>

                        </div>
                    @empty
                        <p>No team leaders found.</p>
                    @endforelse
                </div>
            </div>

            {{-- ── Right sidebar: queue panels ── --}}
            <aside class="tl-sidebar">

                <div class="ops-queue-card">
                    <div class="ops-queue-card__head">
                        <h3>Online</h3>
                        <span class="ops-queue-count">{{ $readyQueue->count() }}</span>
                    </div>
                    <div class="ops-queue-list">
                        @forelse ($readyQueue as $queueLeader)
                            @php $queueState = $teamLeaderStatuses->get($queueLeader->id) ?? []; @endphp
                            <div class="ops-queue-item">
                                <strong>{{ $queueLeader->name }}</strong>
                                <span>{{ $queueState['unit_name'] ?? 'No unit' }}</span>
                            </div>
                        @empty
                            <p class="ops-queue-empty">No team leaders ready.</p>
                        @endforelse
                    </div>
                </div>

                <div class="ops-queue-card">
                    <div class="ops-queue-card__head">
                        <h3>Currently Deployed</h3>
                        <span class="ops-queue-count">{{ $activeQueue->count() }}</span>
                    </div>
                    <div class="ops-queue-list">
                        @forelse ($activeQueue as $queueLeader)
                            @php $queueState = $teamLeaderStatuses->get($queueLeader->id) ?? []; @endphp
                            <div class="ops-queue-item">
                                <strong>{{ $queueLeader->name }}</strong>
                                <span>{{ $queueState['unit_name'] ?? 'No unit' }} ·
                                    {{ $queueState['unit_status_label'] ?? 'On Job' }}</span>
                            </div>
                        @empty
                            <p class="ops-queue-empty">No active crews right now.</p>
                        @endforelse
                    </div>
                </div>

                <div class="ops-queue-card">
                    <div class="ops-queue-card__head">
                        <h3>Temporarily Unavailable</h3>
                        <span class="ops-queue-count">{{ $unavailableQueue->count() }}</span>
                    </div>
                    <div class="ops-queue-list">
                        @forelse ($unavailableQueue as $queueLeader)
                            @php $queueState = $teamLeaderStatuses->get($queueLeader->id) ?? []; @endphp
                            <div class="ops-queue-item">
                                <strong>{{ $queueLeader->name }}</strong>
                                <span>{{ $queueState['presence_label'] ?? 'Offline' }} ·
                                    {{ $queueState['operational_status_label'] ?? 'Not Available' }}</span>
                            </div>
                        @empty
                            <p class="ops-queue-empty">All leaders are ready.</p>
                        @endforelse
                    </div>
                </div>

            </aside>
        </div>
    </div>
    <script src="{{ asset('dispatcher/js/drivers.js') }}"></script>
@endsection
@extends('admin-dashboard.layouts.app')

@section('title', 'Team Leaders')

@section('content')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/drivers.css') }}">
    <style>
        /* ── Focus highlight when redirected from dispatcher after sending a quote ── */
        @keyframes tl-focus-pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.55);
            }

            50% {
                box-shadow: 0 0 0 10px rgba(59, 130, 246, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0);
            }
        }

        .tl-card--focus-highlight {
            outline: 2px solid #3b82f6;
            outline-offset: 3px;
            animation: tl-focus-pulse 1s ease-out 3;
        }

        /* ── Two-column page layout ── */
        .tl-page-layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 24px;
            align-items: start;
        }

        .tl-sidebar {
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: sticky;
            top: 20px;
        }

        /* ── Queue panels (sidebar) ── */
        .ops-queue-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.05);
        }

        .ops-queue-card__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .ops-queue-card__head h3 {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
        }

        .ops-queue-count {
            min-width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #fef3c7;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .ops-queue-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 260px;
            overflow-y: auto;
            padding-right: 2px;
        }

        .ops-queue-item {
            padding: 8px 10px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
        }

        .ops-queue-item strong {
            display: block;
            font-size: 0.88rem;
            color: #0f172a;
        }

        .ops-queue-item span {
            display: block;
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 2px;
        }

        .ops-queue-empty {
            margin: 0;
            color: #94a3b8;
            font-size: 0.85rem;
        }

        /* ── Team leader card controls ── */
        .driver-ops-box {
            margin-top: 14px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.05);
        }

        .driver-status-note {
            margin: 10px 0 0;
            padding: 10px 12px;
            border-radius: 12px;
            background: #fffbeb;
            color: #92400e;
            font-size: 0.92rem;
        }

        .driver-ops-title {
            margin: 0 0 10px;
            font-weight: 700;
            color: #0f172a;
        }

        .driver-ops-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .driver-ops-label {
            width: 44px;
            flex-shrink: 0;
            font-size: 0.78rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .driver-ops-input,
        .driver-ops-select {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 10px 12px;
            background: #fff;
        }

        .driver-assignment-btn--secondary {
            margin-top: 10px;
            width: 100%;
            background: #111827;
            color: #fff;
        }

        @media (max-width: 900px) {
            .tl-page-layout {
                grid-template-columns: 1fr;
            }

            .tl-sidebar {
                position: static;
                flex-direction: row;
                flex-wrap: wrap;
            }

            .tl-sidebar .ops-queue-card {
                flex: 1 1 240px;
            }
        }
    </style>

    @php
        $leaderStates = collect($teamLeaderStatuses ?? []);
        $totalLeaders = $teamLeaders->count();
        $busyCount = $leaderStates->where('workload', 'busy')->count();
        $offlineCount = $offlineTeamLeadersCount ?? $leaderStates->where('presence', 'offline')->count();
        $onlineCount = $onlineTeamLeadersCount ?? $leaderStates->where('presence', 'online')->count();
        $defaultFilter = $onlineCount > 0 ? 'online' : 'all';
        $busyPercent = $totalLeaders > 0 ? (int) round(($busyCount / $totalLeaders) * 100) : 0;
        $readyQueue = $teamLeaders
            ->filter(function ($teamLeader) use ($teamLeaderStatuses) {
                $state = $teamLeaderStatuses->get($teamLeader->id) ?? [];

                return ($state['presence'] ?? 'offline') === 'online' &&
                    ($state['workload'] ?? 'unavailable') === 'available';
            })
            ->values();
        $activeQueue = $teamLeaders
            ->filter(function ($teamLeader) use ($teamLeaderStatuses) {
                $state = $teamLeaderStatuses->get($teamLeader->id) ?? [];

                return ($state['workload'] ?? 'unavailable') === 'busy' || ($state['unit_status'] ?? null) === 'on_job';
            })
            ->values();
        $unavailableQueue = $teamLeaders
            ->filter(function ($teamLeader) use ($teamLeaderStatuses) {
                $state = $teamLeaderStatuses->get($teamLeader->id) ?? [];

                return ($state['presence'] ?? 'offline') !== 'online' ||
                    ($state['workload'] ?? 'unavailable') === 'unavailable';
            })
            ->values();
    @endphp

    <div class="drivers-container">
        @if (session('success'))
            <div class="drivers-feedback drivers-feedback--success" id="driversFeedbackSuccess">{{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="drivers-feedback drivers-feedback--error">{{ $errors->first() }}</div>
        @endif

        <div class="drivers-header">
            <div>
                <p class="drivers-eyebrow">Dispatcher view</p>
                <h1 class="drivers-title">Team Leaders</h1>
            </div>
            <span class="drivers-total">{{ $totalLeaders }} records</span>
        </div>


        <div class="tl-page-layout">

            {{-- ── Left: filter buttons + team leader cards ── --}}
            <div class="tl-main">

                <div class="drivers-section-head" style="margin-bottom: 14px;">
                    <h2 class="drivers-section-title">Team Leaders</h2>
                    <div class="drivers-filters" role="group" aria-label="Filter team leaders"
                        data-default-filter="{{ $defaultFilter }}">
                        <button type="button" class="filter-btn {{ $defaultFilter === 'all' ? 'is-active' : '' }}"
                            data-filter="all">View All</button>
                        <button type="button" class="filter-btn {{ $defaultFilter === 'online' ? 'is-active' : '' }}"
                            data-filter="online">Online</button>
                        <button type="button" class="filter-btn" data-filter="offline">Offline</button>
                    </div>
                </div>

                <div class="drivers-grid">
                    @forelse($teamLeaders as $teamLeader)
                        @php
                            $leaderState = $teamLeaderStatuses->get($teamLeader->id) ?? [];
                        @endphp
                        <div class="driver-card {{ $defaultFilter === 'online' && ($leaderState['presence'] ?? 'offline') !== 'online' ? 'is-hidden' : '' }}"
                            data-driver-id="{{ $teamLeader->id }}"
                            data-presence="{{ $leaderState['presence'] ?? 'offline' }}"
                            data-workload="{{ $leaderState['workload'] ?? 'unavailable' }}">
                            <div class="driver-header">
                                <div class="driver-profile">
                                    <div class="driver-avatar">
                                        {{ strtoupper(substr($teamLeader->name, 0, 2)) }}
                                    </div>
                                    <div class="driver-info">
                                        <h3>{{ $teamLeader->name }}</h3>
                                        <p class="driver-presence-text">
                                            {{ strtoupper($leaderState['presence_label'] ?? 'Offline') }}</p>
                                    </div>
                                </div>

                                <div class="driver-badges">
                                    <span class="mini-pill mini-pill-unit">
                                        {{ $leaderState['unit_name'] ?? ($teamLeader->unit?->name ?? 'No assigned unit') }}
                                    </span>
                                    <span class="mini-pill mini-pill-driver">
                                        {{ $leaderState['driver_name'] ?? (optional(optional($teamLeader->unit)->driver)->name ?? 'No member driver') }}
                                    </span>
                                </div>
                            </div>

                            <div class="driver-body">
                                {{-- Automatic status badge and subtext --}}
                                @php
                                    $isOnline = ($leaderState['presence'] ?? 'offline') === 'online';
                                    $hasUnit =
                                        !empty($leaderState['unit_name']) &&
                                        $leaderState['unit_name'] !== 'No assigned unit';
                                    if (!$isOnline) {
                                        $status = 'NOT AVAILABLE';
                                        $statusClass = 'not-available';
                                        $statusColor = '#b91c1c';
                                        $subtext = 'Offline or not in the field';
                                    } elseif (!$hasUnit) {
                                        $status = 'STANDBY';
                                        $statusClass = 'standby';
                                        $statusColor = '#64748b';
                                        $subtext = 'Waiting for unit assignment';
                                    } else {
                                        $status = 'AVAILABLE';
                                        $statusClass = 'available';
                                        $statusColor = '#15803D';
                                        $subtext = 'Ready for booking assignment';
                                    }
                                @endphp
                                <div class="driver-status-badge-wrap">
                                    <span class="driver-status-badge {{ $statusClass }}"
                                        style="background: {{ $statusColor }};color:#fff;padding:7px 18px;border-radius:16px;font-weight:600;font-size:1rem;display:inline-block;min-width:110px;text-align:center;">
                                        {{ $status }}
                                    </span>
                                    <div class="driver-status-subtext"
                                        style="font-size:0.92rem;color:#64748b;margin-top:4px;">
                                        {{ $subtext }}
                                    </div>
                                </div>
                                <div class="driver-status-subtext"
                                    style="font-size:0.92rem;color:#64748b;margin-top:4px;">
                                    {{ $subtext }}
                                </div>
                            </div>

                        </div>{{-- /.tl-main --}}

                        {{-- ── Right sidebar: queue panels ── --}}
                        <aside class="tl-sidebar">

                            <div class="ops-queue-card">
                                <div class="ops-queue-card__head">
                                    <h3>Online</h3>
                                    <span class="ops-queue-count">{{ $readyQueue->count() }}</span>
                                </div>
                                <div class="ops-queue-list">
                                    @forelse ($readyQueue as $queueLeader)
                                        @php $queueState = $teamLeaderStatuses->get($queueLeader->id) ?? []; @endphp
                                        <div class="ops-queue-item">
                                            <strong>{{ $queueLeader->name }}</strong>
                                            <span>{{ $queueState['unit_name'] ?? 'No unit' }}</span>
                                        </div>
                                    @empty
                                        <p class="ops-queue-empty">No team leaders ready.</p>
                                    @endforelse
                                </div>
                            </div>

                            <div class="ops-queue-card">
                                <div class="ops-queue-card__head">
                                    <h3>Currently Deployed</h3>
                                    <span class="ops-queue-count">{{ $activeQueue->count() }}</span>
                                </div>
                                <div class="ops-queue-list">
                                    @forelse ($activeQueue as $queueLeader)
                                        @php $queueState = $teamLeaderStatuses->get($queueLeader->id) ?? []; @endphp
                                        <div class="ops-queue-item">
                                            <strong>{{ $queueLeader->name }}</strong>
                                            <span>{{ $queueState['unit_name'] ?? 'No unit' }} ·
                                                {{ $queueState['unit_status_label'] ?? 'On Job' }}</span>
                                        </div>
                                    @empty
                                        <p class="ops-queue-empty">No active crews right now.</p>
                                    @endforelse
                                </div>
                            </div>

                            <div class="ops-queue-card">
                                <div class="ops-queue-card__head">
                                    <h3>Temporarily Unavailable</h3>
                                    <span class="ops-queue-count">{{ $unavailableQueue->count() }}</span>
                                </div>
                                <div class="ops-queue-list">
                                    @forelse ($unavailableQueue as $queueLeader)
                                        @php $queueState = $teamLeaderStatuses->get($queueLeader->id) ?? []; @endphp
                                        <div class="ops-queue-item">
                                            <strong>{{ $queueLeader->name }}</strong>
                                            <span>{{ $queueState['presence_label'] ?? 'Offline' }} ·
                                                {{ $queueState['operational_status_label'] ?? 'Not Available' }}</span>
                                        </div>
                                    @empty
                                        <p class="ops-queue-empty">All leaders are ready.</p>
                                    @endforelse
                                </div>
                            </div>

                        </aside>{{-- /.tl-sidebar --}}

                </div>{{-- /.tl-page-layout --}}

            </div>{{-- /.drivers-container --}}

            <script src="{{ asset('dispatcher/js/drivers.js') }}"></script>
        @endsection
