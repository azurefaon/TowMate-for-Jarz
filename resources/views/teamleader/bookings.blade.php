@extends('teamleader.layouts.app')

@php use Illuminate\Support\Str; @endphp

@section('title', 'Accepted Bookings')

@section('content')
    <div class="dashboard-container">
        <div class="section-header">
            <div>
                <h3>Accepted Requests</h3>
                <p>Review jobs approved by dispatch for your team.</p>
            </div>
        </div>

        @if ($bookings->count())
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Booking ID</th>
                            <th>Customer</th>
                            <th>Pickup → Dropoff</th>
                            <th>Vehicle</th>
                            <th>Distance</th>
                            <th>Price</th>
                            <th>Quotation</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $booking)
                            <tr>
                                <td>#{{ $booking->id }}</td>
                                <td>{{ $booking->customer->full_name }}<br><small>{{ $booking->customer->phone }}</small>
                                </td>
                                <td>{{ Str::limit($booking->pickup_address, 25) }} →
                                    {{ Str::limit($booking->dropoff_address, 25) }}</td>
                                <td>{{ $booking->truckType->name ?? 'Unknown' }}</td>
                                <td>{{ number_format($booking->distance_km, 1) }} km</td>
                                <td>₱{{ number_format($booking->final_total, 2) }}</td>
                                <td>{{ $booking->quotation_number ?? 'Pending' }}</td>
                                <td><span class="status-badge accepted">Accepted</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $bookings->links() }}
        @else
            <div class="empty-state">
                <p>No accepted bookings available yet.</p>
            </div>
        @endif
    </div>
@endsection
