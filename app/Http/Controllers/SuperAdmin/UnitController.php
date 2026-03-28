<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Unit;
use App\Models\TruckType;

class UnitController extends Controller
{
    public function index()
    {
        $units = Unit::with(['teamLeader','driver','truckType'])->paginate(10);
        $truckTypes = TruckType::where('status','active')->get();

        return view('superadmin.unit-truck.index', compact('units','truckTypes'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'plate_number' => 'required|string|max:50',
            'truck_type_id' => 'required|exists:truck_types,id'
        ]);

        Unit::create([
            'name' => $request->name,
            'plate_number' => $request->plate_number,
            'truck_type_id' => $request->truck_type_id,
            'status' => 'available'
        ]);

        return back()->with('success','Unit added successfully');
    }

    public function update(Request $request, $id)
    {
        $unit = Unit::findOrFail($id);

        $unit->update([
            'name' => $request->name,
            'plate_number' => $request->plate_number,
            'truck_type_id' => $request->truck_type_id
        ]);

        return back()->with('success','Unit updated successfully');
    }

    public function toggle($id)
    {
        $unit = Unit::findOrFail($id);

        $unit->status =
            $unit->status === 'available'
            ? 'maintenance'
            : 'available';

        $unit->save();

        return back()->with('success', 'Unit status updated successfully.');
    }
}