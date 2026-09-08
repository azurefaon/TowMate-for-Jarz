@extends('layouts.superadmin')

@section('title', 'Bookings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/bookings.css') }}?v={{ filemtime(public_path('admin/css/bookings.css')) }}">
@endpush

@section('content')
    <div class="booking-container" id="bookingPage" data-index-url="{{ route('superadmin.bookings.index') }}">

        <div class="booking-header">
            <div>
                <h1>Bookings Overview</h1>
                <p>Review booking activity and transaction history.</p>
            </div>
        </div>

        <form method="GET" class="booking-toolbar" id="bookingFiltersForm">
            <div class="booking-toolbar-left">
                <div class="booking-field">
                    <label class="booking-field-label" for="bookingStatusSelect">Status</label>
                    <select name="status" id="bookingStatusSelect" data-custom>
                        <option value="" {{ $filters['status'] === '' ? 'selected' : '' }}>All statuses</option>
                        <option value="needs_attention" {{ $filters['status'] === 'needs_attention' ? 'selected' : '' }}>Needs Attention</option>
                        <option value="completed" {{ $filters['status'] === 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="scheduled" {{ $filters['status'] === 'scheduled' ? 'selected' : '' }}>Scheduled</option>
                        <option value="on_job" {{ $filters['status'] === 'on_job' ? 'selected' : '' }}>On Job</option>
                        <option value="returned" {{ $filters['status'] === 'returned' ? 'selected' : '' }}>Returned</option>
                    </select>
                </div>

                <div class="booking-field">
                    <label class="booking-field-label" for="bookingSearchInput">Search</label>
                    <div class="search-box">
                        <i data-lucide="search"></i>
                        <input type="text" id="bookingSearchInput" name="search" value="{{ $filters['search'] }}"
                            placeholder="Search bookings, customers, or locations">
                    </div>
                </div>
            </div>

            <div class="booking-toolbar-right">
                <div class="booking-field">
                    <label class="booking-field-label">Date Range</label>
                    <div class="booking-daterange-group">
                        <div class="date-range-picker" data-range-picker id="bookingDateRangePicker">
                            <input type="date" name="from" data-role="from" value="{{ $filters['from'] }}">
                            <input type="date" name="to" data-role="to" value="{{ $filters['to'] }}">
                        </div>

                        <button type="button" id="bookingRangeApply" class="booking-range-btn booking-range-btn--apply">Apply</button>
                        <button type="button" id="bookingRangeClear" class="booking-range-btn booking-range-btn--clear">Clear</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="booking-table-card" id="bookingTableCard">

            <div class="booking-table-shell">
                <table class="booking-table">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Truck Type</th>
                            <th>Unit</th>
                            <th>Pickup</th>
                            <th>Drop-off</th>
                            <th class="align-right">Distance</th>
                            <th>Booked On</th>
                            <th class="align-right">Total</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody id="bookingTableBody">

                        @forelse ($bookings as $booking)
                            @php
                                $statusClass = match (true) {
                                    $booking->status === 'completed' => 'is-completed',
                                    $booking->status === 'cancelled' => 'is-cancelled',
                                    in_array($booking->status, ['scheduled', 'scheduled_confirmed'], true) => 'is-muted',
                                    default => 'is-neutral',
                                };
                            @endphp
                            <tr onclick="openBooking('{{ $booking->job_code }}')" tabindex="0"
                                onkeydown="if(event.key==='Enter'){openBooking('{{ $booking->job_code }}')}">

                                <td>
                                    <span class="cell-main">{{ $booking->job_code }}</span>
                                    <span class="cell-sub">{{ $booking->service_mode_label }}</span>
                                </td>

                                <td>
                                    <span class="cell-main">{{ $booking->customer->full_name }}</span>
                                </td>

                                <td>
                                    <span class="cell-main">{{ $booking->truckType->name }}</span>
                                </td>

                                <td>
                                    <span class="cell-main">{{ $booking->unit->name ?? 'Unassigned' }}</span>
                                </td>

                                <td class="location">
                                    <span class="cell-main"
                                        title="{{ $booking->pickup_address }}">{{ $booking->pickup_address }}</span>
                                </td>
                                <td class="location">
                                    <span class="cell-main"
                                        title="{{ $booking->dropoff_address }}">{{ $booking->dropoff_address }}</span>
                                </td>

                                <td class="align-right">
                                    <span class="cell-main">{{ $booking->distance_km }} km</span>
                                </td>

                                <td>
                                    <span
                                        class="cell-main">{{ optional($booking->created_at)?->timezone(config('app.timezone', 'Asia/Manila'))->format('M d, Y') }}</span>
                                    <span
                                        class="cell-sub">{{ optional($booking->created_at)?->timezone(config('app.timezone', 'Asia/Manila'))->format('g:i A') }}</span>
                                </td>

                                <td class="align-right">
                                    <span class="cell-main">₱{{ number_format($booking->final_total, 2) }}</span>
                                </td>

                                <td>
                                    <span
                                        class="status-text {{ $statusClass }}">{{ ucfirst(str_replace('_', ' ', $booking->status)) }}</span>
                                </td>

                                <td onclick="event.stopPropagation()">
                                    <button type="button" onclick="openBooking('{{ $booking->job_code }}')"
                                        class="view-btn">View</button>
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="empty-row">
                                    <i data-lucide="inbox" class="empty-row-icon"></i>
                                    <span class="empty-row-title">No bookings found for the selected filters.</span>
                                    <span class="empty-row-hint">Try adjusting your search, status, or date range.</span>
                                </td>
                            </tr>
                        @endforelse

                    </tbody>

                </table>
            </div>

            <div class="pagination-container" id="bookingPagination">
                @if ($bookings->hasPages())
                    {{ $bookings->onEachSide(1)->links() }}
                @endif
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

                    <span id="m_status" class="status-text"></span>

                </div>

                <div class="modal-card receipt">

                    <span class="label">Receipt</span>
                    <h3 id="m_receipt"></h3>

                    <a id="m_download" class="download-btn" target="_blank" rel="noopener noreferrer">Download
                        Receipt</a>

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

                    document.getElementById('m_id').innerText = data.booking_code ?? data.id
                    document.getElementById('m_customer').innerText = data.customer.full_name
                    document.getElementById('m_truck').innerText = data.truck_type.name
                    document.getElementById('m_unit').innerText = data.unit?.name ?? "Unassigned"

                    document.getElementById('m_pickup').innerText = data.pickup_address
                    document.getElementById('m_dropoff').innerText = data.dropoff_address

                    document.getElementById('m_distance').innerText = data.distance_km + " km"
                    document.getElementById('m_total').innerText = "₱" + data.final_total
                    document.getElementById('m_status').innerText = data.status

                    if (data.receipt) {
                        document.getElementById('m_receipt').innerText = data.receipt.receipt_code ?? data.receipt
                            .receipt_number
                        document.getElementById('m_download').href = data.receipt.pdf_url
                        document.getElementById('m_download').target = "_blank"
                        document.getElementById('m_download').rel = "noopener noreferrer"
                        document.getElementById('m_download').style.display = "inline-flex"
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

        const bookingPage = document.getElementById('bookingPage');
        const bookingFiltersForm = document.getElementById('bookingFiltersForm');
        const bookingStatusSelect = document.getElementById('bookingStatusSelect');
        const bookingTableCard = document.getElementById('bookingTableCard');
        const bookingIndexUrl = bookingPage?.dataset.indexUrl;
        const searchInput = bookingFiltersForm?.querySelector('[name="search"]');

        let timer;

        async function refreshBookings(url) {
            if (!bookingIndexUrl || !bookingTableCard) {
                return;
            }

            bookingTableCard.classList.add('is-loading');

            try {
                const response = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!response.ok) {
                    return;
                }

                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');

                const replacements = [
                    ['bookingTableBody', 'bookingTableBody'],
                    ['bookingPagination', 'bookingPagination'],
                ];

                replacements.forEach(([targetId, sourceId]) => {
                    const target = document.getElementById(targetId);
                    const source = doc.getElementById(sourceId);

                    if (target && source) {
                        target.innerHTML = source.innerHTML;
                    }
                });

                window.lucide?.createIcons();

                window.history.replaceState({}, '', url);
            } finally {
                bookingTableCard.classList.remove('is-loading');
            }
        }

        function buildFilterUrl(extra = {}) {
            const params = new URLSearchParams(new FormData(bookingFiltersForm));

            Object.entries(extra).forEach(([key, value]) => {
                params.set(key, value);
            });

            return `${bookingIndexUrl}?${params.toString()}`;
        }

        bookingFiltersForm?.addEventListener('submit', (event) => {
            event.preventDefault();
            refreshBookings(buildFilterUrl({
                page: 1
            }));
        });

        searchInput?.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                refreshBookings(buildFilterUrl({
                    page: 1
                }));
            }, 400);
        });

        bookingStatusSelect?.addEventListener('change', () => {
            refreshBookings(buildFilterUrl({
                status: bookingStatusSelect.value,
                page: 1
            }));
        });

        document.getElementById('bookingRangeApply')?.addEventListener('click', () => {
            refreshBookings(buildFilterUrl({
                page: 1
            }));
        });

        document.getElementById('bookingRangeClear')?.addEventListener('click', () => {
            window.location.href = bookingIndexUrl;
        });

        document.addEventListener('click', (event) => {
            const paginationLink = event.target.closest('#bookingPagination a');

            if (!paginationLink) {
                return;
            }

            event.preventDefault();
            refreshBookings(paginationLink.href);
        });
    </script>
@endpush
