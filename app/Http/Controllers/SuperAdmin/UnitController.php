<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    private const PLATE_NUMBER_REGEX = '/^(?:[A-HJ-NP-Z]{3}[0-9]{4}|[A-HJ-NP-Z]{3}[0-9]{3}|[A-HJ-NP-Z]{2}[0-9]{5})$/';
    private const PLATE_NUMBER_ERROR = 'Plate number must be 3 letters + 4 digits, 3 letters + 3 digits, or 2 letters + 5 digits, without the letters I or O.';

    protected function normalizePlateNumber(?string $plateNumber): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) $plateNumber)));
    }

    public function index()
    {
        $units = Unit::with(['teamLeader.role', 'driver', 'truckType'])
            ->whereNull('archived_at')
            ->latest()
            ->paginate(10);

        $truckTypes = TruckType::where('status', 'active')
            ->orderBy('name')
            ->get();

        $teamLeaderRoleId = Role::where('name', 'Team Leader')->value('id');

        $teamLeaders = User::visibleToOperations()
            ->where('role_id', $teamLeaderRoleId)
            ->withCount(['unit' => fn($q) => $q->whereNull('archived_at')])
            ->with('unit:id,name,team_leader_id')
            ->orderBy('name')
            ->get([
                'id', 'name', 'first_name', 'middle_name', 'last_name',
                'driver_first_name', 'driver_middle_name', 'driver_last_name',
                'crew_member_1_name', 'crew_member_2_name',
            ]);

        $teamLeaderStagedData = $teamLeaders->map(fn($leader) => [
            'id' => $leader->id,
            'current_unit_id' => $leader->unit?->id,
            'current_unit_name' => $leader->unit?->name,
            'driver_name' => build_full_name($leader->driver_first_name, $leader->driver_middle_name, $leader->driver_last_name),
            'crew_member_1_name' => $leader->crew_member_1_name,
            'crew_member_2_name' => $leader->crew_member_2_name,
        ])->values();

        $stats = [
            'total' => Unit::count(),
            'available' => Unit::where('status', 'available')->count(),
            'on_job' => Unit::where('status', 'on_job')->count(),
            'maintenance' => Unit::where('status', 'maintenance')->count(),
        ];

        $activeLoans = UnitCrewLoan::whereNull('returned_at')->get();
        $loansOutBySlot = $activeLoans->keyBy(fn($loan) => $loan->from_unit_id . ':' . $loan->from_slot);
        $loansInBySlot = $activeLoans->keyBy(fn($loan) => $loan->to_unit_id . ':' . $loan->to_slot);

        $crewUnitsData = Unit::select('id', 'name', 'status', 'driver_name', 'driver_2_name', 'crew_member_1_name', 'crew_member_2_name')
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(function ($unit) use ($loansInBySlot) {
                $unit->loaned_in_slots = collect(array_keys(Unit::SLOT_COLUMNS))
                    ->filter(fn($slot) => $loansInBySlot->has("{$unit->id}:{$slot}"))
                    ->values();

                return $unit;
            });

        $nextUnitName = $this->nextUnitName();

        $archivedCount = Unit::whereNotNull('archived_at')->count();

        return view('superadmin.unit-truck.index', compact(
            'units', 'truckTypes', 'stats', 'teamLeaders',
            'crewUnitsData', 'loansOutBySlot', 'loansInBySlot', 'teamLeaderStagedData', 'nextUnitName',
            'archivedCount'
        ));
    }

    public function archived(Request $request)
    {
        $archivedUnits = Unit::with(['teamLeader.role', 'truckType'])
            ->whereNotNull('archived_at')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(fn($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('plate_number', 'like', "%{$search}%"));
            })
            ->latest('archived_at')
            ->paginate(10)
            ->withQueryString();

        return view('superadmin.unit-truck.archived', compact('archivedUnits'));
    }

    protected function nextUnitName(): string
    {
        $prefix = 'JARZ';

        $max = Unit::where('name', 'like', $prefix . ' %')
            ->get(['name'])
            ->map(fn($u) => (int) trim(str_replace($prefix, '', $u->name)))
            ->max();

        return $prefix . ' ' . (($max ?? 0) + 1);
    }

    public function store(Request $request)
    {
        $request->merge([
            'plate_number' => $this->normalizePlateNumber($request->input('plate_number')),
        ]);

        $validated = $request->validate([
            'plate_number' => [
                'required',
                'string',
                'max:50',
                'regex:' . self::PLATE_NUMBER_REGEX,
                'unique:units,plate_number',
            ],
            'truck_type_id' => 'required|exists:truck_types,id',
            'status' => 'nullable|in:available,maintenance',
            'issue_note' => 'nullable|string|max:500',
        ], [
            'plate_number.regex' => self::PLATE_NUMBER_ERROR,
        ]);

        $validated['name'] = $this->nextUnitName();
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

        $request->merge([
            'plate_number' => $this->normalizePlateNumber($request->input('plate_number')),
        ]);

        $validated = $request->validate([
            'plate_number' => [
                'required',
                'string',
                'max:50',
                'regex:' . self::PLATE_NUMBER_REGEX,
                Rule::unique('units', 'plate_number')->ignore($unit->id),
            ],
            'truck_type_id' => 'required|exists:truck_types,id',
        ], [
            'plate_number.regex' => self::PLATE_NUMBER_ERROR,
        ]);

        $unit->update([
            'plate_number'  => $validated['plate_number'],
            'truck_type_id' => $validated['truck_type_id'],
        ]);

        return redirect()->route('superadmin.unit-truck.index')
            ->with('success', 'Unit updated successfully.');
    }

    public function toggle($id)
    {
        $unit = Unit::findOrFail($id);

        if ($unit->status === 'on_job') {
            return back()->with('error', 'This truck cannot be placed under maintenance while it is assigned to an active job.');
        }

        $nextStatus = $unit->status === 'maintenance' ? 'available' : 'maintenance';

        $unit->update([
            'status' => $nextStatus,
            'issue_note' => $nextStatus === 'available' ? null : $unit->issue_note,
        ]);

        return back()->with('success', 'Unit status updated successfully.');
    }

    public function archive($id): RedirectResponse
    {
        $unit = Unit::findOrFail($id);

        if ($unit->status === 'on_job') {
            return back()->with('error', 'Cannot archive a unit that is currently on a job.');
        }

        $unit->update(['archived_at' => now()]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'unit_archived',
            'entity_type' => 'Unit',
            'entity_id' => $unit->id,
            'reference' => $unit->name,
            'description' => "Moved unit {$unit->name} to archive.",
        ]);

        return redirect()->route('superadmin.unit-truck.index')
            ->with('success', 'Unit moved to archive successfully.');
    }

    public function restore($id): RedirectResponse
    {
        $unit = Unit::findOrFail($id);

        $unit->update(['archived_at' => null]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'unit_restored',
            'entity_type' => 'Unit',
            'entity_id' => $unit->id,
            'reference' => $unit->name,
            'description' => "Restored unit {$unit->name} from archive.",
        ]);

        return redirect()->route('superadmin.units.archived')
            ->with('success', 'Unit restored successfully.');
    }

    public function forceDelete($id): RedirectResponse
    {
        $unit = Unit::findOrFail($id);

        if (! $unit->archived_at) {
            return back()->with('error', 'Only archived units can be permanently deleted.');
        }

        if (Booking::where('assigned_unit_id', $unit->id)->exists()) {
            return back()->with('error', 'This unit has booking history and cannot be permanently deleted.');
        }

        $hasActiveLoan = UnitCrewLoan::where(function ($q) use ($unit) {
                $q->where('from_unit_id', $unit->id)->orWhere('to_unit_id', $unit->id);
            })
            ->whereNull('returned_at')
            ->exists();

        if ($hasActiveLoan) {
            return back()->with('error', 'This unit has an active crew transfer (borrowed or lent out). Return it first before deleting.');
        }

        $reference = $unit->name;
        $entityId = $unit->id;

        $unit->delete();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'unit_permanently_deleted',
            'entity_type' => 'Unit',
            'entity_id' => $entityId,
            'reference' => $reference,
            'description' => "Permanently deleted unit {$reference} from archive.",
        ]);

        return redirect()->route('superadmin.units.archived')
            ->with('success', 'Unit permanently deleted.');
    }

}
