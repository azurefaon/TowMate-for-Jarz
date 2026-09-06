<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use App\Services\UnitAvailabilityService;
use App\Services\UnitTeamAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class DriversController extends Controller
{
    public function __construct(
        protected TeamLeaderAvailabilityService $teamLeaderAvailability,
        protected UnitAvailabilityService $availability,
        protected UnitTeamAssignmentService $teamAssignment,
    ) {
    }

    /**
     * Units & Leaders — the Dispatcher's daily operational readiness
     * workspace. One row per Unit, built entirely from
     * UnitAvailabilityService::evaluateAll() (the single shared engine also
     * used by Dispatch Queue, Scheduled dispatch, and Customer Book Now) —
     * no separate/competing availability formula lives in this controller
     * or its Blade anymore.
     */
    public function index()
    {
        $rows = $this->availability->evaluateAll()
            ->map(function (array $row) {
                $unit = $row['unit'];
                $unit->loadMissing(['truckType', 'teamLeader']);

                $activeLoanTl = UnitCrewLoan::where('to_unit_id', $unit->id)
                    ->where('to_slot', 'team_leader')
                    ->whereNull('returned_at')
                    ->with('fromUnit:id,name')
                    ->first();

                $activeLoansIn = $unit->crewLoansIn()
                    ->whereNull('returned_at')
                    ->with('fromUnit:id,name')
                    ->get()
                    ->keyBy('to_slot');

                $row['unit'] = $unit;
                $row['team_leader_home_unit'] = $activeLoanTl?->fromUnit?->name;
                $row['driver_loan'] = $activeLoansIn->get('driver_1');
                $row['crew_1_loan'] = $activeLoansIn->get('crew_member_1');
                $row['crew_2_loan'] = $activeLoansIn->get('crew_member_2');

                return $row;
            });

        $eligibleTeamLeaders = User::visibleToOperations()->where('role_id', 3)->orderBy('name')->get();

        return view('admin-dashboard.pages.drivers', [
            'rows' => $rows,
            'eligibleTeamLeaders' => $eligibleTeamLeaders,
        ]);
    }

    /**
     * Eligible people for the Assign search dialog. Presence is returned
     * for display only (Team Leaders) — it never excludes anyone.
     */
    public function eligiblePeople(Request $request): JsonResponse
    {
        $role = $request->query('role');
        $excludeUnitId = (int) $request->query('exclude_unit_id', 0);

        if ($role === 'team_leader') {
            $teamLeaders = User::visibleToOperations()->where('role_id', 3)->with('unit:id,name,team_leader_id')->get();
            $busyIds = $this->teamLeaderAvailability->busyTeamLeaderIds();

            $people = $teamLeaders->map(function (User $tl) use ($busyIds) {
                return [
                    'id' => $tl->id,
                    'name' => $tl->full_name ?? $tl->name,
                    'home_unit' => $tl->unit?->name,
                    'duty' => $tl->dutyStatus(),
                    'workload' => $busyIds->contains((int) $tl->id) ? 'busy' : 'free',
                    'presence' => $this->availability->presence($tl),
                    'eligible' => $tl->dutyStatus() === 'available' && ! $busyIds->contains((int) $tl->id),
                ];
            })->values();

            return response()->json(['people' => $people]);
        }

        if (in_array($role, ['driver', 'crew'], true)) {
            $slots = $role === 'driver' ? ['driver_1'] : ['crew_member_1', 'crew_member_2'];

            $units = Unit::whereNull('archived_at')->get();
            $people = collect();

            foreach ($units as $unit) {
                if ($unit->id === $excludeUnitId) {
                    continue;
                }

                foreach ($slots as $slot) {
                    $column = Unit::SLOT_COLUMNS[$slot];
                    $name = $unit->{$column};
                    if (blank($name)) {
                        continue;
                    }
                    if ($slot === 'driver_1' && $unit->driver_id) {
                        // Linked-account driver — not eligible for a free-text slot move.
                        continue;
                    }
                    if ($unit->activeLoanOut($slot)) {
                        continue;
                    }
                    if ($unit->activeLoansIn()->has($slot)) {
                        continue;
                    }

                    $state = $this->availability->evaluate($unit);

                    $people->push([
                        'name' => $name,
                        'source_unit_id' => $unit->id,
                        'source_unit_name' => $unit->name,
                        'from_slot' => $slot,
                        'duty' => $slot === 'driver_1' ? $unit->driverDutyStatus() : $unit->crewDutyStatus($slot === 'crew_member_1' ? 1 : 2),
                        'eligible' => ! $state['active_booking'] && ! $state['reservation'],
                    ]);
                }
            }

            return response()->json(['people' => $people->values()]);
        }

        return response()->json(['people' => []]);
    }

    public function assignTeamLeader(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['team_leader_id' => 'required|integer|exists:users,id']);

        try {
            $this->teamAssignment->assignTeamLeader($unit, (int) $validated['team_leader_id'], Auth::user());
        } catch (RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'Team Leader assigned.');
    }

    public function returnTeamLeader(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        try {
            $this->teamAssignment->returnTeamLeader($unit, Auth::user());
        } catch (RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'Team Leader returned.');
    }

    public function assignSlot(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'to_slot' => 'required|string|in:driver_1,crew_member_1,crew_member_2',
            'source_unit_id' => 'required|integer|exists:units,id',
            'from_slot' => 'required|string|in:driver_1,driver_2,crew_member_1,crew_member_2',
        ]);

        try {
            $sourceUnit = Unit::findOrFail($validated['source_unit_id']);
            $this->teamAssignment->assignSlotPerson($unit, $validated['to_slot'], $sourceUnit, $validated['from_slot'], Auth::user());
        } catch (RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'Assigned.');
    }

    public function returnSlot(Request $request, UnitCrewLoan $loan): JsonResponse|RedirectResponse
    {
        try {
            $this->teamAssignment->returnSlotPerson($loan, Auth::user());
        } catch (RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'Returned.');
    }

    public function removeTeamLeader(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        try {
            $this->teamAssignment->removeTeamLeader($unit, Auth::user());
        } catch (RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'Team Leader removed.');
    }

    public function removeSlot(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'slot' => 'required|string|in:driver_1,crew_member_1,crew_member_2',
        ]);

        try {
            $this->teamAssignment->removeSlotPerson($unit, $validated['slot'], Auth::user());
        } catch (RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'Removed.');
    }

    public function transferTeam(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['target_unit_id' => 'required|integer|exists:units,id']);

        try {
            $target = Unit::findOrFail($validated['target_unit_id']);
            $this->teamAssignment->transferTeam($unit, $target, Auth::user());
        } catch (RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'Team transferred.');
    }

    public function setTeamLeaderDuty(Request $request, User $teamLeader): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['status' => 'required|in:available,unavailable']);

        try {
            $this->teamAssignment->setTeamLeaderDuty($teamLeader, $validated['status'], Auth::user());
        } catch (RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'Duty updated.');
    }

    public function setSlotDuty(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'slot' => 'required|string|in:driver_1,crew_member_1,crew_member_2',
            'status' => 'required|in:available,unavailable',
        ]);

        try {
            $this->teamAssignment->setSlotDuty($unit, $validated['slot'], $validated['status'], Auth::user());
        } catch (RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'Duty updated.');
    }

    // ------------------------------------------------------------------
    // Legacy actions — no longer linked from the Units & Leaders UI (see
    // assignTeamLeader/returnTeamLeader/assignSlot/transferTeam above, which
    // replace these), but kept in place so the existing routes
    // (drivers.assign-unit, drivers.remove-unit, drivers.update-status,
    // team-leaders.override) and any code/tests still pointed at them keep
    // working rather than 404/500ing outright. Not used by, and safe to
    // eventually retire once nothing references them anymore.
    // ------------------------------------------------------------------

    public function assignUnit(Request $request, User $teamLeader): RedirectResponse|JsonResponse
    {
        abort_unless((int) $teamLeader->role_id === 3, 404);

        $validated = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
        ]);

        if (! $this->teamLeaderAvailability->isOnline($teamLeader)) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => ['unit_id' => 'This team leader is offline. Bring them online before assigning a unit.']], 422);
            }
            return redirect()->route('admin.drivers')->withErrors(['unit_id' => 'This team leader is offline.']);
        }

        $teamLeader->loadMissing('unit');
        $currentDispatcherStatus = $teamLeader->unit?->dispatcher_status;
        if (in_array($currentDispatcherStatus, ['unavailable', 'on_tow', 'on_job'], true)) {
            $label = match ($currentDispatcherStatus) {
                'unavailable' => 'Not Available',
                'on_tow'      => 'On Tow',
                'on_job'      => 'On Job',
            };
            if ($request->expectsJson()) {
                return response()->json(['errors' => ['unit_id' => "Cannot assign a unit while the team leader is set to {$label}."]], 422);
            }
            return redirect()->route('admin.drivers')->withErrors(['unit_id' => "Cannot assign a unit while status is {$label}."]);
        }

        $unit = Unit::query()->with('truckType')->findOrFail($validated['unit_id']);

        if ($unit->status !== 'available') {
            if ($request->expectsJson()) {
                return response()->json([
                    'errors' => ['unit_id' => 'Only available units can be assigned from the dispatcher module.'],
                ], 422);
            }

            return redirect()
                ->route('admin.drivers')
                ->withErrors(['unit_id' => 'Only available units can be assigned from the dispatcher module.']);
        }

        if ($unit->team_leader_id && (int) $unit->team_leader_id !== (int) $teamLeader->id) {
            $ownerName = optional($unit->teamLeader)->full_name
                ?? optional($unit->teamLeader)->name
                ?? 'another team leader';

            if ($request->expectsJson()) {
                return response()->json([
                    'errors' => ['unit_id' => 'This unit is already assigned to ' . $ownerName . '. Release it first before reassigning.'],
                ], 422);
            }

            return redirect()
                ->route('admin.drivers')
                ->withErrors(['unit_id' => 'This unit is already assigned to ' . $ownerName . '. Release it first before reassigning.']);
        }

        Unit::query()
            ->where('team_leader_id', $teamLeader->id)
            ->whereKeyNot($unit->id)
            ->update(['team_leader_id' => null]);

        $unit->update(['team_leader_id' => $teamLeader->id]);

        \App\Models\AuditLog::create([
            'user_id'     => Auth::id(),
            'action'      => 'unit_assigned',
            'entity_type' => 'Unit',
            'entity_id'   => $unit->id,
            'reference'   => $unit->name,
            'description' => 'Assigned to ' . ($teamLeader->full_name ?: $teamLeader->name),
        ]);

        if ($request->expectsJson()) {
            $summary = $this->teamLeaderAvailability->summarize(collect([$teamLeader]));
            $leader = $summary['leaders']->first();
            return response()->json([
                'message' => 'Unit assigned to ' . ($teamLeader->full_name ?: $teamLeader->name) . ' successfully.',
                'assigned_unit' => [
                    'id' => $leader['assigned_unit_id'] ?? null,
                    'name' => $leader['unit_name'] ?? '',
                    'plate_number' => $unit->plate_number ?? '',
                    'driver_name' => $leader['driver_name'] ?? '',
                ],
                'status' => [
                    'label' => strtoupper($leader['unit_status_label'] ?? ''),
                    'class' => 'status-' . ($leader['unit_status'] ?? 'standby'),
                    'subtext' => $leader['status_summary'] ?? '',
                ],
                'team_leader_id' => $teamLeader->id,
            ], 200);
        }

        return redirect()
            ->route('admin.drivers')
            ->with('success', 'Unit assigned to ' . ($teamLeader->full_name ?: $teamLeader->name) . ' successfully.');
    }

    public function updateStatus(Request $request, User $teamLeader): RedirectResponse
    {
        abort_unless((int) $teamLeader->role_id === 3, 404);

        if ($this->teamLeaderAvailability->busyTeamLeaderIds()->contains((int) $teamLeader->id)) {
            return back()->withErrors('Cannot override status while this team leader has an active job.');
        }

        $validated = $request->validate([
            'operational_status' => ['required', 'in:available,busy,unavailable'],
            'unit_status'        => ['nullable', 'in:available,on_job,maintenance'],
            'status_reason'      => ['nullable', 'string', 'max:120'],
        ]);

        $this->teamLeaderAvailability->setOperationalOverride(
            $teamLeader,
            $validated['operational_status'],
            $validated['status_reason'] ?? null
        );

        if ($teamLeader->unit && filled($validated['unit_status'] ?? null)) {
            $teamLeader->unit->update(['status' => $validated['unit_status']]);
        }

        \App\Models\AuditLog::create([
            'user_id'     => Auth::id(),
            'action'      => 'team_leader_status_override',
            'entity_type' => 'User',
            'entity_id'   => $teamLeader->id,
            'reference'   => $teamLeader->full_name ?: $teamLeader->name,
            'description' => 'Operational status set to ' . $validated['operational_status']
                . (filled($validated['status_reason'] ?? null) ? ' — ' . $validated['status_reason'] : ''),
        ]);

        return redirect()
            ->route('admin.drivers')
            ->with('success', 'Operational status updated for ' . ($teamLeader->full_name ?: $teamLeader->name) . '.');
    }

    public function removeUnit(Request $request, User $teamLeader): RedirectResponse|JsonResponse
    {
        abort_unless((int) $teamLeader->role_id === 3, 404);

        $unit = Unit::where('team_leader_id', $teamLeader->id)->first();

        if (! $unit) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => 'No unit assigned to this team leader.'], 422);
            }
            return back()->withErrors('No unit assigned to this team leader.');
        }

        if (in_array($unit->dispatcher_status, ['on_tow', 'on_job'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => 'Cannot remove unit while it is ' . $unit->dispatcher_status . '.'], 422);
            }
            return back()->withErrors('Cannot remove unit while it is active.');
        }

        $unit->update([
            'team_leader_id'    => null,
            'status'            => 'available',
            'dispatcher_status' => null,
            'dispatcher_note'   => null,
            'zone_confirmed'    => false,
            'last_updated_by'   => Auth::user()->name,
            'last_updated_at'   => now(),
        ]);

        \App\Models\AuditLog::create([
            'user_id'     => Auth::id(),
            'action'      => 'unit_removed',
            'entity_type' => 'Unit',
            'entity_id'   => $unit->id,
            'reference'   => $unit->name,
            'description' => 'Removed from ' . ($teamLeader->full_name ?: $teamLeader->name),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message'        => 'Unit removed from ' . ($teamLeader->full_name ?: $teamLeader->name) . '.',
                'unit_released'  => true,
                'team_leader_id' => $teamLeader->id,
            ], 200);
        }

        return back()->with('success', 'Unit removed from ' . ($teamLeader->full_name ?: $teamLeader->name) . '.');
    }

    public function override(Request $request, int $teamLeaderId): RedirectResponse|JsonResponse
    {
        if ($this->teamLeaderAvailability->busyTeamLeaderIds()->contains($teamLeaderId)) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => 'Cannot override status while this team leader has an active job.'], 422);
            }

            return back()->withErrors('Cannot override status while this team leader has an active job.');
        }

        $validated = $request->validate([
            'zone_id'         => ['nullable', 'exists:zones,id'],
            'unit_status'     => ['nullable', 'in:available,unavailable,on_tow,on_job'],
            'zone_confirmed'  => ['boolean'],
            'dispatcher_note' => ['nullable', 'string', 'max:120'],
        ]);

        $unit = Unit::where('team_leader_id', $teamLeaderId)->first();

        $unitStatus = $validated['unit_status'] ?? null;

        if (! $unit) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => 'No unit found for this team leader.'], 422);
            }

            return back()->withErrors('No unit found for this team leader.');
        }

        $unit->update([
            'zone_id'           => $validated['zone_id']       ?? $unit->zone_id,
            'dispatcher_status' => $unitStatus                 ?? $unit->dispatcher_status,
            'zone_confirmed'    => $validated['zone_confirmed'] ?? false,
            'dispatcher_note'   => $validated['dispatcher_note'] ?? null,
            'last_updated_by'   => Auth::user()->name,
            'last_updated_at'   => now(),
        ]);

        \App\Models\AuditLog::create([
            'user_id'     => Auth::id(),
            'action'      => 'unit_status_override',
            'entity_type' => 'Unit',
            'entity_id'   => $unit->id,
            'reference'   => $unit->name,
            'description' => 'Status set to ' . ($unitStatus ?? $unit->dispatcher_status)
                . (filled($validated['dispatcher_note'] ?? null) ? ' — ' . $validated['dispatcher_note'] : ''),
        ]);

        if ($request->expectsJson()) {
            $teamLeader = User::findOrFail($teamLeaderId);
            $summary    = $this->teamLeaderAvailability->summarize(collect([$teamLeader]));
            $leader     = $summary['leaders']->first();
            $unitStatus = $leader['unit_status'] ?? 'standby';

            return response()->json([
                'status' => [
                    'label'   => strtoupper($leader['unit_status_label'] ?? ''),
                    'class'   => 'status-' . $unitStatus,
                    'subtext' => $leader['status_summary'] ?? '',
                ],
                'assigned_unit' => [
                    'id'           => $leader['assigned_unit_id'] ?? null,
                    'name'         => $leader['unit_name'] ?? '',
                    'plate_number' => $leader['plate_number'] ?? '',
                    'driver_name'  => $leader['driver_name'] ?? '',
                ],
                'unit_released'  => false,
                'new_tab'        => $this->effStatusToTab($unitStatus),
                'team_leader_id' => $teamLeaderId,
            ], 200);
        }

        return back()->with('success', 'Team leader status updated.');
    }

    private function effStatusToTab(string $unitStatus): string
    {
        return match ($unitStatus) {
            'on_job', 'on_tow'           => 'on_job',
            'not_avail', 'unavailable'   => 'not_avail',
            'offline'                    => 'offline',
            default                      => 'available',
        };
    }

    protected function ok(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->route('admin.drivers')->with('success', $message);
    }

    protected function fail(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return back()->withErrors(['team' => $message]);
    }
}
