<?php

namespace App\Services;

use App\Exceptions\Booking\CapacityInconsistentException;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place that changes booking_capacity for scheduled bookings.
 *
 * Rules:
 *  - A booking holds one slot from the moment its quotation is accepted and its
 *    price locked (reserve()), recorded in bookings.capacity_reserved_date: the
 *    date whose counter currently includes it. NULL means it holds none.
 *  - Cancelling, rejecting or marking it not responding gives that slot back
 *    exactly once (release()); a completed booking keeps its consumed slot.
 *  - Rescheduling moves the slot from the recorded date to the new one in the
 *    same transaction (move()).
 *  - slots_max is not enforced here.
 *
 * Every method runs inside the caller's transaction (it opens a savepoint if
 * there isn't one). The booking row is locked first, then the capacity rows in
 * ascending date order, so concurrent requests queue instead of deadlocking
 * and the marker, not the booking's status or current date, decides what is
 * released. A decrement the counter can't honour is refused and logged, never
 * hidden by clamping.
 */
class ScheduledCapacityService
{
    public const RELEASED = 'released';

    /** The booking holds no counted slot: nothing to release. */
    public const NONE = 'none';

    /** An accepted booking from before the marker existed: not released, logged. */
    public const LEGACY_UNMARKED = 'legacy_unmarked';

    /** The marker says it holds a slot the counter can't give back: not released, logged. */
    public const INCONSISTENT = 'inconsistent';

    /**
     * Locks the capacity rows that exist for these dates, in ascending date
     * order. Call before touching several dates so every transaction takes
     * them in the same order.
     *
     * @param  iterable<string>  $dates  Y-m-d
     */
    public function lockDates(iterable $dates): void
    {
        $dates = collect($dates)->filter()->map(fn ($date) => substr((string) $date, 0, 10))->unique()->sort()->values();

        if ($dates->isNotEmpty()) {
            DB::table('booking_capacity')->whereIn('booking_date', $dates->all())->orderBy('booking_date')->lockForUpdate()->get(['id']);
        }
    }

    /**
     * Counts a slot for this booking on $date and records it. Does nothing,
     * and returns false, if the booking already holds one (a booking is never
     * counted twice). The caller holds the booking's row lock.
     */
    public function reserve(Booking $booking, string $date): bool
    {
        $date = substr($date, 0, 10);

        return DB::transaction(function () use ($booking, $date) {
            $marker = Booking::whereKey($booking->id)->value('capacity_reserved_date');

            if ($marker !== null) {
                return false;
            }

            $this->increment($date);
            $this->setMarker($booking, $date);

            return true;
        });
    }

    /**
     * Gives back the slot this booking holds, once. Returns one of the class
     * constants. Safe to call repeatedly and concurrently: the booking row is
     * locked and the marker is cleared in the same transaction as the decrement.
     */
    public function release(Booking|int $booking): string
    {
        $id = $booking instanceof Booking ? $booking->id : $booking;

        // A completed booking consumed its slot; it is never given back, even if
        // some caller later writes "cancelled" over it.
        if ($booking instanceof Booking && ($booking->getPrevious()['status'] ?? null) === 'completed') {
            return self::NONE;
        }

        // Only a call that has just ended an active booking can tell a legacy
        // (never marked) reservation from one that was already released: a
        // released booking also has no marker. Callers update the status first,
        // so the previous status is on the model.
        $endedJustNow = $booking instanceof Booking && $this->wasActiveBeforeThisChange($booking);

        $result = DB::transaction(function () use ($id, $endedJustNow) {
            $row = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $row) {
                return self::NONE;
            }

            if ($row->capacity_reserved_date === null) {
                return ($endedJustNow && $this->looksReserved($row)) ? $this->legacyNotReleased($row) : self::NONE;
            }

            $date = $row->capacity_reserved_date->toDateString();
            $this->lockDates([$date]);

            if (! $this->decrement($date)) {
                Log::error('Scheduled capacity release refused: the counter cannot give back this booking\'s slot.', [
                    'booking_id' => $row->id, 'booking_code' => $row->booking_code, 'reserved_date' => $date,
                ]);

                return self::INCONSISTENT;
            }

            Booking::whereKey($row->id)->update(['capacity_reserved_date' => null]);

            return self::RELEASED;
        });

        if ($booking instanceof Booking && $result === self::RELEASED) {
            $booking->forceFill(['capacity_reserved_date' => null])->syncOriginalAttribute('capacity_reserved_date');
        }

        return $result;
    }

    /**
     * Moves the booking's counted slot to $newDate. Returns false, changing
     * nothing, if the booking holds no slot (its existing non-reserved
     * behaviour is unchanged). Throws CapacityInconsistentException, changing
     * nothing, if the old date's counter can't give the slot back.
     */
    public function move(Booking $booking, string $newDate): bool
    {
        $newDate = substr($newDate, 0, 10);

        return DB::transaction(function () use ($booking, $newDate) {
            $row = Booking::whereKey($booking->id)->lockForUpdate()->first();
            $old = $row?->capacity_reserved_date?->toDateString();

            if ($old === null) {
                return false;
            }

            if ($old === $newDate) {
                return true;
            }

            $this->lockDates([$old, $newDate]);

            if (! $this->decrement($old)) {
                Log::error('Scheduled capacity move refused: the counter cannot give back this booking\'s slot.', [
                    'booking_id' => $row->id, 'booking_code' => $row->booking_code, 'reserved_date' => $old, 'new_date' => $newDate,
                ]);

                throw new CapacityInconsistentException('The capacity records for this booking are inconsistent, so it was not rescheduled.');
            }

            $this->increment($newDate);
            $this->setMarker($booking, $newDate);

            return true;
        });
    }

    /** The last save moved this booking from an active status to something else. */
    protected function wasActiveBeforeThisChange(Booking $booking): bool
    {
        $previous = $booking->getPrevious();

        return array_key_exists('status', $previous)
            && ! in_array($previous['status'], ['cancelled', 'rejected', 'not_responding', 'completed'], true);
    }

    /** An accepted scheduled booking, whatever its marker says. */
    protected function looksReserved(Booking $booking): bool
    {
        return $booking->service_type === 'schedule'
            && $booking->price_locked_at !== null
            && $booking->scheduled_date !== null;
    }

    protected function legacyNotReleased(Booking $booking): string
    {
        Log::warning('Accepted scheduled booking has no capacity marker, so its slot was not released. Reconcile it with `php artisan towmate:capacity-report`.', [
            'booking_id' => $booking->id, 'booking_code' => $booking->booking_code, 'scheduled_date' => $booking->scheduled_date?->toDateString(),
        ]);

        return self::LEGACY_UNMARKED;
    }

    protected function increment(string $date): void
    {
        DB::statement(
            'INSERT INTO booking_capacity (booking_date, slots_used, updated_at)
             VALUES (?, 1, NOW())
             ON CONFLICT (booking_date) DO UPDATE
             SET slots_used = booking_capacity.slots_used + 1, updated_at = NOW()',
            [$date]
        );
    }

    /** Takes one off the counter; false (and no change) if there is no row or it is already 0. */
    protected function decrement(string $date): bool
    {
        return DB::update(
            'UPDATE booking_capacity SET slots_used = slots_used - 1, updated_at = NOW() WHERE booking_date = ? AND slots_used > 0',
            [$date]
        ) === 1;
    }

    protected function setMarker(Booking $booking, ?string $date): void
    {
        Booking::whereKey($booking->id)->update(['capacity_reserved_date' => $date]);
        $booking->forceFill(['capacity_reserved_date' => $date])->syncOriginalAttribute('capacity_reserved_date');
    }
}
