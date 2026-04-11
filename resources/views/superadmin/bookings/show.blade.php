@extends('layouts.superadmin')

@section('title', 'Booking Details')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/bookings.css') }}">
@endpush

@section('content')

    <div class="page-top">

        <div>

            <h1>Booking {{ $booking->job_code }}</h1>
            <p>Booking details and receipt information</p>

        </div>

        <a href="{{ route('superadmin.bookings.index') }}" class="btn-back">

            <i data-lucide="arrow-left"></i>
            Back

        </a>

    </div>


    <div class="details-grid">


        <div class="details-card">

            <h3>Customer</h3>
            <p>{{ $booking->customer->full_name }}</p>

            <h3>Truck Type</h3>
            <p>{{ $booking->truckType->name }}</p>

            <h3>Pickup</h3>
            <p>{{ $booking->pickup_address }}</p>

            <h3>Drop-off</h3>
            <p>{{ $booking->dropoff_address }}</p>

            <h3>Total Price</h3>
            <p>₱{{ number_format($booking->final_total, 2) }}</p>

            <h3>Status</h3>

            <span class="status-pill {{ $booking->status }}">
                {{ ucfirst($booking->status) }}
            </span>

        </div>


        <div class="details-card">

            <h2>Receipt</h2>

            @if ($booking->receipt)
                <p>
                    Receipt Number:
                    <strong>{{ $booking->receipt->receipt_number }}</strong>
                </p>

                <a href="{{ asset($booking->receipt->pdf_path) }}" class="btn-download">

                    <i data-lucide="download"></i>
                    Download Receipt

                </a>
            @else
                <p>No receipt available for this booking.</p>
            @endif

        </div>

    </div>

@endsection
