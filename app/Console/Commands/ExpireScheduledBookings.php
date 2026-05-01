<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireScheduledBookings extends Command
{
    protected $signature   = 'bookings:expire-scheduled';
    protected $description = 'Cancel scheduled bookings whose 7-day reservation window has expired';

    public function handle(): int
    {
        $expired = Booking::where('status', 'scheduled')
            ->whereNotNull('scheduled_expires_at')
            ->where('scheduled_expires_at', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired scheduled bookings found.');
            return self::SUCCESS;
        }

        $ids = $expired->pluck('id')->all();

        // Decrement capacity slots for each affected date
        foreach ($expired->groupBy(fn($b) => optional($b->scheduled_for)->toDateString()) as $date => $group) {
            if ($date) {
                DB::table('booking_capacity')
                    ->where('booking_date', $date)
                    ->decrement('slots_used', $group->count());
            }
        }

        Booking::whereIn('id', $ids)->update([
            'status'     => 'cancelled',
            'updated_at' => now(),
        ]);

        $this->info("Cancelled {$expired->count()} expired scheduled booking(s).");

        return self::SUCCESS;
    }
}
