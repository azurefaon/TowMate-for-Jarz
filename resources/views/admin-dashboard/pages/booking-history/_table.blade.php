<div class="bh-table-wrap jobs-table-wrap">
@forelse ($bookings as $booking)
    @if ($loop->first)
        <table class="jobs-table">
            <thead>
                <tr>
                    <th>Booking / Customer</th>
                    <th>Status</th>
                    <th>Unit / Team</th>
                    <th>Truck Type</th>
                    <th>Amount</th>
                    <th>Payment</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
    @endif

    @php
        $isCompleted = $booking->status === 'completed';

        $reason = match ($booking->status) {
            'not_responding' => 'Customer did not respond',
            'rejected' => 'Rejected by dispatcher',
            'cancelled' => trim((string) ($booking->rejection_reason ?? '')) !== ''
                ? $booking->rejection_reason
                : null,
            default => null,
        };

        $truckTypeLabel = $booking->truckType
            ? ($booking->truckType->class ? ucfirst($booking->truckType->class) . ' Duty' : $booking->truckType->name)
            : null;

        $paymentMethodLabel = match ($booking->payment_method) {
            'gcash' => 'GCash',
            'bank_transfer' => 'Bank Transfer',
            'cash' => 'Cash',
            default => null,
        };

        $historyDate = $isCompleted ? $booking->completed_at : $booking->updated_at;
    @endphp

    <tr>
        <td>
            <div class="jobs-cell-primary jobs-booking-code">{{ $booking->job_code }}</div>
            <div class="jobs-cell-secondary">{{ $booking->customer->full_name ?? 'Guest' }}</div>
        </td>
        <td>
            <span class="jobs-status-text {{ $isCompleted ? 'jobs-status-text--emphasis bh-status--completed' : 'bh-status--cancelled' }}">
                {{ $isCompleted ? 'Completed' : 'Cancelled' }}
            </span>
            @if ($reason)
                <div class="jobs-cell-secondary">{{ $reason }}</div>
            @endif
        </td>
        <td>
            @if ($booking->unit)
                <div class="jobs-cell-primary">{{ $booking->unit->name }}</div>
                <div class="jobs-cell-secondary">{{ optional($booking->assignedTeamLeader)->full_name ?? optional($booking->assignedTeamLeader)->name ?? '—' }}</div>
            @else
                <span class="jobs-cell-secondary">—</span>
            @endif
        </td>
        <td class="jobs-cell-secondary">{{ $truckTypeLabel ?? '—' }}</td>
        <td class="jobs-cell-primary">₱{{ number_format((float) $booking->final_total, 2) }}</td>
        <td>
            @if ($isCompleted && $paymentMethodLabel)
                <div class="jobs-cell-primary">{{ $paymentMethodLabel }}</div>
                <div class="jobs-cell-secondary">Paid</div>
            @else
                <span class="jobs-cell-secondary">—</span>
            @endif
        </td>
        <td>
            <div class="jobs-cell-primary">{{ $historyDate?->format('M d, Y') }}</div>
            <div class="jobs-cell-secondary">{{ $historyDate?->format('h:i A') }}</div>
        </td>
    </tr>

    @if ($loop->last)
            </tbody>
        </table>
    @endif
@empty
    <div class="bh-empty">
        <strong>No booking history found.</strong>
        <p>Try changing your search or filters.</p>
    </div>
@endforelse
</div>

@if ($bookings->total() > 0)
    <div class="bh-pagination">
        <div class="bh-pagination-info">
            Showing {{ $bookings->firstItem() }}–{{ $bookings->lastItem() }} of {{ $bookings->total() }}
        </div>
        @if ($bookings->hasPages())
            <div class="bh-pagination-links">
                @if ($bookings->onFirstPage())
                    <span class="bh-page-link is-disabled">Previous</span>
                @else
                    <a href="{{ $bookings->previousPageUrl() }}" class="bh-page-link">Previous</a>
                @endif

                @for ($page = 1; $page <= $bookings->lastPage(); $page++)
                    @if ($page === $bookings->currentPage())
                        <span class="bh-page-link is-active">{{ $page }}</span>
                    @else
                        <a href="{{ $bookings->url($page) }}" class="bh-page-link">{{ $page }}</a>
                    @endif
                @endfor

                @if ($bookings->hasMorePages())
                    <a href="{{ $bookings->nextPageUrl() }}" class="bh-page-link">Next</a>
                @else
                    <span class="bh-page-link is-disabled">Next</span>
                @endif
            </div>
        @endif
    </div>
@endif
