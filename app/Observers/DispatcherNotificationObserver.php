<?php

namespace App\Observers;

use App\Models\Booking;
use App\Models\DispatcherNotification;

/**
 * Pure side-effect logging only — never alters Booking behavior. Creates the
 * three notification types the Dispatcher topbar cares about (see
 * DispatcherNotification): a new Book Now request, a new Scheduled request,
 * and a booking entering waiting_verification. Ordinary status churn,
 * self-authored actions (drafts, sending a quotation), and Team Leader
 * activity that doesn't need Dispatcher attention are deliberately NOT
 * turned into notifications — that's what Current Activity already covers.
 */
class DispatcherNotificationObserver
{
    public function created(Booking $booking): void
    {
        $type = match ($booking->service_type) {
            'book_now' => DispatcherNotification::TYPE_NEW_BOOK_NOW,
            'schedule' => DispatcherNotification::TYPE_NEW_SCHEDULED,
            default => null,
        };

        if ($type === null) {
            return;
        }

        DispatcherNotification::create([
            'type' => $type,
            'booking_id' => $booking->id,
        ]);
    }

    public function updated(Booking $booking): void
    {
        if ($booking->isDirty('status') && $booking->status === 'waiting_verification') {
            DispatcherNotification::create([
                'type' => DispatcherNotification::TYPE_VERIFICATION_REQUIRED,
                'booking_id' => $booking->id,
            ]);
        }
    }
}
