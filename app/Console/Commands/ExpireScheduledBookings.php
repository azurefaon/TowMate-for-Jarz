<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cancels scheduled bookings whose 7-day reservation window passed without a
 * quotation being generated.
 *
 * These bookings were never accepted, so they never reserved a slot in
 * booking_capacity (a slot is taken only when the customer accepts the
 * quotation and the price is locked). Cancelling them therefore does not touch
 * booking_capacity at all.
 *
 * Safe to run repeatedly and from more than one process at once: each booking
 * is cancelled in its own transaction, under its own row lock, after
 * re-checking that it is still eligible. A run that loses the race finds the
 * booking already cancelled and skips it, so nothing is counted or changed twice.
 */
class ExpireScheduledBookings extends Command
{
    protected $signature   = 'bookings:expire-scheduled';
    protected $description = 'Cancel scheduled bookings whose 7-day reservation window has expired';

    public function handle(): int
    {
        $cancelled = 0;
        $failed = 0;

        foreach ($this->eligible()->orderBy('id')->pluck('id') as $id) {
            try {
                if ($this->cancelIfStillEligible((int) $id)) {
                    $cancelled++;
                }
            } catch (Throwable $e) {
                // One booking failing rolls back only itself; the rest still run.
                $failed++;
                report($e);
                $this->error("Could not cancel booking {$id}; it was left unchanged.");
            }
        }

        if ($cancelled === 0 && $failed === 0) {
            $this->info('No expired scheduled bookings found.');
        } elseif ($cancelled > 0) {
            $this->info("Cancelled {$cancelled} expired scheduled booking(s).");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function eligible(): Builder
    {
        return Booking::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_expires_at')
            ->where('scheduled_expires_at', '<', now())
            ->where(function ($query) {
                $query->whereNull('quotation_generated')
                    ->orWhere('quotation_generated', false);
            });
    }

    /** Locks the booking, re-checks it, and cancels it. Returns false if someone else got there first. */
    protected function cancelIfStillEligible(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $locked = $this->eligible()->whereKey($id)->lockForUpdate()->first();

            if (! $locked) {
                return false;
            }

            // A plain query-builder update on the locked row: same effect (and
            // no model events or notifications) as the original bulk update.
            Booking::whereKey($locked->id)->update([
                'status'     => 'cancelled',
                'updated_at' => now(),
            ]);

            return true;
        });
    }
}
