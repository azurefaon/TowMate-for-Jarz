<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index()
    {
        $units = Unit::with(['teamLeader', 'driver', 'truckType'])
            ->latest()
            ->paginate(10);

        $truckTypes = TruckType::where('status', 'active')
            ->orderBy('name')
            ->get();

        $teamLeaderRoleId = Role::where('name', 'Team Leader')->value('id');
        $driverRoleId     = Role::where('name', 'Driver')->value('id');

        $teamLeaders = User::where('role_id', $teamLeaderRoleId)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name']);

        $drivers = User::where('role_id', $driverRoleId)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name']);

        $stats = [
            'total' => Unit::count(),
            'available' => Unit::where('status', 'available')->count(),
            'on_job' => Unit::where('status', 'on_job')->count(),
            'maintenance' => Unit::where('status', 'maintenance')->count(),
        ];

        return view('superadmin.unit-truck.index', compact('units', 'truckTypes', 'stats', 'teamLeaders', 'drivers'));
    }

    public function store(Request $request)
    {
        $teamLeaderRoleId = Role::where('name', 'Team Leader')->value('id');
        $driverRoleId     = Role::where('name', 'Driver')->value('id');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'plate_number' => 'required|string|max:50|unique:units,plate_number',
            'truck_type_id' => 'required|exists:truck_types,id',
            'team_leader_id' => [
                'nullable',
                Rule::exists('users', 'id')
                    ->where(fn($q) => $q->where('role_id', $teamLeaderRoleId)->whereNull('archived_at')),
                'unique:units,team_leader_id',
            ],
            'driver_id' => [
                'nullable',
                Rule::exists('users', 'id')
                    ->where(fn($q) => $q->where('role_id', $driverRoleId)->whereNull('archived_at')),
            ],
            'status' => 'nullable|in:available,maintenance',
            'issue_note' => 'nullable|string|max:500',
        ], [
            'team_leader_id.unique' => 'This team leader is already assigned to another unit.',
            'team_leader_id.exists' => 'Selected team leader is invalid or archived.',
            'driver_id.exists'      => 'Selected driver is invalid or archived.',
        ]);

        $validated['plate_number'] = strtoupper($validated['plate_number']);
        $validated['status'] = $validated['status'] ?? 'available';

        if ($validated['status'] !== 'maintenance') {
            $validated['issue_note'] = null;
        }

        Unit::create($validated);

        return redirect()->route('superadmin.unit-truck.index')
            ->with('success', 'Unit added successfully.');
    }

    public function update(Request $request, $id)
    {
        $unit = Unit::findOrFail($id);

        $teamLeaderRoleId = Role::where('name', 'Team Leader')->value('id');
        $driverRoleId     = Role::where('name', 'Driver')->value('id');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'plate_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('units', 'plate_number')->ignore($unit->id),
            ],
            'truck_type_id' => 'required|exists:truck_types,id',
            'status' => 'required|in:available,on_job,maintenance',
            'issue_note' => 'nullable|string|max:500',
            'team_leader_id' => [
                'nullable',
                Rule::exists('users', 'id')
                    ->where(fn($q) => $q->where('role_id', $teamLeaderRoleId)->whereNull('archived_at')),
                Rule::unique('units', 'team_leader_id')->ignore($unit->id),
            ],
            'driver_id' => [
                'nullable',
                Rule::exists('users', 'id')
                    ->where(fn($q) => $q->where('role_id', $driverRoleId)->whereNull('archived_at')),
            ],
        ], [
            'team_leader_id.unique' => 'This team leader is already assigned to another unit.',
            'team_leader_id.exists' => 'Selected team leader is invalid or archived.',
            'driver_id.exists'      => 'Selected driver is invalid or archived.',
        ]);

        $validated['plate_number'] = strtoupper($validated['plate_number']);

        if ($validated['status'] !== 'maintenance') {
            $validated['issue_note'] = null;
        }

        $unit->update([
            'name'           => $validated['name'],
            'plate_number'   => $validated['plate_number'],
            'truck_type_id'  => $validated['truck_type_id'],
            'status'         => $validated['status'],
            'issue_note'     => $validated['issue_note'] ?? null,
            'team_leader_id' => $validated['team_leader_id'] ?? null,
            'driver_id'      => $validated['driver_id'] ?? null,
            'driver_name'    => $request->filled('driver_id')
                ? null
                : $unit->driver_name,
        ]);

        return redirect()->route('superadmin.unit-truck.index')
            ->with('success', 'Unit updated successfully.');
    }

    public function toggle($id)
    {
        $unit = Unit::findOrFail($id);

        $nextStatus = $unit->status === 'maintenance' ? 'available' : 'maintenance';

        $unit->update([
            'status' => $nextStatus,
            'issue_note' => $nextStatus === 'available' ? null : $unit->issue_note,
        ]);

        return back()->with('success', 'Unit status updated successfully.');
    }
}
