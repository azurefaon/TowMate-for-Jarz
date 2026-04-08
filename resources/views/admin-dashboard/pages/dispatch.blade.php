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
                <div class="view-controls">
                    <div class="view-toggle">
                        <button class="view-btn active" data-view="list" title="List View">
                            <i data-lucide="list"></i>
                        </button>
                        <button class="view-btn" data-view="grid" title="Grid View">
                            <i data-lucide="grid-3x3"></i>
                        </button>
                    </div>
                    <span class="count" id="requestCount">{{ $incomingRequests->count() }}</span>
                </div>
            </div>

            <div class="incoming-list" id="incomingList">

                @forelse($incomingRequests as $booking)
                    <div class="incoming-card" data-id="{{ $booking->id }}"
                        data-created-at="{{ $booking->created_at->toISOString() }}">

                        <div class="incoming-left">

                            <div class="incoming-route">
                                <strong>{{ $booking->pickup_address ?? 'Unknown Pickup' }}</strong>
                                <span class="arrow">→</span>
                                <span>{{ $booking->dropoff_address ?? 'Unknown Dropoff' }}</span>
                            </div>

                            <div class="incoming-details">
                                <span><strong>Customer:</strong> {{ $booking->customer->full_name ?? 'Guest' }}</span>
                                <span><strong>Phone:</strong> {{ $booking->customer->phone ?? 'N/A' }}</span>
                                <span><strong>Vehicle:</strong> {{ $booking->truckType->name ?? 'Unknown' }}</span>
                            </div>

                            <div class="incoming-meta">
                                <span class="time">
                                    {{ $booking->created_at->diffForHumans() }}
                                </span>
                                <span class="status-badge pending">
                                    Pending
                                </span>
                            </div>

                        </div>

                        <div class="incoming-actions">
                            <button class="btn-accept" data-id="{{ $booking->id }}">Accept</button>
                            <button class="btn-reject" data-id="{{ $booking->id }}">Reject</button>
                        </div>

                    </div>

                @empty
                    <div class="empty-state" id="emptyState">
                        <p>No incoming requests</p>
                    </div>
                @endforelse

            </div>

        </div>

    </div>

@endsection
