@extends('teamleader.layouts.app')

@php use Illuminate\Support\Str; @endphp

@section('title', 'Accepted Bookings')
@section('page_title', 'Accepted Bookings')

@section('content')
    <div class="tl-task-board">
        <section class="tl-section-card tl-section-card--compact">
            <div class="tl-section-card__header">
                <div>
                    <p class="tl-eyebrow">Approved Jobs</p>
                    <h3>Accepted requests</h3>
                    <p>Review jobs approved by dispatch for your team.</p>
                </div>
                <span class="tl-soft-badge">{{ $bookings->count() }} visible</span>
            </div>

            <div class="tl-task-card__note">
                These jobs are already approved and ready for the next towing step with your crew.
            </div>
        </section>

        @if ($bookings->count())
            <section class="tl-task-grid">
                @foreach ($bookings as $booking)
                    <article class="tl-task-card">
                        <div class="tl-task-card__header">
                            <div>
                                <p class="tl-task-card__eyebrow">Booking {{ $booking->job_code }}</p>
                                <h3>{{ Str::limit($booking->pickup_address, 32) }} →
                                    {{ Str::limit($booking->dropoff_address, 32) }}</h3>
                            </div>
                            <span class="tl-status-badge assigned">Accepted</span>
                        </div>

                        <div class="tl-task-card__meta">
                            <div>
                                <small>Customer</small>
                                <p>{{ $booking->customer->full_name }}</p>
                                <span>{{ $booking->customer->phone }}</span>
                            </div>
                            <div>
                                <small>Vehicle</small>
                                <p>{{ $booking->truckType->name ?? 'Unknown' }}</p>
                                <span>{{ number_format($booking->distance_km, 1) }} km</span>
                            </div>
                            <div>
                                <small>Payment</small>
                                <p>₱{{ number_format($booking->final_total, 2) }}</p>
                                <span>{{ $booking->quotation_number ?? 'Pending quotation' }}</span>
                            </div>
                        </div>
                    </article>
                @endforeach
            </section>

            <section class="tl-section-card">
                {{ $bookings->links() }}
            </section>
        @else
            <div class="tl-empty-state">
                <h3>No accepted bookings available yet</h3>
                <p>Once dispatch approves more jobs for your unit, they will appear here.</p>
            </div>
        @endif
    </div>
@endsection
