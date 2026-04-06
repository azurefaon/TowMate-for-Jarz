@extends('admin-dashboard.layouts.app')

@section('title', 'Dispatch')

@section('content')

    <div class="dashboard-container">

        <div class="incoming-section">

            <div class="section-header">
                <div>
                    <h3>Incoming Requests</h3>
                    <p class="section-sub">Live booking queue</p>
                </div>
                <span class="count">{{ $incomingRequests->count() }}</span>
            </div>

            <div class="incoming-list">

                @forelse($incomingRequests as $booking)
                    <div class="incoming-card">

                        <div class="incoming-left">

                            <div class="incoming-route">
                                <strong>{{ $booking->pickup_location ?? 'Unknown Pickup' }}</strong>
                                <span class="arrow">→</span>
                                <span>{{ $booking->dropoff_location ?? 'Unknown Dropoff' }}</span>
                            </div>

                            <div class="incoming-meta">
                                <span class="time">
                                    {{ $booking->created_at->diffForHumans() }}
                                </span>

                                <span class="status-badge new">
                                    New
                                </span>
                            </div>

                        </div>

                        <div class="incoming-actions">
                            <button class="btn-assign" data-id="{{ $booking->id }}">
                                Assign
                            </button>
                        </div>

                    </div>

                @empty
                    <div class="empty-state">
                        <p>No incoming requests</p>
                    </div>
                @endforelse

            </div>

        </div>

    </div>

@endsection
