<?php

namespace App\Console\Commands;

use App\Mail\BookingAcceptedMail;
use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SyncQuotationLifecycle extends Command
{
    protected $signature = 'towmate:sync-quotation-lifecycle';

    protected $description = 'Expire outdated quotations and send day-5 follow-up reminders';

    public function handle(): int
    {
        $expiredCount = 0;
        $reminderCount = 0;

        $candidateQuotes = Booking::query()
            ->whereIn('status', ['quoted', 'quotation_sent'])
            ->get();

        foreach ($candidateQuotes as $booking) {
            if (method_exists($booking, 'syncQuotationLifecycle') && $booking->syncQuotationLifecycle()) {
                $expiredCount++;
            }
        }

        $followUpBookings = Booking::query()
            ->with(['customer', 'truckType'])
            ->whereIn('status', ['quoted', 'quotation_sent'])
            ->where('quotation_status', 'active')
            ->whereNotNull('quotation_sent_at')
            ->where('quotation_sent_at', '<=', now()->subDays(5))
            ->whereNull('quotation_follow_up_sent_at')
            ->get();

        foreach ($followUpBookings as $booking) {
            if (method_exists($booking, 'needsQuotationFollowUp') && ! $booking->needsQuotationFollowUp()) {
                continue;
            }

            if (! filled($booking->customer?->email)) {
                continue;
            }

            try {
                Mail::to($booking->customer->email)->send(new BookingAcceptedMail($booking, true));

                $booking->forceFill([
                    'quotation_follow_up_sent_at' => now(),
                ])->save();

                $reminderCount++;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send quotation follow-up reminder.', [
                    'booking_id' => $booking->id,
                    'customer_email' => $booking->customer?->email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Quotation lifecycle synced. Expired: {$expiredCount}; reminders sent: {$reminderCount}.");

        return self::SUCCESS;
    }
}
