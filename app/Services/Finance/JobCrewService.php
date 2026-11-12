<?php

namespace App\Services\Finance;

use App\Models\Booking;
use App\Models\Finance\CommissionSettlement;
use App\Models\Finance\JobPersonnel;
use App\Models\Personnel;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only: records who ACTUALLY worked each completed job, for which towing unit and in which performed role.
 *
 * Nothing here is automatic. Booking assignment fields, crew loans and a unit's roster are shown only as supporting
 * information; a participant exists only when the Owner records them. Rows are never edited: a correction or removal
 * supersedes the row with a reason, so the history of who was credited stays visible.
 *
 * The performed role is always chosen explicitly. Whether a Rider, substitute, shared role or combined role is paid,
 * and how, stays with the client (CommissionPolicy::CONFIRMABLE_RULES); the calculator blocks approval until then.
 */
class JobCrewService extends FinanceSupport
{
    public function __construct(private readonly JobCollectionService $collections)
    {
    }

    /**
     * @param  array{unit_id:int|string, participant:string, commission_role:string, is_substitute?:bool, borrowed_from_unit_id?:int|string|null, notes?:?string}  $data
     *         participant is "personnel:{id}" or "user:{id}" (a Team Leader account)
     */
    public function record(User $actor, Booking $booking, array $data): JobPersonnel
    {
        $this->authorize($actor, FinanceAccess::MANAGE_CREW);

        return DB::transaction(function () use ($actor, $booking, $data) {
            $job = $this->lockedJob($booking);
            $row = $this->insertRow($actor, $job, $data, null);

            $this->audit($actor, 'finance_crew_recorded', $row, $job['reference'],
                "Recorded {$row->name_snapshot} as {$row->roleLabel()} on job {$job['reference']} ({$row->unit?->name})",
                $this->auditValues($row));

            return $row;
        });
    }

    /** Replaces a participant record (different person, role, unit or substitute details) with a recorded reason. */
    public function correct(User $actor, JobPersonnel $entry, array $data, string $reason): JobPersonnel
    {
        $this->authorize($actor, FinanceAccess::MANAGE_CREW);
        $reason = $this->requireReason($reason, 'correct');

        return DB::transaction(function () use ($actor, $entry, $data, $reason) {
            $old = $this->lockedCurrent($entry);
            $job = $this->lockedJob($old->booking);

            $this->supersede($actor, $old, $reason);
            $new = $this->insertRow($actor, $job, $data, $reason);
            $old->forceFill(['superseded_by_id' => $new->id])->save();

            $this->audit($actor, 'finance_crew_corrected', $new, $job['reference'],
                "Corrected crew record on job {$job['reference']}: {$old->name_snapshot} ({$old->roleLabel()}) → {$new->name_snapshot} ({$new->roleLabel()}). Reason: {$reason}",
                ['replaces_id' => $old->id] + $this->auditValues($new));

            return $new;
        });
    }

    /** Removes a participant who did not actually work the job. The row stays, marked superseded with the reason. */
    public function remove(User $actor, JobPersonnel $entry, string $reason): JobPersonnel
    {
        $this->authorize($actor, FinanceAccess::MANAGE_CREW);
        $reason = $this->requireReason($reason, 'remove');

        return DB::transaction(function () use ($actor, $entry, $reason) {
            $old = $this->lockedCurrent($entry);
            $this->supersede($actor, $old, $reason);

            $ref = $old->booking?->booking_code ?? "#{$old->booking_id}";
            $this->audit($actor, 'finance_crew_removed', $old, $ref,
                "Removed {$old->name_snapshot} ({$old->roleLabel()}) from job {$ref}. Reason: {$reason}");

            return $old;
        });
    }

    /**
     * Records the crew rebuilt from Dispatch and fleet history (CrewHistoryService) for one unit of a completed job,
     * as confirmed by the Owner. Only the people the Owner ticked are recorded; the evidence is recomputed here, so
     * nothing posted by the browser is trusted except the selection and the second Driver's role.
     *
     * @param  array<int, string>  $slots  slots to record (team_leader, driver_1, driver_2, crew_member_1, crew_member_2)
     * @return array{recorded: array<int, JobPersonnel>, skipped: array<int, string>}
     */
    public function recordFromHistory(User $actor, Booking $booking, int $unitId, array $slots, ?string $secondDriverRole = null): array
    {
        $this->authorize($actor, FinanceAccess::MANAGE_CREW);
        if ($slots === []) {
            throw new FinanceException('Tick at least one person who worked this job.');
        }

        return DB::transaction(function () use ($actor, $booking, $unitId, $slots, $secondDriverRole) {
            $job = $this->lockedJob($booking);
            $evidence = app(CrewHistoryService::class)->forJob($job)[$unitId] ?? null;
            if (! $evidence) {
                throw new FinanceException('No crew was found in the Dispatch history for this unit.');
            }
            $recorded = [];
            $skipped = [];
            foreach ($evidence['members'] as $slot => $m) {
                if (! in_array($slot, $slots, true)) {
                    continue;
                }
                $role = $slot === 'driver_2' ? $secondDriverRole : $m['role'];
                if (! in_array($role, ['driver', 'rider', 'team_leader', 'crew_mate'], true)) {
                    throw new FinanceException("Choose the role {$m['name']} performed before confirming.");
                }
                if (! $m['personnel_id'] && ! $m['user_id']) {
                    $skipped[] = "{$m['name']} has no personnel record; add them manually.";
                    continue;
                }
                $participant = $m['user_id'] ? "user:{$m['user_id']}" : "personnel:{$m['personnel_id']}";
                $current = JobPersonnel::current()->whereIn('booking_id', collect($job['members'])->pluck('id'))->where('unit_id', $unitId)->get();
                if ($current->contains(fn (JobPersonnel $p) => ($m['personnel_id'] && $p->personnel_id === $m['personnel_id']) || ($m['user_id'] && $p->user_id === $m['user_id']))) {
                    $skipped[] = "{$m['name']} is already recorded on this job.";
                    continue;
                }
                $row = $this->insertRow($actor, $job, [
                    'unit_id' => $unitId, 'participant' => $participant, 'commission_role' => $role,
                    'borrowed_from_unit_id' => $m['borrowed_from'],
                    'notes' => 'From Dispatch and fleet history (' . ($evidence['as_of']?->format('M j, Y g:i A') ?? 'job record') . '): ' . $m['evidence'] . '. Confirmed by the Owner.',
                ], null, 'backfill');
                $this->audit($actor, 'finance_crew_recorded', $row, $job['reference'],
                    "Confirmed {$row->name_snapshot} as {$row->roleLabel()} on job {$job['reference']} ({$row->unit?->name}) from Dispatch history",
                    $this->auditValues($row) + ['evidence' => $m['evidence'], 'as_of' => $evidence['as_of']?->toIso8601String(), 'uncertain' => $m['uncertain']]);
                $recorded[] = $row;
            }

            return ['recorded' => $recorded, 'skipped' => $skipped];
        });
    }

    /**
     * Confirms every pending crew record of one unit on a job in one action. Records without a role are left
     * pending (the Owner chooses the role with Correct).
     *
     * @return array{confirmed: int, needs_role: array<int, string>}
     */
    public function confirmPending(User $actor, Booking $booking, int $unitId): array
    {
        $this->authorize($actor, FinanceAccess::MANAGE_CREW);
        $job = $this->collections->jobFor($booking);
        $pending = JobPersonnel::current()->whereIn('booking_id', collect($job['members'])->pluck('id'))->where('unit_id', $unitId)
            ->where('needs_review', true)->whereNull('confirmed_at')->orderBy('id')->get();
        $confirmed = 0;
        $needsRole = [];
        foreach ($pending as $row) {
            if (! $row->commission_role) {
                $needsRole[] = $row->name_snapshot;
                continue;
            }
            $this->confirm($actor, $row);
            $confirmed++;
        }

        return ['confirmed' => $confirmed, 'needs_role' => $needsRole];
    }

    /** Confirms a record that came from a capture or backfill and was flagged for review. */
    public function confirm(User $actor, JobPersonnel $entry): JobPersonnel
    {
        $this->authorize($actor, FinanceAccess::MANAGE_CREW);

        return DB::transaction(function () use ($actor, $entry) {
            $row = $this->lockedCurrent($entry);
            if ($row->confirmed_at !== null) {
                throw new FinanceException('This crew record is already confirmed.');
            }
            if (! $row->commission_role) {
                throw new FinanceException('Choose the role this person performed (use Correct) before confirming.');
            }

            $row->forceFill(['needs_review' => false, 'confirmed_by' => $actor->id, 'confirmed_at' => now()])->save();

            $ref = $row->booking?->booking_code ?? "#{$row->booking_id}";
            $this->audit($actor, 'finance_crew_confirmed', $row, $ref, "Confirmed {$row->name_snapshot} as {$row->roleLabel()} on job {$ref}");

            return $row;
        });
    }

    // ------------------------------------------------------------------ read side

    /** Units a participant can be recorded under for this job: its assigned units and any unit it is allocated to. */
    public function jobUnitIds(array $job): array
    {
        return collect($job['units'])->pluck('unit_id')
            ->merge(array_keys($job['allocation']['by_unit']))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Current and superseded crew records for the job, grouped by unit id (0 = no unit). */
    public function records(array $job): Collection
    {
        return JobPersonnel::whereIn('booking_id', collect($job['members'])->pluck('id'))
            ->with(['unit:id,name', 'borrowedFrom:id,name', 'capturer', 'supersededByUser'])
            ->orderBy('id')->get();
    }

    /**
     * Supporting information only, never treated as a record of who worked: the booking's own assignment fields,
     * crew loans active on the service date, and each unit's CURRENT roster (clearly labelled as today's).
     */
    public function supportingInfo(array $job): array
    {
        $info = [];
        foreach ($job['members'] as $m) {
            if ($m->assignedTeamLeader) {
                $info[] = ['source' => "Booking {$m->booking_code}: assigned Team Leader", 'name' => $m->assignedTeamLeader->full_name ?: $m->assignedTeamLeader->name, 'participant' => "user:{$m->assigned_team_leader_id}"];
            }
            if (filled($m->driver_name)) {
                $info[] = ['source' => "Booking {$m->booking_code}: driver name on the booking", 'name' => $m->driver_name, 'participant' => null];
            }
        }

        $date = $job['service_date'];
        $unitIds = $this->jobUnitIds($job);
        if ($date && $unitIds !== []) {
            UnitCrewLoan::whereIn('to_unit_id', $unitIds)
                ->where('borrowed_at', '<=', $date->endOfDay())
                ->where(fn ($q) => $q->whereNull('returned_at')->orWhere('returned_at', '>=', $date->startOfDay()))
                ->get()
                ->each(function ($loan) use (&$info) {
                    $info[] = ['source' => 'Crew loan on the service date (' . str_replace('_', ' ', (string) ($loan->to_slot ?? $loan->slot)) . ')', 'name' => $loan->person_name,
                        'participant' => $loan->personnel_id ? "personnel:{$loan->personnel_id}" : null];
                });
        }

        $roster = [];
        foreach (Unit::whereIn('id', $unitIds)->get() as $unit) {
            foreach (['driver_name' => 'driver_personnel_id', 'driver_2_name' => 'driver_2_personnel_id', 'crew_member_1_name' => 'crew_member_1_personnel_id', 'crew_member_2_name' => 'crew_member_2_personnel_id'] as $nameCol => $idCol) {
                if (filled($unit->{$nameCol})) {
                    $roster[] = ['unit' => $unit->name, 'name' => $unit->{$nameCol}, 'participant' => $unit->{$idCol} ? "personnel:{$unit->{$idCol}}" : null];
                }
            }
        }

        return ['booking' => $info, 'current_roster' => $roster];
    }

    /** People who can be recorded: active personnel and active Team Leader accounts. */
    public function participantOptions(): array
    {
        $options = [];
        foreach (Personnel::active()->orderBy('first_name')->orderBy('last_name')->get() as $p) {
            $options["personnel:{$p->id}"] = $p->full_name . ' (' . ucfirst(str_replace('_', ' ', (string) $p->role)) . ')';
        }
        foreach (User::where('role_id', FinanceAccess::ROLE_TEAM_LEADER)->whereNull('archived_at')->orderBy('name')->get() as $u) {
            $options["user:{$u->id}"] = ($u->full_name ?: $u->name) . ' (Team Leader account)';
        }

        return $options;
    }

    /** Active approved settlements for this job, so the screen can warn that approved statements will not change. */
    public function settledStatements(array $job): Collection
    {
        return CommissionSettlement::active()->whereIn('booking_id', collect($job['members'])->pluck('id'))
            ->with('statement:id,reference_code,status')->get()->pluck('statement')->unique('id')->values();
    }

    // ------------------------------------------------------------------ internals

    private function lockedJob(Booking $booking): array
    {
        Booking::whereKey($booking->id)->lockForUpdate()->first();
        $job = $this->collections->jobFor($booking);
        if (! $job['service_completed']) {
            throw new FinanceException("Crew can only be recorded for a completed job; job {$job['reference']} is not finished.");
        }
        // Serialise crew edits for this job.
        Booking::whereIn('id', collect($job['members'])->pluck('id'))->orderBy('id')->lockForUpdate()->get(['id']);

        return $job;
    }

    private function lockedCurrent(JobPersonnel $entry): JobPersonnel
    {
        $row = JobPersonnel::whereKey($entry->id)->lockForUpdate()->firstOrFail();
        if ($row->superseded_at !== null) {
            throw new FinanceException('This crew record was already corrected or removed. Refresh the page.');
        }

        return $row;
    }

    private function supersede(User $actor, JobPersonnel $row, string $reason): void
    {
        $row->forceFill([
            'superseded_at' => now(),
            'superseded_by_user_id' => $actor->id,
            'supersede_reason' => $reason,
        ])->save();
    }

    private function insertRow(User $actor, array $job, array $data, ?string $changeReason, string $source = 'manual'): JobPersonnel
    {
        $role = (string) ($data['commission_role'] ?? '');
        if (! isset(JobPersonnel::ROLE_LABELS[$role])) {
            throw new FinanceException('Choose the role this person actually performed.');
        }

        $unitId = (int) ($data['unit_id'] ?? 0);
        if (! in_array($unitId, $this->jobUnitIds($job), true)) {
            throw new FinanceException("Choose a towing unit that worked job {$job['reference']}.");
        }

        [$personnelId, $userId, $name] = $this->participant((string) ($data['participant'] ?? ''));

        $borrowedFrom = filled($data['borrowed_from_unit_id'] ?? null) ? (int) $data['borrowed_from_unit_id'] : null;
        if ($borrowedFrom !== null) {
            if ($borrowedFrom === $unitId) {
                throw new FinanceException('A person borrowed from another unit must come from a different unit.');
            }
            if (! Unit::whereKey($borrowedFrom)->exists()) {
                throw new FinanceException('Choose a valid unit the person was borrowed from.');
            }
        }

        $anchorId = $job['anchor']->id;
        $current = JobPersonnel::current()->whereIn('booking_id', collect($job['members'])->pluck('id'))->where('unit_id', $unitId)->get();

        $duplicate = $current->first(fn (JobPersonnel $p) => $p->commission_role === $role
            && (($personnelId && $p->personnel_id === $personnelId) || ($userId && $p->user_id === $userId)));
        if ($duplicate) {
            throw new FinanceException("{$name} is already recorded as " . JobPersonnel::ROLE_LABELS[$role] . ' on this job and unit.' . ($duplicate->needs_review ? ' Confirm the pending record instead.' : ''));
        }

        $taken = $current->where('booking_id', $anchorId)->pluck('slot')->all();
        $slot = collect(JobPersonnel::ROLE_SLOTS[$role])->first(fn ($s) => ! in_array($s, $taken, true));
        if ($slot === null) {
            throw new FinanceException('No more participants can be recorded for this unit on this job. Remove or correct an existing record first.');
        }

        return JobPersonnel::create([
            'booking_id' => $anchorId,
            'unit_id' => $unitId,
            'slot' => $slot,
            'personnel_id' => $personnelId,
            'user_id' => $userId,
            'name_snapshot' => $name,
            'commission_role' => $role,
            'borrowed_from_unit_id' => $borrowedFrom,
            'is_substitute' => (bool) ($data['is_substitute'] ?? false) || $borrowedFrom !== null,
            'notes' => filled($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 500) : null,
            'change_reason' => $changeReason,
            'source' => $source,
            'needs_review' => false,
            'captured_by' => $actor->id,
            'captured_at' => now(),
            'confirmed_by' => $actor->id,     // the Owner recording it is the confirmation
            'confirmed_at' => now(),
        ])->load('unit:id,name');
    }

    /** @return array{0:?int,1:?int,2:string} personnel id, user id, name snapshot */
    private function participant(string $key): array
    {
        if (preg_match('/^personnel:(\d+)$/', $key, $m)) {
            $person = Personnel::find((int) $m[1]);
            if (! $person) {
                throw new FinanceException('Choose a valid person.');
            }

            return [$person->id, null, $person->full_name];
        }
        if (preg_match('/^user:(\d+)$/', $key, $m)) {
            $user = User::find((int) $m[1]);
            if (! $user || (int) $user->role_id !== FinanceAccess::ROLE_TEAM_LEADER) {
                throw new FinanceException('Only Team Leader accounts can be recorded directly. Choose a personnel record for other crew.');
            }

            return [null, $user->id, (string) ($user->full_name ?: $user->name)];
        }

        throw new FinanceException('Choose the person who actually worked the job.');
    }

    private function requireReason(string $reason, string $verb): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new FinanceException("A reason (at least 5 characters) is required to {$verb} a crew record.");
        }

        return mb_substr($reason, 0, 500);
    }

    private function auditValues(JobPersonnel $row): array
    {
        return [
            'booking_id' => $row->booking_id, 'unit_id' => $row->unit_id, 'commission_role' => $row->commission_role,
            'personnel_id' => $row->personnel_id, 'user_id' => $row->user_id, 'is_substitute' => $row->is_substitute,
            'borrowed_from_unit_id' => $row->borrowed_from_unit_id,
        ];
    }

    /** Monday of the week containing $date (the commission reporting week). */
    public static function weekOf(CarbonImmutable $date): CarbonImmutable
    {
        return $date->setTimezone(config('finance.timezone'))->startOfWeek(CarbonImmutable::MONDAY);
    }
}
