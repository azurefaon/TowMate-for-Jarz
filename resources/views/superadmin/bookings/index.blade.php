@extends('layouts.superadmin')

@section('title', 'Bookings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/bookings.css') }}">
    <style>
        .booking-period-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 14px 0 18px;
        }

        .booking-period-tab {
            padding: 10px 14px;
            /* border-radius: 999px; */
            border: 1px solid #d1d5db;
            text-decoration: none;
            color: #1f2937;
            background: #fff;
            font-weight: 600;
            cursor: pointer;
        }

        .booking-period-note {
            margin: 0 0 14px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #334155;
        }

        .booking-period-note strong {
            display: block;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .booking-period-tab.active {
            background: #facc15;
            /* border-color: #111827; */
            color: #000000;
        }

        .empty-row {
            text-align: center;
            color: #6b7280;
            padding: 22px;
        }

        .booking-table-card.is-loading {
            /* opacity: 0.65; */
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        .booking-table-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            color: #475569;
            font-size: 0.92rem;
        }

        .booking-table-shell {
            overflow-x: auto;
            /* border: 1px solid #eef2f7; */
            border-radius: 16px;
            background: rgb(255, 255, 255);
        }

        .booking-table td .cell-main {
            display: block;
            color: #0f172a;
            font-weight: 600;
        }

        .booking-table td .cell-sub {
            display: block;
            margin-top: 4px;
            color: #64748b;
            font-size: 0.8rem;
        }

        .booking-chip {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            background: #f8fafc;
            color: #334155;
            font-size: 11px;
            font-weight: 700;
            border: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .booking-status-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 14px;
        }

        .booking-status-tab {
            padding: 7px 14px;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            border-radius: 6px;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
        }

        .booking-status-tab.active,
        .booking-status-tab:hover {
            border-color: #111827;
            background: #111827;
            color: #fff;
        }

        .booking-status-tab.status-tab-completed.active,
        .booking-status-tab.status-tab-completed:hover {
            background: #166534;
            border-color: #166534;
            color: #fff;
        }

        .booking-status-tab.status-tab-scheduled.active,
        .booking-status-tab.status-tab-scheduled:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            color: #fff;
        }

        .booking-status-tab.status-tab-onjob.active,
        .booking-status-tab.status-tab-onjob:hover {
            background: #9a3412;
            border-color: #9a3412;
            color: #fff;
        }

        .booking-status-tab.status-tab-returned.active,
        .booking-status-tab.status-tab-returned:hover {
            background: #6b21a8;
            border-color: #6b21a8;
            color: #fff;
        }
    </style>
@endpush

@section('content')
    <div class="booking-container" id="bookingPage" data-index-url="{{ route('superadmin.bookings.index') }}">

        <div class="booking-header">

            <div>
                <h1>Bookings Overview</h1>
                <p>Review towing activity by today, this week, or this month.</p>
            </div>

            <form method="GET" class="booking-filters" id="bookingFiltersForm">

                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" name="search" value="{{ request('search') }}"
                        placeholder="Search bookings, customers, or locations">
                </div>

                {{-- <select name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="requested"
                        {{ ($filters['status'] ?? request('status')) == 'requested' ? 'selected' : '' }}>Requests</option>
                    <option value="active" {{ ($filters['status'] ?? request('status')) == 'active' ? 'selected' : '' }}>
                        Active</option>
                    <option value="completed"
                        {{ ($filters['status'] ?? request('status')) == 'completed' ? 'selected' : '' }}>Completed</option>
                </select> --}}

                <input type="hidden" name="period" value="{{ $filters['period'] ?? 'today' }}">

            </form>

        </div>

        <form method="GET" class="booking-period-tabs" id="bookingPeriodTabs">
            <input type="hidden" name="search" value="{{ $filters['search'] ?? '' }}">
            <input type="hidden" name="status" value="{{ $filters['status'] ?? '' }}">

            <button type="submit" name="period" value="today"
                class="booking-period-tab {{ ($filters['period'] ?? 'today') === 'today' ? 'active' : '' }}">Today</button>
            <button type="submit" name="period" value="week"
                class="booking-period-tab {{ ($filters['period'] ?? '') === 'week' ? 'active' : '' }}">This Week</button>
            <button type="submit" name="period" value="month"
                class="booking-period-tab {{ ($filters['period'] ?? '') === 'month' ? 'active' : '' }}">This Month</button>
        </form>

        <div class="booking-status-tabs" id="bookingStatusTabs">
            <button type="button" data-status=""
                class="booking-status-tab {{ ($filters['status'] ?? '') === '' ? 'active' : '' }}">All</button>
            <button type="button" data-status="completed"
                class="booking-status-tab status-tab-completed {{ ($filters['status'] ?? '') === 'completed' ? 'active' : '' }}">Completed</button>
            <button type="button" data-status="scheduled"
                class="booking-status-tab status-tab-scheduled {{ ($filters['status'] ?? '') === 'scheduled' ? 'active' : '' }}">Scheduled</button>
            <button type="button" data-status="on_job"
                class="booking-status-tab status-tab-onjob {{ ($filters['status'] ?? '') === 'on_job' ? 'active' : '' }}">On
                Job</button>
            <button type="button" data-status="returned"
                class="booking-status-tab status-tab-returned {{ ($filters['status'] ?? '') === 'returned' ? 'active' : '' }}">Returned</button>
        </div>

        <div class="booking-period-note" id="bookingPeriodNote">
            <strong>Showing {{ $periodLabel }}</strong>
            <span>{{ $periodDescription }}</span>
        </div>

        {{-- <div class="booking-summary" id="bookingSummary">

            <div class="summary-card blue-card">
                
                <div class="summary-number">{{ $stats['total'] }}</div>
                <div class="summary-title">Total Bookings</div>
            </div>

            <div class="summary-card yellow-card">
                
                <div class="summary-number">{{ $stats['requested'] }}</div>
                <div class="summary-title">Requests</div>
            </div>

            <div class="summary-card orange-card">
                
                <div class="summary-number">{{ $stats['active'] }}</div>
                <div class="summary-title">Active</div>
            </div>

            <div class="summary-card green-card">
                
                <div class="summary-number">{{ $stats['completed'] }}</div>
                <div class="summary-title">Completed</div>
            </div>

        </div> --}}

        <div class="booking-table-card" id="bookingTableCard">

            <div class="booking-table-meta" id="bookingTableMeta">
                <strong id="bookingTableRangeLabel">{{ $periodLabel }} Bookings</strong>
            </div>

            <div class="booking-table-shell">
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
                            <th>Booked On</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody id="bookingTableBody">

                        @forelse ($bookings as $booking)
                            <tr>

                                <td class="booking-id">
                                    <span class="cell-main">{{ $booking->job_code }}</span>
                                    <span class="cell-sub">{{ $booking->service_mode_label }}</span>
                                </td>

                                <td>
                                    <div class="customer-name">
                                        <span class="cell-main">{{ $booking->customer->full_name }}</span>
                                    </div>
                                </td>

                                <td>
                                    <span class="booking-chip">{{ $booking->truckType->name }}</span>
                                </td>

                                <td>
                                    <span class="booking-chip">{{ $booking->unit->name ?? 'Unassigned' }}</span>
                                </td>

                                <td class="location">
                                    <span class="cell-main"
                                        title="{{ $booking->pickup_address }}">{{ $booking->pickup_address }}</span>
                                </td>
                                <td class="location">
                                    <span class="cell-main"
                                        title="{{ $booking->dropoff_address }}">{{ $booking->dropoff_address }}</span>
                                </td>

                                <td>
                                    <span class="cell-main">{{ $booking->distance_km }} km</span>
                                </td>

                                <td>
                                    <span
                                        class="cell-main">{{ optional($booking->created_at)?->timezone(config('app.timezone', 'Asia/Manila'))->format('M d, Y') }}</span>
                                    <span
                                        class="cell-sub">{{ optional($booking->created_at)?->timezone(config('app.timezone', 'Asia/Manila'))->format('g:i A') }}</span>
                                </td>

                                <td class="price">
                                    <span class="cell-main">₱{{ number_format($booking->final_total, 2) }}</span>
                                </td>

                                <td>
                                    <span class="status {{ $booking->status }}">
                                        {{ ucfirst($booking->status) }}
                                    </span>
                                </td>

                                <td>
                                    <button onclick="openBooking('{{ $booking->job_code }}')" class="view-btn">
                                        View
                                    </button>
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="empty-row">No bookings found for the selected time range.</td>
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

                    <span id="m_status" class="status"></span>

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
                        document.getElementById('m_download').href = "/" + data.receipt.pdf_path
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
        const bookingPeriodTabs = document.getElementById('bookingPeriodTabs');
        const bookingStatusTabs = document.getElementById('bookingStatusTabs');
        const bookingTableCard = document.getElementById('bookingTableCard');
        const bookingIndexUrl = bookingPage?.dataset.indexUrl;
        const searchInput = bookingFiltersForm?.querySelector('[name="search"]');
        const statusSelect = bookingFiltersForm?.querySelector('[name="status"]');
        const periodInput = bookingFiltersForm?.querySelector('[name="period"]');

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
                    ['bookingPeriodNote', 'bookingPeriodNote'],
                    ['bookingSummary', 'bookingSummary'],
                    ['bookingTableBody', 'bookingTableBody'],
                    ['bookingPagination', 'bookingPagination'],
                    ['bookingTableMeta', 'bookingTableMeta'],
                ];

                replacements.forEach(([targetId, sourceId]) => {
                    const target = document.getElementById(targetId);
                    const source = doc.getElementById(sourceId);

                    if (target && source) {
                        target.innerHTML = source.innerHTML;
                    }
                });

                const activePeriod = new URL(url, window.location.origin).searchParams.get('period') || 'today';
                document.querySelectorAll('.booking-period-tab').forEach((button) => {
                    button.classList.toggle('active', button.value === activePeriod);
                });

                const activeStatus = new URL(url, window.location.origin).searchParams.get('status') || '';
                document.querySelectorAll('.booking-status-tab').forEach((button) => {
                    button.classList.toggle('active', button.dataset.status === activeStatus);
                });

                if (periodInput) {
                    periodInput.value = activePeriod;
                }

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

        statusSelect?.addEventListener('change', () => {
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

        bookingStatusTabs?.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-status]');
            if (!button) return;
            refreshBookings(buildFilterUrl({
                status: button.dataset.status,
                page: 1
            }));
        });

        bookingPeriodTabs?.addEventListener('click', (event) => {
            const button = event.target.closest('button[name="period"]');

            if (!button) {
                return;
            }

            event.preventDefault();
            refreshBookings(buildFilterUrl({
                period: button.value,
                page: 1
            }));
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
