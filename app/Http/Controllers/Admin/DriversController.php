<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DriversController extends Controller
{
    protected TeamLeaderAvailabilityService $teamLeaderAvailability;

    public function __construct(TeamLeaderAvailabilityService $teamLeaderAvailability)
    {
        $this->teamLeaderAvailability = $teamLeaderAvailability;
    }

    public function index()
    {
        $teamLeaders = User::visibleToOperations()
            ->where('role_id', 3)
            ->with(['unit', 'unit.driver'])
            ->get();

        $assignableUnits = Unit::with(['truckType', 'teamLeader'])
            ->orderByRaw("CASE WHEN status = 'available' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        $busyTeamLeaders = $this->teamLeaderAvailability->busyTeamLeaderIds();
        $teamLeaderSummary = $this->teamLeaderAvailability->summarize($teamLeaders, $busyTeamLeaders);
        $teamLeaderStatuses = $teamLeaderSummary['leaders']->keyBy('id');
        $onlineTeamLeadersCount = $teamLeaderSummary['online_count'];
        $offlineTeamLeadersCount = $teamLeaderSummary['offline_count'];

        return view('admin-dashboard.pages.drivers', compact(
            'teamLeaders',
            'assignableUnits',
            'busyTeamLeaders',
            'teamLeaderStatuses',
            'onlineTeamLeadersCount',
            'offlineTeamLeadersCount'
        ));
    }

    public function assignUnit(Request $request, User $teamLeader): RedirectResponse
    {
        abort_unless((int) $teamLeader->role_id === 3, 404);

        $validated = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
        ]);

        if (! $this->teamLeaderAvailability->isOnline($teamLeader)) {
            return redirect()
                ->route('admin.drivers')
                ->withErrors(['unit_id' => 'This team leader is offline. Bring them online before assigning a unit.']);
        }

        $unit = Unit::query()->with('truckType')->findOrFail($validated['unit_id']);
        $currentAssignedUnit = Unit::query()
            ->where('team_leader_id', $teamLeader->id)
            ->first();

        if ($unit->status !== 'available') {
            return redirect()
                ->route('admin.drivers')
                ->withErrors(['unit_id' => 'Only available units can be assigned from the dispatcher module.']);
        }

        if ($unit->team_leader_id && (int) $unit->team_leader_id !== (int) $teamLeader->id) {
            $ownerName = optional($unit->teamLeader)->full_name ?? optional($unit->teamLeader)->name ?? 'another team leader';

            return redirect()
                ->route('admin.drivers')
                ->withErrors(['unit_id' => 'This unit is already assigned to ' . $ownerName . '. Release it first before reassigning.']);
        }

        Unit::query()
            ->where('team_leader_id', $teamLeader->id)
            ->whereKeyNot($unit->id)
            ->update(['team_leader_id' => null]);

        $unit->update([
            'team_leader_id' => $teamLeader->id,
        ]);

        return redirect()
            ->route('admin.drivers')
            ->with('success', 'Unit assigned to ' . ($teamLeader->full_name ?: $teamLeader->name) . ' successfully.');
    }

    public function updateStatus(Request $request, User $teamLeader): RedirectResponse
    {
        abort_unless((int) $teamLeader->role_id === 3, 404);

        $validated = $request->validate([
            'operational_status' => ['required', 'in:available,busy,unavailable'],
            'unit_status' => ['nullable', 'in:available,on_job,maintenance'],
            'status_reason' => ['nullable', 'string', 'max:120'],
        ]);

        $this->teamLeaderAvailability->setOperationalOverride(
            $teamLeader,
            $validated['operational_status'],
            $validated['status_reason'] ?? null
        );

        if ($teamLeader->unit && filled($validated['unit_status'] ?? null)) {
            $teamLeader->unit->update([
                'status' => $validated['unit_status'],
            ]);
        }

        return redirect()
            ->route('admin.drivers')
            ->with('success', 'Operational status updated for ' . ($teamLeader->full_name ?: $teamLeader->name) . '.');
    }
}
