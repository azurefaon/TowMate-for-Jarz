<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Finance\JobPersonnel;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Automatic job crew capture from the Dispatch workflow (called by JobCrewCaptureObserver).
 *
 * WHEN  a unit is assigned or reassigned to a booking, and again when the Team Leader accepts or starts the job
 *       (the roster can change between assignment and the start of work).
 * WHAT  the unit's crew AT THAT MOMENT: Team Leader account, Driver, second Driver and Crew slots, with personnel
 *       ids, names, the assignment time, and whether the person is on loan from another unit.
 * HOW   every captured row is "Pending confirmation" (needs_review). An assignment is not proof of participation,
 *       so pending rows block commission approval until the Owner confirms or corrects them on the Job Crew page.
 *       The only automatic confirmation: the Team Leader's own row, when that Team Leader's account completes the
 *       job (reliable evidence that they took part).
 *
 * Never guesses history: it only runs on live events, never reads the roster for past jobs, never backfills, and
 * never touches rows the Owner recorded or confirmed. Rows are superseded with a reason, never edited or deleted.
 * The second Driver's commission role is left unset on purpose (Rider / Substitute mapping is a client decision).
 */
class JobCrewCaptureService
{
    public const SOURCE = 'assignment_capture';

    /** Unit slot => commission role recorded for the assignment (null = the Owner must choose). */
    private const SLOT_ROLES = ['team_leader' => 'team_leader', 'driver_1' => 'driver', 'driver_2' => null, 'crew_member_1' => 'crew_mate', 'crew_member_2' => 'crew_mate'];

    /** Captures or re-syncs the pending crew of the booking's assigned unit. Safe to call repeatedly. */
    public function sync(Booking $booking, string $trigger): void
    {
        if (! $booking->assigned_unit_id) {
            return;
        }
        $unit = Unit::find($booking->assigned_unit_id);
        if (! $unit) {
            return;
        }

        DB::transaction(function () use ($booking, $unit, $trigger) {
            $tripIds = $this->tripMemberIds($booking);
            $current = JobPersonnel::current()->whereIn('booking_id', $tripIds)->where('unit_id', $unit->id)->lockForUpdate()->get();

            // A unit towing two vehicles of one trip is ONE crew: keep its rows on the first booking that captured them.
            $homeBookingId = $current->first()?->booking_id ?? $booking->id;
            $roster = $this->roster($unit);

            foreach ($roster as $slot => $member) {
                $existing = $current->firstWhere('slot', $slot);
                if ($existing && ! $this->isPendingCapture($existing)) {
                    continue;   // recorded or confirmed by the Owner: never touched
                }
                if ($existing && $this->samePerson($existing, $member)) {
                    continue;
                }
                // The same person may already be recorded for this job and unit in the same role: no duplicates.
                $duplicate = $current->first(fn (JobPersonnel $p) => $p->id !== $existing?->id && $member['role'] !== null && $p->commission_role === $member['role']
                    && (($member['personnel_id'] && $p->personnel_id === $member['personnel_id']) || ($member['user_id'] && $p->user_id === $member['user_id'])));
                if ($duplicate) {
                    continue;
                }
                if ($existing) {
                    $this->supersede($existing, "Crew changed before the job ({$trigger}): {$member['name']} replaced {$existing->name_snapshot}.");
                }
                JobPersonnel::create([
                    'booking_id' => $homeBookingId, 'unit_id' => $unit->id, 'slot' => $slot,
                    'personnel_id' => $member['personnel_id'], 'user_id' => $member['user_id'], 'name_snapshot' => mb_substr($member['name'], 0, 150),
                    'commission_role' => $member['role'], 'borrowed_from_unit_id' => $member['borrowed_from'], 'is_substitute' => $member['borrowed_from'] !== null,
                    'notes' => "Assigned crew captured from Dispatch ({$trigger}). Pending Owner confirmation.",
                    'source' => self::SOURCE, 'needs_review' => true, 'captured_by' => auth()->id(), 'captured_at' => now(),
                ]);
            }

            // Pending captures for slots that are now empty on the unit.
            foreach ($current as $row) {
                if ($this->isPendingCapture($row) && ! isset($roster[$row->slot])) {
                    $this->supersede($row, "No longer assigned to the unit before the job ({$trigger}).");
                }
            }
        });
    }

    /** The booking left this unit (reassigned, returned to Dispatch or cancelled): withdraw its pending captures. */
    public function release(Booking $booking, int $unitId, string $reason): void
    {
        DB::transaction(function () use ($booking, $unitId, $reason) {
            $tripIds = $this->tripMemberIds($booking);
            // Another vehicle of the same trip may still be assigned to this unit: its crew stays.
            $stillAssigned = Booking::whereIn('id', $tripIds)->whereKeyNot($booking->id)->where('assigned_unit_id', $unitId)
                ->whereNotIn('status', ['cancelled', 'rejected'])->exists();
            if ($stillAssigned) {
                return;
            }
            JobPersonnel::current()->whereIn('booking_id', $tripIds)->where('unit_id', $unitId)->lockForUpdate()->get()
                ->filter(fn (JobPersonnel $p) => $this->isPendingCapture($p))
                ->each(fn (JobPersonnel $p) => $this->supersede($p, $reason));
        });
    }

    /** The Team Leader's own account completed the job: that is reliable evidence the Team Leader took part. */
    public function confirmTeamLeaderOnCompletion(Booking $booking, User $teamLeader): void
    {
        if (! $booking->assigned_unit_id || (int) $booking->assigned_team_leader_id !== (int) $teamLeader->id) {
            return;
        }
        DB::transaction(function () use ($booking, $teamLeader) {
            $row = JobPersonnel::current()->whereIn('booking_id', $this->tripMemberIds($booking))->where('unit_id', $booking->assigned_unit_id)
                ->where('user_id', $teamLeader->id)->where('commission_role', 'team_leader')->lockForUpdate()->first();
            if (! $row || ! $this->isPendingCapture($row)) {
                return;
            }
            $row->forceFill(['needs_review' => false, 'confirmed_by' => $teamLeader->id, 'confirmed_at' => now()])->save();
            AuditLog::create([
                'user_id' => $teamLeader->id, 'actor_label' => $teamLeader->full_name ?: $teamLeader->name, 'action' => 'finance_crew_confirmed',
                'entity_type' => 'JobPersonnel', 'entity_id' => $row->id, 'reference' => (string) $booking->booking_code,
                'description' => "Team Leader {$row->name_snapshot} confirmed on job {$booking->booking_code} by completing it from their own account",
            ]);
        });
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string, array{name:string, personnel_id:?int, user_id:?int, role:?string, borrowed_from:?int}> */
    private function roster(Unit $unit): array
    {
        $roster = [];
        $leader = $unit->team_leader_id ? User::find($unit->team_leader_id) : null;
        if ($leader) {
            $roster['team_leader'] = ['name' => (string) ($leader->full_name ?: $leader->name), 'personnel_id' => null, 'user_id' => $leader->id, 'role' => 'team_leader', 'borrowed_from' => null];
        }
        $loans = $unit->activeLoansIn();
        foreach (Unit::SLOT_COLUMNS as $slot => $nameColumn) {
            $name = trim((string) $unit->{$nameColumn});
            if ($name === '') {
                continue;
            }
            $loan = $loans->get($slot);
            $roster[$slot] = [
                'name' => $name,
                'personnel_id' => $unit->{Unit::SLOT_PERSONNEL_COLUMNS[$slot]} ?: ($loan?->personnel_id ?: null),
                'user_id' => null,
                'role' => self::SLOT_ROLES[$slot],
                'borrowed_from' => $loan && (int) $loan->from_unit_id !== (int) $unit->id ? (int) $loan->from_unit_id : null,
            ];
        }

        return $roster;
    }

    private function tripMemberIds(Booking $booking): Collection
    {
        return $booking->group_code && Invoice::isNormalizedGroupTransaction($booking)
            ? Invoice::sameTripMembers($booking)->pluck('id')->push($booking->id)->unique()->values()
            : collect([$booking->id]);
    }

    private function isPendingCapture(JobPersonnel $row): bool
    {
        return $row->source === self::SOURCE && $row->needs_review && $row->confirmed_at === null;
    }

    private function samePerson(JobPersonnel $row, array $member): bool
    {
        if ($member['user_id'] || $row->user_id) {
            return (int) $row->user_id === (int) $member['user_id'];
        }
        if ($member['personnel_id'] || $row->personnel_id) {
            return (int) $row->personnel_id === (int) $member['personnel_id'];
        }

        return mb_strtolower(trim($row->name_snapshot)) === mb_strtolower(trim($member['name']));
    }

    private function supersede(JobPersonnel $row, string $reason): void
    {
        $row->forceFill(['superseded_at' => now(), 'superseded_by_user_id' => auth()->id(), 'supersede_reason' => mb_substr($reason, 0, 500)])->save();
    }
}
