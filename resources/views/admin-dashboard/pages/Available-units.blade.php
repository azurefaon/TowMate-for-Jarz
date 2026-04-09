@extends('admin-dashboard.layouts.app')

@section('title', 'Available Units')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/available-units.css') }}">
@endpush

@section('content')
    <div class="units-page">
        <section class="units-hero">
            <div>
                <h1 class="units-title">Available Units</h1>
                <p class="units-subtitle">Dispatch-ready units with their current crew.</p>
                @if (!empty($search))
                    <span class="units-filter-pill">Filtered by: {{ $search }}</span>
                @endif
            </div>

            <div class="units-hero-actions">
                <a href="{{ route('admin.dispatch') }}" class="units-link-btn">
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
            <article class="units-stat-card info">
                <span>Ready Team Leaders</span>
                <strong>{{ $stats['ready_team_leaders'] }}</strong>
            </article>
            <article class="units-stat-card success">
                <span>Member Drivers</span>
                <strong>{{ $stats['member_drivers'] }}</strong>
            </article>
            <article class="units-stat-card subtle">
                <span>Truck Types</span>
                <strong>{{ $stats['truck_types'] }}</strong>
            </article>
        </section>

        <section class="units-table-card">
            <div class="units-table-header">
                <div>
                    <h2>Dispatch-ready fleet</h2>
                    <p>Search by unit name, plate number, truck type, or team leader.</p>
                </div>

                <label class="search-box" for="unitSearch">
                    <i data-lucide="search"></i>
                    <input type="text" id="unitSearch" placeholder="Search available units..."
                        value="{{ $search ?? '' }}" autocomplete="off">
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
                            <tr data-name="{{ strtolower($unit->name) }}"
                                data-plate="{{ strtolower($unit->plate_number) }}"
                                data-type="{{ strtolower(optional($unit->truckType)->name ?? '') }}"
                                data-teamleader="{{ strtolower(optional($unit->teamLeader)->full_name ?? (optional($unit->teamLeader)->name ?? '')) }}">
                                <td>
                                    <div class="unit-cell">
                                        <div class="unit-avatar">{{ strtoupper(substr($unit->name, 0, 2)) }}</div>
                                        <div>
                                            <strong class="unit-name">{{ $unit->name }}</strong>
                                            <span class="unit-subtext">Ready since
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
                                    <span class="status-badge available">Available</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="table-empty-cell">
                                    <div class="empty-state">
                                        <i data-lucide="truck"></i>
                                        <h3>No available units</h3>
                                        <p>All towing units are currently assigned or in maintenance.</p>
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
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/available-units.js') }}"></script>
@endpush
