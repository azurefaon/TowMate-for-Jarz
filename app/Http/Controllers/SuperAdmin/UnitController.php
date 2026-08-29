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
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    // 3 letters + 4 digits, 3 letters + 3 digits, or 2 letters + 5 digits; I and O are excluded (confusable with 1/0).
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
        $prefix = 'JARZ'; // TODO: move to System Settings once Content Management exists

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

    public function assignTeamLeader(Request $request, $id): RedirectResponse
    {
        $teamLeaderRoleId = Role::where('name', 'Team Leader')->value('id');

        $validated = $request->validate([
            'team_leader_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where(fn($q) => $q->where('role_id', $teamLeaderRoleId)->whereNull('archived_at')->whereNull('anonymized_at')),
            ],
        ], [
            'team_leader_id.required' => 'Select a Team Leader to assign.',
            'team_leader_id.exists'   => 'Selected team leader is invalid, archived, or deleted.',
        ]);

        $alreadyAssignedMessage = 'This team leader is already assigned to another unit.';

        // Re-check availability and write inside a locked transaction so two
        // near-simultaneous assign requests for the same leader can't both
        // pass the check before either commits (the old bare validation rule
        // was a check-then-write race). The DB's partial unique index on
        // units.team_leader_id is the hard backstop if this still races.
        try {
            $unit = DB::transaction(function () use ($id, $validated, $alreadyAssignedMessage) {
                $unit = Unit::lockForUpdate()->findOrFail($id);

                $alreadyTaken = Unit::where('team_leader_id', $validated['team_leader_id'])
                    ->where('id', '!=', $unit->id)
                    ->exists();

                if ($alreadyTaken) {
                    throw new \RuntimeException($alreadyAssignedMessage);
                }

                $leader = User::find($validated['team_leader_id']);

                $unit->update([
                    'team_leader_id' => $validated['team_leader_id'],
                    'driver_name' => build_full_name($leader->driver_first_name, $leader->driver_middle_name, $leader->driver_last_name),
                    'crew_member_1_name' => $leader->crew_member_1_name,
                    'crew_member_2_name' => $leader->crew_member_2_name,
                ]);

                return $unit;
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            // Belt-and-suspenders: the DB constraint caught a race the
            // in-transaction check above somehow missed.
            if ((string) $e->getCode() === '23505') {
                return back()->with('error', $alreadyAssignedMessage);
            }

            throw $e;
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'team_leader_assigned',
            'entity_type' => 'Unit',
            'entity_id' => $unit->id,
            'reference' => $unit->name,
            'description' => "Team leader assigned to {$unit->name}.",
        ]);

        return redirect()->route('superadmin.unit-truck.index')
            ->with('success', "Team leader assigned to {$unit->name}.");
    }

    public function removeTeamLeader($id): RedirectResponse
    {
        $unit = Unit::findOrFail($id);
        $leaderName = $unit->teamLeader?->full_name ?? $unit->teamLeader?->name;

        $unit->update([
            'team_leader_id' => null,
            'driver_name' => null,
            'crew_member_1_name' => null,
            'crew_member_2_name' => null,
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'team_leader_removed',
            'entity_type' => 'Unit',
            'entity_id' => $unit->id,
            'reference' => $unit->name,
            'description' => $leaderName
                ? "{$leaderName} (and crew) removed from {$unit->name}."
                : "Team leader removed from {$unit->name}.",
        ]);

        return redirect()->route('superadmin.unit-truck.index')
            ->with('success', "Team leader and crew removed from {$unit->name}.");
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

    public function borrowCrew(Request $request, Unit $unit): RedirectResponse
    {
        $validated = $request->validate([
            'from_unit_id' => ['required', 'exists:units,id', Rule::notIn([$unit->id])],
            'from_slot' => ['required', Rule::in(array_keys(Unit::SLOT_COLUMNS))],
            'to_slot' => ['required', Rule::in(array_keys(Unit::SLOT_COLUMNS))],
        ], [
            'from_unit_id.not_in' => 'Cannot assign crew from the same unit.',
        ]);

        $slotType = fn(string $slot) => str_starts_with($slot, 'driver_') ? 'driver' : 'crew_member';

        if ($slotType($validated['from_slot']) !== $slotType($validated['to_slot'])) {
            return back()->with('error', 'You can only assign a Driver into a Driver slot, or a Crew Member into a Crew Member slot.');
        }

        $fromUnit = Unit::findOrFail($validated['from_unit_id']);
        $fromColumn = Unit::SLOT_COLUMNS[$validated['from_slot']];
        $toColumn = Unit::SLOT_COLUMNS[$validated['to_slot']];

        if ($validated['to_slot'] === 'driver_1' && $unit->driver_id) {
            return back()->with('error', 'Driver 1 already has a linked account assigned. Unassign it first before assigning.');
        }

        if ($validated['from_slot'] === 'driver_1' && $fromUnit->driver_id) {
            return back()->with('error', 'That unit\'s Driver 1 is a linked account, not a free-text name, and cannot be assigned.');
        }

        if (filled($unit->{$toColumn})) {
            return back()->with('error', 'That slot is already filled. Clear it first before assigning.');
        }

        if (blank($fromUnit->{$fromColumn})) {
            return back()->with('error', 'The selected source slot is empty.');
        }

        if ($fromUnit->activeLoanOut($validated['from_slot'])) {
            return back()->with('error', 'That person is already on transfer to another unit.');
        }

        if ($fromUnit->status !== 'available') {
            return back()->with('error', 'Cannot assign crew from a unit that is currently on a job or in maintenance.');
        }

        if ($fromUnit->activeLoansIn()->has($validated['from_slot'])) {
            return back()->with('error', 'This person is already on loan from another unit and cannot be assigned again.');
        }

        $personName = $fromUnit->{$fromColumn};

        $fromUnit->update([$fromColumn => null]);
        $unit->update([$toColumn => $personName]);

        $loan = UnitCrewLoan::create([
            'from_unit_id' => $fromUnit->id,
            'to_unit_id' => $unit->id,
            'from_slot' => $validated['from_slot'],
            'to_slot' => $validated['to_slot'],
            'person_name' => $personName,
            'borrowed_at' => now(),
            'created_by' => Auth::id(),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'crew_borrowed',
            'entity_type' => 'Unit',
            'entity_id' => $unit->id,
            'reference' => $personName,
            'description' => "{$personName} assigned from {$fromUnit->name} to {$unit->name}.",
        ]);

        return back()->with('success', "{$personName} assigned from {$fromUnit->name}.");
    }

    public function returnCrew(UnitCrewLoan $loan): RedirectResponse
    {
        if ($loan->returned_at) {
            return back()->with('error', 'This transfer has already been returned.');
        }

        $fromUnit = $loan->fromUnit;
        $toUnit = $loan->toUnit;
        $fromColumn = Unit::SLOT_COLUMNS[$loan->from_slot];
        $toColumn = Unit::SLOT_COLUMNS[$loan->to_slot];

        $fromUnit->update([$fromColumn => $loan->person_name]);
        $toUnit->update([$toColumn => null]);

        $loan->update(['returned_at' => now()]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'crew_returned',
            'entity_type' => 'Unit',
            'entity_id' => $toUnit->id,
            'reference' => $loan->person_name,
            'description' => "{$loan->person_name} returned from {$toUnit->name} to {$fromUnit->name}.",
        ]);

        return back()->with('success', "{$loan->person_name} returned to {$fromUnit->name}.");
    }
}
