@extends('teamleader.layouts.app')

@section('title', 'Dashboard')
@section('page_title', 'Team Leader Dashboard')

@php
    $teamLeaderAppUrl = rtrim(config('app.url') ?: request()->getSchemeAndHttpHost(), '/');
    $teamLeaderRootUrl = $teamLeaderAppUrl . '/teamleader';
    $teamLeaderTasksUrl = $teamLeaderRootUrl . '/tasks';
@endphp

@section('content')

    <div class="tl-dashboard" id="teamLeaderDashboard" data-refresh-url="{{ $teamLeaderTasksUrl }}">

        <section class="tl-hero-card">
            <div class="tl-hero-card__content">
                <p class="tl-hero-card__eyebrow">Today’s Operations</p>
                <h2>Manage Assigned Towing Operations</h2>
                <p class="tl-hero-card__copy">
                    View assigned bookings, coordinate with your crew, and update job progress in real time.
                </p>
            </div>

            <div class="tl-hero-card__actions">
                <div class="tl-hero-card__actions-group">
                    <a href="{{ $teamLeaderTasksUrl }}" class="tl-btn tl-btn--primary">Open Tasks</a>
                </div>
            </div>
        </section>

        <section class="tl-stat-grid" aria-label="Task summary">
            <article class="tl-stat-card">
                <small>Assigned</small>
                <strong data-stat="assigned">{{ $stats['assigned'] ?? 0 }}</strong>
                <span>Tasks assigned by dispatcher</span>
            </article>

            <article class="tl-stat-card">
                <small>In Progress</small>
                <strong data-stat="in_progress">{{ $stats['in_progress'] ?? 0 }}</strong>
                <span>Ongoing towing operations</span>
            </article>

            <article class="tl-stat-card">
                <small>Waiting Verification</small>
                <strong data-stat="waiting_verification">{{ $stats['waiting_verification'] ?? 0 }}</strong>
                <span>Awaiting customer confirmation</span>
            </article>

            <article class="tl-stat-card">
                <small>Completed Today</small>
                <strong data-stat="completed_today">{{ $stats['completed_today'] ?? 0 }}</strong>
                <span>Completed and verified jobs</span>
            </article>
        </section>

        <section class="tl-section-card">
            <div class="tl-section-card__header">
                <div>
                    <p class="tl-eyebrow">Recent Tasks</p>
                    <h3>Assigned jobs</h3>
                </div>
                <a href="{{ $teamLeaderTasksUrl }}" class="tl-inline-link">View all tasks</a>
            </div>

            @if ($recentTasks->count())
                <div class="tl-mini-task-grid">
                    @foreach ($recentTasks as $booking)
                        @php
                            $uiStatus = match ($booking->status) {
                                'accepted', 'assigned' => 'assigned',
                                'in_progress' => 'in-progress',
                                'waiting_verification' => 'waiting-verification',
                                'completed' => 'completed',
                                default => 'assigned',
                            };
                        @endphp

                        <article class="tl-mini-task-card">
                            <div class="tl-mini-task-card__header">
                                <span class="tl-status-badge {{ $uiStatus }}">
                                    {{ str($booking->status)->replace('_', ' ')->title() }}
                                </span>
                                <small>{{ $booking->job_code }}</small>
                            </div>

                            <h4>{{ $booking->pickup_address }} → {{ $booking->dropoff_address }}</h4>
                            <p>{{ $booking->customer->full_name ?? 'Guest' }} • {{ $booking->customer->phone ?? 'N/A' }}
                            </p>
                            <small>{{ $booking->truckType->name ?? 'General Towing' }}</small>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="tl-empty-state">
                    <h3>No assigned tasks</h3>
                    <p>New bookings from dispatch will appear here once assigned to your unit.</p>
                </div>
            @endif
        </section>

    </div>
@endsection

@push('scripts')
    <script
        src="{{ $teamLeaderAppUrl }}/teamleader-assets/js/dashboard.js?v={{ filemtime(public_path('teamleader-assets/js/dashboard.js')) }}">
    </script>
@endpush
