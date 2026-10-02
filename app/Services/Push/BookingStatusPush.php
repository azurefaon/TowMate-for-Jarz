<?php

namespace App\Services\Push;

use App\Models\Booking;
use App\Models\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Customer push for REAL persisted booking-status transitions.
 *
 * Call only after the status change has committed. Best-effort: every failure
 * is contained here and can never affect the booking lifecycle. Queue worker
 * availability on production is not guaranteed (QUEUE_CONNECTION=sync by
 * default, worker is an unsupervised background process), so delivery is
 * inline with short bounded HTTP timeouts instead of a queued job.
 */
class BookingStatusPush
{
    /**
     * Canonical customer-facing whitelist. Anything not listed (assigned,
     * loading_vehicle, waiting_verification, payment_*, delayed, returned, ...)
     * is internal/operational and never pushed.
     */
    public const MESSAGES = [
        'accepted'        => ['Towing job accepted', 'Your assigned team has accepted your towing request.'],
        'on_the_way'      => ['Tow truck on the way', 'Your tow truck is on the way.'],
        'arrived_pickup'  => ['Tow truck arrived', 'Your tow truck has arrived at the pickup location.'],
        'in_progress'     => ['Towing in progress', 'Your towing service is in progress.'],
        'on_job'          => ['On the way to drop-off', 'Your vehicle is on its way to the drop-off location.'],
        'arrived_dropoff' => ['Arrived at destination', 'Your vehicle has arrived at the destination.'],
        'completed'       => ['Towing complete', 'Your towing request is complete.'],
    ];

    public function __construct(private readonly FcmClient $fcm) {}

    public function notify(Booking $booking, ?string $oldStatus = null): void
    {
        try {
            $this->dispatch($booking, $oldStatus);
        } catch (\Throwable $e) {
            Log::warning('Booking status push failed', [
                'booking_id' => $booking->id,
                'status'     => $booking->status,
                'error'      => class_basename($e),
            ]);
        }
    }

    private function dispatch(Booking $booking, ?string $oldStatus): void
    {
        $status = (string) $booking->status;
        $message = self::MESSAGES[$status] ?? null;

        if ($message === null || $oldStatus === $status) {
            return;
        }

        $booking->loadMissing('customer');
        $userId = $booking->customer?->user_id;
        if (! $userId || ! $this->fcm->isConfigured()) {
            return;
        }

        $tokens = DeviceToken::where('user_id', $userId)->get();
        if ($tokens->isEmpty() || ! $this->claim($booking, $status)) {
            return;
        }

        $payload = [
            'type'         => 'booking_status',
            'booking_id'   => (string) $booking->id,
            'booking_code' => (string) $booking->booking_code,
            'status'       => $status,
        ];

        $sent = 0;
        $removed = 0;
        foreach ($tokens as $device) {
            $result = $this->fcm->send($device->token, $message[0], $message[1], $payload);
            if ($result === FcmClient::RESULT_SENT) {
                $sent++;
            } elseif ($result === FcmClient::RESULT_INVALID_TOKEN) {
                $device->delete();
                $removed++;
            }
        }

        Log::info('Booking status push', [
            'booking_id' => $booking->id,
            'status'     => $status,
            'devices'    => $tokens->count(),
            'sent'       => $sent,
            'removed'    => $removed,
        ]);
    }

    /**
     * One push per logical event. A multi-vehicle group shares group_code and
     * each vehicle row progresses on its own, so the group is the dedupe unit
     * (long window); a standalone booking only needs retry protection.
     */
    private function claim(Booking $booking, string $status): bool
    {
        $isGroup = $booking->group_code
            && Booking::where('group_code', $booking->group_code)->count() > 1;

        $key = $isGroup
            ? "booking-push:g:{$booking->group_code}:{$status}"
            : "booking-push:b:{$booking->id}:{$status}";

        return Cache::add($key, 1, $isGroup ? now()->addHours(12) : now()->addMinutes(10));
    }
}
