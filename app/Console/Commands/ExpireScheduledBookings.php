<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Retired. This command used to cancel scheduled bookings whose 7-day window had
 * passed without a quotation. A scheduled booking is now never cancelled just
 * because time has passed: it stays active until the customer cancels it or the
 * dispatcher acts on it (quote it, reschedule it, or cancel it).
 *
 * The command is kept as a harmless no-op so an external cron or script that
 * still calls it keeps succeeding. It changes nothing.
 */
class ExpireScheduledBookings extends Command
{
    protected $signature   = 'bookings:expire-scheduled';
    protected $description = 'Retired: scheduled bookings are no longer cancelled automatically (does nothing)';

    public function handle(): int
    {
        $this->info('Scheduled bookings are no longer cancelled automatically. Nothing to do.');

        return self::SUCCESS;
    }
}
