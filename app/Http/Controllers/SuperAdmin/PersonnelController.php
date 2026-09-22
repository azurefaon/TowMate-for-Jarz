<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Personnel;
use App\Models\Unit;
use App\Models\User;
use App\Services\PersonnelService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PersonnelController extends Controller
{
    public function __construct(protected PersonnelService $personnel)
    {
    }

    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'role' => trim((string) $request->query('role', '')),
        ];

        $page = max(1, (int) $request->query('page', 1));

        return view('superadmin.personnel.index', [
            'personnel' => $this->personnel->listPersonnel($filters['search'], $filters['role'], 7, $page),
            'units' => Unit::whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in(['driver', 'crew'])],
            'home_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('archived_at')],
        ]);

        $record = Personnel::create([
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'role' => $validated['role'],
            'home_unit_id' => $validated['home_unit_id'] ?? null,
            'personnel_status' => 'active',
        ]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'personnel_created',
            'entity_type' => 'Personnel',
            'entity_id' => $record->id,
            'reference' => $record->full_name,
            'description' => "{$record->full_name} added as " . PersonnelService::PERSONNEL_ROLE_LABELS[$record->role] . '.',
        ]);

        return back()->with('success', 'Personnel added.');
    }

    public function update(Request $request, Personnel $record)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in(['driver', 'crew'])],
        ]);

        $record->update($validated);

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'personnel_updated',
            'entity_type' => 'Personnel',
            'entity_id' => $record->id,
            'reference' => $record->full_name,
            'description' => "{$record->full_name} updated.",
        ]);

        return back()->with('success', 'Personnel updated.');
    }

    public function updateRecordHomeUnit(Request $request, Personnel $record)
    {
        $validated = $request->validate([
            'home_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('archived_at')],
        ]);

        $oldHomeUnit = $record->homeUnit;
        $newHomeUnitId = $validated['home_unit_id'] ?? null;

        if ((int) $record->home_unit_id === (int) $newHomeUnitId) {
            return back()->with('success', 'Home Unit is unchanged.');
        }

        $record->update(['home_unit_id' => $newHomeUnitId]);
        $newHomeUnit = $newHomeUnitId ? Unit::find($newHomeUnitId) : null;

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'personnel_home_unit_changed',
            'entity_type' => 'Personnel',
            'entity_id' => $record->id,
            'reference' => $record->full_name,
            'description' => "Home Unit for {$record->full_name} changed from " . ($oldHomeUnit->name ?? 'None') . ' to ' . ($newHomeUnit->name ?? 'None') . '.',
            'old_value' => ['home_unit' => $oldHomeUnit->name ?? 'None'],
            'new_value' => ['home_unit' => $newHomeUnit->name ?? 'None'],
        ]);

        return back()->with('success', 'Home Unit updated.');
    }

    public function toggleRecordStatus(Personnel $record)
    {
        $newStatus = $record->personnel_status === 'active' ? 'inactive' : 'active';

        $record->update(['personnel_status' => $newStatus]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $newStatus === 'active' ? 'personnel_activated' : 'personnel_deactivated',
            'entity_type' => 'Personnel',
            'entity_id' => $record->id,
            'reference' => $record->full_name,
            'description' => "{$record->full_name} set to " . ucfirst($newStatus) . '.',
        ]);

        return back()->with('success', $newStatus === 'active' ? 'Personnel activated.' : 'Personnel deactivated.');
    }

    public function updateHomeUnit(Request $request, User $person)
    {
        if (! in_array((int) $person->role_id, PersonnelService::ACCOUNT_ROLE_IDS, true)) {
            abort(404);
        }

        $validated = $request->validate([
            'home_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('archived_at')],
        ]);

        $oldHomeUnit = $person->homeUnit;
        $newHomeUnitId = $validated['home_unit_id'] ?? null;

        if ((int) $person->home_unit_id === (int) $newHomeUnitId) {
            return back()->with('success', 'Home Unit is unchanged.');
        }

        $person->update(['home_unit_id' => $newHomeUnitId]);
        $newHomeUnit = $newHomeUnitId ? Unit::find($newHomeUnitId) : null;

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'personnel_home_unit_changed',
            'entity_type' => 'User',
            'entity_id' => $person->id,
            'reference' => $person->full_name,
            'description' => "Home Unit for {$person->full_name} changed from " . ($oldHomeUnit->name ?? 'None') . ' to ' . ($newHomeUnit->name ?? 'None') . '.',
            'old_value' => ['home_unit' => $oldHomeUnit->name ?? 'None'],
            'new_value' => ['home_unit' => $newHomeUnit->name ?? 'None'],
        ]);

        return back()->with('success', 'Home Unit updated.');
    }

    public function toggleEnabled(User $person)
    {
        if (! in_array((int) $person->role_id, PersonnelService::ACCOUNT_ROLE_IDS, true)) {
            abort(404);
        }

        $newState = ! $person->personnel_enabled;

        $person->update(['personnel_enabled' => $newState]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $newState ? 'personnel_activated' : 'personnel_deactivated',
            'entity_type' => 'User',
            'entity_id' => $person->id,
            'reference' => $person->full_name,
            'description' => "{$person->full_name} set to " . ($newState ? 'Active' : 'Inactive') . '.',
        ]);

        return back()->with('success', $newState ? 'Personnel activated.' : 'Personnel deactivated.');
    }

    public function homeAssignments()
    {
        return view('superadmin.personnel.home-assignments', [
            'roster' => $this->personnel->homeAssignmentsRoster(),
        ]);
    }
}
