<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AvailableUnitsController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $unitsQuery = Unit::with(['truckType', 'driver.role', 'teamLeader.role']);

        if ($search !== '') {
            $unitsQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('plate_number', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhereHas('truckType', function ($truckTypeQuery) use ($search) {
                        $truckTypeQuery->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('teamLeader', function ($teamLeaderQuery) use ($search) {
                        $teamLeaderQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%");
                    });
            });
        }

        $units = $unitsQuery
            ->orderByRaw("CASE 
                WHEN status = 'available' THEN 0
                WHEN status = 'on_job' THEN 1
                ELSE 2
            END")
            ->orderByRaw('CASE WHEN team_leader_id IS NULL THEN 1 ELSE 0 END')
            ->latest('updated_at')
            ->paginate(12)
            ->withQueryString();

        return view('admin-dashboard.pages.available-units', compact('units', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'plate_number' => 'required|string|max:50|unique:units,plate_number',
            'truck_type_id' => 'required|exists:truck_types,id',
            'status' => 'nullable|in:available,maintenance',
            'issue_note' => 'nullable|string|max:500',
        ]);

        $validated['plate_number'] = strtoupper(trim((string) $validated['plate_number']));
        $validated['status'] = $validated['status'] ?? 'available';

        if ($validated['status'] !== 'maintenance') {
            $validated['issue_note'] = null;
        }

        Unit::create($validated);

        return redirect()
            ->route('admin.available-units')
            ->with('success', 'Unit added successfully.');
    }

    public function toggle(Unit $unit): RedirectResponse
    {
        if ($unit->status === 'on_job') {
            return redirect()
                ->route('admin.available-units')
                ->with('error', 'This unit is currently on a job and cannot be switched off yet.');
        }

        $nextStatus = $unit->status === 'available' ? 'maintenance' : 'available';

        $unit->update([
            'status' => $nextStatus,
            'issue_note' => $nextStatus === 'available' ? null : $unit->issue_note,
        ]);

        return redirect()
            ->route('admin.available-units')
            ->with('success', $nextStatus === 'available'
                ? 'Unit marked as available.'
                : 'Unit marked as not available.');
    }

    public function markMaintenance(Request $request, Unit $unit)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'booking_id' => 'nullable|string',
        ]);

        if ($unit->status === 'on_job') {
            return response()->json([
                'success' => false,
                'message' => 'This unit is currently on a job and cannot be marked for maintenance yet.',
            ], 422);
        }

        $unit->update([
            'status' => 'maintenance',
            'issue_note' => $validated['reason'],
        ]);

        \Illuminate\Support\Facades\Log::warning('Unit marked for maintenance from dispatch', [
            'unit_id' => $unit->id,
            'unit_name' => $unit->name,
            'reason' => $validated['reason'],
            'booking_id' => $validated['booking_id'] ?? null,
            'dispatcher_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Unit marked for maintenance successfully.',
        ]);
    }
}
