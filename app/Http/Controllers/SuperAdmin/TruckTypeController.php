<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\TruckType;
use App\Models\Unit;
use Illuminate\Http\Request;

class TruckTypeController extends Controller
{
    public function index()
    {
        $truckTypes = TruckType::withCount('units')
            ->orderBy('name')
            ->paginate(10);

        $stats = [
            'total' => TruckType::count(),
            'active' => TruckType::where('status', 'active')->count(),
            'inactive' => TruckType::where('status', 'inactive')->count(),
            'units' => Unit::count(),
        ];

        return view('superadmin.truck-types.index', compact('truckTypes', 'stats'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:truck_types,name',
            'base_rate' => 'required|numeric|min:0',
            'per_km_rate' => 'required|numeric|min:0',
            'max_tonnage' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);

        $validated['status'] = 'active';

        TruckType::create($validated);

        return redirect()->route('superadmin.truck-types.index')
            ->with('success', 'Tow truck type created successfully.');
    }

    public function update(Request $request, TruckType $truckType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:truck_types,name,' . $truckType->id,
            'base_rate' => 'required|numeric|min:0',
            'per_km_rate' => 'required|numeric|min:0',
            'max_tonnage' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);

        $truckType->update($validated);

        return redirect()->route('superadmin.truck-types.index')
            ->with('success', 'Tow truck type updated successfully.');
    }

    public function toggleStatus(TruckType $truckType)
    {
        $truckType->update([
            'status' => $truckType->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', 'Tow truck type status updated successfully.');
    }
}
