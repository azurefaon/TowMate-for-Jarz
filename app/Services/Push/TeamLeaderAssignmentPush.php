<?php

namespace App\Services\Push;

use App\Models\Booking;
use App\Models\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "New Towing Assignment" push to the ONE Team Leader a booking was just assigned to.
 *
 * Sent from the Booking `updated` hook after the assignment has committed (see AppServiceProvider), so every
 * assignment path (dispatcher assign, reassignment of a returned task, next-task queue promotion) is covered and a
 * rolled-back assignment never notifies anyone. Best-effort like BookingStatusPush: failures never affect dispatch.
 */
class TeamLeaderAssignmentPush
{
    public const TITLE = 'New Towing Assignment';
    public const BODY = 'You have received a new towing task. Tap to view the details.';

    public function __construct(private readonly FcmClient $fcm) {}

    public function notify(Booking $booking): void
    {
        try {
            $this->dispatch($booking);
        } catch (\Throwable $e) {
            Log::warning('Team Leader assignment push failed', ['booking_id' => $booking->id, 'error' => class_basename($e)]);
        }
    }

    private function dispatch(Booking $booking): void
    {
        $teamLeaderId = (int) $booking->assigned_team_leader_id;
        if ($booking->status !== 'assigned' || $teamLeaderId === 0 || ! $this->fcm->isConfigured()) {
            return;
        }

        $tokens = DeviceToken::where('user_id', $teamLeaderId)->get();

        // One push per assignment: a retried request or a second save of the same assignment is not a new task, while
        // a later reassignment (new assigned_at) is.
        $assignedAt = $booking->assigned_at?->getTimestamp() ?? 0;
        if ($tokens->isEmpty() || ! Cache::add("tl-assignment-push:{$booking->id}:{$teamLeaderId}:{$assignedAt}", 1, now()->addHours(12))) {
            return;
        }

        $payload = [
            'type'         => 'tl_assignment',
            'booking_id'   => (string) $booking->id,
            'booking_code' => (string) $booking->booking_code,
            'status'       => 'assigned',
        ];

        $sent = 0;
        $removed = 0;
        foreach ($tokens as $device) {
            $result = $this->fcm->send($device->token, self::TITLE, self::BODY, $payload);
            if ($result === FcmClient::RESULT_SENT) {
                $sent++;
            } elseif ($result === FcmClient::RESULT_INVALID_TOKEN) {
                $device->delete();
                $removed++;
            }
        }

        Log::info('Team Leader assignment push', [
            'booking_id' => $booking->id,
            'devices'    => $tokens->count(),
            'sent'       => $sent,
            'removed'    => $removed,
        ]);
    }
}
