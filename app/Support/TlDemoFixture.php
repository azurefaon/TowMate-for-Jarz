<?php

namespace App\Support;

use App\Http\Controllers\Api\TeamLeader\TLTaskController;
use App\Models\Booking;
use App\Models\User;

/**
 * Single guard for the LOCAL/DEMO-only "simulate arrival" affordance.
 *
 * Used by BOTH the dedicated endpoint (enforcement) and the task payload
 * (whether the app shows the button), so the two can never disagree. Every
 * condition below must hold; any failure means "not a demo arrival" and the
 * caller must fail closed.
 */
final class TlDemoFixture
{
    /** The seeded demo Team Leader (dev:seed-tl-assigned-team-demo). */
    public const TL_EMAIL = 'tl.assigned.demo@example.com';

    /** The seeded demo booking's customer (dev:seed-tl-demo-task). */
    public const CUSTOMER_EMAIL = 'demo.tl.task@towmate.test';

    /** Marker the demo fixture writes into dispatcher_note. */
    public const NOTE_PREFIX = 'DEMO TASK';

    /**
     * Only these current statuses can be simulated, and the arrival each one
     * produces. Deliberately derived server-side, never from the request.
     */
    public const ARRIVAL_FROM = [
        'on_the_way' => 'arrived_pickup',
        'on_job'     => 'arrived_dropoff',
    ];

    /** Statuses in which the app may offer the button (accepted first moves to on_the_way via the normal path). */
    private const OFFER_STATUSES = ['accepted', 'on_the_way', 'on_job'];

    public static function environmentAllowsDemo(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public static function isDemoUser(?User $user): bool
    {
        return $user !== null && strtolower((string) $user->email) === self::TL_EMAIL;
    }

    public static function isDemoBooking(Booking $booking, ?User $user): bool
    {
        if (! self::isDemoUser($user)) {
            return false;
        }

        $booking->loadMissing('customer');

        return (int) $booking->assigned_team_leader_id === (int) $user->id
            && strtolower((string) $booking->customer?->email) === self::CUSTOMER_EMAIL
            && str_starts_with((string) $booking->dispatcher_note, self::NOTE_PREFIX);
    }

    /** True when the environment, account and booking are all the demo fixture. */
    public static function eligible(Booking $booking, ?User $user): bool
    {
        return self::environmentAllowsDemo() && self::isDemoBooking($booking, $user);
    }

    /** Whether the app should render the simulator control for this task right now. */
    public static function offerSimulator(Booking $booking, ?User $user): bool
    {
        return self::eligible($booking, $user) && in_array($booking->status, self::OFFER_STATUSES, true);
    }

    /**
     * The arrival status a simulation would produce from [$status], or null
     * when it is not a valid simulation point. The normal transition table is
     * still the authority: the target must be an allowed next status.
     */
    public static function targetFor(string $status): ?string
    {
        $target = self::ARRIVAL_FROM[$status] ?? null;

        if ($target === null) {
            return null;
        }

        return in_array($target, TLTaskController::VALID_TRANSITIONS[$status] ?? [], true) ? $target : null;
    }
}
