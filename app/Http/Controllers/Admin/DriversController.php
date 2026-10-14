<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\HomeUnitConflictException;
use App\Http\Controllers\Controller;
use App\Models\Personnel;
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
                    $personnel = $unit->slotPersonnel($slot);
                    if (! $personnel || $personnel->personnel_status !== 'active' || $personnel->role !== $role) {
                        continue;
                    }
                    $name = $personnel->full_name;
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

                    // A person on a unit that is busy with a job is committed
                    // to it and must not be offered for another unit.
                    if ($state['active_booking']) {
                        continue;
                    }

                    $people->push([
                        'name' => $name,
                        'personnel_id' => $personnel->id,
                        'source_unit_id' => $unit->id,
                        'source_unit_name' => $unit->name,
                        'from_slot' => $slot,
                        'duty' => $slot === 'driver_1' ? $unit->driverDutyStatus() : $unit->crewDutyStatus($slot === 'crew_member_1' ? 1 : 2),
                        'eligible' => ! $state['reservation'],
                    ]);
                }
            }

            // Active registered Personnel who are genuinely free — not on any
            // unit slot, on an open loan, or on an active job (by id or name).
            $engaged = $this->teamAssignment->engagedPersonnel();
            $normalize = fn (?string $value) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $value)));

            Personnel::where('role', $role)
                ->where('personnel_status', 'active')
                ->whereNotIn('id', $engaged['ids'])
                ->orderBy('first_name')
                ->get()
                ->reject(fn (Personnel $personnel) => in_array($normalize($personnel->full_name), $engaged['names'], true))
                ->each(function (Personnel $personnel) use ($people) {
                    $people->push([
                        'name' => $personnel->full_name,
                        'personnel_id' => $personnel->id,
                        'source_unit_id' => null,
                        'source_unit_name' => 'Unassigned',
                        'from_slot' => null,
                        'duty' => 'available',
                        'eligible' => true,
                    ]);
                });

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
            return $this->refused($request, $e);
        }

        return $this->ok($request, 'Team Leader assigned.');
    }

    public function returnTeamLeader(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        try {
            $this->teamAssignment->returnTeamLeader($unit, Auth::user());
        } catch (RuntimeException $e) {
            return $this->refused($request, $e);
        }

        return $this->ok($request, 'Team Leader returned.');
    }

    public function assignSlot(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'to_slot' => 'required|string|in:driver_1,crew_member_1,crew_member_2',
            'source_unit_id' => 'nullable|required_without:personnel_id|integer|exists:units,id',
            'from_slot' => 'nullable|required_with:source_unit_id|string|in:driver_1,driver_2,crew_member_1,crew_member_2',
            'personnel_id' => 'nullable|required_without:source_unit_id|integer|exists:personnel,id',
        ]);

        try {
            if (empty($validated['source_unit_id'])) {
                $this->teamAssignment->assignUnassignedPerson($unit, $validated['to_slot'], (int) $validated['personnel_id'], Auth::user());
            } else {
                $sourceUnit = Unit::findOrFail($validated['source_unit_id']);
                $this->teamAssignment->assignSlotPerson($unit, $validated['to_slot'], $sourceUnit, $validated['from_slot'], Auth::user());
            }
        } catch (RuntimeException $e) {
            return $this->refused($request, $e);
        }

        return $this->ok($request, 'Assigned.');
    }

    public function returnSlot(Request $request, UnitCrewLoan $loan): JsonResponse|RedirectResponse
    {
        try {
            $this->teamAssignment->returnSlotPerson($loan, Auth::user());
        } catch (RuntimeException $e) {
            return $this->refused($request, $e);
        }

        return $this->ok($request, 'Returned.');
    }

    public function removeTeamLeader(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        try {
            $this->teamAssignment->removeTeamLeader($unit, Auth::user());
        } catch (RuntimeException $e) {
            return $this->refused($request, $e);
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
            return $this->refused($request, $e);
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
            return $this->refused($request, $e);
        }

        return $this->ok($request, 'Team transferred.');
    }

    public function setTeamLeaderDuty(Request $request, User $teamLeader): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['status' => 'required|in:available,unavailable']);

        try {
            $this->teamAssignment->setTeamLeaderDuty($teamLeader, $validated['status'], Auth::user());
        } catch (RuntimeException $e) {
            return $this->refused($request, $e);
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
            return $this->refused($request, $e);
        }

        return $this->ok($request, 'Duty updated.');
    }

    // ------------------------------------------------------------------
    // Retired legacy actions: drivers.assign-unit, drivers.remove-unit,
    // drivers.update-status and team-leaders.override (routes and methods
    // removed). They changed unit assignment or status with no locks,
    // active-job/reservation guard, maintenance protection or borrow records.
    // Do not reintroduce them; assignments go through the endpoints above
    // (UnitTeamAssignmentService) and maintenance through the Fleet controllers.
    // ------------------------------------------------------------------

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

    /**
     * A team change the service refused (422) or that collided with another
     * change (409, TeamAssignmentConflictException). QueryException is a
     * RuntimeException too: database errors are rethrown, never shown to the
     * dispatcher as a message.
     */
    protected function refused(Request $request, RuntimeException $e): JsonResponse|RedirectResponse
    {
        if ($e instanceof \PDOException) {
            throw $e;
        }

        if ($e instanceof HomeUnitConflictException && $request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->status);
        }

        return $this->fail($request, $e->getMessage());
    }
}
