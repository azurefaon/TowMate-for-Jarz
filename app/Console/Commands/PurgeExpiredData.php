<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Receipt;
use Illuminate\Console\Command;

class PurgeExpiredData extends Command
{
    protected $signature = 'towmate:purge-expired-data';

    protected $description = 'Permanently delete TowMate bookings, customers, and receipts older than 14 days';

    public function handle(): int
    {
        $cutoff = now()->subDays(14);

        $deletedReceipts = Receipt::query()
            ->where('created_at', '<=', $cutoff)
            ->delete();

        $deletedBookings = Booking::query()
            ->where('created_at', '<=', $cutoff)
            ->delete();

        $deletedCustomers = Customer::query()
            ->where('created_at', '<=', $cutoff)
            ->delete();

        $this->info("Purged {$deletedReceipts} receipts, {$deletedBookings} bookings, and {$deletedCustomers} customers older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
