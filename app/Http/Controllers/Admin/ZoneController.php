<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Models\Zone;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $zones = Zone::orderBy('name')->get();
        return view('admin-dashboard.pages.zones.index', compact('zones'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $zones = Zone::orderBy('name')->get();
        $teamLeaders = \App\Models\User::where('role_id', function ($query) {
            $query->select('id')->from('roles')->where('name', 'team leader');
        })->orderBy('name')->get();
        return view('admin-dashboard.pages.zones.create', compact('zones', 'teamLeaders'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:zones,name',
            'description' => 'nullable|string|max:500',
            'team_leader_ids' => 'nullable|array',
            'team_leader_ids.*' => 'exists:users,id',
        ]);

        $zone = Zone::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        // Assign team leaders if provided
        if (!empty($validated['team_leader_ids'])) {
            foreach ($validated['team_leader_ids'] as $leaderId) {
                // This assumes team leaders manage units, which belong to zones
                // You may need to adjust based on your actual data structure
                \App\Models\Unit::where('team_leader_id', $leaderId)
                    ->update(['zone_id' => $zone->id]);
            }
        }

        return redirect()->route('admin.zones.index')->with('success', 'Zone created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $zone = Zone::findOrFail($id);
        return view('admin-dashboard.pages.zones.show', compact('zone'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $zone = Zone::findOrFail($id);
        $zones = Zone::orderBy('name')->get();
        $teamLeaders = \App\Models\User::where('role_id', function ($query) {
            $query->select('id')->from('roles')->where('name', 'team leader');
        })->orderBy('name')->get();
        $zoneTeamLeaders = \App\Models\Unit::where('zone_id', $zone->id)
            ->pluck('team_leader_id')
            ->unique()
            ->toArray();
        return view('admin-dashboard.pages.zones.edit', compact('zone', 'zones', 'teamLeaders', 'zoneTeamLeaders'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $zone = Zone::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:zones,name,' . $zone->id,
            'description' => 'nullable|string|max:500',
            'team_leader_ids' => 'nullable|array',
            'team_leader_ids.*' => 'exists:users,id',
        ]);

        $zone->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        // Update team leader assignments
        if (isset($validated['team_leader_ids'])) {
            // Get all team leaders currently in this zone
            $currentTeamLeaderIds = \App\Models\Unit::where('zone_id', $zone->id)
                ->pluck('team_leader_id')
                ->unique()
                ->toArray();

            // Remove team leaders not in the new list
            $toRemove = array_diff($currentTeamLeaderIds, $validated['team_leader_ids']);
            if (!empty($toRemove)) {
                \App\Models\Unit::where('zone_id', $zone->id)
                    ->whereIn('team_leader_id', $toRemove)
                    ->update(['zone_id' => null]);
            }

            // Add team leaders that are in the new list
            $toAdd = array_diff($validated['team_leader_ids'], $currentTeamLeaderIds);
            if (!empty($toAdd)) {
                foreach ($toAdd as $leaderId) {
                    \App\Models\Unit::where('team_leader_id', $leaderId)
                        ->update(['zone_id' => $zone->id]);
                }
            }
        }

        return redirect()->route('admin.zones.index')->with('success', 'Zone updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $zone = Zone::findOrFail($id);
        $zone->delete();
        return redirect()->route('admin.zones.index')->with('success', 'Zone deleted successfully.');
    }
}
