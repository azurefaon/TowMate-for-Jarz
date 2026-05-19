<?php

namespace App\Services;

use App\Models\CustomerNotification;

class CustomerNotificationService
{
    public static function send(
        int $userId,
        string $type,
        string $title,
        string $body,
        ?string $bookingCode = null,
    ): void {
        try {
            CustomerNotification::create([
                'user_id'      => $userId,
                'type'         => $type,
                'title'        => $title,
                'body'         => $body,
                'booking_code' => $bookingCode,
            ]);
        } catch (\Throwable) {
            // Never let a notification failure break the main flow
        }
    }
}
