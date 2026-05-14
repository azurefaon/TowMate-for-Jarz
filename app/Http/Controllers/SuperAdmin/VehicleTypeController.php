<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use App\Models\TruckType;
use Illuminate\Http\Request;

class VehicleTypeController extends Controller
{
    public function index()
    {
        $vehicleTypes = VehicleType::withCount(['truckTypes', 'bookings'])
            ->with(['truckTypes:id,name'])
            ->orderBy('display_order')
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $truckTypes = TruckType::where('status', 'active')->orderBy('name')->get();

        $stats = [
            'total' => VehicleType::count(),
            'active' => VehicleType::where('status', 'active')->count(),
            'inactive' => VehicleType::where('status', 'inactive')->count(),
            '2_wheeler' => VehicleType::where('category', '2_wheeler')->count(),
            '4_wheeler' => VehicleType::where('category', '4_wheeler')->count(),
            'heavy_vehicle' => VehicleType::where('category', 'heavy_vehicle')->count(),
        ];

        return view('superadmin.vehicle-types.index', compact('vehicleTypes', 'truckTypes', 'stats'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:vehicle_types,name',
            'category' => 'required|in:2_wheeler,4_wheeler,heavy_vehicle',
            'description' => 'nullable|string|max:500',
            'display_order' => 'nullable|integer|min:0',
            'truck_types' => 'nullable|array',
            'truck_types.*' => 'exists:truck_types,id',
        ]);

        $validated['status'] = 'active';
        $validated['display_order'] = $validated['display_order'] ?? 0;

        $vehicleType = VehicleType::create($validated);

        if (!empty($validated['truck_types'])) {
            $vehicleType->truckTypes()->sync($validated['truck_types']);
        }

        return redirect()->route('superadmin.vehicle-types.index')
            ->with('success', 'Vehicle type created successfully.');
    }

    public function update(Request $request, VehicleType $vehicleType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:vehicle_types,name,' . $vehicleType->id,
            'category' => 'required|in:2_wheeler,4_wheeler,heavy_vehicle',
            'description' => 'nullable|string|max:500',
            'display_order' => 'nullable|integer|min:0',
            'truck_types' => 'nullable|array',
            'truck_types.*' => 'exists:truck_types,id',
        ]);

        $vehicleType->update($validated);

        if (isset($validated['truck_types'])) {
            $vehicleType->truckTypes()->sync($validated['truck_types']);
        }

        return redirect()->route('superadmin.vehicle-types.index')
            ->with('success', 'Vehicle type updated successfully.');
    }

    public function toggleStatus(VehicleType $vehicleType)
    {
        $vehicleType->update([
            'status' => $vehicleType->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', 'Vehicle type status updated successfully.');
    }

    public function destroy(VehicleType $vehicleType)
    {
        if ($vehicleType->bookings()->exists()) {
            return back()->with('error', 'Cannot delete vehicle type with existing bookings.');
        }

        $vehicleType->delete();

        return back()->with('success', 'Vehicle type deleted successfully.');
    }

    public function getByCategory($category)
    {
        $vehicleTypes = VehicleType::where('category', $category)
            ->where('status', 'active')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        return response()->json(['vehicleTypes' => $vehicleTypes]);
    }

    public function getTruckTypesByVehicle($vehicleTypeId)
    {
        $vehicleType = VehicleType::with('truckTypes')->findOrFail($vehicleTypeId);
        
        $truckTypes = $vehicleType->truckTypes()
            ->where('status', 'active')
            ->orderBy('base_rate')
            ->get(['id', 'name', 'base_rate', 'per_km_rate', 'description']);

        $truckTypeIds = $vehicleType->truckTypes()->pluck('truck_types.id')->toArray();

        return response()->json([
            'truckTypes' => $truckTypes,
            'truckTypeIds' => $truckTypeIds
        ]);
    }
}
