<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TruckType;

class TruckTypeController extends Controller
{
    public function index()
    {
        $truckTypes = TruckType::paginate(10);
        return view('superadmin.truck-types.index', compact('truckTypes'));
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

        TruckType::create($validated);

        return redirect()->route('superadmin.truck-types.index')->with('success', 'Truck type created successfully.');
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

        return redirect()->route('superadmin.truck-types.index');
    }

    public function toggleStatus(TruckType $truckType)
    {
        $truckType->update([
            'status' => $truckType->status === 'active' ? 'inactive' : 'active'
        ]);

        return back();
    }
}
