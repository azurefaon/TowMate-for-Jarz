<div class="bh-table-wrap">
@forelse ($bookings as $booking)
    @if ($loop->first)
        <table class="bh-table">
            <thead>
                <tr>
                    <th>Booking #</th>
                    <th>Customer</th>
                    <th>Team Leader / Unit</th>
                    <th>Final Total</th>
                    <th>Cash Received</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
    @endif

    <tr>
        <td>
            <span class="bh-cell-main">{{ $booking->job_code }}</span>
        </td>
        <td>
            <span class="bh-cell-main">{{ $booking->customer->full_name ?? 'Guest' }}</span>
            <span class="bh-cell-sub">{{ $booking->customer->phone ?? 'N/A' }}</span>
        </td>
        <td>
            @if ($booking->assignedTeamLeader)
                <span class="bh-cell-main">{{ $booking->assignedTeamLeader->full_name ?? $booking->assignedTeamLeader->name }}</span>
                <span class="bh-cell-sub">{{ $booking->unit->name ?? 'No unit' }}</span>
            @else
                <span class="bh-cell-sub">Unassigned</span>
            @endif
        </td>
        <td>
            <span class="bh-cell-main">₱{{ number_format((float) $booking->final_total, 2) }}</span>
        </td>
        <td>
            @if ($booking->cash_received !== null)
                <span class="bh-cell-main">₱{{ number_format((float) $booking->cash_received, 2) }}</span>
            @else
                <span class="bh-cell-sub">—</span>
            @endif
        </td>
        <td>
            <span class="bh-badge bh-badge--{{ $booking->status }}">{{ $booking->status }}</span>
        </td>
        <td>
            <span class="bh-cell-main">
                {{ ($booking->status === 'completed' ? $booking->completed_at : $booking->updated_at)?->format('M d, Y') }}
            </span>
            <span class="bh-cell-sub">
                {{ ($booking->status === 'completed' ? $booking->completed_at : $booking->updated_at)?->format('h:i A') }}
            </span>
        </td>
    </tr>

    @if ($loop->last)
            </tbody>
        </table>
    @endif
@empty
    <div class="bh-empty">
        <div class="bh-empty-icon">🗂️</div>
        <strong>No booking history yet</strong>
        <p>Completed and cancelled bookings will appear here.</p>
    </div>
@endforelse
</div>

@if ($bookings->hasPages())
    <div class="bh-pagination">
        {{ $bookings->links() }}
    </div>
@endif
