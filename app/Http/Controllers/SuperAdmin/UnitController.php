<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\TruckType;
use App\Models\Unit;
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

        $stats = [
            'total' => Unit::count(),
            'available' => Unit::where('status', 'available')->count(),
            'on_job' => Unit::where('status', 'on_job')->count(),
            'maintenance' => Unit::where('status', 'maintenance')->count(),
        ];

        return view('superadmin.unit-truck.index', compact('units', 'truckTypes', 'stats'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'plate_number' => 'required|string|max:50|unique:units,plate_number',
            'truck_type_id' => 'required|exists:truck_types,id',
            'status' => 'nullable|in:available,maintenance',
            'issue_note' => 'nullable|string|max:500',
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
        ]);

        $validated['plate_number'] = strtoupper($validated['plate_number']);

        if ($validated['status'] !== 'maintenance') {
            $validated['issue_note'] = null;
        }

        $unit->update($validated);

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
