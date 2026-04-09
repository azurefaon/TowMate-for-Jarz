@extends('admin-dashboard.layouts.app')

@section('title', 'Team Leaders')

<link rel="stylesheet" href="{{ asset('dispatcher/css/drivers.css') }}">

@section('content')
    <div class="drivers-container">
        <div class="drivers-header">
            <h1 class="drivers-title">Team Leaders Dashboard ({{ count($teamLeaders) }})</h1>
        </div>

        <div class="drivers-stats">
            <div class="stat-card available-count">
                <div class="stat-number">{{ $teamLeaders->filter(fn($tl) => !$busyTeamLeaders->contains($tl->id))->count() }}
                </div>
                <div class="stat-label">Available</div>
            </div>
            <div class="stat-card busy-count">
                <div class="stat-number">{{ $busyTeamLeaders->count() }}</div>
                <div class="stat-label">Busy</div>
            </div>
        </div>

        <div class="drivers-grid">
            @forelse($teamLeaders as $teamLeader)
                <div class="driver-card" data-driver-id="{{ $teamLeader->id }}">
                    <div class="driver-header">
                        <div style="display: flex; align-items: center;">
                            <div class="driver-avatar">
                                {{ strtoupper(substr($teamLeader->name, 0, 2)) }}
                            </div>
                            <div class="driver-info">
                                <h3>{{ $teamLeader->name }}</h3>
                                <p class="driver-role">{{ $teamLeader->phone ?? 'No phone' }}</p>
                            </div>
                        </div>
                        <div class="availability-toggle">
                            <label class="toggle-switch">
                                <input type="checkbox" data-driver-id="{{ $teamLeader->id }}"
                                    {{ !$busyTeamLeaders->contains($teamLeader->id) ? 'checked' : '' }}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="driver-body">
                        <div class="assignment-grid">
                            <div class="assignment-item">
                                <span class="assignment-label">Unit</span>
                                <span class="assignment-value">{{ $teamLeader->unit->name ?? 'Unassigned' }}</span>
                            </div>
                            <div class="assignment-item">
                                <span class="assignment-label">Member (Driver)</span>
                                <span class="assignment-value">{{ $teamLeader->unit->driver->name ?? 'No driver' }}</span>
                            </div>

                        </div>

                        <div
                            class="driver-status status-{{ $busyTeamLeaders->contains($teamLeader->id) ? 'busy' : 'available' }}">
                            {{ $busyTeamLeaders->contains($teamLeader->id) ? 'Busy' : 'Available' }}
                        </div>
                    </div>
                </div>
            @empty
                <div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #6b7280;">
                    <i data-lucide="users" style="width: 80px; height: 80px; margin-bottom: 20px; opacity: 0.5;"></i>
                    <h3>No team leaders found</h3>
                    <p>Add team leaders through superadmin panel</p>
                </div>
            @endforelse
        </div>
    </div>

    <script src="{{ asset('dispatcher/js/drivers.js') }}"></script>
@endsection
