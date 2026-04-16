@extends('admin-dashboard.layouts.app')

@section('title', 'Active Jobs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/jobs.css') }}">
@endpush

@section('content')
    <div class="jobs-page">
        <div class="jobs-hero">
            <div class="jobs-hero-copy">
                <p class="jobs-eyebrow">Dispatcher view</p>
                <h1 class="jobs-title">Active Jobs</h1>
                <p class="jobs-subtitle">Taken jobs, en-route crews, and live towing operations.</p>
            </div>

            <div class="jobs-hero-actions">
                <span class="jobs-status-pill">
                    <i data-lucide="truck"></i>
                    <span>{{ $stats['total'] }} live jobs</span>
                </span>

                <a href="{{ route('admin.dispatch') }}" class="jobs-link-btn">
                    <i data-lucide="arrow-left"></i>
                    <span>Back to Dispatch</span>
                </a>
            </div>
        </div>

        <div class="jobs-stats-grid">
            <div class="jobs-stat-card">
                <span>Total Active</span>
                <strong>{{ $stats['total'] }}</strong>
                <small>All taken and moving tow jobs.</small>
            </div>
            <div class="jobs-stat-card info">
                <span>Assigned</span>
                <strong>{{ $stats['assigned'] }}</strong>
                <small>Queued with a field team.</small>
            </div>
            <div class="jobs-stat-card success">
                <span>Active Towing</span>
                <strong>{{ $stats['on_job'] }}</strong>
                <small>Currently in live roadside service.</small>
            </div>
            <div class="jobs-stat-card warning">
                <span>Delayed</span>
                <strong>{{ $stats['delayed'] }}</strong>
                <small>Needs dispatcher attention.</small>
            </div>
        </div>

        <div class="jobs-section-head">
            <div>
                <h2>Live job board</h2>
                <p>Current dispatcher handoffs, route details, and assigned towing crews.</p>
            </div>
            <span class="jobs-section-pill">{{ $stats['total'] }} active now</span>
        </div>

        <div class="jobs-grid">
            @forelse ($jobs as $job)
                <article class="job-card" data-job-id="{{ $job->job_code }}"
                    data-customer="{{ optional($job->customer)->full_name ?? (optional($job->customer)->name ?? 'Customer unavailable') }}"
                    data-service="{{ optional($job->truckType)->name ?? 'General Tow' }}"
                    data-status="{{ ucwords(str_replace('_', ' ', $job->status)) }}"
                    data-unit="{{ optional($job->unit)->name ?? 'Unassigned' }}"
                    data-teamleader="{{ optional(optional($job->unit)->teamLeader)->full_name ?? (optional(optional($job->unit)->teamLeader)->name ?? (optional($job->assignedTeamLeader)->name ?? 'Unassigned')) }}"
                    data-driver="{{ $job->driver_name ?? (optional(optional($job->unit)->driver)->full_name ?? (optional(optional($job->unit)->driver)->name ?? 'No member assigned')) }}"
                    data-created="{{ $job->created_at->diffForHumans() }}"
                    data-pickup="{{ $job->pickup_address ?? 'Pickup location pending' }}"
                    data-dropoff="{{ $job->dropoff_address ?? 'Drop-off location pending' }}">

                    <div class="job-header">
                        <span class="job-id">Job {{ $job->job_code }}</span>
                        <span class="status-badge status-{{ str_replace('_', '-', $job->status) }}">
                            {{ ucwords(str_replace('_', ' ', $job->status)) }}
                        </span>
                    </div>

                    <div class="job-body">
                        <div class="job-route">
                            <strong>{{ \Illuminate\Support\Str::limit($job->pickup_address ?? 'Pickup pending', 35) }}</strong>
                            <span>to</span>
                            <span>{{ \Illuminate\Support\Str::limit($job->dropoff_address ?? 'Drop-off pending', 32) }}</span>
                        </div>

                        <div class="job-meta-grid">
                            <div class="job-meta-item">
                                <span class="job-label">Customer</span>
                                <span
                                    class="job-value">{{ optional($job->customer)->full_name ?? (optional($job->customer)->name ?? 'Unknown') }}</span>
                            </div>
                            <div class="job-meta-item">
                                <span class="job-label">Tow Type</span>
                                <span class="job-value">{{ optional($job->truckType)->name ?? 'General Tow' }}</span>
                            </div>
                            <div class="job-meta-item">
                                <span class="job-label">Unit</span>
                                <span class="job-value">{{ optional($job->unit)->name ?? 'Unassigned' }}</span>
                            </div>
                            <div class="job-meta-item">
                                <span class="job-label">Team Leader</span>
                                <span
                                    class="job-value">{{ optional(optional($job->unit)->teamLeader)->full_name ?? (optional(optional($job->unit)->teamLeader)->name ?? (optional($job->assignedTeamLeader)->name ?? 'Unassigned')) }}</span>
                            </div>
                            <div class="job-meta-item full-width">
                                <span class="job-label">Member Driver</span>
                                <span
                                    class="job-value">{{ $job->driver_name ?? (optional(optional($job->unit)->driver)->full_name ?? (optional(optional($job->unit)->driver)->name ?? 'No member assigned')) }}</span>
                            </div>
                        </div>

                        <button type="button" class="job-view-btn js-open-job-modal">
                            <i data-lucide="eye"></i>
                            <span>View details</span>
                        </button>
                    </div>
                </article>
            @empty
                <div class="empty-state jobs-empty-state">
                    <i data-lucide="clipboard-list"></i>
                    <h3>No active jobs</h3>
                    <p>Team Leader-taken and active towing jobs will appear here as soon as the field crew starts work.</p>
                </div>
            @endforelse
        </div>

        <div class="jobs-pagination">
            {{ $jobs->onEachSide(1)->links() }}
        </div>

        <div class="job-modal" id="jobModal">
            <div class="modal-overlay"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title" id="modalTitle">Job Details</h2>
                        <p class="modal-subtitle">Dispatcher view of the assigned towing job.</p>
                    </div>
                    <button type="button" class="close-modal" aria-label="Close">×</button>
                </div>

                <div class="modal-body">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Customer</span>
                            <span class="detail-value" id="job-customer">—</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Tow Type</span>
                            <span class="detail-value" id="job-service">—</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status</span>
                            <span class="detail-value" id="job-status">—</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Unit</span>
                            <span class="detail-value" id="job-unit">—</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Team Leader</span>
                            <span class="detail-value" id="job-teamleader">—</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Member Driver</span>
                            <span class="detail-value" id="job-driver">—</span>
                        </div>
                        <div class="detail-item full-width">
                            <span class="detail-label">Pickup</span>
                            <span class="detail-value" id="job-pickup">—</span>
                        </div>
                        <div class="detail-item full-width">
                            <span class="detail-label">Drop-off</span>
                            <span class="detail-value" id="job-dropoff">—</span>
                        </div>
                        <div class="detail-item full-width">
                            <span class="detail-label">Created</span>
                            <span class="detail-value" id="job-time">—</span>
                        </div>
                    </div>
                </div>

                <div class="modal-actions">
                    <a href="{{ route('admin.dispatch') }}" class="btn btn-secondary">Open Dispatch Queue</a>
                    <button type="button" class="btn btn-primary close-modal-btn">
                        <i data-lucide="check"></i>
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dispatcher/js/jobs.js') }}"></script>
@endpush
