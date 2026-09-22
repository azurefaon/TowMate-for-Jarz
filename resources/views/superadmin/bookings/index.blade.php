@extends('layouts.superadmin')

@section('title', 'Bookings')

@push('styles')
    <link rel="stylesheet"
        href="{{ asset('admin/css/bookings.css') }}?v={{ filemtime(public_path('admin/css/bookings.css')) }}">
@endpush

@section('content')
    <div class="booking-container" id="bookingPage" data-index-url="{{ route('superadmin.bookings.index') }}">

        <div class="booking-header">
            <div>
                <h1>Bookings Overview</h1>
                <p>Review current and historical towing bookings.</p>
            </div>
        </div>

        <form method="GET" class="booking-toolbar" id="bookingFiltersForm">
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

            <div class="booking-field booking-field--grow">
                <label class="booking-field-label" for="bookingSearchInput">Search</label>
                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" id="bookingSearchInput" name="search" value="{{ $filters['search'] }}"
                        placeholder="Search bookings, customers, or locations">
                </div>
            </div>

            <div class="booking-field">
                <label class="booking-field-label">Date Range</label>
                <div class="date-range-picker" data-range-picker id="bookingDateRangePicker">
                    <input type="date" name="from" data-role="from" value="{{ $rangeFrom }}">
                    <input type="date" name="to" data-role="to" value="{{ $rangeTo }}">
                </div>
            </div>
        </form>

        <div class="booking-table-card" id="bookingTableCard">

            <div class="booking-table-shell">
                <table class="booking-table">

                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th class="align-right">Action</th>
                        </tr>
                    </thead>

                    <tbody id="bookingTableBody">

                        @forelse ($bookings as $booking)
                            @php
                                $statusClass = match (true) {
                                    $booking->status === 'completed' => 'is-completed',
                                    in_array($booking->status, ['cancelled', 'rejected'], true) => 'is-cancelled',
                                    in_array($booking->status, ['requested', 'reviewed'], true) => 'is-requested',
                                    in_array($booking->status, ['scheduled', 'scheduled_confirmed'], true) => 'is-scheduled',
                                    in_array($booking->status, ['accepted', 'assigned', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification', 'payment_pending', 'payment_submitted'], true) => 'is-onjob',
                                    default => 'is-neutral',
                                };
                            @endphp
                            <tr class="booking-row" data-job-code="{{ $booking->job_code }}" tabindex="0">

                                <td>
                                    <span class="cell-main">{{ $booking->job_code }}</span>
                                    <span class="cell-sub">{{ $booking->truckType->name }}</span>
                                </td>

                                <td>
                                    <span class="cell-main">{{ $booking->customer->full_name }}</span>
                                </td>

                                <td>
                                    <span
                                        class="status-text {{ $statusClass }}">{{ ucfirst(str_replace('_', ' ', $booking->status)) }}</span>
                                </td>

                                <td class="align-right">
                                    <button type="button" class="view-btn" data-job-code="{{ $booking->job_code }}">View</button>
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="empty-row">
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

    <div class="booking-drawer-overlay" id="bookingDrawerOverlay" onclick="closeBooking()"></div>

    <aside class="booking-drawer" id="bookingDrawer" role="dialog" aria-modal="true" aria-labelledby="bd_title">

        <div class="booking-drawer-header">
            <h2 id="bd_title">Booking Details</h2>
            <button type="button" class="booking-drawer-close" onclick="closeBooking()" aria-label="Close">✕</button>
        </div>

        <div class="booking-drawer-summary">
            <span class="booking-drawer-id" id="bd_id"></span>
            <span class="status-text" id="bd_status"></span>
        </div>
        <p class="booking-drawer-meta" id="bd_created"></p>

        <div class="booking-drawer-error" id="bd_error" style="display:none;"></div>

        <div class="booking-drawer-body" id="bd_body">

            <div class="booking-drawer-card">
                <h3>Customer Information</h3>
                <div class="booking-drawer-row"><span>Name</span><strong id="bd_customer_name"></strong></div>
                <div class="booking-drawer-row"><span>Phone</span><strong id="bd_customer_phone"></strong></div>
                <div class="booking-drawer-row"><span>Email</span><strong id="bd_customer_email"></strong></div>
            </div>

            <div class="booking-drawer-card">
                <h3>Booking Information</h3>
                <div class="booking-drawer-row"><span>Truck Type</span><strong id="bd_truck_type"></strong></div>
                <div class="booking-drawer-row"><span>Status</span><strong class="status-text" id="bd_status_2"></strong></div>
                <div class="booking-drawer-row"><span>Unit</span><strong id="bd_unit"></strong></div>
                <div class="booking-drawer-row"><span>Booked On</span><strong id="bd_booked_on"></strong></div>
                <div class="booking-drawer-row"><span>Scheduled For</span><strong id="bd_scheduled_for"></strong></div>
                <div class="booking-drawer-row"><span>Reference No.</span><strong id="bd_reference"></strong></div>
            </div>

            <div class="booking-drawer-card">
                <h3>Location Details</h3>
                <div class="booking-drawer-row booking-drawer-row--block">
                    <span>Pickup Location</span><strong id="bd_pickup"></strong>
                </div>
                <div class="booking-drawer-row booking-drawer-row--block">
                    <span>Drop-off Location</span><strong id="bd_dropoff"></strong>
                </div>
                <div class="booking-drawer-row"><span>Distance</span><strong id="bd_distance"></strong></div>
            </div>

            <div class="booking-drawer-card">
                <h3>Financial Details</h3>
                <div class="booking-drawer-row"><span>Total Amount</span><strong id="bd_total"></strong></div>
                <div class="booking-drawer-row"><span>Payment Method</span><strong id="bd_payment_method"></strong></div>
                <div class="booking-drawer-row"><span>Payment Status</span><strong id="bd_payment_status"></strong></div>
                <div class="booking-drawer-row"><span>Additional Fees</span><strong id="bd_additional_fee"></strong></div>
                <div class="booking-drawer-row booking-drawer-row--block">
                    <span>Notes</span><strong id="bd_notes"></strong>
                </div>
                <a id="bd_download" class="download-btn" target="_blank" rel="noopener noreferrer" style="display:none;">Download Receipt</a>
            </div>

        </div>

        <div class="booking-drawer-footer">
            <button type="button" onclick="closeBooking()" class="close-btn">Close</button>
        </div>

    </aside>
@endsection


@push('scripts')
    <script>
        function statusClassFor(status) {
            if (status === 'completed') return 'is-completed';
            if (['cancelled', 'rejected'].includes(status)) return 'is-cancelled';
            if (['requested', 'reviewed'].includes(status)) return 'is-requested';
            if (['scheduled', 'scheduled_confirmed'].includes(status)) return 'is-scheduled';
            if (['accepted', 'assigned', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification', 'payment_pending', 'payment_submitted'].includes(status)) return 'is-onjob';
            return 'is-neutral';
        }

        function showBookingError(message) {
            const errorEl = document.getElementById('bd_error');
            const bodyEl = document.getElementById('bd_body');
            errorEl.textContent = message;
            errorEl.style.display = 'block';
            bodyEl.style.display = 'none';
        }

        function clearBookingError() {
            const errorEl = document.getElementById('bd_error');
            const bodyEl = document.getElementById('bd_body');
            errorEl.style.display = 'none';
            errorEl.textContent = '';
            bodyEl.style.display = '';
        }

        function openBookingDrawer() {
            document.getElementById('bookingDrawerOverlay').classList.add('is-open');
            document.getElementById('bookingDrawer').classList.add('is-open');
        }

        function openBooking(id) {
            if (!id) return;

            clearBookingError();
            openBookingDrawer();

            fetch(`/superadmin/bookings/${encodeURIComponent(id)}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(res => {
                    if (!res.ok) {
                        throw new Error('Failed to load booking ' + id);
                    }
                    return res.json();
                })
                .then(data => {
                    document.getElementById('bd_id').innerText = data.booking_code ?? id;

                    const statusText = data.status_label ?? data.status;
                    const statusClass = statusClassFor(data.status);

                    const statusEl = document.getElementById('bd_status');
                    statusEl.innerText = statusText;
                    statusEl.className = 'status-text ' + statusClass;

                    const statusEl2 = document.getElementById('bd_status_2');
                    statusEl2.innerText = statusText;
                    statusEl2.className = 'status-text ' + statusClass;

                    document.getElementById('bd_created').innerText = data.created_at ? `Created on ${data.created_at}` : '';

                    document.getElementById('bd_customer_name').innerText = data.customer?.full_name ?? '—';
                    document.getElementById('bd_customer_phone').innerText = data.customer?.phone ?? '—';
                    document.getElementById('bd_customer_email').innerText = data.customer?.email ?? '—';

                    document.getElementById('bd_truck_type').innerText = data.truck_type?.name ?? '—';
                    document.getElementById('bd_unit').innerText = data.unit?.name ?? 'Unassigned';
                    document.getElementById('bd_booked_on').innerText = data.created_at ?? '—';
                    document.getElementById('bd_scheduled_for').innerText = data.scheduled_for ?? '—';
                    document.getElementById('bd_reference').innerText = data.booking_code ?? id;

                    document.getElementById('bd_pickup').innerText = data.pickup_address ?? '—';
                    document.getElementById('bd_dropoff').innerText = data.dropoff_address ?? '—';
                    document.getElementById('bd_distance').innerText = data.distance_km ? `${data.distance_km} km` : '—';

                    document.getElementById('bd_total').innerText = data.final_total ? `₱${Number(data.final_total).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}` : '—';
                    document.getElementById('bd_payment_method').innerText = data.payment_method ?? '—';
                    document.getElementById('bd_payment_status').innerText = data.payment_status ?? '—';
                    document.getElementById('bd_additional_fee').innerText = data.additional_fee ? `₱${Number(data.additional_fee).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}` : '₱0.00';
                    document.getElementById('bd_notes').innerText = data.notes ?? '—';

                    const download = document.getElementById('bd_download');
                    if (data.receipt) {
                        download.href = data.receipt.pdf_url;
                        download.style.display = 'inline-block';
                    } else {
                        download.style.display = 'none';
                    }

                    clearBookingError();
                })
                .catch(() => {
                    showBookingError('Unable to load booking details. Please try again.');
                });
        }

        function closeBooking() {
            document.getElementById('bookingDrawerOverlay').classList.remove('is-open');
            document.getElementById('bookingDrawer').classList.remove('is-open');
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeBooking();
        });

        document.addEventListener('click', (event) => {
            const viewBtn = event.target.closest('.view-btn');
            if (viewBtn) {
                openBooking(viewBtn.dataset.jobCode);
                return;
            }

            const row = event.target.closest('.booking-row');
            if (row) {
                openBooking(row.dataset.jobCode);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            const row = event.target.closest('.booking-row');
            if (row) {
                openBooking(row.dataset.jobCode);
            }
        });

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

        const bookingFromInput = document.querySelector('#bookingDateRangePicker [data-role="from"]');
        const bookingToInput = document.querySelector('#bookingDateRangePicker [data-role="to"]');

        bookingToInput?.addEventListener('change', () => {
            if (bookingFromInput?.value && bookingToInput.value) {
                refreshBookings(buildFilterUrl({
                    page: 1
                }));
            }
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
