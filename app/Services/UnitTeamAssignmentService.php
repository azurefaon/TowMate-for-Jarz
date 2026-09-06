<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single place that mutates a Unit's Team Leader/Driver/Crew roster.
 * Every write here goes through a DB transaction with row locks and a fresh
 * re-check of every guard immediately before commit — never trusts a
 * disabled button or a client-side check alone.
 *
 * Reuses the existing UnitCrewLoan model for Driver/Crew AND Team Leader
 * borrowing, so there is exactly one loan/transfer mechanism, not two.
 */
class UnitTeamAssignmentService
{
    public function __construct(protected UnitAvailabilityService $availability)
    {
    }

    /**
     * Assign a Team Leader into an empty team_leader slot. If that Team
     * Leader currently belongs to another Unit, this is a borrow: the
     * source Unit's slot is cleared and a UnitCrewLoan records the original
     * home Unit so Return can restore it later.
     */
    public function assignTeamLeader(Unit $targetUnit, int $teamLeaderId, User $actor): Unit
    {
        return DB::transaction(function () use ($targetUnit, $teamLeaderId, $actor) {
            $target = Unit::lockForUpdate()->findOrFail($targetUnit->id);

            if ($target->team_leader_id) {
                throw new RuntimeException('This unit already has a Team Leader assigned. Remove/return the current one first.');
            }

            $this->assertUnitTeamIsMovable($target, 'assign a Team Leader to');

            $teamLeader = User::where('role_id', 3)
                ->whereNull('archived_at')
                ->whereNull('anonymized_at')
                ->find($teamLeaderId);

            if (! $teamLeader) {
                throw new RuntimeException('Selected Team Leader is invalid, archived, or deleted.');
            }

            if ($teamLeader->dutyStatus() === 'unavailable') {
                throw new RuntimeException('This Team Leader is marked Unavailable for duty today.');
            }

            // Presence (online/offline) is deliberately NOT checked here — it
            // must never gate eligibility. Only genuine Workload (an active
            // booking) blocks a reassignment.
            if ($this->isBusy($teamLeader)) {
                throw new RuntimeException('This Team Leader currently has an active job and cannot be reassigned.');
            }

            $sourceUnit = Unit::lockForUpdate()->where('team_leader_id', $teamLeader->id)->first();

            if ($sourceUnit) {
                $this->assertUnitTeamIsMovable($sourceUnit, 'borrow the Team Leader from');
            }

            // Belt-and-suspenders: the DB's own partial unique index on
            // units.team_leader_id is the hard backstop if two requests
            // somehow both reach this point for the same leader.
            try {
                if ($sourceUnit) {
                    $sourceUnit->update(['team_leader_id' => null]);
                }

                $target->update(['team_leader_id' => $teamLeader->id]);
            } catch (QueryException $e) {
                if ((string) $e->getCode() === '23505') {
                    throw new RuntimeException('This team leader was just assigned elsewhere. Please choose another.');
                }

                throw $e;
            }

            if ($sourceUnit) {
                UnitCrewLoan::create([
                    'from_unit_id' => $sourceUnit->id,
                    'to_unit_id' => $target->id,
                    'from_slot' => 'team_leader',
                    'to_slot' => 'team_leader',
                    'person_name' => $teamLeader->full_name ?? $teamLeader->name,
                    'person_user_id' => $teamLeader->id,
                    'borrowed_at' => now(),
                    'created_by' => $actor->id,
                ]);
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'team_leader_assigned',
                'entity_type' => 'Unit',
                'entity_id' => $target->id,
                'reference' => $target->name,
                'description' => $sourceUnit
                    ? "{$teamLeader->full_name} borrowed from {$sourceUnit->name} to {$target->name}."
                    : "{$teamLeader->full_name} assigned to {$target->name}.",
            ]);

            return $target->fresh();
        });
    }

    /**
     * Return a borrowed Team Leader to their recorded home Unit.
     */
    public function returnTeamLeader(Unit $currentUnit, User $actor): Unit
    {
        return DB::transaction(function () use ($currentUnit, $actor) {
            $current = Unit::lockForUpdate()->findOrFail($currentUnit->id);

            $loan = UnitCrewLoan::where('to_unit_id', $current->id)
                ->where('to_slot', 'team_leader')
                ->whereNull('returned_at')
                ->latest('borrowed_at')
                ->first();

            if (! $loan) {
                throw new RuntimeException('This Team Leader is not a borrowed assignment.');
            }

            $this->assertUnitTeamIsMovable($current, 'return the Team Leader from');

            $homeUnit = Unit::lockForUpdate()->findOrFail($loan->from_unit_id);

            $this->assertUnitTeamIsMovable($homeUnit, 'return the Team Leader to');

            if ($homeUnit->team_leader_id) {
                throw new RuntimeException('The home unit already has a different Team Leader assigned — resolve that first.');
            }

            $current->update(['team_leader_id' => null]);
            $homeUnit->update(['team_leader_id' => $loan->person_user_id]);
            $loan->update(['returned_at' => now()]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'team_leader_returned',
                'entity_type' => 'Unit',
                'entity_id' => $homeUnit->id,
                'reference' => $loan->person_name,
                'description' => "{$loan->person_name} returned from {$current->name} to {$homeUnit->name}.",
            ]);

            return $current->fresh();
        });
    }

    /**
     * Assign a Driver/Crew person into an empty slot, borrowing them from
     * another Unit's filled slot of the same category (driver <-> driver,
     * crew <-> crew) — generalizes the pre-existing borrowCrew() logic.
     */
    public function assignSlotPerson(Unit $targetUnit, string $toSlot, Unit $sourceUnitRef, string $fromSlot, User $actor): Unit
    {
        return DB::transaction(function () use ($targetUnit, $toSlot, $sourceUnitRef, $fromSlot, $actor) {
            $target = Unit::lockForUpdate()->findOrFail($targetUnit->id);
            $source = Unit::lockForUpdate()->findOrFail($sourceUnitRef->id);

            if ($source->id === $target->id) {
                throw new RuntimeException('Cannot assign crew from the same unit.');
            }

            $slotType = fn (string $slot) => str_starts_with($slot, 'driver_') ? 'driver' : 'crew_member';
            if ($slotType($fromSlot) !== $slotType($toSlot)) {
                throw new RuntimeException('You can only assign a Driver into a Driver slot, or a Crew Member into a Crew Member slot.');
            }

            $fromColumn = Unit::SLOT_COLUMNS[$fromSlot] ?? null;
            $toColumn = Unit::SLOT_COLUMNS[$toSlot] ?? null;
            if (! $fromColumn || ! $toColumn) {
                throw new RuntimeException('Invalid slot.');
            }

            $this->assertUnitTeamIsMovable($target, 'assign a person to');

            if ($toSlot === 'driver_1' && $target->driver_id) {
                throw new RuntimeException('Driver 1 already has a linked account assigned. Unassign it first before assigning.');
            }
            if ($fromSlot === 'driver_1' && $source->driver_id) {
                throw new RuntimeException('That unit\'s Driver 1 is a linked account, not a free-text name, and cannot be assigned.');
            }
            if (filled($target->{$toColumn})) {
                throw new RuntimeException('That slot is already filled. Clear it first before assigning.');
            }
            if (blank($source->{$fromColumn})) {
                throw new RuntimeException('The selected source slot is empty.');
            }
            if ($source->activeLoanOut($fromSlot)) {
                throw new RuntimeException('That person is already on transfer to another unit.');
            }
            if ($source->activeLoansIn()->has($fromSlot)) {
                throw new RuntimeException('This person is already on loan from another unit and cannot be assigned again.');
            }

            $this->assertUnitTeamIsMovable($source, 'borrow this person from');

            $personName = $source->{$fromColumn};

            $source->update([$fromColumn => null]);
            $target->update([$toColumn => $personName]);

            UnitCrewLoan::create([
                'from_unit_id' => $source->id,
                'to_unit_id' => $target->id,
                'from_slot' => $fromSlot,
                'to_slot' => $toSlot,
                'person_name' => $personName,
                'borrowed_at' => now(),
                'created_by' => $actor->id,
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'crew_borrowed',
                'entity_type' => 'Unit',
                'entity_id' => $target->id,
                'reference' => $personName,
                'description' => "{$personName} assigned from {$source->name} to {$target->name}.",
            ]);

            return $target->fresh();
        });
    }

    public function returnSlotPerson(UnitCrewLoan $loan, User $actor): Unit
    {
        return DB::transaction(function () use ($loan, $actor) {
            $loan = UnitCrewLoan::lockForUpdate()->findOrFail($loan->id);

            if ($loan->returned_at) {
                throw new RuntimeException('This transfer has already been returned.');
            }
            if ($loan->from_slot === 'team_leader') {
                throw new RuntimeException('Use returnTeamLeader() for Team Leader loans.');
            }

            $toUnit = Unit::lockForUpdate()->findOrFail($loan->to_unit_id);
            $fromUnit = Unit::lockForUpdate()->findOrFail($loan->from_unit_id);

            $this->assertUnitTeamIsMovable($toUnit, 'return this person from');
            $this->assertUnitTeamIsMovable($fromUnit, 'return this person to');

            $fromColumn = Unit::SLOT_COLUMNS[$loan->from_slot];
            $toColumn = Unit::SLOT_COLUMNS[$loan->to_slot];

            $fromUnit->update([$fromColumn => $loan->person_name]);
            $toUnit->update([$toColumn => null]);
            $loan->update(['returned_at' => now()]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'crew_returned',
                'entity_type' => 'Unit',
                'entity_id' => $toUnit->id,
                'reference' => $loan->person_name,
                'description' => "{$loan->person_name} returned from {$toUnit->name} to {$fromUnit->name}.",
            ]);

            return $toUnit->fresh();
        });
    }

    /**
     * Detach a Team Leader who is a REGULAR (non-borrowed) assignment on
     * this Unit — the slot-level counterpart to Return, for when there is
     * no home Unit to send anyone back to. Never touches Driver/Crew (see
     * Unit::boot()) and never touches the Team Leader's Duty/Presence.
     */
    public function removeTeamLeader(Unit $unit, User $actor): Unit
    {
        return DB::transaction(function () use ($unit, $actor) {
            $unit = Unit::lockForUpdate()->findOrFail($unit->id);

            if (! $unit->team_leader_id) {
                throw new RuntimeException('This unit has no Team Leader to remove.');
            }

            $this->assertUnitTeamIsMovable($unit, 'remove the Team Leader from');

            $loan = UnitCrewLoan::where('to_unit_id', $unit->id)
                ->where('to_slot', 'team_leader')
                ->whereNull('returned_at')
                ->exists();

            if ($loan) {
                throw new RuntimeException('This Team Leader is a borrowed assignment — use Return instead of Remove.');
            }

            $teamLeader = User::find($unit->team_leader_id);

            $unit->update(['team_leader_id' => null]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'team_leader_removed',
                'entity_type' => 'Unit',
                'entity_id' => $unit->id,
                'reference' => $unit->name,
                'description' => ($teamLeader?->full_name ?? $teamLeader?->name ?? 'Team Leader') . " removed from {$unit->name}.",
            ]);

            return $unit->fresh();
        });
    }

    /**
     * Detach a Driver/Crew member who is a REGULAR (non-borrowed) assignment
     * on this Unit — the slot-level counterpart to Return. Only ever
     * touches the exact free-text SLOT_COLUMNS this service already owns;
     * a Driver 1 linked account (units.driver_id) predates this loan/borrow
     * model and is intentionally out of scope here, same as assignSlotPerson().
     */
    public function removeSlotPerson(Unit $unit, string $slot, User $actor): Unit
    {
        if (! in_array($slot, ['driver_1', 'crew_member_1', 'crew_member_2'], true)) {
            throw new RuntimeException('Invalid slot.');
        }

        return DB::transaction(function () use ($unit, $slot, $actor) {
            $unit = Unit::lockForUpdate()->findOrFail($unit->id);

            $this->assertUnitTeamIsMovable($unit, 'remove personnel from');

            if ($slot === 'driver_1' && $unit->driver_id) {
                throw new RuntimeException('Driver 1 is a linked account, not a free-text name, and cannot be removed here.');
            }

            $column = Unit::SLOT_COLUMNS[$slot];

            if (blank($unit->{$column})) {
                throw new RuntimeException('This slot is already empty.');
            }

            if ($unit->activeLoansIn()->has($slot)) {
                throw new RuntimeException('This person is a borrowed assignment — use Return instead of Remove.');
            }

            $personName = $unit->{$column};

            $unit->update([$column => null]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'crew_removed',
                'entity_type' => 'Unit',
                'entity_id' => $unit->id,
                'reference' => $personName,
                'description' => "{$personName} removed from {$unit->name}.",
            ]);

            return $unit->fresh();
        });
    }

    /**
     * Moves the whole current roster (Team Leader + Driver + Crew 1 + Crew
     * 2 — only whichever are actually present) from one Unit to another in
     * one atomic action. Implemented as one UnitCrewLoan per moved slot
     * (reusing the same mechanism individual Assign/Borrow uses) rather
     * than inventing a second, parallel "team loan" record — each moved
     * person can still be Returned individually afterward.
     */
    public function transferTeam(Unit $sourceUnit, Unit $targetUnit, User $actor): Unit
    {
        return DB::transaction(function () use ($sourceUnit, $targetUnit, $actor) {
            $source = Unit::lockForUpdate()->findOrFail($sourceUnit->id);
            $target = Unit::lockForUpdate()->findOrFail($targetUnit->id);

            if ($source->id === $target->id) {
                throw new RuntimeException('Source and destination unit must be different.');
            }

            $this->assertUnitTeamIsMovable($source, 'transfer the team from');
            $this->assertUnitTeamIsMovable($target, 'transfer the team to');

            if ($target->team_leader_id || $target->driver_name || $target->crew_member_1_name || $target->crew_member_2_name) {
                throw new RuntimeException('The destination unit already has personnel assigned. Clear it first.');
            }

            $movedAny = false;

            if ($source->team_leader_id) {
                $teamLeader = User::find($source->team_leader_id);
                $teamLeaderId = $source->team_leader_id;

                // Source must be cleared BEFORE target is set — units.team_leader_id
                // has a unique constraint, so both rows briefly holding the same
                // value (if set in the other order) would violate it.
                $source->update(['team_leader_id' => null]);
                $target->update(['team_leader_id' => $teamLeaderId]);

                UnitCrewLoan::create([
                    'from_unit_id' => $source->id,
                    'to_unit_id' => $target->id,
                    'from_slot' => 'team_leader',
                    'to_slot' => 'team_leader',
                    'person_name' => $teamLeader?->full_name ?? $teamLeader?->name ?? 'Team Leader',
                    'person_user_id' => $teamLeaderId,
                    'borrowed_at' => now(),
                    'created_by' => $actor->id,
                ]);
                $movedAny = true;
            }

            foreach (['driver_1' => 'driver_name', 'crew_member_1' => 'crew_member_1_name', 'crew_member_2' => 'crew_member_2_name'] as $slot => $column) {
                if (blank($source->{$column})) {
                    continue;
                }

                // A linked Driver 1 account moves its name only, same as an
                // individual assign would — driver_id itself intentionally
                // stays put (it identifies an account, not a slot).
                $personName = $source->{$column};
                $target->update([$column => $personName]);
                $source->update([$column => null]);

                UnitCrewLoan::create([
                    'from_unit_id' => $source->id,
                    'to_unit_id' => $target->id,
                    'from_slot' => $slot,
                    'to_slot' => $slot,
                    'person_name' => $personName,
                    'borrowed_at' => now(),
                    'created_by' => $actor->id,
                ]);
                $movedAny = true;
            }

            if (! $movedAny) {
                throw new RuntimeException('This unit has no team to transfer.');
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'team_transferred',
                'entity_type' => 'Unit',
                'entity_id' => $target->id,
                'reference' => $target->name,
                'description' => "Whole team transferred from {$source->name} to {$target->name}.",
            ]);

            return $target->fresh();
        });
    }

    public function setTeamLeaderDuty(User $teamLeader, string $status, User $actor): void
    {
        if (! in_array($status, ['available', 'unavailable'], true)) {
            throw new RuntimeException('Invalid duty status.');
        }

        DB::transaction(function () use ($teamLeader, $status, $actor) {
            $teamLeader = User::lockForUpdate()->findOrFail($teamLeader->id);

            // A Team Leader committed to an active job is not free to have
            // Duty toggled either way — flipping it mid-job would contradict
            // the job's already-committed state and produce misleading
            // availability elsewhere. Reuses the exact same canonical busy
            // definition as every other guard in this service (isBusy()) —
            // no separate "busy" concept is introduced for this check.
            if ($this->isBusy($teamLeader)) {
                throw new RuntimeException('This Team Leader is currently assigned to an active job and Duty cannot be changed.');
            }

            $teamLeader->update(['duty_status' => $status]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'team_leader_duty_changed',
                'entity_type' => 'User',
                'entity_id' => $teamLeader->id,
                'reference' => $teamLeader->full_name ?? $teamLeader->name,
                'description' => "Duty set to {$status}.",
            ]);
        });
    }

    public function setSlotDuty(Unit $unit, string $slot, string $status, User $actor): void
    {
        if (! in_array($status, ['available', 'unavailable'], true)) {
            throw new RuntimeException('Invalid duty status.');
        }

        $column = match ($slot) {
            'driver_1' => 'driver_duty_status',
            'crew_member_1' => 'crew_1_duty_status',
            'crew_member_2' => 'crew_2_duty_status',
            default => throw new RuntimeException('Duty is not tracked for this slot.'),
        };

        $unit->update([$column => $status]);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'slot_duty_changed',
            'entity_type' => 'Unit',
            'entity_id' => $unit->id,
            'reference' => $unit->name,
            'description' => "{$slot} duty set to {$status}.",
        ]);
    }

    /**
     * Throws if removing/replacing personnel on this Unit right now would
     * break a committed reservation or an active job — the one guard every
     * team-breaking mutation (borrow-out, return, transfer) re-checks
     * fresh, inside the lock, immediately before commit.
     */
    protected function assertUnitTeamIsMovable(Unit $unit, string $actionDescription): void
    {
        $state = $this->availability->evaluate($unit);

        if ($state['active_booking']) {
            throw new RuntimeException("Cannot {$actionDescription} {$unit->name} — it is committed to an active job ({$state['active_booking']->booking_code}).");
        }

        if ($state['reservation']) {
            throw new RuntimeException("Cannot {$actionDescription} {$unit->name} — it is reserved for booking {$state['reservation']->booking_code}. Team changes are unavailable while a unit is reserved.");
        }
    }

    protected function isBusy(User $teamLeader): bool
    {
        return app(TeamLeaderAvailabilityService::class)->busyTeamLeaderIds()->contains((int) $teamLeader->id);
    }
}
