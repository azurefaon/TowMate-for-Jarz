<?php

namespace App\Services;

use App\Events\BookingStatusUpdated;
use App\Exceptions\QueueOfferException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingQueueOffer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Next-task queue: a dispatcher reserves a not-yet-assigned booking for a Team Leader who is
 * finishing another job at the drop-off.
 *
 *   offered -> accepted (Team Leader)  -> promoted (dispatcher, once the Team Leader is free)
 *           -> rejected (Team Leader) / expired (time) / cancelled (dispatcher or the world changed)
 *
 * A queued booking is never an active assignment: it keeps its own status and has no unit or Team
 * Leader until the dispatcher promotes it, and promotion re-validates everything.
 *
 * Lock order, always: the job being finished, then the queued booking, then the unit, then the
 * offer row. That matches assignBooking() (booking then unit) and complete() (booking first), so
 * none of these can wait on each other in a cycle.
 */
class NextTaskQueueService
{
    /** Booking statuses that can be queued: accepted and waiting for a unit. */
    public const QUEUEABLE_STATUSES = ['confirmed', 'scheduled_confirmed'];

    public function __construct(
        protected UnitAvailabilityService $availability,
        protected TeamLeaderAvailabilityService $teamLeaders,
    ) {
    }

    /** Off by default: stays off in production until the Team Leader app that understands offers ships. */
    public function enabled(): bool
    {
        return (bool) config('towmate.next_task_queue_enabled', false);
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('towmate.queue_offer_ttl_minutes', 10));
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    public function activeOfferForBooking(Booking $booking): ?BookingQueueOffer
    {
        return BookingQueueOffer::active()->where('booking_id', $booking->id)->first();
    }

    public function hasActiveOffer(Booking $booking): bool
    {
        return BookingQueueOffer::active()->where('booking_id', $booking->id)->exists();
    }

    public function activeOfferForTeamLeader(int $teamLeaderId): ?BookingQueueOffer
    {
        return BookingQueueOffer::active()->where('team_leader_id', $teamLeaderId)->latest('id')->first();
    }

    /**
     * Expires overdue offers and cancels any whose booking or team changed under them (cancelled,
     * assigned elsewhere, unit moved to another leader). Cheap and safe to run on any read.
     */
    public function sweep(): void
    {
        BookingQueueOffer::where('status', BookingQueueOffer::OFFERED)
            ->where('expires_at', '<=', now())
            ->update(['status' => BookingQueueOffer::EXPIRED, 'updated_at' => now()]);

        BookingQueueOffer::active()->with(['booking', 'unit'])->get()->each(function (BookingQueueOffer $offer) {
            $reason = $this->staleReason($offer);
            if ($reason !== null) {
                $this->cancelWhere($offer->id, $reason, null);
            }
        });
    }

    protected function staleReason(BookingQueueOffer $offer): ?string
    {
        $booking = $offer->booking;
        if (! $booking || ! in_array($booking->status, self::QUEUEABLE_STATUSES, true) || $booking->assigned_team_leader_id || $booking->assigned_unit_id) {
            return 'booking_changed';
        }

        $unit = $offer->unit;
        if (! $unit || $unit->archived_at || (int) $unit->team_leader_id !== (int) $offer->team_leader_id) {
            return 'team_changed';
        }

        return null;
    }

    // ── Eligibility ──────────────────────────────────────────────────────────

    /** Why this booking cannot be queued at all, or null. */
    public function bookingBlocker(Booking $booking): ?string
    {
        if ($booking->group_code) {
            return 'Group bookings cannot be queued yet.';
        }
        if (! in_array($booking->status, self::QUEUEABLE_STATUSES, true)) {
            return 'Only an accepted booking that is still waiting for a unit can be queued.';
        }
        if ($booking->assigned_unit_id || $booking->assigned_team_leader_id) {
            return 'This booking already has a unit assigned.';
        }
        if ($booking->status === 'scheduled_confirmed' && ! in_array($booking->scheduling_bucket, ['ready', 'overdue'], true)) {
            return 'This booking is not yet ready for dispatch — unit selection opens 1 hour before the scheduled service time.';
        }

        return null;
    }

    /**
     * Can this unit's Team Leader be offered this booking as a next task?
     *
     * Stale GPS is deliberately not a reason to refuse. Everything else the assignment rules
     * check still applies, except that the unit being busy with the job it is finishing is the
     * whole point.
     *
     * @return array{eligible: bool, reason: ?string, current: ?string}
     */
    public function evaluate(Booking $booking, Unit $unit, ?array $evaluation = null, ?\Illuminate\Support\Collection $activeByLeader = null): array
    {
        $current = null;
        $refuse = fn (string $reason) => ['eligible' => false, 'reason' => $reason, 'current' => $current?->booking_code];

        if (! $this->enabled()) {
            return $refuse('Next-task queueing is not enabled.');
        }
        if ($blocker = $this->bookingBlocker($booking)) {
            return $refuse($blocker);
        }
        if ((int) $unit->truck_type_id !== (int) $booking->truck_type_id) {
            $bookingClass = $booking->truckType?->name ?? 'the requested class';
            $unitClass = $unit->truckType?->name ?? 'another class';

            return $refuse("Exact tow class required: this booking needs {$bookingClass}; this unit is {$unitClass}.");
        }

        $current = $unit->team_leader_id
            ? ($activeByLeader ?? $this->teamLeaders->activeBookingsByLeaderId())->get((int) $unit->team_leader_id)
            : null;

        if (! $current) {
            return $refuse('This unit is not finishing a job. Assign it directly if it is available.');
        }
        if ($current->status !== 'arrived_dropoff') {
            return $refuse('Only a unit finishing at the drop-off can be queued.');
        }
        if ($current->group_code) {
            return $refuse('The job being finished is part of a group booking; it cannot be queued behind yet.');
        }

        // Stale GPS is fine (finishing Team Leaders stop sending it), but an offer to someone whose
        // app is not reachable would only sit reserved until it expires.
        if (! $this->teamLeaders->isOnline($unit->teamLeader)) {
            return $refuse('This Team Leader appears offline and cannot receive an offer right now.');
        }

        if ($this->hasActiveOffer($booking)) {
            return $refuse('This booking is already queued for a Team Leader.');
        }
        if (BookingQueueOffer::active()->where(fn ($q) => $q->where('unit_id', $unit->id)->orWhere('team_leader_id', $unit->team_leader_id))->exists()) {
            return $refuse('This Team Leader already has a queued next task.');
        }
        if (BookingQueueOffer::active()->where('behind_booking_id', $current->id)->exists()) {
            return $refuse('This job already has a next task queued.');
        }

        $evaluation ??= $this->availability->evaluate($unit);
        $blocking = $this->blockingReasons($unit, $booking, $evaluation, $current);
        if ($blocking !== []) {
            return $refuse($this->reasonText($blocking[0], $evaluation));
        }

        return ['eligible' => true, 'reason' => null, 'current' => $current->booking_code];
    }

    /** Availability reasons that count against queueing, ignoring the job being finished. */
    protected function blockingReasons(Unit $unit, Booking $booking, array $evaluation, ?Booking $finishing): array
    {
        $reasons = collect($evaluation['reasons'] ?? [])->reject(function (string $reason) use ($booking, $evaluation, $finishing) {
            return ($reason === 'busy' && $finishing && ($evaluation['active_booking']?->id ?? null) === $finishing->id)
                || ($reason === 'reserved' && ($evaluation['reservation']?->id ?? null) === $booking->id);
        })->values()->all();

        $override = $unit->teamLeader ? $this->teamLeaders->operationalOverride($unit->teamLeader) : null;
        if (in_array($override['status'] ?? null, ['busy', 'unavailable'], true)) {
            $reasons[] = 'dispatcher_override';
        }

        return $reasons;
    }

    public function reasonText(string $reason, array $evaluation = []): string
    {
        if ($reason === 'busy') {
            return ($evaluation['active_booking']?->status ?? null) === 'arrived_dropoff'
                ? 'Finishing a task at the drop-off. It can be assigned after the Team Leader submits payment.'
                : 'Busy on an active job.';
        }

        return match ($reason) {
            'reserved'                     => 'Reserved for another booking.',
            'queue_reserved'               => 'Reserved for a queued next task.',
            'maintenance'                  => 'Unit is under maintenance.',
            'archived'                     => 'Unit is archived.',
            'no_team_leader'               => 'No Team Leader is assigned to this unit.',
            'team_leader_inactive'         => 'The Team Leader account is inactive.',
            'team_leader_duty_unavailable' => 'The Team Leader is marked unavailable for duty.',
            'no_driver'                    => 'No driver is assigned to this unit.',
            'driver_inactive'              => 'The driver is inactive.',
            'driver_duty_unavailable'      => 'The driver is marked unavailable for duty.',
            'dispatcher_override'          => 'Dispatch has marked this Team Leader busy or unavailable.',
            default                        => 'This unit is not available for dispatch right now.',
        };
    }

    // ── Transitions ──────────────────────────────────────────────────────────

    /**
     * Dispatcher offers $booking to the Team Leader of $unit. Everything is re-checked under locks.
     */
    public function createOffer(Booking $booking, Unit $unit, User $dispatcher): BookingQueueOffer
    {
        $error = null;

        $offer = DB::transaction(function () use ($booking, $unit, $dispatcher, &$error) {
            // 1. the job being finished  2. the queued booking  3. the unit  (then the offers)
            $finishingId = Booking::query()
                ->whereIn('status', $this->teamLeaders->busyStatusesList())
                ->where('assigned_team_leader_id', $unit->team_leader_id)
                ->whereNull('returned_at')
                ->orderByDesc('updated_at')
                ->value('id');
            if ($finishingId) {
                Booking::whereKey($finishingId)->lockForUpdate()->first();
            }
            $lockedBooking = Booking::with('truckType')->whereKey($booking->id)->lockForUpdate()->first();
            $lockedUnit = Unit::lockInIdOrder([$unit->id])->get($unit->id);

            if (! $lockedBooking || ! $lockedUnit) {
                $error = ['This booking or unit no longer exists.', 404, 'not_found'];

                return null;
            }

            // Offers that ran out of time must not block a fresh one.
            BookingQueueOffer::where('status', BookingQueueOffer::OFFERED)->where('expires_at', '<=', now())
                ->where(fn ($q) => $q->where('booking_id', $lockedBooking->id)
                    ->orWhere('unit_id', $lockedUnit->id)
                    ->orWhere('team_leader_id', $lockedUnit->team_leader_id))
                ->update(['status' => BookingQueueOffer::EXPIRED, 'updated_at' => now()]);

            $lockedUnit->load(['teamLeader', 'truckType']);
            $verdict = $this->evaluate($lockedBooking, $lockedUnit);
            if (! $verdict['eligible']) {
                $error = [$verdict['reason'], 422, 'not_eligible'];

                return null;
            }

            $current = $this->teamLeaders->activeBookingsByLeaderId()->get((int) $lockedUnit->team_leader_id);

            try {
                // Its own savepoint, so losing the unique-index race leaves the outer transaction usable.
                $created = DB::transaction(fn () => BookingQueueOffer::create([
                    'booking_id' => $lockedBooking->id,
                    'behind_booking_id' => $current->id,
                    'team_leader_id' => $lockedUnit->team_leader_id,
                    'unit_id' => $lockedUnit->id,
                    'status' => BookingQueueOffer::OFFERED,
                    'offered_at' => now(),
                    'expires_at' => now()->addMinutes($this->ttlMinutes()),
                    'created_by' => $dispatcher->id,
                ]));
            } catch (UniqueConstraintViolationException) {
                // A competing request won between our check and our insert.
                $error = ['Another next-task offer was created at the same time for this booking, unit or Team Leader.', 409, 'conflict'];

                return null;
            }

            $this->audit($dispatcher->id, 'queue_offer_created', $lockedBooking, 'Next task ' . $lockedBooking->booking_code . ' offered to ' . ($lockedUnit->teamLeader?->name ?? 'Team Leader') . ' behind ' . $current->booking_code . '.');

            return $created;
        });

        if ($error !== null) {
            throw new QueueOfferException($error[0], $error[1], $error[2]);
        }

        return $offer;
    }

    /**
     * The Team Leader answers. Idempotent: repeating the same answer succeeds without changing anything.
     *
     * @return array{offer: BookingQueueOffer, changed: bool}
     */
    public function respond(User $teamLeader, int $offerId, string $decision): array
    {
        $target = $decision === 'accept' ? BookingQueueOffer::ACCEPTED : BookingQueueOffer::REJECTED;

        $result = DB::transaction(function () use ($teamLeader, $offerId, $target) {
            $probe = BookingQueueOffer::find($offerId);
            // Another Team Leader's offer looks exactly like one that does not exist.
            if (! $probe || (int) $probe->team_leader_id !== (int) $teamLeader->id) {
                return ['error' => ['Offer not found.', 404, 'not_found']];
            }

            Booking::whereKey($probe->booking_id)->lockForUpdate()->first(); // booking first
            $offer = BookingQueueOffer::whereKey($offerId)->lockForUpdate()->first();

            if ($offer->status === BookingQueueOffer::OFFERED && $offer->expires_at->lte(now())) {
                $offer->update(['status' => BookingQueueOffer::EXPIRED]);
            }

            if ($offer->status === $target) {
                return ['offer' => $offer, 'changed' => false];
            }

            // A reservation can still be released by declining; nothing else leaves a settled offer.
            $releasing = $target === BookingQueueOffer::REJECTED && $offer->status === BookingQueueOffer::ACCEPTED;
            if ($offer->status !== BookingQueueOffer::OFFERED && ! $releasing) {
                return ['error' => [$this->terminalMessage($offer), 409, $offer->status]];
            }

            if ($target === BookingQueueOffer::ACCEPTED && ($stale = $this->staleReason($offer->load(['booking', 'unit'])))) {
                $offer->update(['status' => BookingQueueOffer::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $stale]);

                return ['error' => ['This task is no longer available.', 409, 'cancelled']];
            }

            $offer->update(array_filter([
                'status' => $target,
                'responded_at' => now(),
                'accepted_at' => $target === BookingQueueOffer::ACCEPTED ? now() : null,
            ]));

            $this->audit($teamLeader->id, $target === BookingQueueOffer::ACCEPTED ? 'queue_offer_accepted' : 'queue_offer_rejected', $offer->booking, 'Team Leader ' . ($target === BookingQueueOffer::ACCEPTED ? 'accepted' : 'declined') . ' the next-task offer.');

            return ['offer' => $offer->fresh(), 'changed' => true];
        });

        if (isset($result['error'])) {
            throw new QueueOfferException($result['error'][0], $result['error'][1], $result['error'][2]);
        }

        return $result;
    }

    /** Dispatcher withdraws an offered or accepted reservation. Idempotent. */
    public function cancel(int $offerId, User $dispatcher, string $reason = 'dispatcher_cancelled'): BookingQueueOffer
    {
        return $this->cancelWhere($offerId, $reason, $dispatcher->id)
            ?? throw new QueueOfferException('Offer not found.', 404, 'not_found');
    }

    protected function cancelWhere(int $offerId, string $reason, ?int $userId): ?BookingQueueOffer
    {
        $error = null;

        $offer = DB::transaction(function () use ($offerId, $reason, $userId, &$error) {
            $probe = BookingQueueOffer::find($offerId);
            if (! $probe) {
                return null;
            }
            Booking::whereKey($probe->booking_id)->lockForUpdate()->first(); // booking first
            $offer = BookingQueueOffer::whereKey($offerId)->lockForUpdate()->first();

            if ($offer->status === BookingQueueOffer::CANCELLED) {
                return $offer;
            }
            if (! $offer->isActive()) {
                $error = [$this->terminalMessage($offer), 409, $offer->status];

                return null;
            }

            $offer->update(['status' => BookingQueueOffer::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'cancelled_by' => $userId]);
            $this->audit($userId, 'queue_offer_cancelled', $offer->booking, 'Next-task offer cancelled (' . $reason . ').');

            return $offer->fresh();
        });

        if ($error !== null) {
            throw new QueueOfferException($error[0], $error[1], $error[2]);
        }

        return $offer;
    }

    /**
     * Dispatcher turns an accepted, now-ready reservation into a normal assignment. The Team Leader
     * then accepts it through the usual task flow. Idempotent for an offer already promoted.
     *
     * @return array{offer: BookingQueueOffer, booking: Booking, changed: bool}
     */
    public function promote(int $offerId, User $dispatcher): array
    {
        $result = DB::transaction(function () use ($offerId, $dispatcher) {
            $probe = BookingQueueOffer::find($offerId);
            if (! $probe) {
                return ['error' => ['Offer not found.', 404, 'not_found']];
            }

            $booking = Booking::with('truckType')->whereKey($probe->booking_id)->lockForUpdate()->first(); // booking, then unit, then offer
            $unit = Unit::lockInIdOrder([$probe->unit_id])->get($probe->unit_id);
            $offer = BookingQueueOffer::whereKey($offerId)->lockForUpdate()->first();

            if ($offer->status === BookingQueueOffer::PROMOTED && $booking && (int) $booking->assigned_team_leader_id === (int) $offer->team_leader_id) {
                return ['offer' => $offer, 'booking' => $booking, 'changed' => false];
            }
            if ($offer->status !== BookingQueueOffer::ACCEPTED) {
                return ['error' => [$offer->status === BookingQueueOffer::OFFERED
                    ? 'The Team Leader has not accepted this offer yet.'
                    : $this->terminalMessage($offer), 409, $offer->status]];
            }
            if (! $booking || ! $unit) {
                return ['error' => ['This booking or unit no longer exists.', 409, 'not_found']];
            }

            $unit->load(['teamLeader', 'truckType']);
            if ($blocker = $this->releaseBlocker($offer, $booking, $unit)) {
                return ['error' => [$blocker, 409, 'not_ready']];
            }

            $booking->update([
                'status' => 'assigned',
                'assigned_unit_id' => $unit->id,
                'assigned_team_leader_id' => $unit->team_leader_id,
                'assigned_at' => now(),
            ]);
            $offer->update(['status' => BookingQueueOffer::PROMOTED, 'promoted_at' => now()]);

            $this->audit($dispatcher->id, 'booking_assigned', $booking, 'Assigned to unit ' . ($unit->name ?? 'N/A') . ' (promoted from the next-task queue).');
            $this->audit($dispatcher->id, 'queue_offer_promoted', $booking, 'Next-task offer promoted to a normal assignment.');

            return ['offer' => $offer->fresh(), 'booking' => $booking->fresh(['customer', 'truckType', 'unit.teamLeader']), 'changed' => true];
        });

        if (isset($result['error'])) {
            throw new QueueOfferException($result['error'][0], $result['error'][1], $result['error'][2]);
        }

        if ($result['changed']) {
            BookingStatusUpdated::safeFire($result['booking']);
        }

        return $result;
    }

    /**
     * Why an accepted offer cannot be promoted yet, or null when it is ready. This is the single
     * "released" test: the Team Leader is free again (the existing payment-submitted milestone) and
     * every normal assignment rule holds right now.
     */
    public function releaseBlocker(BookingQueueOffer $offer, Booking $booking, Unit $unit): ?string
    {
        if ($blocker = $this->bookingBlocker($booking)) {
            return $blocker;
        }
        if ((int) $unit->team_leader_id !== (int) $offer->team_leader_id) {
            return 'The unit now belongs to a different Team Leader.';
        }
        if ((int) $unit->truck_type_id !== (int) $booking->truck_type_id) {
            return 'This unit is no longer the required tow class for the booking.';
        }

        $evaluation = $this->availability->evaluate($unit);
        $reasons = collect($evaluation['reasons'] ?? [])->reject(function (string $reason) use ($offer, $booking, $evaluation) {
            return ($reason === 'queue_reserved' && ($evaluation['queue_offer']?->id ?? null) === $offer->id)
                || ($reason === 'reserved' && ($evaluation['reservation']?->id ?? null) === $booking->id);
        })->values()->all();

        $override = $unit->teamLeader ? $this->teamLeaders->operationalOverride($unit->teamLeader) : null;
        if (in_array($override['status'] ?? null, ['busy', 'unavailable'], true)) {
            $reasons[] = 'dispatcher_override';
        }

        if (in_array('busy', $reasons, true)) {
            return 'The Team Leader is still finishing the current job. Assign the next task after payment is submitted.';
        }

        return $reasons === [] ? null : $this->reasonText($reasons[0], $evaluation);
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /** Dispatcher view of one offer, including the derived "ready for review" state. */
    public function present(BookingQueueOffer $offer): array
    {
        $offer->loadMissing(['booking.truckType', 'behindBooking', 'teamLeader', 'unit']);
        $blocker = null;
        $state = $offer->status;

        if ($offer->status === BookingQueueOffer::OFFERED) {
            $state = 'awaiting_tl';
        } elseif ($offer->status === BookingQueueOffer::ACCEPTED) {
            $blocker = $offer->booking && $offer->unit ? $this->releaseBlocker($offer, $offer->booking, $offer->unit) : 'This booking or unit no longer exists.';
            $state = $blocker === null ? 'ready' : 'reserved';
        }

        return [
            'id' => $offer->id,
            'status' => $offer->status,
            'state' => $state,
            'booking_code' => $offer->booking?->booking_code,
            'current_booking_code' => $offer->behindBooking?->booking_code,
            'team_leader_name' => $offer->teamLeader?->full_name ?? $offer->teamLeader?->name,
            'unit_id' => $offer->unit_id,
            'unit_name' => $offer->unit?->name,
            'plate_number' => $offer->unit?->plate_number,
            'expires_at' => $offer->expires_at?->toIso8601String(),
            'seconds_left' => $offer->status === BookingQueueOffer::OFFERED ? max(0, (int) now()->diffInSeconds($offer->expires_at, false)) : null,
            'can_promote' => $state === 'ready',
            'promote_blocker' => $offer->status === BookingQueueOffer::ACCEPTED ? $blocker : null,
        ];
    }

    /** What a Team Leader may see about their offer: the task, not the customer. */
    public function presentForTeamLeader(User $teamLeader): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $this->sweep();

        $offer = $this->activeOfferForTeamLeader((int) $teamLeader->id);
        if (! $offer) {
            return null;
        }

        $offer->loadMissing(['booking.truckType', 'behindBooking']);
        $booking = $offer->booking;

        return [
            'id' => $offer->id,
            'status' => $offer->status,
            'expires_at' => $offer->expires_at?->toIso8601String(),
            'seconds_left' => $offer->status === BookingQueueOffer::OFFERED ? max(0, (int) now()->diffInSeconds($offer->expires_at, false)) : null,
            'accepted_at' => $offer->accepted_at?->toIso8601String(),
            'current_booking_code' => $offer->behindBooking?->booking_code,
            'booking' => [
                'booking_code' => $booking?->booking_code,
                'pickup_address' => $booking?->pickup_address,
                'dropoff_address' => $booking?->dropoff_address,
                'tow_class' => $booking?->truckType?->name,
                'vehicle_info' => $booking ? trim(implode(' ', array_filter([$booking->vehicle_make, $booking->vehicle_model]))) ?: null : null,
                'is_scheduled' => $booking?->service_type === 'schedule',
                'scheduled_for' => $booking?->scheduled_for?->toIso8601String(),
            ],
        ];
    }

    protected function terminalMessage(BookingQueueOffer $offer): string
    {
        return match ($offer->status) {
            BookingQueueOffer::EXPIRED => 'This offer has expired.',
            BookingQueueOffer::REJECTED => 'This offer was declined.',
            BookingQueueOffer::CANCELLED => 'This offer was cancelled.',
            BookingQueueOffer::PROMOTED => 'This offer has already been assigned.',
            BookingQueueOffer::ACCEPTED => 'This offer was already accepted.',
            default => 'This offer can no longer be changed.',
        };
    }

    protected function audit(?int $userId, string $action, ?Booking $booking, string $description): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'category' => 'dispatch',
            'entity_type' => 'Booking',
            'entity_id' => $booking?->id,
            'reference' => $booking?->booking_code,
            'description' => $description,
        ]);
    }
}
