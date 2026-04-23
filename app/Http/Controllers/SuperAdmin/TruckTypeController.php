<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\TruckType;
use App\Models\Unit;
use Illuminate\Http\Request;

class TruckTypeController extends Controller
{
    public function index()
    {
        $truckTypes = TruckType::withCount('units')
            ->withCount([
                'bookings as active_bookings_count' => fn($query) => $query->whereIn('status', $this->busyBookingStatuses()),
            ])
            ->orderBy('name')
            ->paginate(5)
            ->withQueryString();

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
        if ($truckType->status === 'active' && $this->truckTypeIsBusy($truckType)) {
            return back()->with('error', 'This tow truck type is busy and cannot be set inactive while it is assigned or booked.');
        }

        $truckType->update([
            'status' => $truckType->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', 'Tow truck type status updated successfully.');
    }

    public function destroy(TruckType $truckType)
    {
        // Check if truck type has units
        if ($truckType->units()->exists()) {
            return back()->with('error', 'Cannot delete truck type with existing units assigned.');
        }

        // Check if truck type has bookings
        if ($truckType->bookings()->exists()) {
            return back()->with('error', 'Cannot delete truck type with existing bookings.');
        }

        // Check if truck type has vehicle types linked
        if ($truckType->vehicleTypes()->exists()) {
            return back()->with('error', 'Cannot delete truck type that is linked to vehicle types.');
        }

        $truckType->delete();

        return back()->with('success', 'Truck type deleted successfully.');
    }

    public function getConfig(string $name)
    {
        $type = TruckType::where('name', $name)->first();

        if (! $type || is_null($type->base_rate)) {
            return response()->json(['configured' => false]);
        }

        return response()->json([
            'configured'  => true,
            'base_rate'   => $type->base_rate,
            'per_km_rate' => $type->per_km_rate,
            'capacity'    => $type->max_tonnage,
            'description' => $type->description,
        ]);
    }

    public function saveConfig(\Illuminate\Http\Request $request, string $name)
    {
        if (! in_array($name, ['Heavy', 'Medium', 'Light'])) {
            abort(422, 'Invalid truck type.');
        }

        $validated = $request->validate([
            'base_rate'   => 'required|numeric|min:0',
            'per_km_rate' => 'required|numeric|min:0',
            'capacity'    => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);

        TruckType::updateOrCreate(
            ['name' => $name],
            [
                'base_rate'   => $validated['base_rate'],
                'per_km_rate' => $validated['per_km_rate'],
                'max_tonnage' => $validated['capacity'],
                'description' => $validated['description'] ?? null,
                'status'      => 'active',
            ]
        );

        return response()->json(['success' => true]);
    }

    protected function truckTypeIsBusy(TruckType $truckType): bool
    {
        return $truckType->units()->exists()
            || $truckType->bookings()->whereIn('status', $this->busyBookingStatuses())->exists();
    }

    protected function busyBookingStatuses(): array
    {
        return [
            'requested',
            'reviewed',
            'quoted',
            'quotation_sent',
            'confirmed',
            'accepted',
            'assigned',
            'on_the_way',
            'in_progress',
            'waiting_verification',
            'on_job',
        ];
    }
}
