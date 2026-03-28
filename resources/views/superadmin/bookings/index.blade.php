@extends('layouts.superadmin')

@section('content')
    <div class="booking-container">

        <div class="booking-header">

            <div>
                <h1>Bookings Overview</h1>
                <p>Monitor and review all towing service bookings in the system.</p>
            </div>

            <div class="booking-filters">

                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" id="bookingSearch" placeholder="Search bookings, customers, or locations">
                </div>

                <select id="statusFilter">
                    <option value="">All Status</option>
                    <option value="requested">Requested</option>
                    <option value="assigned">Assigned</option>
                    <option value="on_job">On Job</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>

            </div>

        </div>


        {{-- SUMMARY CARDS --}}

        <div class="booking-summary">

            <div class="summary-card">
                <div class="summary-icon blue">
                    <i data-lucide="clipboard"></i>
                </div>

                <div class="summary-number">{{ $bookings->count() }}</div>
                <div class="summary-title">Total Bookings</div>
                <div class="summary-desc">Total number of bookings in the system</div>
            </div>


            <div class="summary-card">
                <div class="summary-icon yellow">
                    <i data-lucide="clock"></i>
                </div>

                <div class="summary-number">{{ $bookings->where('status', 'requested')->count() }}</div>
                <div class="summary-title">Pending Requests</div>
                <div class="summary-desc">Bookings waiting for dispatch</div>
            </div>


            <div class="summary-card">
                <div class="summary-icon orange">
                    <i data-lucide="truck"></i>
                </div>

                <div class="summary-number">{{ $bookings->where('status', 'on_job')->count() }}</div>
                <div class="summary-title">Active Jobs</div>
                <div class="summary-desc">Units currently on job</div>
            </div>


            <div class="summary-card">
                <div class="summary-icon green">
                    <i data-lucide="check-circle"></i>
                </div>

                <div class="summary-number">{{ $bookings->where('status', 'completed')->count() }}</div>
                <div class="summary-title">Completed Today</div>
                <div class="summary-desc">Successfully completed bookings today</div>
            </div>

        </div>

        {{-- BOOKINGS TABLE --}}

        <div class="booking-table-card">

            <table class="booking-table">

                <thead>
                    <tr>
                        <th>BOOKING ID</th>
                        <th>CUSTOMER</th>
                        <th>TRUCK TYPE</th>
                        <th>ASSIGNED UNIT</th>
                        <th>PICKUP LOCATION</th>
                        <th>DROP-OFF LOCATION</th>
                        <th>DISTANCE</th>
                        <th>TOTAL PRICE</th>
                        <th>STATUS</th>
                        <th>RECEIPT</th>
                        <th>ACTION</th>
                    </tr>
                </thead>


                <tbody>

                    @foreach ($bookings as $booking)
                        <tr data-status="{{ $booking->status }}"
                            data-search="
        {{ strtolower($booking->customer->full_name) }}
        {{ strtolower($booking->pickup_address) }}
        {{ strtolower($booking->dropoff_address) }}
        {{ strtolower($booking->truckType->name) }} ">

                            <td>BK-{{ $booking->id }}</td>
                            <td>{{ $booking->customer->full_name }}</td>
                            <td>{{ $booking->truckType->name }}</td>
                            <td>{{ $booking->unit->name ?? 'Unassigned' }}</td>
                            <td>{{ $booking->pickup_address }}</td>
                            <td>{{ $booking->dropoff_address }}</td>
                            <td>{{ $booking->distance_km }} km</td>
                            <td>₱{{ number_format($booking->final_total, 2) }}</td>

                            <td>
                                <span class="status {{ $booking->status }}">
                                    {{ ucfirst($booking->status) }}
                                </span>
                            </td>

                            <td>
                                @if ($booking->receipt)
                                    <a href="{{ asset($booking->receipt->pdf_path) }}" class="receipt-download">
                                        Download
                                    </a>
                                @else
                                    <span class="no-receipt">No Receipt</span>
                                @endif
                            </td>

                            <td>
                                <button onclick="openBooking({{ $booking->id }})" class="view-btn">
                                    View Booking
                                </button>
                            </td>

                        </tr>
                    @endforeach

                </tbody>

            </table>
            <div class="pagination-container">
                {{ $bookings->links() }}
            </div>

        </div>

    </div>

    @include('superadmin.bookings.modal')
@endsection


@push('scripts')
    <script>
        function openBooking(id) {

            fetch(`/superadmin/bookings/${id}`)
                .then(res => res.json())
                .then(data => {

                    document.getElementById('m_id').innerText = "BK-" + data.id
                    document.getElementById('m_customer').innerText = data.customer.full_name
                    document.getElementById('m_truck').innerText = data.truck_type.name
                    document.getElementById('m_unit').innerText = data.unit?.name ?? "Unassigned"

                    document.getElementById('m_pickup').innerText = data.pickup_address
                    document.getElementById('m_dropoff').innerText = data.dropoff_address

                    document.getElementById('m_distance').innerText = data.distance_km + " km"
                    document.getElementById('m_base').innerText = "₱" + data.base_rate
                    document.getElementById('m_km').innerText = "₱" + data.per_km_rate

                    document.getElementById('m_total').innerText = "₱" + data.final_total
                    document.getElementById('m_status').innerText = data.status

                    if (data.receipt) {
                        document.getElementById('m_receipt').innerText = data.receipt.receipt_number
                        document.getElementById('m_download').href = "/" + data.receipt.pdf_path
                    }

                    document.getElementById('bookingModal').style.display = "flex"

                })

        }

        function closeBooking() {
            document.getElementById('bookingModal').style.display = "none"
        }

        document.addEventListener("DOMContentLoaded", function() {

            const searchInput = document.getElementById("bookingSearch")
            const statusFilter = document.getElementById("statusFilter")
            const rows = document.querySelectorAll(".booking-table tbody tr")

            function filterBookings() {

                const searchValue = searchInput.value.toLowerCase()
                const statusValue = statusFilter.value

                rows.forEach(row => {

                    const searchData = row.dataset.search
                    const statusData = row.dataset.status

                    const matchSearch = searchData.includes(searchValue)
                    const matchStatus = statusValue === "" || statusData === statusValue

                    if (matchSearch && matchStatus) {
                        row.style.display = ""
                    } else {
                        row.style.display = "none"
                    }

                })

            }

            searchInput.addEventListener("keyup", filterBookings)
            statusFilter.addEventListener("change", filterBookings)

        })
    </script>
@endpush
