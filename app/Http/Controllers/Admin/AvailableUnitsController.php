<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\TruckType;
use Illuminate\Http\Request;

class AvailableUnitsController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $unitsQuery = Unit::with(['truckType', 'driver', 'teamLeader'])
            ->where('status', 'available');

        if ($search !== '') {
            $unitsQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('plate_number', 'like', "%{$search}%")
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
            ->orderByRaw('CASE WHEN team_leader_id IS NULL THEN 1 ELSE 0 END')
            ->latest('updated_at')
            ->paginate(12)
            ->withQueryString();

        $truckTypes = TruckType::where('status', 'active')->orderBy('name')->get();

        $stats = [
            'available' => Unit::where('status', 'available')->count(),
            'ready_team_leaders' => Unit::where('status', 'available')->whereNotNull('team_leader_id')->count(),
            'truck_types' => $truckTypes->count(),
        ];

        return view('admin-dashboard.pages.available-units', compact('units', 'truckTypes', 'stats', 'search'));
    }
}
