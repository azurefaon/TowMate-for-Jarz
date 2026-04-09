@extends('layouts.superadmin')

@section('title', 'Bookings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/bookings.css') }}">
@endpush

@section('content')
    <div class="booking-container">

        <div class="booking-header">

            <div>
                <h1>Bookings Overview</h1>
                <p>Monitor and review all towing service bookings in the system.</p>
            </div>

            <form method="GET" class="booking-filters">

                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" name="search" value="{{ request('search') }}"
                        placeholder="Search bookings, customers, or locations">
                </div>

                <select name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="requested" {{ request('status') == 'requested' ? 'selected' : '' }}>Requests</option>
                    <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                    <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                </select>

            </form>

        </div>

        <div class="booking-summary">

            <div class="summary-card">
                <div class="summary-icon blue">
                    <i data-lucide="clipboard"></i>
                </div>
                <div class="summary-number">{{ $bookings->total() }}</div>
                <div class="summary-title">Total Bookings</div>
            </div>

            <div class="summary-card">
                <div class="summary-icon yellow">
                    <i data-lucide="clock"></i>
                </div>
                <div class="summary-number">{{ $bookings->where('status', 'requested')->count() }}</div>
                <div class="summary-title">Requests</div>
            </div>

            <div class="summary-card">
                <div class="summary-icon orange">
                    <i data-lucide="truck"></i>
                </div>
                <div class="summary-number">{{ $bookings->whereIn('status', ['assigned', 'on_job'])->count() }}</div>
                <div class="summary-title">Active</div>
            </div>

            <div class="summary-card">
                <div class="summary-icon green">
                    <i data-lucide="check-circle"></i>
                </div>
                <div class="summary-number">{{ $bookings->where('status', 'completed')->count() }}</div>
                <div class="summary-title">Completed</div>
            </div>

        </div>

        <div class="booking-table-card">

            <table class="booking-table">

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Truck</th>
                        <th>Unit</th>
                        <th>Pickup</th>
                        <th>Drop-off</th>
                        <th>Distance</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>

                    @foreach ($bookings as $booking)
                        <tr>

                            <td class="booking-id">BK-{{ $booking->id }}</td>

                            <td>
                                <div class="customer-name">{{ $booking->customer->full_name }}</div>
                            </td>

                            <td>{{ $booking->truckType->name }}</td>

                            <td>{{ $booking->unit->name ?? 'Unassigned' }}</td>

                            <td class="location">{{ $booking->pickup_address }}</td>
                            <td class="location">{{ $booking->dropoff_address }}</td>

                            <td>{{ $booking->distance_km }} km</td>

                            <td class="price">₱{{ number_format($booking->final_total, 2) }}</td>

                            <td>
                                <span class="status {{ $booking->status }}">
                                    {{ ucfirst($booking->status) }}
                                </span>
                            </td>

                            <td>
                                <button onclick="openBooking({{ $booking->id }})" class="view-btn">
                                    View
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

    <div id="bookingModal" class="booking-modal">

        <div class="booking-modal-content">

            <div class="modal-header">
                <h2 id="m_id"></h2>
                <button onclick="closeBooking()">✕</button>
            </div>

            <div class="modal-grid">

                <div class="modal-card">

                    <div class="modal-section">
                        <span class="label">Customer</span>
                        <h3 id="m_customer"></h3>
                    </div>

                    <div class="modal-section">
                        <span class="label">Truck</span>
                        <h3 id="m_truck"></h3>
                    </div>

                    <div class="modal-section">
                        <span class="label">Assigned Unit</span>
                        <h3 id="m_unit"></h3>
                    </div>

                    <div class="divider"></div>

                    <div class="modal-section">
                        <span class="label">Pickup</span>
                        <p id="m_pickup"></p>
                    </div>

                    <div class="modal-section">
                        <span class="label">Drop-off</span>
                        <p id="m_dropoff"></p>
                    </div>

                    <div class="divider"></div>

                    <div class="modal-inline">
                        <div>
                            <span class="label">Distance</span>
                            <h4 id="m_distance"></h4>
                        </div>
                        <div>
                            <span class="label">Total</span>
                            <h4 id="m_total"></h4>
                        </div>
                    </div>

                    <span id="m_status" class="status"></span>

                </div>

                <div class="modal-card receipt">

                    <span class="label">Receipt</span>
                    <h3 id="m_receipt"></h3>

                    <a id="m_download" class="download-btn">Download Receipt</a>

                </div>

            </div>

            <div class="modal-footer">
                <button onclick="closeBooking()" class="close-btn">Close</button>
            </div>

        </div>

    </div>
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
                    document.getElementById('m_total').innerText = "₱" + data.final_total
                    document.getElementById('m_status').innerText = data.status

                    if (data.receipt) {
                        document.getElementById('m_receipt').innerText = data.receipt.receipt_number
                        document.getElementById('m_download').href = "/" + data.receipt.pdf_path
                    } else {
                        document.getElementById('m_receipt').innerText = "No receipt"
                        document.getElementById('m_download').style.display = "none"
                    }

                    document.getElementById('bookingModal').style.display = "flex"
                })
        }

        function closeBooking() {
            document.getElementById('bookingModal').style.display = "none"
        }

        const searchInput = document.querySelector('[name="search"]');

        let timer;

        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                searchInput.form.submit();
            }, 500);
        });
    </script>
@endpush
