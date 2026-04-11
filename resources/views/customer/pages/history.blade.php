@extends('customer.layouts.app')

@section('title', 'Booking History')

@section('content')
    <link rel="stylesheet" href="{{ asset('customer/css/history.css') }}">

    <div class="history-header">
        <h2>Booking History</h2>
        <p>A detailed overview of your past and ongoing recovery requests. Manage receipts and <br>tracking information from
            one place.</p>
    </div>

    <div class="history-stats">
        <div class="stat-box">
            <div class="stat-top"> <i data-lucide="history"></i> <span>ACTIVITY</span> </div>
            <strong>{{ $totalBookings }}</strong><small>Total Bookings</small>
        </div>
        <div class="stat-box">
            <div class="stat-top"> <i data-lucide="check-circle"></i> <span>SUCCESS</span> </div>
            <strong>{{ $totalCompleted }}</strong> <small>Completed Tows</small>
        </div>
        <div class="stat-box">
            <div class="stat-top"> <i data-lucide="credit-card"></i> <span>FINANCIALS</span> </div>
            <strong>₱{{ number_format($totalSpent, 2) }}</strong> <small>Total Spent</small>
        </div>
    </div>

    <div class="history-filter">
        <form method="GET" style="display:flex; gap:10px;"> <input type="text" name="search"
                placeholder="Search location..." value="{{ request('search') }}">
            <select name="status" id="statusFilter">
                <option value="all" {{ request('status', 'all') == 'all' ? 'selected' : '' }}>All</option>
                <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                <option value="ongoing" {{ request('status') == 'ongoing' ? 'selected' : '' }}>Ongoing</option>
            </select>
        </form>
    </div>

    <div class="view-toggle">
        <button id="gridViewBtn" class="active">Grid</button>
        <button id="tableViewBtn">Table</button>
    </div>

    <div id="gridView" class="history-list">
        @forelse ($bookings as $booking)
            <div class="history-card" data-status="{{ $booking->status }}">

                <div class="card-left">

                    <div class="card-id">
                        <h4>{{ $booking->job_code }}</h4>
                        <span>{{ $booking->created_at->format('M d, Y • H:i') }}</span>

                        <span class="status-badge {{ $booking->status }}">
                            {{ ucfirst($booking->status) }}
                        </span>
                    </div>

                    <div class="card-route">
                        <div class="route-line">
                            <div class="dot start"></div>
                            <div class="line"></div>
                            <div class="dot end"></div>
                        </div>

                        <div class="route-text">
                            <p>{{ $booking->pickup_address }}</p>
                            <small>{{ $booking->dropoff_address }}</small>
                        </div>
                    </div>

                </div>

                <div class="card-center">
                    <strong>{{ $booking->truckType->name ?? '-' }}</strong>
                </div>

                <div class="card-right">

                    <div class="price-block">
                        <span>{{ $booking->unit->driver->name ?? 'Not Assigned' }}</span>
                        <strong>₱{{ number_format($booking->final_total, 2) }}</strong>
                    </div>

                    <div class="actions">

                        <button class="btn-view" onclick="openDetailsModal(this)"
                            data-booking='@json($booking)'>
                            View Details
                        </button>
                    </div>

                </div>

            </div>
        @empty
            <div class="empty-state">
                <h4>No bookings yet</h4>
            </div>
        @endforelse
    </div>

    <div id="tableView" class="history-table-view" style="display:none;">
        <table class="history-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Route</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>Status</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bookings as $booking)
                    <tr>
                        <td>{{ $booking->job_code }}</td>
                        <td>{{ $booking->pickup_address }} → {{ $booking->dropoff_address }}</td>
                        <td>{{ $booking->truckType->name ?? '-' }}</td>
                        <td>{{ $booking->unit->driver->name ?? 'Not Assigned' }}</td>
                        <td><span class="status {{ $booking->status }}">{{ ucfirst($booking->status) }}</span></td>
                        <td>₱{{ number_format($booking->final_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($bookings->hasPages())
        <div class="pagination-wrapper">

            @if ($bookings->onFirstPage())
                <span class="pagination-btn disabled">Prev</span>
            @else
                <a href="{{ $bookings->previousPageUrl() }}" class="pagination-btn">Prev</a>
            @endif

            @foreach ($bookings->links()->elements[0] ?? [] as $page => $url)
                @if ($page == $bookings->currentPage())
                    <span class="pagination-btn active">{{ $page }}</span>
                @else
                    <a href="{{ $url }}" class="pagination-btn">{{ $page }}</a>
                @endif
            @endforeach

            @if ($bookings->hasMorePages())
                <a href="{{ $bookings->nextPageUrl() }}" class="pagination-btn">Next</a>
            @else
                <span class="pagination-btn disabled">Next</span>
            @endif

        </div>
    @endif

    <div id="trackingModal" class="tracking-modal">
        <div class="tracking-box">

            <div class="tracking-header">
                <h3>Live Tracking</h3>
                <button onclick="closeTracking()">✕</button>
            </div>

            <div id="map" class="map-box"></div>

        </div>
    </div>

    <div id="detailsModal" class="confirm-modal hidden">

        <div class="fullscreen-modal">

            <div class="modal-header">
                <h2>Booking Details</h2>
                <button onclick="closeDetailsModal()">✕</button>
            </div>

            <div class="modal-body">

                <div class="modal-section">
                    <h4>Locations</h4>

                    <div class="modal-row">
                        <span>Pickup</span>
                        <strong id="dPickup"></strong>
                    </div>

                    <div class="modal-row">
                        <span>Dropoff</span>
                        <strong id="dDrop"></strong>
                    </div>
                </div>

                <div class="modal-section">
                    <h4>Service Details</h4>

                    <div class="modal-row">
                        <span>Vehicle</span>
                        <strong id="dVehicle"></strong>
                    </div>

                    <div class="modal-row">
                        <span>Driver</span>
                        <strong id="dDriver"></strong>
                    </div>

                    <div class="modal-row">
                        <span>Status</span>
                        <strong id="dStatus"></strong>
                    </div>

                    <div class="modal-row">
                        <span>Date</span>
                        <strong id="dDate"></strong>
                    </div>
                </div>

                <div class="modal-section total-section">
                    <div class="modal-total">
                        <span>Total</span>
                        <h1 id="dTotal">₱0</h1>
                    </div>
                </div>

            </div>

            <div class="modal-footer">
                <button class="cancel-btn danger" onclick="cancelBooking()">
                    Cancel Booking
                </button>

                <button class="confirm-btn" onclick="downloadReceipt()">
                    Download Receipt
                </button>
            </div>

        </div>

    </div>

    <div id="confirmCancelModal" class="confirm-modal hidden">
        <div class="confirm-box">
            <h3>Cancel Booking?</h3>
            <p>This action cannot be undone.</p>

            <div class="confirm-actions">
                <button onclick="closeCancelModal()">No</button>
                <button class="danger" onclick="confirmCancel()">Yes, Cancel</button>
            </div>
        </div>
    </div>

    <div id="logoutModal" class="logout-modal hidden">

        <div class="logout-card">

            <h3>Confirm Logout</h3>
            <p>Are you sure you want to logout?</p>

            <div class="logout-actions">
                <button class="cancel-btn" onclick="closeLogoutModal()">Cancel</button>
                <button class="confirm-btn" onclick="submitLogout()">Yes, Logout</button>
            </div>

        </div>

    </div>



    <script src="{{ asset('customer/js/history.js') }}"></script>

    <script src="{{ asset('customer/js/dashboard.js') }}"></script>

    <script>
        lucide.createIcons();

        function submitLogout() {
            document.getElementById("logoutForm").submit();
        }
    </script>
@endsection
