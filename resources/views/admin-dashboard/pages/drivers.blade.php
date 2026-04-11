@extends('admin-dashboard.layouts.app')

@section('title', 'Team Leaders')

<link rel="stylesheet" href="{{ asset('dispatcher/css/drivers.css') }}">

@section('content')
    @php
        $leaderStates = collect($teamLeaderStatuses ?? []);
        $totalLeaders = $teamLeaders->count();
        $busyCount = $leaderStates->where('workload', 'busy')->count();
        $offlineCount = $offlineTeamLeadersCount ?? $leaderStates->where('presence', 'offline')->count();
        $onlineCount = $onlineTeamLeadersCount ?? $leaderStates->where('presence', 'online')->count();
        $defaultFilter = $onlineCount > 0 ? 'online' : 'all';
        $busyPercent = $totalLeaders > 0 ? (int) round(($busyCount / $totalLeaders) * 100) : 0;
    @endphp

    <div class="drivers-container">
        <div class="drivers-header">
            <div>
                <p class="drivers-eyebrow">Dispatcher view</p>
                <h1 class="drivers-title">Team Leaders</h1>
            </div>
            <span class="drivers-total">{{ $totalLeaders }} records</span>
        </div>

        <div class="drivers-overview compact-overview">
            <div class="drivers-stats">
                <div class="stat-card busy-count">
                    <div class="stat-card-top">
                        <div class="stat-chip">
                            <i data-lucide="clock-3"></i>
                            <span>Busy</span>
                        </div>
                    </div>
                    <div class="stat-number">{{ $busyCount }}</div>
                    <div class="stat-progress-row">
                        <div class="stat-progress-track">
                            <span style="width: {{ $busyPercent }}%"></span>
                        </div>
                        <strong>{{ $busyPercent }}%</strong>
                    </div>
                </div>

                <div class="stat-card offline-count">
                    <div class="stat-card-top">
                        <div class="stat-chip">
                            <i data-lucide="circle-off"></i>
                            <span>Not Available</span>
                        </div>
                        <span class="stat-pill inactive-pill">Inactive</span>
                    </div>
                    <div class="stat-number">{{ $offlineCount }}</div>
                </div>

                <div class="stat-card online-count">
                    <div class="stat-card-top">
                        <div class="stat-chip">
                            <i data-lucide="zap"></i>
                            <span>Online Now</span>
                        </div>
                        <span class="stat-pill live-pill">Live</span>
                    </div>
                    <div class="stat-number">{{ $onlineCount }}</div>
                </div>
            </div>
        </div>

        <div class="drivers-section-head">
            <h2 class="drivers-section-title">Team Leaders</h2>
            <div class="drivers-filters" role="group" aria-label="Filter team leaders"
                data-default-filter="{{ $defaultFilter }}">
                <button type="button" class="filter-btn {{ $defaultFilter === 'all' ? 'is-active' : '' }}"
                    data-filter="all">View All</button>
                <button type="button" class="filter-btn {{ $defaultFilter === 'online' ? 'is-active' : '' }}"
                    data-filter="online">Online Only</button>
                <button type="button" class="filter-btn" data-filter="offline">Offline Only</button>
            </div>
        </div>

        <div class="drivers-grid">
            @forelse($teamLeaders as $teamLeader)
                @php
                    $leaderState = $teamLeaderStatuses->get($teamLeader->id) ?? [];
                @endphp
                <div class="driver-card {{ $defaultFilter === 'online' && ($leaderState['presence'] ?? 'offline') !== 'online' ? 'is-hidden' : '' }}"
                    data-driver-id="{{ $teamLeader->id }}" data-presence="{{ $leaderState['presence'] ?? 'offline' }}"
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
                        <div class="driver-status-stack">
                            <div class="driver-status status-{{ $leaderState['workload'] ?? 'unavailable' }}">
                                {{ $leaderState['workload_label'] ?? 'Not Available' }}
                            </div>
                            <div class="driver-status presence-status-{{ $leaderState['presence'] ?? 'offline' }}">
                                {{ $leaderState['presence_label'] ?? 'Offline' }}
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="drivers-empty">
                    <i data-lucide="users"></i>
                    <h3>No team leaders found</h3>
                    <p>Add team leaders through the superadmin panel.</p>
                </div>
            @endforelse
        </div>

        <div class="drivers-empty-filtered" id="driversEmptyFiltered" hidden>
            No team leaders match this filter.
        </div>
    </div>

    <script src="{{ asset('dispatcher/js/drivers.js') }}"></script>
@endsection
