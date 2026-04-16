@extends('teamleader.layouts.app')

@section('title', 'Open Jobs')
@section('page_title', 'Open Jobs')

@php
    $teamLeaderAppUrl = rtrim(config('app.url') ?: request()->getSchemeAndHttpHost(), '/');
    $teamLeaderAssetBaseUrl = $teamLeaderAppUrl . '/teamleader-assets';
    $teamLeaderTasksCssPath = public_path('teamleader-assets/css/tasks.css');
@endphp

@push('styles')
    <link rel="stylesheet" type="text/css"
        href="{{ $teamLeaderAssetBaseUrl }}/css/tasks.css?v={{ filemtime($teamLeaderTasksCssPath) }}">

    @if (is_file($teamLeaderTasksCssPath))
        <style>
            {!! file_get_contents($teamLeaderTasksCssPath) !!}
        </style>
    @endif
@endpush

@section('content')
    <div class="tl-task-board" id="taskBoard">
        <section class="tl-section-card tl-section-card--compact">
            <div class="tl-section-card__header">
                <div>
                    <p class="tl-eyebrow">Approved Jobs</p>
                    <h3>Only customer-approved bookings can be accepted by your crew</h3>
                </div>
                <div class="tl-stat-pills">
                    <span class="tl-stat-pill">Queue: <strong
                            data-stat="assigned">{{ $stats['assigned'] ?? 0 }}</strong></span>
                    <span class="tl-stat-pill">Active: <strong
                            data-stat="in_progress">{{ $stats['in_progress'] ?? 0 }}</strong></span>
                    <span class="tl-stat-pill">Waiting: <strong
                            data-stat="waiting_verification">{{ $stats['waiting_verification'] ?? 0 }}</strong></span>
                    <span class="tl-stat-pill">Done Today: <strong
                            data-stat="completed_today">{{ $stats['completed_today'] ?? 0 }}</strong></span>
                </div>
            </div>

            <div class="tl-task-card__note">
                @if (!empty($focusLocked))
                    Your current task stays visible here after refresh. Accept it again to return to the focus screen until
                    it is completed or
                    returned.
                @else
                    Bookings appear here after the customer approves the quotation. Once accepted, the task stays locked to
                    your
                    focused work screen until it is completed or returned.
                @endif
            </div>
        </section>

        <section class="tl-task-grid" id="taskGrid">
            @forelse ($bookings as $booking)
                <article class="tl-task-card" data-booking-id="{{ $booking->job_code }}"
                    data-status="{{ $booking->status }}">
                    <div class="tl-task-card__header">
                        <div>
                            <p class="tl-task-card__eyebrow">Task {{ $booking->job_code }}</p>
                            <h3>{{ $booking->pickup_address }} → {{ $booking->dropoff_address }}</h3>
                        </div>
                        <span
                            class="tl-status-badge {{ $booking->needs_reassignment ? 'waiting-verification' : 'assigned' }}">
                            {{ $booking->needs_reassignment ? 'Returned' : 'Ready' }}
                        </span>
                    </div>

                    <div class="tl-task-card__meta">
                        <div>
                            <small>Customer</small>
                            <p>{{ $booking->customer->full_name ?? 'Guest' }}</p>
                            <span>{{ $booking->customer->phone ?? 'N/A' }}</span>
                        </div>
                        <div>
                            <small>Truck Type</small>
                            <p>{{ $booking->truckType->name ?? 'General Towing' }}</p>
                            <span>Quotation: {{ $booking->quotation_number ?? 'Pending' }}</span>
                        </div>
                        <div>
                            <small>Assigned Truck</small>
                            <p>{{ $booking->unit->name ?? (optional(auth()->user()->unit)->name ?? 'Dispatch-assigned unit') }}
                            </p>
                            <span>{{ $booking->unit->plate_number ?? (optional(auth()->user()->unit)->plate_number ?? 'Plate pending') }}
                                · Updated {{ $booking->updated_at?->diffForHumans() ?? 'just now' }}</span>
                        </div>
                    </div>

                    <div class="tl-task-card__note">
                        @if ($booking->needs_reassignment)
                            This task was returned to dispatch and is still visible here while reassignment is being
                            reviewed.
                            <br>
                            <strong>Reason:</strong> {{ $booking->return_reason ?? 'Awaiting dispatch review.' }}
                        @else
                            Your assigned truck is linked automatically when you accept this job, so your crew can move
                            right
                            away.
                        @endif
                    </div>

                    <div class="tl-task-card__actions">
                        @if (
                            !$booking->needs_reassignment &&
                                (empty($booking->assigned_team_leader_id) || (int) $booking->assigned_team_leader_id === (int) auth()->id()) &&
                                in_array($booking->status, ['confirmed', 'accepted', 'assigned'], true))
                            <button type="button" class="tl-btn tl-btn--primary tl-btn--full" data-booking-action="accept"
                                data-endpoint="{{ route('teamleader.task.accept', $booking) }}">
                                Accept Task
                            </button>
                        @endif
                    </div>
                </article>
            @empty
                <div class="tl-empty-state" id="emptyTaskState">
                    <h3>No open tasks right now</h3>
                    <p>New dispatcher handoffs will appear here when they are ready for a Team Leader to accept.</p>
                </div>
            @endforelse
        </section>
    </div>
@endsection

@push('scripts')
    <script
        src="{{ $teamLeaderAssetBaseUrl }}/js/tasks.js?v={{ filemtime(public_path('teamleader-assets/js/tasks.js')) }}">
    </script>
@endpush
