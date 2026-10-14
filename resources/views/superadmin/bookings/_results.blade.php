@php
    $total = $bookings->total();
@endphp

<div class="ob-card" data-results data-total="{{ $total }}" data-has-filters="{{ $hasFilters ? '1' : '0' }}"
    data-status="{{ $filters['status'] }}" data-status-label="{{ $filters['statusLabel'] }}"
    data-date="{{ $filters['date'] }}" data-date-label="{{ $filters['dateLabel'] }}"
    data-from="{{ $filters['from'] }}" data-to="{{ $filters['to'] }}">
    @if ($bookings->count() === 0)
        <div class="ob-state">
            @if ($hasFilters || $total > 0)
                <span class="ob-state-icon"><i data-lucide="search-x" aria-hidden="true"></i></span>
                <p class="ob-state-title">No bookings match these filters</p>
                <p class="ob-state-text">Try a different search, status, or booking date.</p>
                <a href="{{ route('superadmin.bookings.index') }}" class="ob-btn" data-clear>Clear filters</a>
            @else
                <span class="ob-state-icon"><i data-lucide="clipboard-list" aria-hidden="true"></i></span>
                <p class="ob-state-title">No bookings yet</p>
                <p class="ob-state-text">New bookings will appear here as soon as they are submitted.</p>
            @endif
        </div>
    @else
        <div class="ob-table-wrap">
            <table class="ob-table">
                <thead>
                    <tr>
                        <th scope="col" class="ob-col-booking">Booking</th>
                        <th scope="col" class="ob-col-customer">Customer</th>
                        <th scope="col" class="ob-col-service">Service</th>
                        <th scope="col" class="ob-col-route">Route</th>
                        <th scope="col" class="ob-col-amount">Amount</th>
                        <th scope="col" class="ob-col-status">Status</th>
                        <th scope="col" class="ob-col-open"><span class="ob-sr">Details</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $booking = $row['booking'];
                        @endphp
                        <tr class="ob-row">
                            <td>
                                <button type="button" class="ob-code" data-booking="{{ $booking->job_code }}"
                                    aria-haspopup="dialog" aria-label="View booking {{ $booking->job_code }}">{{ $booking->job_code }}</button>
                                <span class="ob-sub">{{ $booking->truckType->name ?? '—' }}</span>
                            </td>
                            <td>
                                <span class="ob-main">{{ $booking->customer->full_name ?? '—' }}</span>
                                @if ($row['phone'])
                                    <span class="ob-sub">{{ $row['phone'] }}</span>
                                @endif
                            </td>
                            <td class="ob-service">
                                <span class="ob-main">{{ $row['service']['label'] }}</span>
                                @if ($row['service']['note'])
                                    <span class="ob-sub">{{ $row['service']['note'] }}</span>
                                @endif
                            </td>
                            <td class="ob-route">
                                <span class="ob-stop is-pickup" title="{{ $booking->pickup_address }}">
                                    <span class="ob-sr">Pickup: </span>{{ $booking->pickup_address }}
                                </span>
                                <span class="ob-stop is-dropoff" title="{{ $booking->dropoff_address }}">
                                    <span class="ob-sr">Drop-off: </span>{{ $booking->dropoff_address }}
                                </span>
                            </td>
                            <td class="ob-amount">
                                @if ($row['amount']['amount'] !== null)
                                    <span class="ob-main">₱{{ number_format($row['amount']['amount'], 2) }}</span>
                                    @if ($row['amount']['note'])
                                        <span class="ob-sub">{{ $row['amount']['note'] }}</span>
                                    @endif
                                @else
                                    <span class="ob-dash" aria-label="No amount yet">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="ob-badge tone-{{ $row['status']['tone'] }}">{{ $row['status']['label'] }}</span>
                            </td>
                            <td class="ob-open" aria-hidden="true">
                                <i data-lucide="chevron-right"></i>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <footer class="ob-footer">
            <p class="ob-count">Showing {{ number_format($bookings->firstItem()) }}–{{ number_format($bookings->lastItem()) }} of {{ number_format($total) }} {{ $total === 1 ? 'booking' : 'bookings' }}</p>

            @if ($bookings->hasPages())
                @php
                    $current = $bookings->currentPage();
                    $last = $bookings->lastPage();
                    $pages = collect([1, $current - 1, $current, $current + 1, $last])
                        ->filter(fn ($p) => $p >= 1 && $p <= $last)
                        ->unique()
                        ->sort()
                        ->values();
                @endphp
                <nav class="ob-pages" aria-label="Pagination">
                    @if ($bookings->onFirstPage())
                        <span class="ob-page-btn is-disabled" aria-hidden="true"><i data-lucide="chevron-left"></i></span>
                    @else
                        <a class="ob-page-btn" href="{{ $bookings->previousPageUrl() }}" data-page-link aria-label="Previous page"><i data-lucide="chevron-left" aria-hidden="true"></i></a>
                    @endif

                    @foreach ($pages as $i => $page)
                        @if ($i > 0 && $page - $pages[$i - 1] > 1)
                            <span class="ob-page-gap" aria-hidden="true">…</span>
                        @endif
                        @if ($page === $current)
                            <span class="ob-page-btn is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="ob-page-btn" href="{{ $bookings->url($page) }}" data-page-link aria-label="Page {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach

                    @if ($bookings->hasMorePages())
                        <a class="ob-page-btn" href="{{ $bookings->nextPageUrl() }}" data-page-link aria-label="Next page"><i data-lucide="chevron-right" aria-hidden="true"></i></a>
                    @else
                        <span class="ob-page-btn is-disabled" aria-hidden="true"><i data-lucide="chevron-right"></i></span>
                    @endif
                </nav>
            @endif
        </footer>
    @endif
</div>
