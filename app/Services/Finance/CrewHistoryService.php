<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The crew a unit had on a past job, rebuilt from records only (read-only; nothing is written here):
 *
 * - Team Leader: the account Dispatch assigned to the booking (bookings.assigned_team_leader_id).
 * - Driver / second Driver / Crew slots: the unit's roster AT THE START OF THE JOB, found by walking the unit's
 *   audit log backwards from today's values and undoing every change made after that moment. Each step is checked:
 *   if a logged "new" value does not match what the chain expects, that slot's history is broken and the person is
 *   shown as uncertain. A slot changed while the job was running, or a job older than the audit history, is also
 *   uncertain. Today's roster is used only as the end point of that verified chain, never as evidence on its own.
 * - Crew on loan at that moment (unit_crew_loans with borrowed_at / returned_at) is marked as borrowed.
 *
 * The result is a proposal: the Owner confirms it on Job Crew (one action), unticking anyone who did not work.
 */
class CrewHistoryService
{
    private const SLOT_ROLES = ['team_leader' => 'team_leader', 'driver_1' => 'driver', 'driver_2' => null, 'crew_member_1' => 'crew_mate', 'crew_member_2' => 'crew_mate'];
    private const START_STATUSES = ['accepted', 'on_the_way'];

    /** @var array<int, Collection> */
    private array $unitLogs = [];
    private ?CarbonImmutable $auditStart = null;
    private bool $auditStartLoaded = false;

    /**
     * @return array<int, array{unit_id:int, unit_name:string, booking_id:int, as_of:?CarbonImmutable, members:array, notes:array}>
     */
    public function forJob(array $job): array
    {
        $out = [];
        foreach ($job['members'] as $member) {
            $booking = $member instanceof Booking ? $member : Booking::find($member['id'] ?? $member->id ?? null);
            if (! $booking || ! $booking->assigned_unit_id || isset($out[(int) $booking->assigned_unit_id])) {
                continue;   // one crew per unit, even when it towed several vehicles of the trip
            }
            $unit = Unit::withoutGlobalScopes()->find($booking->assigned_unit_id);
            if ($unit) {
                $out[$unit->id] = $this->forBooking($booking, $unit);
            }
        }

        return $out;
    }

    public function forBooking(Booking $booking, Unit $unit): array
    {
        $bookingLogs = AuditLog::where('entity_type', 'Booking')->where('entity_id', $booking->id)->orderBy('id')->get(['id', 'user_id', 'action', 'created_at', 'old_value', 'new_value']);
        $end = $this->at($booking->completion_requested_at ?? $booking->completed_at);
        $start = $this->startOfJob($booking, $bookingLogs, $end);
        $notes = [];
        $members = [];

        // Team Leader: the account Dispatch assigned to this booking.
        if ($booking->assigned_team_leader_id && ($leader = User::find($booking->assigned_team_leader_id))) {
            $completer = $bookingLogs->first(fn ($l) => in_array($l->new_value['status'] ?? null, ['waiting_verification', 'payment_submitted', 'payment_pending'], true)
                && (int) $l->user_id === (int) $leader->id);
            $members['team_leader'] = [
                'slot' => 'team_leader', 'name' => (string) ($leader->full_name ?: $leader->name), 'personnel_id' => null, 'user_id' => $leader->id,
                'role' => 'team_leader', 'borrowed_from' => null, 'uncertain' => false, 'reason' => null,
                'evidence' => 'Assigned to this booking by Dispatch' . ($completer ? '; completed the job from their own account' : ''),
                'completed_by_self' => (bool) $completer,
            ];
        } else {
            $notes[] = 'Dispatch did not record a Team Leader on this booking.';
        }

        if (! $start) {
            $notes[] = 'The start of this job is not in the records, so the unit crew at that time cannot be established.';

            return $this->result($booking, $unit, null, $members, $notes);
        }

        [$roster, $broken, $changedDuring] = $this->rosterAt($unit, $start, $end);
        $covered = $this->auditStart() !== null && $this->auditStart()->lessThanOrEqualTo($start);
        if (! $covered) {
            $notes[] = 'This job is older than the fleet history (it starts ' . ($this->auditStart()?->format('M j, Y') ?? 'later') . '), so the unit crew cannot be verified.';
        }
        $loans = UnitCrewLoan::where('to_unit_id', $unit->id)->where('borrowed_at', '<=', $start)
            ->where(fn ($q) => $q->whereNull('returned_at')->orWhere('returned_at', '>', $start))->get()->keyBy('to_slot');

        foreach (Unit::SLOT_COLUMNS as $slot => $col) {
            $name = trim((string) ($roster[$slot]['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $reasons = [];
            if (! $covered) {
                $reasons[] = 'older than the fleet history';
            }
            if (isset($broken[$slot])) {
                $reasons[] = 'the fleet history for this position has a gap';
            }
            if (isset($changedDuring[$slot])) {
                $reasons[] = 'this position changed while the job was running';
            }
            $loan = $loans->get($slot);
            $members[$slot] = [
                'slot' => $slot, 'name' => $name, 'personnel_id' => $roster[$slot]['personnel_id'] ?: null, 'user_id' => null,
                'role' => self::SLOT_ROLES[$slot], 'borrowed_from' => $loan && (int) $loan->from_unit_id !== (int) $unit->id ? (int) $loan->from_unit_id : null,
                'uncertain' => $reasons !== [], 'reason' => $reasons ? ucfirst(implode('; ', $reasons)) . '.' : null,
                'evidence' => "On {$unit->name}'s roster when the job started",
                'completed_by_self' => false,
            ];
        }
        if (count($members) <= 1 && $covered) {
            $notes[] = "No Driver or Crew Member was on {$unit->name}'s roster when the job started.";
        }

        return $this->result($booking, $unit, $start, $members, $notes);
    }

    // ------------------------------------------------------------------ internals

    private function result(Booking $booking, Unit $unit, ?CarbonImmutable $start, array $members, array $notes): array
    {
        return ['unit_id' => $unit->id, 'unit_name' => $unit->name, 'booking_id' => $booking->id, 'as_of' => $start, 'members' => $members, 'notes' => $notes];
    }

    /** When work started: the last "accepted" / "on the way" before completion, else the last unit assignment. */
    private function startOfJob(Booking $booking, Collection $logs, ?CarbonImmutable $end): ?CarbonImmutable
    {
        $before = fn ($l) => $end === null || $this->at($l->created_at)->lessThanOrEqualTo($end);
        $started = $logs->filter($before)->filter(fn ($l) => in_array($l->new_value['status'] ?? null, self::START_STATUSES, true))->last();
        if ($started) {
            return $this->at($started->created_at);
        }
        $assigned = $logs->filter($before)->filter(fn ($l) => array_key_exists('assigned_unit_id', (array) $l->new_value) && (int) ($l->new_value['assigned_unit_id'] ?? 0) === (int) $booking->assigned_unit_id)->last();

        return $assigned ? $this->at($assigned->created_at) : $this->at($booking->assigned_at);
    }

    /**
     * Roster at $at, rebuilt from today's values by undoing every logged change after $at (newest first).
     *
     * @return array{0: array<string, array{name:?string, personnel_id:?int}>, 1: array<string, true>, 2: array<string, true>}
     */
    private function rosterAt(Unit $unit, CarbonImmutable $at, ?CarbonImmutable $end): array
    {
        $state = [];
        foreach (Unit::SLOT_COLUMNS as $slot => $nameCol) {
            $state[$slot] = ['name' => $unit->{$nameCol}, 'personnel_id' => $unit->{Unit::SLOT_PERSONNEL_COLUMNS[$slot]} ? (int) $unit->{Unit::SLOT_PERSONNEL_COLUMNS[$slot]} : null];
        }
        $broken = [];
        $changedDuring = [];

        $verified = [];
        foreach ($this->logsFor($unit->id)->reverse() as $log) {
            $when = $this->at($log->created_at);
            if ($when->lessThanOrEqualTo($at)) {
                // Before the job: the last logged value of each position must equal what the chain rebuilt, or a
                // change was made without a log in between (a gap).
                foreach (Unit::SLOT_COLUMNS as $slot => $nameCol) {
                    $pidCol = Unit::SLOT_PERSONNEL_COLUMNS[$slot];
                    $new = (array) $log->new_value;
                    if (isset($verified[$slot]) || (! array_key_exists($nameCol, $new) && ! array_key_exists($pidCol, $new))) {
                        continue;
                    }
                    $verified[$slot] = true;
                    if (! $this->matches($new, $state[$slot], $nameCol, $pidCol)) {
                        $broken[$slot] = true;
                    }
                }
                if (count($verified) === count(Unit::SLOT_COLUMNS)) {
                    break;
                }
                continue;
            }
            $new = (array) $log->new_value;
            $old = (array) $log->old_value;
            foreach (Unit::SLOT_COLUMNS as $slot => $nameCol) {
                $pidCol = Unit::SLOT_PERSONNEL_COLUMNS[$slot];
                if (! array_key_exists($nameCol, $new) && ! array_key_exists($pidCol, $new)) {
                    continue;
                }
                // The chain must agree: the logged new value is what the slot held right after this change.
                if (! $this->matches($new, $state[$slot], $nameCol, $pidCol)) {
                    $broken[$slot] = true;
                }
                // Undo it. A column missing from the old values was unset on a just-created unit, i.e. empty.
                if (array_key_exists($nameCol, $new)) {
                    $state[$slot]['name'] = $old[$nameCol] ?? null;
                }
                if (array_key_exists($pidCol, $new)) {
                    $state[$slot]['personnel_id'] = isset($old[$pidCol]) ? (int) $old[$pidCol] : null;
                }
                if ($end !== null && $when->lessThanOrEqualTo($end)) {
                    $changedDuring[$slot] = true;
                }
            }
        }

        return [$state, $broken, $changedDuring];
    }

    /** A logged value agrees with the rebuilt one: same personnel id when either has one, else the same name. */
    private function matches(array $logged, array $state, string $nameCol, string $pidCol): bool
    {
        if (array_key_exists($pidCol, $logged) && ($logged[$pidCol] !== null || $state['personnel_id'] !== null)) {
            return (int) ($logged[$pidCol] ?? 0) === (int) ($state['personnel_id'] ?? 0);
        }
        if (array_key_exists($nameCol, $logged)) {
            return mb_strtolower(trim((string) $logged[$nameCol])) === mb_strtolower(trim((string) $state['name']));
        }

        return true;
    }

    private function logsFor(int $unitId): Collection
    {
        return $this->unitLogs[$unitId] ??= AuditLog::where('entity_type', 'Unit')->where('entity_id', $unitId)->orderBy('created_at')->orderBy('id')
            ->get(['id', 'created_at', 'old_value', 'new_value']);
    }

    private function auditStart(): ?CarbonImmutable
    {
        if (! $this->auditStartLoaded) {
            $this->auditStartLoaded = true;
            $first = AuditLog::min('created_at');
            $this->auditStart = $first ? $this->at($first) : null;
        }

        return $this->auditStart;
    }

    private function at($value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value) : null;
    }
}
