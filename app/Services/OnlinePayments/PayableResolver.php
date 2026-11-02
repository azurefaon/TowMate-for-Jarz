<?php

namespace App\Services\OnlinePayments;

use App\Models\Booking;
use App\Models\Quotation;

/**
 * The server-side answer to "what is owed, and for what". The amount is always rebuilt from
 * the booking rows; a client never supplies it.
 *
 * A normalized group (several vehicles on one accepted quotation) is paid once, exactly the way
 * TLTaskController::completeGroup() totals it: the active members' final_total plus the
 * quotation's additional_fee minus its discount. Anything else is paid per booking.
 */
class PayableResolver
{
    public function resolve(Booking $booking): Payable
    {
        $quotation = $this->groupQuotation($booking);

        if (! $quotation) {
            $total = (string) ($booking->final_total ?? 0);

            return new Payable(
                'booking',
                $booking->id,
                [$booking->id],
                $booking->status === 'cancelled' ? [] : [$booking->id],
                [$booking->id => number_format((float) $total, 2, '.', '')],
                (int) $booking->customer_id,
                number_format((float) $total, 2, '.', ''),
                $this->centavosOrZero($total),
            );
        }

        $members = Booking::where('group_code', $booking->group_code)
            ->where('pickup_address', $booking->pickup_address)
            ->where('dropoff_address', $booking->dropoff_address)
            ->orderBy('id')
            ->get();

        $active = $members->reject(fn (Booking $member) => $member->status === 'cancelled');
        $anchor = $members->firstWhere('id', $quotation->source_booking_id) ?? $booking;
        $adjustment = (float) ($quotation->additional_fee ?? 0) - (float) ($quotation->discount ?? 0);
        $total = max(round($active->sum(fn (Booking $member) => (float) $member->final_total) + $adjustment, 2), 0);
        $totalPesos = number_format($total, 2, '.', '');

        return new Payable(
            'group',
            $anchor->id,
            $members->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $active->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $members->mapWithKeys(fn (Booking $member) => [$member->id => number_format((float) $member->final_total, 2, '.', '')])->all(),
            (int) $booking->customer_id,
            $totalPesos,
            $this->centavosOrZero($totalPesos),
        );
    }

    private function groupQuotation(Booking $booking): ?Quotation
    {
        if (! $booking->group_code || ! $booking->quotation_id) {
            return null;
        }

        $quotation = Quotation::find($booking->quotation_id);

        if (! $quotation) {
            return null;
        }

        $isNormalized = collect($quotation->extra_vehicles ?? [])->contains(fn ($vehicle) => ! empty($vehicle['booking_id']));

        return $isNormalized ? $quotation : null;
    }

    private function centavosOrZero(string $pesos): int
    {
        try {
            return Money::pesosToCentavos($pesos);
        } catch (\InvalidArgumentException) {
            return 0;
        }
    }
}
