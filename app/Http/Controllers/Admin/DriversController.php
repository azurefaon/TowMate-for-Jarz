<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        $allUnits = Unit::with(['truckType', 'teamLeader', 'driver', 'zone'])
            ->whereNull('archived_at')
            ->where(function ($q) {
                // Include units with no TL (offline/unassigned) AND units with an active TL.
                // Exclude only units whose assigned TL has been archived.
                $q->whereNull('team_leader_id')
                  ->orWhereHas('teamLeader', fn ($sq) => $sq->whereNull('archived_at'));
            })
            ->orderByRaw("CASE status
                WHEN 'available' THEN 0
                WHEN 'on_job'    THEN 1
                WHEN 'offline'   THEN 2
                ELSE 3 END")
            ->orderBy('name')
            ->get();

        $busyTeamLeaders       = $this->teamLeaderAvailability->busyTeamLeaderIds();
        $teamLeaderSummary     = $this->teamLeaderAvailability->summarize($teamLeaders, $busyTeamLeaders);
        $teamLeaderStatuses    = $teamLeaderSummary['leaders']->keyBy('id');
        $onlineTeamLeadersCount  = $teamLeaderSummary['online_count'];
        $offlineTeamLeadersCount = $teamLeaderSummary['offline_count'];

        $zones = \App\Models\Zone::orderBy('name')->get();

        return view('admin-dashboard.pages.drivers', compact(
            'teamLeaders',
            'allUnits',
            'assignableUnits',
            'busyTeamLeaders',
            'teamLeaderStatuses',
            'onlineTeamLeadersCount',
            'offlineTeamLeadersCount',
            'zones'
        ));
    }

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

        // Block assignment when status is unavailable, on_tow, or on_job
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

        if ($request->expectsJson()) {
            // Use the summary service for robust, up-to-date status/unit info
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

        // Block removal if unit is actively on a job or towing
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

        if ($request->expectsJson()) {
            return response()->json([
                'message'        => 'Unit removed from ' . ($teamLeader->full_name ?: $teamLeader->name) . '.',
                'unit_released'  => true,
                'team_leader_id' => $teamLeader->id,
            ], 200);
        }

        return back()->with('success', 'Unit removed from ' . ($teamLeader->full_name ?: $teamLeader->name) . '.');
    }

    /**
     * Accepts both AJAX (JSON) and standard form POST.
     */
    public function override(Request $request, int $teamLeaderId): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'zone_id'         => ['nullable', 'exists:zones,id'],
            'unit_status'     => ['nullable', 'in:available,unavailable,on_tow,on_job'],
            'zone_confirmed'  => ['boolean'],
            'dispatcher_note' => ['nullable', 'string', 'max:120'],
        ]);

        $unit = Unit::where('team_leader_id', $teamLeaderId)->first();

        $unitStatus = $validated['unit_status'] ?? null;

        // When dispatcher marks as unavailable, release the unit entirely
        if ($unitStatus === 'unavailable' && $unit) {
            $unit->update([
                'team_leader_id'    => null,
                'status'            => 'available',
                'dispatcher_status' => 'unavailable',
                'dispatcher_note'   => $validated['dispatcher_note'] ?? null,
                'zone_confirmed'    => false,
                'last_updated_by'   => Auth::user()->name,
                'last_updated_at'   => now(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'status'         => ['label' => 'NOT AVAILABLE', 'class' => 'status-not-avail', 'subtext' => 'Unit released'],
                    'assigned_unit'  => ['id' => null, 'name' => '', 'plate_number' => '', 'driver_name' => ''],
                    'unit_released'  => true,
                    'team_leader_id' => $teamLeaderId,
                ], 200);
            }

            return back()->with('success', 'Team leader marked unavailable and unit released.');
        }

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

        if ($request->expectsJson()) {
            $teamLeader = User::findOrFail($teamLeaderId);
            $summary    = $this->teamLeaderAvailability->summarize(collect([$teamLeader]));
            $leader     = $summary['leaders']->first();

            return response()->json([
                'status' => [
                    'label'   => strtoupper($leader['unit_status_label'] ?? ''),
                    'class'   => 'status-' . ($leader['unit_status'] ?? 'standby'),
                    'subtext' => $leader['status_summary'] ?? '',
                ],
                'assigned_unit' => [
                    'id'          => $leader['assigned_unit_id'] ?? null,
                    'name'        => $leader['unit_name'] ?? '',
                    'plate_number'=> $leader['plate_number'] ?? '',
                    'driver_name' => $leader['driver_name'] ?? '',
                ],
                'unit_released'  => false,
                'team_leader_id' => $teamLeaderId,
            ], 200);
        }

        return back()->with('success', 'Team leader status updated.');
    }
}
