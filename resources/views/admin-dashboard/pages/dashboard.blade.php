@extends('admin-dashboard.layouts.app')

@section('content')
    <div class="dashboard-container">

        <div class="dashboard-header">
            <div>
                <h2>Crew Management</h2>
                <p>Real-time operational readiness and fleet distribution</p>
            </div>

            <div class="view-toggle">
                <button class="toggle-btn active">
                    <i data-lucide="grid"></i> Grid View
                </button>
                <button class="toggle-btn">
                    <i data-lucide="map"></i> Live Map
                </button>
            </div>
        </div>

        <div class="stats-grid">

            <div class="stat-card">
                <p>AVAILABLE NOW</p>
                <h3>{{ $available }}</h3>
                <span class="green">Live</span>
            </div>

            <div class="stat-card">
                <p>ACTIVE JOBS</p>
                <h3>{{ $activeJobs }}</h3>
                <span>Ongoing</span>
            </div>

            <div class="stat-card warning">
                <p>DELAYED TRANSIT</p>
                <h3>{{ $delayed }}</h3>
                <span>Action Required</span>
            </div>

            <div class="stat-card">
                <p>FLEET HEALTH what??</p>
                <h3>{{ $fleetHealth }}%</h3>
                <span>Status</span>
            </div>

        </div>

        <div class="crew-grid">

            @forelse($drivers as $driver)
                <div class="crew-card">
                    <div class="crew-top">

                        <div class="avatar"
                            style="background: linear-gradient(135deg, {{ ['#16a34a', '#2563eb', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2'][$driver->id % 6] }}, #00000020 )">
                            {{ strtoupper(substr($driver->name, 0, 1)) }}
                        </div>

                        <div>
                            <h4>{{ $driver->name }}</h4>
                            <small>Unit: {{ $driver->unit ?? 'N/A' }}</small>
                        </div>

                        <span class="badge {{ $driver->status == 'on_job' ? 'busy' : '' }}">
                            {{ ucfirst($driver->status ?? 'available') }}
                        </span>
                    </div>

                    <p>{{ $driver->location ?? 'Unknown location' }}</p>

                    <div class="crew-actions">
                        <button class="btn-light">Ping</button>
                        <button class="btn">Details</button>
                    </div>
                </div>

            @empty

                <p>No drivers available</p>
            @endforelse

        </div>
    </div>

    <script src="{{ asset('dispatcher/js/dashboard.js') }}"></script>
    <script>
        lucide.createIcons();
    </script>
@endsection
